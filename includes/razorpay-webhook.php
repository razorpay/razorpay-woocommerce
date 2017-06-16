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

        $this->api = $this->razorpay->getRazorpayApiInstance();

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

                // Move this to the base class as well
                if ($order->needs_payment() === false)
                {
                    return;
                }

                $razorpayPaymentId = $data['payload']['payment']['entity']['id'];

                $payment = $this->api->payment->fetch($razorpayPaymentId);

                $amount = $data['payload']['payment']['entity']['amount'];

                $success = false;
                $errorMessage = 'The payment has failed.';

                if ($payment['status'] === 'captured')
                {
                    $success = true;
                }
                else if (($payment['status'] === 'authorized') and
                         ($this->razorpay->payment_action === 'capture'))
                {
                    //
                    // If the payment is only authorized, we capture it
                    // If the merchant has enabled auto capture
                    //
                    $payment->capture(array('amount' => $amount));
                }

                $this->razorpay->updateOrder($order, $success, $errorMessage, $razorpayPaymentId);

                exit;
            }
        }
    }
}
