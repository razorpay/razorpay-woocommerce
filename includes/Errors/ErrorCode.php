<?php

namespace Razorpay\Woocommerce\Errors;

require_once __DIR__.'/../../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Errors as ApiErrors;

class ErrorCode extends ApiErrors\ErrorCode
{
    const INVALID_CURRENCY_ERROR_CODE          = 'INVALID_CURRENCY_ERROR';
    const INVALID_CURRENCY_ERROR_MESSAGE       = 'The selected currency is invalid.';

    const WOOCS_MISSING_ERROR_CODE             = 'WOOCS_MISSING_ERROR';
    const WOOCS_MISSING_ERROR_MESSAGE          = 'The Woocommerce Currency Switcher plugin is missing.';

    const WOOCS_CURRENCY_MISSING_ERROR_CODE    = 'WOOCS_CURRENCY_MISSING_ERROR';
    const WOOCS_CURRENCY_MISSING_ERROR_MESSAGE = 'The current currency and INR needs to be configured in Woocommerce Currency Switcher plugin';

}
