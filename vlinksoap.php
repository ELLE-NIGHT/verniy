<?php
Kernel::Import('classes.soap.SoapConnection');

Kernel::Import('classes.data.vlinkGlnsTable');
Kernel::Import('classes.data.vlinkDocsInboxTable');
Kernel::Import("classes.data.glnsTable");
Kernel::Import("classes.data.usersTable");
Kernel::Import('classes.data.subservicesTable');
Kernel::Import('classes.data.vlinkauthtable.php');
Kernel::Import('classes.data.vlinkshopstable.php');
Kernel::Import('classes.data.ordersTable');
Kernel::Import("classes.unit.xmlparser");
Kernel::Import("classes.unit.Translator");
class VlinkSoap extends SoapConnection
{
	//soap methods
	const
		ping = 'Ping',
		authorization = 'WhoAmI',
		get_catalogues = 'GetRefs',
		order_list_ids = 'GetOrderList',
		recadv_list_ids = 'GetRecAdvList',
		//recadv_list_ids = GetRecAdvList,
		get_orders = 'GetOrders',
		get_returns = 'GetReturns',
		confirm_order = 'ConfirmOrder',
		update_catalogues = 'UpdateRefs',
		get_unconfirmed_docs = 'GetListOfNonconfirmed',
		get_price_list = 'GetPriceList',
		confirm_order_partly = 'ConfirmOrderPartly',
		get_shop_list = 'GetShopList';

	const
		vlinkShopIDLength = 11,
		vlinkAccountID = 6430,
		vlinkRetailerID = 52,
		vlinkUserID = 7895,
		gtinLength = 13,
		outbox_folder = 'coutbox/',
		error_folder = 'error/';

	const
		cacheCatalogueExpireSeconds = 12600,
		cachePriceListExpireSeconds = 2592000,
		NO_GLN_ERROR_CODE = 1010;

	public
		$memcache;

	protected
		$suppl_id = 7895,//lab - 7783 | test - 7850 | rabochiy - 7895
		$soapPath = 'vlinkSoapMap.asmx.xml',//UserPort_v1
		$soapConnectionOptions =
		    array(
			'soap_version' => SOAP_1_2,
			'location' => 'http://xmlvlink.r-gate.ru/v-link/xml/UserPort_v1.asmx',
			'uri' => 'http://xmlvlink.r-gate.ru/',
			'trace' => true,
			'exceptions' => true,
			'cache_wsdl' => WSDL_CACHE_NONE
		    ),
		$soapClient,
		$currentSupplierData,
		$defaultGln = 5000000009992,
		$documents = array(),
		$ordersXmls = array(),
		$currentSupplierCatalogue = null,
		$partlyConfirmedOrdersIDs = array();

	public function __construct(MySQLConnection $connection)
	{
		$this->soapPath = Kernel::createPath('classes.soap.') . $this->soapPath;
		$this->authTable = DB_TABLE_VLINK_AUTH;
		parent::__construct($connection);

		$this->vlinkDocsInboxTable = new vlinkDocsInboxTable($this->connection);
		$this->vlinkGlnsTable = new vlinkGlnsTable($this->connection);
		$this->glnsTable = new glnsTable($this->connection);
		$this->usersTable = new usersTable($this->connection);
		
		
		
		$this->shopsTable = new vlinkShopsTable($this->connection);
		
		//$this->vlinkauthtable = new vlinkauthtable($this->connection);

		Translator::getInstance('ru', 'admin');

		$this->memcache = new Memcache;
		$this->memcache->connect(MEMCACHE_SERVER, MEMCACHE_PORT) or die ('Could not connect to Memcache server on ' . MEMCACHE_SERVER . ':' . MEMCACHE_PORT);
	}

	public function getUnconfirmedOrdersForAllSuppliers()
	{
		$suppliersList = $this->getSuppliersList(null,'login');
		$ordersXmls = array();

		foreach($suppliersList as $supplierData)
		{
			$this->setCurrentConnectionData($supplierData);
			try{
				$newOrdersXml = $this->getUnconfirmedOrders();
				$ordersXmls = $ordersXmls + $newOrdersXml;
			} catch (Exception $ex) {
				$this->log(sprintf('%s; login:%s', $ex->getMessage(), $this->currentSupplierData['login']));
			}
		}

		return $ordersXmls;
	}

	public function getUnconfirmedDocumentsForAllSuppliers()
	{
		$suppliersList = $this->getSuppliersList(null,'login');
		$documentsXmls = array(
			'orders' => array(),
			'retanns' => array(),
		);

		foreach($suppliersList as $supplierData)
		{
			$this->setCurrentConnectionData($supplierData);
			try
			{
				$resultXmls = $this->getUnconfirmedDocuments();
				$documentsXmls['orders'] += $resultXmls['orders'];
				$documentsXmls['retanns'] += $resultXmls['retanns'];

			} catch (Exception $ex)
			{
				$this->log(sprintf('%s; login:%s', $ex->getMessage(), $this->currentSupplierData['login']));
			}
		}

		return $documentsXmls;
	}

