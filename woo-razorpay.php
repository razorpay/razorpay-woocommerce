<?php
/*
 * Plugin Name: Razorpay for WooCommerce
 * Plugin URI: https://razorpay.com
 * Description: Razorpay Payment Gateway Integration for WooCommerce
 * Version: 2.8.5
 * Stable tag: 2.8.5
 * Author: Team Razorpay
 * WC tested up to: 6.1.1
 * Author URI: https://razorpay.com
*/

if ( ! defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly
}

require_once __DIR__.'/includes/razorpay-webhook.php';
require_once __DIR__.'/razorpay-sdk/Razorpay.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once __DIR__.'/includes/razorpay-route.php';
require_once __DIR__ .'/includes/razorpay-route-actions.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

add_action('plugins_loaded', 'woocommerce_razorpay_init', 0);
add_action('admin_post_nopriv_rzp_wc_webhook', 'razorpay_webhook_init', 10);

function woocommerce_razorpay_init()
{
    if (!class_exists('WC_Payment_Gateway'))
    {
        return;
    }

    class WC_Razorpay extends WC_Payment_Gateway
    {
        // This one stores the WooCommerce Order Id
        const SESSION_KEY                    = 'razorpay_wc_order_id';
        const RAZORPAY_PAYMENT_ID            = 'razorpay_payment_id';
        const RAZORPAY_ORDER_ID              = 'razorpay_order_id';
        const RAZORPAY_SIGNATURE             = 'razorpay_signature';
        const RAZORPAY_WC_FORM_SUBMIT        = 'razorpay_wc_form_submit';

        const INR                            = 'INR';
        const CAPTURE                        = 'capture';
        const AUTHORIZE                      = 'authorize';
        const WC_ORDER_ID                    = 'woocommerce_order_id';
        const WC_ORDER_NUMBER                = 'woocommerce_order_number';

        const DEFAULT_LABEL                  = 'Credit Card/Debit Card/NetBanking';
        const DEFAULT_DESCRIPTION            = 'Pay securely by Credit or Debit card or Internet Banking through Razorpay.';
        const DEFAULT_SUCCESS_MESSAGE        = 'Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon.';

        protected $visibleSettings = array(
            'enabled',
            'title',
            'description',
            'key_id',
            'key_secret',
            'payment_action',
            'order_success_message',
            'enable_webhook',
            'webhook_events',
            'webhook_secret',
            'route_enable',
        );

        public $form_fields = array();

        public $supports = array(
            'products',
            'refunds'
        );

        /**
         * Can be set to true if you want payment fields
         * to show on the checkout (if doing a direct integration).
         * @var boolean
         */
        public $has_fields = false;

        /**
         * Unique ID for the gateway
         * @var string
         */
        public $id = 'razorpay';

        /**
         * Title of the payment method shown on the admin page.
         * @var string
         */
        public $method_title = 'Razorpay';


        /**
         * Description of the payment method shown on the admin page.
         * @var  string
         */
        public $method_description = 'Allow customers to securely pay via Razorpay (Credit/Debit Cards, NetBanking, UPI, Wallets)';

        /**
         * Icon URL, set in constructor
         * @var string
         */
        public $icon;

        /**
         * TODO: Remove usage of $this->msg
         */
        protected $msg = array(
            'message'   =>  '',
            'class'     =>  '',
        );

        /**
         * Return Wordpress plugin settings
         * @param  string $key setting key
         * @return mixed setting value
         */
        public function getSetting($key)
        {
            return $this->get_option($key);
        }

        protected function getCustomOrdercreationMessage()
        {
            $message =  $this->getSetting('order_success_message');
            if (isset($message) === false)
            {
                $message = STATIC::DEFAULT_SUCCESS_MESSAGE;
            }
            return $message;
        }

        /**
         * @param boolean $hooks Whether or not to
         *                       setup the hooks on
         *                       calling the constructor
         */
        public function __construct($hooks = true)
        {
            $this->icon =  "https://cdn.razorpay.com/static/assets/logo/payment.svg";

            $this->init_form_fields();
            $this->init_settings();

            // TODO: This is hacky, find a better way to do this
            // See mergeSettingsWithParentPlugin() in subscriptions for more details.
            if ($hooks)
            {
                $this->initHooks();
            }

            $this->title = $this->getSetting('title');
        }

        protected function initHooks()
        {
            add_action('init', array(&$this, 'check_razorpay_response'));

            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

            add_action('woocommerce_api_' . $this->id, array($this, 'check_razorpay_response'));

            $cb = array($this, 'process_admin_options');

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
            {
                add_action("woocommerce_update_options_payment_gateways_{$this->id}", $cb);
                add_action( "woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'autoEnableWebhook'));
            }
            else
            {
                add_action('woocommerce_update_options_payment_gateways', $cb);
                add_action( "woocommerce_update_options_payment_gateways", array($this, 'autoEnableWebhook'));
            }

            add_filter( 'woocommerce_thankyou_order_received_text', array($this, 'rzpThankYouMessage'), 20, 2 );
        }

        public function init_form_fields()
        {
            $webhookUrl = esc_url(admin_url('admin-post.php')) . '?action=rzp_wc_webhook';

            $defaultFormFields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', $this->id),
                    'type' => 'checkbox',
                    'label' => __('Enable this module?', $this->id),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', $this->id),
                    'type'=> 'text',
                    'description' => __('This controls the title which the user sees during checkout.', $this->id),
                    'default' => __(static::DEFAULT_LABEL, $this->id)
                ),
                'description' => array(
                    'title' => __('Description', $this->id),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', $this->id),
                    'default' => __(static::DEFAULT_DESCRIPTION, $this->id)
                ),
                'key_id' => array(
                    'title' => __('Key ID', $this->id),
                    'type' => 'text',
                    'description' => __('The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.', $this->id)
                ),
                'key_secret' => array(
                    'title' => __('Key Secret', $this->id),
                    'type' => 'text',
                    'description' => __('The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.', $this->id)
                ),
                'payment_action' => array(
                    'title' => __('Payment Action', $this->id),
                    'type' => 'select',
                    'description' =>  __('Payment action on order compelete', $this->id),
                    'default' => self::CAPTURE,
                    'options' => array(
                        self::AUTHORIZE => 'Authorize',
                        self::CAPTURE   => 'Authorize and Capture'
                    )
                ),
                'order_success_message' => array(
                    'title' => __('Order Completion Message', $this->id),
                    'type'  => 'textarea',
                    'description' =>  __('Message to be displayed after a successful order', $this->id),
                    'default' =>  __(STATIC::DEFAULT_SUCCESS_MESSAGE, $this->id),
                ),
                'enable_webhook' => array(
                    'title' => __('Enable Webhook', $this->id),
                    'type' => 'checkbox',
                    'description' =>  "<span>$webhookUrl</span><br/><br/>Instructions and guide to <a href='https://github.com/razorpay/razorpay-woocommerce/wiki/Razorpay-Woocommerce-Webhooks'>Razorpay webhooks</a>",
                    'label' => __('Enable Razorpay Webhook', $this->id),
                    'default' => 'no'
                ),
                'webhook_events' => array(
                    'title'       => __('Webhook Events', $this->id),
                    'type'        => 'multiselect',
                    'description' =>  "",
                    'class'       => 'wc-enhanced-select',
                    'default'     => '',
                    'options'     => array(
                        RZP_Webhook::PAYMENT_AUTHORIZED        => 'payment.authorized',
                        RZP_Webhook::REFUNDED_CREATED          => 'refund.created',
                        RZP_Webhook::VIRTUAL_ACCOUNT_CREDITED  => 'virtual_account.credited',
                    ),
                    'custom_attributes' => array(
                        'data-placeholder' => __( 'Select Webhook Events', 'woocommerce' ),
                    ),
                ),
                'webhook_secret' => array(
                    'title' => __('Webhook Secret', $this->id),
                    'type' => 'text',
                    'description' => __('Webhook secret is used for webhook signature verification. This has to match the one added <a href="https://dashboard.razorpay.com/#/app/webhooks">here</a>', $this->id),
                    'default' => ''
                ),

            );

            do_action_ref_array( 'setup_extra_setting_fields', array( &$defaultFormFields ) );

            foreach ($defaultFormFields as $key => $value)
            {
                if (in_array($key, $this->visibleSettings, true))
                {
                    $this->form_fields[$key] = $value;
                }
            }
        }

        public function autoEnableWebhook()
        {
            $webhookExist = false;
            $webhookUrl   = esc_url(admin_url('admin-post.php')) . '?action=rzp_wc_webhook';

            $key_id      = $this->getSetting('key_id');
            $key_secret  = $this->getSetting('key_secret');
            $enabled     = $this->getSetting('enable_webhook');
            $secret      = $this->getSetting('webhook_secret');

            //validating the key id and key secret set properly or not.
            if($key_id == null || $key_secret == null)
            {
                ?>
                <div class="notice error is-dismissible" >
                    <p><b><?php _e( 'Key Id and Key Secret are required.'); ?><b></p>
                </div>
                <?php

                error_log('Key Id and Key Secret are required.');
                return;
            }

            $eventsSubscribe = $this->getSetting('webhook_events');

            $prepareEventsData = [];

            if(empty($eventsSubscribe) == false)
            {
                foreach ($eventsSubscribe as $value)
                {
                    $prepareEventsData[$value] = true;
                }
            }

            $domain = parse_url($webhookUrl, PHP_URL_HOST);

            $domain_ip = gethostbyname($domain);

            if (!filter_var($domain_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
            {
                $this->update_option( 'enable_webhook', 'no' );

                ?>
                <div class="notice error is-dismissible" >
                    <p><b><?php _e( 'Could not enable webhook for localhost server.'); ?><b></p>
                </div>
                <?php

                error_log('Could not enable webhook for localhost');
                return;
            }

            if($enabled === 'no')
            {
                $data = [
                    'url'    => $webhookUrl,
                    'active' => false,
                ];
            }
            else
            {
                //validating event is not empty
                if(empty($eventsSubscribe) === true)
                {
                    ?>
                    <div class="notice error is-dismissible" >
                        <p><b><?php _e( 'At least one webhook event needs to be subscribed to enable webhook.'); ?><b></p>
                    </div>
                    <?php

                    error_log('At least one webhook event needs to be subscribed to enable webhook.');
                    return;
                }

                //validating webhook secret is not empty
                if(empty($secret) === true)
                {
                    ?>
                    <div class="notice error is-dismissible" >
                        <p><b><?php _e( 'Webhook secret field can`t be empty.' ); ?><b></p>
                    </div>
                    <?php

                    error_log('Webhook secret field can`t be empty.');
                    return;
                }

                $data = [
                    'url'    => $webhookUrl,
                    'active' => $enabled == 'yes' ? true: false,
                    'events' => $prepareEventsData,
                    'secret' => $secret,
                ];

            }

            $webhook = $this->webhookAPI("GET", "webhooks");

            if(count($webhook) > 0)
            {
                foreach ($webhook['items'] as $key => $value)
                {
                    if($value['url'] === $webhookUrl)
                    {
                        $webhookExist  = true;
                        $webhookId     = $value['id'];
                    }
                }
            }

            if($webhookExist)
            {
                $this->webhookAPI('PUT', "webhooks/".$webhookId, $data);
            }
            else
            {
                $this->webhookAPI('POST', "webhooks/", $data);
            }

        }

        protected function webhookAPI($method, $url, $data = array())
        {
            $webhook = [];
            try
            {
                $api = $this->getRazorpayApiInstance();

                $webhook = $api->request->request($method, $url, $data);
            }
            catch(Exception $e)
            {
                $log = array(
                    'message' => $e->getMessage(),
                );

                error_log(json_encode($log));
            }

            return $webhook;
        }

        public function admin_options()
        {
            echo '<h3>'.__('Razorpay Payment Gateway', $this->id) . '</h3>';
            echo '<p>'.__('Allows payments by Credit/Debit Cards, NetBanking, UPI, and multiple Wallets') . '</p>';
            echo '<table class="form-table">';

            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
        }

        public function get_description()
        {
            return $this->getSetting('description');
        }

        /**
         * Receipt Page
         * @param string $orderId WC Order Id
         **/
        function receipt_page($orderId)
        {
            echo $this->generate_razorpay_form($orderId);
        }

        /**
         * Returns key to use in session for storing Razorpay order Id
         * @param  string $orderId Razorpay Order Id
         * @return string Session Key
         */
        protected function getOrderSessionKey($orderId)
        {
            return self::RAZORPAY_ORDER_ID . $orderId;
        }

        /**
         * Given a order Id, find the associated
         * Razorpay Order from the session and verify
         * that is is still correct. If not found
         * (or incorrect), create a new Razorpay Order
         *
         * @param  string $orderId Order Id
         * @return mixed Razorpay Order Id or Exception
         */
        protected function createOrGetRazorpayOrderId($orderId)
        {
            global $woocommerce;

            $sessionKey = $this->getOrderSessionKey($orderId);

            $create = false;

            try
            {
                $razorpayOrderId = get_transient($sessionKey);

                // If we don't have an Order
                // or the if the order is present in transient but doesn't match what we have saved
                if (($razorpayOrderId === false) or
                    (($razorpayOrderId and ($this->verifyOrderAmount($razorpayOrderId, $orderId)) === false)))
                {
                    $create = true;
                }
                else
                {
                    return $razorpayOrderId;
                }
            }
                // Order doesn't exist or verification failed
                // So try creating one
            catch (Exception $e)
            {
                $create = true;
            }

            if ($create)
            {
                try
                {
                    return $this->createRazorpayOrderId($orderId, $sessionKey);
                }
                    // For the bad request errors, it's safe to show the message to the customer.
                catch (Errors\BadRequestError $e)
                {
                    return $e;
                }
                    // For any other exceptions, we make sure that the error message
                    // does not propagate to the front-end.
                catch (Exception $e)
                {
                    return new Exception("Payment failed");
                }
            }
        }

        /**
         * Returns redirect URL post payment processing
         * @return string redirect URL
         */
        private function getRedirectUrl($orderId)
        {
            $order = wc_get_order($orderId);

            $query = [
                'wc-api' => $this->id,
                'order_key' => $order->get_order_key(),
            ];
            return add_query_arg($query, trailingslashit( get_home_url()));
        }

        /**
         * Specific payment parameters to be passed to checkout
         * for payment processing
         * @param  string $orderId WC Order Id
         * @return array payment params
         */
        protected function getRazorpayPaymentParams($orderId)
        {
            $razorpayOrderId = $this->createOrGetRazorpayOrderId($orderId);

            if ($razorpayOrderId === null)
            {
                throw new Exception('RAZORPAY ERROR: Razorpay API could not be reached');
            }
            else if ($razorpayOrderId instanceof Exception)
            {
                $message = $razorpayOrderId->getMessage();

                throw new Exception("RAZORPAY ERROR: Order creation failed with the message: '$message'.");
            }

            return [
                'order_id'  =>  $razorpayOrderId
            ];
        }

        /**
         * Generate razorpay button link
         * @param string $orderId WC Order Id
         **/
        public function generate_razorpay_form($orderId)
        {
            $order = wc_get_order($orderId);

            try
            {
                $params = $this->getRazorpayPaymentParams($orderId);
            }
            catch (Exception $e)
            {
                return $e->getMessage();
            }

            $checkoutArgs = $this->getCheckoutArguments($order, $params);

            $html = '<p>'.__('Thank you for your order, please click the button below to pay with Razorpay.', $this->id).'</p>';

            $html .= $this->generateOrderForm($checkoutArgs);

            return $html;
        }

        /**
         * default parameters passed to checkout
         * @param  WC_Order $order WC Order
         * @return array checkout params
         */
        private function getDefaultCheckoutArguments($order)
        {
            global $woocommerce;

            $orderId = $order->get_order_number();

            $wcOrderId = $order->get_id();

            $callbackUrl = $this->getRedirectUrl($wcOrderId);

            $sessionKey = $this->getOrderSessionKey($orderId);
            $razorpayOrderId = get_transient($sessionKey);

            $productinfo = "Order $orderId";

            return array(
                'key'          => $this->getSetting('key_id'),
                'name'         => get_bloginfo('name'),
                'currency'     => self::INR,
                'description'  => $productinfo,
                'notes'        => array(
                    self::WC_ORDER_ID => $orderId,
                    self::WC_ORDER_NUMBER => $wcOrderId
                ),
                'order_id'     => $razorpayOrderId,
                'callback_url' => $callbackUrl,
                'prefill'      => $this->getCustomerInfo($order)
            );
        }

        /**
         * @param  WC_Order $order
         * @return string currency
         */
        private function getOrderCurrency($order)
        {
            if (version_compare(WOOCOMMERCE_VERSION, '2.7.0', '>='))
            {
                return $order->get_currency();
            }

            return $order->get_order_currency();
        }

        /**
         * Returns array of checkout params
         */
        private function getCheckoutArguments($order, $params)
        {
            $args = $this->getDefaultCheckoutArguments($order);

            $currency = $this->getOrderCurrency($order);

            // The list of valid currencies is at https://razorpay.freshdesk.com/support/solutions/articles/11000065530-what-currencies-does-razorpay-support-

            $args = array_merge($args, $params);

            return $args;
        }

        public function getCustomerInfo($order)
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

        protected function createRazorpayOrderId($orderId, $sessionKey)
        {
            // Calls the helper function to create order data
            global $woocommerce;

            $api = $this->getRazorpayApiInstance();

            $data = $this->getOrderCreationData($orderId);

            try
            {
                $razorpayOrder = $api->order->create($data);
            }
            catch (Exception $e)
            {
                return $e;
            }

            $razorpayOrderId = $razorpayOrder['id'];

            set_transient($sessionKey, $razorpayOrderId, 3600);

            //update it in order comments
            $order = wc_get_order($orderId);

            $order->add_order_note("Razorpay OrderId: $razorpayOrderId");

            return $razorpayOrderId;
        }

        protected function verifyOrderAmount($razorpayOrderId, $orderId)
        {
            $order = wc_get_order($orderId);

            $api = $this->getRazorpayApiInstance();

            try
            {
                $razorpayOrder = $api->order->fetch($razorpayOrderId);
            }
            catch (Exception $e)
            {
                $message = $e->getMessage();
                return "RAZORPAY ERROR: Order fetch failed with the message '$message'";
            }

            $orderCreationData = $this->getOrderCreationData($orderId);

            $razorpayOrderArgs = array(
                'id'        => $razorpayOrderId,
                'amount'    => $orderCreationData['amount'],
                'currency'  => $orderCreationData['currency'],
                'receipt'   => (string) $orderId,
            );

            $orderKeys = array_keys($razorpayOrderArgs);

            foreach ($orderKeys as $key)
            {
                if ($razorpayOrderArgs[$key] !== $razorpayOrder[$key])
                {
                    return false;
                }
            }

            return true;
        }

        private function getOrderCreationData($orderId)
        {
            $order = wc_get_order($orderId);

            $data = array(
                'receipt'         => $orderId,
                'amount'          => (int) round($order->get_total() * 100),
                'currency'        => $this->getOrderCurrency($order),
                'payment_capture' => ($this->getSetting('payment_action') === self::AUTHORIZE) ? 0 : 1,
                'app_offer'       => ($order->get_discount_total() > 0) ? 1 : 0,
                'notes'           => array(
                    self::WC_ORDER_NUMBER  => (string) $orderId,
                ),
            );

            if($this->getSetting('route_enable') == 'yes')
            {
                $razorpayRoute = new RZP_Route_Action();
                $orderTransferArr = $razorpayRoute->getOrderTransferData($orderId);

                if(isset($orderTransferArr) && !empty($orderTransferArr)){

                    $transferData = array(
                        'transfers' => $orderTransferArr
                    );
                    $data = array_merge($data,$transferData);
                }
            }

            return $data;
        }

        public function enqueueCheckoutScripts($data)
        {
            if($data === 'checkoutForm' || $data === 'routeAnalyticsForm')
            {
                wp_register_script('razorpay_wc_script', plugin_dir_url(__FILE__)  . 'script.js',
                    null, null);
            }
            else
            {
                wp_register_script('razorpay_wc_script', plugin_dir_url(__FILE__)  . 'script.js',
                    array('razorpay_checkout'));

                wp_register_script('razorpay_checkout',
                    'https://checkout.razorpay.com/v1/checkout.js',
                    null, null);
            }

            wp_localize_script('razorpay_wc_script',
                'razorpay_wc_checkout_vars',
                $data
            );

            wp_enqueue_script('razorpay_wc_script');
        }

        private function hostCheckoutScripts($data)
        {
            $url = Api::getFullUrl("checkout/embedded");

            $formFields = "";
            foreach ($data as $fieldKey => $val) {
                if(in_array($fieldKey, array('notes', 'prefill', '_')))
                {
                    foreach ($data[$fieldKey] as $field => $fieldVal) {
                        $formFields .= "<input type='hidden' name='$fieldKey" ."[$field]"."' value='$fieldVal'> \n";
                    }
                }
            }

            return '<form method="POST" action="'.$url.'" id="checkoutForm">
                    <input type="hidden" name="key_id" value="'.$data['key'].'">
                    <input type="hidden" name="order_id" value="'.$data['order_id'].'">
                    <input type="hidden" name="name" value="'.$data['name'].'">
                    <input type="hidden" name="description" value="'.$data['description'].'">
                    <input type="hidden" name="image" value="'.$data['preference']['image'].'">
                    <input type="hidden" name="callback_url" value="'.$data['callback_url'].'">
                    <input type="hidden" name="cancel_url" value="'.$data['cancel_url'].'">
                    '. $formFields .'
                </form>';

        }


        /**
         * Generates the order form
         **/
        function generateOrderForm($data)
        {
            $data["_"] = $this->getVersionMetaInfo($data);

            $wooOrderId = $data['notes']['woocommerce_order_number'];

            $redirectUrl = $this->getRedirectUrl($wooOrderId);

            $data['cancel_url'] = wc_get_checkout_url();

            $api = new Api($this->getSetting('key_id'),"");

            $merchantPreferences = $api->request->request("GET", "preferences");

            if(isset($merchantPreferences['options']['redirect']) && $merchantPreferences['options']['redirect'] === true)
            {
                $this->enqueueCheckoutScripts('checkoutForm');

                $data['preference']['image'] = $merchantPreferences['options']['image'];

                return $this->hostCheckoutScripts($data);

            } else {
                $this->enqueueCheckoutScripts($data);

                return <<<EOT
<form name='razorpayform' action="$redirectUrl" method="POST">
    <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id">
    <input type="hidden" name="razorpay_signature"  id="razorpay_signature" >
    <!-- This distinguishes all our various wordpress plugins -->
    <input type="hidden" name="razorpay_wc_form_submit" value="1">
</form>
<p id="msg-razorpay-success" class="woocommerce-info woocommerce-message" style="display:none">
Please wait while we are processing your payment.
</p>
<p>
    <button id="btn-razorpay">Pay Now</button>
    <button id="btn-razorpay-cancel" onclick="document.razorpayform.submit()">Cancel</button>
</p>
EOT;
            }
        }

        /**
         * Gets the Order Key from the Order
         * for all WC versions that we suport
         */
        protected function getOrderKey($order)
        {
            $orderKey = null;

            if (version_compare(WOOCOMMERCE_VERSION, '3.0.0', '>='))
            {
                return $order->get_order_key();
            }

            return $order->order_key;
        }

        public function process_refund($orderId, $amount = null, $reason = '')
        {
            $order = wc_get_order($orderId);

            if (! $order or ! $order->get_transaction_id())
            {
                return new WP_Error('error', __('Refund failed: No transaction ID', 'woocommerce'));
            }

            $client = $this->getRazorpayApiInstance();

            $paymentId = $order->get_transaction_id();

            $data = array(
                'amount'    =>  (int) round($amount * 100),
                'notes'     =>  array(
                    'reason'                =>  $reason,
                    'order_id'              =>  $orderId,
                    'refund_from_website'   =>  true,
                    'source'                =>  'woocommerce',
                )
            );

            try
            {
                $refund = $client->payment
                    ->fetch( $paymentId )
                    ->refund( $data );

                $order->add_order_note( __( 'Refund Id: ' . $refund->id, 'woocommerce' ) );
                /**
                 * @var $refund ->id -- Provides the RazorPay Refund ID
                 * @var $orderId -> Refunded Order ID
                 * @var $refund -> WooCommerce Refund Instance.
                 */
                do_action( 'woo_razorpay_refund_success', $refund->id, $orderId, $refund );

                return true;
            }
            catch(Exception $e)
            {
                return new WP_Error('error', __($e->getMessage(), 'woocommerce'));
            }
        }

        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id)
        {
            global $woocommerce;

            $order = wc_get_order($order_id);

            $orderKey = $this->getOrderKey($order);

            if (version_compare(WOOCOMMERCE_VERSION, '2.1', '>='))
            {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('key', $orderKey, $order->get_checkout_payment_url(true))
                );
            }
            else if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
            {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('order', $order->get_id(),
                        add_query_arg('key', $orderKey, $order->get_checkout_payment_url(true)))
                );
            }
            else
            {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('order', $order->get_id(),
                        add_query_arg('key', $orderKey, get_permalink(get_option('woocommerce_pay_page_id'))))
                );
            }
        }

        public function getRazorpayApiInstance()
        {
            return new Api($this->getSetting('key_id'), $this->getSetting('key_secret'));
        }

        /**
         * Check for valid razorpay server callback
         **/
        function check_razorpay_response()
        {
            global $woocommerce;
            global $wpdb;

            $order = false;

            $post_type = 'shop_order';
            $post_password = sanitize_text_field($_GET['order_key']);

            $postIds = $wpdb->get_col( $wpdb->prepare("SELECT ID FROM $wpdb->posts AS P WHERE post_type=%s AND post_password = %s", $post_type, $post_password ) );

            if(count($postIds) > 0)
            {
                $orderId = $postIds[0];
                $order = wc_get_order($orderId);
            }

            if($order === false)
            {
                wp_redirect(wc_get_checkout_url());
                exit;
            }

            //
            // If the order has already been paid for
            // redirect user to success page
            //
            if ($order->needs_payment() === false)
            {
                $this->redirectUser($order);
            }

            $razorpayPaymentId = null;

            if ($orderId  and !empty($_POST[self::RAZORPAY_PAYMENT_ID]))
            {
                $error = "";
                $success = false;

                try
                {
                    $this->verifySignature($orderId);
                    $success = true;
                    $razorpayPaymentId = sanitize_text_field($_POST[self::RAZORPAY_PAYMENT_ID]);
                }
                catch (Errors\SignatureVerificationError $e)
                {
                    $error = 'WOOCOMMERCE_ERROR: Payment to Razorpay Failed. ' . $e->getMessage();
                }
            }
            else
            {
                if($_POST[self::RAZORPAY_WC_FORM_SUBMIT] ==1)
                {
                    $success = false;
                    $error = 'Customer cancelled the payment';
                }
                else
                {
                    $success = false;
                    $error = "Payment Failed.";
                }

                $this->handleErrorCase($order);
                $this->updateOrder($order, $success, $error, $razorpayPaymentId, null);

                wp_redirect(wc_get_checkout_url());
                exit;
            }

            $this->updateOrder($order, $success, $error, $razorpayPaymentId, null);

            $this->redirectUser($order);
        }

        protected function redirectUser($order)
        {
            $redirectUrl = $this->get_return_url($order);

            wp_redirect($redirectUrl);
            exit;
        }

        protected function verifySignature($orderId)
        {
            global $woocommerce;

            $api = $this->getRazorpayApiInstance();

            $attributes = array(
                self::RAZORPAY_PAYMENT_ID => $_POST[self::RAZORPAY_PAYMENT_ID],
                self::RAZORPAY_SIGNATURE  => $_POST[self::RAZORPAY_SIGNATURE],
            );

            $sessionKey = $this->getOrderSessionKey($orderId);
            $attributes[self::RAZORPAY_ORDER_ID] = get_transient($sessionKey);

            $api->utility->verifyPaymentSignature($attributes);
        }

        public function rzpThankYouMessage( $thank_you_title, $order )
        {
            return self::DEFAULT_SUCCESS_MESSAGE;
        }

        protected function getErrorMessage($orderId)
        {
            // We don't have a proper order id
            if ($orderId !== null)
            {
                $message = 'An error occured while processing this payment';
            }
            if (isset($_POST['error']) === true)
            {
                $error = $_POST['error'];

                $description = htmlentities($error['description']);
                $code = htmlentities($error['code']);

                $message = 'An error occured. Description : ' . $description . '. Code : ' . $code;

                if (isset($error['field']) === true)
                {
                    $fieldError = htmlentities($error['field']);
                    $message .= 'Field : ' . $fieldError;
                }
            }
            else
            {
                $message = 'An error occured. Please contact administrator for assistance';
            }

            return $message;
        }

        /**
         * Modifies existing order and handles success case
         *
         * @param $success, & $order
         */
        public function updateOrder(& $order, $success, $errorMessage, $razorpayPaymentId, $virtualAccountId = null, $webhook = false)
        {
            global $woocommerce;

            $orderId = $order->get_order_number();

            if (($success === true) and ($order->needs_payment() === true))
            {
                $order->payment_complete($razorpayPaymentId);
                $order->add_order_note("Razorpay payment successful <br/>Razorpay Id: $razorpayPaymentId");

                if($this->getSetting('route_enable') == 'yes')
                {
                    $razorpayRoute = new RZP_Route_Action();

                    $wcOrderId = $order->get_id();

                    $razorpayRoute->transferFromPayment($wcOrderId, $razorpayPaymentId); // creates transfers from payment
                }

                if($virtualAccountId != null)
                {
                    $order->add_order_note("Virtual Account Id: $virtualAccountId");
                }

                if (isset($woocommerce->cart) === true)
                {
                    $woocommerce->cart->empty_cart();
                }
            }
            else
            {
                $this->msg['class'] = 'error';
                $this->msg['message'] = $errorMessage;

                if ($razorpayPaymentId)
                {
                    $order->add_order_note("Payment Failed. Please check Razorpay Dashboard. <br/> Razorpay Id: $razorpayPaymentId");
                }

                $order->add_order_note("Transaction Failed: $errorMessage<br/>");
                $order->update_status('failed');
            }

            if ($webhook === false)
            {
                $this->add_notice($this->msg['message'], $this->msg['class']);
            }
        }

        protected function handleErrorCase(& $order)
        {
            $orderId = $order->get_order_number();

            $this->msg['class'] = 'error';
            $this->msg['message'] = $this->getErrorMessage($orderId);
        }

        /**
         * Add a woocommerce notification message
         *
         * @param string $message Notification message
         * @param string $type Notification type, default = notice
         */
        protected function add_notice($message, $type = 'notice')
        {
            global $woocommerce;
            $type = in_array($type, array('notice','error','success'), true) ? $type : 'notice';
            // Check for existence of new notification api. Else use previous add_error
            if (function_exists('wc_add_notice'))
            {
                wc_add_notice($message, $type);
            }
            else
            {
                // Retrocompatibility WooCommerce < 2.1
                switch ($type)
                {
                    case "error" :
                        $woocommerce->add_error($message);
                        break;
                    default :
                        $woocommerce->add_message($message);
                        break;
                }
            }
        }

        /**
         * Fetching version info for woo-razorpay and woo-razorpay-subscription
         * Which will be sent through checkout as meta info
         * @param $data
         * @return array
         */
        protected function getVersionMetaInfo($data)
        {
            if (isset($data['subscription_id']) && isset($data['recurring'])) {
                $pluginRoot = WP_PLUGIN_DIR . '/razorpay-subscriptions-for-woocommerce';
                return array(
                    'integration' => 'woocommerce-subscription',
                    'integration_version' => get_plugin_data($pluginRoot . '/razorpay-subscriptions.php')['Version'],
                    'integration_woo_razorpay_version' => get_plugin_data(plugin_dir_path(__FILE__) . 'woo-razorpay.php')['Version'],
                    'integration_parent_version' => WOOCOMMERCE_VERSION,
                );
            } else {
                return array(
                    'integration' => 'woocommerce',
                    'integration_version' => get_plugin_data(plugin_dir_path(__FILE__) . 'woo-razorpay.php')['Version'],
                    'integration_parent_version' => WOOCOMMERCE_VERSION,
                );
            }
        }

    }

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_razorpay_gateway($methods)
    {
        $methods[] = 'WC_Razorpay';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_razorpay_gateway' );

    /**
     * Creating the settings link from the plugins page
     **/
    function razorpay_woo_plugin_links($links)
    {
        $pluginLinks = array(
            'settings' => '<a href="'. esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=razorpay')) .'">Settings</a>',
            'docs'     => '<a href="https://razorpay.com/docs/payment-gateway/ecommerce-plugins/woocommerce/woocommerce-pg/">Docs</a>',
            'support'  => '<a href="https://razorpay.com/contact/">Support</a>'
        );

        $links = array_merge($links, $pluginLinks);

        return $links;
    }

    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'razorpay_woo_plugin_links');
}

// This is set to a priority of 10
function razorpay_webhook_init()
{
    $rzpWebhook = new RZP_Webhook();

    $rzpWebhook->process();
}
