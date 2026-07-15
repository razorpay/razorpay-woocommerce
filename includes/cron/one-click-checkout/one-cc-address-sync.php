<?php

use Razorpay\Api\Api;

function getOneCCAddressSyncApiBaseUrl()
{
    if ((defined('RZP_WC_DEVSTACK_API_BASE_URL') === true) &&
        empty(RZP_WC_DEVSTACK_API_BASE_URL) === false)
    {
        return rtrim(RZP_WC_DEVSTACK_API_BASE_URL, '/');
    }

    return Constants::DEV_ADDRESS_SYNC_BASE_URL;
}

class OneCCAddressSyncRequest extends Razorpay\Api\Request
{
    public function requestJson($method, $url, $data = array(), $apiVersion = "v1")
    {
        $url = Api::getFullUrl($url, $apiVersion);

        $hooks = new Requests_Hooks();

        $hooks->register('curl.before_send', array($this, 'setCurlSslOpts'));

        $options = array(
            'auth' => array(Api::getKey(), Api::getSecret()),
            'hook' => $hooks,
            'timeout' => 60
        );

        $headers = array_merge($this->getRequestHeaders(), array(
            'Content-Type' => 'application/json',
        ));

        $response = Requests::request($url, $headers, json_encode($data), $method, $options);
        $this->checkErrors($response);

        return json_decode($response->body, true);
    }
}

class OneCCAddressSync
{
    const GET_CONFIGS_API        = 'woocommerce/config'; // checkpoint + job state
    const POST_ADDRESSES_API     = 'woocommerce/ingest'; // lifecycle, retry-progress, and address batches
    const GET                    = 'GET';
    const POST                   = 'POST';

    private $apiRequestRetryCount = 3;
    private $apiRequestRetryDelay = 2; // base delay in seconds — doubles each retry (2, 4, 8)
    private $batchSize            = 50;
    private $cooldownMs           = Constants::BATCH_COOLDOWN_MS;

    protected $api;
    private $jobConfig;

    // Run upper bound — frozen at sync start.
    // upperOrderId = ID of the last qualifying order that existed when the run started,
    // so new orders arriving mid-run are excluded and processed tomorrow.
    private $upperCreatedAt = 0;
    private $upperOrderId   = 0;

    // Confirmed cursor — last successfully accepted Woo order ID.
    // Advances ONLY after backend returns 202. On failure stays at previous value.
    private $cursorOrderId = 0;

    // Pending cursor — set by getNextBatch(), committed to confirmed by sync() on 202.
    // This is the last Woo order ID in the batch currently being sent.
    private $pendingCursorOrderId = 0;
    private $runStartedAt = 0;
    private $batchesSent = 0;
    private $addressesSent = 0;
    private $batchFailures = 0;
    private $warningLogs = [];
    private $errorLogs = [];

    public function __construct($api)
    {
        $this->api = $api;
    }

    private function recordLog($bucket, $stage, $message, $context = [])
    {
        $entry = array_merge([
            Constants::MESSAGE => $message,
            'stage'            => $stage,
            Constants::UPDATED_AT => time(),
        ], $context);

        $this->{$bucket}[] = $entry;
        if (count($this->{$bucket}) > 20)
        {
            $this->{$bucket} = array_slice($this->{$bucket}, -20);
        }
    }

    private function recordWarning($stage, $message, $context = [])
    {
        $this->recordLog('warningLogs', $stage, $message, $context);
    }

    private function recordError($stage, $message, $context = [])
    {
        $this->recordLog('errorLogs', $stage, $message, $context);
    }

    private function clearSyncLogs()
    {
        $this->warningLogs = [];
        $this->errorLogs = [];
    }

