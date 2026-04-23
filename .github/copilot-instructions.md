# GitHub Copilot Instructions - Razorpay WooCommerce Plugin

This file provides context for GitHub Copilot to give better suggestions for this codebase.

## Project Context

This is the **Razorpay Payment Gateway plugin for WooCommerce** (WordPress). Version 4.8.3.

- **Language:** PHP 7.4+, JavaScript (vanilla)
- **Framework:** WordPress Plugin API + WooCommerce Payment Gateway
- **Main Class:** `WC_Razorpay` extends `WC_Payment_Gateway`
- **Bundled SDK:** Razorpay PHP SDK in `razorpay-sdk/`

## Code Patterns to Follow

### 1. HPOS-Compatible Order Meta Access
Always check `isHposEnabled` before accessing order meta:
```php
if ($this->isHposEnabled) {
    $value = $order->get_meta('meta_key');
    $order->update_meta_data('meta_key', $newValue);
    $order->save();
} else {
    $value = get_post_meta($orderId, 'meta_key', true);
    update_post_meta($orderId, 'meta_key', $newValue);
}
```

### 2. Amount Conversion (Always in Paise)
```php
// WC order total is in display currency
$amountInPaise = (int) round($order->get_total() * 100);

// Convert back for display
$amountDisplay = $amountInPaise / 100;
```

### 3. Razorpay API Calls
```php
$api = $this->getRazorpayApiInstance();
try {
    $order = $api->order->create($data);
    $payment = $api->payment->fetch($paymentId);
    $payment->capture(['amount' => $amountInPaise]);
    $payment->refund(['amount' => $amountInPaise, 'notes' => [...]]);
} catch (Razorpay\Api\Errors\BadRequestError $e) {
    // Safe to show to user
} catch (Exception $e) {
    // Log only, return generic error
    rzpLogError($e->getMessage());
    return new Exception("Payment failed");
}
```

### 4. Logging
```php
rzpLogInfo("Descriptive message with context");
rzpLogError("Error condition: " . $e->getMessage());
rzpLogDebug("Detailed debug: " . json_encode($data));
```

### 5. REST API Handlers (1CC)
```php
function myHandler(WP_REST_Request $request) {
    try {
        $params = $request->get_params();
        $orderId = (int) sanitize_text_field($params['order_id']);
        
        // Process...
        
        return new WP_REST_Response(['status' => true, ...], 200);
    } catch (Exception $e) {
        rzpLogError("myHandler failed: " . $e->getMessage());
        return new WP_REST_Response(['status' => false, 'message' => $e->getMessage()], 500);
    }
}
```

### 6. Input Sanitization
```php
$text = sanitize_text_field($_POST['field']);
$int = (int) sanitize_text_field($_POST['id']);
$nonce = sanitize_text_field($_POST['nonce']);
wp_verify_nonce($nonce, 'action_name');
```

### 7. Admin Action Security
```php
current_user_can('manage_woocommerce');
wp_verify_nonce($nonce, 'action_name');
```

## Key Files

| File | When to Edit |
|------|-------------|
| `woo-razorpay.php` | Payment flow changes, new settings, hooks |
| `includes/razorpay-webhook.php` | New webhook event handling |
| `includes/api/api.php` | New 1CC REST endpoint registration |
| `includes/razorpay-route.php` | Route admin UI changes |
| `includes/razorpay-route-actions.php` | Route transfer business logic |
| `includes/utils.php` | New global utility functions |

## Important Constants

```php
WC_Razorpay::RAZORPAY_PAYMENT_ID = 'razorpay_payment_id'
WC_Razorpay::RAZORPAY_ORDER_ID   = 'razorpay_order_id'
WC_Razorpay::CAPTURE             = 'capture'
WC_Razorpay::AUTHORIZE           = 'authorize'
WC_Razorpay::INR                 = 'INR'
RZP_1CC_ROUTES_BASE              = '1cc/v1'
RZP_1CC_CART_HASH                = 'wc_razorpay_cart_hash_'
```

## Webhook Event Handling Pattern

When adding a new webhook event handler:

```php
// In RZP_Webhook::$eventsArray - add the event name
// In RZP_Webhook::process() switch statement - add case
// Create the handler method:
protected function myNewEvent(array $data) {
    $orderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];
    $order = $this->checkIsObject($orderId);
    if ($order === false) return;
    
    // Process event...
    
    exit; // Always exit webhook handlers
}
```

## REST Endpoint Registration Pattern

```php
// In includes/api/api.php - rzp1ccInitRestApi()
register_rest_route(
    RZP_1CC_ROUTES_BASE . '/resource',
    'action',
    [
        'methods'             => 'POST',
        'callback'            => 'myHandlerFunction',
        'permission_callback' => 'checkAuthCredentials', // or 'checkHmacSignature'
    ]
);

// Create handler in appropriate file under includes/api/
// Include the file in api.php
```

## Subscription Awareness

When processing payments, always check for subscriptions:
```php
// Skip subscription/invoice payments in main payment flow
if (isset($data['invoice_id']) === true) {
    return; // Handled by companion subscription plugin
}
```

## Never Do

- Never use `error_log()` directly - use `rzpLogError()`
- Never expose Razorpay internal errors to customers for generic exceptions
- Never skip signature verification
- Never process payments without checking `order->needs_payment()`
- Never hardcode API URLs - use SDK methods
- Never process the same refund twice - check `refund_from_website` note