	public function writeNewRecadv($date)
	{
		$suppliersList = $this->getSuppliersList(null, 'login');
		foreach($suppliersList as $supplierData)
		{
			$this->setCurrentConnectionData($supplierData);
			try
				{
				$recadvs = $this->getRecadvDocumentsHeaders($date);
				}
			catch (Exception $e)
				{
				continue;
				}
	
			if(!isset($recadvs->{"СписокАктовПриемки"}->{"АктыПриемки"}->{'АктПриемки'}))
				{
				continue;
				}
			$recadvsList = array();
	
		if( is_array($recadvs->{"СписокАктовПриемки"}->{"АктыПриемки"}->{'АктПриемки'}))
		{
		$recadvsList = $recadvs->{"СписокАктовПриемки"}->{"АктыПриемки"}->{'АктПриемки'};
		}
		else
		{
		$recadvsList[] = $recadvs->{"СписокАктовПриемки"}->{"АктыПриемки"}->{'АктПриемки'};
		}
		
		foreach($recadvsList as $recadv)
		{
			if(!isset($recadv->{'Строки'}))
			{
			continue;
			}
	
	
	$xmlObject = simplexml_load_string($recadv);
	$xmlArray = Kernel::objectToArray($xmlObject);
	//$orderUpdate = true;
	// предполагается, что документ обладает ЗаказИД и ИД
	// получение заказа (order) в таблице vlink_docs_inbox по ЗаказИД из recadv
	
	
	
	//$orderID = empty($recadv->{'ЗаказИД'}) ? $recadv->{'ИД'} : $recadv->{'ЗаказИД'};
	//$orderExistsData = array ('intVlinkDocID' => $orderID, 'varType' => 'order');
	
	
	
	$orderExistsData = array ('intVlinkDocID' => $recadv->{'ЗаказИД'}, 'varType' => 'order');
	$orderExists = $this->vlinkDocsInboxTable->GetByFields($orderExistsData);
	
	// если заказ не найден
	if (empty($orderExists)){
	// получение такого же уведомления по intVlinkDocID и recadv из vlink_docs_inbox
	$recadvExistsData = array('intVlinkDocID' => $recadv->{'ИД'}, 'varType' => 'recadv');
	$recadvExists = $this->vlinkDocsInboxTable->GetByFields($recadvExistsData);
		if (empty($recadvExists)){
		// только если recadv отсутствует в табллице, добавляем новую запись
		//echo ("\n".'New recadv ');
			try
			{
				//$orderUpdate = false;
				$this->substituteDocumentValues($recadv);
				$this->vlinkDocsInboxUpdateRecadvIn($recadv,$supplierData['login'],0);
				$xml = $this->soapResponseToXml($recadv, 'Recadv');
				$this->writeNewDocument($recadv->{'ИД'}, $xml, 'recadv');
			}
			catch (Exception $e)
			{
				$xml = $this->soapResponseToXml($recadv, 'Recadv');
				$this->writeNewDocumentError($recadv->{'ИД'}, $xml, 'recadv');
			}
		}
	}
	
	// если заказ найден
	
	if ($orderExists)
	{
	// проверка, есть ли у такого заказа recadv по полю intHasRecadv
	$recadvPresent = $orderExists['intHasRecadv'];
	if ($recadvPresent == 0){
	echo ("\n".'Existing order ');
	try
		{
		//$orderUpdate = true;
		$this->substituteDocumentValues($recadv);
		$this->vlinkDocsInboxUpdateRecadvIn($recadv,$supplierData['login'], 1);
		$xml = $this->soapResponseToXml($recadv, 'Recadv');
		$this->writeNewDocument($recadv->{'ИД'}, $xml, 'recadv');
		}
	catch (Exception $e)
		{
		$xml = $this->soapResponseToXml($recadv, 'Recadv');
		$this->writeNewDocumentError($recadv->{'ИД'}, $xml, 'recadv');
		}
	}
	//else{
	
	//}
	
	}
	
	
	
	
	/*
	$xmlObject = simplexml_load_string($recadv);
	$xmlArray = Kernel::objectToArray($xmlObject);
	
	$newDocumentData = array('intVlinkDocID' => $recadv->{'ИД'}, 'varType' => 'recadv');
	//$newDocumentDataOrder = array('intVlinkDocID' => $recadv->{'ИД'}, 'varType' => 'order');
	//$newOrdesWithoutRecadv = array('intVlinkDocID' => $recadv->{'ИД'}, 'varType' => 'order', 'intHasRecadv' =>1);
	//$newOrdesWithRecadv = array('intVlinkDocID' => $recadv->{'ИД'}, 'varType' => 'order', 'intHasRecadv' =>0);
	
	
	//$newDocumentData = array('intVlinkDocID' => $recadv->{'ИД'}, 'varType' => $varType);
	//$newDocumentData = array('intVlinkDocID' => $recadv->{'ИД'}, 'varType' => $varType,'auth_login' => $xmlArray['supplier_login']);
	
	
	$docExists = $this->vlinkDocsInboxTable->GetByFields($newDocumentData);
	//$docExistsOrder = $this->vlinkDocsInboxTable->GetByFields($newDocumentDataOrder);
	
	
	//$docOrderWithoutRecadv = $this->vlinkDocsInboxTable->GetByFields($newOrderWithoutRecadv);
	//$docOrderWithRecadv = $this->vlinkDocsInboxTable->GetByFields($newOrderWithRecadv);
	
	
	//if (empty($docExistsOrder))
	
	//{
	
		if(empty($docExists))
		{
		try
			{
			$this->substituteDocumentValues($recadv);
			$this->vlinkDocsInboxUpdateRecadvIn($recadv,$supplierData['login']);
			$xml = $this->soapResponseToXml($recadv, 'Recadv');
			$this->writeNewDocument($recadv->{'ИД'}, $xml, 'recadv');
			}
			catch (Exception $e)
			{
			$xml = $this->soapResponseToXml($recadv, 'Recadv');
			$this->writeNewDocumentError($recadv->{'ИД'}, $xml, 'recadv');
			}
		}
	//}
	
	
	
	*/
	
	
	}
	}
	}

	public function vlinkDocsInboxUpdateRecadvIn(&$recadv, $login, $orderUpdate)
	{
		//$recadv->{'ЗаказИД'} = empty($recadv->{'ЗаказИД'}) ? $recadv->{'ИД'} : $recadv->{'ЗаказИД'};

		//$docExists = $this->vlinkDocsInboxTable->Get(array('intVlinkDocID' => $recadv->{'ИД'}));

							
		//if($docExists)
		//{
		//	$updateData = array('intHasRecadv' => 1, 'intVlinkDocID' => $recadv->{'ИД'});
		//	$this->vlinkDocsInboxTable->Update($updateData);
		//}else
		
		
		
		
		if ($orderUpdate == 1){
			echo (' was updated by recadv'."\n");
			$updateData = array('intHasRecadv' =>1, 'intVlinkDocID' => $recadv->{'ЗаказИД'}, 'varType' => 'order');
			$this->vlinkDocsInboxTable->Update($updateData, true);
		}
		else{
			echo ('was created '."\n");
				$insertData = array(
				'intHasRecadv' => 1,
				'varType' => 'recadv',
				'intVlinkDocID' => $recadv->{'ИД'},
				'auth_login' => $login,
				'created' => date('Y-m-d H:i:s'),
			);
			$this->vlinkDocsInboxTable->Insert($insertData, true);
		}
	}

