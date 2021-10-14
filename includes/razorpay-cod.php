<?php

class Razorpay_COD
{
    /**
     * @param boolean $hooks Whether or not to
     *                       setup the hooks on
     *                       calling the constructor
     */
    public function __construct($hooks = true)
    {
        $this->razopray = new WC_Razorpay(false);

        // TODO: This is hacky, find a better way to do this
        // See mergeSettingsWithParentPlugin() in subscriptions for more details.
        if ($hooks)
        {
            $this->initCodHooks();
        }
    }

    protected function initCodHooks()
    {
        // Adding Meta container admin shop_order pages
        add_action( 'add_meta_boxes', array($this, 'add_rzp_meta_delivery_date') );

        // Save the data of the Meta field
        add_action( 'save_post', array($this, 'save_rzp_delivery_date_field'), 10, 1 );

        // Display field value on the order edit page (not in custom fields metabox)
        add_action( 'woocommerce_admin_order_data_after_billing_address', array($this, 'custom_rzp_delivery_date_field_display_admin_order_meta'), 10, 1 );

        // ADDING NEW COLUMNS WITH THEIR TITLES
        add_filter( 'manage_edit-shop_order_columns', array($this, 'custom_rzp_shop_order_column'), 20 );

        // Adding custom fields meta data for each new column (example)
        add_action( 'manage_shop_order_posts_custom_column' , array($this, 'custom_rzp_orders_list_column_content'), 20, 2 );

        add_action('woocommerce_api_plink_' . $this->razopray->id, array($this, 'check_razorpay_plink_response'));

        add_action('razorpay_delivery_date_set', array($this,'enroll_plink'), 10, 1);

        add_filter( 'woocommerce_endpoint_order-received_title', array($this, 'plink_thank_you_title'), 10, 1 );

        add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'plink_order_received_text' ), 10, 2 );

    }

    public function add_rzp_meta_delivery_date()
    {
        add_meta_box( 'mv_other_fields', __('Delivery Date','woocommerce'), array($this,'add_rzp_delivery_date_for_order'), 'shop_order', 'side', 'core' );
    }
    
    // Adding Meta field in the meta container admin shop_order pages

    public function add_rzp_delivery_date_for_order()
    {
        global $post;

        $rzp_delivery_date = get_post_meta( $post->ID, '_rzp_delivery_date_slug', true ) ? get_post_meta( $post->ID, '_rzp_delivery_date_slug', true ) : '';

        $convertRzpDeliveryDate = $this->convertRzpDeliveryDate($rzp_delivery_date);

        echo '<input type="hidden" name="delivery_date_nonce" value="' . wp_create_nonce() . '">
              <input type="text" name="delivery_date" class="date-picker add_delivery_date" placeholder="' . $convertRzpDeliveryDate['date'] . '"  value="' . $convertRzpDeliveryDate['date'] . '" >
                
                <p>
                @
                <input type="number" class="hour" placeholder="'.$convertRzpDeliveryDate['hour'].'" name="delivery_date_hour" min="0" max="23" style="width: 4.5em;" step="1" value="'.$convertRzpDeliveryDate['hour'].'" pattern="([01]?[0-9]{1}|2[0-3]{1})"> :
                <input type="number" class="minute" style="width: 4.5em;" placeholder="'.$convertRzpDeliveryDate['minute'].'" name="delivery_date_minute" min="0" max="59" step="1" value="'.$convertRzpDeliveryDate['minute'].'" pattern="([01]?[0-9]{1}|2[0-3]{1})"> ';
    }

    public function save_rzp_delivery_date_field( $post_id ) 
    {
        if($_POST[ 'delivery_date' ] != 'Delivery Date')
        {
            global $woocommerce;
            $order = wc_get_order($post_id);

            // We need to verify this with the proper authorization (security stuff).

            // Check if our nonce is set.
            if ( ! isset( $_POST[ 'delivery_date_nonce' ] ) ) 
            {
                return $post_id;
            }
            $nonce = $_REQUEST[ 'delivery_date_nonce' ];

            //Verify that the nonce is valid.
            if ( ! wp_verify_nonce( $nonce ) ) 
            {
                return $post_id;
            }

            // Check the user's permissions.
            if ( 'page' == $_POST[ 'post_type' ] ) 
            {
                if ( ! current_user_can( 'edit_page', $post_id ) ) 
                {
                    return $post_id;
                }
            } 
            else 
            {
                if ( ! current_user_can( 'edit_post', $post_id ) ) 
                {
                    return $post_id;
                }
            }

            $rzp_delivery_date_meta = get_post_meta( $post_id, '_rzp_delivery_date_slug', true );

            $hour = ($_POST[ 'delivery_date_hour' ] != null)? $_POST[ 'delivery_date_hour' ]: '00';
            $minute = ($_POST[ 'delivery_date_minute' ] != null)? $_POST[ 'delivery_date_minute' ]: '00';

            $new_delivery_date = $_POST[ 'delivery_date' ].' '. $hour.':'. $minute;
            
            // Will add the notes only if the value got updated.
            if(strtotime($rzp_delivery_date_meta) != strtotime($new_delivery_date))
            {
                // --- Its safe for us to save the data ! --- //

                // Sanitize user input  and update the meta field in the database.
                update_post_meta( $post_id, '_rzp_delivery_date_slug', $new_delivery_date );

                $order->add_order_note("Delivery Date is updated: $new_delivery_date<br/>");

                do_action( 'razorpay_delivery_date_set', $post_id );

            }
        }
    }  


    public function custom_rzp_delivery_date_field_display_admin_order_meta($order)
    {
        // To show rzp delivery date
        $rzp_delivery_date_custom_field = get_post_meta( $order->get_id(), '_rzp_delivery_date_slug', true );

        if ( ! empty( $rzp_delivery_date_custom_field ) ) 
        {
            ?><p class="form-field form-field-wide wc-customer-user"><label class="order_delivery_column"><b><?php _e( "Delivery Date:" ); ?></b></label><p>
                    <?php echo $rzp_delivery_date_custom_field;
        }

        // To show rzp payment link
        $rzp_payment_link_custom_field = get_post_meta( $order->get_id(), '_rzp_payment_Link', true );

        if ( ! empty( $rzp_payment_link_custom_field ) ) 
        {
            ?><p class="form-field form-field-wide wc-customer-user"><label class="order_rzp_payment_link_column"><b><?php _e( "Razorpay Payment Link:" ); ?></b></label><p>
                    <?php echo $rzp_payment_link_custom_field;
        }
    }

    public function custom_rzp_shop_order_column($columns)
    {
        $reordered_columns = array();

        // Inserting columns to a specific location
        foreach( $columns as $key => $column)
        {
            $reordered_columns[$key] = $column;
            if( $key ==  'order_status' )
            {
                // Inserting after "Status" column
                $reordered_columns['delivery_date'] = __( 'Delivery Date','theme_domain');
            }
        }
        return $reordered_columns;
    }

    public function custom_rzp_orders_list_column_content( $column, $post_id )
    {
        switch ( $column )
        {
            case 'delivery_date' :
                // Get custom post meta data
                $rzp_delivery_date = get_post_meta( $post_id, '_rzp_delivery_date_slug', true );
                $convertRzpDeliveryDate = $this->convertRzpDeliveryDate($rzp_delivery_date);

                $rzp_delivery_date_col = $convertRzpDeliveryDate['date'];
                if(!empty($rzp_delivery_date))
                {
                    $time = new DateTime($rzp_delivery_date_col);
                    echo $time->format('F j, Y');
                }

                break;
        }
    }

    protected function convertRzpDeliveryDate($rzp_delivery_date)
    {
        if(empty($rzp_delivery_date) === false)
        {
            $time = new DateTime($rzp_delivery_date);
            $date = $time->format('Y-n-j');
            $hour = $time->format('H');
            $minute = $time->format('i');
        }
        
        $date = !empty($date)? $date: 'Delivery Date';
        $hour = !empty($hour)? $hour: 'h';
        $minute = !empty($minute)? $minute: 'm';


        return $response = [
            'date' => $date,
            'hour' => $hour,
            'minute' => $minute
        ];
    }

    //Plink code
    function plink_thank_you_title($thank_you_title)
    {
        if(isset($_GET['plink']) and
            ($_GET['plink'] === 'thanks'))
        {
            return 'Your COD order Payments through Razorpay Payments, Successful.';
        }
    }

    //Plink code
    public function plink_order_received_text($text, $order)
    {
        global $wp;

        if ($order and
            isset($_GET['plink']) and
            ($_GET['plink'] === 'thanks'))
        {
            $paymentDetails = '<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
                <li class="woocommerce-order-overview__order order">
                RAZORPAY PaymentId: <strong>' . $order->get_transaction_id() . '</strong>
                    </li>
                </ul>';

           return esc_html__( 'Thank you for your payment with Razorpay. Your transaction has been completed, and a receipt for your purchase has been emailed to you.' , 'woocommerce' ) . $paymentDetails;
        }

        return $text;
    }

    /**
     * Returns redirect URL get payment processing for Plink
     * @return string redirect URL
     */
    private function getPlinkRedirectUrl()
    {
        return add_query_arg( 'wc-api', 'plink_' . $this->razopray->id, trailingslashit( get_home_url() ) );
    }

    //Plink code
    function enroll_plink($order_id)
    {

        if ( ! $order_id )
            return;

        // Getting an instance of the order object
        $order = wc_get_order( $order_id );

        file_put_contents("maas1.log", print_r($order,1));

        if($order->get_status() === 'processing')
        {
            $order->update_status( 'pending' );
        }

        $api = $this->razopray->getRazorpayApiInstance();

        $billingEmail = $order->get_billing_email();

        $billingPhone = $order->get_billing_phone();

        $callbackUrl = $this->getPlinkRedirectUrl();

        //create the plink
        try
        {
            $pLink = $order->get_meta('_rzp_payment_Link',true);

            if(empty($pLink) === false)
            {
                $link = $api->paymentLink->fetch($pLink)
                                         ->edit([
                                            "expire_by" => strtotime($order->get_meta('_rzp_delivery_date_slug',true)),
                                            "notes" => [
                                                "expire_updated" => strtotime($order->get_meta('_rzp_delivery_date_slug',true)),
                                                "woo_order_id" => $order_id
                                            ]
                                         ]);
            }
            else
            {

                $link = $api->paymentLink->create([
                    'amount' => (int) round($order->get_total() * 100),
                    'description' => 'Pay for ' . get_bloginfo('name') . ' Order #'.$order_id,
                    'reference_id' => "$order_id",
                    'currency' => $this->razopray->getOrderCurrency($order),
                    'customer' => [
                        'email'     => $billingEmail,
                        'contact'   => $billingPhone
                    ],
                    "notify" => [
                        "sms"   => true,
                        "email" =>true
                    ],
                    "reminder_enable"   => true,
                    "expire_by" => strtotime($order->get_meta('_rzp_delivery_date_slug',true)),
                    "callback_url" => $callbackUrl,
                    "callback_method" => "get"
                ]); // create payment link

                update_post_meta($order->get_id(),'_rzp_payment_Link', $link->id);
            }
        }
        catch(Exception $e)
        {
            return new WP_Error('error', __($e->getMessage(), 'woocommerce'));
        }
    }

    //Plink code
    function check_razorpay_plink_response()
    {
        $order = new WC_Order(sanitize_text_field($_GET['razorpay_payment_link_reference_id']));

        if (empty($order) === true)
            return;

        //
        // If the order has already been paid for
        // redirect user to success page
        //
        if ($order->needs_payment() === false)
        {
            $pLink = $order->get_meta('_rzp_payment_Link',true);

            if(empty($pLink) === false)
            {
                $this->redirectUser($order,'&plink=thanks');
            }
            else
            {
                $this->redirectUser($order);
            }
        }


        $attributes = [
            'razorpay_payment_id' => $_GET['razorpay_payment_id'],
            'razorpay_payment_link_id' => sanitize_text_field($order->get_meta('_rzp_payment_Link',true)),
            'razorpay_payment_link_reference_id' => $_GET['razorpay_payment_link_reference_id'],
            'razorpay_payment_link_status' => $_GET['razorpay_payment_link_status'],
            'razorpay_signature' => $_GET['razorpay_signature']
        ];

        $error = "";

        $razorpayPaymentId = null;

        try
        {
            $api = $this->razopray->getRazorpayApiInstance();

            $api->utility->verifyPaymentSignature($attributes);

            $success = true;

            $razorpayPaymentId = sanitize_text_field($_GET[WC_Razorpay::RAZORPAY_PAYMENT_ID]);
        }
        catch (Errors\SignatureVerificationError $e)
        {
            $error = 'WOOCOMMERCE_ERROR: Payment to Razorpay Links Failed. ' . $e->getMessage();
        }

        $this->razopray->updateOrder($order, $success, $error, $razorpayPaymentId, null);

        $this->redirectUser($order,'&plink=thanks');
    }

    protected function redirectUser($order, $extraPrams = null)
    {
        $redirectUrl = $this->razopray->get_return_url($order);

        wp_redirect($redirectUrl . $extraPrams);
        exit;
    }

}
?>