<?php

namespace Razorpay\Api;

class Order extends Entity
{
    public function create($attributes = null)
    {
        $woocommerceOrderID = $attributes['woocommerce_order_id'];

        $data =  [
            "id" => "order_9ul1RYIACBKOd",
            "entity" => "order",
            "amount" => "1000",
            "amount_paid" => "0",
            "amount_due" => "1000",
            "currency" => "INR",
            "receipt" => $woocommerceOrderID,
            "offer_id" => "",
            "status" => "created",
            "attempts" => 0,
            "notes" =>
                [
                    "woocommerce_order_id" => $woocommerceOrderID,
                ],
            "created_at" => "1522760822",
        ];

        return static::buildEntity($data);
    }
}



