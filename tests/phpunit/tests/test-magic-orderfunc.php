<?php
/**
 * @covers \WC_Razorpay
 */


require_once __DIR__ . '/../mockfactory/MockApi.php';
require_once __DIR__ . '/../mockfactory/Request.php';
require_once __DIR__ . '/../mockfactory/order.php';

use Razorpay\MockApi\MockApi;

class Test_Magic_Orderfunc extends WP_UnitTestCase
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

    public function testupdateUserAddressInfo()
    {
        $order = wc_create_order();

        $order = wc_create_order(array('customer_id'=>189));

        $shippingAddress = [];

        $shippingAddress['first_name'] = 'Garima';
        $shippingAddress['address_1'] = 'Rolex Estate';
        $shippingAddress['address_2'] = 'Kamta';
        $shippingAddress['city'] = 'Lucknow';
        $shippingAddress['country'] = strtoupper('India');
        $shippingAddress['postcode'] = '226010';
        $shippingAddress['email'] = 'abc.xyz@razorpay.com';
        $shippingAddress['phone'] = '9012345678';

        $shippingState = strtoupper('Uttar Pradesh');
        $shippingStateName = str_replace(" ", '', $shippingState);
        $shippingStateCode = getWcStateCodeFromName($shippingStateName);

        $order->set_shipping_state($shippingStateCode);

        $this->instance->updateUserAddressInfo('shipping_',$shippingAddress,$shippingStateCode, $order);

        $firstName = get_user_meta($order->get_user_id(),'shipping_first_name')[0];
        $address_1 = get_user_meta($order->get_user_id(),'shipping_address_1')[0];
        $address_2 = get_user_meta($order->get_user_id(),'shipping_address_2')[0];
        $country = get_user_meta($order->get_user_id(),'shipping_country')[0];
        $postcode = get_user_meta($order->get_user_id(),'shipping_postcode')[0];
        $email = get_user_meta($order->get_user_id(),'shipping_email')[0];
        $phone = get_user_meta($order->get_user_id(),'shipping_phone')[0];
        $city = get_user_meta($order->get_user_id(),'shipping_city')[0];
        $state = get_user_meta($order->get_user_id(),'shipping_state')[0];

        $this->assertSame($shippingAddress['first_name'], $firstName);

        $this->assertSame($shippingAddress['address_1'], $address_1);

        $this->assertSame($shippingAddress['address_2'], $address_2);

        $this->assertSame($shippingAddress['country'], $country);

        $this->assertSame($shippingAddress['postcode'], $postcode);

        $this->assertSame($shippingAddress['email'], $email);

        $this->assertSame($shippingAddress['phone'], $phone);

        $this->assertSame($shippingAddress['city'], $city);

        $this->assertSame($shippingStateCode, $state);
    }

    public function testnewUserAccount()
    {
        $order = wc_create_order();

        $shippingAddress = (object)array('name' => 'Garima');
        $shippingAddress = (object)array('line1'=> 'Rolex Estate');
        $shippingAddress = (object)array('line2' => 'Kamta');
        $shippingAddress = (object)array('city' => 'Lucknow');
        $shippingAddress = (object)array('country' => strtoupper('India'));
        $shippingAddress = (object)array('postcode' => '226010');
        $shippingAddress = (object)array('state' =>strtoupper('Uttar Pradesh'));

        $shippingState = strtoupper($shippingAddress->state);
        $shippingStateName = str_replace(" ", '', $shippingState);
        $shippingStateCode = getWcStateCodeFromName($shippingStateName);
        
        $razorpayData = array('customer_details' => array('email' => 'abc.xyz@razorpay.com', 'contact' => '9012345678','shipping_address' => $shippingAddress));
       
        add_option('woocommerce_razorpay_settings',array('1cc_account_creation'=> 'yes'));

        $this->instance->newUserAccount($razorpayData,$order);

        $current_user = get_user_by( 'email', 'abc.xyz@razorpay.com' );
        $userID = $current_user->data->ID;

        $this->assertNotNull(get_post_meta($order->get_id(), '_customer_user', $userId));

        $this->assertSame('abc.xyz@razorpay.com', get_user_meta($userID,'shipping_email',$email)[0]);

        $this->assertSame('9012345678', get_user_meta($userID,'shipping_phone',$contact)[0]);

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


    public function testgetShippingZone(){

        $zone_data = new stdClass();
        $zone_data->zone_name = 'test';

        $zone_obj = new WC_Shipping_Zone( $zone_data );
        $zone_obj->save();
        $zone_id = $zone_obj->get_id();

        $response = $this->instance->getShippingZone($zone_id);
        $this->assertNotNull($response);

    }

    public function testgetDefaultCheckoutArguments()
    {
        $order = wc_create_order();
        
        $orderId = $order->get_order_number();
        
        $wcOrderId = $order->get_id();
        
        $desc = "Order $orderId";
        
        $notes = array('woocommerce_order_id'=> $orderId, 'woocommerce_order_number'=> $wcOrderId );
       
        $sessionKey = "razorpay_order_id".$orderId;
        
        $razorpayOrderId =get_transient($sessionKey);

        $address = array(
            'first_name' => 'magic',
            'last_name'  => 'checkout',
            'company'    => 'Razorpay',
            'email'      => 'magic@razorpay.com',
            'phone'      => '760-555-1212',
            'address_1'  => 'test',
            'address_2'  => 'test',
            'city'       => 'Bangalore',
            'state'      => 'Karnataka',
            'postcode'   => '560001',
            'country'    => 'Bangalore'
        );
        
        $order->set_address( $address, 'billing' );

        $args = array(
            'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'email'   => $order->get_billing_email(),
            'contact' => $order->get_billing_phone(),
        );

        $this->instance->expects($this->once())->method('getRedirectUrl')->with($orderId)->andReturn($this->returnValue($callbackUrl));
        
        $this->instance->shouldReceive('getOrderSessionKey')->with($orderId)->andReturn($sessionKey);

        $response = $this->instance->getDefaultCheckoutArguments($order);

        $this->assertNotNull($response['name']);
        
        $this->assertNotEmpty($response['callback_url']);
        
        $this->assertSame('INR',$response['currency']);
        
        $this->assertSame($desc,$response['description']);
        
        $this->assertSame($notes,$response['notes']);
        
        $this->assertSame($razorpayOrderId,$response['order_id']);
        
        $this->assertSame($args,$response['prefill']);
    }

    public function testupdateOrderAddress()
    {

        $order = wc_create_order();

        $wcOrderId = $order->get_id();

        $shippingAddress['name'] = 'magic';
        $shippingAddress['line1'] = 'checkout';
        $shippingAddress['line2'] = 'Kamta';
        $shippingAddress['city'] = 'Bangalore';
        $shippingAddress['country'] = 'India';
        $shippingAddress['postcode'] = '560001';
        $shippingAddress['state'] = 'Karnataka';

        $shippingState = strtoupper($shippingAddress->state);
        $shippingStateName = str_replace(" ", '', $shippingState);
        $shippingStateCode = getWcStateCodeFromName($shippingStateName);

         $razorpayData = array('customer_details' => array('email' => 'abc.xyz@razorpay.com', 'contact' => '9012345678','shipping_address' => $shippingAddress));

        $this->instance->updateOrderAddress($razorpayData, $order);
        
        $firstName = get_user_meta($order->get_user_id(),'shipping_first_name')[0];
        $address_1 = get_user_meta($order->get_user_id(),'shipping_address_1')[0];
        $address_2 = get_user_meta($order->get_user_id(),'shipping_address_2')[0];
        $country = get_user_meta($order->get_user_id(),'shipping_country')[0];
        $postcode = get_user_meta($order->get_user_id(),'shipping_postcode')[0];
        $email = get_user_meta($order->get_user_id(),'shipping_email')[0];
        $phone = get_user_meta($order->get_user_id(),'shipping_phone')[0];
        $city = get_user_meta($order->get_user_id(),'shipping_city')[0];
        $state = get_user_meta($order->get_user_id(),'shipping_state')[0];

        $this->assertSame($shippingAddress['first_name'], $firstName);

        $this->assertSame($shippingAddress['address_1'], $address_1);

        $this->assertSame($shippingAddress['address_2'], $address_2);

        $this->assertSame($shippingAddress['email'], $email);

        $this->assertSame($shippingAddress['phone'], $phone);
    }

    public function testorderArg1CC(){

        $product = new WC_Product_Simple();

        $product->set_name( 'test' ); // product title

        $product->set_slug( 'medium-size-wizard-hat-in-new-york' );

        $product->set_regular_price( 500 ); // in current shop currency
        //$product->set_sale_price( 200 ); // in current shop currency

        $product->set_description( 'test' );
        $product->save();

        $orders = wc_create_order();

        //$order->add_product( wc_get_product( 136 ), 2 );
        $orders->add_product( wc_get_product( $product->get_id() ), 1 );
        $orders->calculate_totals();
        
        

        $response = $this->instance->orderArg1CC($data, $orders);

        foreach ( $orders->get_items() as $item_id => $item )
        {
           $product = $item->get_product();
           $productDetails = $product->get_data();

            $data = array(
            'type' => 'e-commerce',
            'sku'  => $product->get_sku(),
            'variant_id'    => $item->get_variation_id(),
            'price'      => (empty($productDetails['price'])=== false) ? round(wc_get_price_excluding_tax($product)*100) + round($item->get_subtotal_tax()*100 / $item->get_quantity()) : 0,
            'offer_price'      => (empty($productDetails['sale_price'])=== false) ? (int) $productDetails['sale_price']*100 : $productDetails['price']*100,
            'quantity'  => (int)$item->get_quantity(),
            'name'  => mb_substr($item->get_name(), 0, 125, "UTF-8"),
            'description'       => mb_substr($item->get_name(), 0, 250,"UTF-8"),
            'image_url'      => $product->get_image_id()?? null,
            'product_url'   => $product->get_permalink()
          );

        }

        foreach ( $response['line_items'] as $res )
        {
            $this->assertSame($data['price'], $res['price']);
            $this->assertSame($data['offer_price'], $res['offer_price']);
            $this->assertSame($data['quantity'], $res['quantity']);
            $this->assertNotNull($res['name']);
            $this->assertNotNull($res['description']);
            $this->assertSame($data['type'], $res['type']);
            $this->assertSame($data['sku'], $res['sku']);
              
            $this->assertSame($data['product_url'], $res['product_url']);
        }

        
    }  

}

?>