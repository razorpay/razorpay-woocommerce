<?php

class TestWooRazorpay extends WP_UnitTestCase
{

    public function setup()
    {
        $this->wooRazorpay = new WC_Razorpay();

        $this->formFields = $this->wooRazorpay->form_fields;

        $this->productFactory = new ProductFactory();

        $this->orderFactory = new OrderFactory();

        $this->order = $this->createOrder();
    }

    public function test_constructor()
    {
        $this->assertEquals('razorpay', $this->wooRazorpay->id);
        $this->assertFalse($this->wooRazorpay->has_fields);
        $this->assertEquals('Razorpay', $this->wooRazorpay->method_title);
        $this->assertEquals('', $this->wooRazorpay->method_description);
    }

    public function test_formFieldEenableModuleCheckbox()
    {
        $this->assertEquals('Enable/Disable', $this->formFields['enabled']['title']);
        $this->assertEquals('checkbox', $this->formFields['enabled']['type']);
        $this->assertEquals('Enable this module?', $this->formFields['enabled']['label']);
        $this->assertEquals('yes', $this->formFields['enabled']['default']);
    }

    public function test_formFieldTitle()
    {
        $this->assertEquals('Title', $this->formFields['title']['title']);
        $this->assertEquals('text', $this->formFields['title']['type']);
        $this->assertEquals('This controls the title which the user sees during checkout.',
                             $this->formFields['title']['description']);
        $this->assertEquals($this->formFields['title']['default'], 'Credit Card/Debit Card/NetBanking');
    }

    public function test_formFieldDescription()
    {
        $this->assertEquals('Description', $this->formFields['description']['title']);
        $this->assertEquals('textarea', $this->formFields['description']['type'], 'textarea');
        $this->assertEquals('This controls the description which the user sees during checkout.',
                            $this->formFields['description']['description']);
        $this->assertEquals('Pay securely by Credit or Debit card or Internet Banking through Razorpay.',
                            $this->formFields['description']['default']);
    }

    public function test_formFieldkeyID()
    {
        $this->assertEquals('Key ID', $this->formFields['key_id']['title']);
        $this->assertEquals($this->formFields['key_id']['type'], 'text');
        $this->assertEquals($this->formFields['key_id']['description'],
                            'The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.');
    }

    public function test_formFieldKeySecret()
    {
        $this->assertEquals('Key Secret', $this->formFields['key_secret']['title']);
        $this->assertEquals('text', $this->formFields['key_secret']['type']);
        $this->assertEquals('The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.',
            $this->formFields['key_secret']['description']);
    }

    public function test_formFieldPaymentAction()
    {
        $paymentAction = [
            'authorize' => 'Authorize',
            'capture'  => 'Authorize and Capture',
        ];

        $this->assertEquals('Payment Action', $this->formFields['payment_action']['title']);
        $this->assertEquals('select', $this->formFields['payment_action']['type']);
        $this->assertEquals('Payment action on order complete',
            $this->formFields['payment_action']['description']);
        $this->assertEquals('capture', $this->formFields['payment_action']['default']);
        $this->assertEquals($paymentAction, $this->formFields['payment_action']['options']);
    }

    public function test_formFieldOrderCompletionMessage()
    {
        $this->assertEquals('Order Completion Message', $this->formFields['order_success_message']['title']);
        $this->assertEquals('textarea', $this->formFields['order_success_message']['type']);
        $this->assertEquals('Message to be displayed after a successful order',
            $this->formFields['order_success_message']['description']);
        $this->assertEquals('Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon.',
            $this->formFields['order_success_message']['default']);
    }

