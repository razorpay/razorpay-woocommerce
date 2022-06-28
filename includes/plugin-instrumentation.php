<?php

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

class TrackPluginInstrumentation
{
    public function rzpTrackSegment($properties)
    {
        try
        {
            $api = $this->getRazorpayApiInstance();
            $response = $api->request->request('POST', 'plugins/segment', $properties);
        }
        catch (\Razorpay\Api\Errors\Error $e)
        {
            error_log($e->getMessage());
        }
        catch (\Exception $e)
        {
            error_log($e->getMessage());
        }
    }

    public function rzpTrackDataLake($properties)
    {
        try
        {

        }
        catch (\Razorpay\Api\Errors\Error $e)
        {
            error_log($e->getMessage());
        }
        catch (\Exception $e)
        {
            error_log($e->getMessage());
        }
    }

    public function getRazorpayApiInstance()
    {
        $razorpaySettings = get_option('woocommerce_razorpay_settings');
        return new Api($razorpaySettings['key_id'], $razorpaySettings['key_secret']);
    }
}
