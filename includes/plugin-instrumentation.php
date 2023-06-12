<?php

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

class TrackPluginInstrumentation
{
    protected $api;
    protected $mode;

    public function __construct($api, $key_id)
    {
        $this->api = $api;
        $this->mode = (substr($key_id, 0, 8) === 'rzp_live') ? 'live' : 'test';

        register_activation_hook(PLUGIN_MAIN_FILE, [$this, 'razorpayPluginActivated'], 10, 2);
        register_deactivation_hook(PLUGIN_MAIN_FILE, [$this, 'razorpayPluginDeactivated'], 10, 2);
        add_action('upgrader_process_complete', [$this, 'razorpayPluginUpgraded'], 10, 2);
    }

    function razorpayPluginActivated()
    {
        $activateProperties = [
            'page_url'            => $_SERVER['HTTP_REFERER'],
            'redirect_to_page'    => $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']
        ];

        $response = $this->rzpTrackSegment('plugin activate', $activateProperties);

        $this->rzpTrackDataLake('plugin activate', $activateProperties);

        $this->initRzpCronJobs();

        return 'success';
    }

    // initCronJobs initialize all cron jobs needed for this Plugin
    function initRzpCronJobs()
    {
        createOneCCAddressSyncCron();
    }

    function razorpayPluginDeactivated()
    {
        global $wpdb;
        $isTransactingUser = false;

        $rzpTrancationData = $wpdb->get_row($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta AS P WHERE meta_key = %s AND meta_value = %s", "_payment_method", "razorpay"));

        $arrayPost = json_decode(json_encode($rzpTrancationData), true);

        if (empty($arrayPost) === false and
            ($arrayPost == null) === false)
        {
            $isTransactingUser = true;
        }

        $deactivateProperties = [
            'page_url'            => $_SERVER['HTTP_REFERER'],
            'is_transacting_user' => $isTransactingUser
        ];

        $response = $this->rzpTrackSegment('plugin deactivate', $deactivateProperties);

        $this->rzpTrackDataLake('plugin deactivate', $deactivateProperties);

        $this->deleteRzpCronJobs();

        return 'success';
    }

    // deleteRzpCronJobs deletes all Cron jobs created for this Plugin
    function deleteRzpCronJobs()
    {
        deleteOneCCAddressSyncCron('deactivated');
    }

    function razorpayPluginUpgraded()
    {
        $prevVersion = get_option('rzp_woocommerce_current_version');
        $upgradeProperties = [
            'page_url'            => $_SERVER['HTTP_REFERER'],
            'prev_version'        => $prevVersion,
            'new_version'         => get_plugin_data(__FILE__)['Version'],
        ];

        $response = $this->rzpTrackSegment('plugin upgrade', $upgradeProperties);

        $this->rzpTrackDataLake('plugin upgrade', $upgradeProperties);

        // TODO: Update correct version
        if (isset($prevVersion) && strcmp($prevVersion, '4.5.0') <= 0)
        {
            createOneCCAddressSyncCron();
        }

        if ($response['status'] === 'success')
        {
            $existingVersion = get_option('rzp_woocommerce_current_version');

            if(isset($existingVersion))
            {
                update_option('rzp_woocommerce_current_version', get_plugin_data(__FILE__)['Version']);
            }
            else
            {
                add_option('rzp_woocommerce_current_version', get_plugin_data(__FILE__)['Version']);
            }

            return 'success';
        }
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

            $response = wp_remote_post('https://lumberjack.razorpay.com/v1/track', $requestArgs);

            if (is_wp_error($response))
            {
                error_log($response->get_error_message());
            }

            return $response;
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

$paymentSettings = get_option('woocommerce_razorpay_settings');
if ($paymentSettings !== false)
{
    $api = new Api($paymentSettings['key_id'], $paymentSettings['key_secret']);

    new TrackPluginInstrumentation($api, $paymentSettings['key_id']);
}

function rzpInstrumentation()
{
    $paymentSettings = get_option('woocommerce_razorpay_settings');

    if ($paymentSettings === false)
    {
        return;
    }

    $api = new Api($paymentSettings['key_id'], $paymentSettings['key_secret']);

    $trackObject = new TrackPluginInstrumentation($api, $paymentSettings['key_id']);
    $properties = $_POST['properties'];

    if ($_POST['event'] === "signup.initiated" or
        $_POST['event'] === "login.initiated")
    {
        if (empty($paymentSettings['key_id']) === false and
            empty($paymentSettings['key_secret']) === false)
        {
            $properties['is_plugin_merchant'] = true;
            $properties['is_registered_on_razorpay'] = true;
        }
        else
        {
            $properties['is_plugin_merchant'] = false;
            $properties['is_registered_on_razorpay'] = false;
        }
    }

    $trackObject->rzpTrackSegment($_POST['event'], $properties);
    $trackObject->rzpTrackDataLake($_POST['event'], $properties);

    wp_die();
}
add_action("wp_ajax_rzpInstrumentation", "rzpInstrumentation");
