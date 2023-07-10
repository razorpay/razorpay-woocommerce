<?php

namespace Razorpay\MockApi;

class Payment
{
    public $id = ['Abc123'];

    public function fetch($paymentid)
    {
        return new Payment();
    }

    function transfers($options = array())
    {
        $request = new Request();

        $relativeUrl = 'payments/Abc123/transfers';

        return $request->request('GET', $relativeUrl, $options);
    }

    function transfer($options = array())
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
