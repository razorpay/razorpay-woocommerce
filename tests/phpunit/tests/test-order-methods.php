<?php
/**
 * @covers \WC_Razorpay
 */

require_once __DIR__ . '/../mockfactory/MockApi.php';
require_once __DIR__ . '/../mockfactory/Request.php';
require_once __DIR__ . '/../mockfactory/Order.php';

use Razorpay\MockApi\MockApi;

class Test_OrderMethods extends WP_UnitTestCase
{
    private $instance;
    private $rzpPaymentObj;
    
    public function setup(): void
    {
        parent::setup();

        $this->rzpPaymentObj = new WC_Razorpay();

        $this->instance = Mockery::mock('WC_Razorpay')->makePartial()->shouldAllowMockingProtectedMethods();

        $_POST = array();
    }

    public function testnewUserAccount()
    {
        $order = wc_create_order();

        $shippingAddress = (object)array('name' => 'ABC');
        $shippingAddress = (object)array('line1'=> 'SJR Cyber Laskar');
        $shippingAddress = (object)array('line2' => 'Hosur Rd, Adugodi');
        $shippingAddress = (object)array('city' => 'Bengaluru');
        $shippingAddress = (object)array('country' => strtoupper('India'));
        $shippingAddress = (object)array('postcode' => '560030');
        $shippingAddress = (object)array('state' =>strtoupper('Karnataka'));

        $shippingState = strtoupper($shippingAddress->state);
        $shippingStateName = str_replace(" ", '', $shippingState);
        $shippingStateCode = getWcStateCodeFromName($shippingStateName);
        
        $razorpayData = array('customer_details' => array('email' => 'abc.xyz@razorpay.com', 'contact' => '18001231272','shipping_address' => $shippingAddress));
       
        add_option('woocommerce_razorpay_settings',array('1cc_account_creation'=> 'yes'));

        $this->instance->newUserAccount($razorpayData,$order);

        $current_user = get_user_by( 'email', 'abc.xyz@razorpay.com' );
        $userID = $current_user->data->ID;

        $this->assertNotNull(get_post_meta($order->get_id(), '_customer_user', $userId));

        $this->assertSame('abc.xyz@razorpay.com', get_user_meta($userID,'shipping_email',$email)[0]);

        $this->assertSame('18001231272', get_user_meta($userID,'shipping_phone',$contact)[0]);

        $this->assertSame($shippingAddress->name, get_user_meta($userID, 'shipping_first_name', $shippingAddress->name )[0]);

        $this->assertSame($shippingAddress->line1, get_user_meta($userID, 'shipping_first_name', $shippingAddress->line1 )[0]);

        $this->assertSame($shippingAddress->line2, get_user_meta($userID, 'shipping_first_name', $shippingAddress->line2 )[0]);

        $this->assertSame($shippingAddress->city, get_user_meta($userID, 'shipping_first_name', $shippingAddress->city )[0]);

        $this->assertSame($shippingAddress->country, get_user_meta($userID, 'shipping_first_name', $shippingAddress->country )[0]);

        $this->assertSame($shippingAddress->zipcode, get_user_meta($userID, 'shipping_first_name', $shippingAddress->zipcode )[0]);

        $this->assertSame($shippingAddress->name, get_user_meta($userID, 'billing_first_name', $shippingAddress->name )[0]);

        $this->assertSame($shippingAddress->line1, get_user_meta($userID, 'billing_first_name', $shippingAddress->line1 )[0]);

        $this->assertSame($shippingAddress->line2, get_user_meta($userID, 'billing_first_name', $shippingAddress->line2 )[0]);

        $this->assertSame($shippingAddress->city, get_user_meta($userID, 'billing_first_name', $shippingAddress->city )[0]);

        $this->assertSame($shippingAddress->country, get_user_meta($userID, 'billing_first_name', $shippingAddress->country )[0]);

        $this->assertSame($shippingAddress->zipcode, get_user_meta($userID, 'billing_first_name', $shippingAddress->zipcode )[0]);
    } 

    public function testverifyOrderAmount()
    {
        $order = wc_create_order();
        
        $wcOrderId = $order->get_id();

        
        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
            });

        set_transient("razorpay_order_id".$wcOrderId, "razorpay_order_id", 18000);

        $razorpayOrderId = get_transient("razorpay_order_id".$wcOrderId);

        $response = $this->instance->verifyOrderAmount($razorpayOrderId,$wcOrderId);
        
        $this->assertSame(true,$response);
    }
}