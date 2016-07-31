<?php

class Payfort_Pay_Helper_Data extends Mage_Core_Helper_Abstract {

    const PAYFORT_FORT_LOG_FILE = 'payfortfort.log';
    
    private $_gatewayHost        = 'https://checkout.payfort.com/';
    private $_gatewaySandboxHost = 'https://sbcheckout.payfort.com/';
    //private $_gatewaySandboxHost = 'https://checkout.fortstg.com/';
    
    public function deleteallCartItems() {
        $cartHelper = Mage::helper('checkout/cart');
        $items = $cartHelper->getCart()->getItems();
        foreach ($items as $item) {
            $itemId = $item->getItemId();
            $cartHelper->getCart()->removeItem($itemId)->save();
        }
    }

    /**
     * Translates the response code into a more meaningful description.
     * Response code descriptions are taken directly from the Payfort documentation.
     */
    function getResponseCodeDescription($responseCode) {
        switch ($responseCode) {
            case "0" : $result = "Invalid or incomplete";
                break;
            case "1" : $result = "Cancelled by customer";
                break;
            case "2" : $result = "Authorisation declined";
                break;
            case "5" : $result = "Authorised";
                break;
            case "9" : $result = "Payment requested";
                break;
            default : $result = "Response Unknown";
        }

        return $result;
    }
    
    /**
     * Convert Amount with dicemal points
     * @param decimal $amount
     * @param string $baseCurrencyCode
     * @param string  $currentCurrencyCode
     * @return decimal
     */
    public function convertFortAmount($amount, $baseCurrencyCode, $currentCurrencyCode)
    {

        $new_amount     = 0;
        $decimal_points = $this->getCurrencyDecimalPoint($currentCurrencyCode);
        $new_amount     = round($amount, $decimal_points);
        $new_amount     = round(Mage::helper('directory')->currencyConvert($new_amount, $baseCurrencyCode, $currentCurrencyCode), 2);
        $new_amount     = $new_amount * (pow(10, $decimal_points));
        return $new_amount;
    }
    
    
    /**
     * 
     * @param string $currency
     * @param integer 
     */
    public function getCurrencyDecimalPoint($currency)
    {
        $decimalPoint  = 2;
        $arrCurrencies = array(
            'JOD' => 3,
            'KWD' => 3,
            'OMR' => 3,
            'TND' => 3,
            'BHD' => 3,
            'LYD' => 3,
            'IQD' => 3,
        );
        if (isset($arrCurrencies[$currency])) {
            $decimalPoint = $arrCurrencies[$currency];
        }
        return $decimalPoint;
    }
    
    /**
     * calculate fort signature
     * @param array $arr_data
     * @param sting $sign_type request or response
     * @return string fort signature
     */
    public function calculateSignature($arr_data, $sign_type = 'request')
    {
        $sha_in_pass_phrase  = Mage::getStoreConfig('payment/payfort/sha_in_pass_phrase');
        $sha_out_pass_phrase = Mage::getStoreConfig('payment/payfort/sha_out_pass_phrase');
        $sha_type = Mage::getStoreConfig('payment/payfort/sha_type');
        $sha_type = str_replace('-', '', $sha_type);
        
        $shaString = '';

        ksort($arr_data);
        foreach ($arr_data as $k => $v) {
            $shaString .= "$k=$v";
        }

        if ($sign_type == 'request') {
            $shaString = $sha_in_pass_phrase . $shaString . $sha_in_pass_phrase;
        }
        else {
            $shaString = $sha_out_pass_phrase . $shaString . $sha_out_pass_phrase;
        }
        $signature = hash($sha_type, $shaString);

        return $signature;
    }
    
    public function getGatewayUrl($type='redirection') {
        $testMode = Mage::getStoreConfig('payment/payfort/sandbox_mode');
        if($type == 'notificationApi') {
            $gatewayUrl = $testMode ? $this->_gatewaySandboxHost.'FortAPI/paymentApi' : $this->_gatewayHost.'FortAPI/paymentApi';
        }
        else{
            $gatewayUrl = $testMode ? $this->_gatewaySandboxHost.'FortAPI/paymentPage' : $this->_gatewayHost.'FortAPI/paymentPage';
        }
        
        return $gatewayUrl;
    }
    
    public function getMerchantPageData() {
            $language = $this->getLanguage();
            $_order              = Mage::getModel('sales/order');
            $orderId             = Mage::getSingleton('checkout/session')->getLastRealOrderId();
            $_order->loadByIncrementId($orderId);
            $gatewayParams = array(
                'merchant_identifier' => Mage::getStoreConfig('payment/payfort/merchant_identifier'),
                'access_code'         => Mage::getStoreConfig('payment/payfort/access_code'),
                'merchant_reference'  => $orderId,
                'service_command'     => 'TOKENIZATION',
                'language'            => $language,
                'return_url'          => $this->getReturnUrl('payfort/payment/merchantPageResponse'),
            );
            //calculate request signature
            $signature = $this->calculateSignature($gatewayParams, 'request');
            $gatewayParams['signature'] = $signature;
            
            $gatewayUrl = $this->getGatewayUrl('merchantPage');
            
            return array('url' => $gatewayUrl, 'params' => $gatewayParams);
    }
    
    public function isMerchantPageMethod($order = '') {
        $useMerchantPage = Mage::getStoreConfig('payment/payfortcc/integration_type') == 'merchantPage' ? true : false;
        if(!empty($order)) {
            $paymentCode = $order->getPayment()->getMethodInstance()->getCode();
        }
        else{
            $paymentCode = Mage::getSingleton('checkout/session')->getQuote()->getPayment()->getMethodInstance()->getCode();
        }
        if($useMerchantPage && $paymentCode == Mage::getModel('payfort/payment_cc')->getCode()) {
            return true;
        }
        return false;
    }
    
    /**
     * @param $name
     * @param $block
     * @return string
     */
    public function getReviewButtonTemplate($name, $block)
    {
        //$quote = Mage::getSingleton('checkout/session')->getQuote();
        if($this->isMerchantPageMethod()) {
            return $name;
        }

        if ($blockObject = Mage::getSingleton('core/layout')->getBlock($block)) {
            return $blockObject->getTemplate();
        }

        return '';
    }
    
    /**
     * Log the error on the disk
     */
    public function log($messages, $forceDebug = false) {
        $debugMode = Mage::getStoreConfig('payment/payfort/debug_mode');
        if(!$debugMode && !$forceDebug) {
            return;
        }
        Mage::log($messages, null, self::PAYFORT_FORT_LOG_FILE, true);
    }
    
    public function getReturnUrl($path) {
        if (Mage::app()->getStore()->isFrontUrlSecure() 
            && Mage::app()->getRequest()->isSecure()
        ) {
            // current page is https
            return Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK, true) . $path;
        }
        else {
            // current page is http
            return Mage::getBaseUrl() . $path;
        }
    }
    
    public function getLanguage() {
        $language = Mage::getStoreConfig('payment/payfort/language');
        if ($language == 'no_language') {
            $language = Mage::app()->getLocale()->getLocaleCode();
        }
        if(substr($language, 0, 2) == 'ar') {
            $language = 'ar';
        }
        else{
            $language = 'en';
        }
        return $language;
    }
}
