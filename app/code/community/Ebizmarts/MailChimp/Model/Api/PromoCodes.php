<?php

/**
 * mailchimp-lib Magento Component
 *
 * @category  Ebizmarts
 * @package   mailchimp-lib
 * @author    Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Ebizmarts_MailChimp_Model_Api_PromoCodes extends Ebizmarts_MailChimp_Model_Api_ItemSynchronizer
{
    const BATCH_LIMIT = 50;

    protected $_batchId;
    /**
     * @var Ebizmarts_MailChimp_Model_Api_PromoRules
     */
    protected $_apiPromoRules;

    /**
     * @var $_ecommercePromoCodesCollection Ebizmarts_MailChimp_Model_Resource_Ecommercesyncdata_PromoCodes_Collection
     */
    protected $_ecommercePromoCodesCollection;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @return array
     */
    public function createBatchJson()
    {
        $mailchimpStoreId = $this->getMailchimpStoreId();
        $magentoStoreId = $this->getMagentoStoreId();

        $this->_ecommercePromoCodesCollection = $this->getEcommercePromoCodesCollection();
        $this->_ecommercePromoCodesCollection->setMailchimpStoreId($mailchimpStoreId);
        $this->_ecommercePromoCodesCollection->setStoreId($magentoStoreId);

        $batchArray = array();
        $this->_batchId = 'storeid-'
            . $magentoStoreId . '_'
            . Ebizmarts_MailChimp_Model_Config::IS_PROMO_CODE . '_'
            . $this->getDateHelper()->getDateMicrotime();
        $batchArray = array_merge($batchArray, $this->_getDeletedPromoCodes());
        $batchArray = array_merge($batchArray, $this->_getNewPromoCodes($mailchimpStoreId, $magentoStoreId));

        return $batchArray;
    }

    /**
     * @return array
     */
    protected function _getDeletedPromoCodes()
    {
        $mailchimpStoreId = $this->getMailchimpStoreId();
        $batchArray = array();
        $deletedPromoCodes = $this->makeDeletedPromoCodesCollection();
        $counter = 0;

        foreach ($deletedPromoCodes as $promoCode) {
            $promoCodeId = $promoCode->getRelatedId();
            $promoRuleId = $promoCode->getDeletedRelatedId();
            $batchArray[$counter]['method'] = "DELETE";
            $batchArray[$counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId
                . '/promo-rules/' . $promoRuleId
                . '/promo-codes/' . $promoCodeId;
            $batchArray[$counter]['operation_id'] = $this->_batchId . '_' . $promoCodeId;
            $batchArray[$counter]['body'] = '';
            $this->deletePromoCodeSyncData($promoCodeId);
            $counter++;
        }

        return $batchArray;
    }

    /**
     * @param $mailchimpStoreId
     * @param $magentoStoreId
     * @return array
     */
    protected function _getNewPromoCodes($mailchimpStoreId, $magentoStoreId)
    {
        $batchArray = array();
        $helper = $this->getHelper();
        $dateHelper = $this->getDateHelper();
        $newPromoCodes = $this->makePromoCodesCollection($magentoStoreId);

        $this->joinMailchimpSyncDataWithoutWhere($newPromoCodes);
        // be sure that the orders are not in mailchimp
        $websiteId = Mage::getModel('core/store')->load($magentoStoreId)->getWebsiteId();
        $autoGeneratedCondition = "salesrule.use_auto_generation = 1 AND main_table.is_primary IS NULL";
        $notAutoGeneratedCondition = "salesrule.use_auto_generation = 0 AND main_table.is_primary = 1";

        $where = "m4m.mailchimp_sync_delta IS NULL AND website.website_id = " . $websiteId
            . " AND ( " . $autoGeneratedCondition . " OR " . $notAutoGeneratedCondition . ")";

        $this->_ecommercePromoCodesCollection->addWhere($newPromoCodes, $where);
        // send most recently created first
        $newPromoCodes->getSelect()->order(array('salesrule.rule_id DESC'));
        // limit the collection
        $this->_ecommercePromoCodesCollection->limitCollection($newPromoCodes, $this->getBatchLimitFromConfig());

        $counter = 0;

        foreach ($newPromoCodes as $promoCode) {
            $codeId = $promoCode->getCouponId();
            $ruleId = $promoCode->getRuleId();

            try {
                $promoRuleSyncData = $this->getMailchimpEcommerceSyncDataModel()->getEcommerceSyncDataItem(
                    $ruleId,
                    Ebizmarts_MailChimp_Model_Config::IS_PROMO_RULE,
                    $mailchimpStoreId
                );

                if (!$promoRuleSyncData->getId()) {
                    $promoRuleMailchimpData = $this->getApiPromoRules()->getNewPromoRule(
                        $ruleId,
                        $mailchimpStoreId,
                        $magentoStoreId
                    );

                    if (!empty($promoRuleMailchimpData)) {
                        $batchArray[$counter] = $promoRuleMailchimpData;
                        $counter++;
                    } else {
                        $this->setCodeWithParentError($ruleId, $codeId);
                        continue;
                    }
                }

                if ($promoRuleSyncData->getMailchimpSyncError()) {
                    $this->setCodeWithParentError($ruleId, $codeId);
                    continue;
                }

                $promoCodeData = $this->generateCodeData($promoCode, $magentoStoreId);
                $promoCodeJson = json_encode($promoCodeData);

                if ($promoCodeJson !== false) {
                    if (!empty($promoCodeData)) {
                        $batchArray[$counter]['method'] = "POST";
                        $batchArray[$counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId
                            . '/promo-rules/' . $ruleId . '/promo-codes';
                        $batchArray[$counter]['operation_id'] = $this->_batchId . '_' . $codeId;
                        $batchArray[$counter]['body'] = $promoCodeJson;

                        $this->addSyncDataToken($codeId, $promoCode->getToken());
                        $counter++;
                    } else {
                        $error = $helper->__('Something went wrong when retrieving the information.');
                        $this->addSyncDataError(
                            $codeId,
                            $error,
                            null,
                            false,
                            $dateHelper->formatDate(null, "Y-m-d H:i:s")
                        );
                        continue;
                    }
                } else {
                    $jsonErrorMsg = json_last_error_msg();
                    $this->logSyncError(
                        "Promo code" . $codeId . " json encode failed (".$jsonErrorMsg.")",
                        Ebizmarts_MailChimp_Model_Config::IS_PROMO_CODE,
                        $mailchimpStoreId, $magentoStoreId
                    );

                    $this->addSyncDataError(
                        $codeId,
                        $jsonErrorMsg,
                        null,
                        false,
                        $dateHelper->formatDate(null, "Y-m-d H:i:s")
                    );
                }
            } catch (Exception $e) {
                $this->logSyncError(
                    $e->getMessage(),
                    Ebizmarts_MailChimp_Model_Config::IS_PROMO_CODE,
                    $mailchimpStoreId, $magentoStoreId
                );
            }
        }

        return $batchArray;
    }

    /**
     * @return mixed
     */
    protected function getBatchLimitFromConfig()
    {
        $batchLimit = self::BATCH_LIMIT;
        return $batchLimit;
    }

    /**
     * @return Mage_SalesRule_Model_Resource_Coupon_Collection
     */
    protected function getPromoCodeResourceCollection()
    {
        return Mage::getResourceModel('salesrule/coupon_collection');
    }

    /**
     * @param $magentoStoreId
     * @return Mage_SalesRule_Model_Resource_Coupon_Collection
     */
    public function makePromoCodesCollection($magentoStoreId)
    {
        $helper = $this->getHelper();
        /**
         * @var Mage_SalesRule_Model_Resource_Coupon_Collection $collection
         */
        $collection = $this->getPromoCodeResourceCollection();
        $helper->addResendFilter(
            $collection,
            $magentoStoreId,
            Ebizmarts_MailChimp_Model_Config::IS_PROMO_CODE
        );
        $this->addWebsiteColumn($collection);
        $this->joinPromoRuleData($collection);

        $this->_ecommercePromoCodesCollection->addWebsiteColumn($collection);
        $this->_ecommercePromoCodesCollection->joinPromoRuleData($collection);

        return $collection;
    }

    /**
     * @return object
     */
    protected function makeDeletedPromoCodesCollection()
    {
        $deletedPromoCodes = $this->getMailchimpEcommerceSyncDataModel()->getCollection();
        $where = "mailchimp_store_id = '" . $this->getMailchimpStoreId()
            . "' AND type = '" . Ebizmarts_MailChimp_Model_Config::IS_PROMO_CODE
            . "' AND mailchimp_sync_deleted = 1";

        $this->_ecommercePromoCodesCollection->addWhere($deletedPromoCodes, $where, $this->getBatchLimitFromConfig());

        return $deletedPromoCodes;
    }

    /**
     * @param $collection
     */
    public function joinMailchimpSyncDataWithoutWhere($collection)
    {
        $columns = array(
            "m4m.related_id",
            "m4m.type",
            "m4m.mailchimp_store_id",
            "m4m.mailchimp_sync_delta",
            "m4m.mailchimp_sync_modified"
        );

        $this->_ecommercePromoCodesCollection->joinLeftEcommerceSyncData($collection, $columns);
    }

    protected function generateCodeData($promoCode, $magentoStoreId)
    {
        $data = array();
        $code = $promoCode->getCode();
        $data['id'] = $promoCode->getCouponId();
        $data['code'] = $code;

        //Set title as description if description null
        $data['redemption_url'] = $this->getRedemptionUrl($promoCode, $magentoStoreId);

        return $data;
    }

    protected function getRedemptionUrl($promoCode, $magentoStoreId)
    {
        $token = $this->getToken();
        $promoCode->setToken($token);
        $url = Mage::getModel('core/url')->setStore($magentoStoreId)->getUrl(
            'mailchimp/cart/loadcoupon',
            array(
                    '_nosid' => true,
                    '_secure' => true,
                    'coupon_id' => $promoCode->getCouponId(),
                    'coupon_token' => $token
                )
        )
            . 'mailchimp/cart/loadcoupon?coupon_id='
            . $promoCode->getCouponId()
            . '&coupon_token='
            . $token;

        return $url;
    }

    /**
     * @return string
     */
    protected function getToken()
    {
        $token = hash('md5', rand(0, 9999999));
        return $token;
    }

    /**
     * @return Ebizmarts_MailChimp_Model_Api_PromoRules|false|Mage_Core_Model_Abstract
     */
    public function getApiPromoRules()
    {
        if (!$this->_apiPromoRules) {
            $this->_apiPromoRules = Mage::getModel('mailchimp/api_promoRules');
        }

        return $this->_apiPromoRules;
    }

    /**
     * @param $codeId
     * @param $promoRuleId
     */
    public function markAsDeleted($codeId, $promoRuleId)
    {
        $this->_setDeleted($codeId, $promoRuleId);
    }

    /**
     * @param $codeId
     * @param $promoRuleId
     */
    protected function _setDeleted($codeId, $promoRuleId)
    {
        $promoCodes = $this->getMailchimpEcommerceSyncDataModel()->getAllEcommerceSyncDataItemsPerId(
            $codeId,
            Ebizmarts_MailChimp_Model_Config::IS_PROMO_CODE
        );

        foreach ($promoCodes as $promoCode) {
            $mailchimpStoreId = $promoCode->getMailchimpStoreId();
            $this->addDeletedRelatedId($codeId, $promoRuleId);
        }
    }

    /**
     * @param $promoRule
     * @throws Exception
     */
    public function deletePromoCodesSyncDataByRule($promoRule)
    {
        $promoCodeIds = $this->getPromoCodesForRule($promoRule->getRelatedId());

        foreach ($promoCodeIds as $promoCodeId) {
            $promoCodeSyncDataItems = $this->getMailchimpEcommerceSyncDataModel()->getAllEcommerceSyncDataItemsPerId(
                $promoCodeId,
                Ebizmarts_MailChimp_Model_Config::IS_PROMO_CODE
            );

            foreach ($promoCodeSyncDataItems as $promoCodeSyncDataItem) {
                $promoCodeSyncDataItem->delete();
            }
        }
    }

    /**
     * @param $mailchimpStoreId
     */
    public function deletePromoCodeSyncData($promoCodeId)
    {
        $promoCodeSyncDataItem = $this->getMailchimpEcommerceSyncDataModel()->getEcommerceSyncDataItem(
            $promoCodeId,
            Ebizmarts_MailChimp_Model_Config::IS_PROMO_CODE,
            $this->getMailchimpStoreId()
        );
        $promoCodeSyncDataItem->delete();
    }

    /**
     * @param $promoRuleId
     * @return array
     */
    protected function getPromoCodesForRule($promoRuleId)
    {
        $promoCodes = array();
        $helper = $this->getHelper();
        $promoRules = $this->getMailchimpEcommerceSyncDataModel()->getAllEcommerceSyncDataItemsPerId(
            $promoRuleId,
            Ebizmarts_MailChimp_Model_Config::IS_PROMO_RULE
        );

        foreach ($promoRules as $promoRule) {
            $mailchimpStoreId = $promoRule->getMailchimpStoreId();
            $api = $helper->getApiByMailChimpStoreId($mailchimpStoreId);

            if ($api !== null) {
                try {
                    $mailChimpPromoCodes = $api->ecommerce->promoRules->promoCodes
                        ->getAll($mailchimpStoreId, $promoRuleId);

                    foreach ($mailChimpPromoCodes['promo_codes'] as $promoCode) {
                        $this->deletePromoCodeSyncData($promoCode['id']);
                    }
                } catch (MailChimp_Error $e) {
                    $this->logSyncError(
                        $e->getFriendlyMessage(),
                        Ebizmarts_MailChimp_Model_Config::IS_PROMO_CODE,
                        $mailchimpStoreId
                    );
                }
            }
        }

        return $promoCodes;
    }

    /**
     * @param $promoCodeId
     * @return string
     */
    protected function getPromoRuleIdByCouponId($promoCodeId)
    {
        $coupon = Mage::getModel('salesrule/coupon')->load($promoCodeId);
        return $coupon->getRuleId();
    }

    /**
     * @param $ruleId
     * @param $codeId
     * @throws Mage_Core_Model_Store_Exception
     */
    protected function setCodeWithParentError($ruleId, $codeId)
    {
        $dateHelper = $this->getDateHelper();
        $error = Mage::helper('mailchimp')->__(
            'Parent rule with id ' . $ruleId . ' has not been correctly sent.'
        );
        $this->addSyncDataError(
            $codeId,
            $error,
            null,
            false,
            $dateHelper->formatDate(null, "Y-m-d H:i:s")
        );
    }

    /**
     * @return string
     */
    protected function getItemType()
    {
        return Ebizmarts_MailChimp_Model_Config::IS_PROMO_CODE;
    }

    /**
     * @return Ebizmarts_MailChimp_Model_Resource_Ecommercesyncdata_PromoCodes_Collection
     */
    public function getEcommercePromoCodesCollection()
    {
        /**
         * @var $collection Ebizmarts_MailChimp_Model_Resource_Ecommercesyncdata_PromoCodes_Collection
         */
        $collection = Mage::getResourceModel('mailchimp/ecommercesyncdata_promocodes_collection');

        return $collection;
    }
}
