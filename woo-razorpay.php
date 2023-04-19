<?php
/*
 * Plugin Name: Razorpay for WooCommerce
 * Plugin URI: https://razorpay.com
 * Description: Razorpay Payment Gateway Integration for WooCommerce
 * Version: 4.5.1
 * Stable tag: 4.5.1
 * Author: Team Razorpay
 * WC tested up to: 6.7.0
 * Author URI: https://razorpay.com
*/

if ( ! defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly
}

define('PLUGIN_MAIN_FILE', __FILE__);

require_once __DIR__.'/includes/razorpay-webhook.php';
require_once __DIR__.'/razorpay-sdk/Razorpay.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once __DIR__.'/includes/razorpay-route.php';
require_once __DIR__ .'/includes/razorpay-route-actions.php';
require_once __DIR__.'/includes/api/api.php';
require_once __DIR__.'/includes/utils.php';
require_once __DIR__.'/includes/state-map.php';
require_once __DIR__.'/includes/plugin-instrumentation.php';
require_once __DIR__.'/includes/support/cartbounty.php';
require_once __DIR__.'/includes/support/wati.php';
require_once __DIR__.'/includes/razorpay-affordability-widget.php';
require_once __DIR__.'/includes/cron/one-click-checkout/Constants.php';
require_once __DIR__.'/includes/cron/one-click-checkout/one-cc-address-sync.php';
require_once __DIR__.'/includes/cron/cron.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

add_action('plugins_loaded', 'woocommerce_razorpay_init', 0);
add_action('admin_post_nopriv_rzp_wc_webhook', 'razorpay_webhook_init', 10);

