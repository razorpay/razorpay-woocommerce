# Skill: Add a New Payment Method Variant

## Purpose
Add support for a new Razorpay payment method or variant — such as a new EMI type, UPI Autopay, BNPL provider, or a new checkout flow option — within the `WC_Razorpay` plugin class.

## When to Use
- Razorpay launches a new payment method (e.g., Pay Later, new wallet)
- A merchant needs to enable/disable a specific payment method in checkout
- Adding configuration for UPI Autopay (recurring UPI mandates)
- Enabling a new EMI bank or tenure option
- Adding a new checkout preference flag exposed by Razorpay's `/v1/preferences` API

## Prerequisites
- Read `woo-razorpay.php` fully — specifically:
  - `init_form_fields()` (line ~491) — how settings are defined
  - `getDefaultCheckoutArguments()` (line ~1308) — how checkout params are built
  - `getCheckoutArguments()` (line ~1366) — where method-specific options are added
  - `generate_razorpay_form()` (line ~1281) — where JS is injected
  - `process_payment()` (line ~1905) — where payment is initiated
- Know the Razorpay checkout option key for the new method (from Razorpay docs)
- Know whether this requires a backend API change or is purely a frontend option

---

## Steps

### Step 1 — Understand the WC_Razorpay class structure

The `WC_Razorpay` class (in `woo-razorpay.php`) extends `WC_Payment_Gateway`. Key flow:

1. `init_form_fields()` — defines admin settings fields (shown in WC > Payments > Razorpay)
2. `getDefaultCheckoutArguments()` — builds the base Razorpay checkout options object
3. `getCheckoutArguments()` — adds order-specific and feature-specific options
4. `enqueueCheckoutScripts()` / `generateOrderForm()` — passes data to frontend JS
5. `process_payment()` — creates the WC order and redirects to receipt page

### Step 2 — Add an admin settings field

In `init_form_fields()` (line ~491), add a new field for the payment method toggle:

```php
// Example: Adding "Enable UPI Autopay" toggle
'enable_upi_autopay' => [
    'title'       => __('UPI Autopay', 'woocommerce'),
    'type'        => 'checkbox',
    'label'       => __('Enable UPI Autopay for recurring payments', 'woocommerce'),
    'description' => __('Allows customers to set up UPI AutoPay mandates for subscriptions.', 'woocommerce'),
    'default'     => 'no',
    'desc_tip'    => true,
],
```

The `type` can be `checkbox`, `select`, `text`, or `textarea`. For a multi-option field:

```php
'emi_display_label' => [
    'title'   => __('EMI Label', 'woocommerce'),
    'type'    => 'select',
    'options' => [
        'EMI'           => __('EMI', 'woocommerce'),
        'Easy Payment'  => __('Easy Payment', 'woocommerce'),
    ],
    'default' => 'EMI',
],
```

### Step 3 — Read the setting value

After adding the field, read it using `$this->getSetting('key')` or `$this->get_option('key')`:

```php
$enableUpiAutopay = $this->getSetting('enable_upi_autopay');
// Returns 'yes' or 'no' for checkboxes
```

`getSetting()` is defined in `woo-razorpay.php` (~line 316):
```php
public function getSetting($key)
{
    return $this->settings[$key] ?? null;
}
```

### Step 4 — Add the option to checkout arguments

In `getCheckoutArguments()` (line ~1366), add the new option to the checkout params array that gets passed to the Razorpay JS:

```php
protected function getCheckoutArguments($order, $params)
{
    // ... existing code ...

    // Add your new option:
    if ($this->getSetting('enable_upi_autopay') === 'yes')
    {
        $params['config']['display']['blocks']['utib'] = [
            'name'       => 'UPI Autopay',
            'instruments' => [['method' => 'upi', 'flows' => ['collect', 'qr', 'intent']]]
        ];
    }

    return $params;
}
```

For simple feature flags that go directly into the checkout options:

```php
if ($this->getSetting('enable_new_method') === 'yes')
{
    $params['method']['new_method'] = true;
}
```

### Step 5 — Modify the checkout JavaScript options (if needed)

