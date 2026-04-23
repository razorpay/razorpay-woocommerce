# WordPress & WooCommerce Hooks — Razorpay WooCommerce Plugin

## Actions Registered (`add_action`)

### Plugin Bootstrap (global scope, `woo-razorpay.php`)

| Hook | Callback | Priority | Description |
|---|---|---|---|
| `plugins_loaded` | `woocommerce_razorpay_init` | 0 | Loads the main `WC_Razorpay` class |
| `plugins_loaded` | `rzpWcEnsure1ccSecret` | 20 | Ensures 1CC HMAC secret exists and is current |
| `admin_post_nopriv_rzp_wc_webhook` | `razorpay_webhook_init` | 10 | Entry point for incoming webhooks |
| `before_woocommerce_init` | (closure) | — | Declares HPOS compatibility |
| `before_woocommerce_init` | (closure) | — | Declares cart/checkout blocks compatibility |
| `woocommerce_blocks_loaded` | `razorpay_woocommerce_block_support` | — | Registers `WC_Razorpay_Blocks` for Gutenberg checkout |

### Inside `woocommerce_razorpay_init()` (class registration + admin actions)

| Hook | Callback | Priority | Description |
|---|---|---|---|
| `woocommerce_update_options_advanced` | (closure) | — | Tracks HPOS enable/disable |

### `WC_Razorpay::initHooks()` (standard checkout)

| Hook | Callback | Priority | Description |
|---|---|---|---|
| `woocommerce_receipt_razorpay` | `receipt_page` | — | Renders payment form on receipt page |
| `woocommerce_api_razorpay` | `check_razorpay_response` | — | Handles payment callback redirect |
| `woocommerce_update_options_payment_gateways_razorpay` | `pluginInstrumentation` | — | Track settings save |
| `woocommerce_update_options_payment_gateways_razorpay` | `process_admin_options` | — | WC built-in: save settings |
| `woocommerce_update_options_payment_gateways_razorpay` | `autoEnableWebhook` | — | Auto-register webhook on save |
| `woocommerce_update_options_payment_gateways_razorpay` | `addAdminCheckoutSettingsAlert` | — | Show 1CC status notice |
| `woocommerce_update_options_payment_gateways_razorpay` | `createOneCCAddressSyncCron` | — | Create address sync cron |
| `woocommerce_update_options_payment_gateways_razorpay` | `syncPluginFetchCron` | — | Create plugin fetch cron |
| `wp_enqueue_scripts` | `enqueue_checkout_js_script_on_checkout` | — | Enqueue Razorpay checkout.js on checkout pages |

### Route Module (`includes/razorpay-route.php`)

| Hook | Callback | Priority | Description |
|---|---|---|---|
| `setup_extra_setting_fields` | `addRouteModuleSettingFields` | — | Add route_enable checkbox to settings |
| `check_route_enable_status` | `razorpayRouteModule` | 0 | Initialize Route admin UI if enabled |
| `admin_post_rzp_direct_transfer` | (closure) | — | Handle direct transfer form submit |
| `admin_post_rzp_reverse_transfer` | (closure) | — | Handle reverse transfer form submit |
| `admin_post_rzp_settlement_change` | (closure) | — | Handle settlement change form submit |
| `admin_post_rzp_payment_transfer` | (closure) | — | Handle payment transfer form submit |
| `admin_menu` | `rzpAddPluginPage` | — | Add Route admin menu pages |
| `admin_enqueue_scripts` | `adminEnqueueScriptsFunc` | 0 | Enqueue Route admin JS/CSS |
| `woocommerce_product_data_panels` | `productTransferDataFields` | — | Add transfer fields to product edit |
| `woocommerce_process_product_meta` | `woocommerce_process_transfer_meta_fields_save` | — | Save product transfer meta |
| `add_meta_boxes` | `paymentTransferMetaBox` | — | Add transfer meta box to order edit |

### Plugin Instrumentation (`includes/plugin-instrumentation.php`)

| Hook | Callback | Priority | Description |
|---|---|---|---|
| (activation) | `razorpayPluginActivated` | 10 | Track plugin activation + init crons |
| (deactivation) | `razorpayPluginDeactivated` | 10 | Track deactivation + delete crons |
| `upgrader_process_complete` | `razorpayPluginUpgraded` | 10 | Track upgrade + conditionally init crons |
| `wp_ajax_rzpInstrumentation` | `rzpInstrumentation` | — | Admin AJAX handler for instrumentation |

### 1CC API (`includes/api/api.php`)

