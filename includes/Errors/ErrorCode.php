<?php

namespace Razorpay\Woocommerce\Errors;

require_once __DIR__.'/../../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Errors as ApiErrors;

class ErrorCode extends ApiErrors\ErrorCode
{
    const INVALID_CURRENCY_ERROR_CODE          = 'INVALID_CURRENCY_ERROR';
    const WOOCS_CURRENCY_MISSING_ERROR_CODE    = 'WOOCS_CURRENCY_MISSING_ERROR';
    const WOOCS_MISSING_ERROR_CODE             = 'WOOCS_MISSING_ERROR';

    const WOOCS_MISSING_ERROR_MESSAGE          = 'The WooCommerce Currency Switcher plugin is missing.';
    const INVALID_CURRENCY_ERROR_MESSAGE       = 'The selected currency is invalid.';
    const WOOCS_CURRENCY_MISSING_ERROR_MESSAGE = 'Woocommerce Currency Switcher plugin is not configured with INR correctly';

    // Subscription related errors
    const API_SUBSCRIPTION_CREATION_FAILED     = 'Razorpay API subscription creation failed';
    const API_SUBSCRIPTION_CANCELLATION_FAILED = 'Razorpay API subscription cancellation failed';
    const API_PLAN_CREATION_FAILED             = 'Razorpay API plan creation failed';
    const API_CUSTOMER_CREATION_FAILED         = 'Razorpay API customer creation failed';
}
