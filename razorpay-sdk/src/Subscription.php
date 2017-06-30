<?php

namespace Razorpay\Api;

class Subscription extends Entity
{
    public function create($attributes = array())
    {
        return parent::create($attributes);
    }

    public function fetch($id)
    {
        return parent::fetch($id);
    }

    public function all($options = array())
    {
        return parent::all($options);
    }

    public function cancel($subscriptionId)
    {
        $relativeUrl = $this->getEntityUrl() . $subscriptionId . '/cancel';

        return $this->request('POST', $relativeUrl);
    }
}
