<?php
/**
 * Class ErrorCodeTest
 *
 * @package Woo_Razorpay
 /**
 *  Error Code test case.
 */

use Razorpay\Woocommerce\Errors as WooErrors;

class ErrorCodeTest extends WP_UnitTestCase
{

	function test_constants()
	{
		$this->assertEquals(WooErrors\ErrorCode::INVALID_CURRENCY_ERROR_CODE, 'INVALID_CURRENCY_ERROR');
		$this->assertEquals(WooErrors\ErrorCode::WOOCS_CURRENCY_MISSING_ERROR_CODE, 'WOOCS_CURRENCY_MISSING_ERROR');
		$this->assertEquals(WooErrors\ErrorCode::WOOCS_MISSING_ERROR_CODE, 'WOOCS_MISSING_ERROR');
	}

	function test_errorMessages()
	{
		$this->assertEquals(WooErrors\ErrorCode::WOOCS_MISSING_ERROR_MESSAGE, 'The WooCommerce Currency Switcher plugin is missing.');
		$this->assertEquals(WooErrors\ErrorCode::INVALID_CURRENCY_ERROR_MESSAGE, 'The selected currency is invalid.');
		$this->assertEquals(WooErrors\ErrorCode::WOOCS_CURRENCY_MISSING_ERROR_MESSAGE, 'Woocommerce Currency Switcher plugin is not configured with INR correctly');
	}
}
