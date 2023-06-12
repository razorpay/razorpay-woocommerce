<?php

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

require_once __DIR__ .'/razorpay-route.php';

class RZP_Route_Action
{

    public function __construct()
    {
        $this->Wc_Razorpay_Loader = new WC_Razorpay();
        $this->api = $this->Wc_Razorpay_Loader->getRazorpayApiInstance();

    }

    function directTransfer()
    {

        $trfAccount = sanitize_text_field($_POST['drct_trf_account']);
        $trfAmount = sanitize_text_field($_POST['drct_trf_amount']);
        $pageUrl = admin_url('admin.php?page=razorpayRouteWoocommerce');
        try {
            $transferData = array(

                'account' => $trfAccount,
                'amount' => (int)round($trfAmount * 100),
                'currency' => 'INR'
            );

            $this->api->transfer->create($transferData);
        } catch (Exception $e) {
            $message = $e->getMessage();

            wp_die('<div class="error notice">
                    <p>RAZORPAY ERROR: Transfers create failed with the following message: ' . $message . '</p>
                 </div>');
        }
        wp_redirect($pageUrl);
    }

    function reverseTransfer()
    {

        $transferId = sanitize_text_field($_POST['transfer_id']);
        $reversalAmount = sanitize_text_field($_POST['reversal_amount']);
        $pageUrl = admin_url('admin.php?page=razorpayTransfers&id=' . $transferId);
        try {
            $reversalData = array(
                'amount' => (int)round($reversalAmount * 100),
            );

            $this->api->transfer->fetch($transferId)->reverse($reversalData);
        } catch (Exception $e) {
            $message = $e->getMessage();

            wp_die('<div class="error notice">
                    <p>RAZORPAY ERROR: Reverse Transfer failed with the following message: ' . $message . '</p>
                 </div>');
        }
        wp_redirect($pageUrl);
    }

    function updateTransferSettlement()
    {

        $transferId = sanitize_text_field($_POST['transfer_id']);
        $trfHoldStatus = sanitize_text_field($_POST['on_hold']);
        if ($trfHoldStatus == "on_hold_until") {
            $trfHoldUntil = sanitize_text_field($_POST['hold_until']);
            $unixTime = strtotime($trfHoldUntil);

            $trfHoldStatus = true;
        }

        $pageUrl = admin_url('admin.php?page=razorpayTransfers&id=' . $transferId);
        try {
            $updateData = array(
                'on_hold' => $trfHoldStatus,
                'on_hold_until' => $unixTime,
            );

            $url = "transfers/" . $transferId;
            $this->api->request->request("PATCH", $url, $updateData);

        } catch (Exception $e) {
            $message = $e->getMessage();

            wp_die('<div class="error notice">
                    <p>RAZORPAY ERROR: Change settlement schedule failed with the following message: ' . $message . '</p>
                 </div>');
        }
        wp_redirect($pageUrl);
    }

    function createPaymentTransfer()
    {

        $paymentId = sanitize_text_field($_POST['payment_id']);
        $trfAccount = sanitize_text_field($_POST['pay_trf_account']);
        $trfAmount = sanitize_text_field($_POST['pay_trf_amount']);
        $pageUrl = admin_url('admin.php?page=razorpayPaymentsView&id=' . $paymentId);

        $trfHoldStatus = sanitize_text_field($_POST['on_hold']);
        if ($trfHoldStatus == "on_hold_until") {
            $trfHoldUntil = sanitize_text_field($_POST['hold_until']);
            $unixTime = strtotime($trfHoldUntil);

            $trfHoldStatus = true;
        }
        try {

            $data = array(
                'transfers' => array(
                    array(
                        'account' => $trfAccount,
                        'amount' => (int)round($trfAmount * 100),
                        'currency' => 'INR',
                        'on_hold' => $trfHoldStatus,
                        'on_hold_until' => $unixTime,)
                )
            );

            $this->api->payment->fetch($paymentId)->transfer($data);

        } catch (Exception $e) {
            $message = $e->getMessage();

            wp_die('<div class="error notice">
                    <p>RAZORPAY ERROR: Transfers create failed with the following message: ' . $message . '</p>
                 </div>');
        }
        wp_redirect($pageUrl);
    }

    function getOrderTransferData($orderId){
        $order = wc_get_order($orderId);

        $items = $order->get_items();
        $orderTransferArr = array();

        foreach ( $items as $item ) {
            $productId = $item['product_id'];
            $rzpTransferFrom =   get_post_meta($productId, 'rzp_transfer_from', true);

            if($rzpTransferFrom == 'from_order'){

                $LA_number_arr =   get_post_meta($productId, 'LA_number', true);
                $LA_amount_arr =   get_post_meta($productId, 'LA_transfer_amount', true);
                $LA_trf_status_arr =   get_post_meta($productId, 'LA_transfer_status', true);

                if(isset($LA_number_arr) && is_array($LA_number_arr) && isset($LA_amount_arr) && is_array($LA_amount_arr)) {
                    $LA_transfer_count = count($LA_number_arr);
                    for($i=0;$i<$LA_transfer_count;$i++){
                        if(!empty($LA_number_arr[$i]) && !empty($LA_amount_arr[$i])){

                            $transferArr = array(

                                'account'=> $LA_number_arr[$i],
                                'amount'=> (int) round($LA_amount_arr[$i] * 100),
                                'currency'=> 'INR',
                                'on_hold'=> $LA_trf_status_arr[$i]
                            );

                            array_push($orderTransferArr, $transferArr);
                        }
                    }
                }
            }

        }

        return $orderTransferArr;
    }

    function transferFromPayment($orderId, $razorpayPaymentId){

        $order = wc_get_order($orderId);

        $items = $order->get_items();
        $paymentTransferArr = array();

        foreach ( $items as $item ) {
            $productId = $item['product_id'];
            $rzp_transfer_from =   get_post_meta($productId, 'rzp_transfer_from', true);

            if($rzp_transfer_from == 'from_payment'){

                $LA_number_arr =   get_post_meta($productId, 'LA_number', true);
                $LA_amount_arr =   get_post_meta($productId, 'LA_transfer_amount', true);
                $LA_trf_status_arr =   get_post_meta($productId, 'LA_transfer_status', true);

                if(isset($LA_number_arr) && is_array($LA_number_arr) && isset($LA_amount_arr) && is_array($LA_amount_arr)) {
                    $LA_transfer_count = count($LA_number_arr);
                    for($i=0;$i<$LA_transfer_count;$i++){
                        if(!empty($LA_number_arr[$i]) && !empty($LA_amount_arr[$i])){
                            $transferArr = array(

                                'account'=> $LA_number_arr[$i],
                                'amount'=> (int) round($LA_amount_arr[$i] * 100),
                                'currency'=> 'INR',
                                'on_hold'=> $LA_trf_status_arr[$i]
                            );
                            array_push($paymentTransferArr, $transferArr);
                        }
                    }
                }
            }

        }

        if(isset($paymentTransferArr) && !empty($paymentTransferArr)){

            $data = array(

                'transfers' => $paymentTransferArr
            );

            $url = "payments/".$razorpayPaymentId."/transfers";

            $this->api->request->request("POST", $url, $data);

            $this->addRouteAnalyticsScript();

        }

    }

    function addRouteAnalyticsScript() {

        $mod_version = get_plugin_data(PLUGIN_DIR . '/woo-razorpay.php')['Version'];
        $Wc_Razorpay_Loader = new WC_Razorpay();

        $data = array(
            'key'          => $Wc_Razorpay_Loader->getSetting('key_id'),
            'name'         => get_bloginfo('name'),
            '_'            => array(
                'x-integration'                   => 'Woocommerce',
                'x-integration-module'            => 'Route',
                'x-integration-version'           => $mod_version,
                'x-integration-parent-version'    => WOOCOMMERCE_VERSION,
            ),
        );

        $Wc_Razorpay_Loader->enqueueCheckoutScripts('routeAnalyticsForm');

        $url = Api::getFullUrl("checkout/embedded");

        $formFields = "";
        foreach ($data as $fieldKey => $val) {
            if(in_array($fieldKey, array('prefill', '_')))
            {
                foreach ($data[$fieldKey] as $field => $fieldVal) {
                    $formFields .= "<input type='hidden' name='$fieldKey" ."[$field]"."' value='$fieldVal'> \n";
                }
            }
        }

        return '<form method="POST" action="'.$url.'" id="routeAnalyticsForm">
                    <input type="hidden" name="key_id" value="'.$data['key'].'">
                    <input type="hidden" name="name" value="'.$data['name'].'">
                    '. $formFields .'
                </form>';

    }

}