    // makeAPICall — retries with exponential backoff (2s, 4s, 8s by default).
    // Raw address batches pass maxAttempts=1 because postRawAddressesInPhases()
    // owns that retry budget explicitly.
    private function makeAPICall($url, $method, $body, $maxAttempts = null)
    {
        $maxAttempts = $maxAttempts ?? $this->apiRequestRetryCount;
        $retryCount = 0;
        $statusCode = 0;
        while ($retryCount < $maxAttempts)
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
                $this->recordWarning('api_call', $e->getMessage(), [
                    'url'         => $url,
                    'method'      => $method,
                    'retry_count' => $retryCount,
                    'status_code' => $statusCode,
                ]);
            }
            catch (Exception $e)
            {
                rzpLogError("makeAPICall: unexpected error: " . $e->getMessage() . ", retryCount: " . $retryCount);
                $this->recordWarning('api_call', $e->getMessage(), [
                    'url'         => $url,
                    'method'      => $method,
                    'retry_count' => $retryCount,
                    'trace'       => substr($e->getTraceAsString(), 0, 4000),
                ]);
            }
            // Exponential backoff: 2s → 4s → 8s (skip sleep after the last attempt)
            if ($retryCount < $maxAttempts)
            {
                sleep($this->apiRequestRetryDelay * pow(2, $retryCount - 1));
            }
        }
        return [Constants::IS_SUCCESS => false, Constants::STATUS_CODE => $statusCode];
    }

    private function makeJSONAPICall($url, $method, $body, $maxAttempts = null)
    {
        $maxAttempts = $maxAttempts ?? $this->apiRequestRetryCount;
        $retryCount = 0;
        $statusCode = 0;
        while ($retryCount < $maxAttempts)
        {
            $retryCount++;
            try
            {
                $response = (new OneCCAddressSyncRequest())->requestJson($method, $url, $body);
                rzpLogInfo("makeJSONAPICall: url: " . $url . " is success");
                return [Constants::BODY => $response, Constants::IS_SUCCESS => true];
            }
            catch (\Razorpay\Api\Errors\Error $e)
            {
                $statusCode = $e->getHttpStatusCode();
                rzpLogError("makeJSONAPICall: message:" . $e->getMessage() . ", url: " . $url . ", method: " . $method . ", retryCount: " . $retryCount . ", statusCode: " . $statusCode);
                $this->recordWarning('api_call', $e->getMessage(), [
                    'url'         => $url,
                    'method'      => $method,
                    'retry_count' => $retryCount,
                    'status_code' => $statusCode,
                ]);
            }
            catch (Exception $e)
            {
                rzpLogError("makeJSONAPICall: unexpected error: " . $e->getMessage() . ", retryCount: " . $retryCount);
                $this->recordWarning('api_call', $e->getMessage(), [
                    'url'         => $url,
                    'method'      => $method,
                    'retry_count' => $retryCount,
                    'trace'       => substr($e->getTraceAsString(), 0, 4000),
                ]);
            }
            if ($retryCount < $maxAttempts)
            {
                sleep($this->apiRequestRetryDelay * pow(2, $retryCount - 1));
            }
        }
        return [Constants::IS_SUCCESS => false, Constants::STATUS_CODE => $statusCode];
    }

    private function getAddressSyncConfigs()
    {
        return $this->makeAPICall(self::GET_CONFIGS_API, self::GET, []);
    }

    // postAddresses sends lifecycle and retry-progress events through the ingest endpoint.
    // Signal-only events carry top-level checkpoint so backend can update job_execution.
    private function postAddresses($body)
    {
        return $this->makeJSONAPICall(self::POST_ADDRESSES_API, self::POST, $body);
    }

    // postRawAddresses sends a batch to 1cc-address-service.
    // checkpoint = pendingCursorOrderId — the Woo order ID of the LAST order in this batch.
    // If backend returns 202: sync() commits pending → confirmed cursor.
    // If rejected: confirmed cursor stays unchanged; same batch is retried.
    // Backend should reject or ignore lower checkpoints if concurrent writers are introduced.
    private function postRawAddresses($addresses)
    {
        return $this->makeJSONAPICall(self::POST_ADDRESSES_API, self::POST, [
            Constants::ADDRESSES  => $addresses,
            Constants::CHECKPOINT => $this->pendingCursorOrderId,
            Constants::STATUS     => empty($this->errorLogs) ? Constants::PROCESSING : Constants::FAILED,
            Constants::META_DATA  => $this->buildEventMetaData(Constants::BATCH_INGESTED),
        ], 1);
    }

    // Three-phase retry for a single batch. postRawAddresses() is one HTTP attempt,
    // so the total HTTP-level attempts are: 3 + 2 + 1 = 6.
    // Phase warning events are processing/liveness signals, not terminal failures.
    // Returns IS_SUCCESS=true on any successful attempt, IS_SUCCESS=false if all 6 fail.
    private function postRawAddressesInPhases($addresses, $endTime, $consecutiveFailureCount)
    {
        $projectedConsecutiveFailures = $consecutiveFailureCount + 1;

        // Phase 1 — 3 attempts
        for ($i = 0; $i < Constants::BATCH_PHASE1_MAX; $i++)
        {
            $response = $this->postRawAddresses($addresses);
            if ($response[Constants::IS_SUCCESS]) { return $response; }
            rzpLogError("batch phase1 attempt " . ($i + 1) . " failed at order_id=" . $this->pendingCursorOrderId);
        }
        $this->postBatchProgressEvent(Constants::BATCH_RETRY_PHASE1_FAILED, $projectedConsecutiveFailures);
        $this->clearSyncLogs();

        $waited = $this->waitWithinBudget(Constants::BATCH_PHASE1_WAIT_SECONDS, $endTime);
        if ($waited === 0)
        {
            rzpLogInfo("batch: no time budget for phase2, giving up at order_id=" . $this->pendingCursorOrderId);
            return [Constants::IS_SUCCESS => false];
        }

        // Phase 2 — 2 attempts
        for ($i = 0; $i < Constants::BATCH_PHASE2_MAX; $i++)
        {
            $response = $this->postRawAddresses($addresses);
            if ($response[Constants::IS_SUCCESS]) { return $response; }
            rzpLogError("batch phase2 attempt " . ($i + 1) . " failed at order_id=" . $this->pendingCursorOrderId);
        }
        $this->postBatchProgressEvent(Constants::BATCH_RETRY_PHASE2_FAILED, $projectedConsecutiveFailures);
        $this->clearSyncLogs();

        $waited = $this->waitWithinBudget(Constants::BATCH_PHASE2_WAIT_SECONDS, $endTime);
        if ($waited === 0)
        {
            rzpLogInfo("batch: no time budget for phase3, giving up at order_id=" . $this->pendingCursorOrderId);
            return [Constants::IS_SUCCESS => false];
        }

        // Phase 3 — final attempts
        for ($i = 0; $i < Constants::BATCH_PHASE3_MAX; $i++)
        {
            $response = $this->postRawAddresses($addresses);
            if ($response[Constants::IS_SUCCESS]) { return $response; }
            rzpLogError("batch phase3 attempt " . ($i + 1) . " failed at order_id=" . $this->pendingCursorOrderId);
        }
        return [Constants::IS_SUCCESS => false];
    }

    // Sleeps for up to $waitSeconds, capped by the run's end time.
    // Retry progress is reported through explicit phase events, not periodic heartbeats.
    private function waitWithinBudget($waitSeconds, $endTime)
    {
        $sleepSeconds = min($waitSeconds, max(0, $endTime - time()));
        if ($sleepSeconds <= 0)
        {
            return 0;
        }

        sleep($sleepSeconds);
        return $sleepSeconds;
    }

    private function loadCursorFromJobConfig()
    {
        $this->cursorOrderId        = (int)($this->jobConfig[Constants::CHECKPOINT] ?? 0);
        $this->pendingCursorOrderId = $this->cursorOrderId;
    }

    private function loadConfigValues($configs)
    {
        $this->jobConfig = [
            Constants::CHECKPOINT => (int)($configs[Constants::CHECKPOINT] ?? 0),
        ];
        $this->loadCursorFromJobConfig();
    }

    private function buildEventMetaData($message, $extraMetaData = [])
    {
        $metaData = [
            Constants::MESSAGE => $message,
        ];

        if (!empty($this->warningLogs))
        {
            $metaData[Constants::WARNING] = [
                'logs' => $this->warningLogs,
            ];
        }

        if (!empty($this->errorLogs))
        {
            $metaData[Constants::ERROR] = [
                'logs' => $this->errorLogs,
            ];

            if (isset($extraMetaData[Constants::CONSECUTIVE_BATCH_FAILURES]))
            {
                $metaData[Constants::ERROR][Constants::CONSECUTIVE_BATCH_FAILURES] =
                    (int) $extraMetaData[Constants::CONSECUTIVE_BATCH_FAILURES];
            }
        }

        foreach ($extraMetaData as $key => $value)
        {
            $metaData[$key] = $value;
        }

        return $metaData;
    }

    private function postSyncEvent($status, $message, $checkpointOverride = null, $extraMetaData = [])
    {
        $checkpoint = $checkpointOverride ?? $this->cursorOrderId;
        $body = [
            Constants::CHECKPOINT => $checkpoint,
            Constants::STATUS     => empty($this->errorLogs) ? $status : Constants::FAILED,
            Constants::META_DATA  => $this->buildEventMetaData($message, $extraMetaData),
        ];

        return $this->postAddresses($body);
    }

    private function postBatchProgressEvent($message, $consecutiveFailureCount)
    {
        $response = $this->postSyncEvent(
            Constants::PROCESSING,
            $message,
            null,
            [Constants::CONSECUTIVE_BATCH_FAILURES => $consecutiveFailureCount]
        );
        if (!$response[Constants::IS_SUCCESS])
        {
            rzpLogError("postBatchProgressEvent: failed to send " . $message . " at order_id=" . $this->pendingCursorOrderId);
        }

        return $response;
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
                rzpLogError("isMerchantEligible: auth error " . $statusCode . ", removing cron");
                deleteOneCCAddressSyncCron(Constants::FAILED, $message);
            }
            else
            {
                rzpLogError("isMerchantEligible: transient error " . $statusCode . ", will retry tomorrow");
                updateAddressSyncCronData(Constants::FAILED, $message);
            }
            return false;
        }

        $configs = $response[Constants::BODY];

        $this->loadConfigValues($configs);

        if (array_key_exists(Constants::WOOCOMMERCE_ADDRESS_SYNC_ENABLED, $configs) &&
            $configs[Constants::WOOCOMMERCE_ADDRESS_SYNC_ENABLED] === false)
        {
            rzpLogInfo("isMerchantEligible: woocommerce address sync disabled by remote config");
            if ($this->runStartedAt === 0)
            {
                $this->runStartedAt = time();
            }
            $pauseResponse = $this->postSyncEvent(
                Constants::PAUSED,
                Constants::ADDRESS_INGESTION_PAUSED
            );
            if (!$pauseResponse[Constants::IS_SUCCESS])
            {
                rzpLogError("isMerchantEligible: failed to send remote-disabled paused event");
            }
            updateAddressSyncCronData(Constants::PAUSED, Constants::WOOCOMMERCE_ADDRESS_SYNC_DISABLED);
            return false;
        }

        return true;
    }

    // Returns true when an order is NOT a Magic/1CC order and should be ingested.
    // Filtering is done in PHP rather than via meta_query to avoid WordPress's
    // LEFT JOIN / OR meta_query behaviour that causes LIMIT to undershoot unique
    // post counts (known WP core issue with OR + NOT EXISTS meta queries).
    protected function isNonMagicOrder($order): bool
    {
        return $order->get_meta('is_magic_checkout_order') !== 'yes';
    }

    // Filters an array of orders, returning only non-magic orders.
    protected function filterNonMagicOrders(array $orders): array
    {
        return array_values(array_filter($orders, [$this, 'isNonMagicOrder']));
    }

    private function getOrders()
    {
        // Order ID keyset pagination with both bounds applied.
        //
        // Effective WHERE:
        //   id > cursor_order_id
        //   id <= upper_order_id
        //   ORDER BY id ASC
        //
        // The CPT datastore gets the ID range through a temporary posts_where filter.
        // HPOS gets the same range through field_query.
        // Magic/1CC order filtering is intentionally done in PHP (see isNonMagicOrder)
        // rather than via meta_query to avoid WordPress OR+NOT EXISTS LIMIT undershoot.
        $page        = 1;
        $useCptWhere = !$this->isHposOrderStorageEnabled();

        if ($useCptWhere)
        {
            add_filter('posts_where', [$this, 'filterOrderIdRangeWhere'], 10, 2);
        }

        try
        {
            while (true)
            {
                $queryArgs = [
                    Constants::DATE_CREATED => '1...' . $this->upperCreatedAt,
                    'status'                => $this->getAddressSyncOrderStatuses(),
                    'orderby'               => 'ID',
                    Constants::ORDER        => Constants::ASC,
                    Constants::LIMIT        => $this->batchSize,
                    Constants::PAGED        => $page,
                ];

                if (!$useCptWhere)
                {
                    $queryArgs['field_query'] = [
                        'relation' => 'AND',
                        [
                            'field'   => 'id',
                            'value'   => $this->cursorOrderId,
                            'compare' => '>',
                        ],
                        [
                            'field'   => 'id',
                            'value'   => $this->upperOrderId,
                            'compare' => '<=',
                        ],
                    ];
                }

                $orders = wc_get_orders($queryArgs);

                if (empty($orders))
                {
                    return [];
                }

                $cursorOrderId = $this->cursorOrderId;
                $upperOrderId  = $this->upperOrderId;
                $filteredOrders = array_values(array_filter($orders, function($order) use ($cursorOrderId, $upperOrderId) {
                    $id = $order->get_id();
                    if ($id <= $cursorOrderId || $id > $upperOrderId)
                    {
                        return false;
                    }
                    return $this->isNonMagicOrder($order);
                }));

                if (!empty($filteredOrders))
                {
                    return $filteredOrders;
                }

                $page++;
            }
        }
        finally
        {
            if ($useCptWhere)
            {
                remove_filter('posts_where', [$this, 'filterOrderIdRangeWhere'], 10);
            }
        }
    }

    private function isHposOrderStorageEnabled()
    {
        return class_exists('Automattic\WooCommerce\Utilities\OrderUtil') &&
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    private function getAddressSyncOrderStatuses()
    {
        $statuses = array_keys(wc_get_order_statuses());

        return array_map(function($status) {
            return preg_replace('/^wc-/', '', $status);
        }, $statuses);
    }

    public function filterOrderIdRangeWhere($where, $query)
    {
        global $wpdb;

        return $where . $wpdb->prepare(
            " AND {$wpdb->posts}.ID > %d AND {$wpdb->posts}.ID <= %d",
            $this->cursorOrderId,
            $this->upperOrderId
        );
    }

    // Build address array from orders — shipping preferred, billing fallback for every field.
    private function getAddressFromOrders($orders)
    {
        $addresses = [];
        foreach ($orders as $order)
        {
            try
            {
                $shippingName = trim(trim($order->get_shipping_first_name()) . ' ' . trim($order->get_shipping_last_name()));
                $billingName  = trim(trim($order->get_billing_first_name())  . ' ' . trim($order->get_billing_last_name()));
                $address = [
                    'contact' => strlen(trim($order->get_shipping_phone())) > 0
                                    ? trim($order->get_shipping_phone())
                                    : trim($order->get_billing_phone()),
                    'name'    => strlen($shippingName) >= 3 ? $shippingName : $billingName,
                    'line1'   => trim($order->get_shipping_address_1()) ?: trim($order->get_billing_address_1()),
                    'line2'   => trim($order->get_shipping_address_2()) ?: trim($order->get_billing_address_2()),
                    'city'    => trim($order->get_shipping_city())      ?: trim($order->get_billing_city()),
                    'state'   => trim($order->get_shipping_state())     ?: trim($order->get_billing_state()),
                    'country' => trim($order->get_shipping_country())   ?: trim($order->get_billing_country()),
                    'zipcode' => trim($order->get_shipping_postcode())  ?: trim($order->get_billing_postcode()),
                ];
                $addresses[] = $address;
            }
            catch (Exception $e)
            {
                $orderId = method_exists($order, 'get_id') ? $order->get_id() : null;
                rzpLogError("getAddressFromOrders: failed for order_id=" . $orderId . ", error=" . $e->getMessage());
                $this->recordWarning('address_extraction', $e->getMessage(), [
                    'order_id' => $orderId,
                    'trace'    => substr($e->getTraceAsString(), 0, 4000),
                ]);
            }
        }
        return $addresses;
    }

    // Fetch the next batch of order address payloads using order ID keyset pagination.
    // Sets pendingCursorOrderId to the last order fetched but does NOT advance
    // cursorOrderId — that is committed in sync() only after backend returns 202.
    private function getNextBatch($endTime)
    {
        $addresses = [];
        while (empty($addresses) && time() < $endTime)
        {
            $orders = $this->getOrders();
            rzpLogInfo("getNextBatch: confirmed_order_id=" . $this->cursorOrderId . ", orders=" . count($orders));

            if (empty($orders))
            {
                return []; // no more orders in the window — caught up
            }

            // Set pending cursor to the last order in this result set.
            // cursorOrderId (confirmed) is intentionally NOT changed here.
            $lastOrder = end($orders);
            if ($lastOrder)
            {
                $this->pendingCursorOrderId = $lastOrder->get_id();
            }

            $addresses = $this->getAddressFromOrders($orders);
            if (empty($addresses) && !empty($this->warningLogs))
            {
                // Every order in this batch failed extraction (corrupt metadata, etc.).
                // Advance the confirmed cursor past the batch so we don't loop on the
                // same broken orders forever. Log a warning and continue to the next
                // batch — this is recoverable, not a reason to mark the whole sync FAILED.
                rzpLogError("getNextBatch: all addresses unextractable in batch ending at order_id="
                    . $this->pendingCursorOrderId . ", skipping batch");
                $this->cursorOrderId = $this->pendingCursorOrderId;
                $this->clearSyncLogs();
                continue;
            }
        }
        return $addresses;
    }

    // $maxSeconds: how long this cron run is allowed to process before pausing.
    public function sync($maxSeconds)
    {
        try
        {
            if (!$this->isMerchantEligible())
            {
                return;
            }

            $this->runStartedAt   = time();
            $endTime          = time() + $maxSeconds;
            $consecutiveFailureCount      = 0;

            rzpLogInfo("sync: confirmed_order_id=" . $this->cursorOrderId . ", maxSeconds=" . $maxSeconds);
            updateAddressSyncCronData(Constants::PROCESSING);

            $startedResponse = $this->postSyncEvent(Constants::PROCESSING, Constants::CONFIG_RECEIVED);
            if ($startedResponse[Constants::IS_SUCCESS])
            {
                rzpLogInfo("sync: config_received event sent at order_id=" . $this->cursorOrderId);
            }
            else
            {
                rzpLogError("sync: failed to send config_received event at order_id=" . $this->cursorOrderId);
                updateAddressSyncCronData(Constants::PAUSED, Constants::POST_ADDRESSES_ERROR);
                return;
            }

            $this->upperCreatedAt = $this->runStartedAt; // frozen upper bound for this run

            // upperOrderId is a time-fence only — the highest order ID that existed
            // when this run started so orders created mid-run are excluded.
            // We must NOT filter Magic Checkout orders here: if the most recent orders
            // are all 1CC, filtering would set upperOrderId=0, causing WHERE ID <= 0
            // in every batch query and making the sync falsely report COMPLETED.
            // Per-batch Magic order filtering happens in getOrders() where it belongs.
            $candidateUpper = wc_get_orders([
                Constants::DATE_CREATED => '1...' . $this->upperCreatedAt,
                'status'                => $this->getAddressSyncOrderStatuses(),
                'orderby'               => 'ID',
                Constants::ORDER        => 'DESC',
                Constants::LIMIT        => 1,
            ]);
            $this->upperOrderId = !empty($candidateUpper) ? $candidateUpper[0]->get_id() : 0;
            rzpLogInfo("sync: upper_order_id=" . $this->upperOrderId . ", confirmed_order_id=" . $this->cursorOrderId);

            while (time() < $endTime)
            {
                $addresses = $this->getNextBatch($endTime);

                if (empty($addresses))
                {
                    if (!empty($this->errorLogs))
                    {
                        $failedResponse = $this->postSyncEvent(
                            Constants::FAILED,
                            Constants::ADDRESS_INGESTION_FAILED
                        );
                        if (!$failedResponse[Constants::IS_SUCCESS])
                        {
                            rzpLogError("sync: failed to send extraction failure event at order_id=" . $this->pendingCursorOrderId);
                        }
                        updateAddressSyncCronData(Constants::FAILED, Constants::ADDRESS_INGESTION_FAILED);
                        return;
                    }

                    if (time() >= $endTime)
                    {
                        // Time ran out inside getNextBatch while scanning empty pages.
                        // Checkpoint advancement is handled by postRawAddresses() on batch success;
                        // this event carries only the lifecycle state change.
                        rzpLogInfo("sync: time limit reached while scanning pages at order_id=" . $this->pendingCursorOrderId);
                        $pauseResponse = $this->postSyncEvent(
                            Constants::PAUSED,
                            Constants::ADDRESS_INGESTION_PAUSED
                        );
                        if (!$pauseResponse[Constants::IS_SUCCESS])
                        {
                            rzpLogError("sync: failed to send paused event to backend at order_id=" . $this->pendingCursorOrderId);
                        }
                        updateAddressSyncCronData(Constants::PAUSED, Constants::MAX_RUNNING_TIME_REACHED);
                    }
                    else
                    {
                        // No more orders in the window — sync is complete.
                        // Checkpoint is the last confirmed sent order ID, NOT upperOrderId.
                        // Using upperOrderId would permanently skip any orders between the
                        // last sent order and upperOrderId that were missed for any reason.
                        $finalCheckpoint = $this->cursorOrderId;
                        rzpLogInfo("sync: completed. cursor_order_id=" . $this->cursorOrderId . ", upper_order_id=" . $this->upperOrderId);
                        $completionResponse = $this->postSyncEvent(
                            Constants::COMPLETED,
                            Constants::ADDRESS_INGESTION_COMPLETE,
                            $finalCheckpoint
                        );
                        if (!$completionResponse[Constants::IS_SUCCESS])
                        {
                            rzpLogError("sync: failed to notify backend of completion at order_id=" . $this->pendingCursorOrderId);
                            updateAddressSyncCronData(Constants::PAUSED, Constants::POST_ADDRESSES_ERROR);
                            return;
                        }
                        updateAddressSyncCronData(Constants::COMPLETED, Constants::ADDRESS_INGESTION_COMPLETE);
                    }
                    return;
                }

                rzpLogInfo("sync: posting " . count($addresses) . " addresses at order_id=" . $this->pendingCursorOrderId);

                // Six total attempts across three phases: 3 -> wait 10min -> 2 -> wait 5min -> 1
                $response = $this->postRawAddressesInPhases($addresses, $endTime, $consecutiveFailureCount);

                if (!$response[Constants::IS_SUCCESS])
                {
                    // All attempts failed. Do not advance the confirmed cursor; the same
                    // batch remains the resume point until it succeeds or the run stops.
                    $consecutiveFailureCount++;
                    $this->batchFailures++;
                    $this->recordError('batch_ingestion', Constants::POST_ADDRESSES_ERROR, [
                        Constants::CONSECUTIVE_BATCH_FAILURES => $consecutiveFailureCount,
                        Constants::CHECKPOINT => $this->pendingCursorOrderId,
                    ]);
                    rzpLogError("sync: batch failed (consecutive_failures=" . $consecutiveFailureCount . ") at order_id=" . $this->pendingCursorOrderId);

                    if ($consecutiveFailureCount >= Constants::MAX_CONSECUTIVE_FAILURES)
                    {
                        $failMsg = Constants::CONSECUTIVE_BATCH_FAILURES . '_' . $consecutiveFailureCount;
                        rzpLogError("sync: stopping — " . $consecutiveFailureCount . " consecutive batch failures");
                        $pauseResponse = $this->postSyncEvent(
                            Constants::PAUSED,
                            Constants::ADDRESS_INGESTION_PAUSED,
                            null,
                            [Constants::CONSECUTIVE_BATCH_FAILURES => $consecutiveFailureCount]
                        );
                        if (!$pauseResponse[Constants::IS_SUCCESS])
                        {
                            rzpLogError("sync: also failed to send paused event to backend");
                        }
                        updateAddressSyncCronData(Constants::PAUSED, $failMsg);
                        return;
                    }

                    $this->postBatchProgressEvent(Constants::BATCH_FAILED, $consecutiveFailureCount);
                    $this->clearSyncLogs();

                    // Below the stop threshold — retry from the same confirmed cursor.
                    // pendingCursorOrderId is not persisted unless a raw batch succeeds.
                    continue;
                }

                // Batch accepted — commit pending order ID to confirmed.
                // This is the ONLY place cursorOrderId advances after a valid address batch.
                $this->cursorOrderId   = $this->pendingCursorOrderId;
                $consecutiveFailureCount = 0;
                $this->batchesSent++;
                $this->addressesSent += count($addresses);
                $this->clearSyncLogs();

                // Cooldown between consecutive successful batches — prevents hammering the API
                // and gives the 1cc-address-service headroom to process the goroutine work.
                if ($this->cooldownMs > 0)
                {
                    usleep($this->cooldownMs * 1000);
                }
            }

            // Time budget exhausted. Checkpoint is already persisted by the last successful
            // raw batch — this event carries only the lifecycle state change.
            rzpLogInfo("sync: time limit reached at order_id=" . $this->pendingCursorOrderId);
            $pauseResponse = $this->postSyncEvent(
                Constants::PAUSED,
                Constants::ADDRESS_INGESTION_PAUSED
            );
            if (!$pauseResponse[Constants::IS_SUCCESS])
            {
                rzpLogError("sync: failed to send paused event to backend at order_id=" . $this->pendingCursorOrderId);
            }
            updateAddressSyncCronData(Constants::PAUSED, Constants::MAX_RUNNING_TIME_REACHED);
        }
        catch (Exception $e)
        {
            rzpLogError("sync: unhandled exception: " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            $this->batchFailures++;
            $this->recordError('unhandled_exception', $e->getMessage(), [
                'trace' => substr($e->getTraceAsString(), 0, 4000),
            ]);
            $failedResponse = $this->postSyncEvent(
                Constants::FAILED,
                Constants::ADDRESS_INGESTION_FAILED
            );
            if (!$failedResponse[Constants::IS_SUCCESS])
            {
                rzpLogError("sync: also failed to send failed event to backend at order_id=" . $this->cursorOrderId);
            }
            updateAddressSyncCronData(Constants::FAILED, Constants::UNHANDLED_EXCEPTION_OCCURRED_IN_CRON . $e->getMessage());
        }
    }
}

// ─── Scheduling ──────────────────────────────────────────────────────────────

function createOneCCAddressSyncCron()
{
    rzpLogInfo('createOneCCAddressSyncCron: attempting to schedule daily cron');

    $paymentSettings = get_option('woocommerce_razorpay_settings');
    if (!isValidRazorpayMerchant($paymentSettings))
    {
        rzpLogInfo('createOneCCAddressSyncCron: invalid razorpay merchant, aborting');
        return;
    }

    if (isValidOneCCMerchant($paymentSettings))
    {
        rzpLogInfo('createOneCCAddressSyncCron: one cc merchant, deleting cron');
        deleteOneCCAddressSyncCron(Constants::CANCELLED, Constants::INVALID_1CC_MERCHANT, true);
        return;
    }

    // Always compute start time in IST regardless of the merchant server's PHP timezone.
    // wp_schedule_event() takes a Unix timestamp so timezone-awareness is critical here —
    // a server configured to UTC would produce a wrong start time with plain strtotime().
    $tz      = new DateTimeZone(Constants::ADDRESS_SYNC_TIMEZONE);
    $startDt = new DateTime('today ' . Constants::ADDRESS_SYNC_CRON_START_TIME . ':00', $tz);
    if ($startDt->getTimestamp() <= time())
    {
        $startDt = new DateTime('tomorrow ' . Constants::ADDRESS_SYNC_CRON_START_TIME . ':00', $tz);
    }
    $startTime = $startDt->getTimestamp();

    // Reschedule check: compare the scheduled time in IST, not the server's local time.
    // date('H:i', $ts) would give the wrong hour on UTC servers.
    $nextScheduled = wp_next_scheduled(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK);
    if ($nextScheduled !== false)
    {
        $scheduledDt = new DateTime('@' . $nextScheduled);
        $scheduledDt->setTimezone($tz);
        if ($scheduledDt->format('H:i') !== Constants::ADDRESS_SYNC_CRON_START_TIME)
        {
            wp_unschedule_event($nextScheduled, Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK);
            rzpLogInfo('createOneCCAddressSyncCron: rescheduled existing cron (was ' . $scheduledDt->format('H:i T') . ')');
        }
    }

    try
    {
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
            Constants::STATUS      => Constants::PROCESSING,
            Constants::CREATED_AT  => time(),
            Constants::UPDATED_AT  => time(),
            Constants::MESSAGE     => '',
            Constants::CRON_STATUS => 'created',
        ]);
    }
}

