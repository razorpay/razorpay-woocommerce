<?php

require_once __DIR__.'/../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;

class RZP_Subscriptions
{
    public function __construct($keyId, $keySecret)
    {
        $this->api = new Api($keyId, $keySecret);
    }

    public function createSubscription($orderId)
    {
        global $woocommerce;

        $subscriptionData = $this->getSubscriptionCreateData($orderId);

        $subscription = $this->api->subscription->create($subscriptionData);

        // Setting the subscription id as the session variable
        $sessionKey = $this->getSubscriptionSessionKey($orderId);
        $woocommerce->session->set($sessionKey, $subscription['id']);

        return $subscription['id'];
    }

    public function cancelSubscription($subscriptionId)
    {
        $subscription = $this->api->subscription->cancel($subscriptionId);
    }

    protected function getProduct($order)
    {
        $products = $order->get_items();

        // Technically, subscriptions work only if there's one array in the cart
        if (sizeof($products) > 1)
        {
            throw new Exception('Currently Razorpay does not support more than
                                one product in the cart if one of products
                                is a subscription.');
        }

        return array_values($products)[0];
    }

    protected function getSubscriptionCreateData($orderId)
    {
        $order = new WC_Order($orderId);

        $product = $this->getProduct($order);

        $planId = $this->getProductPlanId($product);

        $customerId = $this->getCustomerId($order);

        $length = (int) WC_Subscriptions_Product::get_length($product['product_id']);

        $subscriptionData = array(
            'customer_id'     => $customerId,
            'plan_id'         => $planId,
            'quantity'        => (int) $product['qty'],
            'total_count'     => $length,
            'customer_notify' => 0,
            'notes'           => array(
                'woocommerce_order_id' => $orderId
            ),
        );

        $signUpFee = WC_Subscriptions_Product::get_sign_up_fee($product['product_id']);

        if ($signUpFee)
        {
            $subscriptionData['addons'] = array(
                array (
                    'item' => array(
                        'amount'   => (int) round($signUpFee * 100, 2),
                        'currency' => get_woocommerce_currency(),
                        'name'     => $product['name']
                    )
                )
            );
        }

        return $subscriptionData;
    }

    protected function getProductPlanId($product)
    {
        $productId = $product['product_id'];

        $metadata = get_post_meta($productId);

        // Creating a new plan only if plan isn't created already
        if (empty($metadata['razorpay_wc_plan_id']) === true)
        {
            $planId = $this->createPlan($product);
            add_post_meta($productId, 'razorpay_wc_plan_id', $planId, true);
        }
        else
        {
            // Retrieve the plan id if already created
            $planId = $metadata['razorpay_wc_plan_id'][0];
        }

        return $planId;
    }

    protected function createPlan($product)
    {
        $productId = $product['product_id'];

        $planArgs = $this->getPlanArguments($product);

        $plan = $this->api->plan->create($planArgs);

        // Storing the plan id as product metadata, unique set to true
        return $plan['id'];
    }

    protected function getPlanArguments($product)
    {
        $productId = $product['product_id'];

        $period       = WC_Subscriptions_Product::get_period($productId);
        $interval     = WC_Subscriptions_Product::get_interval($productId);
        $recurringFee = WC_Subscriptions_Product::get_price($productId);

        $planArgs = array(
            'item' => array(
                'name'     => $product['name'],
                'amount'   => (int) round($recurringFee * 100, 2),
                'currency' => get_woocommerce_currency(),
            ),
            'period'   => $this->getProductPeriod($period),
            'interval' => $interval
        );

        return $planArgs;
    }

    protected function getProductPeriod($period)
    {
        $periodMap = array(
            'day'   => 'daily',
            'week'  => 'weekly',
            'month' => 'monthly',
            'year'  => 'yearly'
        );

        return $periodMap[$period];
    }

    protected function getCustomerId($order)
    {
        $data = $this->getCustomerInfo($order);
        $data['fail_existing'] = '0';

        $customer = $this->api->customer->create($data);

        return $customer['id'];
    }

    protected function getCustomerInfo($order)
    {
        if (version_compare(WOOCOMMERCE_VERSION, '2.7.0', '>='))
        {
            $args = array(
                'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'email'   => $order->get_billing_email(),
                'contact' => $order->get_billing_phone(),
            );
        }
        else
        {
            $args = array(
                'name'    => $order->billing_first_name . ' ' . $order->billing_last_name,
                'email'   => $order->billing_email,
                'contact' => $order->billing_phone,
            );
        }

        return $args;
    }

    protected function getSubscriptionSessionKey($orderId)
    {
        return "razorpay_subscription_id".$orderId;
    }

    protected function getRazorpayApiInstance()
    {
        return new Api($this->keyId, $this->keySecret);
    }
}
