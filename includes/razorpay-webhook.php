<?php

require_once __DIR__.'/../razorpay-payments.php';

require_once __DIR__.'/../razorpay-sdk/Razorpay.php';
use Razorpay\Api\Api;

class RZP_Webhook 
{
    protected $razorpay; 

    protected $keyId;
    protected $keySecret;

    protected $api;

    function __construct()
    {
        $this->razorpay = new WC_Razorpay();

        $this->keyId = $this->razorpay->key_id;
        $this->keySecret = $this->razorpay->key_secret;

        $this->api = new Api($this->keyId, $this->keySecret);

        $this->auto_capture_webhook();
    }

    function auto_capture_webhook()
    {
        $post = file_get_contents('php://input');

        $data = json_decode($post, true);

        if ($this->razorpay->enable_webhook === 'yes' && empty($data['event']) === false)
        {   
            // if payment.authorized webhook is enabled, we will update woocommerce about captured payments
            // We have to complete the payment only if the order needs payment
            if ($data['event'] === "payment.authorized")
            {
                global $woocommerce;
                $orderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];
                $order = new WC_Order($orderId);

                if ($order->needs_payment() === false)
                {
                    return;
                }

                $razorpayPaymentId = $data['payload']['payment']['entity']['id'];

                $payment = $this->api->payment->fetch($razorpayPaymentId);

                if ($payment['status'] === 'captured')
                {
                    $this->razorpay->msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon. Order Id: $orderId";
                    $this->razorpay->msg['class'] = 'success';
                    $order->payment_complete();
                    $order->add_order_note("Razorpay payment successful <br/>Razorpay Id: $razorpayPaymentId");
                    $order->add_order_note($this->razorpay->msg['message']);
                    // $woocommerce->cart->empty_cart();
                }
                else 
                {
                    $this->razorpay->msg['class'] = 'error';
                    $this->razorpay->msg['message'] = 'Thank you for shopping with us. However, the payment failed.';
                    $order->add_order_note("Transaction Declined: $error<br/>");
                    $order->add_order_note("Payment Failed. Please check Razorpay Dashboard. <br/> Razorpay Id: $razorpayPaymentId");
                    $order->update_status('failed');
                }

                $redirect_url = $this->razorpay->get_return_url($order);
                wp_redirect($redirect_url);
                exit;
            }
        }
    }
}