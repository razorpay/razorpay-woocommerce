<?php

require_once __DIR__.'/razorpay-payments.php';

require_once __DIR__.'/razorpay-sdk/Razorpay.php';
use Razorpay\Api\Api;

class RZP_Webhook 
{
    function __construct()
    {
        $this->auto_capture_webhook();
    }

    function auto_capture_webhook()
    {
        $razorpay = new WC_Razorpay();

        $post = file_get_contents('php://input');

        $data = json_decode($post, true);

        if ($razorpay->enable_webhook == 'yes' && !empty($data['event']))
        {   
            // if payment.authorized webhook is enabled, we will update woocommerce about captured payments
            if ($data['event'] == "payment.authorized")
            {
                global $woocommerce;
                $order_id = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];
                $order = new WC_Order($order_id);
                
                $key_id = $razorpay->key_id;
                $key_secret = $razorpay->key_secret;

                $razorpay_payment_id = $data['payload']['payment']['entity']['id'];

                $api = new Api($key_id, $key_secret);

                $payment = $api->payment->fetch($razorpay_payment_id);

                if ($payment['status'] == 'captured')
                {
                    $razorpay->msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon. Order Id: $order_id";
                    $razorpay->msg['class'] = 'success';
                    $order->payment_complete();
                    $order->add_order_note("Razorpay payment successful <br/>Razorpay Id: $razorpay_payment_id");
                    $order->add_order_note($razorpay->msg['message']);
                    //$woocommerce->cart->empty_cart();
                }

                else 
                {
                    $razorpay->msg['class'] = 'error';
                    $razorpay->msg['message'] = 'Thank you for shopping with us. However, the payment failed.';
                    $order->add_order_note("Transaction Declined: $error<br/>");
                    $order->add_order_note("Payment Failed. Please check Razorpay Dashboard. <br/> Razorpay Id: $razorpay_payment_id");
                    $order->update_status('failed');
                }

                $redirect_url = $razorpay->get_return_url($order);
                wp_redirect( $redirect_url );
                exit;
            }
        }
    }
}