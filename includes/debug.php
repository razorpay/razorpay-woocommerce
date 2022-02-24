<?php

/**
 * Log a message if Razorpay debug mode is enabled.
 *
 * @param string $level
 * 'emergency': System is unusable.
 *  'alert': Action must be taken immediately.
 * 'critical': Critical conditions.
 * 'error': Error conditions.
 * 'warning': Warning conditions.
 * 'notice': Normal but significant condition.
 * 'info': Informational messages.
 * 'debug': Debug-level messages.
 * @param string $message Message to log.
 */
define('RAZORPAY_LOG_NAME', 'razorpay-logs');

function rzpLog($level, $message)
{
    if (isDebugModeEnabled()) {
        $logger = wc_get_logger();
        $logger->log($level, $message, array('source' => RAZORPAY_LOG_NAME));
    }
}

/**
 * Adds an emergency level message if Razorpay debug mode is enabled
 *
 * System is unusable.
 *
 * @see WC_Logger::log
 *
 * @param string $message Message to log.
 */
function rzpLogEmergency($message)
{
    rzpLog('emergency', $message);
}

/**
 * Adds an alert level message if Razorpay debug mode is enabled.
 *
 * Action must be taken immediately.
 * Example: Entire website down, database unavailable, etc.
 *
 * @see WC_Logger::log
 *
 * @param string $message Message to log.
 */
function rzpLogAlert($message)
{
    rzpLog('alert', $message);
}

/**
 * Adds a critical level message if Razorpay debug mode is enabled.
 *
 * Critical conditions.
 * Example: Application component unavailable, unexpected exception.
 *
 * @see WC_Logger::log
 *
 * @param string $message Message to log.
 */
function rzpLogCritical($message)
{
    rzpLog('critical', $message);
}

/**
 * Adds an error level message if Razorpay debug mode is enabled.
 *
 * Runtime errors that do not require immediate action but should typically be logged
 * and monitored.
 *
 * @see WC_Logger::log
 *
 * @param string $message Message to log.
 */
function rzpLogError($message)
{
    rzpLog('error', $message);
}

/**
 * Adds a warning level message if Razorpay debug mode is enabled.
 *
 * Exceptional occurrences that are not errors.
 *
 * Example: Use of deprecated APIs, poor use of an API, undesirable things that are not
 * necessarily wrong.
 *
 * @see WC_Logger::log
 *
 * @param string $message Message to log.
 */
function rzpRazorpay_log_warning($message)
{
    rzpLog('warning', $message);
}

/**
 * Adds a notice level message if Razorpay debug mode is enabled.
 *
 * Normal but significant events.
 *
 * @see WC_Logger::log
 *
 * @param string $message Message to log.
 */
function rzpLogNotice($message)
{
    rzpLog('notice', $message);
}

/**
 * Adds a info level message if Razorpay debug mode is enabled
 *
 * Interesting events.
 * Example: User logs in, SQL logs
 *
 * @see WC_Logger::log
 *
 * @param string $message Message to log.
 */
function rzpLogInfo($message)
{
    rzpLog('info', $message);
}

/**
 * Adds a debug level message if Razorpay debug mode is enabled
 * Detailed debug information
 * @see WC_Logger::log
 * @param string $message Message to log
 */
function rzpLogDebug($message)
{
    rzpLog('debug', $message);
}
