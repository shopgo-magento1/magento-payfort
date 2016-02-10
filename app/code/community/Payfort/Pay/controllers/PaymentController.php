<?php

class Payfort_Pay_PaymentController extends Mage_Core_Controller_Front_Action
{

    private $_gatewayHost        = 'https://checkout.payfort.com/';
    private $_gatewaySandboxHost = 'https://sbcheckout.payfort.com/';
    
    public function indexAction()
    {
        return;
    }

    public function setOptionAction()
    {
        if (isset($_GET['payfort_option'])) {
            $_SESSION['payfort_option'] = $_GET['payfort_option'];
            if ($_SESSION['payfort_option'] == '') {
                unset($_SESSION['payfort_option']);
            }
        }
    }

    // The redirect action is triggered when someone places an order
    public function redirectAction()
    {

        $is_active = Mage::getStoreConfig('payment/payfort/active');
        $test_mode = Mage::getStoreConfig('payment/payfort/sandbox_mode');

        $gatewayUrl = $test_mode ? $this->_gatewaySandboxHost.'FortAPI/paymentPage' : $this->_gatewayHost.'FortAPI/paymentPage';

        //Loading current layout
        $this->loadLayout();

        $_order              = Mage::getModel('sales/order');
        $orderId             = Mage::getSingleton('checkout/session')->getLastRealOrderId();
        $_order->loadByIncrementId($orderId);
        $baseCurrencyCode    = Mage::app()->getStore()->getBaseCurrencyCode();
        $currentCurrencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();
        $orderAmount         = $this->_convertFortAmount($_order->getBaseGrandTotal(), $baseCurrencyCode, $currentCurrencyCode);
        $currency            = $currentCurrencyCode;
                
        $language = Mage::getStoreConfig('payment/payfort/language');
        if ($language == 'no_language') {
            $language = Mage::app()->getLocale()->getLocaleCode();
        }

        $gatewayParams = array(
            'amount'              => $orderAmount,
            'currency'            => $currency,
            'merchant_identifier' => Mage::getStoreConfig('payment/payfort/merchant_identifier'),
            'access_code'         => Mage::getStoreConfig('payment/payfort/access_code'),
            'merchant_reference'  => $orderId,
            'customer_email'      => $_order->getCustomerEmail(),
            'command'             => Mage::getStoreConfig('payment/payfort/command'),
            'language'            => $language,
            'return_url'          => Mage::getBaseUrl() . 'payfort/payment/response/',
        );

        $payfort_option   = isset($_SESSION['payfort_option']) ? $_SESSION['payfort_option'] : '';
        $isNaps  = $payfort_option == 'NAPS' ? true : false;
        $isSADAD = $payfort_option == 'SADAD' ? true : false;

        if ($isSADAD == "true") {
            $gatewayParams['payment_option'] = 'SADAD';
        }
        else if ($isNaps == "true") {
            $gatewayParams['payment_option']    = 'NAPS';
            $gatewayParams['order_description'] = $orderId;
        }

        $signature                  = $this->_calculateSignature($gatewayParams, 'request');
        $gatewayParams['signature'] = $signature;

        //Creating a new block
        $block = $this->getLayout()->createBlock(
                        'Mage_Core_Block_Template', 'payfort_block_redirect', array('template' => 'payfort/pay/redirect.phtml')
                )
                ->setData('gatewayParams', $gatewayParams)
                ->setData('gatewayUrl', $gatewayUrl);

        $this->getLayout()->getBlock('content')->append($block);

        //Now showing it with rendering of layout
        $this->renderLayout();
    }

