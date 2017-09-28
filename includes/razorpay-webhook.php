<?php

require_once __DIR__.'/../razorpay-payments.php';
require_once __DIR__.'/../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

class RZP_Webhook
{
    protected $razorpay;
    protected $api;

    const PAYMENT_AUTHORIZED = 'payment.authorized';
    const PAYMENT_FAILED     = 'payment.failed';
    const SUBSCRIPTION_CHARGED = 'subscription.charged';

    const RAZORPAY_SUBSCRIPTION_ID = 'razorpay_subscription_id';

    function __construct()
    {
        $this->razorpay = new WC_Razorpay(false);

        $this->api = $this->razorpay->getRazorpayApiInstance();
    }

    /**
     * Process a Razorpay Webhook. We exit in the following cases:
     * - Successful processed
     * - Exception while fetching the payment
     *
     * It passes on the webhook in the following cases:
     * - subscription_id set in payment.authorized
     * - Invalid JSON
     * - Signature mismatch
     * - Secret isn't setup
     * - Event not recognized
     */
    public function process()
    {
        $post = file_get_contents('php://input');

        $data = json_decode($post, true);

        if (json_last_error() !== 0)
        {
            return;
        }

        if ($this->razorpay->getSetting('enable_webhook') === 'yes' && empty($data['event']) === false)
        {
            if ((isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) === true))
            {
                $razorpayWebhookSecret = $this->razorpay->getSetting('webhook_secret');

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
                        'event'     => 'razorpay.wc.signature.verify_failed'
                    );

                    write_log($log);
                    return;
                }

                switch ($data['event'])
                {
                    case self::PAYMENT_AUTHORIZED:
                        return $this->paymentAuthorized($data);

                    case self::PAYMENT_FAILED:
                        return $this->paymentFailed($data);

                    case self::SUBSCRIPTION_CHARGED:
                        return $this->subscriptionCharged($data);

                    default:
                        return;
                }
            }
        }
    }

    /**
     * Handling the subscription charged webhook
     *
     * @param $data
     */
    protected function subscriptionCharged($data)
    {
        //
        // Order entity should be sent as part of the webhook payload
        //
        $orderId = $data['payload']['subscription']['entity']['notes']['woocommerce_order_id'];

        $paymentId = "";

        // TODO: Need to add payment_id to the subscription.charged webhook
        if (isset($data['payload']['payment']['entity']['payment_id']) === true)
        {
            $paymentId = $data['payload']['payment']['entity']['payment_id'];
        }

        // We get the subscription id from the webhook payload
        $subscriptionId = $data['payload']['subscription']['entity']['id'];

        // By default subscription charged is triggered when success = true
        $this->processSubscription($orderId, $paymentId, $subscriptionId, true);

        exit;
    }

    /**
     * Helper method used to handle all subscription processing
     *
     * @param $orderId
     * @param string $paymentId
     * @param $subscriptionId
     * @param bool $success
     * @return string|void
     */
    protected function processSubscription($orderId, $paymentId = "", $subscriptionId, $success = true)
    {
        //
        // If success is false, automatically process subscription failure
        //

        if ($success === false)
        {
            return $this->processSubscriptionFailed($orderId);
        }

        $api = $this->razorpay->getRazorpayApiInstance();

        try
        {
            $subscription = $api->subscription->fetch($subscriptionId);
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();
            return 'RAZORPAY ERROR: Subscription fetch failed with the message \'' . $message . '\'';
        }

        $this->processSubscriptionSuccess($orderId, $subscription, $paymentId);

        exit;
    }

    /**
     * In the case of successful payment, we mark the subscription successful
     *
     * @param $orderId
     * @param $subscription
     * @param $paymentId
     */
    protected function processSubscriptionSuccess($orderId, $subscription, $paymentId)
    {
        //
        // This method is used to process the subscription's recurring payment
        //
        $wcSubscription = wcs_get_subscriptions_for_order($orderId);

        $wcSubscriptionId = array_keys($wcSubscription)[0];

        //
        // We will only process one subscription per order
        //
        $wcSubscription = array_values($wcSubscription)[0];

        if (count($wcSubscription) > 1)
        {
            $log = array(
                'Error' => 'There are more than one subscription products in this order'
            );

            write_log($log);

            exit;
        }

        $paymentCount = $wcSubscription->get_completed_payment_count();

        //
        // The subscription is completely paid for
        //
        if ($paymentCount === $subscription->total_count)
        {
            return;
        }
        else if ($paymentCount === $subscription->paid_count + 1)
        {
            //
            // If subscription has been paid for on razorpay's end, we need to mark the
            // subscription payment to be successful on woocommerce's end
            //
            WC_Subscriptions_Manager::prepare_renewal($wcSubscriptionId);

            $wcSubscription->payment_complete($paymentId);
        }
    }

    /**
     * In the case of payment failure, we mark the subscription as failed
     *
     * @param $orderId
     */
    protected function processSubscriptionFailed($orderId)
    {
        WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($orderId);
    }

    /**
     * Does nothing for the main payments flow currently
     */
    protected function paymentFailed(array $data)
    {
        return;
    }

    /**
     * Handling the payment authorized webhook
     *
     * @param array $data
     */
    protected function paymentAuthorized(array $data)
    {
        //
        // Order entity should be sent as part of the webhook payload
        //
        $orderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];

        $paymentId = $data['payload']['payment']['entity']['id'];

        // We don't process subscription payments here
        if (isset($data['payload']['payment']['entity']['subscription_id']))
        {
            return;
        }

        $order = new WC_Order($orderId);

        // If it is already marked as paid, ignore the event
        if ($order->needs_payment() === false)
        {
            return;
        }

        $razorpayPaymentId = $data['payload']['payment']['entity']['id'];

        try
        {
            $payment = $this->api->payment->fetch($razorpayPaymentId);
        }
        catch (Exception $e)
        {
            $log = array(
                'message'   => $e->getMessage(),
                'data'      => $razorpayPaymentId,
                'event'     => $data['event']
            );

            write_log($log);

            exit;
        }

        $amount = $this->getOrderAmountAsInteger($order);

        $success = false;
        $errorMessage = 'The payment has failed.';

        if ($payment['status'] === 'captured')
        {
            $success = true;
        }
        else if (($payment['status'] === 'authorized') and
                 ($this->razorpay->getSetting('payment_action') === 'capture'))
        {
            //
            // If the payment is only authorized, we capture it
            // If the merchant has enabled auto capture
            //
            $payment->capture(array('amount' => $amount));

            $success = true;
        }

        $this->razorpay->updateOrder($order, $success, $errorMessage, $razorpayPaymentId, true);

        // Graceful exit since payment is now processed.
        exit;
    }

    /**
     * Returns the order amount, rounded as integer
     * @return int Order Amount
     */
    public function getOrderAmountAsInteger($order)
    {
        if (version_compare(WOOCOMMERCE_VERSION, '3.0.0', '>='))
        {
            return (int) round($order->get_total() * 100);
        }

        return (int) round($order->order_total * 100);
    }
}