	public function getUnconfirmedOrders()
	{
		$ordersAndReturnsIdsForSupplier = $this->getUnconfirmedDocumentsHeaders();
		$ordersIdsForSupplier = $this->extractsOrdersIDs($ordersAndReturnsIdsForSupplier);
		$ordersBodies = $this->getOrdersBodies($ordersIdsForSupplier);
		$ordersXmls = $this->buildDocumentsXmls($ordersBodies);

		return $ordersXmls;
	}

	public function getUnconfirmedDocuments()
	{
		$DocumentsXmls = array();

		$ordersAndReturnsIdsForSupplier = $this->getUnconfirmedDocumentsHeaders();

		$ordersIdsForSupplier = $this->extractsOrdersIDs($ordersAndReturnsIdsForSupplier);
		$ordersBodies = $this->getOrdersBodies($ordersIdsForSupplier);
		$DocumentsXmls['orders'] = $this->buildDocumentsXmls($ordersBodies);

		$returnsIdsForSupplier = $this->extractsReturnsIDs($ordersAndReturnsIdsForSupplier);
		$returnsBodies = $this->getReturnsBodies($returnsIdsForSupplier);
		$DocumentsXmls['retanns'] = $this->buildDocumentsXmls($returnsBodies, 'Retann');

		return $DocumentsXmls;
	}

	protected function getUnconfirmedDocumentsHeaders()
	{
		$documents = $this->callSoapMethod(self::get_unconfirmed_docs);

		return $documents;
	}

	protected function getRecadvDocumentsHeaders($date)
	{
		$documents = $this->callSoapMethod(self::recadv_list_ids, array('ДатаСоздания' => $date));

		return $documents;
	}

	private function extractsOrdersIDs($ordersIdsForGLN)
	{
		return $this->extractsDocumentsIDs($ordersIdsForGLN->{'СписокНеподтв'}->{'Заказы'}->{'ИД'});
	}

	private function extractsReturnsIDs($returnsIdsForGLN)
	{
		return $this->extractsDocumentsIDs($returnsIdsForGLN->{'СписокНеподтв'}->{'Возвраты'}->{'ИД'});
	}

	private function extractsDocumentsIDs($docHeaders = array(), $docIdTag = '_')
	{
		$newDocsInfoList = array();

		$documentsInfo = Kernel::toArray($docHeaders);

		foreach($documentsInfo as $documentInfo)
		{
			$docID = (int) $documentInfo->{$docIdTag};

			$newReturnData = array('intVlinkDocID' => $docID);
//			$docExists = $this->vlinkDocsInboxTable->GetByFields($newDocData);
			if(!$docExists)
			{
				$newDocsInfoList[] = $docID;
			}
		}

		return $newDocsInfoList;
	}

	protected function getDocumentsBodies($docIds, $soapMethod, $docTagName)
	{
		$ordersBodiesFormatted = array();

		if (!empty($docIds) && !empty($soapMethod) && !empty($docTagName))
		{
			$docsBodies = $this->callSoapMethod($soapMethod, $docIds);
			$this->log(sprintf('%s тел %sов взято из vlink,supplier: %s', count($docsBodies->{$docTagName}),$docTagName,$this->currentSupplierData['login']));
			$ordersBodiesFormatted = count($docsBodies->{$docTagName}) > 1 ? $docsBodies->{$docTagName} : $docsBodies;
		}

		return $ordersBodiesFormatted;
	}

	protected function getOrdersBodies($orderIds)
	{
		return $this->getDocumentsBodies($orderIds, self::get_orders, 'Заказ');
	}

	protected function getReturnsBodies($returnIds)
	{
		return $this->getDocumentsBodies($returnIds, self::get_returns, 'Возврат');
	}

	private function buildDocumentsXmls($documentsBodies, $docType = 'Order')
	{
		$documentsXmls = array();

		foreach($documentsBodies as $document)
		{
			try
			{
				$this->substituteDocumentValues($document);
			} catch (Exception $ex)
			{
				if ($ex->getCode() == self::NO_GLN_ERROR_CODE)
				{
					$this->setDocumentToReload($document);
				}

				$logMessage = sprintf('error on document %s for supplier %s:%s',
						$document->{'ИД'}, $this->currentSupplierData['login'], $ex->getMessage());
				$this->log($logMessage);

				continue;
			}

			$orderIdVlink = $document->{'ИД'};
			$documentsXmls[$orderIdVlink] = $this->soapResponseToXml($document, $docType);
		}

		return $documentsXmls;
	}

	private function substituteDocumentValues(&$document)
	{
		$supplierGLN = $this->currentSupplierData['gln'];
		$shopID = $this->calculateVlinkShopID($document->{'МагПкпИД'});
		$buyerVlinkData = $this->vlinkGlnsTable->GetByFields(array('varShopID' => $shopID));

		if(empty($buyerVlinkData) || !$buyerVlinkData['varGln'])
		{
			throw new Exception('No GLN for this varShopID - '.$shopID, self::NO_GLN_ERROR_CODE);
		}

		$this->replacePositionsSupplierIdsWithGtin($document);
		$this->replacePositionsDataWithPriceListData($document);

		$document->{'МагПстИД'} = $supplierGLN;
		$document->{'МагПкпИД'} = $buyerVlinkData['varGln'];
		$document->{'supplier_login'} = $this->currentSupplierData['login'];
	}

	private function replacePositionsSupplierIdsWithGtin($order)
	{
		$buyerPositions = false;
		$orderPositions = $this->extractPositionsFromOrder($order);

		foreach($orderPositions as $orderPosition)
		{
			$positionsSupplierID = $orderPosition->{Translator::t('vlink_pos_supplierId_key')};
			$positionBuyerID = $orderPosition->{Translator::t('vlink_pos_buyerId_key')};
			if (empty($positionsSupplierID))
			{
				$buyerPositions = empty($buyerPositions) ?
					$this->getDecoratedCataloguePositions(Translator::t('vlink_buyerId_key')) : $buyerPositions;

				$buyerPositionGtins = $buyerPositions[$positionBuyerID]->{Translator::t('vlink_gtin_key')};

				$orderPosition->{Translator::t('vlink_pos_supplierId_key')} = $this->extractGtinFromBuyerPosition($buyerPositionGtins);
			}
		}
	}

