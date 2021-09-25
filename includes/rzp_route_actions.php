<?php

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

require_once __DIR__ . '/rzp_route.php';

class RZP_Route_Action
{

    public function __construct()
    {
        $Wc_Razorpay_Loader = new WC_Razorpay();
        $this->api = $Wc_Razorpay_Loader->getRazorpayApiInstance();

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

}
