<?php

require_once __DIR__.'/../razorpay-payments.php';
require_once __DIR__.'/../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

class RZP_Subscription_Webhook
{
    const RAZORPAY_SUBSCRIPTION_ID = 'razorpay_subscription_id';

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

                switch ($data['event'])
                {
                    case 'payment.authorized':
                        return $this->paymentAuthorized($data);

                    case 'payment.failed':
                        return $this->paymentFailed($data);

                    // if it is subscription.charged
                    case 'subscription.charged':
                        return $this->subscriptionCharged($data);

                    default:
                        return;
                }
            }
        }
    }

    /**
     * Handling the payment authorized webhook
     *
     * @param $data
     */
    protected function paymentAuthorized($data)
    {
        //
        // Order entity should be sent as part of the webhook payload
        //
        $orderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];

        $paymentId = $data['payload']['payment']['entity']['id'];

        if (isset($data['payload']['payment']['entity']['subscription_id']) === true)
        {
            return $this->processSubscription($orderId, $paymentId);
        }

        $order = new WC_Order($orderId);

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
                 ($this->razorpay->payment_action === 'capture'))
        {
            //
            // If the payment is only authorized, we capture it
            // If the merchant has enabled auto capture
            //
            $payment->capture(array('amount' => $amount));

            $success = true;
        }

        $this->razorpay->updateOrder($order, $success, $errorMessage, $razorpayPaymentId, true);

        exit;
    }

    /**
     * Currently we handle only subscription failures using this webhook
     *
     * @param $data
     */
    protected function paymentFailed($data)
    {
        //
        // Order entity should be sent as part of the webhook payload
        //
        $orderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];

        $paymentId = $data['payload']['payment']['entity']['id'];

        if (isset($data['payload']['payment']['subscription_id']) === true)
        {
            $this->processSubscription($orderId, $paymentId, false);
        }

        exit;
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

        $this->processSubscription($orderId);

        exit;
    }

    /**
     * Helper method used to handle all subscription processing
     *
     * @param $orderId
     * @param $paymentId
     * @param $success
     */
    protected function processSubscription($orderId, $paymentId, $success = true)
    {
        //
        // If success is false, automatically process subscription failure
        //
        if ($success === false)
        {
            return $this->processSubscriptionFailed($orderId);
        }

        $subscriptionId = get_post_meta($orderId, self::RAZORPAY_SUBSCRIPTION_ID)[0];

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
     * @param $wcSubscription
     * @param $subscription
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
        else if ($paymentCount + 1 === $subscription->paid_count)
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
     * Returns the order amount, rounded as integer
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
