<?php
/*
Plugin Name: WooCommerce Razorpay Payments
Plugin URI: https://razorpay.com
Description: Razorpay Payment Gateway Integration for WooCommerce
Version: 1.2.4
Author: Razorpay
Author URI: https://razorpay.com
*/

add_action('plugins_loaded', 'woocommerce_razorpay_init', 0);

function woocommerce_razorpay_init(){
    if(!class_exists('WC_Payment_Gateway')) return;
 
    class WC_Razorpay extends WC_Payment_Gateway{
        public function __construct(){
            $this->id = 'razorpay';
            $this->method_title = 'Razorpay';
            $this->icon =  plugins_url('images/logo.jpg' , __FILE__ );
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->key_id = $this->settings['key_id'];
            $this->key_secret = $this->settings['key_secret'];
            $this->liveurl = 'https://checkout.razorpay.com/v1/checkout.js';

            $this->msg['message'] = "";
            $this->msg['class'] = "";

            add_action('init', array(&$this, 'check_razorpay_response'));
            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_razorpay_response'));

            if ( version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                    add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
                 } else {
                    add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
                }
            add_action('woocommerce_receipt_razorpay', array(&$this, 'receipt_page'));
        }

        function init_form_fields(){
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'razorpay'),
                    'type' => 'checkbox',
                    'label' => __('Enable Razorpay Payment Module.', 'razorpay'),
                    'default' => 'yes'),
                'title' => array(
                    'title' => __('Title:', 'razorpay'),
                    'type'=> 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'razorpay'),
                    'default' => __('Credit Card/Debit Card/Net banking', 'razorpay')),
                'description' => array(
                    'title' => __('Description:', 'razorpay'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'razorpay'),
                    'default' => __('Pay securely by Credit or Debit card or internet banking through Razorpay.', 'razorpay')),
                'key_id' => array(
                    'title' => __('Key ID', 'razorpay'),
                    'type' => 'text',
                    'description' => __('The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.', 'razorpay')),
                'key_secret' => array(
                    'title' => __('Key Secret', 'razorpay'),
                    'type' => 'text',
                    'description' =>  __('The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.', 'razorpay'),
                )
            );
        }
 
        public function admin_options(){
            echo '<h3>'.__('Razorpay Payment Gateway', 'razorpay').'</h3>';
            echo '<p>'.__('Razorpay is an online payment gateway for India with transparent pricing, seamless integration and great support').'</p>';
            echo '<table class="form-table">';
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
        }

        /**
         *  There are no payment fields, but we want to show the description if set.
         **/
        function payment_fields(){
            if($this->description) echo wpautop(wptexturize($this->description));
        }
        
        /**
         * Receipt Page
         **/
        function receipt_page($order){
            echo '<p>'.__('Thank you for your order, please click the button below to pay with Razorpay.', 'razorpay').'</p>';
            echo $this->generate_razorpay_form($order);
        }
    
        /**
         * Generate razorpay button link
         **/
        public function generate_razorpay_form($order_id){
     
            global $woocommerce;
     
            $order = new WC_Order($order_id);
            
            $redirect_url = get_site_url() . '/?wc-api' ."=". get_class($this) ;
     
            $productinfo = "Order $order_id";

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
              )
            );

            $json = json_encode($razorpay_args);

            $html = <<<EOT
<script src="{$this->liveurl}"></script>
<script>
    var data = $json;
</script>
<form name='razorpayform' action="$redirect_url" method="POST">
    <input type="hidden" name="merchant_order_id" value="$order_id">
    <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id">
</form>
<script>
    data.backdropClose = false;
    data.handler = function(payment){
      document.getElementById('razorpay_payment_id').value = 
        payment.razorpay_payment_id;
      document.razorpayform.submit();
    };
    var razorpayCheckout = new Razorpay(data);
    razorpayCheckout.open();
</script>
<p>
<button id="btn-razorpay" onclick="razorpayCheckout.open();">Pay Now</button>
<button onclick="document.razorpayform.submit()">Cancel</button>
</p>

