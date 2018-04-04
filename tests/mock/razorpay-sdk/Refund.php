<?php

namespace Razorpay\Api;

class Refund extends Entity
{

    public function create($attributes = null)
    {
        $data = [
           'id' => 'rfnd_9uwIs0C0JRANyb',
            'entity' => 'refund',
            'amount' => 100,
            'currency' => 'INR',
            'payment_id' => 'pay_9ul1RYIACBKOd',
            'receipt' => "04",
            'created_at' => 1522800550,
        ];

        return static::buildEntity($data);
    }

}
