<?php

/**
 * Values that reach HTML attributes must be escaped:
 *  - Route linked-account fields on the product screen (stored by anyone who can edit a product),
 *  - buyer details in the hosted-checkout form on the pay page.
 */

require_once __DIR__ . '/../../../includes/razorpay-route.php';

class Test_Output_Escaping extends WP_UnitTestCase
{
    const PAYLOAD = '"><script>alert(1)</script>';

    const ATTR_PAYLOAD = "x'><script>alert(1)</script>";

    public function tearDown(): void
    {
        $_POST = array();
        unset($GLOBALS['post']);
        parent::tearDown();
    }

    private function renderProductPanel($postId)
    {
        // woocommerce_wp_radio() is only loaded on admin screens
        require_once WC()->plugin_path() . '/includes/admin/wc-meta-box-functions.php';

        $GLOBALS['post'] = get_post($postId);

        ob_start();
        productTransferDataFields();

        return ob_get_clean();
    }

    private function hostedForm(array $data)
    {
        $instance   = Mockery::mock('WC_Razorpay')->makePartial()->shouldAllowMockingProtectedMethods();
        $reflection = new ReflectionMethod('WC_Razorpay', 'hostCheckoutScripts');
        $reflection->setAccessible(true);

        return $reflection->invoke($instance, $data);
    }

    private function hostedFormData(array $overrides = array())
    {
        return array_merge(array(
            'key'          => 'rzp_test_key',
            'order_id'     => 'order_unit1',
            'name'         => 'Test Store',
            'description'  => 'Order 12',
            'preference'   => array('image' => 'https://example.com/logo.png'),
            'callback_url' => 'https://example.com/?wc-api=razorpay&order_key=wc_order_abc',
            'cancel_url'   => 'https://example.com/checkout/',
            'notes'        => array('woocommerce_order_id' => 12),
            'prefill'      => array('name' => 'Asha', 'email' => 'asha@example.com', 'contact' => '9999999999'),
            '_'            => array('integration' => 'woocommerce'),
        ), $overrides);
    }

    // ------------------------------------------------- Route product panel

    public function testRoutePanelEscapesStoredLinkedAccountAndAmount()
    {
        $postId = self::factory()->post->create(array('post_type' => 'product'));

        update_post_meta($postId, 'LA_number', array(self::PAYLOAD));
        update_post_meta($postId, 'LA_transfer_amount', array(self::PAYLOAD));
        update_post_meta($postId, 'LA_transfer_status', array('0'));

        $html = $this->renderProductPanel($postId);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString(esc_attr(self::PAYLOAD), $html);
    }

    public function testRoutePanelStillShowsOrdinaryValues()
    {
        $postId = self::factory()->post->create(array('post_type' => 'product'));

        update_post_meta($postId, 'LA_number', array('acc_Hn1ukn2d32Fqww'));
        update_post_meta($postId, 'LA_transfer_amount', array('200'));
        update_post_meta($postId, 'LA_transfer_status', array('1'));

        $html = $this->renderProductPanel($postId);

        $this->assertStringContainsString('value="acc_Hn1ukn2d32Fqww"', $html);
        $this->assertStringContainsString('value="200"', $html);
    }

