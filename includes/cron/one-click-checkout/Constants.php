<?php

class Constants
{
    const ONE_CC_ADDRESS_SYNC_CRON_HOOK = 'one_cc_address_sync_cron';
    const ONE_CC_ADDRESS_SYNC_CRON_EXEC = 'one_cc_address_sync_cron_exec';
    const STATUS                         = 'status';
    const DEACTIVATED                    = 'deactivated';
    const PROCESSING                     = 'processing';
    const COMPLETED                      = 'completed';
    const PAUSED                         = 'paused';
    const CREATED_AT                     = 'created_at';
    const UPDATED_AT                     = 'updated_at';
    const MESSAGE                        = 'message';
    const CANCELLED                      = 'cancelled';
    const FAILED                         = 'failed';
    const KEY_ID                         = 'key_id';
    const KEY_SECRET                     = 'key_secret';
    const ENABLE_1CC                     = 'enable_1cc';
    const CRON_STATUS                    = 'cron_status';
    const INVALID_1CC_MERCHANT           = 'invalid_1cc_merchant';
    const INVALID_RAZORPAY_MERCHANT      = 'invalid_razorpay_merchant';
    const MAX_RUNNING_TIME_REACHED       = 'max_running_time_reached';
    const WOOCOMMERCE                    = 'woocommerce';
    const IS_SUCCESS                     = 'is_success';
    const META_DATA                      = 'meta_data';
    const CHECKPOINT                     = 'checkpoint';
    const ADDRESSES                      = 'addresses';
    const DELETED                        = 'deleted';
    const WOOCOMMERCE_ADDRESS_SYNC_ENABLED = 'woocommerce_address_sync_enabled';
    const BODY                           = 'body';
    const STATUS_CODE                    = 'status_code';
    const DEV_ADDRESS_SYNC_BASE_URL      = '';
    const DATE_CREATED                   = 'date_created';
    const LIMIT                          = 'limit';
    const PAGED                          = 'paged';
    const POST_ADDRESSES_ERROR           = 'post_addresses_error';
    const SEVEN_DAYS_IN_SECONDS          = 604800;
    const UNHANDLED_EXCEPTION_OCCURRED_IN_CRON = 'unhandled_exception_occurred_in_cron';
    const CONSECUTIVE_BATCH_FAILURES     = 'consecutive_batch_failures';
    const CONFIG_RECEIVED                = 'config_received';
    const ADDRESS_INGESTION_COMPLETE     = 'address_ingestion_complete';
    const ADDRESS_INGESTION_PAUSED       = 'address_ingestion_paused';
    const ADDRESS_INGESTION_FAILED       = 'address_ingestion_failed';
    const BATCH_INGESTED                 = 'batch_ingested';
    const WOOCOMMERCE_ADDRESS_SYNC_DISABLED = 'woocommerce_address_sync_disabled';
    const ERROR                          = 'error';
    const WARNING                        = 'warning';
    const ORDER                          = 'order';
    const ASC                            = 'ASC';
    const BATCH_RETRY_PHASE1_FAILED      = 'batch_retry_phase1_failed';
    const BATCH_RETRY_PHASE2_FAILED      = 'batch_retry_phase2_failed';
    const BATCH_FAILED                   = 'batch_failed';

    // IST scheduling — all time calculations use Asia/Kolkata explicitly so the result
    // is correct regardless of what timezone the merchant's server PHP is configured with.
    const ADDRESS_SYNC_TIMEZONE        = 'Asia/Kolkata';
    const ADDRESS_SYNC_CRON_START_TIME = '00:00';
    const ADDRESS_SYNC_HARD_STOP_TIME  = '05:00';

    // Cooldown between consecutive successful batches.
    const BATCH_COOLDOWN_MS = 2000;

    // Three-phase batch retry: 3 -> wait 10min -> 2 -> wait 5min -> 1 = 6 total attempts.
    const BATCH_PHASE1_MAX          = 3;
    const BATCH_PHASE1_WAIT_SECONDS = 600;
    const BATCH_PHASE2_MAX          = 2;
    const BATCH_PHASE2_WAIT_SECONDS = 300;
    const BATCH_PHASE3_MAX          = 1;

    // Stop cron after this many consecutive failed batches.
    const MAX_CONSECUTIVE_FAILURES = 10;
}