EOT;
            return $html;
        }

        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id){
            $order = new WC_Order($order_id);

            if ( version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('order', $order->id,
                        add_query_arg('key', $order->order_key, $order->get_checkout_payment_url(true)))
                );
            }
            else {
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
        function check_razorpay_response(){
            global $woocommerce;

            if(isset($_REQUEST['merchant_order_id']) && isset($_REQUEST['razorpay_payment_id'])){
                $order_id = $_REQUEST['merchant_order_id'];
                $razorpay_payment_id = $_REQUEST['razorpay_payment_id'];
                

                $order = new WC_Order($order_id);
                $key_id = $this->key_id;
                $key_secret = $this->key_secret;
                $amount = $order->order_total*100;

                $success = false;
                $error = "";

                try {
                    $url = 'https://api.razorpay.com/v1/payments/'.$razorpay_payment_id.'/capture';
                    $fields_string="amount=$amount";

                    //cURL Request
                    $ch = curl_init();

                    //set the url, number of POST vars, POST data
                    curl_setopt($ch,CURLOPT_URL, $url);
                    curl_setopt($ch,CURLOPT_USERPWD, $key_id . ":" . $key_secret);
                    curl_setopt($ch,CURLOPT_TIMEOUT, 60);
                    curl_setopt($ch,CURLOPT_POST, 1);
                    curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
                    curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch,CURLOPT_CAINFO, plugin_dir_path(__FILE__) . 'ca-bundle.crt');

                    //execute post
                    $result = curl_exec($ch);
                    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);


                    if($result === false) {
                        $success = false;
                        $error = 'Curl error: ' . curl_error($ch);
                    }
                    else {
                        $response_array = json_decode($result, true);
                        //Check success response
                        if($http_status === 200 and isset($response_array['error']) === false){
                            $success = true;    
                        }
                        else {
                            $success = false;

                            if(!empty($response_array['error']['code'])) {
                                $error = $response_array['error']['code'].":".$response_array['error']['description'];
                            }
                            else {
                                $error = "RAZORPAY_ERROR:Invalid Response <br/>".$result;
                            }
                        }
                    }
                    //close connection
                    curl_close($ch);
                }
                catch (Exception $e) {
                    $success = false;
                    $error ="WOOCOMMERCE_ERROR:Request to Razorpay Failed";
                }

                if($success === true){
                    $this->msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon. Order Id: ".$order_id;
                    $this->msg['class'] = 'success';
                    $order->payment_complete();
                    $order->add_order_note('Razorpay payment successful <br/>Razorpay Id: '.$razorpay_payment_id);
                    $order->add_order_note($this->msg['message']);
                    $woocommerce->cart->empty_cart();
                }
                else{
                    $this->msg['class'] = 'error';
                    $this->msg['message'] = "Thank you for shopping with us. However, the payment failed.";
                    $order->add_order_note('Transaction Declined: '.$error);
                    $order->add_order_note('Payment Failed. Please check Razorpay Dashboard. <br/> Razorpay Id:'.$razorpay_payment_id);
                    $order->update_status('failed');
                }                
            }
            else {
                $this->msg['class'] = 'error';
                $this->msg['message'] = "An Error occured";
            }

            if (function_exists('wc_add_notice')) {
                wc_add_notice( $this->msg['message'], $this->msg['class'] );
            }
            else {
                if($this->msg['class']=='success'){
                    $woocommerce->add_message($this->msg['message']);
                }
                else{
                    $woocommerce->add_error($this->msg['message']);

                }
                $woocommerce->set_messages();
            }
            
            $redirect_url = get_permalink(woocommerce_get_page_id('myaccount'));
            wp_redirect( $redirect_url );
            exit;
        }
    }

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_razorpay_gateway($methods) {
        $methods[] = 'WC_Razorpay';
        return $methods;
    }
 
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_razorpay_gateway' );
}
