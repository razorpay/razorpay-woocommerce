<?php

use Razorpay\Api\Api;

class OneCCAddressSync
{
    const GET_CONFIGS_API = '1cc/merchant/address_ingestion/config';
    const POST_ADDRESSES_API = '1cc/merchant/address_ingestion/addresses';
    const GET = 'GET';
    const POST = 'POST';

    private $apiRequestRetryCount = 5;
    private $apiRequestRetryDelay = 2; //in seconds
    private $postAddressDelay = 3;
    private $backOffRetryCount = 10;
    private $batchSize = 50;

    protected $api;
    private $jobConfig;
    private $oneCCOnboardedTimestamp;
    private $checkpoint;

    public function __construct($api)
    {
        $this->api = $api;
    }

    // makeAPICall to make http api call
    private function makeAPICall($url, $method, $body)
    {
        $retryCount = 0;
        $statusCode = 0;
        while($retryCount < $this->apiRequestRetryCount)
        {
            $retryCount++;
            try
            {
                $response = $this->api->request->request($method, $url, $body);
                rzpLogInfo("makeAPICall: url: ". $url . " is success");
                return [Constants::BODY => $response, Constants::IS_SUCCESS => true];
            }
            catch (\Razorpay\Api\Errors\Error $e)
            {
                error_log($e->getMessage());
                $statusCode = $e->getHttpStatusCode();
                rzpLogError("makeAPICall: message:" . $e->getMessage() . ", url: " . $url . ", method: " . $method . ", retryCount: " . $retryCount . ", statusCode : " . $statusCode);
            }
            catch (Exception $e)
            {
                error_log($e->getMessage());
            }
            sleep($this->apiRequestRetryDelay);
        }
        return [Constants::IS_SUCCESS => false, Constants::STATUS_CODE => $statusCode];
    }

    // getAddressSyncConfigs -> to get address sync respective configs
    private function getAddressSyncConfigs($keys = [])
    {
        $body = [Constants::PLATFORM => Constants::WOOCOMMERCE, Constants::KEYS => $keys];
        return $this->makeAPICall(self::GET_CONFIGS_API, self::GET, $body);
    }

    private function postAddresses($body)
    {
        $body[Constants::SOURCE] = Constants::WOOCOMMERCE;
        if (isset($body[Constants::ADDRESSES]))
        {
            $body[Constants::CHECKPOINT] = $this->checkpoint;
        }
        return $this->makeAPICall(self::POST_ADDRESSES_API, self::POST, $body);
    }

    private function isMerchantEligible()
    {
        //get address sync configs
        $response = $this->getAddressSyncConfigs();
        if (!$response[Constants::IS_SUCCESS])
        {
            $this->handleGetJobConfigsFailure($response);
            return false;
        }
        $configs = $response[Constants::BODY];

        $message = '';
        $isEligible = true;
        // check if merchant is valid one cc merchant
        if (isset($configs[Constants::ONE_CLICK_CHECKOUT]) &&
            $configs[Constants::ONE_CLICK_CHECKOUT] === false)
        {
            $isEligible = false;
            $message = Constants::INVALID_1CC_MERCHANT;
        }
        // check if merchant disabled address sync
        else if (isset($configs[Constants::ONE_CC_ADDRESS_SYNC_OFF]) &&
            $configs[Constants::ONE_CC_ADDRESS_SYNC_OFF] === true)
        {
            $isEligible = false;
            $message = Constants::ADDRESS_SYNC_OFF_CONFIGURED;
        }
        if (!$isEligible)
        {
            rzpLogInfo("isMerchantEligible: address sync cancelled due to ". $message);
            $response = $this->postAddresses(
                [
                    Constants::META_DATA => [
                        Constants::STATUS => Constants::CANCELLED,
                        Constants::MESSAGE => $message,
                    ]
                ]
            );
            if ($response[Constants::IS_SUCCESS])
            {
                deleteOneCCAddressSyncCron(Constants::CANCELLED, $message);
            }
            else
            {
                $this->handleCronFailure(Constants::POST_ADDRESS_MARK_CANCELLED_ERROR);
            }
            return false;
        }

        // check if addresses are already synced
        if(isset($configs) && isset($configs[Constants::JOB]) && isset($configs[Constants::ONE_CC_ONBOARDED_TIMESTAMP]))
        {
            if( isset($configs[Constants::JOB][Constants::STATUS]) && $configs[Constants::JOB][Constants::STATUS] === Constants::COMPLETED )
            {
                deleteOneCCAddressSyncCron(Constants::COMPLETED);
                return false;
            }
            $this->jobConfig = $configs[Constants::JOB];
            $this->oneCCOnboardedTimestamp = $configs[Constants::ONE_CC_ONBOARDED_TIMESTAMP];
            return true;
        }
        return false;
    }

