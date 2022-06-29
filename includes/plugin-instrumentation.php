<?php

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

class TrackPluginInstrumentation
{
    public $api;

    public function __construct($key_id, $key_secret)
    {
        $this->api = new Api($key_id, $key_secret);
    }

    public function rzpTrackSegment($event, $properties)
    {
        try
        {
            $data = [
                'event'      => $event,
                'properties' => $properties
            ];

            $response = $this->api->request->request('POST', 'plugins/segment', $data);
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
}