The checkout data is passed via `enqueueCheckoutScripts()` (line ~1668) as a localized script variable. The data object is built in `getDefaultCheckoutArguments()` (line ~1308):

```php
public function getDefaultCheckoutArguments($order)
{
    return [
        'key'         => $this->getSetting('key_id'),
        'name'        => $this->getSetting('title'),
        'currency'    => $this->getOrderCurrency($order),
        'amount'      => $this->getOrderAmountAsInteger($order),
        // ... other options
    ];
}
```

If the new method needs a client-side option that can't be a PHP array (e.g., a callback), it needs to be set in `script.js` or `btn-1cc-checkout.js`.

### Step 6 — Handle in process_payment() if backend changes are needed

Most payment method variants are purely frontend (the Razorpay checkout modal handles them). However, if the new method requires different backend processing (e.g., a different API endpoint or special order creation params):

In `process_payment()` (line ~1905):

```php
public function process_payment($order_id)
{
    // ... existing code ...

    // Example: different handling for a new method
    $selectedMethod = WC()->session->get('chosen_payment_method_variant');
    if ($selectedMethod === 'new_method')
    {
        // Custom logic here
    }

    // ... rest of method
}
```

In `createRazorpayOrderId()` (line ~1406) or `getOrderCreationData()` (line ~1558), add any new params needed when creating the Razorpay order via API.

### Step 7 — Handle the new method in orderArg1CC() for Magic Checkout

If 1CC (Magic Checkout) is affected, update `orderArg1CC()` (line ~1610) to include the new option in 1CC order creation data.

### Step 8 — Update the settings description

In `admin_options()` (line ~1095) or the settings field description, document the new option so merchants understand what it does.

### Step 9 — Test the changes

1. Activate the setting in WC Admin > Payments > Razorpay
2. Go to checkout — the Razorpay modal should show the new method
3. Complete a test payment using Razorpay test credentials
4. Verify order is created correctly and the payment method is recorded

---

## Key Files

- `woo-razorpay.php`:
  - `init_form_fields()` (~line 491) — add settings field here
  - `getSetting()` (~line 316) — how to read settings
  - `getDefaultCheckoutArguments()` (~line 1308) — base checkout params
  - `getCheckoutArguments()` (~line 1366) — feature-specific params
  - `generateOrderForm()` (~line 1725) — JS injection
  - `process_payment()` (~line 1905) — backend payment initiation
  - `getOrderCreationData()` (~line 1558) — Razorpay order creation params
  - `orderArg1CC()` (~line 1610) — 1CC-specific params
- `script.js` — Frontend payment form handling
- `btn-1cc-checkout.js` — 1CC frontend logic (if Magic Checkout affected)

---

## Common Patterns

### Settings field for a checkbox feature
```php
'enable_feature_x' => [
    'title'   => __('Feature X', 'woocommerce'),
    'type'    => 'checkbox',
    'label'   => __('Enable Feature X', 'woocommerce'),
    'default' => 'no',
],
```

### Reading setting and applying to checkout params
```php
if ($this->getSetting('enable_feature_x') === 'yes') {
    $params['feature_x'] = true;
}
```

### Adding a checkout block method config
```php
$params['config']['display']['blocks'] = [
    'banks'  => ['name' => 'Pay via Net Banking', 'instruments' => [...]],
    'wallets' => ['name' => 'Pay via Wallets',    'instruments' => [...]],
];
```

### Amount is always integer paise
```php
'amount' => (int) round($order->get_total() * 100),
```

---

## Example Prompts

- "Use add-payment-method-variant skill to add a toggle for UPI Autopay in the plugin settings."
- "Add a new setting to show/hide the EMI payment method on checkout using add-payment-method-variant skill."
- "Add support for Razorpay Pay Later as a checkout option. Use the add-payment-method-variant skill."

---

## Output

After completing this skill, produce:
1. The new settings field added to `init_form_fields()`
2. The checkout argument change in `getCheckoutArguments()`
3. Any `process_payment()` changes if backend handling differs
4. A summary of how to test the new payment method in Razorpay test mode
5. Any Razorpay dashboard configuration needed (e.g., enabling the method on merchant account)
