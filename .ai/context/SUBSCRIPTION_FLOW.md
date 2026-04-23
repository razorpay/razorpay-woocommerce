# Subscription Flow — Razorpay WooCommerce Plugin

## Overview

The base plugin provides **hook points and webhook event stubs** for subscription handling, but the actual subscription logic is implemented in a **separate** plugin: **Razorpay WooCommerce Subscriptions** (not included in this repository).

The base plugin's subscription-related code establishes:
1. Webhook event registration (events enabled when subscription flag is set)
2. Stub methods that subscriptions plugin overrides via inheritance
3. Webhook table storage for `subscription.charged` events

---

## Subscription Webhook Events

The following events are registered in `$supportedWebhookEvents`:
```php
'subscription.cancelled'
'subscription.paused'
'subscription.resumed'
'subscription.charged'
```

These are **only added** to the auto-registered webhook when the `rzp_subscription_webhook_enable_flag` option is set:

```php
$subscriptionWebhookFlag = get_option('rzp_subscription_webhook_enable_flag');
if ($subscriptionWebhookFlag) {
    $this->defaultWebhookEvents += [
        'subscription.cancelled' => true,
        'subscription.resumed'   => true,
        'subscription.paused'    => true,
        'subscription.charged'   => true,
    ];
}
```

The subscriptions plugin sets this flag during its activation.

---

## Base Class Stub Methods

In `RZP_Webhook`, all subscription event handlers are no-ops:

```php
protected function subscriptionCancelled(array $data)  { return; }
protected function subscriptionPaused(array $data)     { return; }
protected function subscriptionResumed(array $data)    { return; }
protected function subscriptionCharged(array $data)    { return; }
```

The subscriptions plugin extends `RZP_Webhook` and overrides these methods.

---

## `payment.authorized` and Subscriptions

In `paymentAuthorized()`, subscription/invoice payments are explicitly **skipped**:

```php
if (isset($data['invoice_id']) === true) {
    rzpLogInfo("We don't process subscription/invoice payments here");
    return;
}
```

Subscription payments have `invoice_id` set in the payment entity. The subscriptions plugin handles these via its own `paymentAuthorized()` override.

---

## `subscription.charged` Webhook

When the base class receives `subscription.charged`:
1. The `orderId` is extracted from `$data['payload']['subscription']['entity']['notes']['woocommerce_order_id']`.
2. The `razorpayOrderId` comes from `$data['payload']['payment']['entity']['order_id']`.
3. The event is dispatched to `subscriptionCharged()` → no-op in base class.

The subscriptions plugin override:
1. Creates a new WooCommerce subscription renewal order.
2. Marks it as paid with the Razorpay payment ID.
3. Updates subscription status.

---

## Magic Checkout (1CC) + Subscriptions

Magic Checkout supports subscription products. When a subscription product is in the cart:

1. `createWcOrder()` REST handler creates a WC order with subscription line items.
2. `createOrGetRazorpayOrderId()` with `is1ccCheckout = 'yes'` includes `line_items[]` in the payload.
3. The Razorpay order is created with `line_items_total` (separate from final amount to accommodate shipping/COD).
4. After payment, `update1ccOrderWC()` handles the subscription's first payment.

---

## Settings Interaction

The subscriptions plugin (when installed) calls `mergeSettingsWithParentPlugin()` to share the same `key_id`, `key_secret`, and `payment_action` settings as the base plugin. This is why `WC_Razorpay::__construct($hooks = false)` exists — subscriptions instantiate the base class without re-registering hooks.

---

## Key Notes for Development

1. **Do not** add subscription-specific logic to this repository. The design intent is clear separation.
2. The `rzp_subscription_webhook_enable_flag` option is the **feature gate** — only set by the subscriptions plugin.
3. Webhook events for subscriptions use `$data['payload']['subscription']['entity']` (not `payment.entity`) for the `woocommerce_order_id`.
4. `subscription.charged` is the only subscription event that also carries a `razorpayOrderId` (payment entity). The others have `"No payment id in subscription event"` as the value.
