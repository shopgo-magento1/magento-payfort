<?php

class Payfort_Pay_PaymentController extends Mage_Core_Controller_Front_Action
{
    public function indexAction()
    {
        return;
    }

    
    public function setOptionAction()
    {
        
        $payentMethod = Mage::getSingleton('checkout/session')->getData('payfort_option');
        if (isset($_GET['payfort_option'])) {
            Mage::getSingleton('checkout/session')->setData('payfort_option', $_GET['payfort_option']);
        }
    }

    // The redirect action is triggered when someone places an order
    public function redirectAction()
    {

        $is_active = Mage::getStoreConfig('payment/payfort/active');
        $test_mode = Mage::getStoreConfig('payment/payfort/sandbox_mode');

        $gatewayUrl = Mage::helper('payfort/data')->getGatewayUrl('redirection');

        //Loading current layout
        $this->loadLayout();

        $_order              = Mage::getModel('sales/order');
        $orderId             = Mage::getSingleton('checkout/session')->getLastRealOrderId();
        $_order->loadByIncrementId($orderId);
        $baseCurrencyCode    = Mage::app()->getStore()->getBaseCurrencyCode();
        $currentCurrencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();
        $orderAmount         = Mage::helper('payfort/data')->convertFortAmount($_order->getBaseGrandTotal(), $baseCurrencyCode, $currentCurrencyCode);
        $currency            = $currentCurrencyCode;
                
        $language = Mage::helper('payfort/data')->getLanguage();
        $gatewayParams = array(
            'amount'              => $orderAmount,
            'currency'            => $currency,
            'merchant_identifier' => Mage::getStoreConfig('payment/payfort/merchant_identifier'),
            'access_code'         => Mage::getStoreConfig('payment/payfort/access_code'),
            'merchant_reference'  => $orderId,
            'customer_email'      => $_order->getCustomerEmail(),
            'command'             => Mage::getStoreConfig('payment/payfort/command'),
            'language'            => $language,
            'return_url'          => Mage::helper('payfort/data')->getReturnUrl('payfort/payment/response')
        );

        $payment_method = $_order->getPayment()->getMethodInstance()->getCode();
        
        $isNaps  = $payment_method == Mage::getModel('payfort/payment_naps')->getCode() ? true : false;
        $isSADAD = $payment_method == Mage::getModel('payfort/payment_sadad')->getCode() ? true : false;

        if ($isSADAD) {
            $gatewayParams['payment_option'] = 'SADAD';
        }
        else if ($isNaps) {
            $gatewayParams['payment_option']    = 'NAPS';
            $gatewayParams['order_description'] = $orderId;
        }

        $signature                  = Mage::helper('payfort/data')->calculateSignature($gatewayParams, 'request');
        $gatewayParams['signature'] = $signature;
        
        //Creating a new block
        if(!$isNaps && !$isSADAD && Mage::getStoreConfig('payment/payfortcc/integration_type') == 'merchantPage'){
            $this->getLayout()->getBlock('head')->addLinkRel('stylesheet', 'https://maxcdn.bootstrapcdn.com/font-awesome/4.5.0/css/font-awesome.min.css');
            $this->getLayout()->getBlock('head')->addCss('css/payfort/merchant-page.css');
            $this->getLayout()->getBlock('head')->addJs('payfort/checkout-submit.js');
            $merchantPageParams = Mage::helper('payfort/data')->getMerchantPageData();
            
            $block = $this->getLayout()->createBlock(
                        'Mage_Core_Block_Template', 'payfort_block_redirect', array('template' => 'payfort/pay/merchant-page.phtml')
                )
                ->setData('gatewayParams', $merchantPageParams['params'])
                ->setData('gatewayUrl', $merchantPageParams['url']);
        }
        else{
            $block = $this->getLayout()->createBlock(
                        'Mage_Core_Block_Template', 'payfort_block_redirect', array('template' => 'payfort/pay/redirect.phtml')
                )
                ->setData('gatewayParams', $gatewayParams)
                ->setData('gatewayUrl', $gatewayUrl);
        }
        
        $this->getLayout()->getBlock('content')->append($block);

        //Now showing it with rendering of layout
        $this->renderLayout();
    }

