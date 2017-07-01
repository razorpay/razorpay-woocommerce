<?php

require_once __DIR__.'/../razorpay-payments.php';
require_once __DIR__.'/../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

class RZP_Webhook
{
    protected $razorpay;
    protected $api;

    function __construct()
    {
        $this->razorpay = new WC_Razorpay();

        $this->api = $this->razorpay->getRazorpayApiInstance();
    }

    public function process()
    {
        $post = file_get_contents('php://input');

        $data = json_decode($post, true);

        if (json_last_error() !== 0)
        {
            return;
        }

        if ($this->razorpay->enable_webhook === 'yes' && empty($data['event']) === false)
        {
            if ((isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) === true))
            {
                $razorpayWebhookSecret = $this->razorpay->webhook_secret;

                //
                // If the webhook secret isn't set on wordpress, return
                //
                if (empty($razorpayWebhookSecret) === true)
                {
                    return;
                }

                try
                {
                    $this->api->utility->verifyWebhookSignature($post,
                                                                $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'],
                                                                $razorpayWebhookSecret);
                }
                catch (Errors\SignatureVerificationError $e)
                {
                    $log = array(
                        'message'   => $e->getMessage(),
                        'data'      => $data,
                        'event'     => 'razorpay.wc.signature..verify_failed'
                    );

                    write_log($log);
                    return;
                }
            }

            switch ($data['event'])
            {
                case 'payment.authorized':
                    return $this->paymentAuthorized($data);

                default:
                    return;
            }
        }
    }

    protected function paymentAuthorized($data)
    {
        global $woocommerce;

        //
        // Order entity should be sent as part of the webhook payload
        //
        $orderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];

        $order = new WC_Order($orderId);

        if ($order->needs_payment() === false)
        {
            return;
        }

        $razorpayPaymentId = $data['payload']['payment']['entity']['id'];

        $payment = $this->api->payment->fetch($razorpayPaymentId);

        $amount = $this->razorpay->getOrderAmountAsInteger($order);

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