function woocommerce_razorpay_init()
{
    if (!class_exists('WC_Payment_Gateway') || class_exists('WC_Razorpay'))
    {
        return;
    }

    class WC_Razorpay extends WC_Payment_Gateway
    {
        // This one stores the WooCommerce Order Id
        const SESSION_KEY                    = 'razorpay_wc_order_id';
        const RAZORPAY_PAYMENT_ID            = 'razorpay_payment_id';
        const RAZORPAY_ORDER_ID              = 'razorpay_order_id';
        const RAZORPAY_ORDER_ID_1CC          = 'razorpay_order_id_1cc';
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

        protected $supportedWebhookEvents = array(
            'payment.authorized',
            'payment.pending',
            'refund.created',
            'virtual_account.credited',
            'subscription.cancelled',
            'subscription.paused',
            'subscription.resumed',
            'subscription.charged'
        );

        protected $defaultWebhookEvents = array(
            'payment.authorized' => true,
            'refund.created' => true
        );

        protected $visibleSettings = array(
            'enabled',
            'title',
            'description',
            'key_id',
            'key_secret',
            'payment_action',
            'order_success_message',
            'route_enable',
            'enable_1cc_debug_mode',
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

        public function getCustomOrdercreationMessage($thank_you_title, $order)
        {
            $message =  $this->getSetting('order_success_message');
            if (isset($message) === false)
            {
                $message = static::DEFAULT_SUCCESS_MESSAGE;
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
            // 1cc flags should be enabled only if merchant has access to 1cc feature
            $is1ccAvailable = false;
            $isAccCreationAvailable = false;

            // Load preference API call only for administrative interface page.
            if (current_user_can('administrator'))
            {
                if (!empty($this->getSetting('key_id')) && !empty($this->getSetting('key_secret')))
                {
                    try {

                      $api = $this->getRazorpayApiInstance();
                      $merchantPreferences = $api->request->request('GET', 'merchant/1cc_preferences');

                      if (!empty($merchantPreferences['features']['one_click_checkout'])) {
                        $is1ccAvailable = true;
                      }

                      if (!empty($merchantPreferences['features']['one_cc_store_account'])) {
                        $isAccCreationAvailable = true;
                      }

                    } catch (\Exception $e) {
                      rzpLogError($e->getMessage());
                    }

                }
            }

            if ($is1ccAvailable) {
              $this->visibleSettings = array_merge($this->visibleSettings, array(
                'enable_1cc',
                'enable_1cc_mandatory_login',
                'enable_1cc_test_mode',
                'enable_1cc_pdp_checkout',
                'enable_1cc_mini_cart_checkout',
                'enable_1cc_ga_analytics',
                'enable_1cc_fb_analytics',
                '1cc_min_cart_amount',
                '1cc_min_COD_slab_amount',
                '1cc_max_COD_slab_amount',
              ));

              if ($isAccCreationAvailable) {
                $this->visibleSettings = array_merge($this->visibleSettings, array(
                    '1cc_account_creation',
                ));
              }

            }

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
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

            add_action('woocommerce_api_' . $this->id, array($this, 'check_razorpay_response'));

            $cb = array($this, 'process_admin_options');

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
            {
                add_action( "woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'pluginInstrumentation'));
                add_action("woocommerce_update_options_payment_gateways_{$this->id}", $cb);
                add_action( "woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'autoEnableWebhook'));
                add_action( "woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'addAdminCheckoutSettingsAlert'));
                add_action( "woocommerce_update_options_payment_gateways_{$this->id}",  'createOneCCAddressSyncCron');
            }
            else
            {
                add_action( "woocommerce_update_options_payment_gateways", array($this, 'pluginInstrumentation'));
                add_action('woocommerce_update_options_payment_gateways', $cb);
                add_action( "woocommerce_update_options_payment_gateways", array($this, 'autoEnableWebhook'));
                add_action( "woocommerce_update_options_payment_gateways", array($this, 'addAdminCheckoutSettingsAlert'));
                add_action( "woocommerce_update_options_payment_gateways", 'createOneCCAddressSyncCron');
            }

            add_filter( 'woocommerce_thankyou_order_received_text', array($this, 'getCustomOrdercreationMessage'), 20, 2 );
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
                'enable_1cc_debug_mode' => array( //Added this config for both native and 1cc merchants
                    'title'       => __('Activate debug mode'),
                    'type'        => 'checkbox',
                    'description' => 'When debug mode is active, API logs and errors are collected and stored in your Woocommerce dashboard. It is recommended to keep this activated.',
                    'label'       => __('Enable debug mode'),
                    'default'     => 'yes',
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

            //Affordability Widget Code
            if (is_admin())
            {
                try
                {
                    if (isset($_POST['woocommerce_razorpay_key_id']) and
                        empty($_POST['woocommerce_razorpay_key_id']) === false and
                        isset($_POST['woocommerce_razorpay_key_secret']) and
                        empty($_POST['woocommerce_razorpay_key_secret']) === false)
                    {
                        $api = new Api($_POST['woocommerce_razorpay_key_id'], $_POST['woocommerce_razorpay_key_secret']);
                    }
                    else
                    {
                        $api = $this->getRazorpayApiInstance();
                    }

                    $merchantPreferences = $api->request->request('GET', 'accounts/me/features');
                    if (isset($merchantPreferences) === false or
                        isset($merchantPreferences['assigned_features']) === false)
                    {
                        throw new Exception("Error in Api call.");
                    }

                    update_option('rzp_afd_enable', 'no');
                    foreach ($merchantPreferences['assigned_features'] as $preference)
                    {
                        if ($preference['name'] === 'affordability_widget' or
                            $preference['name'] === 'affordability_widget_set')
                        {
                            add_action('woocommerce_sections_checkout', 'addSubSection');
                            add_action('woocommerce_settings_tabs_checkout', 'displayAffordabilityWidgetSettings');
                            add_action('woocommerce_update_options_checkout', 'updateAffordabilityWidgetSettings');
                            update_option('rzp_afd_enable', 'yes');
                            break;
                        }
                    }

                    update_option('rzp_afd_feature_checked', 'yes');
                }
                catch (\Exception $e)
                {
                    rzpLogError($e->getMessage());
                    return;
                }
            }
        }

        protected function getWebhookUrl()
        {
            return esc_url(admin_url('admin-post.php')) . '?action=rzp_wc_webhook';
        }

        protected function triggerValidationInstrumentation($data)
        {
            $properties = [
                'page_url'            => $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
                'field_type'          => 'text',
                'field_name'          => 'key_id or key_secret',
                'is_plugin_activated' => ($this->getSetting('enabled') === 'yes') ? true : false
            ];

            $properties = array_merge($properties, $data);

            $trackObject = $this->newTrackPluginInstrumentation($this->getSetting('key_id'), '');
            $response = $trackObject->rzpTrackSegment('formfield.validation.error', $properties);
            $trackObject->rzpTrackDataLake('formfield.validation.error', $properties);
        }

        public function autoEnableWebhook()
        {
            $webhookExist = false;
            $webhookUrl   = $this->getWebhookUrl();

            $key_id      = $this->getSetting('key_id');
            $key_secret  = $this->getSetting('key_secret');
            $enabled     = true;
            $secret = empty($this->getSetting('webhook_secret')) ? $this->generateSecret() : $this->getSetting('webhook_secret');

            update_option('webhook_secret', $secret);
            $getWebhookFlag =  get_option('webhook_enable_flag');
            $time = time();

            if (empty($getWebhookFlag))
            {
                add_option('webhook_enable_flag', $time);
            }
            else
            {
                update_option('webhook_enable_flag', $time);
            }
            //validating the key id and key secret set properly or not.
            if($key_id == null || $key_secret == null)
            {
                $validationErrorProperties = $this->triggerValidationInstrumentation(
                    ['error_message' => 'Key Id and or Key Secret is null']);
                ?>
                <div class="notice error is-dismissible" >
                    <p><b><?php _e( 'Key Id and Key Secret are required.'); ?><b></p>
                </div>
                <?php

                rzpLogError('Key Id and Key Secret are required.');
                return;
            }

            try
            {
                $api = $this->getRazorpayApiInstance();
                $validateKeySecret = $api->request->request("GET", "orders");
            }
            catch (Exception $e)
            {
                $validationErrorProperties = $this->triggerValidationInstrumentation(
                    ['error_message' => 'Invalid Key Id and Key Secret']);
                ?>
                <div class="notice error is-dismissible" >
                    <p><b><?php _e( 'Please check Key Id and Key Secret.'); ?></b></p>
                </div>
                <?php

                $log = array(
                    'message' => $e->getMessage(),
                );
                rzpLogError(json_encode($log));

                return;
            }

            $domain = parse_url($webhookUrl, PHP_URL_HOST);

            $domain_ip = gethostbyname($domain);

            if (!filter_var($domain_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
            {
                ?>
                <div class="notice error is-dismissible" >
                    <p><b><?php _e( 'Could not enable webhook for localhost server.'); ?><b></p>
                </div>
                <?php

                rzpLogError('Could not enable webhook for localhost');
                return;
            }
            $skip = 0;
            $count = 10;
            $webhookItems= [];

            do {
                $webhook = $this->webhookAPI("GET", "webhooks?count=".$count."&skip=".$skip);
                $skip += 10;
                if ($webhook['count'] > 0)
                {
                    foreach ($webhook['items'] as $key => $value)
                    {
                        $webhookItems[] = $value;
                    }
                }
            } while ( $webhook['count'] === $count);

            $subscriptionWebhookFlag =  get_option('rzp_subscription_webhook_enable_flag');

            if ($subscriptionWebhookFlag)
            {
                $this->defaultWebhookEvents += array(
                    'subscription.cancelled' => true,
                    'subscription.resumed'   => true,
                    'subscription.paused'    => true,
                    'subscription.charged'   => true
                );
            }

            $data = [
                'url'    => $webhookUrl,
                'active' => $enabled,
                'events' => $this->defaultWebhookEvents,
                'secret' => $secret,
            ];

            if (count($webhookItems) > 0)
            {
                foreach ($webhookItems as $key => $value)
                {
                    if ($value['url'] === $webhookUrl)
                    {
                        foreach ($value['events'] as $evntkey => $evntval)
                        {
                            if (($evntval == 1) and
                                (in_array($evntkey, $this->supportedWebhookEvents) === true))
                            {
                                $this->defaultWebhookEvents[$evntkey] =  true;
                            }
                        }

                        if (!$subscriptionWebhookFlag)
                        {
                            unset($this->defaultWebhookEvents['subscription.cancelled']);
                            unset($this->defaultWebhookEvents['subscription.resumed']);
                            unset($this->defaultWebhookEvents['subscription.paused']);
                            unset($this->defaultWebhookEvents['subscription.charged']);
                        }

                        $data = [
                            'url'    => $webhookUrl,
                            'active' => $enabled,
                            'events' => $this->defaultWebhookEvents,
                            'secret' => $secret,
                        ];
                        $webhookExist  = true;
                        $webhookId     = $value['id'];
                    }
                }
            }

            $webhookProperties = [
                'page_url'       => $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
                'prev_page_url'  => $_SERVER['HTTP_REFERER'],
                'is_plugin_activated' => ($this->getSetting('enabled') === 'yes') ? true : false,
                'events_selected'     => $this->defaultWebhookEvents,
            ];

            if ($webhookExist)
            {
                $trackObject = $this->newTrackPluginInstrumentation($this->getSetting('key_id'), '');
                $response = $trackObject->rzpTrackSegment('autowebhook.updated', $webhookProperties);
                $trackObject->rzpTrackDataLake('autowebhook.updated', $webhookProperties);

                rzpLogInfo('Updating razorpay webhook');
                return $this->webhookAPI('PUT', "webhooks/" . $webhookId, $data);
            }
            else
            {
                $trackObject = $this->newTrackPluginInstrumentation($this->getSetting('key_id'), '');
                $response = $trackObject->rzpTrackSegment('autowebhook.created', $webhookProperties);
                $trackObject->rzpTrackDataLake('autowebhook.created', $webhookProperties);

                rzpLogInfo('Creating razorpay webhook');
                return $this->webhookAPI('POST', "webhooks/", $data);
            }
        }

        public function newTrackPluginInstrumentation($key, $secret)
        {
            $api = $this->getRazorpayApiInstance($key, $secret);

            return new TrackPluginInstrumentation($api, $key);
        }

        public function pluginInstrumentation()
        {
            if (empty($_POST['woocommerce_razorpay_key_id']) or
                empty($_POST['woocommerce_razorpay_key_secret']))
            {
                error_log('Key Id and Key Secret are required.');
                return;
            }

            $trackObject = $this->newTrackPluginInstrumentation($_POST['woocommerce_razorpay_key_id'], $_POST['woocommerce_razorpay_key_secret']);

            $existingVersion = get_option('rzp_woocommerce_current_version');

            if(isset($existingVersion))
            {
                update_option('rzp_woocommerce_current_version', get_plugin_data(__FILE__)['Version']);
            }
            else
            {
                add_option('rzp_woocommerce_current_version', get_plugin_data(__FILE__)['Version']);
            }

            try
            {
                global $wpdb;
                $isTransactingUser = false;

                $rzpTrancationData = $wpdb->get_row($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta AS P WHERE meta_key = %s AND meta_value = %s", "_payment_method", "razorpay"));

                $arrayPost = json_decode(json_encode($rzpTrancationData), true);

                if (empty($arrayPost) === false and
                    ($arrayPost == null) === false)
                {
                    $isTransactingUser = true;
                }
            }
            catch (\Razorpay\Api\Errors\Error $e)
            {
                ?>
                <div class="notice error is-dismissible" >
                    <p><b><?php _e( 'Please check Key Id and Key Secret.'); ?></b></p>
                </div>
                <?php
                error_log('Please check Key Id and Key Secret.');
                return;
            }
            catch (\Exception $e)
            {
                ?>
                <div class="notice error is-dismissible" >
                    <p><b><?php _e( 'something went wrong'); ?></b></p>
                </div>
                <?php
                error_log($e->getMessage());
                return;
            }

            $authEvent = '';
            $pluginStatusEvent = '';

            $authProperties = [
                'is_key_id_populated'     => true,
                'is_key_secret_populated' => true,
                'page_url'                => $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'],
                'auth_successful_status'  => true,
                'is_plugin_activated'     => (isset($_POST['woocommerce_razorpay_enabled'])) ? true :false
            ];

            // for enable and disable plugin
            $pluginStatusProperties = [
                'current_status'      => ($this->getSetting('enabled')==='yes') ? 'enabled' :'disabled',
                'is_transacting_user' => $isTransactingUser
            ];

            if (empty($this->getSetting('key_id')) and
                empty($this->getSetting('key_secret')))
            {
                $authEvent = 'saving auth details';

                if(empty($_POST['woocommerce_razorpay_enabled']) === false)
                {
                    $pluginStatusEvent = 'plugin enabled';
                }
            }
            else
            {
                $authEvent = 'updating auth details';
            }

            $response = $trackObject->rzpTrackSegment($authEvent, $authProperties);

            $trackObject->rzpTrackDataLake($authEvent, $authProperties);

            if ((empty($_POST['woocommerce_razorpay_enabled']) === false) and
                ($this->getSetting('enabled') === 'no'))
            {
                $pluginStatusEvent = 'plugin enabled';
            }
            elseif ((empty($_POST['woocommerce_razorpay_enabled']) === true) and
                ($this->getSetting('enabled') === 'yes'))
            {
                $pluginStatusEvent = 'plugin disabled';
            }

            if ($pluginStatusEvent !== '')
            {
                $response = $trackObject->rzpTrackSegment($pluginStatusEvent, $pluginStatusProperties);

                $trackObject->rzpTrackDataLake($pluginStatusEvent, $pluginStatusProperties);
            }
        }

        public function generateSecret()
        {
            $alphanumericString = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-=~!@#$%^&*()_+,./<>?;:[]{}|abcdefghijklmnopqrstuvwxyz';
            $secret = substr(str_shuffle($alphanumericString), 0, 20);

            return $secret;
        }

        // showing notice : status of 1cc active / inactive message in admin dashboard
        function addAdminCheckoutSettingsAlert() {
            $enable_1cc  = $this->getSetting('enable_1cc');
            if($enable_1cc == 'no')
            {
                ?>
                    <div class="notice error is-dismissible" >
                        <p><b><?php _e( 'We are sorry to see you opt out of Magic Checkout experience. Please help us understand what went wrong by filling up this form.'); ?></b></p>
                    </div>
                <?php
                error_log('1cc is disabled.');
                return;
            }
            elseif ($enable_1cc == 'yes')
            {
                ?>
                    <div class="notice notice-success is-dismissible" >
                        <p><b><?php _e( 'You are Live with Magic Checkout.'); ?></b></p>
                    </div>
                <?php
                return;
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
                rzpLogError(json_encode($log));
            }

            return $webhook;
        }

        public function admin_options()
        {
            echo '<h3>'.__('Razorpay Payment Gateway', $this->id) . '</h3>';
            echo '<p>'.__('Allows payments by Credit/Debit Cards, NetBanking, UPI, and multiple Wallets') . '</p>';
            echo '<p>'.__('First <a href="https://easy.razorpay.com/onboarding?recommended_product=payment_gateway&source=woocommerce" target="_blank" onclick="rzpSignupClicked(event)">signup</a> for a Razorpay account or
            <a href="https://dashboard.razorpay.com/signin?screen=sign_in&source=woocommerce" target="_blank" onclick="rzpLoginClicked(event)">login</a> if you have an existing account.'). '</p>';
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
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $value)
            {
                if ($value['function'] === 'output' and
                    stripos($value['file'], 'themes/divi') !== false and
                    basename($value['file'], ".php") !== 'CheckoutPaymentInfo')
                {
                    return;
                }
            }

            echo $this->generate_razorpay_form($orderId);
        }

        /**
         * Returns key to use in session for storing Razorpay order Id
         * @param  string $orderId Razorpay Order Id
         * @return string Session Key
         */
        protected function getOrderSessionKey($orderId)
        {
            $is1ccOrder = get_post_meta( $orderId, 'is_magic_checkout_order', true );

            if($is1ccOrder == 'yes')
            {
                return self::RAZORPAY_ORDER_ID_1CC . $orderId;
            }
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
        public function createOrGetRazorpayOrderId($orderId, $is1ccCheckout = 'no')
        {
            global $woocommerce;
            rzpLogInfo("createOrGetRazorpayOrderId $orderId and is1ccCheckout is set to $is1ccCheckout");

            $create = false;

            if($is1ccCheckout == 'no')
            {
                update_post_meta( $orderId, 'is_magic_checkout_order', 'no' );

                rzpLogInfo("Called createOrGetRazorpayOrderId with params orderId $orderId and is_magic_checkout_order is set to no");
            }

            $sessionKey = $this->getOrderSessionKey($orderId);

            try
            {
                $razorpayOrderId = get_transient($sessionKey);
                rzpLogInfo("razorpayOrderId $razorpayOrderId | sessionKey $sessionKey");
                // If we don't have an Order
                // or the if the order is present in transient but doesn't match what we have saved
                if (($razorpayOrderId === false) or
                    (($razorpayOrderId and ($this->verifyOrderAmount($razorpayOrderId, $orderId, $is1ccCheckout)) === false)))
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

            return add_query_arg($query, trailingslashit(get_home_url()));
        }

        /**
         * Specific payment parameters to be passed to checkout
         * for payment processing
         * @param  string $orderId WC Order Id
         * @return array payment params
         */
        protected function getRazorpayPaymentParams($orderId)
        {
            $getWebhookFlag =  get_option('webhook_enable_flag');
            $time = time();
            if (empty($getWebhookFlag) == false)
            {
                if ($getWebhookFlag + 86400 < time())
                {
                    $this->autoEnableWebhook();
                }
            }
            else
            {
                update_option('webhook_enable_flag', $time);
                $this->autoEnableWebhook();
            }

            rzpLogInfo("getRazorpayPaymentParams $orderId");
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
        public function getDefaultCheckoutArguments($order)
        {
            global $woocommerce;

            $orderId = $order->get_order_number();

            $wcOrderId = $order->get_id();

            $callbackUrl = $this->getRedirectUrl($wcOrderId);

            $sessionKey = $this->getOrderSessionKey($wcOrderId);

            $razorpayOrderId = get_transient($sessionKey);

            $productinfo = "Order $orderId";

            return array(
                'key'          => $this->getSetting('key_id'),
                'name'         => html_entity_decode(get_bloginfo('name'), ENT_QUOTES),
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
            rzpLogInfo("Called createRazorpayOrderId with params orderId $orderId and sessionKey $sessionKey");


            // Calls the helper function to create order data
            global $woocommerce;

            $api = $this->getRazorpayApiInstance();

            $data = $this->getOrderCreationData($orderId);
            rzpLogInfo('For order ' . $orderId);
            rzpLogInfo(json_encode($data));
            try
            {
                $razorpayOrder = $api->order->create($data);
            }
            catch (Exception $e)
            {
                return $e;
            }

            $getWebhookFlag =  get_option('webhook_enable_flag');
            $time = time();

            if (empty($getWebhookFlag) == false)
            {
                    if ($getWebhookFlag + 43200 < time())
                    {
                        $this->autoEnableWebhook();
                    }
            }
            else
            {
                    update_option('webhook_enable_flag', $time);
                    $this->autoEnableWebhook();
            }

            $razorpayOrderId = $razorpayOrder['id'];

            // Storing the razorpay order id in transient for 5 hours time.
            set_transient($sessionKey, $razorpayOrderId, 18000);

            // By default woocommerce session TTL is 48 hours.
            $woocommerce->session->set($sessionKey, $razorpayOrderId);

            rzpLogInfo('For order session key ' . $sessionKey);
            //update it in order comments
            $order = wc_get_order($orderId);

            $order->add_order_note("Razorpay OrderId: $razorpayOrderId");

            return $razorpayOrderId;
        }

        protected function verifyOrderAmount($razorpayOrderId, $orderId, $is1ccCheckout = 'no')
        {
            rzpLogInfo("Called verifyOrderAmount with params orderId $orderId and rzporderId $razorpayOrderId");
            $order = wc_get_order($orderId);

            $api = $this->getRazorpayApiInstance();

            try
            {
                $razorpayOrder = $api->order->fetch($razorpayOrderId);
            }
            catch (Exception $e)
            {
                $message = $e->getMessage();
                rzpLogInfo("Failed at verifyOrderAmount with $message");
                return "RAZORPAY ERROR: Order fetch failed with the message '$message'";
            }

            $orderCreationData = $this->getOrderCreationData($orderId);

            $razorpayOrderArgs = array(
                'id'        => $razorpayOrderId,
                'currency'  => $orderCreationData['currency'],
                'receipt'   => (string) $orderId,
            );

            if($is1ccCheckout == 'no'){
                $razorpayOrderArgs['amount'] = $orderCreationData['amount'];
            }else{
               $razorpayOrderArgs['line_items_total'] = $orderCreationData['amount'];
            }

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
            rzpLogInfo("Called getOrderCreationData with params orderId $orderId");
            $order = wc_get_order($orderId);

            $is1ccOrder = get_post_meta( $orderId, 'is_magic_checkout_order', true );

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

            if ($this->getSetting('route_enable') == 'yes')
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

            rzpLogInfo("Called getOrderCreationData with params orderId $orderId and is1ccOrder is set to $is1ccOrder");

            if (is1ccEnabled() && !empty($is1ccOrder) && $is1ccOrder == 'yes')
            {
                $data = $this->orderArg1CC($data, $order);
                rzpLogInfo("Called getOrderCreationData with params orderId $orderId and adding line_items_total");
            }

            return $data;
        }

        public function orderArg1CC($data, $order)
        {
            // TODO: trim to 2 deciamls
            $data['line_items_total'] = $order->get_total()*100;

            $i = 0;
            // Get and Loop Over Order Items
            $type = "e-commerce";
            foreach ( $order->get_items() as $item_id => $item )
            {
               $product = $item->get_product();

               $productDetails = $product->get_data();

               // check product type for gift card plugin
               if(is_plugin_active('pw-woocommerce-gift-cards/pw-gift-cards.php') || is_plugin_active('yith-woocommerce-gift-cards/init.php')){
                   if($product->is_type('variation')){
                        $parentProductId = $product->get_parent_id();
                        $parentProduct = wc_get_product($parentProductId);

                        if($parentProduct->get_type() == 'pw-gift-card' || $parentProduct->get_type() == 'gift-card'){
                            $type = 'gift_card';
                        }

                   }else{

                       if($product->get_type() == 'pw-gift-card' || $product->get_type() == 'gift-card'){
                              $type = 'gift_card';
                       }
                   }

               }

               $data['line_items'][$i]['type'] =  $type;
               $data['line_items'][$i]['sku'] = $product->get_sku();
               $data['line_items'][$i]['variant_id'] = $item->get_variation_id();
               $data['line_items'][$i]['price'] = (empty($productDetails['price'])=== false) ? round(wc_get_price_excluding_tax($product)*100) + round($item->get_subtotal_tax()*100 / $item->get_quantity()) : 0;
               $data['line_items'][$i]['offer_price'] = (empty($productDetails['sale_price'])=== false) ? (int) $productDetails['sale_price']*100 : $productDetails['price']*100;
               $data['line_items'][$i]['quantity'] = (int)$item->get_quantity();
               $data['line_items'][$i]['name'] = mb_substr($item->get_name(), 0, 125, "UTF-8");
               $data['line_items'][$i]['description'] = mb_substr($item->get_name(), 0, 250,"UTF-8");
               $productImage = $product->get_image_id()?? null;
               $data['line_items'][$i]['image_url'] = $productImage? wp_get_attachment_url( $productImage ) : null;
               $data['line_items'][$i]['product_url'] = $product->get_permalink();

               $i++;
            }

            return $data;
        }

        public function enqueueCheckoutScripts($data)
        {
            if($data === 'checkoutForm' || $data === 'routeAnalyticsForm')
            {
                wp_register_script('razorpay_wc_script', plugin_dir_url(__FILE__)  . 'script.js',
                    null, null);
                $data = array($data);
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

            $api = $this->getRazorpayApiPublicInstance();

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
<p id="msg-razorpay-success" class="woocommerce-info" style="display:none;background-color:rgba(176,176,176,.6);padding:1rem 1.5rem 1rem 3.5rem;border-top-style:solid;border-top-width:2px;">
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

        // process refund for gift card
        function processGiftCardRefund($orderId, $razorpayPaymentId, $amount = null, $reason = '')
        {
            $order = wc_get_order($orderId);

            if (! $order or ! $razorpayPaymentId)
            {
                return new WP_Error('error', __('Refund failed: No transaction ID', 'woocommerce'));
            }

            $client = $this->getRazorpayApiInstance();

            $data = array(
                'amount'    =>  (int) round($amount),
                'notes'     =>  array(
                    'reason'                =>  $reason,
                    'order_id'              =>  $orderId,
                    'refund_from_website'   =>  true,
                    'source'                =>  'woocommerce',
                )
            );

            try
            {
                $refund = $client->payment->fetch( $razorpayPaymentId )->refund( $data );

                wc_create_refund(array(
                    'amount'         => $amount,
                    'reason'         => $reason,
                    'order_id'       => $orderId,
                    'refund_id'      => $refund->id,
                    'line_items'     => array(),
                    'refund_payment' => false,
                ));

                $order->add_order_note( __( 'Refund Id: ' . $refund->id, 'woocommerce' ) );
                /**
                 * @var $refund ->id -- Provides the RazorPay Refund ID
                 * @var $orderId -> Refunded Order ID
                 * @var $refund -> WooCommerce Refund Instance.
                 */
                //do_action( 'woo_razorpay_refund_success', $refund->id, $orderId, $refund );
                $this->add_notice("Payment refunded", "error");

            }
            catch(Exception $e)
            {
                rzpLogInfo('failure message for refund:' . $e->getMessage());
            }

            wp_redirect(wc_get_cart_url());
            exit;
        }

        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id)
        {
            rzpLogInfo("Called process_payment with params order_id $order_id");

            global $woocommerce;

            $order = wc_get_order($order_id);

            set_transient(self::SESSION_KEY, $order_id, 3600);
            rzpLogInfo("Set transient with key " . self::SESSION_KEY . " params order_id $order_id");

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

        public function getRazorpayApiInstance($key = '', $secret = '')
        {
            if ($key === '')
            {
                $key = $this->getSetting('key_id');
            }

            if ($secret === '')
            {
                $secret = $this->getSetting('key_secret');
            }

            return new Api($key, $secret);
        }

        public function getRazorpayApiPublicInstance()
        {
            return new Api($this->getSetting('key_id'), "");
        }

        /**
         * Check for valid razorpay server callback
         * Called once payment is completed using redirect method
         */
        function check_razorpay_response()
        {
            global $woocommerce;
            global $wpdb;

            $order = false;

            $post_type = 'shop_order';

            $post_password = sanitize_text_field($_GET['order_key']);

            rzpLogInfo("Called check_razorpay_response: $post_password");

            $meta_key = '_order_key';

            $postMetaData = $wpdb->get_row( $wpdb->prepare("SELECT post_id FROM $wpdb->postmeta AS P WHERE meta_key = %s AND meta_value = %s", $meta_key, $post_password ) );

            $postData = $wpdb->get_row( $wpdb->prepare("SELECT post_status FROM $wpdb->posts AS P WHERE post_type=%s and ID=%s", $post_type, $postMetaData->post_id) );

            $arrayPost = json_decode(json_encode($postMetaData), true);

            if (!empty($arrayPost) and
                $arrayPost != null)
            {
                $orderId = $postMetaData->post_id;

                if ($postData->post_status === 'draft')
                {
                    updateOrderStatus($orderId, 'wc-pending');
                }

                $order = wc_get_order($orderId);
                rzpLogInfo("Get order id in check_razorpay_response: orderId $orderId");
            }

            // TODO: Handle redirect
            if ($order === false)
            {
                // TODO: Add test mode condition
                if (is1ccEnabled())
                {
                    rzpLogInfo("Order details not found for the orderId: $orderId");

                    wp_redirect(wc_get_cart_url());
                    exit;
                }
                wp_redirect(wc_get_checkout_url());
                exit;
            }

            // If the order has already been paid for
            // redirect user to success page
            if ($order->needs_payment() === false)
            {
                rzpLogInfo("Order payment is already done for the orderId: $orderId");

                $cartHash = get_transient(RZP_1CC_CART_HASH.$orderId);

                if ($cartHash != false)
                {
                  // Need to delete the cart hash stored in transient.
                  // Becuase the cart hash will be depending on the cart items so this will cause the issue when order api triggers.
                  $woocommerce->session->__unset(RZP_1CC_CART_HASH.$cartHash);
                }

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

                    $cartHash = get_transient(RZP_1CC_CART_HASH.$orderId);

                    if ($cartHash != false)
                    {
                      // Need to delete the cart hash stored in transient.
                      // Becuase the cart hash will be depending on the cart items so this will cause the issue when order api triggers.
                      $woocommerce->session->__unset(RZP_1CC_CART_HASH.$cartHash);
                    }
                }
                catch (Errors\SignatureVerificationError $e)
                {
                    $error = 'WOOCOMMERCE_ERROR: Payment to Razorpay Failed. ' . $e->getMessage();
                }
            }
            else
            {
                if(isset($_POST[self::RAZORPAY_WC_FORM_SUBMIT]) && $_POST[self::RAZORPAY_WC_FORM_SUBMIT] ==1)
                {
                    $success = false;
                    $error = 'Customer cancelled the payment';
                }
                else
                {
                    $success = false;
                    $error = "Payment Failed.";
                }

                $is1ccOrder = get_post_meta( $orderId, 'is_magic_checkout_order', true );

                if (is1ccEnabled() && !empty($is1ccOrder) && $is1ccOrder == 'yes')
                {
                    $api = $this->getRazorpayApiInstance();
                    $sessionKey = $this->getOrderSessionKey($orderId);

                    //Check the transient data for razorpay order id, if it's not available then look into session data.
                    if(get_transient($sessionKey))
                    {
                        $razorpayOrderId = get_transient($sessionKey);
                    }
                    else
                    {
                        $razorpayOrderId = $woocommerce->session->get($sessionKey);
                    }

                    $razorpayData = $api->order->fetch($razorpayOrderId);

                    $this->UpdateOrderAddress($razorpayData, $order);
                }

                $this->handleErrorCase($order);
                $this->updateOrder($order, $success, $error, $razorpayPaymentId, null);

                if (is1ccEnabled())
                {
                    wp_redirect(wc_get_cart_url());
                    exit;
                }

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
            rzpLogInfo("verifySignature orderId: $orderId");

            global $woocommerce;

            $api = $this->getRazorpayApiInstance();

            $attributes = array(
                self::RAZORPAY_PAYMENT_ID => $_POST[self::RAZORPAY_PAYMENT_ID],
                self::RAZORPAY_SIGNATURE  => $_POST[self::RAZORPAY_SIGNATURE],
            );

            $sessionKey = $this->getOrderSessionKey($orderId);
            //Check the transient data for razorpay order id, if it's not available then look into session data.
            if(get_transient($sessionKey))
            {
                $razorpayOrderId = get_transient($sessionKey);
            }
            else
            {
                $razorpayOrderId = $woocommerce->session->get($sessionKey);
            }

            $attributes[self::RAZORPAY_ORDER_ID] = $razorpayOrderId?? '';
            rzpLogInfo("verifySignature attr");
            rzpLogInfo(json_encode($attributes));
            $api->utility->verifyPaymentSignature($attributes);
        }

        public function rzpThankYouMessage( $thank_you_title, $order )
        {
            return self::DEFAULT_SUCCESS_MESSAGE;
        }

        protected function getErrorMessage($orderId)
        {
            // We don't have a proper order id
            rzpLogInfo("getErrorMessage orderId: $orderId");

            if ($orderId !== null)
            {
                $message = 'An error occured while processing this payment';
            }
            if (isset($_POST['error']) === true && is_array($_POST['error']))
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
            rzpLogInfo("returning $message");
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

            rzpLogInfo("updateOrder orderId: $orderId , razorpayPaymentId: $razorpayPaymentId , success: $success");

            if ($success === true)
            {
                try
                {
                    $wcOrderId = $order->get_id();

                    $is1ccOrder = get_post_meta( $wcOrderId, 'is_magic_checkout_order', true );

                    rzpLogInfo("Order details check initiated step 1 for the orderId: $wcOrderId");

                    if (is1ccEnabled() && !empty($is1ccOrder) && $is1ccOrder == 'yes')
                    {
                        rzpLogInfo("Order details update initiated step 1 for the orderId: $wcOrderId");

                        //To verify whether the 1cc update order function already under execution or not
                        if(get_transient('wc_order_under_process_'.$wcOrderId) === false)
                        {
                            rzpLogInfo("Order details update initiated step 2 for the orderId: $wcOrderId");

                            $this->update1ccOrderWC($order, $wcOrderId, $razorpayPaymentId);
                        }

                    }
                } catch (Exception $e) {
                    $message = $e->getMessage();
                    rzpLogError("Failed to update 1cc flow with error : $message");
                }

                $payment_method=$order->get_payment_method();

                // Need to set the status manually to processing incase of COD payment method.
                if ($payment_method == "cod")
                {
                    $order->update_status( 'processing' );
                }
                else
                {
                    $order->payment_complete($razorpayPaymentId);
                }

                if(is1ccEnabled() && !empty($is1ccOrder) && $is1ccOrder == 'yes' && is_plugin_active('woo-save-abandoned-carts/cartbounty-abandoned-carts.php')){
                    handleCBRecoveredOrder($orderId);
                }

                // Check Wati.io retargetting plugin is active or not
                if (is1ccEnabled() && !empty($is1ccOrder) && $is1ccOrder == 'yes' && is_plugin_active('wati-chat-and-notification/wati-chat-and-notification.php')){
                    handleWatiRecoveredOrder($orderId);
                }

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

                rzpLogInfo("Woocommerce orderId: $orderId processed through callback");
            }
            else
            {
                rzpLogInfo("Woocommerce orderId: $orderId processed through webhook");
            }
        }

        public function update1ccOrderWC(& $order, $wcOrderId, $razorpayPaymentId)
        {
            global $woocommerce;

            $logObj = array();
            rzpLogInfo("update1ccOrderWC wcOrderId: $wcOrderId, razorpayPaymentId: $razorpayPaymentId");

            //To avoid the symultanious update from callback and webhook
            set_transient('wc_order_under_process_'.$wcOrderId, true, 300);

            $api = $this->getRazorpayApiInstance();
            $sessionKey = $this->getOrderSessionKey($wcOrderId);

            //Check the transient data for razorpay order id, if it's not available then look into session data.
            if(get_transient($sessionKey))
            {
                $razorpayOrderId = get_transient($sessionKey);
            }
            else
            {
                $razorpayOrderId = $woocommerce->session->get($sessionKey);
            }

            $razorpayData = $api->order->fetch($razorpayOrderId);

            $this->UpdateOrderAddress($razorpayData, $order);

            $gstNo             = $razorpayData['notes']['gstin']??'';
            $orderInstructions  = $razorpayData['notes']['order_instructions']??'';

            if($gstNo != ''){
                $order->add_order_note( "GSTIN No. : ". $gstNo );
            }
            if($orderInstructions != ''){
                $order->add_order_note( "Order Instructions: ". $orderInstructions);
            }

            // update gift card and coupons
            $this->updateGiftAndCoupon($razorpayData, $order, $wcOrderId, $razorpayPaymentId);

            //Apply shipping charges to woo-order
            if(isset($razorpayData['shipping_fee']) === true)
            {

                //To remove by default shipping method added on order.
                $existingItems = (array) $order->get_items('shipping');
                rzpLogInfo("Shipping details updated for orderId: $wcOrderId is".json_encode($existingItems));

                if (sizeof($existingItems) != 0) {
                    // Loop through shipping items
                    foreach ($existingItems as $existingItemKey => $existingItemVal) {
                        $order->remove_item($existingItemKey);
                    }
                }

                // Get a new instance of the WC_Order_Item_Shipping Object
                $item = new WC_Order_Item_Shipping();

                // if shipping charges zero
                if($razorpayData['shipping_fee'] == 0)
                {
                    $item->set_method_title( 'Free Shipping' );
                }
                else
                {
                    $isStoreShippingEnabled = "";
                    $shippingData = get_post_meta( $wcOrderId, '1cc_shippinginfo', true );

                    if (class_exists('WCFMmp'))
                    {
                        $shippingOptions = get_option( 'wcfm_shipping_options', array());
                        // By default store shipping should be consider enable
                        $isStoreShippingEnabled = isset( $shippingOptions['enable_store_shipping'] ) ? $shippingOptions['enable_store_shipping'] : 'yes';
                    }

                    if ($isStoreShippingEnabled == 'yes')
                    {
                        foreach ($shippingData as $key => $value)
                        {
                            $item = new WC_Order_Item_Shipping();
                            //$item->set_method_id($test[$key]['rate_id']);
                            $item->set_method_title($shippingData[$key]['name']);
                            $item->set_total($shippingData[$key]['price']/100 );
                            $order->add_item($item);
                            $item->save();
                            $itemId = $item->get_id();

                            $wcfmCommissionOptions = get_option( 'wcfm_commission_options', array() );

                            $vendorGetShipping = isset( $wcfmCommissionOptions['get_shipping'] ) ? $wcfmCommissionOptions['get_shipping'] : 'yes';

                            if (isset($shippingData[$key]['vendor_id']) && $vendorGetShipping == 'yes')
                            {
                                $itemData = array(
                                    'method_id' => $shippingData[$key]['method_id'],
                                    'instance_id' => $shippingData[$key]['instance_id'],
                                    'vendor_id' => $shippingData[$key]['vendor_id'],
                                    'Items' => $shippingData[$key]['meta_data'][0]['value']
                                );
                                updateVendorDetails($shippingData[$key]['price']/100, $shippingData[$key]['vendor_id'], $wcOrderId);

                                foreach ($itemData as $itemkey => $itemval)
                                {
                                    wc_update_order_item_meta( $itemId, $itemkey, $itemval);
                                }
                            }

                        }
                    }
                    else
                    {
                        $item = new WC_Order_Item_Shipping();

                        // if shipping charges zero
                        if ($razorpayData['shipping_fee'] == 0)
                        {
                            $item->set_method_title( 'Free Shipping' );
                        }
                        else
                        {
                             $item->set_method_title($shippingData??[0]['name']);
                        }

                        // set an non existing Shipping method rate ID will mark the order as completed instead of processing status
                        // $item->set_method_id( "flat_rate:1" );
                        $item->set_total( $razorpayData['shipping_fee']/100 );

                        $order->add_item( $item );

                        $item->save();
                    }
                    // Calculate totals and save
                    $order->calculate_totals();

                }
            }

            // set default payment method
            $payment_method = $this->id;
            $payment_method_title = $this->title;

            // To verify the payment method for particular payment id.
            $razorpayPaymentData = $api->payment->fetch($razorpayPaymentId);

            $paymentDoneBy = $razorpayPaymentData['method'];

            if (($paymentDoneBy === 'cod') && isset($razorpayData['cod_fee']) == true)
            {
                $codKey = $razorpayData['cod_fee']/100;
                $payment_method = 'cod';
                $payment_method_title = 'Cash on delivery';
            }

            //update payment method title
            $order->set_payment_method($payment_method);
            $order->set_payment_method_title($payment_method_title);
            $order->save();

            if (($paymentDoneBy === 'cod') && isset($razorpayData['cod_fee']) == true)
            {
                // Get a new instance of the WC_Order_Item_Fee Object
                $itemFee = new WC_Order_Item_Fee();

                $itemFee->set_name('COD Fee'); // Generic fee name
                $itemFee->set_amount($codKey); // Fee amount
                // $itemFee->set_tax_class(''); // default for ''
                $itemFee->set_tax_status( 'none' ); // If we don't set tax status then it will consider by dafalut tax class.
                $itemFee->set_total($codKey); // Fee amount

                // Calculating Fee taxes
                // $itemFee->calculate_taxes( $calculateTaxFor );

                // Add Fee item to the order
                $order->add_item($itemFee);
                $order->calculate_totals();
                $order->save();
            }

            //For abandon cart Lite recovery plugin recovery function
            if(is_plugin_active( 'woocommerce-abandoned-cart/woocommerce-ac.php'))
            {
                $this->updateRecoverCartInfo($wcOrderId);
            }

            $note = __('Order placed through Razorpay Magic Checkout');
            $order->add_order_note( $note );
        }

        public function updateGiftAndCoupon($razorpayData, $order, $orderId, $razorpayPaymentId)
        {
            global $woocommerce;
            global $wpdb;

            foreach($razorpayData['promotions'] as $promotion)
            {
                if($promotion['type'] == 'gift_card'){

                    $usedAmt = $promotion['value']/100;
                    $giftCode = $promotion['code'];
                    if(is_plugin_active('yith-woocommerce-gift-cards/init.php')){

                        $yithCard = new YITH_YWGC_Gift_Card( $args = array('gift_card_number'=> $giftCode));

                        //Get GC status
                        $post  = get_post($yithCard->ID);
                        $status = $post->post_status;

                        $giftCardBalance = $yithCard->get_balance();

                        if($giftCardBalance == null && $giftCardBalance >= 0 && $usedAmt > $giftCardBalance && 'trash' == $status && !$yithCard->exists()){
                            // initiate refund in case gift card faliure
                            $this->processGiftCardRefund($orderId, $razorpayPaymentId, $razorpayData['amount_paid'], $reason = '');
                        }else{
                            //Deduct amount of gift card
                            $yithCard->update_balance( $yithCard->get_balance() - $usedAmt );
                            $yithCard->register_order($orderId);

                            $code = "Gift Card ($giftCode)";

                            $wpdb->query( // phpcs:ignore
                                $wpdb->prepare(
                                    'INSERT INTO `' . $wpdb->prefix . 'woocommerce_order_items`( order_item_name, order_item_type, order_id ) VALUES ( %s, %s, %s)',
                                    $code,
                                    'fee',
                                    $orderId
                                )
                            );

                            $itemId = $wpdb->insert_id;
                            wc_add_order_item_meta( $itemId, '_line_total', -$usedAmt );

                            $order->add_order_note( sprintf( esc_html__( 'Order paid with gift cards for a total amount of %s.', 'yith-woocommerce-gift-cards' ), wc_price( $usedAmt ) ) );

                            $orderTotal = $order->get_total() - $usedAmt;
                            $order->set_total($orderTotal);
                            $order->save();
                        }

                    }
                    else if(is_plugin_active('pw-woocommerce-gift-cards/pw-gift-cards.php'))
                    {
                        $result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->pimwick_gift_card}` WHERE `number` = %s", $promotion['code'] ) );
                        if($result != null){
                            $balance = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$wpdb->pimwick_gift_card_activity} WHERE pimwick_gift_card_id = %d", $result->pimwick_gift_card_id ) );

                            if($balance == null && $balance >= 0 && $usedAmt > $balance ){
                                // initiate refund in case gift card faliure
                                $this->processGiftCardRefund($orderId, $razorpayPaymentId, $razorpayData['amount_paid'], $reason = '');
                            }else{
                                //Deduct amount of gift card
                               $this->debitGiftCards($orderId, $order, "order_id: $orderId checkout_update_order_meta", $usedAmt, $giftCode);
                            }

                        }else{
                            $this->processGiftCardRefund($orderId, $razorpayPaymentId, $razorpayData['amount_paid'], $reason = '');
                        }

                    }else{
                       $this->processGiftCardRefund($orderId,  $razorpayPaymentId, $razorpayData['amount_paid'], $reason = '');
                    }

                }else{

                    $couponKey = $promotion['code'];

                    if (empty($couponKey) === false)
                    {
                        // Remove the same coupon, if already being added to order.
                        $order->remove_coupon($couponKey);

                        //TODO: Convert all razorpay amount in paise to rupees
                        $discount_total = $promotion['value']/100;

                        //TODO: Verify source code implementation
                        // Loop through products and apply the coupon discount
                        foreach($order->get_items() as $order_item)
                        {
                            $total = $order_item->get_total();
                            $order_item->set_subtotal($total);
                            $order_item->set_total($total - $discount_total);
                            $order_item->save();
                        }
                        // TODO: Test if individual use coupon fails by hardcoding here
                        $isApplied = $order->apply_coupon($couponKey);
                        $order->save();

                        rzpLogInfo("Coupon details updated for orderId: $orderId");
                    }
                }

            }
        }

        protected function debitGiftCards( $orderId, $order, $note, $usedAmt, $giftCardNo) {

            global $woocommerce;
            global $wpdb;

            if ( ! is_a( $order, 'WC_Order' ) ) {
                return;
            }

            // insert GC in orderitems
            $wpdb->query( // phpcs:ignore
                $wpdb->prepare(
                    'INSERT INTO `' . $wpdb->prefix . 'woocommerce_order_items`( order_item_name, order_item_type, order_id ) VALUES ( %s, %s, %s)',
                    $code,
                    'pw_gift_card',
                    $orderId
                )
            );

            $itemId = $wpdb->insert_id;
            wc_add_order_item_meta( $itemId, 'card_number', $giftCardNo );
            wc_add_order_item_meta( $itemId, 'amount', $usedAmt );

            foreach( $order->get_items( 'pw_gift_card' ) as $order_item_id => $line ) {
                $gift_card = new PW_Gift_Card( $giftCardNo );

                if ( $gift_card->get_id() ) {
                    if ( !$line->meta_exists( '_pw_gift_card_debited' ) ) {
                        if ( $line->get_amount() != 0 ) {
                            $gift_card->debit( ( $line->get_amount() * -1 ), "$note, order_item_id: $order_item_id" );
                        }

                        $line->add_meta_data( '_pw_gift_card_debited', true );
                        $line->save();
                    }
                }
            }
        }

        //To update customer address info to wc order.
        public function updateOrderAddress($razorpayData, $order)
        {
            $this->newUserAccount($razorpayData, $order);
            rzpLogInfo("updateOrderAddress function called");
            $receipt = $razorpayData['receipt'];

            if (isset($razorpayData['customer_details']['shipping_address']))
            {
                $shippingAddressKey = $razorpayData['customer_details']['shipping_address'];

                $shippingAddress = [];

                $shippingAddress['first_name'] = $shippingAddressKey['name'];
                $shippingAddress['address_1'] = $shippingAddressKey['line1'];
                $shippingAddress['address_2'] = $shippingAddressKey['line2'];
                $shippingAddress['city'] = $shippingAddressKey['city'];
                $shippingAddress['country'] = strtoupper($shippingAddressKey['country']);
                $shippingAddress['postcode'] = $shippingAddressKey['zipcode'];
                $shippingAddress['email'] = $razorpayData['customer_details']['email'];
                $shippingAddress['phone'] = $shippingAddressKey['contact'];

                $order->set_address( $shippingAddress, 'shipping' );

                $shippingState = strtoupper($shippingAddressKey['state']);
                $shippingStateName = str_replace(" ", '', $shippingState);
                $shippingStateCode = getWcStateCodeFromName($shippingStateName);
                $order->set_shipping_state($shippingStateCode);

                $this->updateUserAddressInfo('shipping_', $shippingAddress, $shippingStateCode, $order);
                rzpLogInfo('shipping details for receipt id: '.$receipt .' is '. json_encode($shippingAddress));

                if (empty($razorpayData['customer_details']['billing_address']) == false)
                {
                    $billingAddress['first_name'] = $razorpayData['customer_details']['billing_address']['name'];
                    $billingAddress['address_1'] = $razorpayData['customer_details']['billing_address']['line1'];
                    $billingAddress['address_2'] = $razorpayData['customer_details']['billing_address']['line2'];
                    $billingAddress['city'] = $razorpayData['customer_details']['billing_address']['city'];
                    $billingAddress['country'] = strtoupper($razorpayData['customer_details']['billing_address']['country']);
                    $billingAddress['postcode'] = $razorpayData['customer_details']['billing_address']['zipcode'];
                    $billingAddress['email'] = $razorpayData['customer_details']['email'];
                    $billingAddress['phone'] = $razorpayData['customer_details']['billing_address']['contact'];
                    $order->set_address( $billingAddress, 'billing' );

                    $billingState = strtoupper($razorpayData['customer_details']['billing_address']['state']);
                    $billingStateName = str_replace(" ", '', $billingState);
                    $billingStateCode = getWcStateCodeFromName($billingStateName);
                    $order->set_billing_state($billingStateCode);

                    $this->updateUserAddressInfo('billing_', $billingAddress, $billingStateCode, $order);
                    rzpLogInfo('billing details for receipt id: '.$receipt .' is '. json_encode($billingAddress));
                }
                else
                {
                    $order->set_address( $shippingAddress, 'billing' );
                    $order->set_billing_state($shippingStateCode);

                    $this->updateUserAddressInfo('billing_', $shippingAddress, $shippingStateCode, $order);
                }

                rzpLogInfo("updateOrderAddress function executed");

                $order->save();
            }
        }

        //Create new user account
        public function newUserAccount($razorpayData, $order)
        {
            global $woocommerce;

            if (!email_exists($razorpayData['customer_details']['email']) && isMandatoryAccCreationEnabled()) {

                $contact = $razorpayData['customer_details']['contact'];

                $email = $razorpayData['customer_details']['email'];
                $random_password = wp_generate_password(8, false);

                //create user name with the help default woocommerce function
                $username = wc_create_new_customer_username( $email );
                $userId  = wp_create_user( $username, $random_password, $email );
                $user = get_user_by('id', $userId);

                update_post_meta($order->get_id(), '_customer_user', $userId);

                // Get all WooCommerce emails Objects from WC_Emails Object instance
                $emails = wc()->mailer()->emails;

                // Send WooCommerce "Customer New Account" email notification with the password
                $emails['WC_Email_Customer_New_Account']->trigger( $userId, $random_password, true );

                update_user_meta( $userId, 'shipping_email', $email);
                update_user_meta( $userId, 'shipping_phone', $contact );

                if (isset($razorpayData['customer_details']['shipping_address']))
                {
                    $shpping = $razorpayData['customer_details']['shipping_address'];

                    //extract data
                    if (empty($razorpayData['customer_details']['billing_address']) == false)
                    {
                        $billing = $razorpayData['customer_details']['billing_address'];
                    }
                    else
                    {
                        $billing = $razorpayData['customer_details']['shipping_address'];
                    }

                    $shippingState = strtoupper($shpping->state);
                    $shippingStateName = str_replace(" ", '', $shippingState);
                    $shippingStateCode = getWcStateCodeFromName($shippingStateName);

                    $billingState = strtoupper($billing->state);
                    $billingStateName = str_replace(" ", '', $billingState);
                    $billingStateCode = getWcStateCodeFromName($billingStateName);

                    // user's shipping data
                    update_user_meta( $userId, 'shipping_first_name', $shpping->name );
                    update_user_meta( $userId, 'shipping_address_1', $shpping->line1);
                    update_user_meta( $userId, 'shipping_address_2', $shpping->line2);
                    update_user_meta( $userId, 'shipping_city', $shpping->city);
                    update_user_meta( $userId, 'shipping_country', strtoupper($shpping->country));
                    update_user_meta( $userId, 'shipping_postcode', $shpping->zipcode);
                    update_user_meta( $userId, 'shipping_state', $shippingStateCode);

                    // user's billing data
                    update_user_meta( $userId, 'billing_first_name', $shpping->name);
                    update_user_meta( $userId, 'billing_phone', $contact);
                    update_user_meta( $userId, 'billing_address_1', $billing->line1);
                    update_user_meta( $userId, 'billing_address_2', $billing->line2);
                    update_user_meta( $userId, 'billing_city', $billing->city);
                    update_user_meta( $userId, 'billing_country', strtoupper($billing->country));
                    update_user_meta( $userId, 'billing_postcode', $billing->zipcode);
                    update_user_meta( $userId, 'billing_state', $billingStateCode);
                }
            }
        }

        /**
          * Retrieve a Shipping Zone by it's ID.
          *
          * @param int $zone_id Shipping Zone ID.
          * @return WC_Shipping_Zone|WP_Error
          */
          // TODO: can't we directly return the statement?
        protected function getShippingZone($zoneId)
        {
            $zone = WC_Shipping_Zones::get_zone_by('zone_id', $zoneId);

            return $zone;
        }

        // Update user billing and shipping information
        protected function updateUserAddressInfo($addressKeyPrefix, $addressValue, $stateValue, $order)
        {
            foreach ($addressValue as $key => $value)
            {
                $metaKey = $addressKeyPrefix;
                $metaKey .= $key;

                update_user_meta($order->get_user_id(), $metaKey, $value);
            }

            update_user_meta($order->get_user_id(), $addressKeyPrefix . 'state', $stateValue);
        }

        // Update Abandonment cart plugin table for recovered cart.
        protected function updateRecoverCartInfo($wcOrderId)
        {
            global $woocommerce;
            global $wpdb;

            $userId = get_post_meta($wcOrderId, '_customer_user', true);
            $currentTime  = current_time('timestamp'); // phpcs:ignore
            $cutOffTime  = get_option('ac_lite_cart_abandoned_time');

            if (isset($cut_off_time))
            {
                $cartCutOffTime = intval($cutOffTime) * 60;
            }
            else
            {
                $cartCutOffTime = 60 * 60;
            }

            $compareTime = $currentTime - $cutOffTime;
            if($userId > 0)
            {
                $userType = 'REGISTERED';
            }
            else
            {
                $userType = 'GUEST';
                $userId = get_post_meta($wcOrderId, 'abandoned_user_id', true);
            }

            $results = $wpdb->get_results( // phpcs:ignore
                $wpdb->prepare(
                    'SELECT * FROM `' . $wpdb->prefix . 'ac_abandoned_cart_history_lite` WHERE user_id = %s AND cart_ignored = %s AND recovered_cart = %s AND user_type = %s',
                    $userId,
                    0,
                    0,
                    $userType
                )
            );

            if(count($results) > 0)
            {
                if(isset($results[0]->abandoned_cart_time) && $compareTime > $results[0]->abandoned_cart_time)
                {
                     wcal_common::wcal_set_cart_session('abandoned_cart_id_lite', $results[0]->id);
                }
            }

            $abandonedOrderId    = wcal_common::wcal_get_cart_session('abandoned_cart_id_lite');

            add_post_meta($wcOrderId, 'abandoned_id', $abandonedOrderId);
            $wpdb->query( // phpcS:ignore
            $wpdb->prepare(
                'UPDATE `' . $wpdb->prefix . 'ac_abandoned_cart_history_lite` SET recovered_cart = %s, cart_ignored = %s WHERE id = %s',
                    $wcOrderId,
                    '1',
                    $abandonedOrderId
                )
            );
        }

        protected function handleErrorCase($order)
        {
            $orderId = $order->get_order_number();
            rzpLogInfo('handleErrorCase');
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
        public function getVersionMetaInfo($data = array())
        {
            if (isset($data['subscription_id']) && isset($data['recurring'])) {
                $pluginRoot = WP_PLUGIN_DIR . '/razorpay-subscriptions-for-woocommerce';
                return array(
                    'integration' => 'woocommerce-subscription',
                    'integration_version' => get_plugin_data($pluginRoot . '/razorpay-subscriptions.php')['Version'],
                    'integration_woo_razorpay_version' => get_plugin_data(plugin_dir_path(__FILE__) . 'woo-razorpay.php')['Version'],
                    'integration_parent_version' => WOOCOMMERCE_VERSION,
                    'integration_type' => 'plugin',
                );
            } else {
                return array(
                    'integration' => 'woocommerce',
                    'integration_version' => get_plugin_data(plugin_dir_path(__FILE__) . 'woo-razorpay.php')['Version'],
                    'integration_parent_version' => WOOCOMMERCE_VERSION,
                    'integration_type' => 'plugin',
                );
            }
        }

    }

    //update vendor data into wp_wcfm_marketplace_orders
    function updateVendorDetails($shippingFee, $vendorId, $orderId)
    {
        global $woocommerce;
        global $wpdb;
        $commission = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM `' . $wpdb->prefix . 'wcfm_marketplace_orders` WHERE vendor_id = %d AND order_id = %d',
                $vendorId,
                $orderId
            )
        );

        if (count($commission) > 0)
        {
            $totalComm = $commission[0]->total_commission+$shippingFee;
            $wpdb->query(
                $wpdb->prepare(
                 'UPDATE `' . $wpdb->prefix . 'wcfm_marketplace_orders` SET shipping = %d, total_commission = %d WHERE vendor_id = %d AND order_id = %d',
                    $shippingFee,
                    $totalComm,
                    $vendorId,
                    $orderId
                )
            );
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

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_razorpay_gateway');

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

    add_action( 'woocommerce_before_single_product', 'trigger_affordability_widget', 10 );

    function trigger_affordability_widget()
    {
        if (empty(get_option('rzp_afd_enable')) === false and
            get_option('rzp_afd_enable') === 'yes')
        {
            if (empty(get_option('rzp_afd_feature_checked')) === true or
                get_option('rzp_afd_feature_checked') === 'no')
            {
                try
                {
                    $api = new Api(get_option('woocommerce_razorpay_settings')['key_id'], get_option('woocommerce_razorpay_settings')['key_secret']);
                    $merchantPreferences = $api->request->request('GET', 'accounts/me/features');
                    if (isset($merchantPreferences) === false or
                        isset($merchantPreferences['assigned_features']) === false)
                    {
                        throw new Exception("Error in Api call.");
                    }

                    update_option('rzp_afd_enable', 'no');
                    foreach ($merchantPreferences['assigned_features'] as $preference)
                    {
                        if ($preference['name'] === 'affordability_widget' or
                            $preference['name'] === 'affordability_widget_set')
                        {
                            update_option('rzp_afd_enable', 'yes');
                            break;
                        }
                    }

                    update_option('rzp_afd_feature_checked', 'yes');
                }
                catch(\Exception $e)
                {
                    rzpLogError($e->getMessage());
                    return;
                }
            }

            if (empty(get_option('rzp_afd_enable')) === false and
                get_option('rzp_afd_enable') === 'yes')
            {
                add_action ('woocommerce_before_add_to_cart_form', 'addAffordabilityWidgetHTML');
            }
        }
    }
}

// This is set to a priority of 10
function razorpay_webhook_init()
{
    $rzpWebhook = new RZP_Webhook();

    $rzpWebhook->process();
}

define('RZP_PATH', plugin_dir_path( __FILE__ ));
define('RZP_CHECKOUTJS_URL', 'https://checkout.razorpay.com/v1/checkout-1cc.js');
define('BTN_CHECKOUTJS_URL', 'https://cdn.razorpay.com/static/wooc/magic-rzp.js');
define('RZP_1CC_CSS_SCRIPT', 'RZP_1CC_CSS_SCRIPT');


function enqueueScriptsFor1cc()
{
    $siteurl = get_option('siteurl');
    $pluginData = get_plugin_data( __FILE__ );

    $domain = parse_url($siteurl, PHP_URL_HOST);
    $domain_ip = gethostbyname($domain);

    //Consider https if site url is not localhost server.
    if (filter_var($domain_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
    {
        $siteurl = str_replace('http://', 'https://', $siteurl);
    }

    wp_register_script( '1cc_razorpay_config', '' );
    wp_enqueue_script( '1cc_razorpay_config' );
    wp_add_inline_script( '1cc_razorpay_config', '!function(){var o=document.querySelector("meta[name=rzp_merchant_key]");o&&(window.Razorpay||(window.Razorpay={}),"object"==typeof window.Razorpay&&(window.Razorpay.config||(window.Razorpay.config={}),window.Razorpay.config.merchant_key=o.getAttribute("value")))}();');

    wp_register_script('1cc_razorpay_checkout', RZP_CHECKOUTJS_URL, null, null);
    wp_enqueue_script('1cc_razorpay_checkout');
    wp_register_style(RZP_1CC_CSS_SCRIPT, plugin_dir_url(__FILE__)  . 'public/css/1cc-product-checkout.css', null, null);
    wp_enqueue_style(RZP_1CC_CSS_SCRIPT);

    wp_register_script('btn_1cc_checkout', BTN_CHECKOUTJS_URL, null, null);
    wp_localize_script('btn_1cc_checkout', 'rzp1ccCheckoutData', array(
      'nonce' => wp_create_nonce("wp_rest"),
      'siteurl' => $siteurl,
      'blogname' => get_bloginfo('name'),
      'cookies' => $_COOKIE,
      'requestData' => $_REQUEST,
      'version' => $pluginData['Version'],
    ) );
    wp_enqueue_script('btn_1cc_checkout');
}

//To add 1CC button on cart page.
add_action( 'woocommerce_proceed_to_checkout', 'addCheckoutButton');

if(isRazorpayPluginEnabled() && is1ccEnabled()) {
   add_action('wp_head', 'injectMerchantKeyMeta', 1);
   add_action('wp_head', 'addRzpSpinner');
}

function addCheckoutButton()
{
  add_action('wp_enqueue_scripts', 'enqueueScriptsFor1cc', 0);

  if (isRazorpayPluginEnabled() && is1ccEnabled() )
  {
    if (isTestModeEnabled()) {
      $current_user = wp_get_current_user();
      if ($current_user->has_cap( 'administrator' ) || preg_match( '/@razorpay.com$/i', $current_user->user_email )) {
        $tempTest = RZP_PATH . 'templates/rzp-cart-checkout-btn.php';
        load_template( $tempTest, false, array() );
      }
    } else {
      $tempTest = RZP_PATH . 'templates/rzp-cart-checkout-btn.php';
      load_template( $tempTest, false, array() );
    }
  }
  else
  {
    return;
  }
}

//To add 1CC Mini cart checkout button
if(isRazorpayPluginEnabled() && is1ccEnabled() && isMiniCartCheckoutEnabled())
{

    add_action( 'woocommerce_widget_shopping_cart_buttons', function()
    {
        // Removing Buttons
        remove_action( 'woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_proceed_to_checkout', 20 );

        add_action('woocommerce_cart_updated', 'enqueueScriptsFor1cc', 10);

        add_action( 'woocommerce_widget_shopping_cart_buttons', 'addMiniCheckoutButton', 20 );

    }, 1 );
}

function addMiniCheckoutButton()
{
    add_action('wp_enqueue_scripts', 'enqueueScriptsFor1cc', 0);

    if (isTestModeEnabled()) {
      $current_user = wp_get_current_user();
      if ($current_user->has_cap( 'administrator' ) || preg_match( '/@razorpay.com$/i', $current_user->user_email )) {
        $tempTest = RZP_PATH . 'templates/rzp-mini-checkout-btn.php';
        load_template( $tempTest, false, array() );
      }
    } else {
      $tempTest = RZP_PATH . 'templates/rzp-mini-checkout-btn.php';
      load_template( $tempTest, false, array() );
    }

}

//To add 1CC button on product page.
if(isRazorpayPluginEnabled() && is1ccEnabled() && isPdpCheckoutEnabled())
{
    add_action( 'woocommerce_after_add_to_cart_button', 'addPdpCheckoutButton');
}

function injectMerchantKeyMeta(){
  echo "<meta name='rzp_merchant_key' value=" . get_option('woocommerce_razorpay_settings')['key_id'] . ">";
}

function addRzpSpinner()
{
    if (isTestModeEnabled()) {
      $current_user = wp_get_current_user();
      if ($current_user->has_cap( 'administrator' ) || preg_match( '/@razorpay.com$/i', $current_user->user_email )) {
        $tempTest = RZP_PATH . 'templates/rzp-spinner.php';
        load_template( $tempTest, false, array() );
      }
    } else {
      $tempTest = RZP_PATH . 'templates/rzp-spinner.php';
      load_template( $tempTest, false, array() );
    }
}

function addPdpCheckoutButton()
{
    add_action('wp_enqueue_scripts', 'enqueueScriptsFor1cc', 0);

    if (isTestModeEnabled()) {
      $current_user = wp_get_current_user();
      if ($current_user->has_cap( 'administrator' ) || preg_match( '/@razorpay.com$/i', $current_user->user_email )) {
        $tempTest = RZP_PATH . 'templates/rzp-pdp-checkout-btn.php';
        load_template( $tempTest, false, array() );
      }
    } else {
      $tempTest = RZP_PATH . 'templates/rzp-pdp-checkout-btn.php';
      load_template( $tempTest, false, array() );
    }
}

// for admin panel custom alerts
function addAdminSettingsAlertScript()
{
    if (isRazorpayPluginEnabled()) {
        wp_enqueue_script('rzpAdminSettingsScript',  plugin_dir_url(__FILE__) .'public/js/admin-rzp-settings.js');
    }
}

add_action('admin_enqueue_scripts', 'addAdminSettingsAlertScript');

function disable_coupon_field_on_cart($enabled)
{
    if (isTestModeEnabled()) {
        $current_user = wp_get_current_user();
        if ($current_user->has_cap( 'administrator' ) || preg_match( '/@razorpay.com$/i', $current_user->user_email )) {
            if (is_cart()) {
                $enabled = false;
            }
        }
    } else {
        if (is_cart()) {
            $enabled = false;
        }
    }
    return $enabled;
}

if(is1ccEnabled())
{
    add_filter('woocommerce_coupons_enabled', 'disable_coupon_field_on_cart');
    add_action('woocommerce_cart_updated', 'enqueueScriptsFor1cc', 10);
    add_filter('woocommerce_order_needs_shipping_address', '__return_true');
}

//Changes Recovery link URL to Magic cart URL to avoid redirection to checkout page
function cartbounty_alter_automation_button( $button ){
    return str_replace("cartbounty=","cartbounty=magic_",$button);
}

if(is_plugin_active('woo-save-abandoned-carts/cartbounty-abandoned-carts.php')){
    add_filter( 'cartbounty_automation_button_html', 'cartbounty_alter_automation_button' );
}
