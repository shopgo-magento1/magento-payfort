<?php

require_once(MAGENTO_ROOT . '/lib/payfortFort/init.php');

class Payfort_Pay_Model_Payment_Cc extends Mage_Payment_Model_Method_Abstract
{

    protected $_code               = PAYFORT_FORT_PAYMENT_METHOD_CC;
    protected $_isInitializeNeeded = true;
    protected $_canUseInternal     = true;
    public $pfConfig;

    //protected $_canUseForMultishipping = false;
    //protected $_formBlockType = 'payfort/form_ccoptions';
    //protected $_infoBlockType = 'ewayrapid/info_sharedpage_ewayone';

    public function __construct()
    {
        $this->pfConfig = Payfort_Fort_Config::getInstance();
//        //$this->_code    = PAYFORT_FORT_PAYMENT_METHOD_CC;
        if ($this->pfConfig->getCcIntegrationType() == PAYFORT_FORT_INTEGRATION_TYPE_MERCAHNT_PAGE2) {
            $this->_formBlockType = 'payfort/form_cc_notsaved';
            $this->_infoBlockType = 'payfort/info_cc_notsaved';
        }
        else {
            $this->_formBlockType = 'payfort/form_gateway';
        }
        parent::__construct();
        
    }

    public function getOrderPlaceRedirectUrl()
    {
        if($this->pfConfig->getCcIntegrationType() == PAYFORT_FORT_INTEGRATION_TYPE_MERCAHNT_PAGE2) {
            $postData = Mage::app()->getRequest()->getPost();
            if(isset($postData['pluginName']) && $postData['pluginName'] == 'OneStepCheckout') {
                return Mage::getUrl('/', array('_secure' => true));
            }
            return '';
        }
        return Mage::getUrl('payfort/payment/redirect', array('_secure' => true));
    }

}