	private function replacePositionsDataWithPriceListData($order)
	{
		$date = date('Y-m-d',strtotime($order->{'Размещен'}));
		$priceList = $this->getPriceList($date);
		$priceListPositions = $this->extractPositionsFromPriceList($priceList);
		$orderPositions = $this->extractPositionsFromOrder($order);

		foreach($orderPositions as $orderPosition)
		{
			foreach($priceListPositions as $priceListPosition)
			{
				if($orderPosition->{'ТовПкпИД'} === $priceListPosition->{'ПкпИД'})
				{
					$orderPosition->{'Цена'} = $priceListPosition->{'Цена'};
					$orderPosition->{'ЦенаСкл'} = $priceListPosition->{'ЦенаСкл'};
					$orderPosition->{'НДС'} = $priceListPosition->{'НДС'};

					break;
				}
			}
		}
	}

	private function extractGtinFromBuyerPosition($buyerPositionGtins)
	{
		$gtinDelimiter = " ";
		$gtinParts = explode($gtinDelimiter, $buyerPositionGtins);
		$gtin = null;
		$curLen = 0;
		$maxLen = 0;
		$maxLenValue = '';
		foreach($gtinParts as $gtinToCheck)
		{
			$curLen = strlen($gtinToCheck);

			if ($curLen == self::gtinLength)
			{
				$gtin = $gtinToCheck;

			} else if (empty($gtin) && $curLen > $maxLen)
			{
				$maxLen = $curLen;
				$maxLenValue = $gtinToCheck;
			}
		}

		if (empty($gtin) && !empty($maxLenValue))
		{
			$gtin = $maxLenValue;
		}

		return $gtin;
	}

	private function getDecoratedCataloguePositions($fieldName)
	{
		$positions = $this->extractPositionsFromCatalogues($this->getCatalogues());
		$decoratedPositions = array();
		foreach($positions as $position)
		{
			$decoratedPositions[$position->$fieldName] = $position;
		}

		return $decoratedPositions;
	}

	private function calculateVlinkShopID($shopID)
	{
		$calculatedShopID = null;

		//get shopID prefix
		$startCharPosition = 1;//P
		$endCharPosition = strrpos($this->currentSupplierData['login'],'-') - 1;
		// get 01 from "P01" and turn 01 to 10
		$shopIdPrefix = (int)substr($this->currentSupplierData['login'],$startCharPosition,$endCharPosition) * 10;

		//insert char deficit with zeroes between prefix and suffix
		$shopIdPrefixLength = strlen($shopIdPrefix);
		$shopIdSuffixMaxLength = self::vlinkShopIDLength - $shopIdPrefixLength;
		$shopID = str_pad($shopID, $shopIdSuffixMaxLength, '0', STR_PAD_LEFT);

		$calculatedShopID = $shopIdPrefix . $shopID;

		return $calculatedShopID;
	}

	public function writeNewOrders($orders)
	{
		return $this->writeNewDocuments($orders, 'order');
	}

	public function writeNewReturns($returns)
	{
		return $this->writeNewDocuments($returns, 'retann');
	}

	public function writeNewDocuments($documents, $varType)
	{
		if (!empty($documents) && !empty($varType))
		{
			foreach($documents as $documentID => $document)
			{
				$xmlObject = simplexml_load_string($document);
				$xmlArray = Kernel::objectToArray($xmlObject);

				$newDocumentData = array('intVlinkDocID' => $documentID, 'varType' => $varType,'auth_login' => $xmlArray['supplier_login']);
				$docExists = $this->vlinkDocsInboxTable->GetByFields($newDocumentData);

				if(empty($docExists))
				{
					try
					{
						$folder = 'coutbox/';
						$fileName = strtoupper($varType).'_'.date('YmdHis').$documentID.'.xml';
						$this->createXSDRecord($this->suppl_id, $fileName, $folder, $document, strlen($document));
						$newDocumentData['created']= date('Y-m-d H:i:s');

						$this->vlinkDocsInboxTable->Insert($newDocumentData,true);

						$this->log(sprintf('Document %s created and written to %s;supplier:%s',$fileName, $folder, $xmlArray['supplier_login']));
					} catch (Exception $exception)
					{
						echo $exception->getMessage();

						continue;
					}
				}
			}
		}

		return $this;
	}

	public function confirmOrders()
	{
		$suppliersList = $this->getSuppliersList(null,'login');
		$orderConfirmsList = $this->getFilesList($this->suppl_id, 'cinbox/', true);

		foreach($orderConfirmsList as $file)
		{
			$fileName = $file['varDocName'];
			$xml = $file['varDocBody'];

			$arguments = $this->prepareOrderConfirmArguments($xml);

			if($arguments['Заказ']['ИД'])
			{
				$filter = array('intVlinkDocID' => $arguments['Заказ']['ИД']);
				$vlinkInboxDoc = $this->vlinkDocsInboxTable->GetByFields($filter);
			}
			else
			{
				$hash = XSDParserDocumentHandler::generateVlinkOrderspHash($arguments['Заказ']['RECIPIENT'], $arguments['Заказ']['SENDER'],
						$arguments['Заказ']['ORDERNUMBER'],$arguments['Заказ']['DELIVERYPLACE']);
				$oldHash = XSDParserDocumentHandler::generateVlinkOrderspOldHash($arguments['Заказ']['RECIPIENT'], $arguments['Заказ']['SENDER'],
						$arguments['Заказ']['ORDERNUMBER']);
				$vlinkInboxDoc = $this->vlinkDocsInboxTable->getDocumentWithHash($hash,$oldHash);
			}

			$arguments['Заказ']['ИД'] = $vlinkInboxDoc['intVlinkDocID'];

			$supplierData = $suppliersList[$vlinkInboxDoc['auth_login']];
			$this->setCurrentConnectionData($supplierData);

			try
			{
				$this->confirmOrder($arguments);

				$this->moveFileTo($this->suppl_id, $fileName, 'cinbox/', 'arc/');
				$this->log(sprintf('Success: %s has been confirmed; file: moved to arc; supplier:%s', $fileName, $vlinkInboxDoc['auth_login']));
			} catch(Exception $e)
			{
				$msg = sprintf('Error: %s; request: %s; file: moved to error;login:%s;filename:%s;',$e->getMessage(),
						print_r($this->soapClient->__getLastRequest(),true),$vlinkInboxDoc['auth_login'],$fileName);
				$msgForMail = sprintf('Error: %s;login:%s;filename:%s',$e->getMessage(),$vlinkInboxDoc['auth_login'],$fileName);
				if($arguments['Заказ']['senderUserID'])
				{
					$this->sendErrorEmail($msgForMail,'ordrsp',$arguments['Заказ']['senderUserID']);
				}
				$this->moveFileTo($this->suppl_id, $fileName, 'cinbox/', 'error/');
				$this->log($msg);
			}
		}

		return $this;
	}

