<?php

require_once __DIR__.'/../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;
use Razorpay\Woocommerce\Errors as WooErrors;

class RZP_Subscriptions
{
    protected $razorpay;

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
                WooErrors\ErrorCode::API_SUBSCRIPTION_CREATION_FAILED,
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
                WooErrors\ErrorCode::API_SUBSCRIPTION_CANCELLATION_FAILED,
                400
            );
        }
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
        $productId = $product['product_id'];

        $metadata = get_post_meta($productId);

        list($planId, $created) = $this->createOrGetPlanId($metadata, $product);

        //
        // If we created a new planId, we have to store it as post metadata
        //
        if ($created === true)
        {
            add_post_meta($productId, self::RAZORPAY_PLAN_ID, $planId, true);
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
    protected function createOrGetPlanId($metadata, $product)
    {
        $planArgs = $this->getPlanArguments($product);

        //
        // If razorpay_plan_id is set in the metadata,
        // we check if the amounts match and return the plan id
        //
        if (isset($metadata[self::RAZORPAY_PLAN_ID]) === true)
        {
            $planId = $metadata[self::RAZORPAY_PLAN_ID][0];

            try
            {
                $plan = $this->api->plan->fetch($planId);
            }
            catch (Exception $e)
            {
                $message = $e->getMessage();

                throw new Errors\Error(
                    $message,
                    WooErrors\ErrorCode::API_PLAN_FETCH_FAILED,
                    400
                );
            }

            if ($plan['item']['amount'] === $planArgs['item']['amount'])
            {
                return array($plan['id'], false);
            }

            //
            // If the amounts don't match we have to create a
            // new plan. Therefore, we have to delete the old plan id
            //
            delete_post_meta($productId, self::RAZORPAY_PLAN_ID);
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
                WooErrors\ErrorCode::API_PLAN_CREATION_FAILED,
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

        $salePrice    = WC_Subscriptions_product::get_meta_data($productId, 'sale_price', 0);

        //
        // If the item is on sale, sell it for the sale price
        //
        if (empty($salePrice) === false)
        {
            $recurringFee = $salePrice;
        }

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

        try
        {
            $customer = $this->api->customer->create($data);
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();

            throw new Errors\Error(
                $message,
                WooErrors\ErrorCode::API_CUSTOMER_CREATION_FAILED,
                400
            );
        }

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

    public function getProduct($order)
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
        return 'razorpay_subscription_id' . $orderId;
    }

    protected function getRazorpayApiInstance()
    {
        return new Api($this->keyId, $this->keySecret);
    }
}
