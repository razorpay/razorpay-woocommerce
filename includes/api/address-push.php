<?php

/**
 * Standard checkout address push to Razorpay CMS via Kong → 1cc-address-service.
 * Fire-and-forget: blocking:false means zero checkout delay.
 * Metrics come from Kong access logs + address-service rawaddressmetrics.
 */

/**
 * Check if address push is enabled for this merchant via DCS feature flag.
 * Cached per merchant (md5 of key_id) for 3600s. Fails closed on error.
 */
function rzpIsAddressPushEnabled($keyId, $keySecret) {
    $cacheKey = 'rzp_addr_push_dcs_' . md5($keyId);
    $cached   = get_transient($cacheKey);
    if ($cached !== false) return $cached === 'yes';

    try {
        $modeCode = (strpos($keyId, 'rzp_test_') === 0) ? 2 : 1;
        $api      = new \Razorpay\Api\Api($keyId, $keySecret);
        $resp     = $api->request->request('GET',
                      'app/merchant/api/verify/' . RZP_ADDRESS_PUSH_DCS_FEATURE_ID . '/' . $modeCode);
        $enabled  = isset($resp['enabled']) && $resp['enabled'] === true;
    } catch (Exception $e) {
        rzpLogError("Address push DCS check failed: " . $e->getMessage());
        $enabled = false;
    }

    set_transient($cacheKey, $enabled ? 'yes' : 'no', 3600);
    return $enabled;
}

/**
 * Build address array from WC order. Prefers shipping fields; falls back to billing.
 * State is sent as full WooCommerce name (e.g. "Karnataka") not ISO code.
 */
function rzpBuildAddressFromOrder($order) {
    $wcStates = WC()->countries->get_states('IN') ?? [];

    $shippingState = trim($order->get_shipping_state() ?? '');
    $billingState  = trim($order->get_billing_state()  ?? '');
    $stateName = ($wcStates[$shippingState] ?: null)
              ?: ($wcStates[$billingState]  ?: null)
              ?: $shippingState
              ?: $billingState
              ?: '';

    $shippingName = trim(trim($order->get_shipping_first_name() ?? '') . ' ' . trim($order->get_shipping_last_name() ?? ''));
    $billingName  = trim(trim($order->get_billing_first_name()  ?? '') . ' ' . trim($order->get_billing_last_name()  ?? ''));

    return [
        'contact' => trim($order->get_shipping_phone()    ?? '') ?: trim($order->get_billing_phone()      ?? ''),
        'name'    => $shippingName ?: $billingName,
        'line1'   => trim($order->get_shipping_address_1() ?? '') ?: trim($order->get_billing_address_1() ?? ''),
        'line2'   => trim($order->get_shipping_address_2() ?? '') ?: trim($order->get_billing_address_2() ?? ''),
        'city'    => trim($order->get_shipping_city()      ?? '') ?: trim($order->get_billing_city()      ?? ''),
        'state'   => $stateName,
        'zipcode' => trim($order->get_shipping_postcode()  ?? '') ?: trim($order->get_billing_postcode()  ?? ''),
        'country' => trim($order->get_shipping_country()   ?? '') ?: trim($order->get_billing_country()   ?? ''),
    ];
}

/**
 * Validate address has all required fields for a push.
 * country must be IN (NormalizeContact is IN-only in address-service).
 */
function rzpIsValidAddressForPush($address) {
    if (($address['country'] ?? '') !== 'IN') return false;
    $contact = preg_replace('/\D/', '', $address['contact'] ?? '');
    if (strlen($contact) < 10 || strlen($contact) > 15) return false;
    if (strlen($address['name']    ?? '') <= 1)  return false;
    if (empty($address['line1']   ?? ''))        return false;
    if (empty($address['city']    ?? ''))        return false;
    if (empty($address['state']   ?? ''))        return false;
    if (empty($address['zipcode'] ?? ''))        return false;
    return true;
}

/**
 * Push order address to Razorpay CMS via Kong → 1cc-address-service.
 * Guards run cheapest-first: credentials → test key → DCS (network) → address validation.
 */
function rzpPushAddress($order, $keyId, $keySecret) {
    // Guard 1: credentials — cheapest check, always first
    if (empty($keyId) || empty($keySecret)) return;

    // Guard 2: test key — cheap string check before any network I/O
    // Blocked on prod; set RZP_ADDRESS_PUSH_ALLOW_TEST_KEYS=true in wp-config.php for stage only.
    if (!RZP_ADDRESS_PUSH_ALLOW_TEST_KEYS && strpos($keyId, 'rzp_test_') === 0) return;

    // Guard 3: DCS feature flag (cached 3600s, fails closed) — network I/O, runs after cheap guards
    if (!rzpIsAddressPushEnabled($keyId, $keySecret)) return;

    // Guard 4 + 5: build + validate address (includes country=IN check)
    $address = rzpBuildAddressFromOrder($order);
    if (!rzpIsValidAddressForPush($address)) return;

    // Fire and forget — blocking:false means zero checkout delay.
    // No response read. No flags set. No retries.
    wp_remote_post(RZP_ADDRESS_PUSH_URL, [
        'blocking'  => false,
        'method'    => 'POST',
        'sslverify' => true,
        'headers'   => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Basic ' . base64_encode("$keyId:$keySecret"),
        ],
        'body' => json_encode(['addresses' => [$address]]),
    ]);
}