    public function testRouteSaveStripsMarkupFromLinkedAccountFields()
    {
        $postId = self::factory()->post->create(array('post_type' => 'product'));

        $_POST = array(
            'rzp_transfer_from'  => 'from_order<script>alert(1)</script>',
            'LA_number'          => array('acc_1<script>alert(1)</script>', 'acc_2'),
            'LA_transfer_amount' => array('200<script>alert(1)</script>', '300'),
            'LA_transfer_status' => array('1', '0<b>'),
        );

        woocommerce_process_transfer_meta_fields_save($postId);

        $stored = array(
            get_post_meta($postId, 'rzp_transfer_from', true),
            get_post_meta($postId, 'LA_number', true),
            get_post_meta($postId, 'LA_transfer_amount', true),
            get_post_meta($postId, 'LA_transfer_status', true),
        );

        $this->assertStringNotContainsString('<', wp_json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(array('acc_1', 'acc_2'), $stored[1]);
        $this->assertSame(array('200', '300'), $stored[2]);
        $this->assertSame(array('1', '0'), $stored[3]);
    }

    public function testRouteSaveKeepsOrdinaryValuesUnchanged()
    {
        $postId = self::factory()->post->create(array('post_type' => 'product'));

        $_POST = array(
            'rzp_transfer_from'  => 'from_payment',
            'LA_number'          => array('acc_Hn1ukn2d32Fqww'),
            'LA_transfer_amount' => array('200'),
            'LA_transfer_status' => array('1'),
        );

        woocommerce_process_transfer_meta_fields_save($postId);

        $this->assertSame('from_payment', get_post_meta($postId, 'rzp_transfer_from', true));
        $this->assertSame(array('acc_Hn1ukn2d32Fqww'), get_post_meta($postId, 'LA_number', true));
        $this->assertSame(array('200'), get_post_meta($postId, 'LA_transfer_amount', true));
        $this->assertSame(array('1'), get_post_meta($postId, 'LA_transfer_status', true));
    }

    // -------------------------------------------------- hosted checkout form

    public function testHostedFormEscapesBuyerDetails()
    {
        $html = $this->hostedForm($this->hostedFormData(array(
            'prefill' => array('name' => self::ATTR_PAYLOAD, 'email' => 'asha@example.com', 'contact' => self::ATTR_PAYLOAD),
            'notes'   => array('woocommerce_order_id' => 12, 'note' => self::ATTR_PAYLOAD),
        )));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString(esc_attr(self::ATTR_PAYLOAD), $html);
    }

    public function testHostedFormEscapesTopLevelFields()
    {
        $html = $this->hostedForm($this->hostedFormData(array(
            'name'        => self::PAYLOAD,
            'description' => self::PAYLOAD,
            'preference'  => array('image' => self::PAYLOAD),
        )));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testHostedFormKeepsOrdinaryValuesReadableByTheBrowser()
    {
        $data = $this->hostedFormData();
        $html = $this->hostedForm($data);

        $document = new DOMDocument();
        @$document->loadHTML($html);

        $fields = array();

        foreach ($document->getElementsByTagName('input') as $input)
        {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        // What the browser submits to Razorpay is unchanged
        $this->assertSame('rzp_test_key', $fields['key_id']);
        $this->assertSame('order_unit1', $fields['order_id']);
        $this->assertSame($data['callback_url'], $fields['callback_url']);
        $this->assertSame($data['cancel_url'], $fields['cancel_url']);
        $this->assertSame('Asha', $fields['prefill[name]']);
        $this->assertSame('asha@example.com', $fields['prefill[email]']);
        $this->assertSame('12', $fields['notes[woocommerce_order_id]']);
        $this->assertSame('woocommerce', $fields['_[integration]']);

        $form = $document->getElementsByTagName('form')->item(0);
        $this->assertStringEndsWith('checkout/embedded', $form->getAttribute('action'));
    }

    public function testHostedFormSubmitsACraftedNameAsTextNotMarkup()
    {
        $html = $this->hostedForm($this->hostedFormData(array(
            'prefill' => array('name' => self::ATTR_PAYLOAD),
        )));

        $document = new DOMDocument();
        @$document->loadHTML($html);

        $this->assertSame(0, $document->getElementsByTagName('script')->length);

        foreach ($document->getElementsByTagName('input') as $input)
        {
            if ($input->getAttribute('name') === 'prefill[name]')
            {
                $this->assertSame(self::ATTR_PAYLOAD, $input->getAttribute('value'));

                return;
            }
        }

        $this->fail('prefill[name] field not found');
    }
}
