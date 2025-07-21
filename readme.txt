=== Razorpay for WooCommerce ===
Contributors: razorpay
Tags: razorpay, payments, india, woocommerce, curlec, malaysia, ecommerce, international, cross border
Requires at least: 3.9.2
Tested up to: 6.8
Stable tag: 4.7.6
Requires PHP: 7.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Start accepting payments in minutes with 100% digital onboarding & feature filled Razorpay payment gateway with the WooCommerce plugin.

== Description ==

Allows you to accept credit cards, debit cards, netbanking, wallet, and UPI payments in India and FPX, eWallets, Duitnow in Malaysia.

This is the official Razorpay payment gateway plugin for WooCommerce. A system designed to handle end-to-end payments. Accept payments via 100+ payment modes - domestic & international credit & debit cards, EMIs, paylater, net banking, UPI & mobile wallets  including JioMoney, Mobikwik, Airtel Money, FreeCharge, Ola Money and PayZapp  in India, and FPX, Duitnow and eWallets including GrabPay, Touch N Go, Boost in Malaysia, with the WooCommerce plugin.
Get a feature-filled and easy to integrate checkout with cards (Visa, MasterCard, American Express, UnionPay etc) saved across businesses so that customers can pay seamlessly everywhere, both domestic and international. This plugin allows for refunds, works across all browsers, and is compatible with the latest WooCommerce. Boost conversions with international customers paying in their local currency. Keep your data safe with robust security that comes with PCI DSS Level 1 compliance.

This is compatible with WooCommerce>=4.0, including the new 9.0 release. It has been tested up to the 9.1.2 WooCommerce release.

BENEFITS OF USING RAZORPAY

