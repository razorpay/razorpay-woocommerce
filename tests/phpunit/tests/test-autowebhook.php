<?php

/**
 * @covers \WC_Razorpay
 */

require_once __DIR__ . '/../mockfactory/MockApi.php';
require_once __DIR__ . '/../mockfactory/Request.php';
require_once __DIR__ . '/../mockfactory/Order.php';

use Razorpay\MockApi\MockApi;

class Test_AutoWebhook extends WP_UnitTestCase
{
    private $instance;

    public function setup(): void
    {
        parent::setup();
        $this->instance = Mockery::mock('WC_Razorpay')->makePartial()->shouldAllowMockingProtectedMethods();
        $_POST = array();
    }

    public function testEmptyKeyAndSecretValidation()
    {
        $this->instance->shouldReceive('getSetting')->andReturnUsing(function ($key) {
            if ($key == 'key_id')
            {
                return null;
            }
            else
            {
                return null;
            }
        });

        ob_start();
        $response = $this->instance->autoEnableWebhook();
        $response = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString('Key Id and Key Secret are required', $response);
    }

    /*
    public function testInvalidKeyAndSecretValidation()
    {
        $this->instance->shouldReceive('getSetting')->andReturnUsing(function ($key) {
            if ($key == 'key_id')
            {
                return 'key_id';
            }
            else
            {
                return 'key_secret';
            }
        });

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(function () {
            return new MockApi('invalid_key_id', 'invalid_key_secret');
        });

        ob_start();
        $response = $this->instance->autoEnableWebhook();
        $response = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString('Please check Key Id and Key Secret', $response);
    }

    */ 
    
    public function testWebhookFailedForLocalhost()
    {
        $this->instance->shouldReceive('getSetting')->andReturnUsing(function ($key) {
            if ($key == 'key_id')
            {
                return 'key_id';
            }
            else
            {
                return 'key_secret';
            }
        });

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(function () {
            return new MockApi('key_id', 'key_secret');
        });

        ob_start();
        $response = $this->instance->autoEnableWebhook();
        $response = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString('Could not enable webhook for localhost server', $response);
    }

    public function testAutoCreateWebhook()
    {
        $this->instance->shouldReceive('getSetting')->andReturnUsing(function ($key) {
            if ($key == 'key_id')
            {
                return 'key_id';
            }
            else
            {
                return 'key_secret';
            }
        });

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(function () {
            return new MockApi('key_id', 'key_secret');
        });

        $this->instance->shouldReceive('getWebhookUrl')->andReturn("https://webhook.site/create");

        $response = $this->instance->autoEnableWebhook();

        $this->assertSame('create', $response['id']);
        $this->assertSame('https://webhook.site/create', $response['url']);
        $this->assertSame('webhook', $response['entity']);
        $this->assertTrue($response['active']);
        $this->assertNotNull($response['events']);
        $this->assertTrue($response['events']['payment.authorized']);
        $this->assertTrue($response['events']['order.paid']);
    }

    public function testAutoUpdateWebhook()
    {
        $this->instance->shouldReceive('getSetting')->andReturnUsing(function ($key) {
            if ($key == 'key_id')
            {
                return 'key_id_1';
            }
            else
            {
                return 'key_secret';
            }
        });

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(function () {
            return new MockApi('key_id_1', 'key_secret');
        });

        $this->instance->shouldReceive('getWebhookUrl')->andReturn("https://webhook.site/update");

        $response = $this->instance->autoEnableWebhook();

        $this->assertSame('update', $response['id']);
        $this->assertSame('https://webhook.site/update', $response['url']);
        $this->assertSame('webhook', $response['entity']);
        $this->assertTrue($response['active']);
        $this->assertNotNull($response['events']);
        $this->assertTrue($response['events']['payment.authorized']);
        $this->assertTrue($response['events']['order.paid']);
    }
}
