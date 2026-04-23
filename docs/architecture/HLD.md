# High Level Design (HLD) - Razorpay WooCommerce Plugin

## 1. System Architecture Overview

```mermaid
graph TD
    subgraph "Customer Browser"
        CB[Customer Browser]
        RCJ[Razorpay checkout.js CDN]
    end

    subgraph "WordPress / WooCommerce"
        WP[WordPress Core]
        WC[WooCommerce]
        RZP_PLUGIN[WC_Razorpay Plugin]
        subgraph "Plugin Components"
            MAIN[woo-razorpay.php<br/>WC_Razorpay Class]
            WEBHOOK[razorpay-webhook.php<br/>RZP_Webhook Class]
            ROUTE[razorpay-route-actions.php<br/>RZP_Route_Action Class]
            API_1CC[includes/api/<br/>1CC REST Endpoints]
            CRON[WP Cron<br/>Webhook Processor]
        end
        DB[(WordPress DB<br/>wp_rzp_webhook_requests<br/>wp_options<br/>wp_postmeta/wc_orders_meta)]
    end

    subgraph "Razorpay Platform"
        RZP_API[Razorpay API<br/>api.razorpay.com]
        RZP_DASH[Razorpay Dashboard]
        RZP_CHK[Razorpay Checkout<br/>checkout.razorpay.com]
    end

    CB -->|1. Place order| WC
    WC -->|2. process_payment| MAIN
    MAIN -->|3. Create Razorpay order| RZP_API
    MAIN -->|4. Return checkout form| CB
    CB -->|5. Load checkout.js| RCJ
    RCJ -->|6. Opens payment modal| RZP_CHK
    CB -->|7. Payment callback POST| MAIN
    MAIN -->|8. Verify signature| RZP_API
    MAIN -->|9. Update order| WC
    MAIN -->|10. Save webhook record| DB
    RZP_API -->|11. Webhook event| WEBHOOK
    WEBHOOK -->|12. Verify + Store| DB
    CRON -->|13. Process queued events| WEBHOOK
    WEBHOOK -->|14. Capture payment| RZP_API
    WEBHOOK -->|15. Update WC order| WC
    RZP_DASH -->|Admin actions| ROUTE
    ROUTE -->|Transfer operations| RZP_API
```

---

## 2. Component Interaction Diagram

```mermaid
graph LR
    subgraph "Entry Points"
        EP1[WooCommerce Checkout]
        EP2[1CC Button Click]
        EP3[Razorpay Webhook POST]
        EP4[WC Admin Refund]
        EP5[WP REST API]
    end

    subgraph "Plugin Classes"
        WCRZP[WC_Razorpay]
        WBHK[RZP_Webhook]
        RACT[RZP_Route_Action]
        TINST[TrackPluginInstrumentation]
    end

    subgraph "External APIs"
        RZPAPI[Razorpay PHP SDK]
        WCAPI[WooCommerce API]
        WPAPI[WordPress API]
    end

    EP1 --> WCRZP
    EP2 --> EP5
    EP5 --> WCRZP
    EP3 --> WBHK
    EP4 --> WCRZP

    WCRZP --> RZPAPI
    WCRZP --> WCAPI
    WCRZP --> TINST
    WBHK --> RZPAPI
    WBHK --> WCAPI
    WBHK --> WCRZP
    RACT --> RZPAPI
    RACT --> WCAPI
    TINST --> RZPAPI
```

---

## 3. Payment Flow HLD

```mermaid
graph TD
    A([Customer at WC Checkout]) --> B{Payment Method?}
    B -->|Razorpay| C[process_payment called]
    B -->|Other| Z([Other Gateway])

    C --> D[Create/Fetch Razorpay Order]
    D --> E{Order Exists & Valid?}
    E -->|No| F[POST api.razorpay.com/v1/orders]
    E -->|Yes| G[Use Existing Order ID]
    F --> G

    G --> H[Render Checkout Form]
    H --> I{Merchant Preference?}
    I -->|redirect=true| J[Hosted Checkout Page]
    I -->|redirect=false| K[Inline Modal via checkout.js]

    J --> L([Customer Pays])
    K --> L

    L --> M{Payment Result?}
    M -->|Success| N[POST callback to woocommerce_api_razorpay]
    M -->|Failure/Cancel| O[POST with error to callback]

    N --> P[verifySignature HMAC-SHA256]
    P -->|Valid| Q[updateOrder - payment_complete]
    P -->|Invalid| R[Mark Order Failed]

    O --> R
    Q --> S([Thank You Page])
    R --> T([Return to Checkout])

    Q --> U[Razorpay Webhook payment.authorized]
    U --> V[Async: Save to DB]
    V --> W[WP Cron: paymentAuthorized]
    W --> X{Payment Status?}
    X -->|authorized + capture mode| Y[api->payment->capture]
    X -->|already captured| Z2[Skip]
    Y --> Z2
    Z2 --> AA[updateOrder via webhook]
```

---

## 4. Webhook Flow HLD

