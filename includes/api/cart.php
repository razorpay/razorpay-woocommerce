<?php
/**
 * For cart related functionality
 */

/**
 * Create the cart object for the line items exist in order
 */
function create1ccCart($orderId)
{
    global $woocommerce;

    $order = wc_get_order($orderId);

    $variationAttributes = [];
    if ($order && $order->get_item_count() > 0) {
        foreach ($order->get_items() as $item_id => $item) {
            $productId   = $item->get_product_id();
            $variationId = $item->get_variation_id();
            $quantity    = $item->get_quantity();

            $customData['item_id'] = $item_id;
            $product               = $item->get_product();
            if ($product->is_type('variation')) {
                $variation_attributes = $product->get_variation_attributes();
                foreach ($variation_attributes as $attribute_taxonomy => $term_slug) {
                    $taxonomy                                 = str_replace('attribute_', '', $attribute_taxonomy);
                    $value                                    = wc_get_order_item_meta($item_id, $taxonomy, true);
                    $variationAttributes[$attribute_taxonomy] = $value;
                }
            }

            $woocommerce->cart->add_to_cart($productId, $quantity, $variationId, $variationAttributes, $customData);

        }

        return true;
    } else {
        return false;
    }
}
