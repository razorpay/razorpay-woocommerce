<?php
/*
Plugin Name: WooCommerce Razorpay Payments
Plugin URI: https://razorpay.com
Description: Razorpay Payment Gateway Integration for WooCommerce
Version: 1.2.11
Author: Razorpay
Author URI: https://razorpay.com
*/

require_once __DIR__.'/includes/razorpay-webhook.php';

require_once __DIR__.'/razorpay-sdk/Razorpay.php';
use Razorpay\Api\Api;

add_action('plugins_loaded', 'woocommerce_razorpay_init', 0);
add_action('admin_post_nopriv_rzp_webhook', 'razorpay_webhook_init'); 
add_action('admin_post_rzp_webhook', 'razorpay_webhook_init'); 

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
            $this->icon =  plugins_url('images/logo.png' , __FILE__ );
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->key_id = $this->settings['key_id'];
            $this->key_secret = $this->settings['key_secret'];
            $this->payment_action = $this->settings['payment_action'];

            $this->enable_webhook = $this->settings['enable_webhook'];

            $this->liveurl = 'https://checkout.razorpay.com/v1/checkout.js';

            $this->msg['message'] = "";
            $this->msg['class'] = "";

            // Adding subscriptions support for woocommerce-razorpay ---- Step 1
            $this->supports = array(
                                'products',
                                'subscriptions', 
                                'subscription_cancellation', 
                                'subscription_suspension', 
                                'subscription_reactivation',
                                'subscription_amount_changes',
                                'subscription_date_changes',
                                'subscription_payment_method_change'
                            );

            // We want this to be called when there is a subscription to be processed
            add_action('woocommerce_scheduled_subscription_payment', array($this, 'process_subscription'));

            add_action('init', array(&$this, 'check_razorpay_response'));
            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_razorpay_response'));

            if ( version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=' ) )
            {
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
            }
            else
            {
                add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
            }

            add_action('woocommerce_receipt_razorpay', array(&$this, 'receipt_page'));
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
                ),
                'enable_webhook' => array(
                    'title' => __('Enable Webhook', 'razorpay'),
                    'type' => 'checkbox',
                    'description' => esc_url( admin_url('admin-post.php') ) . '?action=rzp_webhook',
                    'label' => __('Enable Razorpay Webhook with the URL listed below.', 'razorpay'),
                    'default' => 'yes'
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
                echo wpautop(wptexturize($this->description));
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

            $api = new Api($this->key_id, $this->key_secret);

            $redirect_url = get_site_url() . '/?wc-api=' . get_class($this);

            $productinfo = "Order $order_id";

            // Calls the helper function to create order data
            $data = $this->get_order_creation_data($order_id);
            
            $razorpay_order = $api->order->create($data);
            
            $woocommerce->session->set('razorpay_order_id', $razorpay_order['id']);

            $razorpay_args = array(
                  'key' => $this->key_id,
                  'name' => get_bloginfo('name'),
                  'amount' => $order->order_total*100,
                  'currency' => get_woocommerce_currency(),
                  'description' => $productinfo,
                  'prefill' => array(
                    'name' => $order->billing_first_name." ".$order->billing_last_name,
                    'email' => $order->billing_email,
                    'contact' => $order->billing_phone
                  ),
                  'notes' => array(
                    'woocommerce_order_id' => $order_id
                  ),
                  'order_id' => $razorpay_order['id']
                );

            // since 2.0 this is the new API call for subscriptions, have to check if wc_subscriptions exists in the install
            if ( class_exists('WC_Subscriptions') && wcs_order_contains_subscription( $order_id ) )
            {
                // random number each time
                $number = mt_rand();

                $customer = $api->customer->create(array(
                    'name' => $order->billing_first_name." ".$order->billing_last_name,
                    'email' => $order->billing_email,
                    'contact' => $number // for now, random number
                ));

                $razorpay_args['customer_id'] = $customer['id'];
                $razorpay_args['recurring'] = 1;
            }

            $json = json_encode($razorpay_args);

            $html = $this->generate_order_form($redirect_url,$json,$order_id);

            return $html;
        }


        /**
         * Creates order data
        **/
        function get_order_creation_data($order_id)
        {
            $order = new WC_Order($order_id);
            
            switch($this->payment_action)
            {
                case 'authorize':
                    $data = array(
                      'receipt' => $order_id,
                      'amount' => (int) ($order->order_total * 100),
                      'currency' => get_woocommerce_currency(),
                      'payment_capture' => 0
                    );    
                    break;

                default:
                    $data = array(
                      'receipt' => $order_id,
                      'amount' => (int) ($order->order_total * 100),
                      'currency' => get_woocommerce_currency(),
                      'payment_capture' => 1
                    );
                    break;
            }

            return $data;
        }

        /**
         * Generates the order form
        **/
        function generate_order_form($redirect_url, $json, $order_id)
        {
            $checkout_html = file_get_contents(__DIR__.'/js/checkout.phtml');
            $keys = array("#liveurl#","#json#","#redirect_url#","#order_id#");
            $values = array($this->liveurl,$json,$redirect_url,$order_id);

            $html = str_replace($keys,$values,$checkout_html);

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

            if ( version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=' ) )
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
                
                // storing every order's id and razorpay_payment_id in the database
                global $wpdb;
                $table_name = $wpdb->prefix . "subscription";
                $this->create_table($table_name);
                $this->insert_payment_into_table($order_id, $razorpay_payment_id);            
                
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

        function process_subscription($subscription_id)
        {
            // Tools -> Scheduled Actions, trigger manually
            $subscription = wcs_get_subscription($subscription_id);
            $order_id = $subscription->order->id; // using this we can get the payment_id stored
            $order = new WC_Order($order_id);

            $razorpay_payment_id = $this->get_payment_from_table($order_id);

            $api = new Api($this->key_id, $this->key_secret);

            $payment = $api->payment->fetch($razorpay_payment_id);

            // payment has all our fields -> customer_id, token_id, email, contact, add recurring = 1
            $token_id = $payment['token_id'];

            // All the fields have the right values
            $recurring_args = array(
                'email' => $payment['email'],
                'contact' => $payment['contact'],
                'currency' => $payment['currency'],
                'amount' => (int)WC_Subscriptions_Order::get_recurring_total($order)*100, // in paise
                'customer_id' => $payment['customer_id'],
                'token' => $payment['token_id'],
                'recurring' => 1
            );

            // make server to server call and then order status updated, using payment->createRecurring
            try
            {
                $url = 'https://api.razorpay.com/v1/payments/create/recurring';
                $options = array('auth' => array($this->key_id, $this->key_secret));

                $response = Requests::post($url, array(), $recurring_args, $options);

                // subscription payment received. Capture the payment and update order state on woocommerce
                WC_Subscriptions_Manager::process_subscription_payments_on_order( $order );
            }

            catch (Exception $e) {}
        }

        function create_table($table_name)
        {
            global $wpdb;
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
              id mediumint(9) NOT NULL AUTO_INCREMENT,
              order_id mediumint(9) NOT NULL,
              payment_id TEXT NOT NULL,
              PRIMARY KEY (id)
            ) $charset_collate;";

            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta( $sql );
        }

        function insert_payment_into_table($order_id, $razorpay_payment_id)
        {
            // adding each order into the database and pulling out the ones that are subscriptions later on in the code
            global $wpdb;

            $table_name = $wpdb->prefix . "subscription";
            
            $wpdb->insert(
                    $table_name,
                    array(
                        'order_id' => $order_id,
                        'payment_id' => $razorpay_payment_id
                    )
                );
        }

        function get_payment_from_table($order_id)
        {
            global $wpdb;

            $table_name = $wpdb->prefix . "subscription";
            // We find the payment ID associated with this order id
            $query = "SELECT * FROM $table_name WHERE order_id = $order_id";
            $results = $wpdb->get_results($query);

            $razorpay_payment_id = array_values($results)[0]->payment_id;

            return $razorpay_payment_id;
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
    new RZP_Webhook();
}