<?php

require_once __DIR__. '/woocs.php' ;
require_once __DIR__.'/../includes/Errors/ErrorCode.php';

use Razorpay\Api\Errors;
use Razorpay\Woocommerce\Errors as WooErrors;

/**
 * Class TestWC_Razorpay
 *
 * @package Woo_Razorpay
 */

class WC_RazorpayTest extends WP_UnitTestCase
{
    function setup()
    {
        $this->razorpay = new WC_Razorpay(false);

        $this->order  = WC_Helper_Order::create_order();

        global $WOOCS;

        $WOOCS = new WOOCS();
    }


    function test_getSetting()
        {
             $this->assertEquals($this->razorpay->getSetting('title'), 'Credit Card/Debit Card/NetBanking');
        }

    function test_formFieldEenableModuleCheckbox()
    {
        $this->assertEquals($this->razorpay->form_fields['enabled']['title'], 'Enable/Disable');
        $this->assertEquals($this->razorpay->form_fields['enabled']['type'], 'checkbox');
        $this->assertEquals($this->razorpay->form_fields['enabled']['label'], 'Enable this module?');
        $this->assertEquals($this->razorpay->form_fields['enabled']['default'], 'yes');
    }

    function test_formFieldTitle()
    {
        $this->assertEquals($this->razorpay->form_fields['title']['title'], 'Title');
        $this->assertEquals($this->razorpay->form_fields['title']['type'], 'text');
        $this->assertEquals($this->razorpay->form_fields['title']['description'], 'This controls the title which the user sees during checkout.');
        $this->assertEquals($this->razorpay->form_fields['title']['default'], 'Credit Card/Debit Card/NetBanking');
    }

    function test_formFieldDescription()
    {
        $this->assertEquals($this->razorpay->form_fields['description']['title'], 'Description');
        $this->assertEquals($this->razorpay->form_fields['description']['type'], 'textarea');
        $this->assertEquals($this->razorpay->form_fields['description']['description'], 'This controls the description which the user sees during checkout.');
        $this->assertEquals($this->razorpay->form_fields['description']['default'], 'Pay securely by Credit or Debit card or Internet Banking through Razorpay.');
    }

    function test_formFieldKeyId()
    {
        $this->assertEquals($this->razorpay->form_fields['key_id']['title'], 'Key ID');
        $this->assertEquals($this->razorpay->form_fields['key_id']['type'], 'text');
        $this->assertEquals($this->razorpay->form_fields['key_id']['description'], 'The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.');
    }

    function test_formFieldkeySecret()
    {
        $this->assertEquals($this->razorpay->form_fields['key_secret']['title'], 'Key Secret');
        $this->assertEquals($this->razorpay->form_fields['key_secret']['type'], 'text');
        $this->assertEquals($this->razorpay->form_fields['key_secret']['description'], 'The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.');
    }

    function test_formFieldpaymentAction()
    {
        $this->assertEquals($this->razorpay->form_fields['payment_action']['title'], 'Payment Action');
        $this->assertEquals($this->razorpay->form_fields['payment_action']['type'], 'select');
        $this->assertEquals($this->razorpay->form_fields['payment_action']['description'], 'Payment action on order compelete');
        $this->assertEquals($this->razorpay->form_fields['payment_action']['default'], 'capture');
        $this->assertEquals(json_encode($this->razorpay->form_fields['payment_action']['options']), '{"authorize":"Authorize","capture":"Authorize and Capture"}');
    }

    function test_formFieldenableWwebHook()
    {
        $webhookUrl = 'http://example.org/wp-admin/admin-post.php?action=rzp_wc_webhook';
        $this->assertEquals($this->razorpay->form_fields['enable_webhook']['title'], 'Enable Webhook');
        $this->assertEquals($this->razorpay->form_fields['enable_webhook']['type'], 'checkbox');
        $this->assertEquals($this->razorpay->form_fields['enable_webhook']['description'], "<span>$webhookUrl</span><br/><br/>Instructions and guide to <a href='https://github.com/razorpay/razorpay-woocommerce/wiki/Razorpay-Woocommerce-Webhooks'>Razorpay webhooks</a>");
        $this->assertEquals($this->razorpay->form_fields['enable_webhook']['default'], 'no');
        $this->assertEquals(json_encode($this->razorpay->form_fields['enable_webhook']['label']), '"Enable Razorpay Webhook <a href=\"https:\/\/dashboard.razorpay.com\/#\/app\/webhooks\">here<\/a> with the URL listed below."');
    }

