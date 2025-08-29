<?php
// WOOCS plugin active and multiple allowed flag
function razorpay_is_woocs_multiple_allowed_enabled()
{
    if (is_plugin_active('woocommerce-currency-switcher/index.php')) 
    {
        return ((int) get_option('woocs_is_multiple_allowed', 0)) === 1;
    }

    return false;
}

// Convert amount from base currency (paise) to target order currency using WOOCS rates
function razorpay_currency_convert($amountInPaise, $orderCurrency)
{
    global $WOOCS;
    if (!isset($WOOCS)) 
    {
        return $amountInPaise; // fallback if WOOCS not available
    }

    $currencies    = $WOOCS->get_currencies();
    if (!isset($currencies[$orderCurrency])) 
    {
        return $amount_in_paise; // fallback if currency not found
    }

    $orderRate     = $currencies[$orderCurrency]['rate'];
    return round($orderRate * $amountInPaise, 0);
} 
