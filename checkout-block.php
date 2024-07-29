<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Razorpay_Blocks extends AbstractPaymentMethodType 
{
    protected $name = 'razorpay';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_razorpay_settings', []);
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'razorpay-blocks-integration',
            plugin_dir_url(__FILE__) . 'checkout_block.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );

        if (function_exists('wp_set_script_translations')) 
        {
            wp_set_script_translations('razorpay-blocks-integration');
        }

        return ['razorpay-blocks-integration'];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => 'Pay by Razorpay',
            'description' => $this->settings['description'],
        ]; 
    }
}
