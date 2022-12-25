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

function fetchWcCart()
{
    include_once WC_ABSPATH . 'includes/wc-cart-functions.php'; // nosemgrep: file-inclusion

    if (null === WC()->session) {
        $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');
        WC()->session = new $session_class();
        WC()->session->init();
    }

    if (null === WC()->customer) {
        WC()->customer = new WC_Customer(get_current_user_id(), true);
    }

    $cart = WC()->cart;

    if (null === $cart) {
        $cart = new WC_Cart();
        WC()->cart = $cart;
    }

    return $cart;
}

function createWcCart(WP_REST_Request $request) {
    $params = $request->get_params();
    $productId = $params['product_id'];
    $quantity = $params['quantity'];

    $cart = fetchWcCart();
    $cart->empty_cart();
    $cart->add_to_cart($productId, $quantity);
    return $cart->get_item_data();
}
