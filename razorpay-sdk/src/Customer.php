<?php

namespace Razorpay\Api;

class Customer extends Entity
{
    /**
     *  @param $id Customer id description
     */
    public function fetch($id)
    {
        return parent::fetch($id);
    }

    public function create($attributes = array())
    {
        return parent::create($attributes);
    }

    public function edit($attributes = null)
    {
        $entityUrl = $this->getEntityUrl().$this->id;

        return $this->request('PUT', $entityUrl, $attributes);
    }

    public function tokens()
    {
        $token = new Token();

        $token['customer_id'] = $this->id;

        return $token;
    }
}