    public function test_enableWebHook()
    {
        $webhookUrl = esc_url(admin_url('admin-post.php')) . '?action=rzp_wc_webhook';
        $this->assertEquals('Enable Webhook', $this->formFields['enable_webhook']['title']);
        $this->assertEquals('checkbox', $this->formFields['enable_webhook']['type']);
        $this->assertEquals('no', $this->formFields['enable_webhook']['default']);
        $this->assertEquals("<span>$webhookUrl</span><br/><br/>Instructions and guide to <a href='https://github.com/razorpay/razorpay-woocommerce/wiki/Razorpay-Woocommerce-Webhooks'>Razorpay webhooks</a>",
            $this->formFields['enable_webhook']['description']);
        $this->assertEquals('"Enable Razorpay Webhook <a href=\"https:\/\/dashboard.razorpay.com\/#\/app\/webhooks\">here<\/a> with the URL listed below."',
            json_encode($this->formFields['enable_webhook']['label']));
    }

    public function test_webhookSecret()
    {
        $this->assertEquals('Webhook Secret', $this->formFields['webhook_secret']['title']);
        $this->assertEquals('text', $this->formFields['webhook_secret']['type']);
    }

    public function test_adminOptions()
    {
        $this->wooRazorpay->admin_options();
        $out = ob_get_clean();
        $this->assertContains('<h3>Razorpay Payment Gateway</h3>', $out);
        $this->assertContains('<p>Allows payments by Credit/Debit Cards, NetBanking, UPI, and multiple Wallets</p>', $out);
        $this->assertContains('<table class="form-table"', $out);
    }

    public function test__description()
    {
        $this->assertEquals($this->wooRazorpay->get_description(), 'Pay securely by Credit or Debit card or Internet Banking through Razorpay.');
    }

    public function test_settings()
    {
        $this->assertEquals('Credit Card/Debit Card/NetBanking', $this->wooRazorpay->getSetting('title'));
    }

    public function test_generateRazorpayForm()
    {
        $redirectUrl =  get_site_url() . '/wc-api/razorpay';

        $html = "<p>Thank you for your order, please click the button below to pay with Razorpay.</p>";

    $expectedForm = <<<EOT
<form name='razorpayform' action="$redirectUrl" method="POST">
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
        $actualForm = $this->wooRazorpay->generate_razorpay_form($this->order->get_id());

        $this->assertEquals($html. $expectedForm, $actualForm);
    }

    public function test_getCustomerInfo()
    {
        $actualCustomerData = $this->wooRazorpay->getCustomerInfo($this->order);

        $expectedCustomerData = [
            'name'  => 'test user',
            'email' => 'test@razorpay.org',
            'contact' => '555-32123',
        ];

        $this->assertEquals($expectedCustomerData, $actualCustomerData);
    }

    public function test_processRefundFailed()
    {
        $wpErrorObject = $this->wooRazorpay->process_refund($this->order);

        $this->assertEquals('Refund failed: No transaction ID', $wpErrorObject->get_error_message());
    }

    public function test_processRefundSuccess()
    {
        $this->order->payment_complete('pay_9ul1RYIACBKOd"');

        $this->assertTrue($this->wooRazorpay->process_refund($this->order));
    }

    public function test_updateOrderFailed()
    {
        $this->wooRazorpay->updateOrder($this->order, false, '', '');

        $this->assertEquals('failed' ,$this->order->get_status());
    }

    public function test_updateOrderSuccess()
    {
        $this->wooRazorpay->updateOrder($this->order, true, 'Payment Success', 'pay_9ul1RYIACBKOd');

        $this->assertEquals('processing', $this->order->get_status());
    }

    public function test_updateOrderDownloadableProduct()
    {
        $virtualProductOrder = $this->orderVirtualProduct();

        $this->wooRazorpay->updateOrder($virtualProductOrder, true, 'Payment Success', 'pay_9ul1RYIACBKOe');

        $this->assertEquals('completed', $virtualProductOrder->get_status());
    }

    private function createOrder()
    {
        $product =  $this->productFactory->createSimpleProduct();

        $order = $this->orderFactory->createOrder(1, $product);

        return $order;
    }

    private function orderVirtualProduct()
    {
        $product =  $this->productFactory->createDownloadableProduct();

        $order = $this->orderFactory->createOrder(1, $product);

        return $order;
    }
}
