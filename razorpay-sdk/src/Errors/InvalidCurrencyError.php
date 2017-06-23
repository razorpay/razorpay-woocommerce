<?php

namespace Razorpay\Api\Errors;

class InvalidCurrencyError extends Error
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
