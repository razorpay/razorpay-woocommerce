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

    if ($order && $order->get_item_count() > 0) {
        foreach ($order->get_items() as $item_id => $item) {
            $productId             = $item->get_product_id();
            $variationId           = $item->get_variation_id();
            $quantity              = $item->get_quantity();
            $customData["item_id"] = $item_id;

            WC()->cart->add_to_cart(
                $productId,
                $quantity,
                $variationId,
                [],
                $customData
            );
        }

        return true;
    } else {
        return false;
    }
}
