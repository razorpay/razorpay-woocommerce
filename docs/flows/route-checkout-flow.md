# Route / Magic Checkout Flow - Razorpay WooCommerce

## Overview

This document covers two separate but related features:

1. **Razorpay Route**: Marketplace payment splitting/transfers to linked accounts
2. **Magic Checkout (1CC)**: One-click checkout from product/cart page via Razorpay's hosted experience

---

## Part 1: Razorpay Route

### What is Route?
Route enables marketplace-style payments where a single customer payment is split between the merchant and one or more linked (sub-merchant) accounts.

### Route Architecture

```mermaid
graph TD
    A[Admin enables route_enable=yes] --> B[Route Module Activated]
    B --> C[Product meta: transfer rules added]
    B --> D[Admin UI: Razorpay Route WooCommerce]

    E[Customer places order] --> F[getOrderCreationData]
    F --> G{route_enable=yes?}
    G -->|Yes| H[RZP_Route_Action::getOrderTransferData]
    H --> I[Fetch transfer rules from WC product meta]
    I --> J[Build transfers array]
    J --> K[Add to Razorpay order creation payload]

    K --> L[Payment collected by Razorpay]
    L --> M[updateOrder success]
    M --> N[RZP_Route_Action::transferFromPayment]
    N --> O[api->payment->transfer to linked accounts]
```

### Route - Order Creation with Transfers

```mermaid
sequenceDiagram
    participant RZP as WC_Razorpay
    participant RACT as RZP_Route_Action
    participant DB as WordPress DB
    participant RZPAPI as Razorpay API

    RZP->>RZP: getOrderCreationData($orderId)
    RZP->>RZP: Check getSetting('route_enable') == 'yes'
    RZP->>RACT: getOrderTransferData($orderId)
    RACT->>DB: Get product transfer meta for each order item
    DB-->>RACT: {linked_account, amount/percentage, on_hold, settlement_type}
    RACT-->>RZP: orderTransferArr

    RZP->>RZP: Merge transfers into order data
    Note over RZP,RZPAPI: Order payload now includes transfers array
    RZP->>RZPAPI: POST /v1/orders {amount, currency, ..., transfers: [{account, amount, on_hold}]}
    RZPAPI-->>RZP: {id: order_xxx, transfers: [...]}
```

### Route - Post-Payment Transfer

```mermaid
sequenceDiagram
    participant RZP as WC_Razorpay.updateOrder()
    participant RACT as RZP_Route_Action
    participant RZPAPI as Razorpay API

    RZP->>RZP: updateOrder($order, true, '', $paymentId)
    RZP->>RZP: Check getSetting('route_enable') == 'yes'
    RZP->>RACT: transferFromPayment($orderId, $razorpayPaymentId)
    RACT->>RZPAPI: payment->fetch($paymentId)->transfer([{account, amount}])
    RZPAPI-->>RACT: Transfer created
```

### Route Admin Operations

```mermaid
sequenceDiagram
    actor Admin
    participant WP as WordPress Admin
    participant RACT as RZP_Route_Action
    participant RZPAPI as Razorpay API

    Note over Admin,WP: Direct Transfer
    Admin->>WP: Enter amount + linked account, submit
    WP->>RACT: directTransfer() via rzp_direct_transfer action
    RACT->>RACT: authorizeAndAuthenticate(nonce, 'rzp_direct_transfer')
    RACT->>RZPAPI: api->transfer->create({account, amount_paise, currency: INR})
    RZPAPI-->>RACT: Transfer created
    RACT-->>Admin: Redirect to route page

    Note over Admin,WP: Reverse Transfer
    Admin->>WP: Enter transfer ID + amount, submit
    WP->>RACT: reverseTransfer() via rzp_reverse_transfer action
    RACT->>RZPAPI: api->transfer->fetch($transferId)->reverse({amount_paise})
    RZPAPI-->>RACT: Reversal created
    RACT-->>Admin: Redirect to transfers page

    Note over Admin,WP: Settlement Change
    Admin->>WP: Update on_hold status for transfer
    WP->>RACT: updateTransferSettlement()
    RACT->>RZPAPI: api->transfer->fetch($transferId)->edit({on_hold, on_hold_until})

    Note over Admin,WP: Payment Transfer
    Admin->>WP: Create transfer from existing payment
    WP->>RACT: createPaymentTransfer()
    RACT->>RZPAPI: payment->fetch($paymentId)->transfer([{account, amount}])
```