	private function confirmOrder($arguments)
	{
		if (self::isPartly($arguments))
		{
			$this->log(sprintf('Document "%s" sent by "%s" method; INFO value: "%s"',
				$arguments['Заказ']['ИД'], self::confirm_order_partly, $arguments['Заказ']['INFO']));
			$this->callSoapMethod(self::confirm_order_partly, $arguments);
			if (!empty($arguments['Заказ']['ИД']))
			{
				array_push($this->partlyConfirmedOrdersIDs, $arguments['Заказ']['ИД']);
			}

		} else
		{
			$this->log(sprintf('Document "%s" sent by "%s" method; INFO value: "%s"',
				$arguments['Заказ']['ИД'], self::confirm_order, $arguments['Заказ']['INFO']));
			$this->callSoapMethod(self::confirm_order, $arguments);
		}
	}

	private function prepareOrderConfirmArguments($xml)
	{
		$xmlObject = simplexml_load_string($xml);
		$xmlArray = Kernel::objectToArray($xmlObject);

		if(isset($xmlArray['Строки']['стр']['@attributes']))
		{
			$xmlArray['Строки']['стр'][] = $xmlArray['Строки']['стр'];
			unset($xmlArray['Строки']['стр']['@attributes']);
		}

		foreach((array)$xmlArray['Строки']['стр'] as $position)
		{
			$xmlArray['Строки'][] = $position['@attributes'];
		}
		unset($xmlArray['Строки']['стр']);

		foreach((array)$xmlArray['@attributes'] as $orderAttributeKey => $orderAttributes)
		{
			if($orderAttributes)
			{
				$xmlArray[$orderAttributeKey] = $orderAttributes;
			}
		}
		unset($xmlArray['@attributes']);

		if (self::isPartly($xmlArray) && is_array($xmlArray['Строки']))
		{
			foreach ($xmlArray['Строки'] as &$item)
			{
				$item['ЗакКво'] = $item['НаклКво'];
				unset($item['НаклКво']);
			}
		}

		$arguments = array('Заказ' => $xmlArray);

		return $arguments;
	}

	private static function isPartly($data = array())
	{
		return ($data['INFO'] == 'PARTLY' || $data['Заказ']['INFO'] == 'PARTLY');
	}

	public function getCatalogues()
	{
		$catalogues = $this->getCachedCatalogueData();

		return $catalogues;
	}

	private function getCachedCatalogueData()
	{
		$cacheKey = $this->currentSupplierData['login'] . '_catalogue';

		$catalogueData = $this->memcache->get($cacheKey);
		if(!$catalogueData)
		{
			$catalogueData = $this->callSoapMethod(self::get_catalogues);
			$this->memcache->set($cacheKey,$catalogueData,null,self::cacheCatalogueExpireSeconds);
		}

		return $catalogueData;
	}

	public function replacePositionsSupplierIdWithGtins(&$positions)
	{
		$vlinkGtinKey = Translator::t('vlink_gtin_key'); // ШтрихКоды
		$vlinkPositionSupplierID = Translator::t('vlink_supplierId_key'); // ПстИД
		foreach($positions as &$positionData)
		{
			$positionGtins = $positionData[$vlinkGtinKey];
			$positionSupplierID = &$positionData[$vlinkPositionSupplierID];
			
			if (empty($positionSupplierID))
			{
				$tmp = $this->extractGtinFromBuyerPosition($positionGtins);
				if (!empty($tmp))
				{
					$positionSupplierID = $tmp;
				}
			} else
			{
				$positionSupplierID = $this->extractGtinFromBuyerPosition($positionSupplierID);
			}
		}
		unset($positionData);
	}

	public function extractPositionsFromCatalogues($catalogues)
	{
		$positions = $catalogues->{'Справочники'}->{'Товары'}->{'тов'};

		return Kernel::toArray($positions);
	}

	public function extractPositionsFromPriceList($priceList)
	{
		$positions = $priceList->{'Товары'}->{'тов'};

		return Kernel::toArray($positions);
	}

	public function extractPositionsFromOrder($order)
	{
		$positions = $order->{'Строки'}->{'стр'};

		return Kernel::toArray($positions);
	}

	public function extractShopsFromCatalogues($catalogues)
	{
		$shops = $catalogues->{'Справочники'}->{'Магазины'}->{'маг'};

		return Kernel::toArray($shops);
	}

	public function updateCatalogues($positionsToUpdate)
	{
		$error = false;

		$arguments = $this->prepareUpdateCataloguesArguments($positionsToUpdate);

		try
		{
			$this->callSoapMethod(self::update_catalogues,$arguments);

			$cacheKey = $this->currentSupplierData['login'] . '_catalogue';
			$this->memcache->set($cacheKey, null, null, 0);

		} catch(Exception $e)
		{
			$error = $e->getMessage();
		}

		return $error;
	}

	private function prepareUpdateCataloguesArguments($positionsToUpdate)
	{
		$arguments = array('Справочники' => array(
				'Товары' => array('тов' => array()),
				'Магазины' => array()
		    ),
		);

		foreach($positionsToUpdate as $position)
		{
			$arguments['Справочники']['Товары']['тов'][] = array(
				'ПкпИД' => $position[Translator::t('vlink_positionID')],
				'Назв' => $position[Translator::t('vlink_name')],
				'Ед' => $position[Translator::t('vlink_unit')],
				'Упак' => $position[Translator::t('vlink_package')],
				'ПстИД' => $position[Translator::t('vlink_supplierId_key')] ? $position[Translator::t('vlink_supplierId_key')] : $position[Translator::t('vlink_gtin')]
			);
		}

		return $arguments;
	}

	public function refreshVlinkGlnsForAllSuppliers()
	{
		$suppliersList = $this->getSuppliersList(null,'login',false);
		foreach($suppliersList as $supplierData)
		{
		if ($supplierData['isEnabled'] == '1'){
			$this->setCurrentConnectionData($supplierData);
			try
			{
				$this->refreshVlinkGlns();
			} catch (Exception $ex) {
				$this->log(sprintf('%s; login456:%s', $ex->getMessage(), $this->currentSupplierData['login']));
			}
		}
		}

		return $this;
	}

