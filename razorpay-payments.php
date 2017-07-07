<?php
/*
Plugin Name: WooCommerce Razorpay Payments
Plugin URI: https://razorpay.com
Description: Razorpay Payment Gateway Integration for WooCommerce
Version: 1.5.2
Author: Razorpay
Author URI: https://razorpay.com
*/

if ( ! defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly
}

require_once __DIR__.'/includes/razorpay-webhook.php';
require_once __DIR__.'/includes/Errors/ErrorCode.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;
use Razorpay\Woocommerce\Errors as WooErrors;

require_once __DIR__.'/razorpay-sdk/Razorpay.php';

add_action('plugins_loaded', 'woocommerce_razorpay_init', 0);
add_action('admin_post_nopriv_rzp_wc_webhook', 'razorpay_webhook_init');

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

        const INR                            = 'INR';
        const CAPTURE                        = 'capture';
        const AUTHORIZE                      = 'authorize';
        const WC_ORDER_ID                    = 'woocommerce_order_id';


        public function __construct()
        {
            $this->id = 'razorpay';
            $this->method_title = 'Razorpay';
            $this->icon =  plugins_url('images/logo.png' , __FILE__);
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->key_id = $this->settings['key_id'];
            $this->key_secret = $this->settings['key_secret'];
            $this->payment_action = $this->settings['payment_action'];

            if (isset($this->settings['enable_webhook']) === true)
            {
                $this->enable_webhook = $this->settings['enable_webhook'];
            }
            else
            {
                $this->enable_webhook = 'yes';
            }

            if (isset($this->settings['webhook_secret']) === true)
            {
                $this->webhook_secret = $this->settings['webhook_secret'];
            }
            else
            {
                $this->webhook_secret = '';
            }

            $this->supports = array(
                'products',
                'refunds',
            );

            $this->msg['message'] = '';
            $this->msg['class'] = '';

            add_action('init', array(&$this, 'check_razorpay_response'));
            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_razorpay_response'));

            $cb = array($this, 'process_admin_options');

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
            {
                add_action("woocommerce_update_options_payment_gateways_{$this->id}", $cb);
            }
            else
            {
                add_action('woocommerce_update_options_payment_gateways', $cb);
            }

            add_action('woocommerce_receipt_razorpay', array($this, 'receipt_page'));
        }

        function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'razorpay'),
                    'type' => 'checkbox',
                    'label' => __('Enable Razorpay Payment Module.', 'razorpay'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'razorpay'),
                    'type'=> 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'razorpay'),
                    'default' => __('Credit Card/Debit Card/NetBanking', 'razorpay')
                ),
                'description' => array(
                    'title' => __('Description', 'razorpay'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'razorpay'),
                    'default' => __('Pay securely by Credit or Debit card or internet banking through Razorpay.', 'razorpay')
                ),
                'key_id' => array(
                    'title' => __('Key ID', 'razorpay'),
                    'type' => 'text',
                    'description' => __('The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.', 'razorpay')
                ),
                'key_secret' => array(
                    'title' => __('Key Secret', 'razorpay'),
                    'type' => 'text',
                    'description' => __('The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.', 'razorpay')
                ),
                'payment_action' => array(
                    'title' => __('Payment Action', 'razorpay'),
                    'type' => 'select',
                    'description' =>  __('Payment action on order compelete', 'razorpay'),
                    'default' => self::CAPTURE,
                    'options' => array(
                        self::AUTHORIZE => 'Authorize',
                        self::CAPTURE   => 'Authorize and Capture'
                    )
                ),
                'enable_webhook' => array(
                    'title' => __('Enable Webhook', 'razorpay'),
                    'type' => 'checkbox',
                    'description' => esc_url( admin_url('admin-post.php') ) . "?action=rzp_wc_webhook <br><br>Instructions and guide to <a href='https://github.com/razorpay/razorpay-woocommerce/wiki/Razorpay-Woocommerce-Webhooks'>Razorpay webhooks</a>",
                    'label' => __('Enable Razorpay Webhook <a href="https://dashboard.razorpay.com/#/app/webhooks">here</a> with the URL listed below.', 'razorpay'),
                    'default' => 'yes'
                ),
                'webhook_secret' => array(
                    'title' => __('Webhook Secret', 'razorpay'),
                    'type' => 'text',
                    'description' => __('Webhook secret is used for webhook signature verification. This has to match the one added <a href="https://dashboard.razorpay.com/#/app/webhooks">here</a>', 'razorpay'),
                    'default' => ''
                ),
            );
        }
        public function admin_options()
        {
            echo '<h3>'.__('Razorpay Payment Gateway', 'razorpay') . '</h3>';
            echo '<p>'.__('Razorpay is an online payment gateway for India with transparent pricing, seamless integration and great support') . '</p>';
            echo '<table class="form-table">';

            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
        }

        /**
         *  There are no payment fields, but we want to show the description if set.
         **/
        function payment_fields()
        {
            if($this->description)
            {
                echo wpautop(wptexturize($this->description));
            }
        }

        /**
         * Receipt Page
         **/
        function receipt_page($order)
        {
            echo $this->generate_razorpay_form($order);
        }

        protected function getSessionKey($orderId)
        {
            return "razorpay_order_id.$orderId";
        }

        /**
         * Given a order Id, find the associated
         * Razorpay Order from the session and verify
         * that is is still correct. If not found
         * (or incorrect), create a new Razorpay Order
         * @param  string $orderId Order Id
         * @return mixed Razorpay Order Id or Exception
         */
        protected function createOrGetRazorpayOrderId($orderId)
        {
            global $woocommerce;

            $sessionKey = $this->getSessionKey($orderId);

            $create = false;

            try
            {
                $razorpayOrderId = $woocommerce->session->get($sessionKey);

                // If we don't have an Order
                // or the if the order is present in session but doesn't match what we have saved
                if (($razorpayOrderId === null) or
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
         * Generate razorpay button link
         **/
        public function generate_razorpay_form($orderId)
        {
            global $woocommerce;
            $order = new WC_Order($orderId);

            $redirectUrl = get_site_url() . '/?wc-api=' . get_class($this);

            $razorpayOrderId = $this->createOrGetRazorpayOrderId($orderId);

            if(is_a($razorpayOrderId, 'Exception'))
            {
                $message = $razorpayOrderId->getMessage();
                return 'RAZORPAY ERROR: Order creation failed with the message \'' . $message . '\'';
            }

            $checkoutArgs = $this->getCheckoutArguments($order, $razorpayOrderId);

            $html = '<p>'.__('Thank you for your order, please click the button below to pay with Razorpay.', 'razorpay').'</p>';
            $html .= $this->generateOrderForm($redirectUrl, $checkoutArgs);

            return $html;
        }

        /**
         * Returns array of checkout params
         */
        protected function getCheckoutArguments($order, $razorpayOrderId)
        {
            $callbackUrl = get_site_url() . '/?wc-api=' . get_class($this);

            $orderId = $this->getOrderId($order);

            $productinfo = "Order $orderId";

            $currency = null;

            $args = array(
                'key'           => $this->key_id,
                'name'          => get_bloginfo('name'),
                'currency'      => self::INR,
                'description'   => $productinfo,
                'notes'         => array (
                    self::WC_ORDER_ID => $orderId
                ),
                'order_id'      => $razorpayOrderId,
                'callback_url'  => $callbackUrl
            );



            $args['amount'] = $this->getOrderAmountAsInteger($order);

            if (version_compare(WOOCOMMERCE_VERSION, '2.7.0', '>='))
            {
                $args['prefill'] = array(
                    'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'email'   => $order->get_billing_email(),
                    'contact' => $order->get_billing_phone(),
                );
                $currency = $order->get_currency();
            }
            else
            {
                $args['prefill'] = array(
                    'name'    => $order->billing_first_name . ' ' . $order->billing_last_name,
                    'email'   => $order->billing_email,
                    'contact' => $order->billing_phone,
                );
                $currency = $order->get_order_currency();
            }

            if ($currency !== self::INR)
            {
                $args['display_currency'] = $currency;
                $args['display_amount']   = $order->get_total();
            }

            return $args;
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

        protected function createRazorpayOrderId($orderId, $sessionKey)
        {
            // Calls the helper function to create order data
            global $woocommerce;

            $api = $this->getRazorpayApiInstance();

            $data = $this->getOrderCreationData($orderId);

            $razorpayOrder = $api->order->create($data);

            $razorpayOrderId = $razorpayOrder['id'];

            $woocommerce->session->set($sessionKey, $razorpayOrderId);

            return $razorpayOrderId;
        }

        /**
         * Convert the currency to INR using rates fetched from Woocommerce Currency Switcher plugin
         *
         * @param Array $data
         *
         * @return Array
         *
         **/
        protected function convertCurrency(& $data)
        {
            global $WOOCS;

            $currencies = $WOOCS->get_currencies();

            $currency = $data['currency'];

            if (array_key_exists(self::INR, $currencies) and array_key_exists($currency, $currencies))
            {
                // If the currenct currency is the same as the default currency set in WooCommerce,
                // Currency Switcher plugin sets the rate of currenct currency as 0, because of which
                // we need to set this to 1 here if it's value is 0
                $currencyConversionRate = ($currencies[$currency]['rate'] == 0 ? 1 : $currencies[$currency]['rate']);

                // Convert the currency to INR using the rates fetched from the Currency Switcher plugin
                $value = $data['amount'] * $currencies[self::INR]['rate'];

                $data['amount'] = intval(round($value / $currencyConversionRate));
                $data['currency'] = self::INR;
            }
            else
            {
                throw new Errors\BadRequestError(
                    WooErrors\ErrorCode::WOOCS_CURRENCY_MISSING_ERROR_MESSAGE,
                    WooErrors\ErrorCode::WOOCS_CURRENCY_MISSING_ERROR_CODE,
                    400
                );

            }
        }

        protected function verifyOrderAmount($razorpayOrderId, $orderId)
        {
            $order = new WC_Order($orderId);

            $api = $this->getRazorpayApiInstance();

            $razorpayOrder = $api->order->fetch($razorpayOrderId);

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

        function getOrderCreationData($orderId)
        {
            $order = new WC_Order($orderId);

            if (!isset($this->payment_action))
            {
                $this->payment_action = self::CAPTURE;
            }

            $data = array(
                'receipt'         => $orderId,
                'amount'          => (int) round($order->get_total() * 100),
                'currency'        => get_woocommerce_currency(),
                'payment_capture' => ($this->payment_action === self::AUTHORIZE) ? 0 : 1,
                'notes'           => array(
                    self::WC_ORDER_ID  => (string) $orderId,
                ),
            );

            if ($data['currency'] !== self::INR)
            {
                if (class_exists('WOOCS'))
                {
                    $this->convertCurrency($data);
                }
                else
                {
                    throw new Errors\BadRequestError(
                        WooErrors\ErrorCode::WOOCS_MISSING_ERROR_MESSAGE,
                        WooErrors\ErrorCode::WOOCS_MISSING_ERROR_CODE,
                        400
                    );
                }
            }

            return $data;
        }

        private function enqueueCheckoutScripts($data)
        {
            wp_register_script('razorpay_checkout',
                'https://checkout.razorpay.com/v1/checkout.js',
                null, null);

            wp_register_script('razorpay_wc_script', plugin_dir_url(__FILE__)  . 'script.js',
                array('razorpay_checkout'));

            wp_localize_script('razorpay_wc_script',
                'razorpay_wc_checkout_vars',
                $data
            );

            wp_enqueue_script('razorpay_wc_script');
        }

        /**
         * Generates the order form
         **/
        function generateOrderForm($redirectUrl, $data)
        {
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

        protected function getOrderId($order)
        {
            if (version_compare(WOOCOMMERCE_VERSION, '2.7.0', '>='))
            {
                return $order->get_id();
            }

            return $order->id;
        }

        public function process_refund($orderId, $amount = null, $reason = '')
        {
            $order = new WC_Order($orderId);

            if (! $order or ! $order->get_transaction_id())
            {
                return new WP_Error('error', __('Refund failed: No transaction ID', 'woocommerce'));
            }

            $client = $this->getRazorpayApiInstance();

            $paymentId = $order->get_transaction_id();

            $data = array(
                'amount'    =>  (int) round($amount * 100),
                'notes'     =>  array(
                    'reason'    =>  $reason,
                    'order_id'  =>  $orderId
                )
            );

            try
            {
                $refund = $client->payment
                                 ->fetch($paymentId)
                                 ->refund($data);

                $order->add_order_note(__( 'Refund Id: ' . $refund->id, 'woocommerce' ));

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
            $order = new WC_Order($order_id);
            $woocommerce->session->set(self::SESSION_KEY, $order_id);

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
            return new Api($this->key_id, $this->key_secret);
        }

        /**
         * Check for valid razorpay server callback
         **/
        function check_razorpay_response()
        {
            global $woocommerce;

            $orderId = $woocommerce->session->get(self::SESSION_KEY);
            $order = new WC_Order($orderId);

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
                $success = false;
                $error = 'Customer cancelled the payment';
                $this->handleErrorCase($order);
            }

            $this->updateOrder($order, $success, $error, $razorpayPaymentId);

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

            $key_id = $this->key_id;
            $key_secret = $this->key_secret;

            $api = new Api($key_id, $key_secret);

            $sessionKey = $this->getSessionKey($orderId);

            $attributes = array(
                self::RAZORPAY_PAYMENT_ID => $_POST['razorpay_payment_id'],
                'razorpay_order_id'       => $woocommerce->session->get($sessionKey),
                'razorpay_signature'      => $_POST['razorpay_signature'],
            );

            $api->utility->verifyPaymentSignature($attributes);
        }

        protected function getErrorMessage($orderId)
        {
            // We don't have a proper order id
            if ($orderId !== null)
            {
                $message = "An error occured while processing this payment";
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
        public function updateOrder(& $order, $success, $errorMessage, $razorpayPaymentId)
        {
            global $woocommerce;

            $orderId = $this->getOrderId($order);

            if ($success === true)
            {
                $this->msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon. Order Id: $orderId";
                $this->msg['class'] = 'success';

                $order->payment_complete($razorpayPaymentId);
                $order->add_order_note("Razorpay payment successful <br/>Razorpay Id: $razorpayPaymentId");
                $order->add_order_note($this->msg['message']);

                if (isset($woocommerce->cart) === true)
                {
                    $woocommerce->cart->empty_cart();
                }
            }
            else
            {
                $this->msg['class'] = 'error';
                $this->msg['message'] = 'Thank you for shopping with us. However, the payment failed.';

                if ($razorpayPaymentId)
                {
                    $order->add_order_note("Payment Failed. Please check Razorpay Dashboard. <br/> Razorpay Id: $razorpayPaymentId");
                }

                $order->add_order_note("Transaction Failed: $errorMessage<br/>");
                $order->update_status('failed');
            }

            $this->add_notice($this->msg['message'], $this->msg['class']);
        }

        protected function handleErrorCase(& $order)
        {
            $orderId = $this->getOrderId($order);

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
}

function razorpay_webhook_init()
{
    $rzpWebhook = new RZP_Webhook();

    $rzpWebhook->process();
}
