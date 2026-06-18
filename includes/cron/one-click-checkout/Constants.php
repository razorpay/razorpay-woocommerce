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
    const ADDRESS_SYNC_OFF_CONFIGURED    = 'address_sync_off_configured';
    const MAX_RUNNING_TIME_REACHED       = 'max_running_time_reached';
    const CHECKPOINT_NOT_UPDATED         = 'checkpoint_not_updated';
    const WOOCOMMERCE                    = 'woocommerce';
    const PLATFORM                       = 'platform';
    const KEYS                           = 'keys';
    const IS_SUCCESS                     = 'is_success';
    const META_DATA                      = 'meta_data';
    const JOB                            = 'job';
    const ONE_CC_ONBOARDED_TIMESTAMP     = 'one_cc_onboarded_timestamp';
    const CHECKPOINT                     = 'checkpoint';
    const SYNC_UPPER_BOUND               = 'sync_upper_bound';
    const ADDRESSES                      = 'addresses';
    const DELETED                        = 'deleted';
    const ONE_CLICK_CHECKOUT             = 'one_click_checkout';
    const ONE_CC_ADDRESS_SYNC_OFF        = 'one_cc_address_sync_off';
    const BODY                           = 'body';
    const STATUS_CODE                    = 'status_code';
    const SOURCE                         = 'source';
    const SHIPPING_COUNTRY               = 'shipping_country';
    const IN                             = 'IN';
    const DATE_CREATED                   = 'date_created';
    const LIMIT                          = 'limit';
    const PAGED                          = 'paged';
    const POST_ADDRESS_MARK_COMPLETED_ERROR = 'post_address_mark_completed_error';
    const POST_ADDRESSES_ERROR           = 'post_addresses_error';
    const SEVEN_DAYS_IN_SECONDS          = 604800;
    const POST_ADDRESS_MARK_PAUSED_CHECKPOINT_NOT_UPDATED_ERROR   = 'post_address_mark_paused_checkpoint_not_updated_error';
    const POST_ADDRESS_MARK_PAUSED_MAX_RUNNING_TIME_REACHED_ERROR = 'post_address_mark_paused_max_running_time_reached_error';
    const POST_ADDRESS_MARK_CANCELLED_ERROR    = 'post_address_mark_cancelled_error';
    const UNHANDLED_EXCEPTION_OCCURRED_IN_CRON = 'unhandled_exception_occurred_in_cron';
    const CONSECUTIVE_BATCH_FAILURES     = 'consecutive_batch_failures';
    const LAST_CHECKPOINT                = 'last_checkpoint';
    const PENDING_CHECKPOINT             = 'pending_checkpoint';
    const UPPER_BOUND                    = 'upper_bound';
    const BATCHES_INGESTED              = 'batches_ingested';
    const ADDRESSES_INGESTED            = 'addresses_ingested';
    const HEARTBEATS_SENT               = 'heartbeats_sent';
    const BATCH_FAILURES                = 'batch_failures';
    const RUN_STARTED_AT                = 'run_started_at';
    const TRACE                          = 'trace';
    const ORDER                          = 'order';
    const ASC                            = 'ASC';
    const ID                             = 'ID';
    const ADDRESS_SYNC_COMPLETED         = 'address_sync_completed';

    // IST scheduling — all time calculations use Asia/Kolkata explicitly so the result
    // is correct regardless of what timezone the merchant's server PHP is configured with.
    const ADDRESS_SYNC_TIMEZONE        = 'Asia/Kolkata';
    const ADDRESS_SYNC_CRON_START_TIME = '01:00';
    const ADDRESS_SYNC_HARD_STOP_TIME  = '05:00';

    // Heartbeat sent every 15 minutes only when there has been no successful batch activity.
    // Server uses TouchRunStatus (direct SQL UPDATE) so updated_at always advances.
    const HEARTBEAT                  = 'heartbeat';
    const HEARTBEAT_INTERVAL_SECONDS = 900;

    // Cooldown between consecutive successful batches.
    const BATCH_COOLDOWN_MS = 500;

    // Three-phase batch retry: 3 → wait 10min → 2 → wait 5min → 1 = 6 total attempts.
    const BATCH_PHASE1_MAX          = 3;
    const BATCH_PHASE1_WAIT_SECONDS = 600;
    const BATCH_PHASE2_MAX          = 2;
    const BATCH_PHASE2_WAIT_SECONDS = 300;
    const BATCH_PHASE3_MAX          = 1;

    // Stop cron after this many consecutive failed batches.
    const MAX_CONSECUTIVE_FAILURES = 3;
}
