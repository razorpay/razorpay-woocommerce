<?php

use Razorpay\Api\Api;

class OneCCAddressSync
{
    const GET_CONFIGS_API        = 'woocommerce/config'; // checkpoint + job state
    const POST_ADDRESSES_API     = 'woocommerce/event';  // heartbeat and lifecycle events
    const POST_RAW_ADDRESSES_API = 'woocommerce/ingest'; // address batches
    const GET                    = 'GET';
    const POST                   = 'POST';

    private $apiRequestRetryCount = 3;
    private $apiRequestRetryDelay = 2; // base delay in seconds — doubles each retry (2, 4, 8)
    private $batchSize            = 50;

    protected $api;
    private $jobConfig;
    private $lastActivityAt = 0; // shared by sync() and getNextBatch() so scans stay live

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
    private $batchesIngested = 0;
    private $addressesIngested = 0;
    private $heartbeatsSent = 0;
    private $batchFailures = 0;

    public function __construct($api)
    {
        $this->api = $api;
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
            }
            catch (Exception $e)
            {
                rzpLogError("makeAPICall: unexpected error: " . $e->getMessage() . ", retryCount: " . $retryCount);
            }
            // Exponential backoff: 2s → 4s → 8s (skip sleep after the last attempt)
            if ($retryCount < $maxAttempts)
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

    // postAddresses sends heartbeat and lifecycle events (completed/paused/failed/cancelled).
    // It intentionally carries no checkpoint — checkpoint advancement is the sole
    // responsibility of postRawAddresses() via the WooCommerce ingest endpoint.
    // Checkpoint progression happens through batch success only.
    private function postAddresses($body)
    {
        $body[Constants::SOURCE] = Constants::WOOCOMMERCE;
        return $this->makeAPICall(self::POST_ADDRESSES_API, self::POST, $body);
    }

    // postRawAddresses sends a batch to 1cc-address-service.
    // checkpoint = pendingCursorOrderId — the Woo order ID of the LAST order in this batch.
    // If backend returns 202: sync() commits pending → confirmed cursor.
    // If rejected: confirmed cursor stays unchanged; same batch is retried.
    // Backend should reject or ignore lower checkpoints if concurrent writers are introduced.
    private function postRawAddresses($addresses)
    {
        return $this->makeAPICall(self::POST_RAW_ADDRESSES_API, self::POST, [
            Constants::ADDRESSES  => $addresses,
            Constants::CHECKPOINT => $this->pendingCursorOrderId,
        ], 1);
    }

