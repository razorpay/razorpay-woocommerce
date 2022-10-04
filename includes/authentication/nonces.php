<?php

function rzp_create_nonce( $action = -1 ) {
    $user = wp_get_current_user();
    $uid  = (int) $user->ID;
    if ( ! $uid ) {
        $uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
    }
   
    $token = wp_get_session_token();
    $i     = wp_nonce_tick();
    
    return wp_hash_password( $i . 'rzp' . $action . 'rzp' . $uid . 'rzp' . $token, 'nonce' );
}

function rzp_verify_nonce( $nonce, $action = -1 ) {
    $nonce = (string) $nonce;
    $user  = wp_get_current_user();
    $uid   = (int) $user->ID;
    if ( ! $uid ) {
        $uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
    } 
    if ( empty( $nonce ) ) {
        return false;
    }

    $token = wp_get_session_token();
    $i     = wp_nonce_tick();

    // Nonce generated 0-12 hours ago.
    $expected = wp_hash_password($i . 'rzp' . $action . 'rzp' . $uid . 'rzp' . $token, 'nonce' );
    if ( wp_check_password( $nonce, $expected ) ) {
        return 1;
    }

    // Nonce generated 12-24 hours ago.
    $expected = wp_hash_password( ($i-1) . 'rzp' . $action . 'rzp' . $uid . 'rzp' . $token, 'nonce' );
    if ( wp_check_password( $expected, $nonce ) ) {
        return 2;
    }
    do_action( 'wp_verify_nonce_failed', $nonce, $action, $user, $token );

    return false;
}
