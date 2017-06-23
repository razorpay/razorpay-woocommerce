<?php

namespace Razorpay\Api\Errors;

class ErrorCode
{
    const BAD_REQUEST_ERROR                    = 'BAD_REQUEST_ERROR';
    const SERVER_ERROR                         = 'SERVER_ERROR';
    const GATEWAY_ERROR                        = 'GATEWAY_ERROR';

    const INVALID_CURRENCY_ERROR_CODE          = 'INVALID_CURRENCY_ERROR';
    const INVALID_CURRENCY_ERROR_MESSAGE       = 'The selected currency is invalid.';

    const WOOCS_MISSING_ERROR_CODE             = 'WOOCS_MISSING_ERROR';
    const WOOCS_MISSING_ERROR_MESSAGE          = 'The Woocommerce Currency Switcher plugin is missing.';

    const WOOCS_CURRENCY_MISSING_ERROR_CODE    = 'WOOCS_CURRENCY_MISSING_ERROR';
    const WOOCS_CURRENCY_MISSING_ERROR_MESSAGE = 'The current currency and INR needs to be configured in Woocommerce Currency Switcher plugin';

    public static function exists($code)
    {
        $code = strtoupper($code);

        return defined(get_class() . '::' . $code);
    }
}