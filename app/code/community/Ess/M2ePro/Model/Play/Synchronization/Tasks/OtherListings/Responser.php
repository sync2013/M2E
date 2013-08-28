<?php

/*
 * @copyright  Copyright (c) 2012 by  ESS-UA.
 */

class Ess_M2ePro_Model_Play_Synchronization_Tasks_OtherListings_Responser
{
    protected $params = array();
    protected $synchronizationLog = NULL;

    /**
     * @var Ess_M2ePro_Model_Marketplace|null
     */
    protected $marketplace = NULL;

    /**
     * @var Ess_M2ePro_Model_Account|null
     */
    protected $account = NULL;

    protected $logActionId = NULL;

    // ########################################

    public function initialize(array $params = array(),
                               Ess_M2ePro_Model_Marketplace $marketplace = NULL,
                               Ess_M2ePro_Model_Account $account = NULL)
    {
        $this->params = $params;
        $this->marketplace = $marketplace;
        $this->account = $account;
    }

    // ########################################

    public function unsetLocks($hash, $fail = false, $message = NULL)
    {
        /** @var $lockItem Ess_M2ePro_Model_LockItem */
        $lockItem = Mage::getModel('M2ePro/LockItem');

        $tempNick = Ess_M2ePro_Model_Play_Synchronization_Tasks_OtherListings::LOCK_ITEM_PREFIX;
        $tempNick .= '_'.$this->params['account_id'].'_'.$this->params['marketplace_id'];

        $lockItem->setNick($tempNick);
        $lockItem->remove();

        $this->getAccount()->deleteObjectLocks(NULL,$hash);
        $this->getAccount()->deleteObjectLocks('synchronization',$hash);
        $this->getAccount()->deleteObjectLocks('synchronization_play',$hash);
        $this->getAccount()->deleteObjectLocks(
            Ess_M2ePro_Model_Play_Synchronization_Tasks_OtherListings::LOCK_ITEM_PREFIX,
            $hash
        );

        $this->getMarketplace()->deleteObjectLocks(NULL,$hash);
        $this->getMarketplace()->deleteObjectLocks('synchronization',$hash);
        $this->getMarketplace()->deleteObjectLocks('synchronization_play',$hash);
        $this->getMarketplace()->deleteObjectLocks(
            Ess_M2ePro_Model_Play_Synchronization_Tasks_OtherListings::LOCK_ITEM_PREFIX,
            $hash
        );

        /** @var $connWrite Varien_Db_Adapter_Pdo_Mysql */
        $connWrite = Mage::getSingleton('core/resource')->getConnection('core_write');
        $tempTable = Mage::getSingleton('core/resource')->getTableName('m2epro_play_processed_inventory');
        $connWrite->delete($tempTable,array('`hash` = ?'=>(string)$hash));

        $fail && $this->getSynchLogModel()->addMessage(Mage::helper('M2ePro')->__($message),
                                                       Ess_M2ePro_Model_Log_Abstract::TYPE_ERROR,
                                                       Ess_M2ePro_Model_Log_Abstract::PRIORITY_HIGH);
    }

    public function processSucceededResponseData($receivedItems, $hash, $nextPart)
    {
        $this->updateFirstSynchronizationTime();

        //--------------------
        $tempItems = array();
        foreach ($receivedItems as $receivedItem) {
            if (empty($receivedItem['sku'])) {
                continue;
            }
            $tempItems[$receivedItem['sku']] = $receivedItem;
        }
        $receivedItems = $tempItems;
        unset($tempItems);
        //--------------------

        $receivedItems = $this->filterReceivedOnlyOtherListings($receivedItems);

        try {

            $this->updateReceivedOtherListings($receivedItems);
            $this->createNotExistedOtherListings($receivedItems);

            $this->updateNotReceivedOtherListings($receivedItems,$hash,$nextPart);

        } catch (Exception $exception) {

            Mage::helper('M2ePro/Exception')->process($exception);

            $this->getSynchLogModel()->addMessage(Mage::helper('M2ePro')->__($exception->getMessage()),
                                                  Ess_M2ePro_Model_Log_Abstract::TYPE_ERROR,
                                                  Ess_M2ePro_Model_Log_Abstract::PRIORITY_HIGH);
        }
    }

    // ########################################

