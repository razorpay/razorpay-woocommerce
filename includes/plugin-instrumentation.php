<?php

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

class TrackPluginInstrumentation
{
    protected $api;
    protected $mode;

    public function __construct($key_id, $key_secret)
    {
        $this->api = new Api($key_id, $key_secret);
        $this->mode = (substr($key_id, 0, 8) === 'rzp_live') ? 'live' : 'test';
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

    public function rzpTrackDataLake($event, $properties)
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

            $requestArgs = [
                'timeout'   => 45,
                'headers'   => [
                    'Content-Type'  => 'application/json'
                ],
                'body'      => json_encode(
                    [
                        'mode'   => $this->mode,
                        'key'    => '0c08FC07b3eF5C47Fc19B6544afF4A98',
                        'events' => [
                            [
                                'event_type'    => 'plugin-events',
                                'event_version' => 'v1',
                                'timestamp'     => time(),
                                'event'         => str_replace(' ', '.', $event),
                                'properties'    => array_merge($properties, $this->getDefaultProperties(false))
                            ]
                        ]
                    ]
                ),
            ];

            $response = wp_remote_post( 'https://lumberjack.razorpay.com/v1/track', $requestArgs);

            if (is_wp_error($response))
            {
                error_log($response->get_error_message());
            }
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

    public function getDefaultProperties($timestamp = true)
    {
        global $wp_version;
        $pluginData = get_plugin_data(plugin_dir_path(__FILE__) . '/../woo-razorpay.php');

        $defaultProperties = [
            'platform'            => 'WordPress',
            'platform_version'    => $wp_version,
            'woocommerce_version' => WOOCOMMERCE_VERSION,
            'plugin_name'         => $pluginData['Name'],
            'plugin_version'      => $pluginData['Version'],
            'unique_id'           => $_SERVER['HTTP_HOST']
        ];

        if ($timestamp)
        {
            $defaultProperties['event_timestamp'] = time();
        }

        return $defaultProperties;
    }
}