### Route Admin Pages

| Page | URL | Description |
|------|-----|-------------|
| Razorpay Route | `razorpayRouteWoocommerce` | Main transfers list |
| Transfers | `razorpayTransfers` | Transfer detail view |
| Reversals | `razorpayRouteReversals` | Reversal management |
| Payments | `razorpayRoutePayments` | Payment view |
| Settlement Transfers | `razorpaySettlementTransfers` | Settlement management |
| Payments View | `razorpayPaymentsView` | Payment listing |

---

## Part 2: Magic Checkout (1CC - One-Click Checkout)

### What is Magic Checkout?
Magic Checkout (internally called 1CC) is a Razorpay product that allows customers to complete purchases directly from the product page or cart without going through the full WooCommerce checkout process. Customer data (address, payment method) is pre-filled from their Razorpay profile.

### 1CC Architecture

```mermaid
graph TD
    A{is1ccEnabled?} -->|Yes| B[Show Magic Checkout Buttons]
    A -->|No| C[Standard WC Checkout Only]

    B --> D[Product Page Button: isPdpCheckoutEnabled]
    B --> E[Cart Page Button]
    B --> F[Mini Cart Button: isMiniCartCheckoutEnabled]

    G[Customer clicks 1CC button] --> H[btn-1cc-checkout.js]
    H --> I[POST /wp-json/1cc/v1/order/create]
    I --> J[WC Order created - checkout-draft]
    J --> K[Razorpay Order created with line_items]
    K --> L[Razorpay Magic Checkout UI]
    L --> M[Customer fills/confirms address]
    M --> N[Shipping options loaded]
    N --> O[Customer selects payment]
    O --> P[Payment processed]
    P --> Q[Callback to WC]
    Q --> R[Order synced from Razorpay]
```

### 1CC Order Creation - Detailed Flow

```mermaid
sequenceDiagram
    participant BTN as btn-1cc-checkout.js
    participant REST as WordPress REST API
    participant API as includes/api/order.php
    participant WC as WooCommerce
    participant RZP as WC_Razorpay
    participant RZPAPI as Razorpay API
    participant DB as WordPress DB

    BTN->>REST: POST /wp-json/1cc/v1/order/create
    Note over BTN,REST: Headers: X-WP-Nonce, Content-Type: application/json
    Note over BTN,REST: Body: {cookies: {wp_woocommerce_session_*}, token?, ...}

    REST->>API: checkAuthCredentials() -> true
    REST->>API: createWcOrder($request)

    API->>API: Extract session from cookies
    API->>WC: initCartCommon() - init session/customer/cart
    API->>WC: checkCartEmpty()
    API->>WC: WC()->cart->get_cart_hash()

    API->>WC: WC()->checkout()->create_order([])
    WC-->>API: $orderId

    API->>DB: updateOrderStatus($orderId, 'checkout-draft')
    API->>DB: update_meta 'is_magic_checkout_order' = 'yes'

    API->>WC: Remove existing coupons from order
    API->>WC: Remove default shipping methods
    API->>WC: order->calculate_totals()

    API->>RZP: createOrGetRazorpayOrderId($order, $orderId, '1cc')
    RZP->>RZP: getOrderCreationData($orderId)
    RZP->>RZP: orderArg1CC($data, $order)
    Note over RZP: Adds line_items_total + line_items[] with product details

    RZP->>RZPAPI: POST /v1/orders {line_items_total, line_items[], receipt, currency}
    RZPAPI-->>RZP: {id: order_xxx}
    RZP->>DB: update_meta 'razorpay_order_id_1cc{orderId}' = order_xxx

    API-->>REST: {status: true, orderId, razorpay_order_id, ...}
    REST-->>BTN: Response
```

