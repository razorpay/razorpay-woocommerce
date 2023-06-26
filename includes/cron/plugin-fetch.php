<?php
/**
 * plugin Fetch details
 * 
 */
use Razorpay\Api\Api;

add_action('one_cc_plugin_sync_cron', 'one_cc_plugin_sync_cron_exce');


function one_cc_plugin_sync_cron_exce() 
{

	$siteUrl = get_option('siteurl');

    //get all plugin details
	$data = get_plugins();

    //remove additional fields
    foreach(array_keys($data) as $key) {
         unset($data[$key]['DomainPath'], $data[$key]['TextDomain'], $data[$key]['Description'], $data[$key]['AuthorURI']);
    }

	$pluginData = ["url" => $siteUrl, "platform" => "woocommerce", "plugin_info" => $data];
    $lastUpdateDate = add_option('plugin_cron_sync_date_test2', time());


	$url = '1cc/merchant/woocommerce/plugins_list';

	try
    {
    	$paymentSettings = get_option('woocommerce_razorpay_settings');

    	$api = new Api($paymentSettings['key_id'], $paymentSettings['key_secret']);
    	$response = $api->request->request('POST', $url, $pluginData);
        
    }
    catch (Exception $e)
    {
        rzpLogError($e->getMessage());
    }


}

function syncPluginFetchCron(){

    $timestamp = strtotime('+15 days 2:00:00');

    try
    {
        createPluginFetchCron('one_cc_plugin_sync_cron', $timestamp , 'daily');
        rzpLogInfo('create one_cc_plugin_sync_cron successful');
    }
    catch (Exception $e)
    {
        rzpLogError($e->getMessage());
    }
}


function createPluginFetchCron(string $hookName, int $startTime, string $recurrence)
{
    if (!wp_next_scheduled($hookName))
    {
         wp_schedule_event($startTime, $recurrence, $hookName);
    }

}

function deletePluginFetchCron(string $hookName)
{
    $timestamp = wp_next_scheduled( $hookName );
    wp_unschedule_event( $timestamp, $hookName );
}


