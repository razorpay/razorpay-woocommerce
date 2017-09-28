<?php

use Razorpay\Api\Api;
use Razorpay\Api\Errors;
use Razorpay\Woocommerce\Errors as WooErrors;

class RZP_Subscriptions
{
    protected $razorpay;
    protected $api;

    const RAZORPAY_SUBSCRIPTION_ID       = 'razorpay_subscription_id';
    const RAZORPAY_PLAN_ID               = 'razorpay_wc_plan_id';
    const INR                            = 'INR';

    public function __construct($keyId, $keySecret)
    {
        $this->api = new Api($keyId, $keySecret);

        $this->razorpay = new WC_Razorpay();
    }

    public function createSubscription($orderId)
    {
        global $woocommerce;

        $subscriptionData = $this->getSubscriptionCreateData($orderId);

        try
        {
            $subscription = $this->api->subscription->create($subscriptionData);
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();

            throw new Errors\Error(
                $message,
                WooErrors\SubscriptionErrorCode::API_SUBSCRIPTION_CREATION_FAILED,
                400
            );
        }

        // Setting the subscription id as the session variable
        $sessionKey = $this->getSubscriptionSessionKey($orderId);

        $woocommerce->session->set($sessionKey, $subscription['id']);

        return $subscription['id'];
    }

    public function cancelSubscription($subscriptionId)
    {
        try
        {
            $subscription = $this->api->subscription->cancel($subscriptionId);
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();

            throw new Errors\Error(
                $message,
                WooErrors\SubscriptionErrorCode::API_SUBSCRIPTION_CANCELLATION_FAILED,
                400
            );
        }
    }

    protected function getSubscriptionCreateData($orderId)
    {
        $order = new WC_Order($orderId);

        $product = $this->getProductFromOrder($order);

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
                'woocommerce_order_id'   => $orderId,
                'woocommerce_product_id' => $product['product_id']
            ),
        );

        $signUpFee = WC_Subscriptions_Product::get_sign_up_fee($product['product_id']);

        if ($signUpFee)
        {
            $item = array(
                'amount'   => (int) round($signUpFee * 100),
                'currency' => get_woocommerce_currency(),
                'name'     => $product['name']
            );

            if ($item['currency'] !== self::INR)
            {
                $this->razorpay->handleCurrencyConversion($item);
            }

            $subscriptionData['addons'] = array(array('item' => $item));
        }

        return $subscriptionData;
    }

    protected function getProductPlanId($product)
    {
        $currency = get_woocommerce_currency();

        $key = self::RAZORPAY_PLAN_ID . '_'. strtolower($currency);

        $productId = $product['product_id'];

        $metadata = get_post_meta($productId);

        list($planId, $created) = $this->createOrGetPlanId($metadata, $product, $key);

        //
        // If new plan was created, we delete the old plan id
        // If we created a new planId, we have to store it as post metadata
        //
        if ($created === true)
        {
            delete_post_meta($productId, $key);

            add_post_meta($productId, $key, $planId, true);
        }

        return $planId;
    }

    /**
     * Takes in product metadata and product
     * Creates or gets created plan
     *
     * @param $metadata,
     * @param $product
     *
     * @return string $planId
     * @return bool $created
     */
    protected function createOrGetPlanId($metadata, $product, $key)
    {
        $planArgs = $this->getPlanArguments($product);

        //
        // If razorpay_plan_id is set in the metadata,
        // we check if the amounts match and return the plan id
        //
        if (isset($metadata[$key]) === true)
        {
            $create = false;

            $planId = $metadata[$key][0];

            try
            {
                $plan = $this->api->plan->fetch($planId);
            }
            catch (Exception $e)
            {
                //
                // If plan id fetch causes an error, we re-create the plan
                //
                $create = true;
            }

            if (($create === false) and
                ($plan['item']['amount'] === $planArgs['item']['amount']))
            {
                return array($plan['id'], false);
            }
        }

        //
        // By default we create a new plan
        // if metadata doesn't have plan id set
        //
        $planId = $this->createPlan($planArgs);

        return array($planId, true);
    }

    protected function createPlan($planArgs)
    {
        try
        {
            $plan = $this->api->plan->create($planArgs);
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();

            throw new Errors\Error(
                $message,
                WooErrors\SubscriptionErrorCode::API_PLAN_CREATION_FAILED,
                400
            );
        }

        // Storing the plan id as product metadata, unique set to true
        return $plan['id'];
    }

    protected function getPlanArguments($product)
    {
        $productId = $product['product_id'];

        $period       = WC_Subscriptions_Product::get_period($productId);

        $interval     = WC_Subscriptions_Product::get_interval($productId);

        $recurringFee = WC_Subscriptions_Product::get_price($productId);

        //
        // Ad-Hoc code
        //
        if ($period === 'year')
        {
            $period = 'month';

            $interval *= 12;
        }

        $planArgs = array(
            'period'   => $this->getProductPeriod($period),
            'interval' => $interval
        );

        $item = array(
            'name'     => $product['name'],
            'amount'   => (int) round($recurringFee * 100),
            'currency' => get_woocommerce_currency(),
        );

        if ($item['currency'] !== self::INR)
        {
            $this->razorpay->handleCurrencyConversion($item);
        }

        $planArgs['item'] = $item;

        return $planArgs;
    }

    public function getDisplayAmount($order)
    {
        $product = $this->getProductFromOrder($order);

        $productId = $product['product_id'];

        $recurringFee = WC_Subscriptions_Product::get_price($productId);

        $signUpFee = WC_Subscriptions_Product::get_sign_up_fee($productId);

        return $recurringFee + $signUpFee;
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
        $data = $this->razorpay->getCustomerInfo($order);

        //
        // This line of code tells api that if a customer is already created,
        // return the created customer instead of throwing an exception
        // https://docs.razorpay.com/v1/page/customers-api
        //
        $data['fail_existing'] = '0';

        try
        {
            $customer = $this->api->customer->create($data);
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();

            throw new Errors\Error(
                $message,
                WooErrors\SubscriptionErrorCode::API_CUSTOMER_CREATION_FAILED,
                400
            );
        }

        return $customer['id'];
    }

    public function getProductFromOrder($order)
    {
        $products = $order->get_items();

        //
        // Technically, subscriptions work only if there's one array in the cart
        //
        if (sizeof($products) > 1)
        {
            throw new Exception('Currently Razorpay does not support more than'
                                . ' one product in the cart if one of the products'
                                . ' is a subscription.');
        }

        return array_values($products)[0];
    }

    protected function getSubscriptionSessionKey($orderId)
    {
        return self::RAZORPAY_SUBSCRIPTION_ID . $orderId;
    }

    protected function getRazorpayApiInstance()
    {
        return new Api($this->keyId, $this->keySecret);
    }
}
