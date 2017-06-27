<?php

namespace RazorpayWoo\Errors;

require_once __DIR__.'/../../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Errors;

class InvalidCurrencyError extends Errors\Error
{
    protected $field = null;

    public function __construct($field = null)
    {
        parent::__construct($message, $code, $httpStatusCode);

        $this->field = $field;
    }

    public function getField()
    {
        return $this->field;
    }
}