	public function refreshVlinkGlns()
	{
		$catalogues = $this->getCatalogues();
		$shops = $this->extractShopsFromCatalogues($catalogues);

		foreach($shops as $shopData)
		{
			$this->updateGlnsInfo($shopData);
		}
	}

	private function updateGlnsInfo($shopData)
	{
		$vlinkShopDataIdSuffix = $shopData->{'ПкпИД'};
		$vlinkShopDataID = $this->calculateVlinkShopID($vlinkShopDataIdSuffix);
		$vlinkGlnsTableData = $this->vlinkGlnsTable->GetByFields(array('varShopID' => $vlinkShopDataID));

		$newVlinkGlnData = array();
		$newVlinkGlnData['intVlinkGlnID'] = $vlinkGlnsTableData['intVlinkGlnID'];
		$newVlinkGlnData['varGln'] = (int) $shopData->{'GLN'};
		$newVlinkGlnData['varShopID'] = $vlinkShopDataID;
		$newVlinkGlnData['varName'] = $shopData->{'Назв'};
		$newVlinkGlnData['varEdrpouCode'] = $shopData->{'КПП'};
		$newVlinkGlnData['varStreet'] = $shopData->{'Адрес'};

		$this->updateVlinkGlnsTable($vlinkGlnsTableData,$newVlinkGlnData);
		$this->updateVlinkGlnsInGlnsTable($newVlinkGlnData);
	}

	private function updateVlinkGlnsTable($vlinkGlnsTableData,$newVlinkGlnData)
	{
		$vlinkGlnsTableGLN = (int) $vlinkGlnsTableData['varGln'];
		$vlinkShopDataGLN = $newVlinkGlnData['varGln'];

		if(empty($vlinkGlnsTableData))
		{
			unset($newVlinkGlnData['intVlinkGlnID']);

			$this->vlinkGlnsTable->Insert($newVlinkGlnData);

			$message = sprintf('Inserted new row into vlinkGlnTable: gln - %s,varShopID - %s',
				$newVlinkGlnData['varGln'],
				$newVlinkGlnData['varShopID']);

			//$this->log($message);
		}
		else
		{
			if($vlinkGlnsTableGLN !== $vlinkShopDataGLN)
			{
				$this->vlinkGlnsTable->Update($newVlinkGlnData);

				$message = sprintf('Updated row in vlinkGlnTable: gln - %s,varShopID - %s',
					$newVlinkGlnData['varGln'],
					$newVlinkGlnData['varShopID']);

				//$this->log($message);
			}
		}
	}

	private function updateVlinkGlnsInGlnsTable($newVlinkGlnData)
	{
		$new_partner = array();

		if($newVlinkGlnData['varGln'])
		{
			$glnExistsData = $this->glnsTable->GetByFields(array('varGln' => $newVlinkGlnData['varGln']));
			if(empty($glnExistsData))
			{
				$new_partner = $newVlinkGlnData;
				$new_partner['intSubserviceID'] = subservicesTable::ru;
				$new_partner['intAccountID'] = self::vlinkAccountID;
				$new_partner['intRetailerID'] = self::vlinkRetailerID;

				$id = $this->glnsTable->Insert($new_partner, false, true);

				$this->usersTable->InsertUserGLN($id, self::vlinkUserID);

				$message = sprintf('Inserted new gln with data: %s',print_r($new_partner,true));
				//$this->log($message);
			}
			else
			{
				if (mb_strlen($glnExistsData['varStreet']) > 10)
				{
					$newVlinkGlnData['varStreet'] = $glnExistsData['varStreet'];
				}
				$glnExistsData = array_replace_recursive($glnExistsData, $newVlinkGlnData);

				$id = $this->glnsTable->Update($glnExistsData);

				$message = sprintf('Updated gln with data: varEdrpouCode - %s',print_r($glnExistsData['varEdrpouCode'],true));
				//$this->log($message);
			}
		}
		return $id;
	}

	public function writeNewDocument($docid, $xml, $docType)
	{
		$documentCreated = true;

		try
		{
			$fileName = sprintf('%s_%s%d.xml', strtoupper($docType), date('YmdHis'), $docid);
			$this->createXSDRecord($this->suppl_id, $fileName, self::outbox_folder, $xml, strlen($xml));

			$this->log(sprintf('Document %s created and written to %s;', $fileName, self::outbox_folder));
		} catch (Exception $exception)
		{
			$this->log(sprintf('%s %s was not created:%s',$docType,$docid,$exception->getMessage()));

			$documentCreated = false;
		}

		return $documentCreated;
	}

	public function writeNewDocumentError($docid, $xml, $docType)
	{
		$documentCreated = true;

		try
		{
			$fileName = sprintf('%s_%s%d.xml', strtoupper($docType), date('YmdHis'), $docid);
			$this->createXSDRecord($this->suppl_id, $fileName, self::error_folder, $xml, strlen($xml));

			$this->log(sprintf('Document %s created and written to %s;', $fileName, self::error_folder));
		} catch (Exception $exception)
		{
			$this->log(sprintf('%s %s was not created:%s',$docType,$docid,$exception->getMessage()));

			$documentCreated = false;
		}

		return $documentCreated;
	}

	public function setCurrentConnectionDataByLogin($auth_login = null)
	{
		$this->setSupplierLogin($auth_login);
		$supplierData = $this->getSuppliersList();
		$supplierData = reset($supplierData);
		$this->setCurrentConnectionData($supplierData);

		return $this;
	}

	public function prepareOrdersXML($docids = array())
	{
		if (!empty($docids))
		{
			$orderBodies = $this->getOrdersBodies($docids);
			$this->ordersXmls = $this->buildDocumentsXmls($orderBodies);
		}
		return $this;
	}