- Get started in minutes with seamless onboarding - [Razorpay registration link](https://easy.razorpay.com/onboarding/l1/signup?field=MobileNumber) via 100% digital KYC for Indian businesses and [easy onboarding]( https://curlec.com/onboarding/my/) for Malaysia businesses

- Boost customer conversions with superior checkout experience on fast-growing UPI  in India and Duitnow in Malaysia

- Enjoy industry leading success rate & avoid drop offs with seamless payment flow

- Razorpay has no setup fees, no monthly fees, no hidden costs: you only get charged when you earn money! Earnings are transferred to your bank account as per settlement cycle.

- Razorpay supports the WooCommerce Subscriptions extension via [Razorpay Subscriptions Plugin for WooCommerce](https://razorpay.com/docs/payments/subscriptions/plugins/woocommerce/) and When a customer pays for a subscription item, you can accept recurring payments for the same on your WooCommerce-enabled WordPress site.

- Razorpay has [Affordability Widget](https://razorpay.com/docs/payments/payment-gateway/affordability/widget/woocommerce/) to spread awareness about the affordability-based payment options before they reach checkout.  You can integrate Razorpay Affordability Widget with your WooCommerce website to influence your customer's purchase decisions before they reach checkout by displaying various affordable payment options and offers.

COUNTRIES SUPPORTED
Razorpay is available for Store Owners and Merchants in
- India
- Malaysia

== Screenshots ==

1. Razorpay Payment Gateway for WooCommerce API keys.
2. Razorpay Payment Gateway for WooCommerce Plugin settings.
3. Razorpay Payment Gateway for WooCommerce Plugin Payments checkout.

== Installation ==

1. Install the plugin from the [Wordpress Plugin Directory](https://wordpress.org/plugins/woo-razorpay/).
2. To use this plugin correctly, you need to be able to make network requests. Please make sure that you have the php-curl extension installed.

== Dependencies ==

1. Wordpress v3.9.2 and later
2. Woocommerce v4.0 and later
3. PHP v5.6.0 and later
4. php-curl extension

== Configuration ==

1. Visit the WooCommerce settings page, and click on the Checkout/Payment Gateways tab.
2. Click on Razorpay to edit the settings. If you do not see Razorpay in the list at the top of the screen make sure you have activated the plugin in the WordPress Plugin Manager.
3. Enable the Payment Method, name it Credit Card / Debit Card / Internet Banking (this will show up on the payment page your customer sees), add in your Key id and Key Secret.
4. The Payment Action should be set to "Authorize and Capture". If you want to capture payments manually from the Dashboard after manual verification, set it to "Authorize".

== Upgrade Notice ==
= 2.0.0 =
* Switches from WooCommerce side currency conversion to Razorpay's native multi currency support.

== Changelog ==

= 4.7.6 =
* Fixed order number issue.

= 4.7.5 =
* Added instrumentation for alerts.
* Fixed certificate issue in cron.
* Fixed HPOS and signature verification issue in 1cc.

= 4.7.4 =
* Added cron instrumentation.
* Changed order status in webhook table on callback failure.

= 4.7.3 =
* Enhanced webhook cron.

= 4.7.2 =
* Added cron for webhook.

= 4.7.1 =
* Updated SDK update to 2.9.0.
* Fixed auto update webhook.

= 4.7.0 =
* Fixed validation for instrumentation.
* Added section restriction to API call.

= 4.6.9 =
* Added checkout.js script to checkout page.

= 4.6.8 =
* Fixed default currency bug.
* Sent config integration value in magic inline script.
* Bug fix for offer price data in magic checkout cart API.

= 4.6.7 =
* Fixed, Reduced 1cc preferences API features check.

= 4.6.6 =
* Added, UT fixes.
* Fixed, Reduced 1cc preferences API calls.
* ( Bug present, for 1cc settings, please use 4.6.7)

= 4.6.5 =
* Added, UT to SVN ignore list.

= 4.6.4 =
* Reverted, Magic checkout banner on woocommerce dashboard.

= 4.6.3 =
* Added, Magic checkout banner on woocommerce dashboard.
* Reverted, Instand Refund.

= 4.6.2 =
* Fixed, Subscription events webhook bug.
* Fixed, console errors due to instrumentation.
* Added Shipping Method Name for Shipping Engine support.

= 4.6.1 =
* Added support for Checkout Blocks on Woocommerce.
* Added instant refund with fallback.
* Removed RTB widget ineligibility message.
* Fixed RTB widget eligibility check.

= 4.6.0 =
* Support for Tera Wallet.
* Added RTB widget.

= 4.5.9 =
* Plugin name and description updated.

= 4.5.8 =
* Bug fix, plugin not activating in php 7.0.
* Add option fix for 1cc address pull cron job script.
* Webhook conflict error message as info

= 4.5.7 =
* Added nonce and user capability check for route
* Blocked currencies KWD, OMR, BHD.

= 4.5.6 =
* Added productId for advance cod support
* Updated Razorpay SDK to 2.8.7

= 4.5.5 =
* wordpress security issue fix
* Bug fix, order status issue

= 4.5.4 =
* Add compatibility with WooCommerce 7.9 HPOS
* Bug fix, city restriction for smart cod
Note: Razorpay woocommerce plugin v4.5.4  only supports Razorpay Subcriptions for woocommerce >= 2.3.6

= 4.5.3 =
* New feature to send payment-links for COD orders with added discounts
* Added Razorpay prepaid offers
* Razorpay payment icon change

= 4.5.2 =
* Bug fix, One cc cron db update fix for php v8.2
* Bug fix, remove duplicate order creation
* Updated Razorpay SDK to 2.8.5

= 4.5.1 =
* Bug fix, typed params, 1cc_enabled flag check

= 4.5.0 =
* Bug fix, missing condition for data of array type.
* Bug fix, for optional params.

= 4.4.3 =
* Bug fix, missing price level in lineitems.
* Bug fix, same orderid for different customer.
* Bug fix, new version of smart coupon plugin.
* Fixed magic latency issue.

= 4.4.2 =
* Bug fix, parameter missing in shipping call.

= 4.4.1 =
* Bug fix, razorpay cart response function.

= 4.4.0 =
* Bug fix, razorpay routes calling private function.
* Changed, default mode set to live for affordability widget.
* Disable coupon feature for dynamic price plugin.
* Fixed product category smart cod issue.
* Conditional and shipping plugin coupon restriction.
* Support for yith abandoned recovery plugin.
* Support gift card for pw and yith gift card.
* Support for Caddy and Xootix sidecarts.

= 4.3.5 =
* Fixed, multiple webhook API calls.
* Added, subscription.charged webhook event.
* Updated, plugin activation and deactivation hooks.
* Electro mobile button support added.
* Minicart and spinner issue fix.
* Abandoned cart hooks support
* GSTIN and Order Instructions support
* Build support for yith abandoned recovery plugin
* Tested up to Wordpress 6.1.1

= 4.3.4 =
* Fixed, Api calls for affordability widget being made from product page.
* Removed, checkbox to enable affordability widget.

= 4.3.3 =
* Added, checkbox to enable affordability widget.
* Fixed, Api call being made from all pages for features.

= 4.3.2 =
* Added, support for variable products.
* Removed, checkbox to enable affordability widget.
* Added, Divi theme support.
* Added, WATI plugin support.
* Added, Flycart changes.

= 4.3.1 =
* Fixed, automatic injection of affordability widget code.
* Added, checkbox to enable affordability widget.

= 4.3.0 =
* Added affordability widget.
* Bug fix, Order properties cannot be accessed directly. 
* Added datalake changes.
* Fixed recursive redirect issue.
* Flycart plugin support
* Mandatory fields check bug fix.
* Auto cart-abandonment with timeout feature.
* Multi-retargeting parallel support.

= 4.2.0 =
* Bug fix for cart bounty plugin support issue in magic checkout.
* Added mandatory store account creation feature for magic checkout.
* Bug fix for sticky add to cart plugin support.
* Magic checkout new script endpoint.
* Added instrumentation
* Added auto webhook log
* Added validation for key and secret

= 4.1.0 =
* Bug fix for jquery undefined issue.
* Bug fix for duplicate wooc orderId and Razorpay ID form same carthash.
* Bug fix for nonce issue

= 4.0.1 =
* Bug fix for uft8 characters.

= 4.0.0 =
* Added support for CartBounty plugin in magic checkout.
* Added debug log config on native checkout flow.

= 3.9.4 =
* Added delay of 5 minutes to webhook process.

= 3.9.3 =
* Bug fix multiple shipping charges issue for magic checkout.

= 3.9.2 =
* Bug fix cart line item char limit issue for magic checkout.
* Bug fix callback issue in order placed through admin panel.

= 3.9.1 =
* Bug fix cart line item int issue for magic checkout.

= 3.9.0 =
* Added Cart line item for magic checkout.
* Bug fix in COD min/max amount restriction for magic checkout.
* Reduced the auto enable webhook verification time to 12 hours.

= 3.8.3 =
* Bug fix for UTM data for pixel your site plugin for magic checkout.

= 3.8.2 =
* Bug fix for blog name in magic checkout.

= 3.8.1 =
* Bug fix webhook
* Added meta info to magic checkout.

= 3.8.0 =
* Added support for Pixel your site pro plugin in magic checkout.
* Bug fix to handle the transient data.

= 3.7.2 =
* Bug fix for webhook.

= 3.7.1 =
* Bug fix for auto webhook.
* Added supported subscription webhook events

= 3.7.0 =
* Magic Checkout support for Klaviyo plugin.
* Bug fix for warning message on place order and callback script.
* Bug fix in smart cod support in magic checkout.

= 3.6.0 =
* New webhook event i.e payment.pending has been added to handle the magic checkout COD orders

= 3.5.1 =
* Bug fix for magic checkout blank order issue.

= 3.5.0 =
* Feature Auto Enable webhook.
* Bug fix for magic checkout mini cart refresh.
* Bug fix for smart coupon auto apply coupon in magic checkout.
* Tested up to Woocommerce 6.4.1

= 3.4.1 =
* Bug fix in webhook.
* Updated Razorpay SDK.
* Tested up to Woocommerce 6.4.1

= 3.4.0 =
* Bug fix for magic checkout blank orders.

= 3.3.0 =
* Magic checkout COD intelligence config moved from wooc dashboard to razorpay dashboard.

= 3.2.2 =
* Bug fix in admin config page for magic checkout

= 3.2.1 =
* Fix the latency issue.
* Bug fix in coupon fetch API.

= 3.2.0 =
* Version bump to 3.2.0
* Tested up to Woocommerce 6.2.2

= 3.1.1 =
* Updated the preferences API call to 1cc_preferences call.

= 3.1.0 =
* Support for Smart COD plugin in magic checkout.
* Payment title update for magic checkout COD orders.
* Bug fix for theme specific qty issue on magic checkout buy now checkout option.

= 3.0.1 =
* Bug fix for Stock Management issue on magic checkout buy now option.
* Bug fix for Cart page checkout style issue on magic checkout.
* Optimize latency issue.
* Bug fix for PG title missing on invoice for magic checkout.

= 3.0.0 =
* Added Magic Checkout feature.

= 2.8.6 =
* Bug fix for not reflecting an updated order success message.
* Tested upto Wordpress 5.9

= 2.8.5 =
* Bug fix for session storage.

= 2.8.4 =
* Bug fix for guest checkout thank you message in order summary page.

= 2.8.3 =
* Updated Route module settings and added a note for creating reverse transfer.
* Tested up to Woocommerce 5.9.0

= 2.8.2 =
* Updated Razorpay SDK.
* Added subscription webhook events.
* Tested up to Woocommerce 5.9.0

= 2.8.1 =
* Bug fix in custom woocommerce order number issue.
* Tested up to Woocommerce 5.9.0

= 2.8.0 =
* Added Route module to split payments and transfer funds to Linked accounts.
* Tested up to Woocommerce 5.8.0

= 2.7.3 =
* Bug fix in callback handler.
* Tested up to Woocommerce 5.8.0

= 2.7.2 =
* Bug fix in webhook.
* Tested up to Woocommerce 5.5.1

= 2.7.1 =
* Updated the Razorpay Order notes key from woocommerce_order_id to woocommerce_order_number.

= 2.7.0 =
* Added auto-webhook setup feature.
* Updates Razorpay SDK.
* Tested upto WordPress 5.7.2 and WooCommerce 5.3.0

= 2.6.2 =
* Updated wc order syntax.
* Tested upto WordPress 5.7.1 and WooCommerce 5.2.2

= 2.6.1 =
* Added RAZORPAY ORDER ID in checkout argument.
* Tested upto WordPress 5.6.2 and WooCommerce 5.0.0

= 2.6.0 =
* Added webhook for virtual account credited event.
* Tested upto WordPress 5.6 and WooCommerce 4.6.1

= 2.5.0 =
* Added support for "Pay by Cred".
* Tested upto WordPress 5.4.2 and WooCommerce 4.3.0

= 2.4.3 =
* Updated logo from CDN.
* Tested upto WordPress 5.4.2 and WooCommerce 4.3.0

= 2.4.2 =
* Bug fix for partial refund shown twice.
* Bug fix for wc-api redirection after payment completed
* Tested upto WordPress 5.4.1 and WooCommerce 4.1.1

= 2.4.1 =
* Updated WordPress support version info

= 2.4.0 =
* Added webhook for handling refund create and change order status
* Bug fix for cart is reset if payment fails or is cancelled
* Tested upto WordPress 5.2.4 and WooCommerce 3.7.1

= 2.3.2 =
* Added RAZORPAY ORDER ID in order notes.
* Tested upto WordPress 5.2.4 and WooCommerce 3.7.1

= 2.3.1 =
* Bug fix for hosted checkout.
* Tested upto WordPress 5.2.4 and WooCommerce 3.7.1


= 2.3.0 =
* Support for hosted checkout.
* Tested upto WordPress 5.2.4 and WooCommerce 3.7.1


= 2.2.0 =
* Adds webhook for handling subscription cancellation.
* Tested upto WordPress 5.2.2 and WooCommerce 3.7.0

= 2.1.0 =
* Fixed bug for razorpay orderID validation.
* Adds support for razorpay Analytics
* Tested upto WordPress 5.2.2 and WooCommerce 3.6.5

= 2.0.0 =
* Removes support for WooCommerce Currency Convertor
* Switches to Razorpay's Native Multi-Currency support
* Adds support for [Price Based on Country Plugin](https://www.pricebasedcountry.com/)
* Tested upto WordPress 5.2-RC1 and WooCommerce 3.6.2
* Release uploaded as 2.0.1 on the Wordpress Plugin Directory.

= 1.6.3 =
* Allows for null values in displayAmount
* Better support for international currency conversion
* Support for custom order success message

= 1.6.2 =
* Fixes webhook capture flow by re-fetching payment and checking for status

= 1.6.1 =
* Fixes payment title/description in WC Checkout page.
* Adds WooCommerce version tested in the plugin metadata

= 1.6.0 =
* Adds Razorpay Subscriptions plugin support.
* Code cleanup.

= 1.5.3 =
* Webhooks are now disabled by default ([#52](https://github.com/razorpay/razorpay-woocommerce/pull/52))

= 1.5.2 =
* Fixed an issue with some websites "Pay now" button click not working. ([#50](https://github.com/razorpay/razorpay-woocommerce/pull/50))

= 1.5.1 =
* Fixes backward compatibilty with older WooCommerce releases. ([#49](https://github.com/razorpay/razorpay-woocommerce/pull/49))

= 1.5.0 =
* Javascript fixes for additional compatibility with other plugins ([#47](https://github.com/razorpay/razorpay-woocommerce/pull/47))
* Adds multi-currency support using [WooCommerce Currency Switcher](https://wordpress.org/plugins/woocommerce-currency-switcher/) plugin. ([#46](https://github.com/razorpay/razorpay-woocommerce/pull/46))

= 1.4.6 =
* Webhooks signature verification fix

= 1.4.4 =
* Added webhooks to the plugin (includes/razorpay-webhook.php) ([#18](https://github.com/razorpay/razorpay-woocommerce/pull/18))

= 1.4.2 =
* Added missing classes in the WordPress release (Utility.php was missing)

= 1.4.0 =
* Added Support for WooCommerce 3.x ([#35](https://github.com/razorpay/razorpay-woocommerce/pull/35]))
* Fixes around discount coupon handling (Order Amount mismatch)
* Updates Razorpay SDK
* Improves Javascript Caching ([#39](https://github.com/razorpay/razorpay-woocommerce/pull/39]))
* Adds support for mobile browsers ([#37](https://github.com/razorpay/razorpay-woocommerce/pull/37]):)
    * Chrome on iOS
    * Facebook Browser
    * Internet Explorer Mobile
    * AOSP Browser
    * Opera Mini
    * Google Search App
    * Any other apps using webviews
* Adds support for refunding payments from within WooCommerce

= 1.3.2 =
* Fixes a Notice about WC_Shortcode_Checkout->output being deprecated
* PR: [#28](https://github.com/razorpay/razorpay-woocommerce/pull/28])

= 1.3.1 =
* Improves Session management
* Diff: https://git.io/vHVBM

= 1.3.0 =
* Shifts to the official [Razorpay SDK](https://github.com/razorpay/razorpay-php)
* Shifts to the Razorpay Orders API. Allows for auto-capturing and improves success rates
* Wordpress Versions >=3.9.2 only are supported

= 1.2.11 =
* Fixes issues with Safari and Internet Explorer

= 1.2.10 =
* Improves error handling in case customer clicks on cancel.
* Orders are now marked as failed if customer clicks cancel
* Note is added to the order mentioning that the customer cancelled the order.

= 1.2.9 =
* Fixed error handling and capture call

= 1.2.8 =
* Disables buttons while payment is in progress
* Refactors error message display

= 1.2.7 =
* Redirects customer to order details page, as per WooCommerce guidelines.

= 1.2.6 =
* Adds manual capture option

== Frequently Asked Questions ==

= Where can I find Razorpay documentation for Razorpay WooCommerce plugin? =

For help setting up and configuring, please refer to our [documentation](https://razorpay.com/docs/payments/payment-gateway/ecommerce-plugins/woocommerce/).

= Does Razorpay for WooCommerce support webhooks? =

Yes, please see https://github.com/razorpay/razorpay-woocommerce/wiki/Webhooks for more details

= Does Razorpay for WooCommerce support both production mode and test / sandbox mode for testing? =

Yes, it does - production and Test (sandbox) mode is driven by the API keys you use with a checkbox in the admin settings to toggle between both.

= How do I enable Multi-currency support or Accept payments in international currencies =

You can accept [International payments](https://razorpay.com/docs/payments/payments/international-payments/) from your customers in more than 100 foreign currencies using our Payment Gateway. Please get multi-currency enabled for your account. Once you have it enabled, you can install any plugin
version higher than 2.0.0, which comes with native multi-currency support.

= How can I exclude Draft orders from WooCommerce analytics reports? =

Please follow the below steps:
1. Click on the ‘Analytics’ settings in the WooCommerce dashboard menu.
2. Go to the ‘Excluded statuses’ section and select the checkbox for ‘Draft’ orders under the ‘Unregistered statuses’ section.

= Does this support recurring payments / subscriptions? =

Yes. WooCommerce Subscriptions extension and [Razorpay Subscriptions Plugin for WooCommerce](https://razorpay.com/docs/payments/subscriptions/plugins/woocommerce/) installed along with Razorpay WooCommerce plugin will support subscription on your WooCommerce supported Wordpress website

== Support ==

Visit [razorpay.com](https://razorpay.com/support/#request/merchant/technical-assistance) for support requests.

== License ==

The Razorpay WooCommerce plugin is released under the GPLv2 license, same as that
of WordPress. See the LICENSE file for the complete LICENSE text.
