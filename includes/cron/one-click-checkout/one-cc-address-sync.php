<?php

use Razorpay\Api\Api;

class OneCCAddressSync
{
    const GET_CONFIGS_API    = '1cc/merchant/address_ingestion/config';
    const POST_ADDRESSES_API = '1cc/merchant/address_ingestion/addresses';
    const GET = 'GET';
    const POST = 'POST';

    private $apiRequestRetryCount = 3;
    private $apiRequestRetryDelay = 2; // base delay in seconds — doubles each retry (2, 4, 8)
    private $batchSize = 50;

    protected $api;
    private $jobConfig;
    private $oneCCOnboardedTimestamp;
    private $checkpoint;

    public function __construct($api)
    {
        $this->api = $api;
    }

    // makeAPICall — 3 retries with exponential backoff (2s, 4s, 8s)
    private function makeAPICall($url, $method, $body)
    {
        $retryCount = 0;
        $statusCode = 0;
        while ($retryCount < $this->apiRequestRetryCount)
        {
            $retryCount++;
            try
            {
                $response = $this->api->request->request($method, $url, $body);
                rzpLogInfo("makeAPICall: url: " . $url . " is success");
                return [Constants::BODY => $response, Constants::IS_SUCCESS => true];
            }
            catch (\Razorpay\Api\Errors\Error $e)
            {
                $statusCode = $e->getHttpStatusCode();
                rzpLogError("makeAPICall: message:" . $e->getMessage() . ", url: " . $url . ", method: " . $method . ", retryCount: " . $retryCount . ", statusCode: " . $statusCode);
            }
            catch (Exception $e)
            {
                rzpLogError("makeAPICall: unexpected error: " . $e->getMessage() . ", retryCount: " . $retryCount);
            }
            // Exponential backoff: 2s → 4s → 8s (skip sleep after the last attempt)
            if ($retryCount < $this->apiRequestRetryCount)
            {
                sleep($this->apiRequestRetryDelay * pow(2, $retryCount - 1));
            }
        }
        return [Constants::IS_SUCCESS => false, Constants::STATUS_CODE => $statusCode];
    }

    private function getAddressSyncConfigs($keys = [])
    {
        $body = [Constants::PLATFORM => Constants::WOOCOMMERCE, Constants::KEYS => $keys];
        return $this->makeAPICall(self::GET_CONFIGS_API, self::GET, $body);
    }

    private function postAddresses($body)
    {
        $body[Constants::SOURCE]     = Constants::WOOCOMMERCE;
        $body[Constants::CHECKPOINT] = $this->checkpoint;
        return $this->makeAPICall(self::POST_ADDRESSES_API, self::POST, $body);
    }

    private function isMerchantEligible()
    {
        $response = $this->getAddressSyncConfigs();
        if (!$response[Constants::IS_SUCCESS])
        {
            $statusCode = $response[Constants::STATUS_CODE] ?? 0;
            $message    = 'get_address_sync_configs_' . $statusCode . '_error';
            if ($statusCode === 401 || $statusCode === 403)
            {
                // Auth failure — credentials are wrong, permanently remove the cron
                rzpLogError("isMerchantEligible: auth error " . $statusCode . ", removing cron");
                deleteOneCCAddressSyncCron(Constants::FAILED, $message);
            }
            else if ($statusCode === 404)
            {
                // No record yet for this merchant — treat as fresh start, proceed normally
                rzpLogInfo("isMerchantEligible: no config record yet (404), starting fresh");
                $this->jobConfig               = [];
                $this->oneCCOnboardedTimestamp = time();
                return true;
            }
            else
            {
                // 0 (network), 5xx, other 4xx — transient, keep cron, try again tomorrow
                rzpLogError("isMerchantEligible: transient error " . $statusCode . ", will retry tomorrow");
                updateAddressSyncCronData(Constants::FAILED, $message);
            }
            return false;
        }

        $configs = $response[Constants::BODY];

        // Merchant explicitly opted out of address sync
        if (isset($configs[Constants::ONE_CC_ADDRESS_SYNC_OFF]) &&
            $configs[Constants::ONE_CC_ADDRESS_SYNC_OFF] === true)
        {
            rzpLogInfo("isMerchantEligible: address sync off for merchant");
            $this->postAddresses([
                Constants::META_DATA => [
                    Constants::STATUS  => Constants::CANCELLED,
                    Constants::MESSAGE => Constants::ADDRESS_SYNC_OFF_CONFIGURED,
                ]
            ]);
            deleteOneCCAddressSyncCron(Constants::CANCELLED, Constants::ADDRESS_SYNC_OFF_CONFIGURED);
            return false;
        }

        // Resume from saved checkpoint if a job exists; otherwise start fresh from 0
        if (isset($configs[Constants::JOB]))
        {
            $this->jobConfig = $configs[Constants::JOB];
        }
        else
        {
            $this->jobConfig = [];
        }

        // For non-1CC merchants onboarded_timestamp is absent — default to now so
        // getOrders() covers the merchant's entire order history
        $this->oneCCOnboardedTimestamp = $configs[Constants::ONE_CC_ONBOARDED_TIMESTAMP] ?? time();
        return true;
    }

