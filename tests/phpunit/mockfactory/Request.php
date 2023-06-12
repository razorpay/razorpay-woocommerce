<?php

namespace Razorpay\MockApi;

use Razorpay\MockApi\MockApi;
use Requests;
use Exception;

/**
 * Request class to communicate to the request libarary
 */

class Request
{
    public function request($method, $url, $data = array())
    {
        $key_id = MockApi::getKey();
        $response = $this->loadData();
        return $response[$key_id][$method][$url];
    }

    public function loadData()
    {
        return [
            'key_id' => [
                'GET' => [
                    'webhooks' => [
                        'entity' => 'collection',
                        'count' => 1,
                        'items' => [
                            [
                                'id' => 'abcd',
                                'url' => 'https://webhook.site/abcd',
                                'entity' => 'webhook',
                                'active' => true,
                                'events' => [
                                    'payment.authorized' => true,
                                    'order.paid' => true,
                                ]
                            ],
                        ]
                    ],
                    'webhooks?count=10&skip=0' => [
                        'entity' => 'collection',
                        'count' => 1,
                        'items' => [
                            [
                                'id' => 'abcd',
                                'url' => 'https://webhook.site/abcd',
                                'entity' => 'webhook',
                                'active' => true,
                                'events' => [
                                    'payment.authorized' => true,
                                    'order.paid' => true,
                                ]
                            ],
                        ]
                    ],
                    'preferences' => ['options'],
                    'orders' => [
                        'entity' => 'collection',
                        'count' => 1,
                        'items' => [
                            [
                                'id' => 'order_test',
                                'entity' => 'order',
                                'amount' => 0,
                                'amount_paid' => 0,
                                'amount_due' => 0,
                                'currency' => 'INR',
                                'receipt' => '11',
                                'offer_id' => null,
                                'status' => 'created',
                                'attempts' => 0,
                                'notes' => [
                                    'woocommerce_order_number' => '11'
                                ],
                                'created_at' => 1666097548
                            ]
                        ]
                    ]
                ],
                'POST' => [
                    'plugins/segment' => [
                        'status' => 'success'
                    ],
                    'orders' => [
                        'id' => 'razorpay_test_id',
                        'entity' => 'order',
                        'amount' => 0,
                        'amount_paid' => 0,
                        'amount_due' => 0,
                        'currency' => 'INR',
                    ],
                    'orders/id' => [
                        'id' => 'razorpay_test_id',
                        'entity' => 'order',
                        'amount' => 0,
                        'amount_paid' => 0,
                        'amount_due' => 0,
                        'currency' => 'INR',
                    ],
                    'webhooks/' => [
                        'id' => 'create',
                        'url' => 'https://webhook.site/create',
                        'entity' => 'webhook',
                        'active' => true,
                        'events' => [
                            'payment.authorized' => true,
                            'order.paid' => true
                        ]
                    ]
                ]
            ],
            'key_id_1' => [
                'GET' => [
                    'preferences' => [
                        'options' => [
                            'redirect' => true,
                            'image' => 'image.png'
                        ]
                    ],
                    'orders' => [
                        'entity' => 'collection',
                        'count' => 1,
                        'items' => [
                            [
                                'id' => 'order_test',
                                'entity' => 'order',
                                'amount' => 0,
                                'amount_paid' => 0,
                                'amount_due' => 0,
                                'currency' => 'INR',
                                'receipt' => '11',
                                'offer_id' => null,
                                'status' => 'created',
                                'attempts' => 0,
                                'notes' => [
                                    'woocommerce_order_number' => '11'
                                ],
                                'created_at' => 1666097548
                            ]
                        ]
                    ],
                    'webhooks?count=10&skip=0' => [
                        'entity' => 'collection',
                        'count' => 1,
                        'items' => [
                            [
                                'id' => 'update',
                                'url' => 'https://webhook.site/update',
                                'entity' => 'webhook',
                                'active' => true,
                                'events' => [
                                    'payment.authorized' => true,
                                    'order.paid' => true,
                                ]
                            ],
                        ]
                    ],
                ],
                'PUT' => [
                    'webhooks/update' => [
                        'id' => 'update',
                        'url' => 'https://webhook.site/update',
                        'entity' => 'webhook',
                        'active' => true,
                        'events' => [
                            'payment.authorized' => true,
                            'order.paid' => true
                        ],
                    ]
                ]
            ],
            'key_id_2' => [
                'GET' => [
                    'payments/Abc123/transfers' =>
                        [
                            'recipient_settlement_id' => 'Rzp123',
                            'items' => [
                                [
                                    'id' => 'abcd',
                                    'source' => 'order',
                                    'recipient' => 'pay',
                                    'amount' => 1200,
                                    'created_at' => 1677542400,
                                    'status' => 'Pending',
                                    'settlement_status' => 'pending',
                                    'recipient_settlement_id' => 'Rzp123'
                                ],
                            ]
                        ],
                    'payments/Abc123/?expand[]=recipient_settlement' =>
                        [
                            'recipient_settlement_id' => 'Rzp123',
                            'items' => [
                                [
                                    'id' => 'abcd',
                                    'source' => 'order',
                                    'recipient' => 'pay',
                                    'amount' => 1200,
                                    'amount_reversed' => 800,
                                    'created_at' => 1677542400
                                ],
                            ]
                        ],
                    'reversals' =>
                        [
                            'items' => [
                                [
                                    'id' => 'abcd',
                                    'transfer_id' => 'pqrs',
                                    'recipient' => 'pay',
                                    'amount' => 1200,
                                    'created_at' => 1677542400
                                ],
                            ]
                        ],
                    'payments/' =>
                        [
                            'items' => [
                                [
                                    'id' => 'abcd',
                                    'order_id' => 11,
                                    'email' => 'abc.xyz@razorpay.com',
                                    'amount' => 1200,
                                    'created_at' => 1677542400,
                                    'contact' => '9087654321',
                                    'status' => 'Pending'
                                ],
                            ]
                        ],
                    'transfers/Abc123/?expand[]=recipient_settlement' =>
                        [
                            'id' => 'abcd',
                            'source' => 'order',
                            'recipient' => 'pay',
                            'amount' => 1200,
                            'amount_reversed' => 800,
                            'created_at' => 1677542400,
                            'status' => 'Pending',
                            'settlement_status' => 'pending',
                            'recipient_settlement_id' => 'Rzp123',
                            'on_hold_until' => '',
                            'on_hold' => 1
                        ],
                    'accounts/me/features' =>
                        [
                            'assigned_features' => [
                                'afd' => [
                                    'name' => 'affordability_widget'
                                ]
                            ]
                        ],
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
                        ],
                    'preferences' => ['options'],
                    'orders' => [
                        "entity" => "collection",
                        "count" => 1,
                        "items" => [
                            [
                                "id" => "order_test",
                                "entity" => "order",
                                "amount" => 0,
                                "amount_paid" => 0,
                                "amount_due" => 0,
                                "currency" => "USD",
                                "receipt" => "11",
                                "offer_id" => null,
                                "status" => "created",
                                "attempts" => 0,
                                "notes" => [
                                    "woocommerce_order_number" => "11"
                                ],
                                "created_at" => 1666097548
                            ]
                        ]
                    ]
                ],
                'POST' => [
                    'plugins/segment' => [
                        'status' => 'tested'
                    ],
                    'orders' => [
                        'id' => 'razorpay_order_id',
                        'entity' => 'order',
                        'amount' => 0,
                        'amount_paid' => 0,
                        'amount_due' => 0,
                        'currency' => 'USD',
                        'receipt' => '16',
                    ],
                    'orders/id' => [
                        'id' => 'razorpay_order_id',
                        'entity' => 'order',
                        'amount' => 0,
                        'amount_paid' => 0,
                        'amount_due' => 0,
                        'currency' => 'USD',
                        'receipt' => '16',
                    ]
                ]
            ],
            'key_id_3' => [
                'GET' => [
                    'transfers/Abc123/?expand[]=recipient_settlement' =>
                        [
                            'id' => 'abcd',
                            'source' => 'order',
                            'recipient' => 'pay',
                            'amount' => 1200,
                            'amount_reversed' => 800,
                            'created_at' => 1677542400,
                            'status' => 'Pending',
                            'settlement_status' => 'on_hold',
                            'recipient_settlement_id' => 'Rzp123',
                            'on_hold_until' => 1677542400,
                            'on_hold' => 1
                        ],
                ]
            ],
            'key_id_4' => [
                'GET' => [
                    'transfers/Abc123/?expand[]=recipient_settlement' =>
                        [
                            'id' => 'abcd',
                            'source' => 'order',
                            'recipient' => 'pay',
                            'amount' => 1200,
                            'amount_reversed' => 800,
                            'created_at' => 1677542400,
                            'status' => 'Pending',
                            'settlement_status' => 'on_hold',
                            'recipient_settlement_id' => '',
                            'on_hold_until' => '',
                            'on_hold' => 0
                        ],
                ]
            ],
            'key_id_5' => [
                'GET' => [
                    'transfers/Abc123/?expand[]=recipient_settlement' =>
                        [
                            'id' => 'abcd',
                            'source' => 'order',
                            'recipient' => 'pay',
                            'amount' => 1200,
                            'amount_reversed' => 800,
                            'created_at' => 1677542400,
                            'status' => 'Pending',
                            'settlement_status' => '',
                            'recipient_settlement_id' => '',
                            'on_hold_until' => '',
                            'on_hold' => 0
                        ],
                ]
            ],
            'key_id_6' => [
                'GET' => [
                    'transfers/Abc123/?expand[]=recipient_settlement' =>
                        [
                            'id' => 'abcd',
                            'source' => 'order',
                            'recipient' => 'pay',
                            'amount' => 1200,
                            'amount_reversed' => 800,
                            'created_at' => 1677542400,
                            'status' => 'Pending',
                            'settlement_status' => 'Complete',
                            'recipient_settlement_id' => '',
                            'on_hold_until' => '',
                            'on_hold' => 0
                        ],
                ]
            ]
        ];
    }
}
