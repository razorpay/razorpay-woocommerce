<?php

require_once __DIR__ . '/../woo-razorpay.php';
require_once __DIR__ . '/../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

class RZP_Webhook
{
    /**
     * @var HTTP CONFLICT Request
     */
    protected const HTTP_CONFLICT_STATUS = 409;

    /**
     * @var Webhook Notify Wait Time
     */
    protected const WEBHOOK_NOTIFY_WAIT_TIME = (5 * 60);

    /**
     * Instance of the razorpay payments class
     * @var WC_Razorpay
     */
    protected $razorpay;

    /**
     * API client instance to communicate with Razorpay API
     * @var Razorpay\Api\Api
     */
    protected $api;

    /**
     * Event constants
     */
    const PAYMENT_AUTHORIZED       = 'payment.authorized';
    const PAYMENT_FAILED           = 'payment.failed';
    const PAYMENT_PENDING          = 'payment.pending';
    const SUBSCRIPTION_CANCELLED   = 'subscription.cancelled';
    const REFUNDED_CREATED         = 'refund.created';
    const VIRTUAL_ACCOUNT_CREDITED = 'virtual_account.credited';
    const SUBSCRIPTION_PAUSED      = 'subscription.paused';
    const SUBSCRIPTION_RESUMED     = 'subscription.resumed';
    const SUBSCRIPTION_CHARGED     = 'subscription.charged';

    protected $eventsArray = [
        self::PAYMENT_AUTHORIZED,
        self::VIRTUAL_ACCOUNT_CREDITED,
        self::REFUNDED_CREATED,
        self::PAYMENT_FAILED,
        self::PAYMENT_PENDING,
        self::SUBSCRIPTION_CANCELLED,
        self::SUBSCRIPTION_PAUSED,
        self::SUBSCRIPTION_RESUMED,
        self::SUBSCRIPTION_CHARGED,
    ];