    protected function updateReceivedOtherListings($receivedItems)
    {
        /** @var $stmtTemp Zend_Db_Statement_Pdo */
        $stmtTemp = $this->getPdoStatementExistingListings(true);

        $tempLog = Mage::getModel('M2ePro/Listing_Other_Log');
        $tempLog->setComponentMode(Ess_M2ePro_Helper_Component_Play::NICK);

        while ($existingItem = $stmtTemp->fetch()) {

            if (!isset($receivedItems[$existingItem['sku']])) {
                continue;
            }

            $receivedItem = $receivedItems[$existingItem['sku']];

            $newData = array(
                'general_id' => (string)$receivedItem['general_id'],
                'general_id_type' => (string)$receivedItem['general_id_type'],
                'play_listing_id' => (int)$receivedItem['play_listing_id'],
                'title' => (string)$receivedItem['title'],
                'dispatch_to' => (string)$receivedItem['dispatch_to'],
                'dispatch_from' => (string)$receivedItem['dispatch_from'],
                'online_price_gbr' => (float)$receivedItem['price_gbr'],
                'online_price_euro' => (float)$receivedItem['price_euro'],
                'online_qty' => (int)$receivedItem['qty'],
                'condition' => (string)$receivedItem['condition'],
                'condition_note' => (string)$receivedItem['condition_note'],
                'start_date' => (string)date('c',strtotime($receivedItem['start_date']))
            );

            if ($newData['online_qty'] > 0) {
                $newData['status'] = Ess_M2ePro_Model_Listing_Product::STATUS_LISTED;
            } else {
                $newData['status'] = Ess_M2ePro_Model_Listing_Product::STATUS_STOPPED;
            }

            $existingData = array(
                'general_id' => (string)$existingItem['general_id'],
                'general_id_type' => (string)$existingItem['general_id_type'],
                'play_listing_id' => (int)$existingItem['play_listing_id'],
                'title' => (string)$existingItem['title'],
                'dispatch_to' => (string)$existingItem['dispatch_to'],
                'dispatch_from' => (string)$existingItem['dispatch_from'],
                'online_price_gbr' => (float)$existingItem['online_price_gbr'],
                'online_price_euro' => (float)$existingItem['online_price_euro'],
                'online_qty' => (int)$existingItem['online_qty'],
                'condition' => (string)$existingItem['condition'],
                'condition_note' => (string)$existingItem['condition_note'],
                'start_date' => (string)date('c',strtotime($existingItem['start_date'])),
                'status' => (int)$existingItem['status'],
            );

            if ($newData == $existingData) {
                continue;
            }

            if ($newData['online_qty'] > 0) {
                $newData['end_date'] = NULL;
            } else {
                $newData['end_date'] = Mage::helper('M2ePro')->getCurrentGmtDate();
            }

            if ($newData['status'] != $existingData['status']) {

                $newData['status_changer'] = Ess_M2ePro_Model_Listing_Product::STATUS_CHANGER_COMPONENT;

                $tempLogMessage = '';
                switch ($newData['status']) {
                    case Ess_M2ePro_Model_Listing_Product::STATUS_LISTED:
                        // Parser hack ->__('Item status was successfully changed to "Active".');
                        $tempLogMessage = 'Item status was successfully changed to "Active".';
                        break;
                    case Ess_M2ePro_Model_Listing_Product::STATUS_STOPPED:
                        // Parser hack ->__('Item status was successfully changed to "Inactive".');
                        $tempLogMessage = 'Item status was successfully changed to "Inactive".';
                        break;
                }

                $tempLog->addProductMessage(
                    (int)$existingItem['listing_other_id'],
                    Ess_M2ePro_Model_Log_Abstract::INITIATOR_EXTENSION,
                    $this->getLogActionId(),
                    Ess_M2ePro_Model_Listing_Other_Log::ACTION_CHANGE_STATUS_ON_CHANNEL,
                    $tempLogMessage,
                    Ess_M2ePro_Model_Log_Abstract::TYPE_SUCCESS,
                    Ess_M2ePro_Model_Log_Abstract::PRIORITY_LOW
                );
            }

            $listingOtherObj = Mage::helper('M2ePro/Component_Play')
                                ->getObject('Listing_Other',(int)$existingItem['listing_other_id']);

            $newData['condition_note'] == '' && $newData['condition_note'] = new Zend_Db_Expr("''");

            $listingOtherObj->addData($newData)->save();
        }
    }

