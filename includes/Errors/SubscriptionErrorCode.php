<?php

namespace Razorpay\Woocommerce\Errors;

use Razorpay\Api\Errors as ApiErrors;

class SubscriptionErrorCode extends ApiErrors\ErrorCode
{
    // Subscription related errors
    const API_SUBSCRIPTION_CREATION_FAILED     = 'Razorpay API subscription creation failed';
    const API_SUBSCRIPTION_CANCELLATION_FAILED = 'Razorpay API subscription cancellation failed';
    const API_PLAN_CREATION_FAILED             = 'Razorpay API plan creation failed';
    const API_CUSTOMER_CREATION_FAILED         = 'Razorpay API customer creation failed';
    const SUBSCRIPTION_START_DATE_INVALID      = 'Invalid subscription start date specified';
}
