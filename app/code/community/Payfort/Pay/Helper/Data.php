<?php

class Payfort_Pay_Helper_Data extends Mage_Core_Helper_Abstract {

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

}
