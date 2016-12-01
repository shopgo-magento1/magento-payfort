<?php

require_once(Mage::getBaseDir('lib') . '/payfortFort/init.php');

class Payfort_Pay_Model_Payment_Naps extends Mage_Payment_Model_Method_Abstract
{

    protected $_code               = PAYFORT_FORT_PAYMENT_METHOD_NAPS;
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