    public function responseAction()
    {

        $response_params = $this->getRequest()->getParams();
        $payfortHelper = Mage::helper('payfort/data');
        Mage::helper('payfort/data')->log(print_r($response_params, 1), null, $payfortHelper::PAYFORT_FORT_LOG_FILE, true);
        $orderId         = $response_params['merchant_reference'];
        //$orderId         = Mage::getSingleton('checkout/session')->getLastRealOrderId();
        $order           = Mage::getModel('sales/order')->loadByIncrementId($orderId);

        /*
         * $order = Mage::getModel('sales/order')->loadByIncrementId(Mage::getSingleton('checkout/session')->getLastRealOrderId());
         * $order->getGrandTotal();
         *
         * */
        /*
         * * Most frequent transaction statuses:
         * *
          14 purchase
          02 authorize
         */

        $sha_in_pass_phrase      = Mage::getStoreConfig('payment/payfort/sha_in_pass_phrase');
        $sha_out_pass_phrase     = Mage::getStoreConfig('payment/payfort/sha_out_pass_phrase');
        $params_not_included     = array('signature', 'route', '___store');
        $response_type           = $this->getRequest()->getParam('response_message');
        $signature               = $this->getRequest()->getParam('signature');
        $response_order_id       = $this->getRequest()->getParam('merchant_reference');
        $response_status         = $this->getRequest()->getParam('status');
        $response_code           = $this->getRequest()->getParam('response_code');
        $response_status_message = $response_type;

        $responseGatewayParams = $response_params;
        foreach($responseGatewayParams as $k => $v) {
            if (in_array($k, $params_not_included)) {
                unset($responseGatewayParams[$k]);
            }
        }
        $responseSignature    = Mage::helper('payfort/data')->calculateSignature($responseGatewayParams, 'response');
        
        $error  = false;
        $status = "";

        // check the signature
        if (strtolower($responseSignature) !== strtolower($signature)) {

            $response_message = $this->__('Invalid response signature.');
            Mage::helper('payfort/data')->log(sprintf('Invalid Signature. Calculated Signature: %1s, Response Signature: %2s', $signature, $responseSignature), null, $payfortHelper::PAYFORT_FORT_LOG_FILE, true);
            $this->loadLayout();
            //Creating a new block
            $block = $this->getLayout()->createBlock(
                            'Mage_Core_Block_Template', 'payfort_block_response', array('template' => 'payfort/pay/response.phtml')
                    )
                    ->setData('response_message', $response_message);

            $this->getLayout()->getBlock('content')->append($block);

            //Now showing it with rendering of layout
            $this->renderLayout();
            // There is a problem in the response we got
            $this->cancelAction($order);
            // $response_status_message = Mage::helper('payfort/data')->getResponseCodeDescription($response_status);
            Mage::getSingleton('checkout/session')->setErrorMessage($response_message);
            Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure' => true));
            // $this->renderResponse($response_message);
            return false;
        }

        //$response_status_message = Mage::helper('payfort/data')->getResponseCodeDescription($response_status);

        
        if (($response_status != 12 && $response_status != 02) || substr($response_code, 2) != '000') {
            // $response_message = $this->__($response_status_message);
            // $this->renderResponse($response_message);
            // return false;
            if ($response_type != 'decline')
                $response_type = 'decline';
        }

        if (substr($response_code, 2) == '000') {
            $response_type    = 'Success';
            $response_message = $this->__('Redirecting, please wait...');
        }      
        switch ($response_type):
            case 'Success':
                list($success, $response_message) = $this->_successOrder($response_params, $order, $response_message);
                // $this->renderResponse($response_message);
                // Mage::getSingleton('checkout/session')->setSuccessMessage($response_status_message);
                if(Mage::helper('payfort/data')->isMerchantPageMethod($order)){
                    echo '<script>window.top.location.href = "'.Mage::getUrl('checkout/onepage/success').'"</script>';
                    exit;
                }
                else{
                    Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/success', array('_secure' => true));
                }
                return;
                break;
            case 'decline':
                // There is a problem in the response we got
                $this->declineAction($order);
                // $response_status_message = Mage::helper('payfort/data')->getResponseCodeDescription($response_status);
                Mage::getSingleton('checkout/session')->setErrorMessage($response_status_message);
                if(Mage::helper('payfort/data')->isMerchantPageMethod($order)){
                    echo '<script>window.top.location.href = "'.Mage::getUrl('checkout/onepage/failure').'"</script>';
                    exit;
                }
                else{
                    Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure' => true));
                }
                // $this->renderResponse($response_message);
                return;
                break;
            case 'exception':
                // There is a problem in the response we got
                $this->cancelAction($order);
                // $response_status_message = Mage::helper('payfort/data')->getResponseCodeDescription($response_status);
                Mage::getSingleton('checkout/session')->setErrorMessage($response_status_message);
                if(Mage::helper('payfort/data')->isMerchantPageMethod($order)){
                    echo '<script>window.top.location.href = "'.Mage::getUrl('checkout/onepage/failure').'"</script>';
                    exit;
                }
                else{
                    Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure' => true));
                }
                // $this->renderResponse($response_message);
                return;
                break;
            case 'cancel':
                // There is a problem in the response we got
                $this->cancelAction($order);
                // $response_status_message = Mage::helper('payfort/data')->getResponseCodeDescription($response_status);
                Mage::getSingleton('checkout/session')->setErrorMessage($response_status_message);
                if(Mage::helper('payfort/data')->isMerchantPageMethod($order)){
                    echo '<script>window.top.location.href = "'.Mage::getUrl('checkout/onepage/failure').'"</script>';
                    exit;
                }
                else{
                    Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure' => true));
                }
                // $this->renderResponse($response_message);
                return;
                break;
            default:
                $response_message = $this->__('Response Unknown');
                Mage::getSingleton('checkout/session')->setErrorMessage($response_message);
                if(Mage::helper('payfort/data')->isMerchantPageMethod($order)){
                    echo '<script>window.top.location.href = "'.Mage::getUrl('checkout/onepage/success').'"</script>';
                    exit;
                }
                else{
                    $this->renderResponse($response_message);
                }
                return;
                break;
        endswitch;
    }

