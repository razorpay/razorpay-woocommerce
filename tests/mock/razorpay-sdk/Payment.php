<?php

namespace Razorpay\Api;

class Payment extends Entity
{
    public function fetch($id)
    {
        $data = [
            'id' => $id,
            'entity' => 'payment',
            'currency' => 'INR',
            'status' => 'captured',
            'order_id' => 'order_9ul1RYIACBKOd',
            'invoice_id' => 'invoice_9ul1RYIACBKOd',
            'international' => 'false',
            'method' => 'card',
            'amount_refunded' => 'null',
            'refund_status' => 'null',
            'captured' => 'true',
            'description' => '"Order 04',
            'card_id' => 'card_9rwfCGiyasssss',
            'bank' => null,
            'vpa' => null,
            'email' => 'test@razorpay.org',
            'contact' => '555-32123',
            'notes' => [
                'woocommerce_order_id' => '04',
            ],
        ];

        return static::buildEntity($data);
    }

    public function refund($attributes)
    {
        $refund = new Refund;

        $attributes = array_merge($attributes, array('payment_id' => $this->id));

        return $refund->create($attributes);
    }
}