	public function getOrdersWithoutRecadv()
	{
		//передаем дополнительный параметр в фильтр по списку заказов без recadv
		//берем текущую дату
		$yyyy=date('Y');
		$mm=date('m');
		$dd=date('d');
		//отнимаем месяц от текущей даты
		$mm=$mm-1;
		if($mm==0){$mm=12; $yyyy=$yyyy-1;}
		//переводим в формат 'yy-mm-dd'
		$yymmdd = date('y-m-d', mktime(0,0,0,$mm,$dd,$yyyy));
		//запрашиваем только заказы не старше 1 мес от текущей даты
		$OrderData = array('intHasRecadv' => 0, 'varType' => 'order','FROMcreated' => $yymmdd);

//		$OrderData = array('intHasRecadv' => 0, 'varType' => 'order');

		$res = $this->getDocsInboxByData($OrderData);

		return $res;
	}

	public function setOrderHasRecadv($docid)
	{
		if (empty($docid))
			return;

		$data = $this->vlinkDocsInboxTable->GetByFields(array('intVlinkDocID' => $docid));
		$data['intHasRecadv'] = 1;
		$this->vlinkDocsInboxTable->Update($data);
	}

	public function getPriceList($date)
	{
		$priceList = $this->getCachedPriceList($date);

		return $priceList;
	}

	private function getCachedPriceList($date)
	{
		$cacheKey = $this->currentSupplierData['login'] . '_priceList_'.$date;

		$priceListData = $this->memcache->get($cacheKey);
		if(!$priceListData)
		{
			$priceListData = $this->callSoapMethod(self::get_price_list,array('date' => $date));
			$this->memcache->set($cacheKey,$priceListData,null,self::cachePriceListExpireSeconds);
		}

		return $priceListData;
	}

	public function setDocumentToReload($document)
	{
		$newOrderData = array(
			'varType' => 'order',
			'isReload' => 1,
			'intVlinkDocID' => $document->{'ИД'},
			'auth_login' => $this->currentSupplierData['login'],
		);

		$docExists = $this->vlinkDocsInboxTable->Get(array('intVlinkDocID' => $newOrderData['intVlinkDocID']));
		if(empty($docExists))
		{
    $newDocumentData['created']= date('Y-m-d H:i:s');
			$this->vlinkDocsInboxTable->Insert($newOrderData,true);
		} else
		{
			$this->vlinkDocsInboxTable->Update($newOrderData);
		}
	}

	public function unsetDocumentToReload($intVlinkDocID)
	{
		if (empty($intVlinkDocID))
		{
			return;
		}

		$data = array(
			'isReload' => 0,
			'intVlinkDocID' => $intVlinkDocID
		);

		$this->vlinkDocsInboxTable->Update($data);
	}

	public function getDocsInboxByData($filter = array())
	{
		$ret = array();
		$res = $this->vlinkDocsInboxTable->GetList($filter);

		while(list($key, $val) = each($res))
		{
			if (empty($val['auth_login']))
			{
				continue;
			}

			$ret[$val['auth_login']][] = $val['intVlinkDocID'];
		}
		return $ret;
	}

	public function getOrdersXML()
	{
		return $this->ordersXmls;
	}

	public function reloadPartlyConfirmedOrders()
	{
		if (!empty($this->partlyConfirmedOrdersIDs))
		{
			$where = array('intVlinkDocID' => $this->partlyConfirmedOrdersIDs);
			$this->vlinkDocsInboxTable->deleteByFields($where);

			$ordersBodies = $this->getOrdersBodies($this->partlyConfirmedOrdersIDs);
			$ordersXmls = $this->buildDocumentsXmls($ordersBodies);
			$this->writeNewOrders($ordersXmls);
		}

		return $this;
	}

	public function checkPartlyConfirmedOrders()
	{
		$ordersTable = new OrdersTable($this->connection);

		if (!empty($this->partlyConfirmedOrdersIDs))
		{
			$docsIDs = $this->vlinkDocsInboxTable->getDocsIDsByVlinkDocsIDs($this->partlyConfirmedOrdersIDs);
			$ordersTable->checkDocsByIDsAsPartlyConfirmed($docsIDs);
		}

		return $this;
	}

	public function clearPositionsGtins(&$positions)
	{
		foreach($positions as &$positionData)
		{
			if (!empty($positionData['ТовПстИД']))
			{
				$tmp = $this->extractGtinFromBuyerPosition($positionData['ТовПстИД']);
				if (!empty($tmp))
				{
					$positionData['ТовПстИД'] = $tmp;
				}
			}
		}
		unset($positionData);

		return $this;
	}

	protected function getSuppliersUniqueByRegion($tempSuppliers)
	{
		$result = array();

		foreach ($tempSuppliers as $key => $value)
		{
			$result[substr($key, 0, 3)] = $value;
		}

		return $result;
	}

	public function updateShops()
	{
		$this->log('Start updateShops');

		$tempSuppliers = $this->getSuppliersList(NULL, 'login');

		$suppliers = $this->getSuppliersUniqueByRegion($tempSuppliers);

		foreach ($suppliers as $supplier)
		{
			$this->log('Get shops for ' . $supplier['login']);

			$soapResponse = $this->setCurrentConnectionDataByLogin($supplier['login'])
				->callSoapMethod(self::get_shop_list);
			$shops = Kernel::toArray($soapResponse->{'Магазины'}->{'маг'});

			$this->log(sprintf('Found %d shops. Update shops', count($shops)));
			foreach($shops as $shopData)
			{
				$this->updateGlnsInfo($shopData);
			}
			$this->log('Start update leradata_vlink_shops');
			$this->shopsListUpdate($shops);
			$this->log('End update leradata_vlink_shops');
			
		}

		$this->log('End updateShops');
	}
	