// Creates the daily address sync cron. Called from settings saves and plugin instrumentation.
// Also cleans up the old evening cron hook that existed in previous plugin versions so it
// does not remain as an orphaned scheduled event after the upgrade.
function createAllAddressSyncCrons()
{
    $oldEveningHook = 'one_cc_address_sync_evening_cron';
    if (wp_next_scheduled($oldEveningHook) !== false)
    {
        deleteCron($oldEveningHook);
        rzpLogInfo('createAllAddressSyncCrons: removed legacy evening cron');
    }

    createOneCCAddressSyncCron();
}

// ─── Cron execution ──────────────────────────────────────────────────────────

// Shared execution logic for the daily cron.
// Budget is computed as (5 AM IST - now) so the cron never processes past 5 AM IST
// regardless of when WP-Cron actually fired it.
function runOneCCAddressSync($cronType)
{
    // Hard stop: compute budget in IST — server timezone is irrelevant.
    $tz         = new DateTimeZone(Constants::ADDRESS_SYNC_TIMEZONE);
    $hardStopDt = new DateTime('today ' . Constants::ADDRESS_SYNC_HARD_STOP_TIME . ':00', $tz);
    $budget     = $hardStopDt->getTimestamp() - time();

    if ($budget <= 0)
    {
        rzpLogInfo("runOneCCAddressSync: [{$cronType}] past " . Constants::ADDRESS_SYNC_HARD_STOP_TIME . " IST hard stop, skipping this run");
        return;
    }

    rzpLogInfo("runOneCCAddressSync: [{$cronType}] budget=" . round($budget / 60, 1) . "min (until " . Constants::ADDRESS_SYNC_HARD_STOP_TIME . " IST)");

    // Concurrency lock TTL = budget + 5 min buffer so the lock expires naturally
    // even if the process is killed without hitting the finally block.
    if (get_transient('rzp_addr_sync_running'))
    {
        rzpLogInfo("runOneCCAddressSync: [{$cronType}] already running, skipping");
        return;
    }
    set_transient('rzp_addr_sync_running', 1, $budget + 300);

    set_time_limit(0);
    ignore_user_abort(true);

    try
    {
        $paymentSettings = get_option('woocommerce_razorpay_settings');
        if (!isValidRazorpayMerchant($paymentSettings))
        {
            rzpLogInfo("runOneCCAddressSync: [{$cronType}] invalid razorpay merchant");
            deleteOneCCAddressSyncCron(Constants::CANCELLED, Constants::INVALID_RAZORPAY_MERCHANT, true);
            return;
        }

        if (isValidOneCCMerchant($paymentSettings))
        {
            rzpLogInfo("runOneCCAddressSync: [{$cronType}] one cc merchant");
            deleteOneCCAddressSyncCron(Constants::CANCELLED, Constants::INVALID_1CC_MERCHANT, true);
            return;
        }

        $api = new Api($paymentSettings[Constants::KEY_ID], $paymentSettings[Constants::KEY_SECRET]);
        $baseUrl = getOneCCAddressSyncApiBaseUrl();
        if (empty($baseUrl) === false)
        {
            $api->setBaseUrl($baseUrl);
        }

        $oneCCAddressSync = new OneCCAddressSync($api);
        $oneCCAddressSync->sync($budget);
    }
    catch (Exception $e)
    {
        rzpLogError("runOneCCAddressSync: [{$cronType}] " . $e->getMessage());
    }
    finally
    {
        delete_transient('rzp_addr_sync_running');
    }
}

function one_cc_address_sync_cron_exec()
{
    runOneCCAddressSync('daily');
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
    $keyId     = $paymentSettings[Constants::KEY_ID]     ?? '';
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
        // Clean up old retry hook from previous plugin versions — constant no longer exists.
        deleteCron('one_cc_address_sync_retry_cron');
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
            Constants::STATUS      => Constants::PROCESSING,
            Constants::UPDATED_AT  => time(),
            Constants::CREATED_AT  => time(),
            Constants::MESSAGE     => '',
            Constants::CRON_STATUS => 'created',
        ];
    }
    if ($status === $data[Constants::STATUS] && $message === $data[Constants::MESSAGE] && $cronStatus === $data[Constants::CRON_STATUS])
    {
        return;
    }
    if (strlen($status) > 0)     { $data[Constants::STATUS]      = $status; }
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
