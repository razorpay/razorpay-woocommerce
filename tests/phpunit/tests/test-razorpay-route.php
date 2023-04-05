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

    public function testrzpTransferDetails()
    {
        $_REQUEST = array('id' => 'Abc123');
        
        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
            }
        );

        ob_start();
        $this->instance->rzpTransferDetails();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringcontainsString('<a href="http://127.0.0.1/wp-admin/admin.php?page=razorpayRouteWoocommerce">', $result);

        $this->assertStringcontainsString('<div class="col-sm-8 panel-value">abcd</div>', $result);

        $this->assertStringcontainsString('<div class="col-sm-8 panel-value">order</div>', $result);

        $this->assertStringcontainsString('<div class="col-sm-8 panel-value">pay</div>', $result);

        $this->assertStringcontainsString('<div class="col-sm-8 panel-value"><span class="rzp-currency">₹ </span>12</div>', $result);

        $this->assertStringcontainsString('Pending', $result);

        $this->assertStringcontainsString('<span class="text-success trf-status">Pending</span>', $result);

        $this->assertStringcontainsString('<button onclick="' . "jQuery('.rev_trf_overlay').show()" . '" class="btn btn-primary">Create Reversal</button>', $result);

        $this->assertStringcontainsString(date("d F Y h:i A", strtotime('+5 hour +30 minutes', 1677542400)), $result);

        $this->assertStringcontainsString('<button type="button" class="close" data-dismiss="modal" onclick="' . "jQuery('.rev_trf_overlay').hide()" . '">&times;</button>', $result);

        $this->assertStringcontainsString('<form method="POST" action="' . esc_url(admin_url('admin-post.php')) . '">', $result);
        
        $this->assertStringcontainsString('<input type="hidden" name="transfer_id" value="abcd">', $result);

        $this->assertStringcontainsString('<input type="hidden" name="transfer_amount" value="1200">', $result);

        $this->assertStringcontainsString('<label><input type="radio" name="on_hold" class="enable_hold_until" value="on_hold_until" ', $result);

        $this->assertStringcontainsString('<input type="date" name="hold_until" id="hold_until"  min="' . date('Y-m-d', strtotime('+4 days')) . '" value="" disabled >', $result);

        $this->assertStringcontainsString('<label><input type="radio" name="on_hold" class="disable_hold_until" value="0"  >', $result);

        $this->assertStringcontainsString('<button type="submit" onclick="' . "jQuery('.trf_settlement_overlay').hide()" . '" name="update_setl_status"  class="btn btn-primary">Save</button>', $result);

        $this->assertStringcontainsString('<input type="hidden" name="transfer_id" value="abcd">', $result);
    }

    public function testrzpTransferDetailsOnholdwithholdunit()
    {
        $_REQUEST = array('id' => 'Abc123');
        
        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_3', 'key_secret3');
            }
        );

        ob_start();
        $this->instance->rzpTransferDetails();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringcontainsString('<span class="text-warning trf-status">Scheduled for ' . date("d M Y", 1677542400) . '</span>', $result);

        $this->assertStringcontainsString('<input type="date" name="hold_until" id="hold_until"  min="' . date('Y-m-d', strtotime('+4 days')) . '" value="' . date("Y-m-d", 1677542400), $result);
    }

    public function testrzpTransferDetailsOnholdwithoutsettlementid()
    {
        $_REQUEST = array('id' => 'Abc123');
        
        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_4', 'key_secret4');
            }
        );

        ob_start();
        $this->instance->rzpTransferDetails();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringcontainsString('<span><a href="javascript:void(0);" onclick="' . "jQuery('.trf_settlement_overlay').show()" . '" >Change</a></span>', $result);

        $this->assertStringcontainsString('<label><input type="radio" name="on_hold" class="disable_hold_until" value="0" checked >', $result);
    }

    public function testrzpTransferDetailsWithoutSettlementStatus()
    {
        $_REQUEST = array('id' => 'Abc123');
        
        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_5', 'key_secret5');
            }
        );

        ob_start();
        $this->instance->rzpTransferDetails();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringcontainsString('<span>Not Applicable</span>', $result);
    }

    public function testrzpTransferDetailsStatusComplete()
    {
        $_REQUEST = array('id' => 'Abc123');
        
        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_6', 'key_secret6');
            }
        );

        ob_start();
        $this->instance->rzpTransferDetails();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringcontainsString('<span>' . ucwords('Complete') . '</span>', $result);
    }
    
    public function testrouteHeaderrazorpayRouteWoocommerce()
    {
        $_GET['page'] = 'razorpayRouteWoocommerce';

        ob_start();
        $this->instance->routeHeader();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString('<a  class="active"  href="?page=razorpayRouteWoocommerce">Transfers</a>', $result);

        $this->assertStringContainsString('<a  href="?page=razorpayRoutePayments">Payments</a>', $result);

        $this->assertStringContainsString('<a  href="?page=razorpayRouteReversals">Reversals</a>', $result);
    }

    public function testrouteHeaderrazorpayRoutePayments()
    {
        $_GET['page'] = 'razorpayRoutePayments';

        ob_start();
        $this->instance->routeHeader();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString('<a  href="?page=razorpayRouteWoocommerce">Transfers</a>', $result);

        $this->assertStringContainsString('<a  class="active"  href="?page=razorpayRoutePayments">Payments</a>', $result);

        $this->assertStringContainsString('<a  href="?page=razorpayRouteReversals">Reversals</a>', $result);
    }

    public function testrouteHeaderrazorpayRouteReversals()
    {
        $_GET['page'] = 'razorpayRouteReversals';

        ob_start();
        $this->instance->routeHeader();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString('<a  href="?page=razorpayRouteWoocommerce">Transfers</a>', $result);

        $this->assertStringContainsString('<a  href="?page=razorpayRoutePayments">Payments</a>', $result);

        $this->assertStringContainsString('<a  class="active"  href="?page=razorpayRouteReversals">Reversals</a>', $result);
    }

    public function testrzpTransferReversals()
    {
        $this->instance->shouldReceive('routeHeader');

        $this->instance->shouldReceive('prepareReversalItems');

        $this->instance->shouldReceive('display');

        ob_start();
        $this->instance->rzpTransferReversals();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString('<div class="wrap route-container">', $result);

        $this->assertStringContainsString('<form method="get">', $result);

        $this->assertStringContainsString('<input type="hidden" name="page" value="razorpayRouteReversals">', $result);
    }
    
    public function testprepareReversalItems()
    {
        $this->instance->shouldReceive('get_pagenum')->andReturn(12);

        $_REQUEST = array('s' => 'ABC123');

        $reversalPage = array(
            'reversal_id' => '9654',
            'transfer_id' => '1234',
            'amount' => '2500',
            'created_at' => 1677542400
        );

        $this->instance->shouldReceive('getReversalItems')->with(10)->andReturn($reversalPage);

        $this->instance->shouldReceive('set_pagination_args');

        $this->instance->prepareReversalItems();

        $this->assertTrue(true);
    }

    public function testprepareReversalItemswithoutOffet()
    {
        $this->instance->shouldReceive('get_pagenum')->andReturn(0);

        $_REQUEST = array('s' => 'ABC123');

        $reversalPage = array(
            0 => array(
                'reversal_id' => '9654',
                'transfer_id' => '1234',
                'amount' => '2500',
                'created_at' => 1677542400
            )
        );

        $this->instance->shouldReceive('getReversalItems')->with(10)->andReturn($reversalPage);

        $this->instance->shouldReceive('set_pagination_args');

        $this->instance->prepareReversalItems();

        $this->assertTrue(true);
    }

    public function testgetReversalColumns()
    {
        $response = $this->instance->getReversalColumns();

        $this->assertSame('Reversal Id', $response['reversal_id']);

        $this->assertSame('Transfer Id', $response['transfer_id']);

        $this->assertSame('Amount', $response['amount']);

        $this->assertSame('Created At', $response['created_at']);
    }

    public function testgetReversalItems()
    {
        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
            });

        $response = $this->instance->getReversalItems(5);

        $this->assertSame('abcd', $response[0]['reversal_id']);

        $this->assertSame('pqrs', $response[0]['transfer_id']);

        $this->assertSame('<span class="rzp-currency">₹</span> 12', $response[0]['amount']);

        $this->assertSame(date("d M Y h:i A", strtotime('+5 hour +30 minutes', 1677542400)), $response[0]['created_at']);
    }

    public function testrzpRoutePayments()
    {
        $this->instance->shouldReceive('routeHeader');

        $this->instance->shouldReceive('preparePaymentItems');

        $this->instance->shouldReceive('search_box')->with('search', 'search_id');

        $this->instance->shouldReceive('display');

        ob_start();
        $this->instance->rzpRoutePayments();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString('<div class="wrap route-container"><form method="get">', $result);

        $this->assertStringContainsString('<input type="hidden" name="page" value="razorpayRoutePayments">', $result);

        $this->assertStringContainsString('<p class="pay_search_label">Search here for payments of linked account</p>', $result);
    }

    public function testpreparePaymentItems()
    {
        $this->instance->shouldReceive('get_pagenum')->andReturn(12);

        $_REQUEST = array('s' => 'ABC123');

        $paymentItems = array(
            'payment_id' => 'pay_LEkixKpTE1Mvrk',
            'order_id' => 11,
            'amount' => '2500',
            'email' => 'abc.xyz@razorpay.com',
            'contact' => '0987654321',
            'created_at' => '16/02/2023',
            'status' => 'Pending');

        $this->instance->shouldReceive('getPaymentItems')->with(10, 'ABC123')->andReturn($paymentItems);

        $this->instance->shouldReceive('set_pagination_args');

        $this->instance->preparePaymentItems();

        $this->assertTrue(true);
    }

    public function testpreparePaymentItemswithouroffset()
    {
        $this->instance->shouldReceive('get_pagenum')->andReturn(0);

        $_REQUEST = array('s' => 'ABC123');

        $paymentItems = array(
            0 => array(
            'payment_id' => 'pay_LEkixKpTE1Mvrk',
            'order_id' => 11,
            'amount' => '2500',
            'email' => 'abc.xyz@razorpay.com',
            'contact' => '0987654321',
            'created_at' => '16/02/2023',
            'status' => 'Pending'
        ));

        $this->instance->shouldReceive('getPaymentItems')->with(10, 'ABC123')->andReturn($paymentItems);

        $this->instance->shouldReceive('set_pagination_args');

        $this->instance->preparePaymentItems();

        $this->assertTrue(true);
    }

    public function testgetPaymentColumns()
    {
        $response = $this->instance->getPaymentColumns();

        $this->assertSame('Payment Id', $response['payment_id']);

        $this->assertSame('Order Id', $response['order_id']);

        $this->assertSame('Amount', $response['amount']);

        $this->assertSame('Email', $response['email']);

        $this->assertSame('Contact', $response['contact']);

        $this->assertSame('Created At', $response['created_at']);

        $this->assertSame('Status', $response['status']);
    }

    public function testgetPaymentItems()
    {
        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
            });
        
        $response = $this->instance->getPaymentItems(5, '');

        $this->assertSame('<a href="?page=razorpayPaymentsView&id=abcd">abcd</a>', $response[0]['payment_id']);

        $this->assertSame(11, $response[0]['order_id']);

        $this->assertSame('<span class="rzp-currency">₹</span> 12', $response[0]['amount']);

        $this->assertSame('abc.xyz@razorpay.com', $response[0]['email']);

        $this->assertSame('9087654321', $response[0]['contact']);

        $this->assertSame(date("d F Y h:i A", strtotime('+5 hour +30 minutes', 1677542400)), $response[0]['created_at']);

        $this->assertSame('Pending', $response[0]['status']);
    }
    
    public function testrzpSettlementTransfers()
    {
        $_REQUEST = array('id' => 'Rzp123');
        $_SERVER = array('HTTP_REFERER' => 'razorpay.com');

        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
            });

        ob_start();
        $this->instance->rzpSettlementTransfers();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString('<a href="razorpay.com">', $result);

        $this->assertStringContainsString('Settlement ID :  <strong>Rzp123</strong>', $result);

        $this->assertStringContainsString('<div class="col">abcd</div>', $result);

        $this->assertStringContainsString('<div class="col">order</div>', $result);

        $this->assertStringContainsString('<div class="col">pay </div>', $result);

        $this->assertStringContainsString('<div class="col"><span class="rzp-currency">₹ </span>12</div>', $result);

        $this->assertStringContainsString('<div class="col">1677542400</div>', $result);

    }

    public function testcheckDirectTransferFeature()
    {
        add_option('key_id', 'key_id_2');
        add_option('key_secret', 'key_secret2');
        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
            });

        $data = array('assigned_features' => array('callback' => array('name' => 'direct_transfer')));

        $this->instance->shouldReceive('fetchFileContents')->andReturn(json_encode($data));

        ob_start();
        $this->instance->checkDirectTransferFeature();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString('<button class="btn btn-primary" onclick="' . "jQuery('.overlay').show()" . '">Create Direct Transfer</button>', $result);
    }

    public function testadminEnqueueScriptsFunc()
    {
        adminEnqueueScriptsFunc();

        $this->assertTrue(wp_script_is('route-script', 'enqueued'));

        $this->assertTrue(wp_script_is('bootstrap-script', 'enqueued'));

        $this->assertTrue(wp_style_is('bootstrap-css', 'enqueued'));

        $this->assertTrue(wp_style_is('woo_route-css', 'enqueued'));

        $this->assertTrue(wp_script_is('jquery', 'enqueued'));

        $this->assertTrue(wp_style_is('bootstrap-css', 'registered'));

        $this->assertTrue(wp_style_is('woo_route-css', 'registered'));
    }

    public function testtransferDataTab()
    {
        $response = transferDataTab([]);

        $this->assertSame('Razorpay Route', $response['route']['label']);

        $this->assertSame('rzp_transfer_product_data', $response['route']['target']);

        $this->assertSame(11, $response['route']['priority']);
    }

    public function testwoocommerce_process_transfer_meta_fields_save()
    {
        $_POST = array('rzp_transfer_from' => 'ABC', 'LA_number' => '123', 'LA_transfer_amount' => '2500', 'LA_transfer_status' => 'Pending');

        $postid = 24;

        woocommerce_process_transfer_meta_fields_save($postid);

        $this->assertSame('ABC', get_post_meta($postid, 'rzp_transfer_from', true));

        $this->assertSame('123', get_post_meta($postid, 'LA_number', true));

        $this->assertSame('2500', get_post_meta($postid, 'LA_transfer_amount', true));

        $this->assertSame('Pending', get_post_meta($postid, 'LA_transfer_status', true));
    }

    public function testpaymentTransferMetaBox()
    {
        paymentTransferMetaBox();

        $this->assertSame('Razorpay transfers from Order / Payment', ($GLOBALS['wp_meta_boxes']['shop_order']['normal']['low']['rzp_trf_payment_meta']['title']));

        $this->assertSame('Razorpay Payment ID', ($GLOBALS['wp_meta_boxes']['shop_order']['normal']['low']['rzp_payment_meta']['title']));
    }

    public function testrenderPaymentMetaBox()
    {
        global $post;

        $wordpress_post = array(
        'id' => 11,
        'post_title' => 'Post title',
        'post_content' => 'Post Content',
        'post_status' => 'publish',
        'post_author' => 1,
        'post_type' => 'page'
        );

        $postid = wp_insert_post($wordpress_post);

        $post = get_post($postid);

        $paymentID = 'pay_LEkixKpTE1Mvrk';
        update_post_meta($postid, '_transaction_id', $paymentID);

        ob_start();
        renderPaymentMetaBox();
        $result = ob_get_contents();
        ob_end_clean();

        $expected = '<p>' . $paymentID . ' <span><a href="?page=razorpayPaymentsView&id=' . $paymentID . '"><input type="button" class="button" value="View"></a></span></p>';
        $this->assertStringContainsString($expected, $result);
    }
}