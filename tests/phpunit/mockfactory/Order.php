<?php

namespace Razorpay\MockApi;

class Order
{
    /**
     * @param $id Order id description
     */
    public function create($attributes = array())
    {
        $request = new Request();

        $response = $request->request('POST', 'orders', $attributes);

        return $response;
    }

    public function fetch($id)
    {
        $request = new Request();

        $response = $request->request('POST', 'orders/id', $attributes);

        return $response;
    }
}

