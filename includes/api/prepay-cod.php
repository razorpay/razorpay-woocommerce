<?php

function prepayCODOrderHandler(WP_REST_Request $request) {
    $params = $request->get_params();
    return prepayCODOrder($params);
}
