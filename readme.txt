=== Razorpay for WooCommerce ===
Contributors: razorpay
Tags: razorpay, payments, india, woocommerce, ecommerce
Requires at least: 3.9.2
Tested up to: 5.8.2
Stable tag: 2.8.5
Requires PHP: 5.6
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Allows you to use Razorpay payment gateway with the WooCommerce plugin.

== Description ==

This is the official Razorpay payment gateway plugin for WooCommerce. Allows you to accept credit cards, debit cards, netbanking, wallet, and UPI payments with the WooCommerce plugin. It uses a seamles integration, allowing the customer to pay on your website without being redirected away. This allows for refunds, works across all browsers, and is compatible with the latest WooCommerce.

This is compatible with WooCommerce>=2.4, including the new 3.0 release. It has been tested upto the 3.1.1 WooCommerce release.

== Installation ==

1. Install the plugin from the [Wordpress Plugin Directory](https://wordpress.org/plugins/woo-razorpay/).
2. To use this plugin correctly, you need to be able to make network requests. Please make sure that you have the php-curl extension installed.

== Dependencies ==

1. Wordpress v3.9.2 and later
2. Woocommerce v2.6 and later
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

== 2.8.5 ==
php sdk update to v2.8.1
* Tested up to Woocommerce 6.1.1

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

= Does this support webhooks? =

Yes, please see https://github.com/razorpay/razorpay-woocommerce/wiki/Webhooks for more details

= How do I enable Multi-currency support =

Please get multi-currency enabled for your account. Once you have it enabled, you can install any plugin
version higher than 2.0.0, which comes with native multi-currency support.

== Support ==

Visit [razorpay.com](https://razorpay.com/support/#request/merchant/technical-assistance) for support requests.

== License ==

The Razorpay WooCommerce plugin is released under the GPLv2 license, same as that
of WordPress. See the LICENSE file for the complete LICENSE text.
