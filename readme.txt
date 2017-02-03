=== Razorpay for WooCommerce ===
Contributors: razorpay
Tags: razorpay, payments, india, woocommerce, ecommerce
Requires at least: 3.9.2
Tested up to: 4.7
Stable tag: 1.3.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Allows you to use Razorpay payment gateway with the WooCommerce plugin.

== Description ==

This is the official Razorpay payment gateway plugin for WooCommerce. Allows you to accept credit cards, debit cards, netbanking with the WooCommerce plugin. It uses a seamles integration, allowing the customer to pay on your website without being redirected away from your woocommerce website.

This is compatible with both 2.4 and 2.5 series of WooCommerce.

== Installation ==

1. Install the plugin from the [Wordpress Plugin Directory](https://wordpress.org/plugins/woo-razorpay/).
2. To use this plugin correctly, you need to be able to make network requests. Please make sure that you have the php-curl extension installed.

== Dependencies == 

1. Wordpress v3.9.2 and later
2. Woocommerce v2.4 and later
3. PHP v5.6.0 and later
4. php-curl

== Configuration ==

1. Visit the WooCommerce settings page, and click on the Checkout/Payment Gateways tab.
2. Click on Razorpay to edit the settings. If you do not see Razorpay in the list at the top of the screen make sure you have activated the plugin in the WordPress Plugin Manager.
3. Enable the Payment Method, name it Credit Card / Debit Card / Internet Banking (this will show up on the payment page your customer sees), add in your Key id and Key Secret.
4. The Payment Action should be set to "Authorize and Capture". If you want to capture payments manually from the Dashboard after manual verification, set it to "Authorize".

== Support ==

Visit [razorpay.com](https://razorpay.com) for support requests or email us at <integrations@razorpay.com>.

== License ==

The Razorpay WooCommerce plugin is released under the GPLv2 license, same as that
of WordPress. See the LICENSE file for the complete LICENSE text.
