<?php
/*
Plugin Name: WooCommerce Razorpay Payments
Plugin URI: https://razorpay.com
Description: Razorpay Payment Gateway Integration for WooCommerce
Version: 1.2.11
Author: Razorpay
Author URI: https://razorpay.com
*/

use Razorpay\Api\Api;

require_once __DIR__.'/razorpay-sdk/Razorpay.php';

add_action('plugins_loaded', 'woocommerce_razorpay_init', 0);

function woocommerce_razorpay_init()
{
    if (!class_exists('WC_Payment_Gateway'))
        return;

    class WC_Razorpay extends WC_Payment_Gateway
    {
        const BASE_URL = 'https://api.razorpay.com/';
        const API_VERSION = 'v1';
        const SESSION_KEY = 'razorpay_wc_order_id';

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

            $this->msg['message'] = "";
            $this->msg['class'] = "";

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

        /**
         * Generate razorpay button link
         **/
        public function generate_razorpay_form($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);
            $redirect_url = get_site_url() . '/?wc-api=' . get_class($this);
            $productinfo = "Order $order_id";
            try
            {
                $api = new Api($this->key_id, $this->key_secret);
                // Calls the helper function to create order data
                $data = $this->get_order_creation_data($order_id);

                $razorpay_order = $api->order->create($data);

                $woocommerce->session->set('razorpay_order_id', $razorpay_order['id']);
                $razorpay_args = array(
                  'key'             => $this->key_id,
                  'name'            => get_bloginfo('name'),
                  'amount'          => $order->order_total * 100,
                  'currency'        => get_woocommerce_currency(),
                  'description'     => $productinfo,
                  'prefill'         => array(
                    'name'              => $order->billing_first_name." ".$order->billing_last_name,
                    'email'             => $order->billing_email,
                    'contact'           => $order->billing_phone
                  ),
                  'notes'           => array(
                    'woocommerce_order_id' => $order_id
                  ),
                  'order_id'        => $razorpay_order['id']
                );
                $json = json_encode($razorpay_args);
                $html = $this->generate_order_form($redirect_url, $json);
                return $html;
            }
            catch (Exception $e)
            {
                echo "RAZORPAY ERROR: Api could not be reached";
            }
        }

        /**
         * Creates order data
         **/
        function get_order_creation_data($order_id)
        {
            $order = new WC_Order($order_id);

            if (!isset($this->payment_action))
            {
                $this->payment_action = 'capture';
            }

            $data = array(
                'receipt' => $order_id,
                'amount' => (int) ($order->order_total * 100),
                'currency' => get_woocommerce_currency(),
            );

            switch($this->payment_action)
            {
                case 'authorize':
                    $data['payment_capture'] = 0;
                    break;

                case 'capture':
                default:
                    $data['payment_capture'] = 1;
                    break;
            }
            return $data;
        }

        /**
         * Generates the order form
         **/
        function generate_order_form($redirect_url, $json)
        {
            $html = <<<EOT
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
    var data = $json;
</script>
<form name='razorpayform' action="$redirect_url" method="POST">
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
         * Process the payment and return the result
         **/
        function process_payment($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);
            $woocommerce->session->set(self::SESSION_KEY, $order_id);
            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
            {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('order', $order->id,
                        add_query_arg('key', $order->order_key, $order->get_checkout_payment_url(true)))
                );
            }
            else
            {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('order', $order->id,
                        add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
                );
            }
        }

        /**
         * Check for valid razorpay server callback
         **/
        function check_razorpay_response()
        {
            global $woocommerce;
            $order_id = $woocommerce->session->get(self::SESSION_KEY);

            if ($order_id  and !empty($_POST['razorpay_payment_id']))
            {
                $razorpay_payment_id = $_POST['razorpay_payment_id'];
                $order = new WC_Order($order_id);
                $key_id = $this->key_id;
                $key_secret = $this->key_secret;
                $amount = $order->order_total*100;
                $success = false;
                $error = "";
                $api = new Api($key_id, $key_secret);
                $payment = $api->payment->fetch($razorpay_payment_id);

                try
                {
                    if ($this->payment_action === 'authorize' && $payment['amount'] === $amount)
                    {
                        $success = true;
                    }

                    else
                    {
                        $razorpay_order_id = $woocommerce->session->get('razorpay_order_id');
                        $razorpay_signature = $_POST['razorpay_signature'];

                        $signature = hash_hmac('sha256', $razorpay_order_id . '|' . $razorpay_payment_id, $key_secret);
                        if (hash_equals($signature , $razorpay_signature))
                        {
                            $success = true;
                        }
                        else
                        {
                            $success = false;
                            $error = "PAYMENT_ERROR: Payment failed";
                        }
                    }
                }
                catch (Exception $e)
                {
                    $success = false;
                    $error = 'WOOCOMMERCE_ERROR: Request to Razorpay Failed';
                }
                if ($success === true)
                {
                    $this->msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon. Order Id: $order_id";
                    $this->msg['class'] = 'success';
                    $order->payment_complete();
                    $order->add_order_note("Razorpay payment successful <br/>Razorpay Id: $razorpay_payment_id");
                    $order->add_order_note($this->msg['message']);
                    $woocommerce->cart->empty_cart();
                }
                else
                {
                    $this->msg['class'] = 'error';
                    $this->msg['message'] = 'Thank you for shopping with us. However, the payment failed.';
                    $order->add_order_note("Transaction Declined: $error<br/>");
                    $order->add_order_note("Payment Failed. Please check Razorpay Dashboard. <br/> Razorpay Id: $razorpay_payment_id");
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
            $redirect_url = $this->get_return_url($order);
            wp_redirect( $redirect_url );
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
