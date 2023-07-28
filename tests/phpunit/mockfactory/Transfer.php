<?php

namespace Razorpay\MockApi;

class Transfer
{
    public $id = ['Abc123'];
    
    public function fetch($id)
    {
        return new Transfer();
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

    function reverse($attributes = array())
    {
        $request = new Request();

        $relativeUrl = 'transfers/reversals';

        return $request->request('POST', $relativeUrl, $attributes);
    }
}