    public function merchantPageResponseAction() 
    {
        $response_params = $this->getRequest()->getParams();
        $payfortHelper = Mage::helper('payfort/data');
        Mage::helper('payfort/data')->log(print_r($response_params, 1), null, $payfortHelper::PAYFORT_FORT_LOG_FILE, true);
        $orderId         = $response_params['merchant_reference'];
        $order           = Mage::getModel('sales/order')->loadByIncrementId($orderId);
        
        
        $params_not_included     = array('signature', 'route', '___store');
        $signature               = $this->getRequest()->getParam('signature');
        $response_code           = $this->getRequest()->getParam('response_code');
        $response_type           = $this->getRequest()->getParam('response_message');
        $response_status_message = $response_type;
        $response_message        = '';
        
        $responseGatewayParams = $response_params;
        foreach($responseGatewayParams as $k => $v) {
            if (in_array($k, $params_not_included)) {
                unset($responseGatewayParams[$k]);
            }
        }
        $responseSignature    = Mage::helper('payfort/data')->calculateSignature($responseGatewayParams, 'response');
        $success = true;
        
        if (strtolower($responseSignature) !== strtolower($signature)) {
            $success = false;
            $response_message = $this->__('Invalid response signature.');
            Mage::helper('payfort/data')->log(sprintf('Invalid Signature. Calculated Signature: %1s, Response Signature: %2s', $responseSignature, $signature), null, $payfortHelper::PAYFORT_FORT_LOG_FILE, true);
            
        }
        elseif (substr($response_code, 2) != '000') {
            $success = false;
            $response_message = $response_type;
        }
        else{
            //get payfort notification
            $success = true;
            $host2HostParams = $this->merchantPageNotifyFort($response_params, $order);
            if(!$host2HostParams) {
                $success = false;
                $response_message = $this->__('Invalid response parameters.');
                Mage::helper('payfort/data')->log('Invalid response parameters.', null, $payfortHelper::PAYFORT_FORT_LOG_FILE, true);
            }
            else {
                Mage::helper('payfort/data')->log(print_r($host2HostParams, 1), null, $payfortHelper::PAYFORT_FORT_LOG_FILE, true);
                $responseGatewayParams = $host2HostParams;
                $signature             = $host2HostParams['signature'];
                foreach($responseGatewayParams as $k => $v) {
                    if (in_array($k, $params_not_included)) {
                        unset($responseGatewayParams[$k]);
                    }
                }
                $responseSignature    = Mage::helper('payfort/data')->calculateSignature($responseGatewayParams, 'response');
                if (strtolower($responseSignature) !== strtolower($signature)) {
                    $success = false;
                    $response_message = $this->__('Invalid response signature.');
                    Mage::helper('payfort/data')->log(sprintf('Invalid Signature. Calculated Signature: %1s, Response Signature: %2s', $responseSignature, $signature), null, $payfortHelper::PAYFORT_FORT_LOG_FILE, true);
                }
                else{
                    $response_code    = $host2HostParams['response_code'];
                    if($response_code == '20064' && isset($host2HostParams['3ds_url'])) {
                        $success = true;
                        header('location:'.$host2HostParams['3ds_url']);
                        exit;
                    }
                    else{
                        if (substr($response_code, 2) != '000'){
                            $success = false;
                            $response_message = $host2HostParams['response_message'];
                        }
                        else {
                            list($success, $response_message) = $this->_successOrder($host2HostParams, $order, $response_message);
                            // $this->renderResponse($response_message);
                            // Mage::getSingleton('checkout/session')->setSuccessMessage($response_status_message);
                            if($success) {
                                echo '<script>window.top.location.href = "'.Mage::getUrl('checkout/onepage/success').'"</script>';
                            }
                            else{
                                Mage::getSingleton('checkout/session')->setErrorMessage($response_message);
                                echo '<script>window.top.location.href = "'.Mage::getUrl('checkout/onepage/success').'"</script>';
                            }
                            exit;
                        }
                    }
                }
            }
        }
        if(!$success) {
            $this->declineAction($order);
            // $response_status_message = Mage::helper('payfort/data')->getResponseCodeDescription($response_status);
            Mage::getSingleton('checkout/session')->setErrorMessage($response_message);
            //Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure' => true));
            echo '<script>window.top.location.href = "'.Mage::getUrl('checkout/onepage/failure').'"</script>';
            exit;
        }
    }
    