    private function isValidAddress($address)
    {
        if ($this->isEmpty($address['line1']) && $this->isEmpty($address['line2']))
        {
            return false;
        }
        if (strlen($address['name'] ?? '') <= 1        ||
            $this->isEmpty($address['city'])            ||
            $this->isEmpty($address['state'])           ||
            $this->isEmpty($address['zipcode'])         ||
            ($address['country'] ?? '') !== Constants::IN || // must be India
            $this->isEmpty($address['phone']))
        {
            return false;
        }
        return true;
    }

    private function isEmpty($data)
    {
        return strlen(trim((string)($data ?? ''))) === 0;
    }

    // Fetch one page of orders.
    // Uses $this->oneCCOnboardedTimestamp as upper bound — covers entire order history
    // for non-1CC merchants (defaults to time()) and up to onboarding date for 1CC merchants.
    private function getOrders()
    {
        return wc_get_orders([
            Constants::SHIPPING_COUNTRY => Constants::IN,
            Constants::DATE_CREATED     => 1 . '...' . $this->oneCCOnboardedTimestamp,
            'status'                    => ['processing', 'completed', 'on-hold'],
            Constants::ORDER            => Constants::ASC,
            Constants::LIMIT            => $this->batchSize,
            Constants::PAGED            => $this->checkpoint,
        ]);
    }

    // Build address array from orders — shipping preferred, billing fallback for every field.
    private function getAddressFromOrders($orders)
    {
        $addresses = [];
        foreach ($orders as $order)
        {
            $shippingName = trim(trim($order->get_shipping_first_name()) . ' ' . trim($order->get_shipping_last_name()));
            $billingName  = trim(trim($order->get_billing_first_name())  . ' ' . trim($order->get_billing_last_name()));
            $address = [
                'phone'   => strlen(trim($order->get_shipping_phone())) > 0
                                ? trim($order->get_shipping_phone())
                                : trim($order->get_billing_phone()),
                'name'    => strlen($shippingName) > 1 ? $shippingName : $billingName,
                'line1'   => trim($order->get_shipping_address_1()) ?: trim($order->get_billing_address_1()),
                'line2'   => trim($order->get_shipping_address_2()) ?: trim($order->get_billing_address_2()),
                'city'    => trim($order->get_shipping_city())      ?: trim($order->get_billing_city()),
                'state'   => trim($order->get_shipping_state())     ?: trim($order->get_billing_state()),
                'country' => trim($order->get_shipping_country())   ?: trim($order->get_billing_country()),
                'zipcode' => trim($order->get_shipping_postcode())  ?: trim($order->get_billing_postcode()),
            ];
            if ($this->isValidAddress($address))
            {
                $addresses[] = $address;
            }
        }
        return $addresses;
    }

    // Fetch the next page of valid addresses, advancing checkpoint past empty pages.
    // $endTime is passed in so the inner loop respects the run budget even when
    // many consecutive pages have no valid addresses.
    private function getNextBatch($endTime)
    {
        $addresses = [];
        while (empty($addresses) && time() < $endTime)
        {
            $this->checkpoint++;
            rzpLogInfo("getNextBatch: fetching page " . $this->checkpoint);
            $orders = $this->getOrders();
            rzpLogInfo("getNextBatch: orders count = " . count($orders));
            if (empty($orders))
            {
                return []; // no more orders — naturally caught up
            }
            $addresses = $this->getAddressFromOrders($orders);
        }
        return $addresses;
    }

