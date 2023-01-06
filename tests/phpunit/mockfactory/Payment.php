<?php

namespace Razorpay\MockApi;

class Payment
{
    /**
     * @param $id Payment id
     */
    public function fetch($id)
    {
        $request = new Request();

        $response = $request->request('POST', 'orders/id', $attributes);

        var_dump($response);

        return $response;
   
    }

    public function refund($attributes = array())
    {
        $refund = new Refund;

        $attributes = array_merge($attributes, array('payment_id' => $this->id));

        return $refund->create($attributes);
    }

}