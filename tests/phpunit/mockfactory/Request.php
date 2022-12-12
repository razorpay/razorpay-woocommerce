<?php

namespace Razorpay\MockApi;

require_once __DIR__ . '/Request.php';

use Requests;
use Exception;

/**
 * Request class to communicate to the request libarary
 */

class Request
{
    public function request($method, $url, $data = array())
    {
        $response = $this->loadData();
        return $response[$method][$url];
    }

    public function loadData()
    {
        return [
            'GET' => [
                'webhooks' =>
                    [
                        "entity" => "collection",
                        "count" => 1,
                        "items" => [
                            [
                                "id" => "abcd",
                                "url" => "https://webhook.site/abcd",
                                "entity" => "webhook",
                                "active" => true,
                                "events" => [
                                    "payment.authorized" => false,
                                    "order.paid" => false,
                                ]
                            ],
                        ]
                    ]
                ],
            'POST' => [
                'plugins/segment' =>
                [
                    'status' => 'success'
                ]
            ]
        ];
    }
}