    public function sync()
    {
        try
        {
            if (!$this->isMerchantEligible())
            {
                return;
            }

            $endTime          = time() + Constants::CRON_MAX_SECONDS;
            $this->checkpoint = $this->jobConfig[Constants::CHECKPOINT] ?? 0;

            rzpLogInfo("sync: starting from checkpoint=" . $this->checkpoint . ", endTime in " . Constants::CRON_MAX_SECONDS . "s");
            updateAddressSyncCronData(Constants::PROCESSING);

            while (time() < $endTime)
            {
                $addresses = $this->getNextBatch($endTime);

                if (empty($addresses))
                {
                    if (time() >= $endTime)
                    {
                        // Time ran out inside getNextBatch while scanning pages —
                        // more orders may exist, resume tomorrow from same checkpoint
                        rzpLogInfo("sync: time limit reached while scanning pages at checkpoint=" . $this->checkpoint);
                        updateAddressSyncCronData(Constants::PAUSED, Constants::MAX_RUNNING_TIME_REACHED);
                    }
                    else
                    {
                        // Genuinely no more orders — notify backend and mark complete
                        rzpLogInfo("sync: no more orders at checkpoint=" . $this->checkpoint . ", all caught up");
                        $this->postAddresses([
                            Constants::META_DATA => [
                                Constants::STATUS  => Constants::COMPLETED,
                                Constants::MESSAGE => Constants::ADDRESS_SYNC_COMPLETED,
                            ]
                        ]);
                        updateAddressSyncCronData(Constants::COMPLETED, Constants::ADDRESS_SYNC_COMPLETED);
                    }
                    return;
                }

                rzpLogInfo("sync: posting " . count($addresses) . " addresses at checkpoint=" . $this->checkpoint);

                $response = $this->postAddresses([Constants::ADDRESSES => $addresses]);
                if (!$response[Constants::IS_SUCCESS])
                {
                    rzpLogError("sync: failed to post addresses at checkpoint=" . $this->checkpoint . " after " . $this->apiRequestRetryCount . " retries");
                    updateAddressSyncCronData(Constants::PAUSED, Constants::POST_ADDRESSES_ERROR);
                    return; // resume tomorrow from same checkpoint
                }
            }

            // Time budget exhausted — checkpoint saved, resumes tomorrow
            rzpLogInfo("sync: time limit reached at checkpoint=" . $this->checkpoint);
            updateAddressSyncCronData(Constants::PAUSED, Constants::MAX_RUNNING_TIME_REACHED);
        }
        catch (Exception $e)
        {
            rzpLogError("sync: unhandled exception: " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            updateAddressSyncCronData(Constants::FAILED, Constants::UNHANDLED_EXCEPTION_OCCURRED_IN_CRON . $e->getMessage());
        }
    }
}

// ─── Scheduling ──────────────────────────────────────────────────────────────

function createOneCCAddressSyncCron()
{
    rzpLogInfo('createOneCCAddressSyncCron: attempting to schedule');

    $paymentSettings = get_option('woocommerce_razorpay_settings');
    if (!isValidRazorpayMerchant($paymentSettings))
    {
        rzpLogInfo('createOneCCAddressSyncCron: invalid razorpay merchant, aborting');
        return;
    }

    try
    {
        $startTime = strtotime("today 02:00") > time()
            ? strtotime("today 02:00")
            : strtotime("tomorrow 02:00");
        createCron(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK, $startTime, 'daily');
        rzpLogInfo('createOneCCAddressSyncCron: scheduled at ' . date('Y-m-d H:i:s', $startTime));
    }
    catch (Exception $e)
    {
        if (wp_next_scheduled(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK) === false)
        {
            rzpLogError('createOneCCAddressSyncCron: failed to schedule: ' . $e->getMessage());
            return;
        }
        rzpLogInfo('createOneCCAddressSyncCron: already scheduled, skipping');
    }

    $existing = get_option(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK);
    if ($existing === false)
    {
        add_option(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK, [
            Constants::STATUS     => Constants::PROCESSING,
            Constants::CREATED_AT => time(),
            Constants::UPDATED_AT => time(),
            Constants::MESSAGE    => '',
            Constants::CRON_STATUS => 'created',
        ]);
    }
}

function one_cc_address_sync_cron_exec()
{
    // Concurrency lock — prevent a second instance if the previous run is still alive
    if (get_transient('rzp_addr_sync_running'))
    {
        rzpLogInfo("one_cc_address_sync_cron_exec: already running, skipping");
        return;
    }
    set_transient('rzp_addr_sync_running', 1, Constants::CRON_MAX_SECONDS + 300);

    // Remove PHP execution time cap — best-effort; no-op on locked-down hosts
    set_time_limit(0);
    ignore_user_abort(true);

    try
    {
        $paymentSettings = get_option('woocommerce_razorpay_settings');
        if (!isValidRazorpayMerchant($paymentSettings))
        {
            rzpLogInfo("one_cc_address_sync_cron_exec: invalid razorpay merchant");
            deleteOneCCAddressSyncCron(Constants::CANCELLED, Constants::INVALID_RAZORPAY_MERCHANT, true);
            return;
        }

        $api             = new Api($paymentSettings[Constants::KEY_ID], $paymentSettings[Constants::KEY_SECRET]);
        $oneCCAddressSync = new OneCCAddressSync($api);
        $oneCCAddressSync->sync();
    }
    catch (Exception $e)
    {
        rzpLogError("one_cc_address_sync_cron_exec: " . $e->getMessage());
    }
    finally
    {
        delete_transient('rzp_addr_sync_running');
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

// Returns true for any Razorpay merchant with valid credentials — no 1CC required.
function isValidRazorpayMerchant($paymentSettings)
{
    if ($paymentSettings === false)
    {
        return false;
    }
    $keyId     = $paymentSettings[Constants::KEY_ID]     ?? '';
    $keySecret = $paymentSettings[Constants::KEY_SECRET] ?? '';
    return strlen($keyId) > 0 && strlen($keySecret) > 0;
}

// Kept for backward compatibility — called by woo-razorpay.php in 1CC settings paths.
function isValidOneCCMerchant($paymentSettings)
{
    if ($paymentSettings === false)
    {
        return false;
    }
    $keyId    = $paymentSettings[Constants::KEY_ID] ?? '';
    $keySecret = $paymentSettings[Constants::KEY_SECRET] ?? '';
    $enable1cc = $paymentSettings[Constants::ENABLE_1CC] ?? '';
    return $enable1cc === 'yes' && strlen($keyId) > 0 && strlen($keySecret) > 0;
}

function deleteOneCCAddressSyncCron($status = '', $message = '', $isWooCConfig = false)
{
    rzpLogInfo("deleteOneCCAddressSyncCron: status=" . $status . ", message=" . $message);
    $data = get_option(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK);
    if ($status === Constants::COMPLETED || $status === Constants::DEACTIVATED || $isWooCConfig ||
        isCronFailingForMoreThan7Days($data, $status, $message))
    {
        deleteCron(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK);
        updateAddressSyncCronData($status, $message, $data, Constants::DELETED);
        return;
    }
    updateAddressSyncCronData($status, $message, $data);
}

function isCronFailingForMoreThan7Days($data, $status, $message)
{
    if ($data === false || empty($data))
    {
        return false;
    }
    if (!isset($data[Constants::STATUS]) || !isset($data[Constants::MESSAGE]) || !isset($data[Constants::UPDATED_AT]))
    {
        return false;
    }
    if ($data[Constants::STATUS] !== $status || $data[Constants::MESSAGE] !== $message)
    {
        return false;
    }
    return ($data[Constants::UPDATED_AT] + Constants::SEVEN_DAYS_IN_SECONDS) <= time();
}

function updateAddressSyncCronData(string $status, string $message = '', $data = [], string $cronStatus = 'created')
{
    if ($data === false || empty($data))
    {
        $data = get_option(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK);
    }
    if ($data === false)
    {
        $data = [
            Constants::STATUS     => Constants::PROCESSING,
            Constants::UPDATED_AT => time(),
            Constants::CREATED_AT => time(),
            Constants::MESSAGE    => '',
            Constants::CRON_STATUS => 'created',
        ];
    }
    if ($status === $data[Constants::STATUS] && $message === $data[Constants::MESSAGE] && $cronStatus === $data[Constants::CRON_STATUS])
    {
        return;
    }
    if (strlen($status) > 0)    { $data[Constants::STATUS]      = $status; }
    if (strlen($cronStatus) > 0) { $data[Constants::CRON_STATUS] = $cronStatus; }
    $data[Constants::MESSAGE]    = $message;
    $data[Constants::UPDATED_AT] = time();
    update_option(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK, $data);
}

function isAddressSynced($oneCCAddressSyncCronData)
{
    if ($oneCCAddressSyncCronData === false)
    {
        return false;
    }
    return $oneCCAddressSyncCronData[Constants::STATUS] === Constants::COMPLETED;
}