    // The cancel action is triggered when an order is to be cancelled
    public function cancelAction($order)
    {
        if ($order->getId()) {
            // Flag the order as 'cancelled' and save it
            $order->cancel()->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Payfort has declined the payment.')->save();
        }
    }

    public function declineAction($order) 
    {
        $session = Mage::getSingleton('checkout/session');
        //$session->setQuoteId($session->getPaypalStandardQuoteId(true));
        //if ($session->getLastRealOrderId()) {
        try {
            if($order->getId()){
                //$incrementId = $session->getLastRealOrderId();
                $session->getQuote()->setIsActive(false)->save();
                $session->clear();
                try {
                    //$order->setActionFlag(Mage_Sales_Model_Order::ACTION_FLAG_CANCEL, true);
                    //$order->cancel()->save();
                    $order->cancel()->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Payfort has declined the payment.')->save();
                } catch (Mage_Core_Exception $e) {
                    Mage::logException($e);
                }
                $this->refillCart($order);
                //$session->addError($this->__('Payfort has declined the payment.'));
            }
        } catch (Mage_Core_Exception $e) {
            $session->addError($e->getMessage());
        } catch (Exception $e) {
            //$session->addError($this->__('Payfort has declined the payment.'));
            Mage::logException($e);
        }
        //$this->_redirect('checkout/cart');
    }
    
    public function refillCart($order) 
    {
        $session = Mage::getSingleton('checkout/session');
        $cart = Mage::getSingleton('checkout/cart');
        if($order->getId()){
            $items = $order->getItemsCollection();
            foreach ($items as $item) {
                try {
                    $cart->addOrderItem($item);
                } catch (Mage_Core_Exception $e) {
                    $session->addError($this->__($e->getMessage()));
                    Mage::logException($e);
                    continue;
                }
            }
            $cart->save();
        }
    }
    
    private function _successOrder($fortParams ,$order, $response_message) {
        $success = true;
        try {
            /** trying to create invoice * */
            if (!$order->canInvoice()):
                $response_message = Mage::helper('core')->__('cannot create an invoice !'); //already created invoice by host to host
                $success = false;
                Mage::throwException(Mage::helper('core')->__('cannot create an invoice !'));
            else:
                /** create invoice  * */
                //$invoiceId = Mage::getModel('sales/order_invoice_api')->create($order->getIncremenetId(), array());
                $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
                if (!$invoice->getTotalQty()):
                    $response_message = Mage::helper('core')->__('cannot create an invoice without products !'); //already created invoice by host to host
                    $success = false;
                    Mage::throwException(Mage::helper('core')->__('cannot create an invoice without products !'));
                endif;
                if($success){
                    $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                    $invoice->register();
                    $transactionSave = Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($invoice->getOrder());
                    $transactionSave->save();
                    $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, 'Payfort has accepted the payment.');
                }
            /** load invoice * */
            //$invoice = Mage::getModel('sales/order_invoice')->loadByIncrementId($invoiceId);
            /** pay invoice * */
            //$invoice->capture()->save();
            endif;
        } catch (Mage_Core_Exception $e) {
            //Mage::throwException(Mage::helper('core')->__('cannot create an invoice !'));
        }
        if($success) {
            $order->sendNewOrderEmail();
            $order->setEmailSent(true);
            $order->save();
            $response_status = $fortParams['status'];
            if ($response_status == 14) {
                $response_message = $this->__('Your payment is accepted.');
            }
            elseif ($response_status == 02) {
                $response_message = $this->__('Your payment is authorized.');
            }
            else {
                $response_message = $this->__('Unknown response status.');
            }
        }
        
