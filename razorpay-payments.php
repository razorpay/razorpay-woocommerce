<?php
/*
Plugin Name: WooCommerce Razorpay Payments
Plugin URI: https://razorpay.com
Description: Razorpay Payment Gateway Integration for WooCommerce
Version: 1.3.2
Author: Razorpay
Author URI: https://razorpay.com
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

require_once __DIR__.'/razorpay-sdk/Razorpay.php';

add_action('plugins_loaded', 'woocommerce_razorpay_init', 0);

function woocommerce_razorpay_init()
{
    if (!class_exists('WC_Payment_Gateway'))
        return;

    class WC_Razorpay extends WC_Payment_Gateway
    {
        // This one stores the WooCommerce Order Id
        const SESSION_KEY = 'razorpay_wc_order_id';
        const RAZORPAY_PAYMENT_ID = 'razorpay_payment_id';

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

            $this->supports = array(
                'products',
                'refunds',
                'subscriptions',
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
                    'default' => 'capture',
                    'options' => array(
                        'authorize' => 'Authorize',
                        'capture'   => 'Authorize and Capture'
                    )
                )
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
            echo '<p>'.__('Thank you for your order, please click the button below to pay with Razorpay.', 'razorpay').'</p>';
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
         * @return mixed Razorpay Order Id or null
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
                    return $this->createRazorpayOrderId(
                        $orderId, $sessionKey);
                }
                catch(Exception $e)
                {
                    // Order creation failed
                    return null;
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

            if (function_exists('wcs_order_contains_subscription'))
            {
                $types = array(
                    'parent',
                    'renewal',
                    'resubscribe',
                    'switch',
                );

                $containsSubscription = wcs_order_contains_subscription($order, $types);

                $isSubscription = wcs_is_subscription($order);

                if ($containsSubscription or $isSubscription)
                {
                    // Now we create a subscription
                    // with our PLAN
                    $planId = 'plan_7xi9PsYhXNXKG4';

                    // Attach an addon perhaps?
                }
            }

            $razorpayOrderId = $this->createOrGetRazorpayOrderId($orderId);

            if ($razorpayOrderId === null)
            {
                return 'RAZORPAY ERROR: Api could not be reached';
            }

            $checkoutArgs = $this->getCheckoutArguments($order, $razorpayOrderId);

            $json = json_encode($checkoutArgs);

            $html = $this->generateOrderForm($redirectUrl, $json, $orderId);

            return $html;
        }

        /**
         * Returns array of checkout params
         */
        protected function getCheckoutArguments($order, $razorpayOrderId)
        {
            if (version_compare(WOOCOMMERCE_VERSION, '2.7.0', '>='))
            {
                $orderId = $order->get_id();
            }
            else
            {
                $orderId = $order->id;
            }

            $productinfo = "Order $orderId";

            $args = array(
              'key'         => $this->key_id,
              'name'        => get_bloginfo('name'),
              'currency'    => get_woocommerce_currency(),
              'description' => $productinfo,
              'notes'       => array(
                'woocommerce_order_id' => $orderId
              ),
              'order_id'    => $razorpayOrderId
            );

            $args['amount']  = $this->getOrderAmountAsInteger($order);

            if (version_compare(WOOCOMMERCE_VERSION, '2.7.0', '>='))
            {
                $args['prefill'] = array(
                    'name'      => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'email'     => $order->get_billing_email(),
                    'contact'   => $order->get_billing_phone(),
                );
            }
            else
            {
                $args['prefill'] = array(
                    'name'      => $order->billing_first_name . ' ' . $order->billing_last_name,
                    'email'     => $order->billing_email,
                    'contact'   => $order->billing_phone,
                );
            }

            return $args;
        }

        /**
         * Returns the order amount, rounded as integer
         */
        protected function getOrderAmountAsInteger($order)
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

            $api = new Api($this->key_id, $this->key_secret);

            $data = $this->getOrderCreationData($orderId);
            $razorpay_order = $api->order->create($data);

            $razorpayOrderId = $razorpay_order['id'];

            $woocommerce->session->set($sessionKey, $razorpayOrderId);

            return $razorpayOrderId;
        }

        protected function verifyOrderAmount($razorpayOrderId, $orderId)
        {
            $order = new WC_Order($orderId);

            $api = $this->getRazorpayApiInstance();

            $razorpayOrder = $api->order->fetch($razorpayOrderId);

            $razorpayOrderArgs = array(
                'id'        => $razorpayOrderId,
                'amount'    => $this->getOrderAmountAsInteger($order),
                'currency'  => get_woocommerce_currency(),
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
                $this->payment_action = 'capture';
            }

            $data = array(
                'receipt'         => $orderId,
                'amount'          => (int) round($order->get_total() * 100),
                'currency'        => get_woocommerce_currency(),
                'payment_capture' => ($this->payment_action === 'authorize') ? 0 : 1
            );

            return $data;
        }

        /**
         * Generates the order form
         **/
        function generateOrderForm($redirectUrl, $json)
        {
            $html = <<<EOT
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
    var data = $json;
</script>
<form name='razorpayform' action="$redirectUrl" method="POST">
    <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id">
    <input type="hidden" name="razorpay_signature"  id="razorpay_signature" >
</form>
<p id="msg-razorpay-success" class="woocommerce-info woocommerce-message" style="display:none">
Please wait while we are processing your payment.
</p>
<p>
    <button id="btn-razorpay">Pay Now</button>
    <button id="btn-razorpay-cancel" onclick="document.razorpayform.submit()">Cancel</button>
</p>
<script>
    (function(){
    var setDisabled = function(id, state) {
      if (typeof state === 'undefined') {
        state = true;
      }
      var elem = document.getElementById(id);
      if (state === false) {
        elem.removeAttribute('disabled');
      }
      else {
        elem.setAttribute('disabled', state);
      }
    };
    // Payment was closed without handler getting called
    data.modal = {
      ondismiss: function() {
        setDisabled('btn-razorpay', false);
      }
    };
    data.handler = function(payment){
      setDisabled('btn-razorpay-cancel');
      var successMsg = document.getElementById('msg-razorpay-success');
      successMsg.style.display = "block";
      document.getElementById('razorpay_payment_id').value = payment.razorpay_payment_id;
      document.getElementById('razorpay_signature').value = payment.razorpay_signature;
      document.razorpayform.submit();
    };
    var razorpayCheckout = new Razorpay(data);
    // global method
    function openCheckout() {
      // Disable the pay button
      setDisabled('btn-razorpay');
      razorpayCheckout.open();
    }
    function addEvent(element, evnt, funct){
      if (element.attachEvent)
       return element.attachEvent('on'+evnt, funct);
      else
       return element.addEventListener(evnt, funct, false);
    }
    // Attach event listener
    addEvent(document.getElementById('btn-razorpay'), 'click', openCheckout);
    openCheckout();
})();
</script>
EOT;
            return $html;
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

        protected function getRazorpayApiInstance()
        {
            return new Api($this->key_id, $this->key_secret);
        }

        /**
         * Check for valid razorpay server callback
         **/
        function check_razorpay_response()
        {
            global $woocommerce;

            $order_id = $woocommerce->session->get(self::SESSION_KEY);

            if ($order_id and !empty($_POST[self::RAZORPAY_PAYMENT_ID]))
            {
                $order = new WC_Order($order_id);

                $amount = $this->getOrderAmountAsInteger($order);

                $success = false;
                $error = 'WOOCOMMERCE_ERROR: Payment to Razorpay Failed. ';

                $api = $this->getRazorpayApiInstance();

                $sessionKey = $this->getSessionKey($order_id);

                $attributes = array(
                    self::RAZORPAY_PAYMENT_ID => $_POST[self::RAZORPAY_PAYMENT_ID],
                    'razorpay_order_id'   => $woocommerce->session->get($sessionKey),
                    'razorpay_signature'  => $_POST['razorpay_signature'],
                );

                try
                {
                    $api->utility->verifyPaymentSignature($attributes);

                    $success = true;
                }
                catch (Errors\SignatureVerificationError $e)
                {
                    $error .= $e->getMessage();
                }

                if ($success === true)
                {
                    $this->msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon. Order Id: $order_id";
                    $this->msg['class'] = 'success';
                    $order->payment_complete($attributes[self::RAZORPAY_PAYMENT_ID]);
                    $order->add_order_note("Razorpay payment successful <br/>Razorpay Id: " . $attributes[self::RAZORPAY_PAYMENT_ID]);
                    $order->add_order_note($this->msg['message']);
                    $woocommerce->cart->empty_cart();
                }
                else
                {
                    $this->msg['class'] = 'error';
                    $this->msg['message'] = 'Thank you for shopping with us. However, the payment failed.';
                    $order->add_order_note("Transaction Declined: $error<br/>");
                    $order->add_order_note("Payment Failed. Please check Razorpay Dashboard. <br/> Razorpay Id: " . $attributes[self::RAZORPAY_PAYMENT_ID]);
                    $order->update_status('failed');
                }
            }
            // We don't have a proper order id
            else
            {
                if ($order_id !== null)
                {
                    $order = new WC_Order($order_id);
                    $order->update_status('failed');
                    $order->add_order_note('Customer cancelled the payment');
                }
                $this->msg['class'] = 'error';
                $this->msg['message'] = "An error occured while processing this payment";
            }
            $this->add_notice($this->msg['message'], $this->msg['class']);
            $redirectUrl = $this->get_return_url($order);
            wp_redirect($redirectUrl);
            exit;
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
