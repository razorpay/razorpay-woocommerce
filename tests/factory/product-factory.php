<?php

class ProductFactory
{
    public function createSimpleProduct()
    {
        $product = wp_insert_post(
            array(
            'post_title'  => 'Simple Product',
            'post_type'   => 'product',
            'post_status' => 'publish',
            )
        );

        update_post_meta( $product, '_price', '10' );

        update_post_meta( $product, '_currency', 'INR' );

        update_post_meta( $product, '_regular_price', '10' );

        update_post_meta( $product, '_downloadable', 'no' );

        update_post_meta( $product, '_virtual', 'no' );

        wp_set_object_terms( $product, 'simple', 'product_type' );

        return new WC_Product_Simple( $product );
    }

    public function createExternalProduct()
    {
        $product = wp_insert_post(
            array(
                'post_title'  => 'External Product',
                'post_type'   => 'product',
                'post_status' => 'publish',
            )
        );

        update_post_meta( $product, '_price', '10' );

        update_post_meta( $product, '_regular_price', '10' );

        update_post_meta( $product, '_downloadable', 'no' );

        update_post_meta( $product, '_virtual', 'no' );

        wp_set_object_terms( $product, 'external', 'product_type' );

        return new WC_Product_External( $product );
    }

    public function createVirtualProduct()
    {
        $product = wp_insert_post(
            array(
                'post_title'  => 'Virtual Product',
                'post_type'   => 'product',
                'post_status' => 'publish',
            )
        );

        update_post_meta( $product, '_price', '10' );

        update_post_meta( $product, '_regular_price', '10' );

        update_post_meta( $product, '_downloadable', 'no' );

        update_post_meta( $product, '_virtual', 'yes' );

        wp_set_object_terms( $product, 'simple', 'product_type' );

        return new WC_Product_Simple( $product );
    }

    public function createGroupedProduct()
    {
        $product = wp_insert_post(
            array(
            'post_title'  => 'Dummy Grouped Product',
            'post_type'   => 'product',
            'post_status' => 'publish',
            )
         );

        $simple_product_1 = self::create_simple_product( $product );

        $simple_product_2 = self::create_simple_product( $product );

        update_post_meta( $product, '_children', array( $simple_product_1->get_id(), $simple_product_2->get_id() ) );

        update_post_meta( $product, '_sku', 'DUMMY GROUPED SKU' );

        update_post_meta( $product, '_manage_stock', 'no' );

        update_post_meta( $product, '_tax_status', 'taxable' );

        update_post_meta( $product, '_downloadable', 'no' );

        update_post_meta( $product, '_virtual', 'no' );

        update_post_meta( $product, '_stock_status', 'instock' );

        wp_set_object_terms( $product, 'grouped', 'product_type' );

        return new WC_Product_Grouped( $product );
    }

    //TODO create variable product
    public function createVariableProduct()
    {

    }

}