    protected function createNotExistedOtherListings($receivedItems)
    {
        /** @var $stmtTemp Zend_Db_Statement_Pdo */
        $stmtTemp = $this->getPdoStatementExistingListings(false);

        while ($existingItem = $stmtTemp->fetch()) {

            if (!isset($receivedItems[$existingItem['sku']])) {
                continue;
            }

            $receivedItems[$existingItem['sku']]['founded'] = true;
        }

        /** @var $logModel Ess_M2ePro_Model_Listing_Other_Log */
        $logModel = Mage::getModel('M2ePro/Listing_Other_Log');
        $logModel->setComponentMode(Ess_M2ePro_Helper_Component_Play::NICK);

        /** @var $mappingModel Ess_M2ePro_Model_Play_Listing_Other_Mapping */
        $mappingModel = Mage::getModel('M2ePro/Play_Listing_Other_Mapping');

        /** @var $movingModel Ess_M2ePro_Model_Play_Listing_Other_Moving */
        $movingModel = Mage::getModel('M2ePro/Play_Listing_Other_Moving');

        foreach ($receivedItems as $receivedItem) {

            if (isset($receivedItem['founded'])) {
                continue;
            }

            $newData = array(
                'account_id' => (int)$this->params['account_id'],
                'marketplace_id' => (int)$this->params['marketplace_id'],
                'product_id' => NULL,

                'general_id' => (string)$receivedItem['general_id'],
                'general_id_type' => (string)$receivedItem['general_id_type'],
                'play_listing_id' => (int)$receivedItem['play_listing_id'],
                'sku' => (string)$receivedItem['sku'],

                'title' => (string)$receivedItem['title'],

                'dispatch_to' => (string)$receivedItem['dispatch_to'],
                'dispatch_from' => (string)$receivedItem['dispatch_from'],

                'online_price_gbr' => (float)$receivedItem['price_gbr'],
                'online_price_euro' => (float)$receivedItem['price_euro'],
                'online_qty' => (int)$receivedItem['qty'],

                'condition' => (string)$receivedItem['condition'],
                'condition_note' => (string)$receivedItem['condition_note'],

                'start_date' => (string)$receivedItem['start_date'],
                'end_date' => NULL,
            );

            if ((int)$newData['online_qty'] > 0) {
                $newData['status'] = Ess_M2ePro_Model_Listing_Product::STATUS_LISTED;
                $newData['end_date'] = NULL;
            } else {
                $newData['status'] = Ess_M2ePro_Model_Listing_Product::STATUS_STOPPED;
                $newData['end_date'] = Mage::helper('M2ePro')->getCurrentGmtDate();
            }

            $newData['status_changer'] = Ess_M2ePro_Model_Listing_Product::STATUS_CHANGER_COMPONENT;
            $newData['condition_note'] == '' && $newData['condition_note'] = new Zend_Db_Expr("''");
            $newData['start_date'] == '' && $newData['start_date'] = new Zend_Db_Expr("''");

            $listingOtherModel = Mage::helper('M2ePro/Component_Play')->getModel('Listing_Other');
            $listingOtherModel->setData($newData)->save();

            $logModel->addProductMessage($listingOtherModel->getId(),
                                         Ess_M2ePro_Model_Log_Abstract::INITIATOR_EXTENSION,
                                         NULL,
                                         Ess_M2ePro_Model_Listing_Other_Log::ACTION_ADD_LISTING,
                                         // Parser hack -> Mage::helper('M2ePro')->__('Item was successfully added');
                                         'Item was successfully added',
                                         Ess_M2ePro_Model_Log_Abstract::TYPE_NOTICE,
                                         Ess_M2ePro_Model_Log_Abstract::PRIORITY_LOW);

            if (!$this->getAccount()->getChildObject()->isOtherListingsMappingEnabled()) {
                continue;
            }

            $mappingModel->initialize($this->getMarketplace(),$this->getAccount());
            $mappingResult = $mappingModel->autoMapOtherListingProduct($listingOtherModel);

            if ($mappingResult) {

                if (!$this->getAccount()->getChildObject()->isOtherListingsMoveToListingsEnabled()) {
                    continue;
                }

                $movingModel->initialize($this->getMarketplace(),$this->getAccount());
                $movingModel->autoMoveOtherListingProduct($listingOtherModel);
            }
        }
    }

    //-----------------------------------------