    function test_formFieldWebhookSecret()
    {
        $this->assertEquals($this->razorpay->form_fields['webhook_secret']['title'], 'Webhook Secret');
        $this->assertEquals($this->razorpay->form_fields['webhook_secret']['type'], 'text');
        $this->assertEquals($this->razorpay->form_fields['webhook_secret']['description'], 'Webhook secret is used for webhook signature verification. This has to match the one added <a href="https://dashboard.razorpay.com/#/app/webhooks">here</a>');
        $this->assertEquals($this->razorpay->form_fields['webhook_secret']['default'], '');
    }

    function test_adminOptions()
    {
        $this->razorpay->admin_options();
        $out = ob_get_clean();
        $this->assertContains('<h3>Razorpay Payment Gateway</h3>', $out);
        $this->assertContains('<p>Allows payments by Credit/Debit Cards, NetBanking, UPI, and multiple Wallets</p>', $out);
        $this->assertContains('<table class="form-table"', $out);
    }

    function test__description()
    {
        $this->assertEquals($this->razorpay->get_description(), 'Pay securely by Credit or Debit card or Internet Banking through Razorpay.');
    }


    function test_generateRazorpay()
    {
        $form = $this->razorpayForm();
        $this->assertEquals($this->razorpay->generate_razorpay_form($this->order->get_order_key()), $form);
    }


    function razorpayForm()
        {
            return <<<EOT
<p>Thank you for your order, please click the button below to pay with Razorpay.</p><form name='razorpayform' action="http://example.org/wc-api/razorpay" method="POST">
    <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id">
    <input type="hidden" name="razorpay_signature"  id="razorpay_signature" >
    <!-- This distinguishes all our various wordpress plugins -->
    <input type="hidden" name="razorpay_wc_form_submit" value="1">
</form>
<p id="msg-razorpay-success" class="woocommerce-info woocommerce-message" style="display:none">
Please wait while we are processing your payment.
</p>
<p>
    <button id="btn-razorpay">Pay Now</button>
    <button id="btn-razorpay-cancel" onclick="document.razorpayform.submit()">Cancel</button>
</p>
EOT;
    }


    function test_handleCurrencyConversionSuccess()
    {
        $data = array(
                'receipt'         => 1,
                'amount'          => 100,
                'currency'        => 'GBP',
                'payment_capture' => 1,
        );

        $this->razorpay->handleCurrencyConversion($data);
        $this->assertEquals($data['currency'], 'INR');
        $this->assertEquals($data['amount'], '119');
    }

    /**
     * @expectedException Razorpay\Api\Errors\BadRequestError
     */
    function test_wooCurrencyMisisng()
    {

        $data = array(
                'receipt'         => 1,
                'amount'          => 100,
                'currency'        => 'USD',
                'payment_capture' => 1,
        );
        $this->razorpay->handleCurrencyConversion($data);

    }

    function test_getCustomerInfo()
    {

        $this->order->set_billing_first_name('abc');
        $this->order->set_billing_last_name('xyz');
        $this->order->set_billing_email('x@ytest.org');
        $this->order->set_billing_phone('95123456789');
        $this->order->save();

        $result         = $this->razorpay->getCustomerInfo($this->order);

        $expectedResult = Array(
            'name' => 'abc xyz',
            'email' => 'x@ytest.org',
            'contact' => '95123456789'
        );

        $this->assertEquals($result, $expectedResult);

    }

    function test_updateOrderSuccess()
    {
        $result = $this->razorpay->updateOrder($this->order, true, "", 2);

        $this->assertEquals(2, $this->order->get_transaction_id());

    }

    function test_updateOrderFailure(){
        $this->order->save();

        $result = $this->razorpay->updateOrder($this->order, false, "", 2);

        $this->assertEquals($this->order->get_status(), 'failed');
    }

    function test_getRazorpayApiInstance(){
      $this->assertInstanceOf('Razorpay\Api\Api', $this->razorpay->getRazorpayApiInstance());
    }
}
