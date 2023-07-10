<?php

namespace Razorpay\MockApi;

class Transfer
{
    public function fetch($id)
    {
        return new Reverse();
    }

    public function all($options = array())
    {
        $request = new Request();

        $relativeUrl = 'payments/Abc123/transfers';

        return $request->request('GET', $relativeUrl, $options);
    }

    public function create($transferData)
    {
        $request = new Request();

        $response = $request->request('POST', 'orders/id', $transferData);

        return $response;
    }
}

class Reverse 
{
    public $id = ['Abc123'];

    function reverse($attributes = array())
    {
        $request = new Request();

        $relativeUrl = 'transfers/reversals';

        return $request->request('POST', $relativeUrl, $attributes);
    }
}