### 1CC Line Items Structure

Each product in the cart is sent as a line item to Razorpay:

```php
$data['line_items'][$i] = [
    'type'        => 'e-commerce' | 'gift_card',
    'sku'         => $product->get_sku(),
    'variant_id'  => (string)$item->get_variation_id(),
    'product_id'  => (string)$product_id,
    'weight'      => round(wc_get_weight($product->get_weight(), 'g')),
    'price'       => round(wc_get_price_excluding_tax($product)*100) + tax_per_unit,
    'offer_price' => (int)$sale_price * 100,  // or regular price
    'quantity'    => (int)$item->get_quantity(),
    'name'        => mb_substr($item->get_name(), 0, 125),
    'description' => mb_substr($item->get_name(), 0, 250),
    'image_url'   => wp_get_attachment_url($productImage),
    'product_url' => $product->get_permalink(),
];
```

### 1CC Shipping Calculation Flow

```mermaid
sequenceDiagram
    participant RZPCHK as Razorpay Checkout
    participant REST as WordPress REST API
    participant SHIP as calculateShipping1cc()
    participant WC as WooCommerce

    RZPCHK->>REST: POST /wp-json/1cc/v1/shipping/shipping-info
    Note over RZPCHK,REST: Body: {order_id, addresses: [{id, country, state, pincode, ...}], razorpay_order_id}

    REST->>SHIP: calculateShipping1cc($request)
    SHIP->>SHIP: validateInput('shipping', $params)
    SHIP->>WC: initCustomerSessionAndCart()
    SHIP->>WC: WC()->cart->empty_cart()
    SHIP->>WC: create1ccCart($orderId) - rebuild cart from WC order items
    WC-->>SHIP: Cart created with order products

    loop For each address
        SHIP->>WC: shippingUpdateCustomerInformation1cc($address)
        Note over SHIP,WC: Sets customer shipping to provided address
        SHIP->>WC: shippingCalculatePackages1cc($addressId, $orderId, $address, $rzpOrderId)
        WC->>WC: Calculate available shipping methods for address
        WC-->>SHIP: [{id, name, price, description, cod_eligible}]
    end

    SHIP-->>REST: Shipping options array
    REST-->>RZPCHK: Available shipping methods with costs
```

### 1CC Order Completion - Address and Data Sync

```mermaid
sequenceDiagram
    participant RZP as WC_Razorpay
    participant RZPAPI as Razorpay API
    participant WC as WooCommerce
    participant DB as WordPress DB

    RZP->>RZP: update1ccOrderWC($order, $wcOrderId, $razorpayPaymentId)
    DB->>DB: set_transient('wc_order_under_process_'+orderId, true, 300)

    RZP->>RZPAPI: order->fetch($razorpayOrderId)
    RZPAPI-->>RZP: Full order object {shipping_fee, cod_fee, notes, offers, ...}

    RZP->>RZP: UpdateOrderAddress($razorpayData, $order)
    RZP->>WC: order->set_billing_first_name/last_name/email/phone/address...
    RZP->>WC: order->set_shipping_first_name/address...

    alt GSTIN in notes
        RZP->>WC: order->add_order_note("GSTIN No.: " + gstin)
    end

    alt order_instructions in notes
        RZP->>WC: order->add_order_note("Order Instructions: " + instructions)
    end

    alt shipping_fee set
        RZP->>WC: Remove existing shipping items
        alt WCFM Marketplace with store shipping
            RZP->>WC: Add per-vendor shipping items from 1cc_shippinginfo meta
        else Standard shipping
            RZP->>WC: Add WC_Order_Item_Shipping with shipping_fee/100
        end
        RZP->>WC: order->calculate_totals()
    end

    RZP->>RZPAPI: payment->fetch($razorpayPaymentId)
    RZPAPI-->>RZP: {method: 'card'|'cod'|'upi'|...}

    alt method = 'cod'
        RZP->>WC: order->set_payment_method('cod')
        RZP->>WC: Add COD fee item (cod_fee/100)
        RZP->>WC: order->calculate_totals()
    end

    RZP->>RZP: handlePromotions($razorpayData, $order, $wcOrderId, $razorpayPaymentId)
    Note over RZP: Handles coupons, gift cards, Terra wallet from RZP order

    alt Razorpay offers applied
        RZP->>RZP: Calculate offer discount amount
        RZP->>RZP: createRzpOfferCoupon(title, offerDiscount)
        RZP->>WC: applyCoupon($order, couponTitle, offerDiff)
    end
```