    private function isValidAddress($address)
    {
        if($this->isEmpty($address['line1']) && $this->isEmpty($address['line2']))
        {
            return false;
        }
        if( strlen($address['name']) === 1 ||
            $this->isEmpty($address['city']) ||
            $this->isEmpty($address['state']) ||
            $this->isEmpty($address['zipcode']) ||
            $this->isEmpty($address['country']) ||
            $this->isEmpty($address['phone'])
        )
        {
            return false;
        }
        return true;
    }

    private function isEmpty($data)
    {
        return strlen($data) == 0;
    }

    // getOrders -> to get woocommerce completed address based on checkpoint and pagination
    private function getOrders()
    {
        return wc_get_orders(
            array(
                Constants::SHIPPING_COUNTRY => Constants::IN,
                Constants::DATE_CREATED => 1 . '...' . $this->oneCCOnboardedTimestamp,
                Constants::ORDER => Constants::ASC,
                Constants::LIMIT => $this->batchSize,
                Constants::PAGED => $this->checkpoint,
            )
        );
    }

    // getAddressFromOrders -> get address from orders array
    private function getAddressFromOrders($orders)
    {
        $addresses = array();
        foreach ($orders as $order) {
            $address = array();
            $address['phone'] = strlen(trim($order->get_shipping_phone())) !== 0 ? trim($order->get_shipping_phone()) : trim($order->get_billing_phone()) ;
            $address['name'] = trim($order->get_shipping_first_name()) . ' ' . trim($order->get_shipping_last_name());
            $address['line1']= trim($order->get_shipping_address_1());
            $address['line2'] = trim($order->get_shipping_address_2());
            $address['city'] = trim($order->get_shipping_city());
            $address['state'] = trim($order->get_shipping_state());
            $address['country'] = trim($order->get_shipping_country());
            $address['zipcode'] = trim($order->get_shipping_postcode());
            if ($this->isValidAddress($address))
            {
                $addresses[] = $address;
            }
        }
        return $addresses;
    }

    // getAddresses -> Iterate woocommerce completed orders for getting customer
    // address and return Indian addresses
    private function getAddresses()
    {
        $addresses = array();
        while(sizeof($addresses) === 0)
        {
            $this->checkpoint += 1;
            rzpLogInfo("getAddresses: Checkpoint: " . $this->checkpoint);
            $orders = $this->getOrders();
            rzpLogInfo("getAddresses: orders size : " . sizeof($orders));
            if (sizeof($orders) === 0)
            {
                break;
            }
            $addresses = $this->getAddressFromOrders($orders);
            rzpLogInfo("getAddresses: addresses size : " . sizeof($addresses));
        }
        return $addresses;
    }

    private function handleGetJobConfigsFailure($response)
    {
        $statusCode = $response[Constants::STATUS_CODE];
        $message = 'get_address_sync_configs_'. $statusCode . '_error';
        if($statusCode >= 400 and $statusCode < 500)
        {
            deleteOneCCAddressSyncCron(Constants::FAILED, $message);
        }
    }

    private function handleCronFailure($message)
    {
        rzpLogError("handleCronFailure: message=". $message );
        updateAddressSyncCronData(Constants::FAILED, $message);
    }

    private function markStatusAsCompleted()
    {
        $response = $this->postAddresses(
            [
                Constants::META_DATA => [
                    Constants::STATUS => Constants::COMPLETED,
                    Constants::MESSAGE => Constants::ADDRESS_SYNC_COMPLETED,
                ]
            ]
        );
        if (!$response[Constants::IS_SUCCESS])
        {
            rzpLogError("markStatusAsCompleted: updating address_sync job status as completed error");
            $this->handleCronFailure(Constants::POST_ADDRESS_MARK_COMPLETED_ERROR);
        }
        else
        {
            rzpLogInfo("markStatusAsCompleted: updating address_sync job status as completed success");
            deleteOneCCAddressSyncCron(Constants::COMPLETED, Constants::ADDRESS_SYNC_COMPLETED);
        }
    }