| Hook | Callback | Priority | Description |
|---|---|---|---|
| `rest_api_init` | `rzp1ccInitRestApi` | — | Register all 1CC REST endpoints |
| `setup_extra_setting_fields` | `addMagicCheckoutSettingFields` | — | Add 1CC settings fields |

### Affordability Widget (`includes/razorpay-affordability-widget.php`)

| Hook | Callback | Priority | Description |
|---|---|---|---|
| `woocommerce_sections_checkout` | `addSubSection` | — | Add affordability widget sub-section |
| `woocommerce_settings_tabs_checkout` | `displayAffordabilityWidgetSettings` | — | Display widget settings |
| `woocommerce_update_options_checkout` | `updateAffordabilityWidgetSettings` | — | Save widget settings |
| (product page hook) | `addAffordabilityWidgetHTML` | — | Render widget HTML on product page |

### Cron (`includes/cron/`)

| Hook | Callback | Priority | Description |
|---|---|---|---|
| `one_cc_address_sync_cron` | Address sync function | — | Scheduled cron for 1CC address sync |

---

## Filters Registered (`add_filter`)

| Filter | Callback | Priority | Args | Description |
|---|---|---|---|---|
| `woocommerce_payment_gateways` | (internal WC) | — | — | Plugin registers itself via WC gateway filter |
| `woocommerce_thankyou_order_received_text` | `getCustomOrdercreationMessage` | 20 | 2 | Custom thank-you message |
| `script_loader_tag` | `add_defer_to_checkout_js` | 10 | 3 | Add `defer` attribute to checkout.js |
| `woocommerce_product_data_tabs` | `transferDataTab` | 90 | 1 | Add Route transfer tab to product |
| `nonce_user_logged_out` | (closure) | 10 | 2 | Fix REST cookie nonce for guest `createWcOrder` |
| `rest_authentication_errors` | (closure) | — | — | Allow unauthenticated `createWcOrder` requests |

---

## Actions Fired (`do_action`)

| Hook | Where | When |
|---|---|---|
| `setup_extra_setting_fields` | `init_form_fields()` | Building settings form — allows other plugins to add fields |
| `check_route_enable_status` | `razorpay-route.php` | On load — checks route setting and conditionally hooks admin UI |
| `woo_razorpay_refund_success` | `process_refund()` | After successful refund — passes `($refund_id, $order_id, $refund)` |

---

## WooCommerce Gateway Registration

The plugin registers itself with WooCommerce via the standard gateway filter (inside `woocommerce_razorpay_init()`):

```php
add_filter('woocommerce_payment_gateways', function($methods) {
    $methods[] = 'WC_Razorpay';
    return $methods;
});
```

(Note: This is done implicitly when WC loads all gateway classes.)

---

## REST API Endpoints Registered

All endpoints use base: `1cc/v1`

| Method | Route | Handler | Auth |
|---|---|---|---|
| POST | `/1cc/v1/coupon/list` | `getCouponList` | HMAC signature |
| POST | `/1cc/v1/coupon/apply` | `applyCouponOnCart` | `checkAuthCredentials` (always true) |
| POST | `/1cc/v1/order/create` | `createWcOrder` | `checkAuthCredentials` |
| POST | `/1cc/v1/shipping/shipping-info` | `calculateShipping1cc` | `checkAuthCredentials` |
| POST | `/1cc/v1/abandoned-cart` | `saveCartAbandonmentData` | `checkAuthCredentials` |
| POST | `/1cc/v1/cart/fetch-cart` | `fetchCartData` | `checkAuthCredentials` |
| POST | `/1cc/v1/cart/create-cart` | `createCartData` | `checkAuthCredentials` |
| POST | `/1cc/v1/giftcard/apply` | `validateGiftCardData` | `checkAuthCredentials` |
| POST | `/1cc/v1/cod/order/prepay` | `prepayCODOrder` | `checkAuthCredentials` |

**Note:** `checkAuthCredentials` currently returns `true` unconditionally. The `createWcOrder` endpoint additionally validates a WP nonce header (`X-WP-Nonce`). The `/coupon/list` endpoint uses HMAC signature for stronger security.

---

## Lifecycle Hooks

| WordPress Hook | Plugin Action |
|---|---|
| `register_activation_hook` | `razorpayPluginActivated()` — track event, init crons |
| `register_deactivation_hook` | `razorpayPluginDeactivated()` — track event, delete crons |
| `upgrader_process_complete` | `razorpayPluginUpgraded()` — track event, update version, conditionally init crons |