    protected function updateNotReceivedOtherListings($receivedItems,$hash,$nextPart)
    {
        /** @var $connWrite Varien_Db_Adapter_Pdo_Mysql */
        $connWrite = Mage::getSingleton('core/resource')->getConnection('core_write');
        $tempTable = Mage::getSingleton('core/resource')->getTableName('m2epro_play_processed_inventory');

        //--------------------------
        $chunckedArray = array_chunk($receivedItems,1000);

        foreach ($chunckedArray as $partReceivedItems) {

            $inserts = array();
            foreach ($partReceivedItems as $partReceivedItem) {
                $inserts[] = array(
                    'sku' => $partReceivedItem['sku'],
                    'hash' => $hash
                );
            }

            $connWrite->insertMultiple($tempTable, $inserts);
        }
        //--------------------------

        if (!is_null($nextPart)) {
            return;
        }

        $listingOtherMainTable = Mage::getResourceModel('M2ePro/Listing_Other')->getMainTable();

        /** @var $collection Mage_Core_Model_Mysql4_Collection_Abstract */
        $collection = Mage::helper('M2ePro/Component_Play')->getCollection('Listing_Other');
        $collection->getSelect()->joinLeft(array('api' => $tempTable),
                 '`second_table`.sku = `api`.sku AND `api`.`hash` = \''.$hash.'\'', array('sku'));
        $collection->getSelect()->where('`main_table`.marketplace_id = ?',(int)$this->getMarketplace()->getId());
        $collection->getSelect()->where('`main_table`.account_id = ?',(int)$this->getAccount()->getId());
        $collection->getSelect()->where('`main_table`.`status` != ?',
            (int)Ess_M2ePro_Model_Listing_Product::STATUS_NOT_LISTED);
        $collection->getSelect()->where('`main_table`.`status` != ?',
            (int)Ess_M2ePro_Model_Listing_Product::STATUS_STOPPED);
        $collection->getSelect()->having('`api`.sku IS NULL');

        $tempColumns = array('main_table.id','main_table.status','api.sku');
        $collection->getSelect()->reset(Zend_Db_Select::COLUMNS)->columns($tempColumns);

        /** @var $stmtTemp Zend_Db_Statement_Pdo */
        $stmtTemp = $connWrite->query($collection->getSelect()->__toString());

        $tempLog = Mage::getModel('M2ePro/Listing_Other_Log');
        $tempLog->setComponentMode(Ess_M2ePro_Helper_Component_Play::NICK);

        $notReceivedIds = array();
        while ($notReceivedItem = $stmtTemp->fetch()) {

            if (!in_array((int)$notReceivedItem['id'],$notReceivedIds)) {
                $tempLog->addProductMessage(
                    (int)$notReceivedItem['id'],
                    Ess_M2ePro_Model_Log_Abstract::INITIATOR_EXTENSION,
                    $this->getLogActionId(),
                    Ess_M2ePro_Model_Listing_Other_Log::ACTION_CHANGE_STATUS_ON_CHANNEL,
                    // Parser hack ->__('Item status was successfully changed to "Inactive".');
                    'Item status was successfully changed to "Inactive".',
                    Ess_M2ePro_Model_Log_Abstract::TYPE_SUCCESS,
                    Ess_M2ePro_Model_Log_Abstract::PRIORITY_LOW
                );
            }

            $notReceivedIds[] = (int)$notReceivedItem['id'];
        }
        $notReceivedIds = array_unique($notReceivedIds);

        $bind = array(
            'status' => Ess_M2ePro_Model_Listing_Product::STATUS_STOPPED,
            'status_changer' => Ess_M2ePro_Model_Listing_Product::STATUS_CHANGER_COMPONENT
        );

        $chunckedIds = array_chunk($notReceivedIds,1000);
        foreach ($chunckedIds as $partIds) {
            $where = '`id` IN ('.implode(',',$partIds).')';
            $connWrite->update($listingOtherMainTable,$bind,$where);
        }
    }

    // ########################################

