<?php

require_once __DIR__ . '/../../../includes/razorpay-route.php';
require_once __DIR__ .'/../../../woo-razorpay.php';
require_once __DIR__ . '/../mockfactory/MockApi.php';
require_once __DIR__ . '/../mockfactory/Request.php';
require_once __DIR__ . '/../mockfactory/Transfer.php';

use Razorpay\MockApi\MockApi;

class Test_RzpRoute extends \PHPUnit_Framework_TestCase
{ 
    private $instance;

    public function setup(): void
    {
        parent::setup();
        $this->instance = Mockery::mock('RZP_Route')->makePartial()->shouldAllowMockingProtectedMethods();

        $_POST = array();
        $_REQUEST = array();
        $_GET = array();
        $_SERVER = array();
    }
    
    public function testaddRouteModuleSettingFields()
    {
        update_option('woocommerce_currency', 'INR');
        
        $defaultFormFields = array(
            'key_id' => array(
                'title' => __('Key ID', 11),
                'type' => 'text',
                'description' => __('The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.', 11)
            ),
            'key_secret' => array(
                'title' => __('Key Secret', 11),
                'type' => 'text',
                'description' => __('The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.', 11)
            ),
        );

        addRouteModuleSettingFields($defaultFormFields);

        $description = "<span>For Route payments / transfers, first create a linked account <a href='https://dashboard.razorpay.com/app/route/payments' target='_blank'>here</a></span><br/><br/>Route Documentation - <a href='https://razorpay.com/docs/route/' target='_blank'>View</a>";

        $this->assertSame('Route Module', $defaultFormFields['route_enable']['title']);

        $this->assertSame('checkbox', $defaultFormFields['route_enable']['type']);

        $this->assertSame('Enable route module?', $defaultFormFields['route_enable']['label']);

        $this->assertSame($description, $defaultFormFields['route_enable']['description']);

        $this->assertSame('no', $defaultFormFields['route_enable']['default']);

        update_option('woocommerce_currency', 'USD');
    }

    public function testrazorpayRouteModule()
    {
        add_option('woocommerce_razorpay_settings', array('route_enable' => 'yes'));

        razorpayRouteModule();

        $this->assertTrue(has_action('admin_menu'));

        $this->assertTrue(has_action('admin_enqueue_scripts'));

        $this->assertTrue(has_action('woocommerce_product_data_panels'));

        $this->assertTrue(has_action('woocommerce_process_product_meta'));

        $this->assertTrue(has_action('add_meta_boxes'));

        $this->assertTrue(has_filter('woocommerce_product_data_tabs'));
    }

    public function testrzpAddPluginPage()
    {
        global $submenu;

        rzpAddPluginPage();

        $this->assertSame('razorpay-route-woocommerce', $GLOBALS['admin_page_hooks']['razorpayRouteWoocommerce']);

        $this->assertSame('razorpayTransfers', $GLOBALS['submenu'][''][0][2]);

        $this->assertSame('razorpayRouteReversals', $GLOBALS['submenu'][''][1][2]);

        $this->assertSame('razorpayRoutePayments', $GLOBALS['submenu'][''][2][2]);

        $this->assertSame('razorpaySettlementTransfers', $GLOBALS['submenu'][''][3][2]);

        $this->assertSame('razorpayPaymentsView', $GLOBALS['submenu'][''][4][2]);
    }
    
    public function testrzpTransfers()
    {
        $this->instance->shouldReceive('routeHeader');

        $this->instance->shouldReceive('checkDirectTransferFeature');

        $this->instance->shouldReceive('prepareItems');

        $this->instance->shouldReceive('views');

        $this->instance->shouldReceive('display');

        ob_start();
        $this->instance->rzpTransfers();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString('<div class="wrap route-container">', $result);

        $this->assertStringContainsString('<input type="hidden" name="page" value="" />', $result);

        $this->assertStringContainsString('<input type="hidden" name="section" value="issues" />', $result);

        $this->assertStringContainsString('<form method="get">', $result);

        $this->assertStringContainsString('<input type="hidden" name="page" value="razorpayRouteWoocommerce">', $result);

        $hide = "jQuery('.overlay').hide()";

        $this->assertStringContainsString('<button type="button" class="close" data-dismiss="modal" onclick="' . $hide . '">&times;</button>', $result);

        $this->assertStringContainsString('<form method="POST" action="' . esc_url(admin_url('admin-post.php')) . '">', $result);

        $this->assertStringContainsString('<button type="submit" onclick="' . $hide . '" name="trf_create" class="btn btn-primary">Create</button>', $result);
    }

    public function testget_columns()
    {
        $response = $this->instance->get_columns();

        $this->assertSame('Transfer Id', $response['transfer_id']);

        $this->assertSame('Source', $response['source']);

        $this->assertSame('Recipient', $response['recipient']);

        $this->assertSame('Amount', $response['amount']);

        $this->assertSame('Created At', $response['created_at']);

        $this->assertSame('Transfer Status', $response['transfer_status']);

        $this->assertSame('Settlement Status', $response['settlement_status']);

        $this->assertSame('Settlement Id', $response['settlement_id']);
    }

