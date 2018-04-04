<?php

class OrderFactory
{
    public function createOrder($customerId= 1, $product)
    {
        $order_data = array(
			'status'        => 'pending',
			'customer_id'   => '1',
			'customer_note' => '',
			'total'         => '',
		);

		$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; // Required, else wc_create_order throws an exception

		$order 	= wc_create_order( $order_data );

        $item = new WC_Order_Item_Product();

        $item->set_props(
            array(
            'product'  => $product,
            'quantity' => 1,
            'subtotal' => wc_get_price_excluding_tax( $product, array( 'qty' => 1 ) ),
            'total'    => wc_get_price_excluding_tax( $product, array( 'qty' => 1 ) ),
                )
        );

        $item->save();
        $order->add_item( $item );

        $order->set_billing_first_name( 'test' );
        $order->set_billing_last_name( 'user' );
        $order->set_billing_company( 'Razorpay' );
        $order->set_billing_address_1( '' );
        $order->set_billing_address_2( '' );
        $order->set_billing_city( '' );
        $order->set_billing_state( '' );
        $order->set_billing_postcode( '123456' );
        $order->set_billing_country( 'India' );
        $order->set_billing_email( 'test@razorpay.org' );
        $order->set_billing_phone( '555-32123' );


        $payment_gateways = WC()->payment_gateways->payment_gateways();

        $order->set_payment_method( $payment_gateways['razorpay'] );

        $order->set_shipping_total( 0 );
        $order->set_currency( 'INR');

        $order->set_discount_total( 0 );
        $order->set_total( 10 );
        $order->set_discount_tax( 0 );
        $order->set_cart_tax( 0 );
        $order->set_shipping_tax( 0 );
        $order->save();

        return $order;
    }
}