    protected function filterReceivedOnlyOtherListings(array $receivedItems)
    {
        /** @var $connWrite Varien_Db_Adapter_Pdo_Mysql */
        $connWrite = Mage::getSingleton('core/resource')->getConnection('core_write');

        /** @var $collection Mage_Core_Model_Mysql4_Collection_Abstract */
        $collection = Mage::helper('M2ePro/Component_Play')->getCollection('Listing_Product');
        $collection->getSelect()->reset(Zend_Db_Select::COLUMNS)->columns(array('second_table.sku'));

        $listingTable = Mage::getResourceModel('M2ePro/Listing')->getMainTable();
        $generalTemplateTable = Mage::getResourceModel('M2ePro/Template_General')->getMainTable();

        $collection->getSelect()->join(array('l' => $listingTable), 'main_table.listing_id = l.id', array());
        $collection->getSelect()->join(array('gt' => $generalTemplateTable), 'l.template_general_id = gt.id', array());
        $collection->getSelect()->where('gt.marketplace_id = ?',(int)$this->getMarketplace()->getId());
        $collection->getSelect()->where('gt.account_id = ?',(int)$this->getAccount()->getId());

        /** @var $stmtTemp Zend_Db_Statement_Pdo */
        $stmtTemp = $connWrite->query($collection->getSelect()->__toString());

        while ($existListingProduct = $stmtTemp->fetch()) {
            if (empty($existListingProduct['sku'])) {
                continue;
            }
            if (isset($receivedItems[$existListingProduct['sku']])) {
                unset($receivedItems[$existListingProduct['sku']]);
            }
        }

        return $receivedItems;
    }

    protected function getPdoStatementExistingListings($withData = false)
    {
        /** @var $connWrite Varien_Db_Adapter_Pdo_Mysql */
        $connWrite = Mage::getSingleton('core/resource')->getConnection('core_write');

        /** @var $collection Mage_Core_Model_Mysql4_Collection_Abstract */
        $collection = Mage::helper('M2ePro/Component_Play')->getCollection('Listing_Other');
        $collection->getSelect()->where('`main_table`.`account_id` = ?',(int)$this->params['account_id']);
        $collection->getSelect()->where('`main_table`.`marketplace_id` = ?',(int)$this->params['marketplace_id']);

        $tempColumns = array('second_table.sku');

        if ($withData) {
            $tempColumns = array('main_table.status','second_table.sku','second_table.title',
                                 'second_table.general_id','second_table.general_id_type',
                                 'second_table.play_listing_id',
                                 'second_table.online_price_gbr','second_table.online_price_euro',
                                 'second_table.online_qty','second_table.dispatch_to','second_table.dispatch_from',
                                 'second_table.condition','second_table.condition_note','second_table.start_date',
                                 'second_table.listing_other_id');
        }

        $collection->getSelect()->reset(Zend_Db_Select::COLUMNS)->columns($tempColumns);

        /** @var $stmtTemp Zend_Db_Statement_Pdo */
        $stmtTemp = $connWrite->query($collection->getSelect()->__toString());

        return $stmtTemp;
    }

    protected function updateFirstSynchronizationTime()
    {
        $childAccountObject = $this->getAccount()->getChildObject();

        $firstSynchronizationTime = $childAccountObject->getOtherListingsFirstSynchronization();

        if (!is_null($firstSynchronizationTime)) {
            return;
        }

        $childAccountObject->setData('other_listings_first_synchronization',
                                     Mage::helper('M2ePro')->getCurrentGmtDate())->save();
    }

    // ########################################

    /**
     * @return Ess_M2ePro_Model_Account
     */
    protected function getAccount()
    {
        return $this->account;
    }

    /**
     * @return Ess_M2ePro_Model_Marketplace
     */
    protected function getMarketplace()
    {
        return $this->marketplace;
    }

    //-----------------------------------------

    protected function getLogActionId()
    {
        if (!is_null($this->logActionId)) {
            return $this->logActionId;
        }

        return $this->logActionId = Mage::getModel('M2ePro/Listing_Other_Log')->getNextActionId();
    }

    protected function getSynchLogModel()
    {
        if (!is_null($this->synchronizationLog)) {
            return $this->synchronizationLog;
        }

        /** @var $runs Ess_M2ePro_Model_Synchronization_Run */
        $runs = Mage::getModel('M2ePro/Synchronization_Run');
        $runs->start(Ess_M2ePro_Model_Synchronization_Run::INITIATOR_UNKNOWN);
        $runsId = $runs->getLastId();
        $runs->stop();

        /** @var $logs Ess_M2ePro_Model_Synchronization_Log */
        $logs = Mage::getModel('M2ePro/Synchronization_Log');
        $logs->setSynchronizationRun($runsId);
        $logs->setComponentMode(Ess_M2ePro_Helper_Component_Play::NICK);
        $logs->setInitiator(Ess_M2ePro_Model_Synchronization_Run::INITIATOR_UNKNOWN);
        $logs->setSynchronizationTask(Ess_M2ePro_Model_Synchronization_Log::SYNCH_TASK_OTHER_LISTINGS);

        $this->synchronizationLog = $logs;

        return $this->synchronizationLog;
    }

    // ########################################
}