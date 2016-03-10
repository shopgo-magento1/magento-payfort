<?php
class Payfort_Pay_Model_Observer extends Mage_CatalogInventory_Model_Observer
{
    function __construct()
    {
        
    }
    
    /**
     * Save order into registry to use it in the overloaded controller.
     *
     * @param Varien_Event_Observer $observer
     * @return Mage_Paypal_Model_Observer
     */
    public function saveOrderAfterSubmit(Varien_Event_Observer $observer)
    {
        /* @var $order Mage_Sales_Model_Order */
        $order = $observer->getEvent()->getData('order');
        Mage::register('payfort_fort_order', $order, true);
        
        return $this;
    }
    
    /**
     * Set data for response of frontend saveOrder action
     *
     * @param Varien_Event_Observer $observer
     * @return Mage_Paypal_Model_Observer
     */
//    public function setResponseAfterSaveOrder(Varien_Event_Observer $observer)
//    {
//        /* @var $order Mage_Sales_Model_Order */
//        //$order = Mage::registry('pf_order');
//        if(Mage::helper('payfort/data')->isMerchantPageMethod()){
//            $controller = $observer->getEvent()->getData('controller_action');
//            $result = Mage::helper('core')->jsonDecode(
//                $controller->getResponse()->getBody('default'),
//                Zend_Json::TYPE_ARRAY
//            );
//            $result['redirect'] = false;
//            $result['success'] = false;
//            $result['results']['save_order']['success'] = false;
//            $result['results']['save_order']['redirect'] = false;
//            $controller->getResponse()->clearHeader('Location');
//            $controller->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
//        }
//        return $this;
//    }
}

?>