    public function responseAction()
    {

        $response_params = $this->getRequest()->getParams();
        $orderId         = $response_params['merchant_reference'];
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
        $params_not_included     = array('signature');
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
        $responseSignature    = $this->_calculateSignature($responseGatewayParams, 'response');
        
        $error  = false;
        $status = "";

        // check the signature
        if (strtolower($responseSignature) !== strtolower($signature)) {

            $response_message = $this->__('Invalid response signature.');

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
            Mage::getSingleton('checkout/session')->setErrorMessage($response_status_message);
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
            $response_message = 'Redirecting, please wait...';
        }

        switch ($response_type):
            case 'Success':
                /** trying to create invoice * */
                try {
                    if (!$order->canInvoice()):
                        //Mage::throwException(Mage::helper('core')->__('cannot create an invoice !'));
                        //$response_message = $this->__('Error: cannot create an invoice !'); //already created invoice by host to host
                        $this->renderResponse($response_message);
                        return false;
                    else:
                        /** create invoice  * */
                        //$invoiceId = Mage::getModel('sales/order_invoice_api')->create($order->getIncremenetId(), array());
                        $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
                        if (!$invoice->getTotalQty()):
                            //Mage::throwException(Mage::helper('core')->__('cannot create an invoice without products !'));
                            //$response_message = $this->__('Error: cannot create an invoice without products !'); //already created invoice by host to host
                            $this->renderResponse($response_message);
                            return false;
                        endif;
                        $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                        $invoice->register();
                        $transactionSave = Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($invoice->getOrder());
                        $transactionSave->save();
                        $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, 'Payfort has accepted the payment.');
                    /** load invoice * */
                    //$invoice = Mage::getModel('sales/order_invoice')->loadByIncrementId($invoiceId);
                    /** pay invoice * */
                    //$invoice->capture()->save();
                    endif;
                } catch (Mage_Core_Exception $e) {
                    //Mage::throwException(Mage::helper('core')->__('cannot create an invoice !'));
                }
                $order->sendNewOrderEmail();
                $order->setEmailSent(true);
                $order->save();
                if ($response_status == 14) {
                    $response_message = $this->__('Your payment is accepted.');
                }
                elseif ($response_status == 02) {
                    $response_message = $this->__('Your payment is authorized.');
                }
                else {
                    $response_message = $this->__('Unknown response status.');
                }
                // $this->renderResponse($response_message);
                // Mage::getSingleton('checkout/session')->setSuccessMessage($response_status_message);
                Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/success', array('_secure' => true));
                return;
                break;
            case 'decline':
                // There is a problem in the response we got
                $this->declineAction($order);
                // $response_status_message = Mage::helper('payfort/data')->getResponseCodeDescription($response_status);
                Mage::getSingleton('checkout/session')->setErrorMessage($response_status_message);
                Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure' => true));
                // $this->renderResponse($response_message);
                return;
                break;
            case 'exception':
                // There is a problem in the response we got
                $this->cancelAction($order);
                // $response_status_message = Mage::helper('payfort/data')->getResponseCodeDescription($response_status);
                Mage::getSingleton('checkout/session')->setErrorMessage($response_status_message);
                Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure' => true));
                // $this->renderResponse($response_message);
                return;
                break;
            case 'cancel':
                // There is a problem in the response we got
                $this->cancelAction($order);
                // $response_status_message = Mage::helper('payfort/data')->getResponseCodeDescription($response_status);
                Mage::getSingleton('checkout/session')->setErrorMessage($response_status_message);
                Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure' => true));
                // $this->renderResponse($response_message);
                return;
                break;
            default:
                $response_message = $this->__('Response Unknown');
                $this->renderResponse($response_message);
                return;
                break;
        endswitch;
    }

    // The cancel action is triggered when an order is to be cancelled
    public function cancelAction($order)
    {
        if ($order->getId()) {
            // Flag the order as 'cancelled' and save it
            $order->cancel()->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Payfort has declined the payment.')->save();
        }
    }

    public function declineAction($order) {
        $session = Mage::getSingleton('checkout/session');
        $cart = Mage::getSingleton('checkout/cart');
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

    public function testAction()
    {
        
    }

    /**
     * Convert Amount with dicemal points
     * @param decimal $amount
     * @param string $baseCurrencyCode
     * @param string  $currentCurrencyCode
     * @return decimal
     */
    private function _convertFortAmount($amount, $baseCurrencyCode, $currentCurrencyCode)
    {

        $new_amount     = 0;
        $decimal_points = $this->_getCurrencyDecimalPoint($currentCurrencyCode);
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
    private function _getCurrencyDecimalPoint($currency)
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
    private function _calculateSignature($arr_data, $sign_type = 'request')
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

}
