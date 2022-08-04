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
                'properties' => array_merge($properties, $this->getDefaultProperties())
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

    public function getDefaultProperties()
    {
        global $wp_version;
        $pluginData = get_plugin_data(plugin_dir_path(__FILE__) . '/../woo-razorpay.php');

        return [
            'platform'            => 'WordPress',
            'platform_version'    => $wp_version,
            'woocommerce_version' => WOOCOMMERCE_VERSION,
            'plugin_name'         => $pluginData['Name'],
            'plugin_version'      => $pluginData['Version'],
            'unique_id'           => $_SERVER['HTTP_HOST'],
            'event_timestamp'     => time(),
        ];
    }
}
