<?php

require_once __DIR__ . '/../../../includes/razorpay-route.php';
require_once __DIR__ .'/../../../woo-razorpay.php';

use Razorpay\MockApi\MockApi;

class Test_RzpRoute extends \PHPUnit_Framework_TestCase
{ 
    private $instance;
    private $rzpRoute;
    private $api;

    public function setup(): void
    {
        parent::setup();
        $this->rzpRoute = new RZP_Route();
        $this->instance = Mockery::mock('RZP_Route')->makePartial()->shouldAllowMockingProtectedMethods();

        $_POST = array();
        $_REQUEST = array();
        $_GET = array();
        $_SERVER = array();
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

        $directTransferModal = '<div class="overlay">
            <div class="modal" id="transferModal" >
                <div class="modal-dialog">
                    <!-- Modal content-->
                    <div class="modal-content">
                        <div class="modal-header">
                        <h4 class="modal-title">Direct Transfer</h4>
                            <button type="button" class="close" data-dismiss="modal" onclick="' . $hide . '">&times;</button>

                        </div>
                        <div class="modal-body">
                        <form method="POST" action="' . esc_url(admin_url('admin-post.php')) . '">
                            <div class="form-group">
                            <label class="">Transfer Amount</label>
                            <div class="input-group"><div class="input-group-addon">INR</div>
                            <div class="InputField ">
                            <input name="drct_trf_amount" type="number" autocomplete="off" class="form-control" placeholder="Enter amount">
                            </div>
                            </div></div>
                            <div class="form-group"><label>Linked Account Number</label>
                            <div class="InputField ">
                                <input type="text" name="drct_trf_account" class="form-control" placeholder="Linked account number">
                            </div>
                            </div>
                            <div>
                            <button type="submit" onclick="' . $hide . '" name="trf_create" class="btn btn-primary">Create</button>
                            <input type="hidden" name="action" value="rzp_direct_transfer">
                            </div>
                            </form>
                        </div>

                    </div>
                </div>
            </div>
            </div>
        <script type="text/javascript">
            jQuery("' . '.overlay' . '").on("' . 'click' . '", function(e) {
              if (e.target !== this) {
                return;
              }
              jQuery("' . '.overlay' . '").hide();
            });
        </script>';

        $this->assertStringContainsString($directTransferModal, $result);
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

        $this->assertSame(11, $this->instance->column_default($item,'id'));

        $this->assertSame(22, $this->instance->column_default($item,'transfer_id'));

        $this->assertSame('order', $this->instance->column_default($item,'source'));

        $this->assertSame('pay', $this->instance->column_default($item,'recipient'));

        $this->assertSame(2500, $this->instance->column_default($item,'amount'));

        $this->assertSame('14/02/2023', $this->instance->column_default($item,'created_at'));

        $this->assertSame(33, $this->instance->column_default($item,'settlement_id'));

        $this->assertSame('Completed', $this->instance->column_default($item,'transfer_status'));

        $this->assertSame('Processing', $this->instance->column_default($item,'settlement_status'));

        $this->assertSame('ABC123', $this->instance->column_default($item,'payment_id'));

        $this->assertSame(11, $this->instance->column_default($item,'order_id'));

        $this->assertSame('abc.xyz@razorpay.com', $this->instance->column_default($item,'email'));

        $this->assertSame('1234567890', $this->instance->column_default($item,'contact'));

        $this->assertSame('Processing', $this->instance->column_default($item,'status'));

        $this->assertSame('0001142', $this->instance->column_default($item,'reversal_id'));

        $this->assertSame('ABC', $this->instance->column_default($item,'la_name'));

        $this->assertSame('0987654321', $this->instance->column_default($item,'la_number'));

        $result = $this->instance->column_default($item, 'Razorpay');
        
        $this->assertStringContainsString('[email] => abc.xyz@razorpay.com', $result);
    }
}