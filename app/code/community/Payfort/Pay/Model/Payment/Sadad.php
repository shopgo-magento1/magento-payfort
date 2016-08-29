<?php

require_once(MAGENTO_ROOT . '/lib/payfortFort/init.php');

class Payfort_Pay_Model_Payment_Sadad extends Mage_Payment_Model_Method_Abstract
{

    protected $_code               = PAYFORT_FORT_PAYMENT_METHOD_SADAD;
    protected $_isInitializeNeeded = true;
    protected $_canUseInternal     = true;

    //protected $_canUseForMultishipping = false;
    //protected $_formBlockType = 'payfort/form_sadadoptions';
    //protected $_infoBlockType = 'payfort/info_sadaddetails';

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('payfort/payment/redirect', array('_secure' => true));
    }

}
