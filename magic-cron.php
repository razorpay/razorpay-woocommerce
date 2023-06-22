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
	$merchantKeyId = get_option('woocommerce_razorpay_settings')['key_id'];
	$data = get_plugin_data();
	$pluginData = ["url" => $siteUrl, "keyID" => $merchantKeyId, "plugin_info" => $data];
	//$lastSyncDate = get_option('plugin_cron_sync_date');
	$lastUpdateDate = add_option('plugin_cron_sync_date', time());
	//createPluginFetchCron('one_cc_plugin_sync_cron', 'every-5-minutes', 'daily');
	//createPluginFetchCron('one_cc_plugin_sync_cron', strtotime("today 12:30"), 'daily');



	$url = '1cc/merchant/wooc/plugin';


	// try
    // {
    // 	$paymentSettings = get_option('woocommerce_razorpay_settings');

    // 	$api = new Api($paymentSettings['key_id'], $paymentSettings['key_secret']);
    // 	$response = $api->request->request('POST', $url, $pluginData);


    //     //createPluginFetchCron('one_cc_plugin_sync_cron', strtotime("today 02:00"), 'daily');
    //     createPluginFetchCron('one_cc_plugin_sync_cron','every-5-minutes', 'daily');
        
    //     rzpLogInfo('create one_cc_plugin_sync_cron successful');
    // }
    // catch (Exception $e)
    // {
    //     rzpLogError($e->getMessage());
    // }


}

function testrzpss(){
	$lastUpdateDate = add_option('plugin_cron_sync_date', time());
	//one_cc_plugin_sync_cron_exce();

}

function createPluginFetchCron(string $hookName, int $startTime, string $recurrence)
{
    if (!wp_next_scheduled($hookName))
    {
         wp_schedule_event($startTime, $recurrence, $hookName);
    }

}

