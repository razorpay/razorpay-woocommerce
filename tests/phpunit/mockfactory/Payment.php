<?php

namespace Razorpay\MockApi;

class Payment
{
    public function fetch($paymentid)
    {
        return new Data();
    }
}

class Data 
{
    public $id = ['Abc123'];

    function transfers()
    {
        $request = new Request();

        $relativeUrl = 'payments/Abc123/transfers';

        return $request->request('GET', $relativeUrl, $options);
    }

    function refund()
    {
        return (object)array('id' => 'abc');
    }
}
