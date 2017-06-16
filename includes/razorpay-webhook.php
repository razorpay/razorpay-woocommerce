<?php

require_once __DIR__.'/../razorpay-payments.php';

require_once __DIR__.'/../razorpay-sdk/Razorpay.php';
use Razorpay\Api\Api;

class RZP_Webhook
{
    protected $razorpay;
    protected $api;

    function __construct()
    {
        $this->razorpay = new WC_Razorpay();

        $this->api = $this->getRazorpayApiInstance();

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

                $success = false;
                $errorMessage = 'The payment has failed.';

                if ($payment['status'] === 'captured')
                {
                    $success = true;
                }

                $this->razorpay->updateOrder($order, $success, $errorMessage, $razorpayPaymentId);

                $redirect_url = $this->razorpay->get_return_url($order);
                wp_redirect($redirect_url);
                exit;
            }
        }
    }
}
