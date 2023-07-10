<?php
/**
 * @covers \RZP_Route_Action
 * @covers ::woocommerce_razorpay_init
 * @covers ::addRouteModuleSettingFields
 */

use Razorpay\MockApi\MockApi;

class Test_RzpRouteAction extends \PHPUnit_Framework_TestCase
{
    private $instance;

    public function setup(): void
    {
        parent::setup();
        $this->instance = Mockery::mock('RZP_Route_Action')->makePartial()->shouldAllowMockingProtectedMethods();
    }

    public function testReverseTransfer()
    {

        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
        });

        $_POST['transfer_id']       = 'test';
        $_POST['reversal_amount']   = 12;

        $pageUrl = admin_url('admin.php?page=razorpayTransfers&id=' . 'test');
        $this->instance->shouldReceive('redirect')->with($pageUrl);

        $response = $this->instance->reverseTransfer();

        $this->assertNull($response);
    }

    public function testDirectTransfer()
    {

        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
        });

        $_POST['drct_trf_account']  = 'test';
        $_POST['drct_trf_amount']   = '123';

        $pageUrl = admin_url('admin.php?page=razorpayRouteWoocommerce');
        $this->instance->shouldReceive('redirect')->with($pageUrl);

        $response = $this->instance->directTransfer();

        $this->assertNull($response);
    }

    public function testUpdateTransferSettlement()
    {

        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
        });

        $_POST['transfer_id']   = 'test';
        $_POST['on_hold']       = 'on_hold_until';
        $_POST['hold_until']    = 1666097548;

        $pageUrl            = admin_url('admin.php?page=razorpayTransfers&id=' . 'test');
        $this->instance->shouldReceive('redirect')->with($pageUrl);

        $response = $this->instance->updateTransferSettlement();

        $this->assertNull($response);
    }

    public function testCreatePaymentTransfer()
    {

        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
        });

        $_POST['payment_id']        = 'Abc123';
        $_POST['pay_trf_account']   = 'test';
        $_POST['pay_trf_amount']    = 123;
        $_POST['on_hold']           = 'on_hold_until';
        $_POST['hold_until']        = 1666097548;

        $pageUrl            = admin_url('admin.php?page=razorpayPaymentsView&id=' . 'Abc123');
        $this->instance->shouldReceive('redirect')->with($pageUrl);

        $response = $this->instance->createPaymentTransfer();

        $this->assertNull($response);
    }

    public function testGetOrderTransferData()
    {
        global $product;

        $order      = wc_create_order();
        $orderId    = $order->get_id();

        $item = new WC_Order_Item_Product();
        
        $product = new WC_Product_Simple();
        $product->set_regular_price(15);
        $product->set_sale_price(10);
        $product->save();

        $item->set_product($product);
		$item->save();

		$order->add_item($item);
        $order->save();

        $productId = $product->get_id();
        
        add_post_meta($productId, 'rzp_transfer_from', 'from_order');

        add_post_meta($productId, 'LA_number', ['A1', 'A2']);

        add_post_meta($productId, 'LA_transfer_amount', [123, 234]);

        add_post_meta($productId, 'LA_transfer_status', ['Pending', 'Completed']);
        
        $response = $this->instance->getOrderTransferData($orderId);

        $this->assertSame('A1', $response[0]['account']);

        $this->assertSame(12300, $response[0]['amount']);

        $this->assertSame('Pending', $response[0]['on_hold']);

        $this->assertSame('A2', $response[1]['account']);

        $this->assertSame(23400, $response[1]['amount']);

        $this->assertSame('Completed', $response[1]['on_hold']);
    }

    public function testTransferFromPayment()
    {
        global $product;

        $order      = wc_create_order();
        $orderId    = $order->get_id();

        $item = new WC_Order_Item_Product();
        
        $product = new WC_Product_Simple();
        $product->set_regular_price(15);
        $product->set_sale_price(10);
        $product->save();

        $item->set_product($product);
		$item->save();

		$order->add_item( $item );
        $order->save();

        $productId = $product->get_id();

        add_post_meta($productId, 'rzp_transfer_from', 'from_payment');

        add_post_meta($productId, 'LA_number', ['A1', 'A2']);

        add_post_meta($productId, 'LA_transfer_amount', [123, 234]);

        add_post_meta($productId, 'LA_transfer_status', ['Pending', 'Completed']);
        
        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
        });
        
        $response = $this->instance->transferFromPayment($orderId, 'Abc123');
    
        $this->assertNull($response);
    }

    public function testAddRouteAnalyticsScript()
    {
        $response = $this->instance->addRouteAnalyticsScript();

        $this->assertStringContainsString('<form method="POST" action="https://api.razorpay.com/v1/checkout/embedded" id="routeAnalyticsForm">', $response);
        $this->assertStringContainsString("<input type='hidden' name='_[x-integration]' value='Woocommerce'>", $response);
        $this->assertStringContainsString("<input type='hidden' name='_[x-integration-module]' value='Route'>", $response);
    }
}