    public function testcolumn_default()
    {
        $item = array('id' => 11, 
                    'transfer_id' => 22, 
                    'source' => 'order', 
                    'recipient' => 'pay', 
                    'amount' => 2500, 
                    'created_at' => '14/02/2023', 
                    'settlement_id' => 33, 
                    'transfer_status' => 'Completed', 
                    'settlement_status' => 'Processing', 
                    'payment_id' => 'ABC123', 
                    'order_id' => 11, 
                    'email' => 'abc.xyz@razorpay.com', 
                    'contact' => '1234567890', 
                    'status' => 'Processing', 
                    'reversal_id' => '0001142', 
                    'la_name' => 'ABC', 
                    'la_number' => '0987654321');

        $this->assertSame(11, $this->instance->column_default($item, 'id'));

        $this->assertSame(22, $this->instance->column_default($item, 'transfer_id'));

        $this->assertSame('order', $this->instance->column_default($item, 'source'));

        $this->assertSame('pay', $this->instance->column_default($item, 'recipient'));

        $this->assertSame(2500, $this->instance->column_default($item, 'amount'));

        $this->assertSame('14/02/2023', $this->instance->column_default($item, 'created_at'));

        $this->assertSame(33, $this->instance->column_default($item, 'settlement_id'));

        $this->assertSame('Completed', $this->instance->column_default($item, 'transfer_status'));

        $this->assertSame('Processing', $this->instance->column_default($item, 'settlement_status'));

        $this->assertSame('ABC123', $this->instance->column_default($item, 'payment_id'));

        $this->assertSame(11, $this->instance->column_default($item, 'order_id'));

        $this->assertSame('abc.xyz@razorpay.com', $this->instance->column_default($item, 'email'));

        $this->assertSame('1234567890', $this->instance->column_default($item, 'contact'));

        $this->assertSame('Processing', $this->instance->column_default($item, 'status'));

        $this->assertSame('0001142', $this->instance->column_default($item, 'reversal_id'));

        $this->assertSame('ABC', $this->instance->column_default($item, 'la_name'));

        $this->assertSame('0987654321', $this->instance->column_default($item, 'la_number'));

        $result = $this->instance->column_default($item, 'Razorpay');

        $this->assertStringContainsString('[email] => abc.xyz@razorpay.com', $result);
    }
    
    public function testprepareItems()
    {
        $this->instance->shouldReceive('get_pagenum')->andReturn(12);

        $_REQUEST = array('s' => 'ABC123');

        $reversalPage = array(
            0 => array(
            'transfer_id' => '2345',
            'source' => 'Direct Transfer',
            'recipient' => 'Razorpay',
            'amount' => '2400',
            'created_at' => '16/2/23',
            'transfer_status' => 'Pending',
            'settlement_status' => 'Pending',
            'settlement_id' => '1234' 
            )
        );

        $this->instance->shouldReceive('getItems')->with(10)->andReturn($reversalPage);

        $this->instance->shouldReceive('set_pagination_args');

        $this->instance->prepareItems();

        $this->assertTrue(true);
    }

    public function testprepareItemsnoOffset()
    {
        $this->instance->shouldReceive('get_pagenum')->andReturn(0);

        $_REQUEST = array('s' => 'ABC123');

        $reversalPage = array(
            0 => array(
            'transfer_id' => '2345',
            'source' => 'Direct Transfer',
            'recipient' => 'Razorpay',
            'amount' => '2400',
            'created_at' => '16/2/23',
            'transfer_status' => 'Pending',
            'settlement_status' => 'Pending',
            'settlement_id' => '1234' 
            )
        );

        $this->instance->shouldReceive('getItems')->with(10)->andReturn($reversalPage);

        $this->instance->shouldReceive('set_pagination_args');

        $this->instance->prepareItems();

        $this->assertTrue(true);
    }

    public function testgetItems()
    {
        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
            });

        $response = $this->instance->getItems(4);

        $this->assertSame('<a href="?page=razorpayTransfers&id=abcd">abcd</a>', $response[0]['transfer_id']);

        $this->assertSame('order', $response[0]['source']);

        $this->assertSame('pay', $response[0]['recipient']);

        $this->assertSame('<span class="rzp-currency">₹</span> 12', $response[0]['amount']);

        $this->assertSame(date("d F Y h:i A", strtotime('+5 hour +30 minutes', 1677542400)), $response[0]['created_at']);

        $this->assertSame('Pending', $response[0]['transfer_status']);

        $this->assertSame('Pending', $response[0]['settlement_status']);

        $this->assertSame('<a href="?page=razorpaySettlementTransfers&id=Rzp123">Rzp123</a>', $response[0]['settlement_id']);
    }
}
