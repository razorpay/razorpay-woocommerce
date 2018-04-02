<?php

use Razorpay\Woocommerce\Errors as WooErrors;

class TestErrorCode extends WP_UnitTestCase
{
    public function test_errorcodes()
    {
        $this->assertEquals(WooErrors\ErrorCode::INVALID_CURRENCY_ERROR_CODE, 'INVALID_CURRENCY_ERROR');
        $this->assertEquals(WooErrors\ErrorCode::WOOCS_CURRENCY_MISSING_ERROR_CODE, 'WOOCS_CURRENCY_MISSING_ERROR');
        $this->assertEquals(WooErrors\ErrorCode::WOOCS_MISSING_ERROR_CODE, 'WOOCS_MISSING_ERROR');
    }

    public function test_errorMessages()
    {
        $this->assertEquals(WooErrors\ErrorCode::WOOCS_MISSING_ERROR_MESSAGE, 'The WooCommerce Currency Switcher plugin is missing.');
        $this->assertEquals(WooErrors\ErrorCode::INVALID_CURRENCY_ERROR_MESSAGE, 'The selected currency is invalid.');
        $this->assertEquals(WooErrors\ErrorCode::WOOCS_CURRENCY_MISSING_ERROR_MESSAGE, 'Woocommerce Currency Switcher plugin is not configured with INR correctly');
    }
}
