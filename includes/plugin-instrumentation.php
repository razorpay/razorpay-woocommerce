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
            if (empty($event) === true or
                is_string($event) === false)
            {
                return ['status' => 'error', 'message' => 'event given as input is not valid'];
            }

            if (empty($properties) === true or
                is_array($properties) === false)
            {
                return ['status' => 'error', 'message' => 'properties given as input is not valid'];
            }

            $data = [
                'event'      => $event,
                'properties' => $properties
            ];

            $response = $this->api->request->request('POST', 'plugins/segment', $data);

            return $response;
        }
        catch (\Razorpay\Api\Errors\Error $e)
        {
            error_log($e->getMessage());
            return ['status' => 'error'];
        }
        catch (\Exception $e)
        {
            error_log($e->getMessage());
            return ['status' => 'error'];
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
