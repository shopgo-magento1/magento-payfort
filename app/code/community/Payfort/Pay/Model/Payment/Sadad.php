<?php
class Payfort_Pay_Model_Payment_Sadad extends Payfort_Pay_Model_Method
{

    protected $_code               = PAYFORT_FORT_PAYMENT_METHOD_SADAD;

    //protected $_canUseForMultishipping = false;
    //protected $_formBlockType = 'payfort/form_sadadoptions';
    //protected $_infoBlockType = 'payfort/info_sadaddetails';

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('payfort/payment/redirect', array('_secure' => true));
    }

}
