<?php

class Payfort_Pay_PaymentController extends Mage_Core_Controller_Front_Action {

    public function indexAction() {
		return;
    }
    public function setOptionAction() {
        if(isset($_GET['payfort_option'])){
            $_SESSION['payfort_option'] = $_GET['payfort_option'];
            if ($_SESSION['payfort_option'] == ''){
                unset($_SESSION['payfort_option']);
            }
        }
    }
    
	// The redirect action is triggered when someone places an order
    public function redirectAction() {

		$is_active = Mage::getStoreConfig('payment/payfort/active');
        $test_mode = Mage::getStoreConfig('payment/payfort/sandbox_mode');

        $sha_in_pass_phrase = Mage::getStoreConfig('payment/payfort/sha_in_pass_phrase');
        $sha_out_pass_phrase = Mage::getStoreConfig('payment/payfort/sha_out_pass_phrase');
        $action_gateway = '';

        if (!$test_mode) {
			$action_gateway = 'https://checkout.payfort.com/FortAPI/paymentPage';
        } else {
			$action_gateway =  'https://sbcheckout.payfort.com/FortAPI/paymentPage';
        }

        //Loading current layout
        $this->loadLayout();
        
        
        //Creating a new block
        $block = $this->getLayout()->createBlock(
			'Mage_Core_Block_Template', 'payfort_block_redirect', array('template' => 'payfort/pay/redirect.phtml')
        )

        ->setData('sha_in_pass_phrase', $sha_in_pass_phrase)
        ->setData('sha_out_pass_phrase', $sha_out_pass_phrase)
        ->setData('action_gateway', $action_gateway);

        $this->getLayout()->getBlock('content')->append($block);

        //Now showing it with rendering of layout
        $this->renderLayout();
    }

    public function responseAction() {
        
        $response_params = $this->getRequest()->getParams();
		$orderId = $response_params['merchant_reference'];
        $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);

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

		$sha_in_pass_phrase = Mage::getStoreConfig('payment/payfort/sha_in_pass_phrase');
		$sha_out_pass_phrase = Mage::getStoreConfig('payment/payfort/sha_out_pass_phrase');
		$params_not_included = array('signature');
		$response_type = $this->getRequest()->getParam('response_message');
		$signature = $this->getRequest()->getParam('signature');
		$response_order_id = $this->getRequest()->getParam('merchant_reference');
		$response_status = $this->getRequest()->getParam('status');
		$response_code = $this->getRequest()->getParam('response_code');
        $response_status_message = $response_type;
        
		uksort($response_params, 'strnatcasecmp');
		$sha_string = '';

		$error = false;
        $status = "";

		foreach($response_params as $key => $param) {
			// ignore not included params
			if(in_array($key, $params_not_included))
			continue;
			// ignore empty params
			if($param == '')
			continue;
			$sha_string .= strtolower($key) . '=' . $param;
		}

		$sha_type = Mage::getStoreConfig('payment/payfort/sha_type');

		//$sha_string_encrypted = sha1($sha_string);
        //die($sha_out_pass_phrase.$sha_string.$sha_out_pass_phrase);
		$sha_string_encrypted = hash(str_replace('-', '', $sha_type), $sha_out_pass_phrase.$sha_string.$sha_out_pass_phrase);

		// check the signature
		if(strtolower($sha_string_encrypted) !== strtolower($signature)) {

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
        

		if(($response_status != 12 && $response_status != 02) || substr($response_code,2) != '000') {
			// $response_message = $this->__($response_status_message);
			// $this->renderResponse($response_message);
			// return false;
			if($response_type != 'decline')
			$response_type = 'decline';			
		}
        
        if (substr($response_code,2) == '000'){
            $response_type = 'Success';
            $response_message = 'Redirecting, please wait...';
        }
        
        switch($response_type):
			case 'Success':
			/** trying to create invoice * */
			try {
				if (!$order->canInvoice()):
					//Mage::throwException(Mage::helper('core')->__('cannot create an invoice !'));
					//$response_message = $this->__('Error: cannot create an invoice !'); //already created invoice by host to host
					$this->renderResponse($response_message);
					return false;
				else:
					/** create invoice  **/
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
				if($response_status == 14) {
					$response_message = $this->__('Your payment is accepted.');
				} elseif($response_status == 02) {
					$response_message = $this->__('Your payment is authorized.');
				} else {
					$response_message = $this->__('Unknown response status.');
				}
				// $this->renderResponse($response_message);
				// Mage::getSingleton('checkout/session')->setSuccessMessage($response_status_message);
				Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/success', array('_secure' => true));
				return;
			break;
			case 'decline':
				// There is a problem in the response we got
				$this->cancelAction($order);
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
    public function cancelAction($order) {
        if ($order->getId()) {
            // Flag the order as 'cancelled' and save it
            $order->cancel()->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Payfort has declined the payment.')->save();
        }
    }

    public function successAction() {
        /**/
    }

    public function renderResponse($response_message) {
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

    public function testAction() {

    }

}
