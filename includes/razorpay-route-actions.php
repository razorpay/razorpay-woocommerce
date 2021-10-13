<?php

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

require_once __DIR__ . '/razorpay-route.php';

class RZP_Route_Action
{

    public function __construct()
    {
        $this->Wc_Razorpay_Loader = new WC_Razorpay();
        $this->api = $this->Wc_Razorpay_Loader->getRazorpayApiInstance();

    }

    function direct_transfer()
    {

        $trf_account = sanitize_text_field($_POST['drct_trf_account']);
        $trf_amount = sanitize_text_field($_POST['drct_trf_amount']);
        $page_url = admin_url('admin.php?page=razorpay_route_woocommerce');
        try {
            $transfer_data = array(

                'account' => $trf_account,
                'amount' => (int)round($trf_amount * 100),
                'currency' => 'INR'
            );

            $this->api->transfer->create($transfer_data);
        } catch (Exception $e) {
            $message = $e->getMessage();

            wp_die('<div class="error notice">
                    <p>RAZORPAY ERROR: Transfers create failed with the following message: ' . $message . '</p>
                 </div>');
        }
        wp_redirect($page_url);
    }

    function reverse_transfer()
    {

        $transfer_id = sanitize_text_field($_POST['transfer_id']);
        $reversal_amount = sanitize_text_field($_POST['reversal_amount']);
        $page_url = admin_url('admin.php?page=razorpay_transfers&id=' . $transfer_id);
        try {
            $reversal_data = array(
                'amount' => (int)round($reversal_amount * 100),
            );

            $this->api->transfer->fetch($transfer_id)->reverse($reversal_data);
        } catch (Exception $e) {
            $message = $e->getMessage();

            wp_die('<div class="error notice">
                    <p>RAZORPAY ERROR: Reverse Transfer failed with the following message: ' . $message . '</p>
                 </div>');
        }
        wp_redirect($page_url);
    }

    function update_transfer_settlement()
    {

        $transfer_id = sanitize_text_field($_POST['transfer_id']);
        $trf_hold_status = sanitize_text_field($_POST['on_hold']);
        if ($trf_hold_status == "on_hold_until") {
            $trf_hold_until = sanitize_text_field($_POST['hold_until']);
            $unix_time = strtotime($trf_hold_until);

            $trf_hold_status = true;
        }

        $page_url = admin_url('admin.php?page=razorpay_transfers&id=' . $transfer_id);
        try {
            $update_data = array(
                'on_hold' => $trf_hold_status,
                'on_hold_until' => $unix_time,
            );

            $url = "transfers/" . $transfer_id;
            $this->api->request->request("PATCH", $url, $update_data);

        } catch (Exception $e) {
            $message = $e->getMessage();

            wp_die('<div class="error notice">
                    <p>RAZORPAY ERROR: Change settlement schedule failed with the following message: ' . $message . '</p>
                 </div>');
        }
        wp_redirect($page_url);
    }

    function create_payment_transfer()
    {

        $payment_id = sanitize_text_field($_POST['payment_id']);
        $trf_account = sanitize_text_field($_POST['pay_trf_account']);
        $trf_amount = sanitize_text_field($_POST['pay_trf_amount']);
        $page_url = admin_url('admin.php?page=razorpay_payments_view&id=' . $payment_id);

        $trf_hold_status = sanitize_text_field($_POST['on_hold']);
        if ($trf_hold_status == "on_hold_until") {
            $trf_hold_until = sanitize_text_field($_POST['hold_until']);
            $unix_time = strtotime($trf_hold_until);

            $trf_hold_status = true;
        }
        try {

            $data = array(
                'transfers' => array(
                    array(
                        'account' => $trf_account,
                        'amount' => (int)round($trf_amount * 100),
                        'currency' => 'INR',
                        'on_hold' => $trf_hold_status,
                        'on_hold_until' => $unix_time,)
                )
            );

            $this->api->payment->fetch($payment_id)->transfer($data);

        } catch (Exception $e) {
            $message = $e->getMessage();

            wp_die('<div class="error notice">
                    <p>RAZORPAY ERROR: Transfers create failed with the following message: ' . $message . '</p>
                 </div>');
        }
        wp_redirect($page_url);
    }

    function add_linked_account()
    {

        $la_number = sanitize_text_field($_POST['rzp_account_number']);
        $la_name = sanitize_text_field($_POST['rzp_account_name']);
        $page_url = admin_url('admin.php?page=razorpay_route_accounts');
        try {
            global $wpdb;
            $table_name = $wpdb->prefix . "razorpay_accounts";
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                          id int(11) NOT NULL AUTO_INCREMENT,
                          la_name tinytext NOT NULL,
                          la_number varchar(50) NOT NULL,
                          PRIMARY KEY  id (id)
                        ) $charset_collate";

            $wpdb->query($sql);

            $insert = "INSERT INTO $table_name (la_name, la_number)
            SELECT * FROM (SELECT '$la_name', '$la_number') AS tmp
            WHERE NOT EXISTS (
                            SELECT la_number FROM $table_name WHERE la_number = '$la_number'
            ) LIMIT 1";

            $wpdb->query($insert);

        } catch (Exception $e) {
            $message = $e->getMessage();

            wp_die('<div class="error notice">
                    <p>RAZORPAY ERROR: Add accounts failed with the following message: ' . $message . '</p>
                 </div>');
        }
        wp_redirect($page_url);
    }

    function getOrderTransferData($orderId){
        $order = wc_get_order($orderId);

        $items = $order->get_items();
        $order_transfer_arr = array();

        foreach ( $items as $item ) {
            $product_id = $item['product_id'];
            $rzp_transfer_from =   get_post_meta($product_id, 'rzp_transfer_from', true);

            if($rzp_transfer_from == 'from_order'){

                $LA_number_arr =   get_post_meta($product_id, 'LA_number', true);
                $LA_amount_arr =   get_post_meta($product_id, 'LA_transfer_amount', true);
                $LA_trf_status_arr =   get_post_meta($product_id, 'LA_transfer_status', true);

                if(isset($LA_number_arr) && is_array($LA_number_arr) && isset($LA_amount_arr) && is_array($LA_amount_arr)) {
                    $LA_transfer_count = count($LA_number_arr);
                    for($i=0;$i<$LA_transfer_count;$i++){
                        if(!empty($LA_number_arr[$i]) && !empty($LA_amount_arr[$i])){

                            $transfer_arr = array(

                                'account'=> $LA_number_arr[$i],
                                'amount'=> (int) round($LA_amount_arr[$i] * 100),
                                'currency'=> 'INR',
                                'on_hold'=> $LA_trf_status_arr[$i]
                            );

                            array_push($order_transfer_arr, $transfer_arr);
                        }
                    }
                }
            }

        }

        return $order_transfer_arr;
    }

    function transferFromPayment($orderId, $razorpayPaymentId){

        $order = wc_get_order($orderId);

        $items = $order->get_items();
        $payment_transfer_arr = array();

        foreach ( $items as $item ) {
            $product_id = $item['product_id'];
            $rzp_transfer_from =   get_post_meta($product_id, 'rzp_transfer_from', true);

            if($rzp_transfer_from == 'from_payment'){

                $LA_number_arr =   get_post_meta($product_id, 'LA_number', true);
                $LA_amount_arr =   get_post_meta($product_id, 'LA_transfer_amount', true);
                $LA_trf_status_arr =   get_post_meta($product_id, 'LA_transfer_status', true);

                if(isset($LA_number_arr) && is_array($LA_number_arr) && isset($LA_amount_arr) && is_array($LA_amount_arr)) {
                    $LA_transfer_count = count($LA_number_arr);
                    for($i=0;$i<$LA_transfer_count;$i++){
                        if(!empty($LA_number_arr[$i]) && !empty($LA_amount_arr[$i])){
                            $transfer_arr = array(

                                'account'=> $LA_number_arr[$i],
                                'amount'=> (int) round($LA_amount_arr[$i] * 100),
                                'currency'=> 'INR',
                                'on_hold'=> $LA_trf_status_arr[$i]
                            );
                            array_push($payment_transfer_arr, $transfer_arr);
                        }
                    }
                }
            }

        }

        if(isset($payment_transfer_arr) && !empty($payment_transfer_arr)){

            $data = array(

                'transfers' => $payment_transfer_arr
            );


            $url = "payments/".$razorpayPaymentId."/transfers";

            $this->api->request->request("POST", $url, $data);

            $this->Wc_Razorpay_Loader->addRouteAnalyticsScript();

        }

    }

}