    public function __construct()
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
     * - invoice_id set in payment.authorized
     * - order refunded
     * - Invalid JSON
     * - Signature mismatch
     * - Secret isn't setup
     * - Event not recognized
     *
     * @return void|WP_Error
     * @throws Exception
     */
    public function process()
    {
        $post = file_get_contents('php://input');

        $data = json_decode($post, true);

        if (json_last_error() !== 0) {
            return;
        }

        if (empty($data['event']) === false) {

            $orderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_number'];
            $razorpayOrderId = $data['payload']['payment']['entity']['order_id'];

            // Skip the webhook if not the valid data and event
            if ($this->shouldConsumeWebhook($data) === false) {
                rzpLogInfo("Woocommerce orderId: $orderId webhook process exited in shouldConsumeWebhook function");

                return;
            }
            if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) === true) {
                $razorpayWebhookSecret = $this->razorpay->getSetting('webhook_secret');
                //
                // If the webhook secret isn't set on wordpress, return
                //
                if (empty($razorpayWebhookSecret) === true) {
                    $razorpayWebhookSecret = get_option('rzp_webhook_secret');
                    if (empty($razorpayWebhookSecret) === false) {
                        $this->razorpay->update_option('webhook_secret', $razorpayWebhookSecret);
                    } else {
                        rzpLogInfo("Woocommerce orderId: $orderId webhook process exited due to secret not available");

                        return;
                    }
                }

                try
                {
                    $this->api->utility->verifyWebhookSignature($post,
                        $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'],
                        $razorpayWebhookSecret);
                } catch (Errors\SignatureVerificationError $e) {
                    $log = array(
                        'message' => $e->getMessage(),
                        'data'    => $data,
                        'event'   => 'razorpay.wc.signature.verify_failed',
                    );

                    rzpLogError(json_encode($log));

                    error_log(json_encode($log));
                    return;
                }

                $rzpWebhookNotifiedAt = get_post_meta($orderId, "rzp_webhook_notified_at", true);
                if ($rzpWebhookNotifiedAt === '')
                {
                    update_post_meta($orderId, "rzp_webhook_notified_at", time());
                    error_log("ORDER NUMBER $orderId:webhook conflict due to early execution for razorpay order: $razorpayOrderId ");
                    header('Status: ' . static::HTTP_CONFLICT_STATUS . ' Webhook conflicts due to early execution.', true, static::HTTP_CONFLICT_STATUS);// nosemgrep : php.lang.security.non-literal-header.non-literal-header
                    return;
                }
                elseif ((time() - $rzpWebhookNotifiedAt) < static::WEBHOOK_NOTIFY_WAIT_TIME)
                {
                    error_log("ORDER NUMBER $orderId:webhook conflict due to early execution for razorpay order: $razorpayOrderId ");
                    header('Status: ' . static::HTTP_CONFLICT_STATUS . ' Webhook conflicts due to early execution.', true, static::HTTP_CONFLICT_STATUS);// nosemgrep : php.lang.security.non-literal-header.non-literal-header
                    return;
                }

                error_log("ORDER NUMBER $orderId:webhook conflict over for razorpay order: $razorpayOrderId");

                rzpLogInfo("Woocommerce orderId: $orderId webhook process intitiated");

                switch ($data['event']) {
                    case self::PAYMENT_AUTHORIZED:
                        return $this->paymentAuthorized($data);

                    case self::VIRTUAL_ACCOUNT_CREDITED:
                        return $this->virtualAccountCredited($data);

                    case self::PAYMENT_FAILED:
                        return $this->paymentFailed($data);

                    case self::PAYMENT_PENDING:
                        return $this->paymentPending($data);

                    case self::SUBSCRIPTION_CANCELLED:
                        return $this->subscriptionCancelled($data);

                    case self::REFUNDED_CREATED:
                        return $this->refundedCreated($data);

                    case self::SUBSCRIPTION_PAUSED:
                        return $this->subscriptionPaused($data);

                    case self::SUBSCRIPTION_RESUMED:
                        return $this->subscriptionResumed($data);

                    case self::SUBSCRIPTION_CHARGED:
                        return $this->subscriptionCharged($data);

                    default:
                        return;
                }
            }
        }
    }

    /**
     * Does nothing for the main payments flow currently
     * @param array $data Webook Data
     */
    protected function paymentFailed(array $data)
    {
        return;
    }

    /**
     * Does nothing for the main payments flow currently
     * @param array $data Webook Data
     */
    protected function subscriptionCancelled(array $data)
    {
        return;
    }

    /**
     * Does nothing for the main payments flow currently
     * @param array $data Webook Data
     */
    protected function subscriptionPaused(array $data)
    {
        return;
    }

    /**
     * Does nothing for the main payments flow currently
     * @param array $data Webook Data
     */
    protected function subscriptionResumed(array $data)
    {
        return;
    }

    /**
     * Handling next subscription charged webhook
     * @param array $data Webook Data
     */
    protected function subscriptionCharged(array $data)
    {
        return;
    }

    /**
     * Handling the payment authorized webhook
     *
     * @param array $data Webook Data
     */
    protected function paymentAuthorized(array $data)
    {
        // We don't process subscription/invoice payments here
        if (isset($data['payload']['payment']['entity']['invoice_id']) === true) {
            return;
        }

        //
        // Order entity should be sent as part of the webhook payload
        //
        $orderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_number'];

        rzpLogInfo("Woocommerce orderId: $orderId webhook process intitiated for payment authorized event");

        if (!empty($orderId)) {
            $order = $this->checkIsObject($orderId);
        }

        $orderStatus = $order->get_status();
        rzpLogInfo("Woocommerce orderId: $orderId order status: $orderStatus");

        // If it is already marked as paid, ignore the event
        if ($orderStatus != 'draft' and
            ($order->needs_payment() === false and
                ($orderStatus === 'cancelled') === false)) {
            rzpLogInfo("Woocommerce orderId: $orderId webhook process exited with need payment status :" . $order->needs_payment());

            return;
        }

        if ($orderStatus == 'draft') {
            updateOrderStatus($orderId, 'wc-pending');
        }

        $razorpayPaymentId = $data['payload']['payment']['entity']['id'];

        $payment = $this->getPaymentEntity($razorpayPaymentId, $data);

        $amount = $this->getOrderAmountAsInteger($order);

        $success      = false;
        $errorMessage = 'The payment has failed.';

        if ($payment['status'] === 'captured') {
            $success = true;
        } else if (($payment['status'] === 'authorized') and
            ($this->razorpay->getSetting('payment_action') === WC_Razorpay::CAPTURE)) {
            //
            // If the payment is only authorized, we capture it
            // If the merchant has enabled auto capture
            //
            try
            {
                $payment->capture(array('amount' => $amount));

                $success = true;
            } catch (Exception $e) {
                //
                // Capture will fail if the payment is already captured
                //
                $log = array(
                    'message'    => $e->getMessage(),
                    'payment_id' => $razorpayPaymentId,
                    'event'      => $data['event'],
                );

                error_log(json_encode($log));

                //
                // We re-fetch the payment entity and check if the payment is captured now
                //
                $payment = $this->getPaymentEntity($razorpayPaymentId, $data);

                if ($payment['status'] === 'captured') {
                    $success = true;
                }
            }
        }

        $this->razorpay->updateOrder($order, $success, $errorMessage, $razorpayPaymentId, null, true);
        rzpLogInfo("Woocommerce orderId: $orderId webhook process finished the update order function");

        rzpLogInfo("Woocommerce orderId: $orderId webhook process finished the updateOrder function");

        // Graceful exit since payment is now processed.
        exit;
    }

    /**
     * Handling the payment pending webhook to handle COD orders
     *
     * @param array $data Webook Data
     */
    protected function paymentPending(array $data)
    {
        // We don't process subscription/invoice payments here
        if (isset($data['payload']['payment']['entity']['invoice_id']) === true) {
            return;
        }

        if (isset($data['payload']['payment']['entity']['method']) != 'cod') {
            return;
        }

        //
        // Order entity should be sent as part of the webhook payload
        //
        $orderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_number'];

        rzpLogInfo("Woocommerce orderId: $orderId webhook process intitiated for COD method payment pending event");

        if (!empty($orderId)) {
            $order = $this->checkIsObject($orderId);
        }

        $orderStatus = $order->get_status();
        rzpLogInfo("Woocommerce orderId: $orderId order status: $orderStatus");

        // If it is already marked as paid, ignore the event
        if ($orderStatus != 'draft' && $order->needs_payment() === false) {
            rzpLogInfo("Woocommerce orderId: $orderId webhook process exited with need payment status :" . $order->needs_payment());

            return;
        }

        if ($orderStatus == 'draft') {
            updateOrderStatus($orderId, 'wc-pending');
        }

        $razorpayPaymentId = $data['payload']['payment']['entity']['id'];

        $payment = $this->getPaymentEntity($razorpayPaymentId, $data);

        $success      = false;
        $errorMessage = 'The payment has failed.';

        if ($payment['status'] === 'pending' && $data['payload']['payment']['entity']['method'] == 'cod' && !empty($razorpayPaymentId)) {
            $success = true;

            $this->razorpay->updateOrder($order, $success, $errorMessage, $razorpayPaymentId, null, true);
            rzpLogInfo("Woocommerce orderId: $orderId webhook process finished the update order function for COD");
        }

        // Graceful exit since payment is now processed.
        exit;
    }

    /**
     * Handling the virtual account credited webhook
     *
     * @param array $data Webook Data
     */
    protected function virtualAccountCredited(array $data)
    {
        // We don't process subscription/invoice payments here
        if (isset($data['payload']['payment']['entity']['invoice_id']) === true) {
            return;
        }

        //
        // Order entity should be sent as part of the webhook payload
        //
        $orderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_number'];

        if (!empty($orderId)) {
            $order = $this->checkIsObject($orderId);
        }
        // If it is already marked as paid, ignore the event
        if ($order->needs_payment() === false) {
            return;
        }

        $razorpayPaymentId = $data['payload']['payment']['entity']['id'];
        $virtualAccountId  = $data['payload']['virtual_account']['entity']['id'];
        $amountPaid        = (int) $data['payload']['virtual_account']['entity']['amount_paid'];

        $payment = $this->getPaymentEntity($razorpayPaymentId, $data);

        $amount = $this->getOrderAmountAsInteger($order);

        $success      = false;
        $errorMessage = 'The payment has failed.';

        if ($payment['status'] === 'captured' and $amountPaid === $amount) {
            $success = true;
        } else if (($payment['status'] === 'authorized') and $amountPaid === $amount and
            ($this->razorpay->getSetting('payment_action') === WC_Razorpay::CAPTURE)) {
            //
            // If the payment is only authorized, we capture it
            // If the merchant has enabled auto capture
            //
            try
            {
                $payment->capture(array('amount' => $amount));

                $success = true;
            } catch (Exception $e) {
                //
                // Capture will fail if the payment is already captured
                //
                $log = array(
                    'message'    => $e->getMessage(),
                    'payment_id' => $razorpayPaymentId,
                    'event'      => $data['event'],
                );

                error_log(json_encode($log));

                //
                // We re-fetch the payment entity and check if the payment is captured now
                //
                $payment = $this->getPaymentEntity($razorpayPaymentId, $data);

                if ($payment['status'] === 'captured') {
                    $success = true;
                }
            }
        }

        $this->razorpay->updateOrder($order, $success, $errorMessage, $razorpayPaymentId, $virtualAccountId, true);

        // Graceful exit since payment is now processed.
        exit;
    }

    protected function getPaymentEntity($razorpayPaymentId, $data)
    {
        try
        {
            $payment = $this->api->payment->fetch($razorpayPaymentId);
        } catch (Exception $e) {
            $log = array(
                'message'    => $e->getMessage(),
                'payment_id' => $razorpayPaymentId,
                'event'      => $data['event'],
            );

            error_log(json_encode($log));

            exit;
        }

        return $payment;
    }

    /**
     * Returns boolean false incase not proper webhook data
     */
    protected function shouldConsumeWebhook($data)
    {
        if ((isset($data['event']) === true) and
            (in_array($data['event'], $this->eventsArray) === true) and
            (isset($data['payload']['payment']['entity']['notes']['woocommerce_order_number']) === true or isset($data['payload']['subscription']['entity']['notes']['woocommerce_order_id']) === true)) {
            return true;
        }

        return false;
    }

    /**
     * Returns the order amount, rounded as integer
     * @param WC_Order $order WooCommerce Order instance
     * @return int Order Amount
     */
    public function getOrderAmountAsInteger($order)
    {
        if (version_compare(WOOCOMMERCE_VERSION, '3.0.0', '>=')) {
            return (int) round($order->get_total() * 100);
        }

        return (int) round($order->order_total * 100);
    }

    /**
     * Process Order Refund through Webhook
     * @param array $data
     * @return void|WP_Error
     * @throws Exception
     */
    public function refundedCreated(array $data)
    {
        // We don't process subscription/invoice payments here
        if (isset($data['payload']['payment']['entity']['invoice_id']) === true) {
            return;
        }

        //Avoid to recreate refund, If already refund saved and initiated from woocommerce website.
        if (isset($data['payload']['refund']['entity']['notes']['refund_from_website']) === true) {
            return;
        }

        $razorpayPaymentId = $data['payload']['refund']['entity']['payment_id'];

        $refundId = $data['payload']['refund']['entity']['id'];

        $payment = $this->getPaymentEntity($razorpayPaymentId, $data);

        //
        // Order entity should be sent as part of the webhook payload
        //
        $orderId = $payment['notes']['woocommerce_order_number'];

        if (!empty($orderId)) {
            $order = $this->checkIsObject($orderId);
        }

        // If it is already marked as unpaid, ignore the event
        if ($order->needs_payment() === true) {
            return;
        }

        // If it's something else such as a WC_Order_Refund, we don't want that.
        if (!is_a($order, 'WC_Order')) {
            $log = array(
                'Error' => 'Provided ID is not a WC Order',
            );

            error_log(json_encode($log));
        }

        if ('refunded' == $order->get_status()) {
            $log = array(
                'Error' => 'Order has been already refunded for Order Id -' . $orderId,
            );

            error_log(json_encode($log));
        }

        $refundAmount = round(($data['payload']['refund']['entity']['amount'] / 100), 2);

        $refundReason = $data['payload']['refund']['entity']['notes']['comment'];

        try
        {
            wc_create_refund(array(
                'amount'         => $refundAmount,
                'reason'         => $refundReason,
                'order_id'       => $orderId,
                'refund_id'      => $refundId,
                'line_items'     => array(),
                'refund_payment' => false,
            ));

            $order->add_order_note(__('Refund Id: ' . $refundId, 'woocommerce'));

        } catch (Exception $e) {
            //
            // Capture will fail if the payment is already captured
            //
            $log = array(
                'message'    => $e->getMessage(),
                'payment_id' => $razorpayPaymentId,
                'event'      => $data['event'],
            );

            error_log(json_encode($log));

        }

        // Graceful exit since payment is now refunded.
        exit();
    }

    public function checkIsObject($orderId)
    {
        $order = wc_get_order($orderId);
        if (is_object($order)) {
            return wc_get_order($orderId);
        } else {
            rzpLogInfo("Woocommerce order Object does not exist");
            exit();
        }
    }
}
