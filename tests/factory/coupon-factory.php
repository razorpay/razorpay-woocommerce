<?php

class CouponFactory
{
    public function createFixedCartCoupon()
    {
        return self::createCoupon('fixed_cart');
    }

    public function createPercentageCoupon()
    {
        return self::createCoupon('percent');
    }

    public function createFixedProductCoupon()
    {
        return self::createCoupon('fixed_product');
    }

    protected function createCoupon($couponType)
    {
        $coupon_id = wp_insert_post(
            array(
                'post_title'   => $couponType,
                'post_type'    => 'shop_coupon',
                'post_status'  => 'publish',
                'post_excerpt' => 'This is a dummy coupon',
            )
        );

        update_post_meta( $coupon_id, 'discount_type', $couponType );

        update_post_meta( $coupon_id, 'coupon_amount', '1' );

        update_post_meta( $coupon_id, 'individual_use', 'no' );

        update_post_meta( $coupon_id, 'product_ids', '' );

        update_post_meta( $coupon_id, 'exclude_product_ids', '' );

        update_post_meta( $coupon_id, 'usage_limit', '' );

        update_post_meta( $coupon_id, 'usage_limit_per_user', '' );

        update_post_meta( $coupon_id, 'limit_usage_to_x_items', '' );

        update_post_meta( $coupon_id, 'expiry_date', '' );

        update_post_meta( $coupon_id, 'free_shipping', 'no' );

        update_post_meta( $coupon_id, 'exclude_sale_items', 'no' );

        update_post_meta( $coupon_id, 'product_categories', array() );

        update_post_meta( $coupon_id, 'exclude_product_categories', array() );

        update_post_meta( $coupon_id, 'minimum_amount', '' );


        update_post_meta( $coupon_id, 'maximum_amount', '' );
        update_post_meta( $coupon_id, 'customer_email', array() );

        update_post_meta( $coupon_id, 'usage_count', '0' );

        return new WC_Coupon( $couponType );
    }
}
