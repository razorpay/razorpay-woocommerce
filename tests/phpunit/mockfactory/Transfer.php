<?php

namespace Razorpay\MockApi;

class Transfer
{
    public function fetch($id)
    {
        $request = new Request();

        $response = $request->request('POST', 'orders/id', $attributes);

        return $response;
    }

    public function all($options = array())
    {
        $request = new Request();

        $relativeUrl = 'payments/Abc123/transfers';

        return $request->request('GET', $relativeUrl, $options);
    }
}