```mermaid
graph TD
    RZP([Razorpay Platform]) -->|POST admin-post.php?action=rzp_wc_webhook| WH[razorpay_webhook_init]

    WH --> A[new RZP_Webhook]
    A --> B[Read php://input]
    B --> C{Valid JSON?}
    C -->|No| EXIT1([Return])
    C -->|Yes| D[shouldConsumeWebhook check]

    D -->|Not valid event/payload| EXIT1
    D -->|Valid| E[Get X-Razorpay-Signature header]

    E --> F{Secret configured?}
    F -->|No| EXIT1
    F -->|Yes| G[verifyWebhookSignature HMAC-SHA256]

    G -->|Invalid| H[Log error + return]
    G -->|Valid| I{Event type?}

    I -->|payment.authorized| J[saveWebhookEvent to DB]
    I -->|virtual_account.credited| K[virtualAccountCredited - sync capture]
    I -->|refund.created| L[refundedCreated - wc_create_refund]
    I -->|payment.pending + COD| M[paymentPending - mark paid]
    I -->|payment.failed| N([No-op return])
    I -->|subscription.*| O([No-op return])

    J --> P[WP Cron - later processing]
    P --> Q[paymentAuthorized]
    Q --> R{Payment captured?}
    R -->|No + capture mode| S[api->payment->capture]
    R -->|Yes| T[updateOrder WC]
    S --> T

    K --> T
    L --> U[wc_create_refund]
    M --> T
```

---

## 5. Subscription Flow HLD

```mermaid
graph TD
    A([Subscription Plugin Creates RZP Subscription]) --> B[Razorpay charges subscription]
    B --> C[subscription.charged webhook]
    C --> D[RZP_Webhook::process]
    D --> E{Signature valid?}
    E -->|No| EXIT([Return])
    E -->|Yes| F[subscriptionCharged - no-op in base class]

    B2[Subscription status change] --> G{Event type?}
    G -->|subscription.cancelled| H[subscriptionCancelled - no-op]
    G -->|subscription.paused| I[subscriptionPaused - no-op]
    G -->|subscription.resumed| J[subscriptionResumed - no-op]

    NOTE[Note: Full subscription logic<br/>handled in companion plugin.<br/>Base plugin only registers<br/>subscription webhook events<br/>when rzp_subscription_webhook_enable_flag set]
```

---

## 6. Route Transfer HLD

```mermaid
graph TD
    A([Admin enables route_enable=yes]) --> B[addRouteModuleSettingFields]
    B --> C[Admin UI: Razorpay Route Woocommerce menu]

    D([Customer places order]) --> E[getOrderCreationData]
    E --> F{route_enable=yes?}
    F -->|Yes| G[RZP_Route_Action::getOrderTransferData]
    G --> H[Fetch product transfer rules from meta]
    H --> I[Add transfers array to order creation payload]
    I --> J[Razorpay Order created with transfers]

    J --> K([Customer pays])
    K --> L[updateOrder success]
    L --> M[RZP_Route_Action::transferFromPayment]
    M --> N[api->payment->transfer to linked accounts]

    O([Admin: Direct Transfer]) --> P[rzp_direct_transfer action]
    P --> Q[api->transfer->create]

    R([Admin: Reverse Transfer]) --> S[rzp_reverse_transfer action]
    S --> T[api->transfer->fetch->reverse]

    U([Admin: Settlement Change]) --> V[rzp_settlement_change action]
    W([Admin: Payment Transfer]) --> X[rzp_payment_transfer action]
```

---

## 7. Magic Checkout (1CC) Flow HLD

```mermaid
graph TD
    A([Customer on Product/Cart Page]) --> B{is1ccEnabled?}
    B -->|No| Z([Standard Checkout])
    B -->|Yes| C[Show Magic Checkout Button]

    C --> D[Customer clicks 1CC button]
    D --> E[btn-1cc-checkout.js fires]
    E --> F[POST /wp-json/1cc/v1/order/create]

    F --> G[checkAuthCredentials]
    G --> H[createWcOrder handler]
    H --> I[WC()->checkout()->create_order]
    I --> J[Set status: checkout-draft]
    J --> K[Set is_magic_checkout_order=yes]
    K --> L[Remove default shipping]
    L --> M[Return orderId + rzpOrderId]

    M --> N[Razorpay fetches shipping options]
    N --> O[POST /wp-json/1cc/v1/shipping/shipping-info]
    O --> P[calculateShipping1cc]
    P --> Q[Build cart from WC order]
    Q --> R[Calculate WC shipping rates]
    R --> S[Return shipping options + costs]

    S --> T[Customer fills address + pays]
    T --> U[Razorpay processes payment]
    U --> V[Payment callback to woocommerce_api_razorpay]

    V --> W[check_razorpay_response]
    W --> X[verifySignature]
    X --> Y{is_magic_checkout_order?}
    Y -->|Yes| Z2[update1ccOrderWC]
    Y -->|No| Z3[Standard updateOrder]

    Z2 --> AA[UpdateOrderAddress from RZP order]
    AA --> AB[Apply shipping fees]
    AB --> AC[Handle COD fee if applicable]
    AC --> AD[handlePromotions - coupons, giftcards]
    AD --> AE[payment_complete]
    AE --> AF([Thank You Page])
    Z3 --> AF
```