    // Three-phase retry for a single batch. postRawAddresses() is one HTTP attempt,
    // so the total HTTP-level attempts are: 3 + 2 + 1 = 6.
    // Total wait budget between phases: 10+5 = 15 min.
    //
    // Phase 1: 3 attempts  → wait 10 min (heartbeats throughout)
    // Phase 2: 2 attempts  → wait 5 min  (heartbeats throughout)
    // Phase 3: 1 attempt   → give up if still failing
    //
    // Returns IS_SUCCESS=true on any successful attempt, IS_SUCCESS=false if all 6 fail.
    private function postRawAddressesInPhases($addresses, $endTime)
    {
        // Phase 1 — 3 attempts
        for ($i = 0; $i < Constants::BATCH_PHASE1_MAX; $i++)
        {
            $response = $this->postRawAddresses($addresses);
            if ($response[Constants::IS_SUCCESS]) { return $response; }
            rzpLogError("batch phase1 attempt " . ($i + 1) . " failed at order_id=" . $this->pendingCursorOrderId);
        }

        $waited = $this->waitWithHeartbeats(Constants::BATCH_PHASE1_WAIT_SECONDS, $endTime);
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

        $waited = $this->waitWithHeartbeats(Constants::BATCH_PHASE2_WAIT_SECONDS, $endTime);
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

    // Sleeps for up to $waitSeconds (capped by $endTime), firing heartbeats every
    // HEARTBEAT_INTERVAL_SECONDS throughout so monitoring never sees a stale signal during
    // an in-run retry wait. Returns the actual seconds waited (0 if no budget was available).
    private function waitWithHeartbeats($waitSeconds, $endTime)
    {
        $waitEnd = min(time() + $waitSeconds, $endTime);
        $waited  = 0;
        while (time() < $waitEnd)
        {
            $chunk = min(Constants::HEARTBEAT_INTERVAL_SECONDS, (int)($waitEnd - time()));
            if ($chunk > 0)
            {
                sleep($chunk);
                $waited += $chunk;
            }
            $this->sendHeartbeatIfStale();
        }
        return $waited;
    }

    private function markActivity()
    {
        $this->lastActivityAt = time();
    }

    private function sendHeartbeatIfStale($force = false)
    {
        if (!$force &&
            $this->lastActivityAt > 0 &&
            time() - $this->lastActivityAt < Constants::HEARTBEAT_INTERVAL_SECONDS)
        {
            return;
        }

        $this->sendHeartbeat();
        $this->markActivity();
    }

    // Sends a lightweight heartbeat to the backend so monitoring can confirm the cron is alive.
    // A failed heartbeat is logged but never stops the sync — it is fire-and-observe, not blocking.
    // The server uses TouchRunStatus (direct SQL UPDATE) so updated_at is always stamped,
    // even when status+message are identical to the previous heartbeat.
    private function sendHeartbeat()
    {
        $this->heartbeatsSent++;
        $response = $this->postAddresses([
            Constants::META_DATA => [
                Constants::STATUS             => Constants::PROCESSING,
                Constants::MESSAGE            => Constants::HEARTBEAT,
                Constants::LAST_CHECKPOINT    => $this->cursorOrderId,
                Constants::PENDING_CHECKPOINT => $this->pendingCursorOrderId,
                Constants::UPPER_BOUND        => $this->upperOrderId,
                Constants::BATCHES_INGESTED   => $this->batchesIngested,
                Constants::ADDRESSES_INGESTED => $this->addressesIngested,
                Constants::HEARTBEATS_SENT    => $this->heartbeatsSent,
                Constants::BATCH_FAILURES     => $this->batchFailures,
                Constants::RUN_STARTED_AT     => $this->runStartedAt,
            ]
        ]);
        if ($response[Constants::IS_SUCCESS])
        {
            rzpLogInfo("sendHeartbeat: sent at order_id=" . $this->pendingCursorOrderId);
        }
        else
        {
            rzpLogError("sendHeartbeat: failed at order_id=" . $this->pendingCursorOrderId);
        }
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
            else if ($statusCode === 404)
            {
                rzpLogInfo("isMerchantEligible: no config record yet (404), starting fresh");
                $this->jobConfig = [];
                return true;
            }
            else
            {
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

        $this->jobConfig = $configs[Constants::JOB] ?? [];

        // Once-per-day guard: skip if already completed today in IST.
        $todayStartDt = new DateTime('today midnight', new DateTimeZone(Constants::ADDRESS_SYNC_TIMEZONE));
        if (($this->jobConfig[Constants::STATUS]     ?? '') === Constants::COMPLETED &&
            ($this->jobConfig[Constants::UPDATED_AT] ?? 0)  >= $todayStartDt->getTimestamp())
        {
            rzpLogInfo("isMerchantEligible: already completed today, skipping");
            return false;
        }

        return true;
    }

    private function isValidAddress($address)
    {
        if ($this->isEmpty($address['line1']) && $this->isEmpty($address['line2']))
        {
            return false;
        }
        if (strlen($address['name'] ?? '') < 3         ||
            $this->isEmpty($address['city'])            ||
            $this->isEmpty($address['state'])           ||
            $this->isEmpty($address['zipcode'])         ||
            ($address['country'] ?? '') !== Constants::IN ||
            $this->isEmpty($address['contact']))
        {
            return false;
        }
        return true;
    }

    private function isEmpty($data)
    {
        return strlen(trim((string)($data ?? ''))) === 0;
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
                    Constants::SHIPPING_COUNTRY => Constants::IN,
                    Constants::DATE_CREATED     => '1...' . $this->upperCreatedAt,
                    'status'                    => ['processing', 'completed', 'on-hold'],
                    'orderby'                   => 'ID',
                    Constants::ORDER            => Constants::ASC,
                    Constants::LIMIT            => $this->batchSize,
                    Constants::PAGED            => $page,
                    'meta_query'                => [
                        'relation' => 'OR',
                        [
                            'key'     => 'is_magic_checkout_order',
                            'compare' => 'NOT EXISTS',
                        ],
                        [
                            'key'     => 'is_magic_checkout_order',
                            'value'   => 'yes',
                            'compare' => '!=',
                        ],
                    ],
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

                    return $id > $cursorOrderId && $id <= $upperOrderId;
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
            if ($this->isValidAddress($address))
            {
                $addresses[] = $address;
            }
        }
        return $addresses;
    }

    // Fetch the next batch of valid addresses using order ID keyset pagination.
    // Sets pendingCursorOrderId to the last order fetched but does NOT advance
    // cursorOrderId — that is committed in sync() only after backend returns 202.
    // For batches where all orders are address-invalid, the confirmed cursor is temporarily
    // advanced so the next iteration fetches a fresh window, not the same one.
    private function getNextBatch($endTime)
    {
        $addresses = [];
        while (empty($addresses) && time() < $endTime)
        {
            $this->sendHeartbeatIfStale();

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
            if (empty($addresses))
            {
                // All orders invalid — advance confirmed cursor so next iteration
                // fetches a genuinely new window, not the same orders again.
                $this->cursorOrderId   = $this->pendingCursorOrderId;
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
            $this->upperCreatedAt = $this->runStartedAt; // frozen upper bound for this run

            // Query the last qualifying order that exists right now to freeze upperOrderId.
            // Orders created after this point are excluded from this run.
            $upperOrders = wc_get_orders([
                Constants::SHIPPING_COUNTRY => Constants::IN,
                Constants::DATE_CREATED     => '1...' . $this->upperCreatedAt,
                'status'                    => ['processing', 'completed', 'on-hold'],
                'orderby'                   => 'ID',
                Constants::ORDER            => 'DESC',
                Constants::LIMIT            => 1,
                'meta_query'                => [
                    'relation' => 'OR',
                    ['key' => 'is_magic_checkout_order', 'compare' => 'NOT EXISTS'],
                    ['key' => 'is_magic_checkout_order', 'value' => 'yes', 'compare' => '!='],
                ],
            ]);
            $this->upperOrderId = !empty($upperOrders) ? $upperOrders[0]->get_id() : 0;

            $endTime = time() + $maxSeconds;

            // Stored checkpoint is the last successfully processed Woo order ID.
            $this->cursorOrderId        = (int)($this->jobConfig[Constants::CHECKPOINT] ?? 0);
            $this->pendingCursorOrderId = $this->cursorOrderId;
            $consecutiveFailureCount      = 0;

            rzpLogInfo("sync: upper_order_id=" . $this->upperOrderId . ", confirmed_order_id=" . $this->cursorOrderId . ", maxSeconds=" . $maxSeconds);
            updateAddressSyncCronData(Constants::PROCESSING);

            // Initial heartbeat so the backend knows the cron fired.
            $this->sendHeartbeatIfStale(true);

            while (time() < $endTime)
            {
                // Periodic heartbeat between batches. getNextBatch() also fires heartbeats
                // internally so long empty-page scans don't appear stale to monitoring.
                $this->sendHeartbeatIfStale();

                $addresses = $this->getNextBatch($endTime);

                if (empty($addresses))
                {
                    if (time() >= $endTime)
                    {
                        // Time ran out inside getNextBatch while scanning empty pages.
                        // Checkpoint advancement is handled by postRawAddresses() on batch success;
                        // this event carries only the lifecycle state change.
                        rzpLogInfo("sync: time limit reached while scanning pages at order_id=" . $this->pendingCursorOrderId);
                        $pauseResponse = $this->postAddresses([
                            Constants::META_DATA => [
                                Constants::STATUS  => Constants::PAUSED,
                                Constants::MESSAGE => Constants::MAX_RUNNING_TIME_REACHED,
                            ]
                        ]);
                        if (!$pauseResponse[Constants::IS_SUCCESS])
                        {
                            rzpLogError("sync: failed to send paused event to backend at order_id=" . $this->pendingCursorOrderId);
                        }
                        updateAddressSyncCronData(Constants::PAUSED, Constants::MAX_RUNNING_TIME_REACHED);
                    }
                    else
                    {
                        // No more orders in the window — sync is complete.
                        // Store the run's exact upper order ID so next run starts strictly after it.
                        rzpLogInfo("sync: completed. upper_order_id=" . $this->upperOrderId);
                        $completionResponse = $this->postAddresses([
                            Constants::META_DATA        => [
                                Constants::STATUS  => Constants::COMPLETED,
                                Constants::MESSAGE => Constants::ADDRESS_SYNC_COMPLETED,
                            ],
                            Constants::SYNC_UPPER_BOUND => $this->upperOrderId,
                        ]);
                        if (!$completionResponse[Constants::IS_SUCCESS])
                        {
                            rzpLogError("sync: failed to notify backend of completion at order_id=" . $this->pendingCursorOrderId);
                            updateAddressSyncCronData(Constants::PAUSED, Constants::POST_ADDRESSES_ERROR);
                            return;
                        }
                        updateAddressSyncCronData(Constants::COMPLETED, Constants::ADDRESS_SYNC_COMPLETED);
                    }
                    return;
                }

                rzpLogInfo("sync: posting " . count($addresses) . " addresses at order_id=" . $this->pendingCursorOrderId);

                // Six total attempts across three phases: 3 → wait 10min → 2 → wait 5min → 1
                $response = $this->postRawAddressesInPhases($addresses, $endTime);

                if (!$response[Constants::IS_SUCCESS])
                {
                    // All attempts failed. Do not advance the confirmed cursor; the same
                    // batch remains the resume point until it succeeds or the run stops.
                    $consecutiveFailureCount++;
                    $this->batchFailures++;
                    rzpLogError("sync: batch failed (consecutive_failures=" . $consecutiveFailureCount . ") at order_id=" . $this->pendingCursorOrderId);

                    if ($consecutiveFailureCount >= Constants::MAX_CONSECUTIVE_FAILURES)
                    {
                        $failMsg = Constants::CONSECUTIVE_BATCH_FAILURES . '_' . $consecutiveFailureCount;
                        rzpLogError("sync: stopping — " . $consecutiveFailureCount . " consecutive batch failures");
                        $failedResponse = $this->postAddresses([
                            Constants::META_DATA => [
                                Constants::STATUS  => Constants::FAILED,
                                Constants::MESSAGE => $failMsg,
                            ]
                        ]);
                        if (!$failedResponse[Constants::IS_SUCCESS])
                        {
                            rzpLogError("sync: also failed to send failed event to backend");
                        }
                        updateAddressSyncCronData(Constants::FAILED, $failMsg);
                        return;
                    }

                    // Below the stop threshold — retry from the same confirmed cursor.
                    // pendingCursorOrderId is not persisted unless a raw batch succeeds.
                    continue;
                }

                // Batch accepted — commit pending order ID to confirmed.
                // This is the ONLY place cursorOrderId advances after a valid address batch.
                $this->cursorOrderId   = $this->pendingCursorOrderId;
                $consecutiveFailureCount = 0;
                $this->batchesIngested++;
                $this->addressesIngested += count($addresses);
                $this->markActivity();

                // Cooldown between consecutive successful batches — prevents hammering the API
                // and gives the 1cc-address-service headroom to process the goroutine work.
                if (Constants::BATCH_COOLDOWN_MS > 0)
                {
                    usleep(Constants::BATCH_COOLDOWN_MS * 1000);
                }
            }

            // Time budget exhausted. Checkpoint is already persisted by the last successful
            // raw batch — this event carries only the lifecycle state change.
            rzpLogInfo("sync: time limit reached at order_id=" . $this->pendingCursorOrderId);
            $pauseResponse = $this->postAddresses([
                Constants::META_DATA => [
                    Constants::STATUS  => Constants::PAUSED,
                    Constants::MESSAGE => Constants::MAX_RUNNING_TIME_REACHED,
                ]
            ]);
            if (!$pauseResponse[Constants::IS_SUCCESS])
            {
                rzpLogError("sync: failed to send paused event to backend at order_id=" . $this->pendingCursorOrderId);
            }
            updateAddressSyncCronData(Constants::PAUSED, Constants::MAX_RUNNING_TIME_REACHED);
        }
        catch (Exception $e)
        {
            rzpLogError("sync: unhandled exception: " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            $failedResponse = $this->postAddresses([
                Constants::META_DATA => [
                    Constants::STATUS  => Constants::FAILED,
                    Constants::MESSAGE => Constants::UNHANDLED_EXCEPTION_OCCURRED_IN_CRON . $e->getMessage(),
                ]
            ]);
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

        $api              = new Api($paymentSettings[Constants::KEY_ID], $paymentSettings[Constants::KEY_SECRET]);
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