    private function syncAddresses($endTime)
    {
        $addressData = $this->getAddresses();

        // if order Data is empty make job as completed and delete cron
        if (sizeof($addressData) === 0)
        {
            $this->markStatusAsCompleted();
            return false;
        }

        rzpLogInfo("Address Data size: " . sizeof($addressData));
        $response = $this->postAddresses([Constants::ADDRESSES => $addressData]);
        if (!$response[Constants::IS_SUCCESS])
        {
            rzpLogError("syncAddresses: post address error");
            $this->handleCronFailure(Constants::POST_ADDRESSES_ERROR);
            return false;
        }

        sleep($this->postAddressDelay);

        $retryCount = 1;
        while (time() <= $endTime)
        {
            $duration = pow(2, $retryCount);

            rzpLogInfo("syncAddresses: retryCount: " . $retryCount . ", sleep duration: " . $duration);
            sleep($duration);

            $response = $this->getAddressSyncConfigs([Constants::JOB]);
            if (!$response[Constants::IS_SUCCESS])
            {
                $this->handleGetJobConfigsFailure($response);
                return false;
            }

            $config = $response[Constants::BODY];

            if (isset($config[Constants::JOB]))
            {
                $job = $config[Constants::JOB];
                $jobCheckpoint = $job[Constants::CHECKPOINT];
                if ($this->checkpoint === $jobCheckpoint)
                {
                    return true;
                }
            }
            $retryCount++;
            if ($retryCount > $this->backOffRetryCount)
            {
                rzpLogInfo("syncAddresses: checkpoint not updated after maximum retry");
                $response = $this->postAddresses([
                    Constants::META_DATA => [
                        Constants::STATUS => Constants::PAUSED,
                        Constants::MESSAGE => Constants::CHECKPOINT_NOT_UPDATED,
                    ]
                ]);
                if (!$response[Constants::IS_SUCCESS])
                {
                    $this->handleCronFailure(Constants::POST_ADDRESS_MARK_PAUSED_CHECKPOINT_NOT_UPDATED_ERROR);
                }
                else
                {
                    updateAddressSyncCronData(Constants::PAUSED, Constants::CHECKPOINT_NOT_UPDATED);
                }
                return false;
            }
        }
        rzpLogInfo("syncAddresses: " . Constants::MAX_RUNNING_TIME_REACHED);
        $response = $this->postAddresses([
            Constants::META_DATA => [
                Constants::STATUS => Constants::PAUSED,
                Constants::MESSAGE => Constants::MAX_RUNNING_TIME_REACHED,
            ]
        ]);
        if (!$response[Constants::IS_SUCCESS])
        {
            $this->handleCronFailure(Constants::POST_ADDRESS_MARK_PAUSED_MAX_RUNNING_TIME_REACHED_ERROR);
        }
        else
        {
            updateAddressSyncCronData(Constants::PAUSED, Constants::MAX_RUNNING_TIME_REACHED);
        }
        return false;
    }

    public function sync()
    {
        try
        {
            if (!$this->isMerchantEligible())
            {
                return;
            }
            $endTime = time() + 1800;

            rzpLogInfo("Sync: Cron job is processing");
            updateAddressSyncCronData(Constants::PROCESSING);

            $this->checkpoint = $this->jobConfig[Constants::CHECKPOINT] ?? 0;
            while (1)
            {
                $isSuccess = $this->syncAddresses($endTime);
                if (!$isSuccess)
                {
                    return;
                }
            }
        }
        catch (Exception $e)
        {
            rzpLogError("sync: ". Constants::UNHANDLED_EXCEPTION_OCCURRED_IN_CRON . ": " . $e->getMessage(). ", trace:" . $e->getTraceAsString());
            $response = $this->postAddresses([
                Constants::META_DATA => [
                    Constants::STATUS => Constants::FAILED,
                    Constants::MESSAGE => Constants::UNHANDLED_EXCEPTION_OCCURRED_IN_CRON . $e->getMessage(),
                    Constants::TRACE => $e->getTraceAsString(),
                ]
            ]);
            if (!$response[Constants::IS_SUCCESS]) {
                $this->handleCronFailure(Constants::UNHANDLED_EXCEPTION_OCCURRED_IN_CRON);
            }
        }
    }
}

function createOneCCAddressSyncCron()
{
    rzpLogInfo('Trying to create one_cc_address_sync_cron');
    $addressSyncCronData = get_option(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK);
    $isAddressSynced = isAddressSynced($addressSyncCronData);
    if ($isAddressSynced)
    {
        rzpLogInfo('Failed to create one_cc_address_sync_cron as addresses already synced');
        deleteOneCCAddressSyncCron(Constants::COMPLETED);
        return;
    }

    $paymentSettings = get_option('woocommerce_razorpay_settings');
    $isValidOneCCMerchant = isValidOneCCMerchant($paymentSettings);
    if (!$isValidOneCCMerchant)
    {
        rzpLogInfo('Failed to create one_cc_address_sync_cron as invalid one cc merchant');
        deleteOneCCAddressSyncCron(Constants::CANCELLED, Constants::INVALID_1CC_MERCHANT, true);
        return;
    }

    try
    {
        createCron(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK, strtotime("today 18:30"), 'daily');
        rzpLogInfo('create one_cc_address_sync_cron successful');
    }
    catch (Exception $e)
    {
        rzpLogError($e->getMessage());
    }
    if ($addressSyncCronData !== false)
    {
        updateAddressSyncCronData(Constants::PROCESSING);
    }
    else
    {
        $data = [
            Constants::STATUS => Constants::PROCESSING,
            Constants::UPDATED_AT => time(),
            Constants::CREATED_AT => time(),
            Constants::CRON_STATUS => 'created'];
        add_option(
            Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK,
            $data,
        );
    }
}