### 1CC REST API Authentication

```mermaid
graph TD
    A[1CC REST Request] --> B{Endpoint}
    B -->|/coupon/list| C[checkHmacSignature]
    B -->|All others| D[checkAuthCredentials]

    C --> E[Get X-Razorpay-Signature header]
    E --> F[Get rzp1cc_hmac_secret from DB]
    F --> G[api->utility->verifySignature body,sig,secret]
    G -->|Valid| H[Proceed]
    G -->|Invalid| I[403 Forbidden]

    D --> J[return true - credentials embedded via WP nonce]
    J --> H
```

### 1CC Configuration Options

| Setting Key | Purpose | Default |
|------------|---------|---------|
| `enable_1cc` | Master toggle for Magic Checkout | `no` |
| `enable_1cc_test_mode` | Only show to logged-in admins | `no` |
| `enable_1cc_debug_mode` | Enable debug logging | `yes` |
| `enable_1cc_pdp_checkout` | Show button on product pages | `no` |
| `enable_1cc_mini_cart_checkout` | Show button in mini cart | `no` |
| `enable_1cc_mandatory_login` | Require Razorpay login | `no` |
| `enable_1cc_ga_analytics` | Google Analytics integration | `no` |
| `enable_1cc_fb_analytics` | Facebook Pixel integration | `no` |
| `1cc_min_cart_amount` | Minimum cart total for 1CC | `0` |
| `1cc_min_COD_slab_amount` | Min order for COD | - |
| `1cc_max_COD_slab_amount` | Max order for COD | - |
| `1cc_account_creation` | Allow account creation | `no` |

### 1CC HMAC Secret Management

```mermaid
sequenceDiagram
    participant WP as WordPress
    participant RZP as WC_Razorpay
    participant RZPAPI as Razorpay API
    participant DB as WordPress DB

    WP->>WP: plugins_loaded (priority 20) -> rzpWcEnsure1ccSecret()
    WP->>WP: Check is1ccEnabled()
    WP->>DB: Get current key_id from settings
    WP->>DB: Get rzp_wc_last_key_id
    WP->>DB: Get rzp1cc_hmac_secret

    alt Key changed OR secret missing
        WP->>DB: set_transient(rzp_wc_ensure_1cc_secret_lock, 1, 30)
        WP->>RZP: registerRzp1ccSigningSecret($keyId, $keySec)
        RZP->>RZP: generateSecret() - 20-char alphanumeric
        RZP->>RZPAPI: POST /magic/merchant/auth/secret {key_id, platform:woocommerce, secret}
        RZPAPI-->>RZP: {success: true}
        RZP-->>WP: $newSecret
        WP->>DB: update_option('rzp1cc_hmac_secret', $newSecret)
        WP->>DB: update_option('rzp_wc_last_key_id', $currentKeyId)
        WP->>DB: delete_transient(rzp_wc_ensure_1cc_secret_lock)
    end
```