	public function shopsListUpdate($shops){
	
	$vlinkShopsTable = new vlinkShopsTable($this->connection);
	
	$arr_ins = array();
	
	foreach ($shops as $shopData){
	
	$data = array();
	
	foreach ($shopData as $tempKey => $tempValue){
		$key = $tempKey; $value = $tempValue;
		
		if ($key == 'GLN'){
			$data['gln'] = $value;
		}
	
		if ($key == 'КПП'){
			$data['kpp'] = $value;
		}
	
		if ($key == 'Формат'){
			$data['format'] = $value;
		}
	
		if ($key == 'Филиал'){
			$data['branch'] = $value;
		}
	}
	

	$arr = $vlinkShopsTable->GetList(array(
	'gln' => $data['gln'], 'kpp' => $data['kpp'], 'format' => $data['format'], 
	'branch' => $data['branch']));
	
	if (empty($arr)){
		$vlinkShopsTable->Insert($data);
		array_push($arr_ins, $data);
	}
	
	}
	
	if (!empty($arr_ins)){
	echo ("\n".'INSERT'."\n"."\n");
	printf ( "%-15s%-30s%-20s%-40s%-30s\n%'=136s\n", "INTID", "GLN","KPP","FORMAT","BRANCH","");
		foreach ($arr_ins as $ins){
		//echo ("\n");
		printf ( "%-15s%-30s%-20s%-60s%-30s\n%", $ins['intID'], $ins['gln'],$ins['kpp'],$ins['format'],$ins['branch'],"-");}
	$arr_ins = array();
	}
	
	
	}
	
	
	public function getFieldValue($str)
	{
		$divider = ">";
		$unused = array("stdClass::__set_state(array(","СайтИД","=");
		
		$str = str_replace($unused,"",$str);
		
		$value = trim(str_replace("'","",substr(
		$str,strpos($str, $divider) + 1), strrpos($str, "'")));
		
		
		
		return trim(str_replace(">","",$value));
	}
	
	public function getTokens($str){
		
		$temp = substr($str,strpos($str,"0 =>"),strrpos($str,")),",-11));
		$result = substr($temp,0,strrpos($temp,")),")+3);
		return $result;
	}
	
	public function numberFormat($digit, $width) {
	    while(strlen($digit) < $width)
	          $digit = '0' . $digit;
	                return $digit;
	}
	
	
	
	public function vlinkVerniyAuth()
	{
	
	$accesses = array (	
	"P01-LERADATA" => "BYG6HF",
	"P02-LERADATA" => "MOJ222",
	"P03-LERADATA" => "7IEYMO",
	"P04-LERADATA" => "66AEE5");
	
	//$accesses = array ("P02-LERADATA" => "MOJ222");
	
	$arr_ins = array();
	$arr_up = array();
	
	echo ("\n".'START PROCESS => '.date("Y-m-d H:i:s")."\n");
	foreach ($accesses as $key => $value)
	{
	
	$this->setCurrentConnectionData(array ( 'login'=>$key,  'password'=>$value ));
	
	header('Content-Type: text/html; charset=utf-8');
	$xml = $this->callSoapMethod('WhoAmI');
	
	$pureData = $this->getTokens(var_export($xml,true));
	$vlinkAuthTable = new vlinkAuthTable ($this->connection);
	$tokens = split('СайтИД',$pureData);
	foreach ($tokens as $token){
	
		$pars = explode(","."\n",$token);
		$sideid = $this->getFieldValue($pars[0]);
		$authid = $this->getFieldValue($pars[1]);
		$login = $this->getFieldValue($pars[2]);
		$name = $this->getFieldValue($pars[3]);
		$ShortName = $this->getFieldValue($pars[4]);
		$adress = $this->getFieldValue($pars[5]);
		$VarINN = $this->getFieldValue($pars[6]);
		
		$arr = $vlinkAuthTable->GetList(array('login'=> $login));
		
		$tempLogin = $arr[0]['login'];
	
	if (!empty($login)){
		if ($login == $tempLogin){
		$tempName = $arr[0]['name'];
		
		$tempSideid = $arr[0]['sideid'];
		$tempAuthid = $arr[0]['authid'];
		$tempInName = $arr[0]['ShortName'];
		$tempAdress = $arr[0]['adress'];
		$tempInn =    $arr[0]['VarINN'];
			if((strcmp($tempName,$name) !='0' )
			|| (strcmp($tempSideid, $sideid) !='0' )
			|| (strcmp($tempAuthid, $authid) !='0' )
			|| (strcmp($tempInName, $ShortName) !='0' )
			|| (strcmp($tempAdress, $adress) !='0' )
			|| (strcmp($tempInn, $VarINN) !='0')
			){
				
				$up_data = array();
				$up_data['id'] = $arr[0]['id'];
				$up_data['login'] = $arr[0]['login'];
				$up_data['password'] = $arr[0]['password'];
				$up_data['gln'] = $arr[0]['gln'];
				$up_data['intCatalogueLastUpdateTimestamp'] = $arr[0]['intCatalogueLastUpdateTimestamp'];
				$up_data['isEnabled'] = $arr[0]['isEnabled'];
				$up_data['sideid'] = $sideid;
				$up_data['authid'] = $authid;
				$up_data['ShortName'] = $ShortName;
				$up_data['adress'] = $adress;
				$up_data['VarINN'] = $VarINN;
				$up_data['name'] = $name;
				$vlinkAuthTable->Update($up_data);
				array_push($arr_up, $up_data);
				}
			}
			if ($login != $tempLogin){
			$data = array();
			$data['login'] = $login;
			$data['sideid']=$sideid;
			$data['authid']=$authid;
			$data['ShortName']=$ShortName;
			$data['adress']=$adress;
			$data['VarINN']=$VarINN;
			$data['name']=$name;
			$vlinkAuthTable->Insert($data);
			array_push($arr_ins, $data);
			
			}
			
	}
	else{continue;}
	
	
	}
	
	//end accesses foreach
	}
	
	
	echo ("\n".'UPDATE'."\n"."\n");
		printf ( "%-15s%-10s%-10s%-60s%-50s%-15s%-30s\n%'=170s\n", "LOGIN", "SIDEID","AUTHID","NAME","INNAME","INN","ADRESS","");
		foreach ($arr_up as $up){
		printf ( "%-15s%-10s%-10s%-40s%-50s%-15s%-30s'\n", $up['login'], $up['sideid'],$up['authid'],$up['name'],$up['ShortName'],$up['VarINN'],$up['adress'],"-"); 
		}
	
	
	echo ("\n".'INSERT'."\n"."\n");
		printf ( "%-15s%-10s%-10s%-60s%-50s%-15s%-30s\n%'=170s\n", "LOGIN", "SIDEID","AUTHID","NAME","INNAME","INN","ADRESS","");
		foreach ($arr_ins as $ins){
		echo ("\n");
		printf ( "%-15s%-10s%-10s%-60s%-50s%-15s%-30s\n%", $ins['login'], $ins['sideid'],$ins['authid'],$ins['name'],$ins['ShortName'],$ins['VarINN'],$ins['adress'],"-");
	}
	
	echo ("\n".'END PROCESS => '.date("Y-m-d H:i:s")."\n");
	die;
	}
}
