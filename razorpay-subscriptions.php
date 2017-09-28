<?php
/*
Plugin Name: Razorpay Subscriptions for WooCommerce
Plugin URI: https://razorpay.com
Description: Razorpay Subscriptions for WooCommerce
Version: 1.0.0
Stable tag: 1.0.0
Author: Razorpay
Author URI: https://razorpay.com
*/

if ( ! defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly
}

define('RAZORPAY_WOOCOMMERCE_PLUGIN', 'woo-razorpay');
$pluginRoot = WP_PLUGIN_DIR . '/' . RAZORPAY_WOOCOMMERCE_PLUGIN;

require_once $pluginRoot . '/razorpay-payments.php';
require_once $pluginRoot . '/razorpay-sdk/Razorpay.php';
require_once __DIR__ . '/includes/razorpay-subscription-webhook.php';
require_once __DIR__ . '/includes/Errors/SubscriptionErrorCode.php';
require_once __DIR__ . '/includes/razorpay-subscriptions.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;
use Razorpay\Woocommerce\Errors as WooErrors;

// Load this after the woo-razorpay plugin
add_action('plugins_loaded', 'woocommerce_razorpay_subscriptions_init', 20);
add_action('admin_post_nopriv_rzp_wc_webhook', 'razorpay_webhook_subscription_init', 20);

function woocommerce_razorpay_subscriptions_init()
{
    if (!class_exists('WC_Payment_Gateway'))
    {
        return;
    }

    class WC_Razorpay_Subscription extends WC_Razorpay
    {
        public $id = 'razorpay_subscriptions';
        public $method_title = 'Razorpay Subscriptions';

        const RAZORPAY_SUBSCRIPTION_ID       = 'razorpay_subscription_id';
        const DEFAULT_LABEL                  = 'MasterCard/Visa Credit Card';
        const DEFAULT_DESCRIPTION            = 'Setup automatic recurring billing on a MasterCard or Visa Credit Card';

        protected $visibleSettings = array(
            'enabled',
            'title',
            'description',
        );

        public $supports = array(
            'subscriptions',
            'subscription_reactivation',
            'subscription_suspension',
            'subscription_cancellation',
        );

        public function __construct()
        {
            parent::__construct();
            $this->mergeSettingsWithParentPlugin();
            $this->setupExtraHooks();
        }

        private function mergeSettingsWithParentPlugin()
        {
            // Easiest way to read config of a different plugin
            // is to initialize it
            $gw = new WC_Razorpay(false);

            $parentSettings = array(
                'key_id',
                'key_secret',
                'webhook_secret',
            );

            foreach ($parentSettings as $key)
            {
                $this->settings[$key] = $gw->settings[$key];
            }
        }

        protected function setupExtraHooks()
        {
            add_action('woocommerce_subscription_status_cancelled', array(&$this, 'subscription_cancelled'));

            // Hide Subscriptions Gateway for non-subscription payments
            add_filter('woocommerce_available_payment_gateways', array($this, 'disable_non_subscription'), 20);
        }

        public function disable_non_subscription($availableGateways)
        {
            global $woocommerce;

            $enable = WC_Subscriptions_Cart::cart_contains_subscription();

            if ($enable === false)
            {
                if (isset($availableGateways[$this->id]))
                {
                    unset($availableGateways[$this->id]);
                }
            }

            return $availableGateways;
        }

        public function admin_options()
        {
            echo '<h3>'.__('Razorpay Subscriptions Payment Gateway', $this->id) . '</h3>';
            echo '<p>'.__('Allows recurring payments by MasterCard/Visa Credit Cards') . '</p>';
            echo '<table class="form-table">';

            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
        }

        protected function getSubscriptionSessionKey($orderId)
        {
            return self::RAZORPAY_SUBSCRIPTION_ID . $orderId;
        }

        protected function getRazorpayPaymentParams($orderId)
        {
            $this->subscriptions = new RZP_Subscriptions($this->getSetting('key_id'), $this->getSetting('key_secret'));

            try
            {
                $subscriptionId = $this->subscriptions->createSubscription($orderId);

                add_post_meta($orderId, self::RAZORPAY_SUBSCRIPTION_ID, $subscriptionId);
            }
            catch (Exception $e)
            {
                $message = $e->getMessage();

                throw new Exception("RAZORPAY ERROR: Subscription creation failed with the following message: '$message'");
            }

            return [
                'recurring'         => 1,
                'subscription_id'   => $subscriptionId,
            ];
        }

        public function init_form_fields()
        {
            parent::init_form_fields();
            $this->fields = $this->form_fields;

            unset($this->fields['payment_action']);

            $this->form_fields = $this->fields;
        }

        protected function getDisplayAmount($order)
        {
            return $this->subscriptions->getDisplayAmount($order);
        }

        protected function verifySignature($orderId)
        {
            global $woocommerce;

            $api = $this->getRazorpayApiInstance();

            $sessionKey = $this->getSubscriptionSessionKey($orderId);

            $attributes = array(
                self::RAZORPAY_PAYMENT_ID       => $_POST['razorpay_payment_id'],
                self::RAZORPAY_SIGNATURE        => $_POST['razorpay_signature'],
                self::RAZORPAY_SUBSCRIPTION_ID  => $woocommerce->session->get($sessionKey),
            );

            $api->utility->verifyPaymentSignature($attributes);

            add_post_meta($orderId, self::RAZORPAY_SUBSCRIPTION_ID, $attributes[self::RAZORPAY_SUBSCRIPTION_ID]);
        }

        public function subscription_cancelled($subscription)
        {
            $orderIds = array_keys($subscription->get_related_orders());

            $parentOrderId = $orderIds[0];

            $subscriptionId = get_post_meta($parentOrderId, self::RAZORPAY_SUBSCRIPTION_ID)[0];

            $this->subscriptions->cancelSubscription($subscriptionId);
        }
    }

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_razorpay_subscriptions_gateway(array $methods)
    {
        $methods[] = 'WC_Razorpay_Subscription';

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_razorpay_subscriptions_gateway');
}

function razorpay_webhook_subscription_init()
{
    $rzpWebhook = new RZP_Subscription_Webhook();

    $rzpWebhook->process();
}