        return array($success, $response_message);
    }
    public function successAction()
    {
        /**/
    }

    public function renderResponse($response_message)
    {
        $this->loadLayout();
        //Creating a new block
        $block = $this->getLayout()->createBlock(
                        'Mage_Core_Block_Template', 'payfort_block_response', array('template' => 'payfort/pay/response.phtml')
                )
                ->setData('response_message', $response_message);

        $this->getLayout()->getBlock('content')->append($block);

        //Now showing it with rendering of layout
        $this->renderLayout();
    }
    
    public function getMerchantPageDataAction() 
    {
        $merchantPageData = Mage::helper('payfort/data')->getMerchantPageData();
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($merchantPageData));
    }
    
    public function merchantPageCancelAction() 
    {
        $checkoutSession = Mage::getSingleton('checkout/session');
        $cart    = Mage::getSingleton('checkout/cart');
        $orderId = $checkoutSession->getLastRealOrderId();
        
        if(!empty($orderId)) {
            $order   = Mage::getModel('sales/order')->loadByIncrementId($orderId);
            $this->cancelAction($order);
            $this->refillCart($order);
        }
        
        $checkoutSession->addError($this->__('You have canceled the payment, please try again.'));
        //$checkoutSession->setErrorMessage('You have canceled the payment, please try again.');
        Mage::app()->getResponse()->setRedirect(Mage::getModel('core/url')->getUrl('checkout/cart/index'))
        ->sendResponse();
        exit;
        //Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/index', array('_secure' => true));
    }
    
    private function merchantPageNotifyFort($fortParams, $order) 
    {
        //send host to host
        $payfortHelper = Mage::helper('payfort/data');
        $order_id = $order->getId();
        $baseCurrencyCode    = Mage::app()->getStore()->getBaseCurrencyCode();//base_currency_code
        $currentCurrencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();//order_currency_code
        $currency            = $currentCurrencyCode;
        $language = Mage::helper('payfort/data')->getLanguage();
        $postData = array(
            'merchant_reference'    => $fortParams['merchant_reference'],
            'access_code'           => Mage::getStoreConfig('payment/payfort/access_code'),
            'command'               => Mage::getStoreConfig('payment/payfort/command'),
            'merchant_identifier'   => Mage::getStoreConfig('payment/payfort/merchant_identifier'),
            'customer_ip'           => Mage::helper('core/http')->getRemoteAddr(),//$order->getData('remote_ip')
            'amount'                => Mage::helper('payfort/data')->convertFortAmount($order->getGrandTotal(), $baseCurrencyCode, $currentCurrencyCode),
            'currency'              => $currency,
            'customer_email'        => $order->getData('customer_email'),
            //'customer_name'         => trim($order->getData('customer_firstname').' '.$order->getData('customer_lastname')),
            'token_name'            => $fortParams['token_name'],
            'language'              => $language,
            'return_url'            => Mage::helper('payfort/data')->getReturnUrl('payfort/payment/response'),
        );
        $customerFName = $order->getData('customer_firstname');
        if(!empty($customerFName)) {
            $postData['customer_name'] = trim($order->getData('customer_firstname').' '.$order->getData('customer_lastname'));
        }
        //calculate request signature
        $signature    = Mage::helper('payfort/data')->calculateSignature($postData, 'request');
        $postData['signature'] = $signature;
        
        $gatewayUrl = Mage::helper('payfort/data')->getGatewayUrl('notificationApi');
        Mage::helper('payfort/data')->log('Merchant Page Notify Request Params : '.print_r($postData, 1), null, $payfortHelper::PAYFORT_FORT_LOG_FILE, true);
        //open connection
        $ch = curl_init();
        
        //set the url, number of POST vars, POST data
        $useragent = "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:20.0) Gecko/20100101 Firefox/20.0";
        curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json;charset=UTF-8',
                //'Accept: application/json, application/*+json',
                //'Connection:keep-alive'
        ));
        curl_setopt($ch, CURLOPT_URL, $gatewayUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_FAILONERROR, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_ENCODING, "compress, gzip");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // allow redirects		
        //curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // return into a variable
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0); // The number of seconds to wait while trying to connect
        //curl_setopt($ch, CURLOPT_TIMEOUT, Yii::app()->params['apiCallTimeout']); // timeout in seconds
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

        $response = curl_exec($ch);
        
        $response_data = array();

        //parse_str($response, $response_data);
        curl_close($ch);
            
        
        $array_result    = json_decode($response, true);
        
        if(!$response || empty($array_result)) {
            return false;
        }
        return $array_result;
    }
}