function isCronFailingForMoreThan7Days($data, $status, $message)
{
    if($data === false || sizeof($data) === 0)
    {
        return false;
    }
    if(!isset($data[Constants::STATUS]) || !isset($data[Constants::MESSAGE]) || !isset($data[Constants::UPDATED_AT]))
    {
        return false;
    }
    if($data[Constants::STATUS] !== $status || $data[Constants::MESSAGE] !== $message)
    {
        return false;
    }
    if ($data[Constants::UPDATED_AT] + Constants::SEVEN_DAYS_IN_SECONDS <= time())
    {
        return true;
    }
    return false;
}

function deleteOneCCAddressSyncCron($status='', $message='', $isWooCConfig=false)
{
    rzpLogInfo("Attempting to delete one_cc_address_sync_cron, status: " . $status . ", message: " . $message);
    $data = get_option(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK);
    if($status === Constants::COMPLETED || $status === Constants::DEACTIVATED || $isWooCConfig ||
        isCronFailingForMoreThan7Days($data, $status, $message))
    {
        deleteCron(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK);
        rzpLogInfo("Deleted one_cc_address_sync_cron  if exists");
        updateAddressSyncCronData($status, $message, $data, Constants::DELETED);
        return;
    }
    rzpLogInfo("one_cc_address_sync_cron not deleted");
    updateAddressSyncCronData($status, $message, $data);
}

function isValidOneCCMerchant($paymentSettings)
{
    if ($paymentSettings === false)
    {
        return false;
    }

    $keyId = $paymentSettings[Constants::KEY_ID];
    $keySecret = $paymentSettings[Constants::KEY_SECRET];
    $enable1cc = '';
    if (array_key_exists(Constants::ENABLE_1CC, $paymentSettings))
    {
        $enable1cc = $paymentSettings[Constants::ENABLE_1CC];
    }

    return $enable1cc === 'yes' && strlen($keyId) !== 0 && strlen($keySecret) !== 0;
}

function updateAddressSyncCronData(string $status, string $message = '', $data = [], string $cronStatus = 'created')
{
    rzpLogInfo("updateAddressSyncCronData: status=" . $status . ", message=" . $message . ", cronStatus=" . $cronStatus);
    if ($data === false ||sizeof($data) === 0)
    {
        $data = get_option(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK);
    }
    if ($status === $data[Constants::STATUS] && $message === $data[Constants::MESSAGE] && $cronStatus === $data[Constants::CRON_STATUS])
    {
        return;
    }
    if (strlen($status) > 0)
    {
        $data[Constants::STATUS] = $status;
    }
    if (strlen($cronStatus) > 0 )
    {
        $data[Constants::CRON_STATUS] = $cronStatus;
    }
    $data[Constants::MESSAGE] = $message;
    $data[Constants::UPDATED_AT] = time();
    update_option(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK, $data);
    rzpLogInfo("updateAddressSyncCronData: successfully updated");
}

function one_cc_address_sync_cron_exec()
{
    try
    {
        $paymentSettings = get_option('woocommerce_razorpay_settings');
        if (!isValidOneCCMerchant($paymentSettings))
        {
            rzpLogInfo("one_cc_address_sync_cron_exec: invalid one cc merchant");
            deleteOneCCAddressSyncCron(Constants::CANCELLED, Constants::INVALID_1CC_MERCHANT, true);
            return;
        }

        $api = new Api($paymentSettings[Constants::KEY_ID], $paymentSettings[Constants::KEY_SECRET]);

        $oneCCAddressSync = new OneCCAddressSync($api);

        $oneCCAddressSync->sync();
    }
    catch (Exception $e)
    {
        rzpLogError($e->getMessage());
    }
}

function isAddressSynced($oneCCAddressSyncCronData)
{
    if ($oneCCAddressSyncCronData === false)
    {
        return false;
    }
    else if ($oneCCAddressSyncCronData[Constants::STATUS] === Constants::COMPLETED)
    {
        return true;
    }

    return false;
}
