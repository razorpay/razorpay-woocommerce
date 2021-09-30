<?php

require_once __DIR__ . '/../woo-razorpay.php';
require_once __DIR__ . '/../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class RZP_Route extends WP_List_Table
{

    function __construct()
    {
        parent::__construct(
            array(
                'singular' => 'wp_list_text_link', //Singular label
                'plural' => 'wp_list_test_links', //plural label, also this well be one of the table css class
                'ajax' => false        //does this table support ajax?
            )
        );
    }

    function rzp_transfers()
    {
        echo '<div>
            <div class="wrap route-container">';

        $this->route_header();
        $this->check_direct_transfer_feature();
        $this->prepare_items();

        echo '<input type="hidden" name="page" value="" />
            <input type="hidden" name="section" value="issues" />';

        $this->views();

        echo '<form method="get">
            <input type="hidden" name="page" value="razorpay_route_woocommerce">';

//        $this->search_box( 'search', 'search_id' );
        $this->display();

        echo '</form></div>
            </div>';
        $hide = "jQuery('.overlay').hide()";

        $direct_transfer_modal = '<div class="overlay">
            <div class="modal" id="transferModal" >
                <div class="modal-dialog">
                    <!-- Modal content-->
                    <div class="modal-content">
                        <div class="modal-header">
                        <h4 class="modal-title">Direct Transfer</h4>
                            <button type="button" class="close" data-dismiss="modal" onclick="' . $hide . '">&times;</button>

                        </div>
                        <div class="modal-body">
                        <form method="POST" action="' . esc_url(admin_url('admin-post.php')) . '">
                            <div class="form-group">
                            <label class="">Transfer Amount</label>
                            <div class="input-group"><div class="input-group-addon">INR</div>
                            <div class="InputField ">
                            <input name="drct_trf_amount" type="number" autocomplete="off" class="form-control" placeholder="Enter amount">
                            </div>
                            </div></div>
                            <div class="form-group"><label>Account Number</label>
                            <div class="InputField ">
                                <input type="text" name="drct_trf_account" class="form-control" placeholder="Linked account number">
                            </div>
                            </div>
                            <div>
                            <button type="submit" onclick="' . $hide . '" name="trf_create" class="btn btn-primary">Create</button>
                            <input type="hidden" name="action" value="rzp_direct_transfer">
                            </div>
                            </form>
                        </div>

                    </div>
                </div>
            </div>
            </div>
        <script type="text/javascript">
            jQuery("' . '.overlay' . '").on("' . 'click' . '", function(e) {
              if (e.target !== this) {
                return;
              }
              jQuery("' . '.overlay' . '").hide();
            });
        </script>';
        echo $direct_transfer_modal;
    }

    /**
     * Add columns to grid view
     */
    function get_columns()
    {

        $columns = array(
            'transfer_id' => __('Transfer Id'),
            'source' => __('Source'),
            'recipient' => __('Recipient'),
            'amount' => __('Amount'),
            'created_at' => __('Created At'),
            'transfer_status' => __('Transfer Status'),
            'settlement_status' => __('Settlement Status'),
            'settlement_id' => __('Settlement Id'),
        );

        return $columns;
    }

    function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'id':
            case 'transfer_id':
            case 'source':
            case 'recipient':
            case 'amount':
            case 'created_at':
            case 'settlement_id':
            case 'transfer_status':
            case 'settlement_status':
            case 'payment_id':
            case 'order_id':
            case 'email':
            case 'contact':
            case 'status':
            case 'reversal_id':
                return $item[$column_name];

            default:

                return print_r($item, true); //Show the whole array for troubleshooting purposes
        }
    }


    function usort_reorder($a, $b)
    {
        if (isset($_GET['orderby']) && isset($_GET['order'])) {
            // If no sort, default to title
            $orderby = (!empty(sanitize_text_field($_GET['orderby']))) ? sanitize_text_field($_GET['orderby']) : 'title';
            // If no order, default to asc
            $order = (!empty(sanitize_text_field($_GET['order']))) ? sanitize_text_field($_GET['order']) : 'desc';
            // Determine sort order
            $result = strcmp($a[$orderby], $b[$orderby]);
            // Send final sort direction to usort
            return ($order === 'asc') ? $result : -$result;
        }

    }

    function get_sortable_columns()
    {
        $sortable_columns = array(
            'title' => array('title', false),
        );
        return $sortable_columns;
    }

    /**
     * Prepare admin view
     */
    function prepare_items()
    {

        $per_page = 10;
        $current_page = $this->get_pagenum();

        if (1 < $current_page) {
            $offset = $per_page * ($current_page - 1);
        } else {
            $offset = 0;
        }

        $transfer_page = $this->get_items($per_page);
        $count = count($transfer_page);

        for ($i = 0; $i < $count; $i++) {
            if ($i >= $offset && $i < $offset + $per_page) {
                $transfer_pages[] = $transfer_page[$i];
            }
        }
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);
        usort($transfer_pages, array(&$this, 'usort_reorder'));


        $this->items = $transfer_pages;

        // Set the pagination
        $this->set_pagination_args(array(
            'total_items' => $count,
            'per_page' => $per_page,
            'total_pages' => ceil($count / $per_page)
        ));
    }

    function get_items($count)
    {
        $items = array();

        $Wc_Razorpay_Loader = new WC_Razorpay();

        $api = $Wc_Razorpay_Loader->getRazorpayApiInstance();

        try {
            $transfers = $api->transfer->all(['count' => 100, 'expand[]' => 'recipient_settlement']);

        } catch (Exception $e) {
            $message = $e->getMessage();

            wp_die('<div class="error notice">
                    <p>RAZORPAY ERROR: Transfers fetch failed with the following message: ' . $message . '</p>
                 </div>');
        }
        if ($transfers) {
            foreach ($transfers['items'] as $transfer) {

                $source_key = explode('_',$transfer['source']);

                $items[] = array(
                    'transfer_id' => '<a href="?page=razorpay_transfers&id=' . $transfer['id'] . '">' . $transfer['id'] . '</a>',
                    'source' => (($source_key[0] == 'order') || ($source_key[0] == 'pay'))? $transfer['source'] : 'Direct Transfer',
                    'recipient' => $transfer['recipient'],
                    'amount' => '<span class="rzp-currency">₹</span> ' . (int)round($transfer['amount'] / 100),
                    'created_at' => date("d F Y h:i A", strtotime('+5 hour +30 minutes', $transfer['created_at'])),
                    'transfer_status' => ucwords($transfer['status']),
                    'settlement_status' => (empty($transfer['settlement_status'])) ? 'Not Applicable' : ucwords($transfer['settlement_status']),
                    'settlement_id' => (!empty($transfer['recipient_settlement_id'])) ? '<a href="?page=razorpay_settlement_transfers&id=' . $transfer['recipient_settlement_id'] . '">' . $transfer['recipient_settlement_id'] . '</a>' : '--',

                );
            }
        }
        return $items;
    }

    function rzp_transfer_details()
    {
        if (empty(sanitize_text_field($_REQUEST['id'])) || null == (sanitize_text_field($_REQUEST['id']))) {
            wp_die("This page consist some request parameters to view response");
        } else {

            $transfer_id = $_REQUEST['id'];
            $Wc_Razorpay_Loader = new WC_Razorpay();

            $api = $Wc_Razorpay_Loader->getRazorpayApiInstance();
            $url = "transfers/" . $transfer_id . "/?expand[]=recipient_settlement";

            $transfer_detail = $api->request->request("GET", $url);

            $prev_url = admin_url('admin.php?page=razorpay_route_woocommerce');

            $show = "jQuery('.rev_trf_overlay').show()";
            $hide = "jQuery('.rev_trf_overlay').hide()";

            $show_setl = "jQuery('.trf_settlement_overlay').show()";
            $hide_setl = "jQuery('.trf_settlement_overlay').hide()";

            $source_key = explode('_',$transfer_detail['source']) ;
            $source = (($source_key[0] == 'order') || ($source_key[0] == 'pay')) ? $transfer_detail['source'] : "Direct Transfer" ;

            echo '<div class="wrap">
            <div class="content-header">
                <a href="' . $prev_url . '">
                    <span class="dashicons rzp-dashicons dashicons-arrow-left-alt"></span>  Transfer List
                </a>
            </div>
            <div class="container rzp-container">
                <div class="row panel-heading">
                    <div class="text">Transfer Details</div>
                </div>
                <div class="row panel-body">
                    <div class="col-md-12 panel-body-left">
                        <div class="row">
                            <div class="col-sm-4 panel-label">Transfer ID</div>
                            <div class="col-sm-8 panel-value">' . $transfer_detail["id"] . '</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 panel-label">Source</div>
                            <div class="col-sm-8 panel-value">' . $source . '</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 panel-label">Recipient</div>
                            <div class="col-sm-8 panel-value">' . $transfer_detail['recipient'] . '</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 panel-label">Amount</div>
                            <div class="col-sm-8 panel-value"><span class="rzp-currency">₹ </span>' . (int)round($transfer_detail['amount'] / 100) . '</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 panel-label">Transfer Status</div>
                            <div class="col-sm-8 panel-value"><span class="text-info">'. ucwords($transfer_detail['status']) .'</span></div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 panel-label">Settlement Status</div>
                            <div class="col-sm-8 panel-value">';

            if ($transfer_detail['settlement_status'] == 'on_hold' && $transfer_detail['on_hold_until'] != '') {
                echo '<span class="text-warning trf-status">Scheduled for ' . date("d M Y", $transfer_detail['on_hold_until']) . '</span>';
                echo '<span><a href="javascript:void(0);" onclick="' . $show_setl . '" >Change</a></span>';
            } elseif ($transfer_detail['settlement_status'] == 'on_hold' && $transfer_detail['on_hold_until'] == '') {
                echo '<span class="text-danger trf-status">On Hold</span>';
                echo '<span><a href="javascript:void(0);" onclick="' . $show_setl . '" >Change</a></span>';
            } elseif ($transfer_detail['settlement_status'] == 'pending') {
                echo '<span class="text-success trf-status">Pending</span>';
                echo '<span><a href="javascript:void(0);" onclick="' . $show_setl . '" >Change</a></span>';
            } elseif ($transfer_detail['settlement_status'] == ''){
                echo '<span>Not Applicable</span>';
            } else {
                echo '<span>' . ucwords($transfer_detail['settlement_status']) . '</span>';
            }

            echo '</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 panel-label">Amount Reversed</div>
                            <div class="col-sm-8 panel-value"><span class="rzp-currency">₹ </span>' . (int)round($transfer_detail['amount_reversed'] / 100);
            if ($transfer_detail["amount_reversed"] < $transfer_detail["amount"]) {
                echo '<button onclick="' . $show . '" class="btn btn-primary">Create Reversal</button>';
            }
            echo '</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 panel-label">Created on</div>
                            <div class="col-sm-8 panel-value">' . date("d F Y h:i A", strtotime('+5 hour +30 minutes', $transfer_detail['created_at'])) . '</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>';

            $reverse_transfer_modal = '<div class="rev_trf_overlay">
                <div class="modal" id="revTransferModal">
                    <div class="modal-dialog">
                        <!-- Modal content-->
                        <div class="modal-content">
                            <div class="modal-header">
                            <h4 class="modal-title">Reverse Transfer</h4>
                                <button type="button" class="close" data-dismiss="modal" onclick="' . $hide . '">&times;</button>

                            </div>
                            <div class="modal-body">
                            <form method="POST" action="' . esc_url(admin_url('admin-post.php')) . '">
                                <div class="form-group">
                                <label class="label-required">Reversal Amount</label>
                                <div class="input-group"><div class="input-group-addon">INR</div>
                                <div class="InputField ">
                                <input name="reversal_amount" type="number" autocomplete="off" class="form-control" id="trf_reversal_amount" placeholder="Enter the reversal amount" value="'.(int)round(($transfer_detail['amount'] - $transfer_detail['amount_reversed'])/ 100).'">
                                </div>
                                </div>
                                <p class="text-danger" id="trf_reverse_text"></p>
                                </div>

                                <div>

                                <button type="submit" onclick="' . $hide . '" name="create_reversal" id="reverse_transfer_btn" class="btn btn-primary">Create Reversal</button>
                                <input type="hidden" name="action" value="rzp_reverse_transfer">
                                <input type="hidden" name="transfer_id" value="' . $transfer_detail['id'] . '">
                                <input type="hidden" name="transfer_amount" value="' . $transfer_detail['amount'] . '">

                                </div>
                                </form>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
                <script type="text/javascript">
                    jQuery("' . '.rev_trf_overlay' . '").on("' . 'click' . '", function(e) {
                      if (e.target !== this) {
                        return;
                      }
                      jQuery("' . '.rev_trf_overlay' . '").hide();
                    });
                </script>';
            echo $reverse_transfer_modal;

            $onhold_status = $settle_sts = $hold_until_status = $hold_date = $disable_date = '';

            if ($transfer_detail['on_hold_until'] != '') {
                $hold_date = date("Y-m-d", $transfer_detail['on_hold_until']);
                $hold_until_status = "checked";
            }
            if ($transfer_detail['on_hold'] == 1 && $transfer_detail['on_hold_until'] == '') {
                $onhold_status = "checked";
                $disable_date = "disabled";
            }
            if ($transfer_detail['on_hold'] == 0 && $transfer_detail['recipient_settlement_id'] == '') {
                $settle_sts = "checked";
                $disable_date = "disabled";
            }

            $transfer_settlement_modal = '<div class="trf_settlement_overlay">
                <div class="modal" id="transferModal" >
                    <div class="modal-dialog">
                        <!-- Modal content-->
                        <div class="modal-content">
                            <div class="modal-header">
                            <h4 class="modal-title">Change Settlement Schedule</h4>
                                <button type="button" class="close" data-dismiss="modal" onclick="' . $hide_setl . '">&times;</button>

                            </div>
                            <div class="modal-body">
                            <form method="POST" action="' . esc_url(admin_url('admin-post.php')) . '">
                                <div class="RadioButton">
                                    <label><input type="radio" name="on_hold" class="enable_hold_until" value="on_hold_until" ' . $hold_until_status . '>
                                        <span>Schedule settlement on</span>
                                    </label>
                                    <input type="date" name="hold_until" id="hold_until"  min="' . date('Y-m-d', strtotime('+4 days')) . '" value="' . $hold_date . '" ' . $disable_date . ' >
                                </div>
                                <div class="RadioButton">
                                    <label><input type="radio" name="on_hold" class="disable_hold_until" value="1" ' . $onhold_status . '>
                                        <span>Put on hold</span>
                                    </label>
                                </div>
                                <div class="RadioButton">
                                    <label><input type="radio" name="on_hold" class="disable_hold_until" value="0" ' . $settle_sts . ' >
                                        <span>Settle in next slot</span>
                                    </label>
                                </div>

                                <div>

                                <button type="submit" onclick="' . $hide_setl . '" name="update_setl_status"  class="btn btn-primary">Save</button>
                                <input type="hidden" name="action" value="rzp_settlement_change">
                                <input type="hidden" name="transfer_id" value="' . $transfer_detail['id'] . '">
                                </div>
                                </form>
                            </div>

                        </div>
                    </div>
                </div></div>
                <script type="text/javascript">
                    jQuery("' . '.trf_settlement_overlay' . '").on("' . 'click' . '", function(e) {
                      if (e.target !== this) {
                        return;
                      }
                      jQuery("' . '.trf_settlement_overlay' . '").hide();
                    });
                </script>';
            echo $transfer_settlement_modal;
        }
    }

    function route_header()
    {

        ?>
        <header id="rzp-route-header" class="rzp-route-header">
            <a <?php if ($_GET['page'] == "razorpay_route_woocommerce") { ?> class="active" <?php } ?>
                href="?page=razorpay_route_woocommerce">Transfers</a>
            <a <?php if ($_GET['page'] == "razorpay_route_payments") { ?> class="active" <?php } ?>
                href="?page=razorpay_route_payments">Payments</a>
            <a <?php if ($_GET['page'] == "razorpay_route_reversals") { ?> class="active" <?php } ?>
                href="?page=razorpay_route_reversals">Reversals</a>

        </header>
        <?php
    }

    function rzp_transfer_reversals()
    {
        echo '<div>
            <div class="wrap route-container">';

        $this->route_header();

        $this->prepare_reversal_items();

        echo '<form method="get">
            <input type="hidden" name="page" value="razorpay_route_reversals">';

//        $this->search_box('search', 'search_id');
        $this->display();

        echo '</form></div>
            </div>';
    }

    function prepare_reversal_items()
    {

        $per_page = 10;
        $current_page = $this->get_pagenum();
        if (1 < $current_page) {
            $offset = $per_page * ($current_page - 1);
        } else {
            $offset = 0;
        }

        $reversal_page = $this->get_reversal_items($per_page);
        $count = count($reversal_page);

        for ($i = 0; $i < $count; $i++) {
            if ($i >= $offset && $i < $offset + $per_page) {
                $reversal_pages[] = $reversal_page[$i];
            }
        }
        $columns = $this->get_reversal_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);
        usort($reversal_pages, array(&$this, 'usort_reorder'));

        $this->items = $reversal_pages;

        // Set the pagination
        $this->set_pagination_args(array(
            'total_items' => $count,
            'per_page' => $per_page,
            'total_pages' => ceil($count / $per_page)
        ));
    }


    function get_reversal_columns()
    {
        $columns = array(
            'reversal_id' => __('Reversal Id'),
            'transfer_id' => __('Transfer Id'),
            'amount' => __('Amount'),
            'created_at' => __('Created At'),
        );
        return $columns;
    }

    function get_reversal_items($count)
    {
        $result = array();
        $Wc_Razorpay_Loader = new WC_Razorpay();
        $api = $Wc_Razorpay_Loader->getRazorpayApiInstance();

        try {
            $url = "reversals";
            $data = array(
                'count' => 100,
            );

            $reversals = $api->request->request("GET", $url, $data);

        } catch (Exception $e) {
            $message = $e->getMessage();

            wp_die('<div class="error notice">
                    <p>RAZORPAY ERROR: Fetch Transfer reversals failed with the following message: ' . $message . '</p>
                 </div>');
        }

        if ($reversals) {
            foreach ($reversals['items'] as $reversal) {
                $result[] = array(
                    'reversal_id' => $reversal['id'],
                    'transfer_id' => $reversal['transfer_id'],
                    'amount' => '<span class="rzp-currency">₹</span> ' . (int)round($reversal['amount'] / 100),
                    'created_at' => date("d M Y h:i A", strtotime('+5 hour +30 minutes', $reversal['created_at'])),
                );

            }
        }
        return $result;

    }

    function rzp_route_payments()
    {
        echo '<div>
            <div class="wrap route-container">';

        $this->route_header();

        $this->prepare_payment_items();

        echo '<form method="get">
            <input type="hidden" name="page" value="razorpay_route_payments">';
        echo '<p class="pay_search_label">Search here for payments of linked account</p>';
        $this->search_box('search', 'search_id');
        $this->display();

        echo '</form></div>
            </div>';
    }

    function prepare_payment_items()
    {

        $per_page = 10;
        $current_page = $this->get_pagenum();

        if (1 < $current_page) {
            $offset = $per_page * ($current_page - 1);
        } else {
            $offset = 0;
        }

        $acc_id = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';

        $payment_page = $this->get_payment_items($per_page, $acc_id);
        $count = count($payment_page);

        for ($i = 0; $i < $count; $i++) {
            if ($i >= $offset && $i < $offset + $per_page) {
                $payment_pages[] = $payment_page[$i];
            }
        }
        $columns = $this->get_payment_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);
        usort($payment_pages, array(&$this, 'usort_reorder'));

        $this->items = $payment_pages;

        // Set the pagination
        $this->set_pagination_args(array(
            'total_items' => $count,
            'per_page' => $per_page,
            'total_pages' => ceil($count / $per_page)
        ));
    }


    function get_payment_columns()
    {
        $columns = array(
            'payment_id' => __('Payment Id'),
            'order_id' => __('Order Id'),
            'amount' => __('Amount'),
            'email' => __('Email'),
            'contact' => __('Contact'),
            'created_at' => __('Created At'),
            'status' => __('Status'),
        );
        return $columns;
    }


    function get_payment_items($count, $acc_id)
    {
        $result = array();
        $Wc_Razorpay_Loader = new WC_Razorpay();
        $api = $Wc_Razorpay_Loader->getRazorpayApiInstance();

        try {
            $url = "payments/";
            $data = array(
                'count' => 100,
            );

            if (isset($acc_id) && $acc_id != '') {
                $api->request->addHeader('X-Razorpay-Account', $acc_id);
            }

            $payments = $api->request->request("GET", $url, $data);

        } catch (Exception $e) {
            $message = $e->getMessage();

            wp_die('<div class="error notice">
                    <p>RAZORPAY ERROR: Payments fetch failed with the following message: ' . $message . '</p>
                 </div>');
        }

        if ($payments) {
            foreach ($payments['items'] as $payment) {
                $result[] = array(
                    'payment_id' => ($acc_id != "") ? $payment['id'] : '<a href="?page=razorpay_payments_view&id=' . $payment['id'] . '">' . $payment['id'] . '</a>',
                    'order_id' => $payment['order_id'],
                    'amount' => '<span class="rzp-currency">₹</span> ' . (int)round($payment['amount'] / 100),
                    'email' => $payment['email'],
                    'contact' => $payment['contact'],
                    'created_at' => date("d F Y h:i A", strtotime('+5 hour +30 minutes', $payment['created_at'])),
                    'status' => ucfirst($payment['status']),
                );

            }
        }
        return $result;

    }

    function rzp_settlement_transfers()
    {
        if (empty(sanitize_text_field($_REQUEST['id'])) || null == (sanitize_text_field($_REQUEST['id']))) {
            wp_die("This page consist some request parameters to view response");
        } else {

            $settlement_id = $_REQUEST['id'];
            $Wc_Razorpay_Loader = new WC_Razorpay();

            $api = $Wc_Razorpay_Loader->getRazorpayApiInstance();
            try {
                $data = array(
                    'recipient_settlement_id' => $settlement_id
                );
                $stl_transfers = $api->transfer->all($data);

            } catch (Exception $e) {
                $message = $e->getMessage();

                wp_die('<div class="error notice">
                    <p>RAZORPAY ERROR: Settlement Transfers fetch failed with the following message: ' . $message . '</p>
                 </div>');
            }


            $prev_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

            echo '<div class="wrap">
            <div class="content-header">
                <a href="' . $prev_url . '">
                    <span class="dashicons rzp-dashicons dashicons-arrow-left-alt"></span>  Back
                </a>
            </div>
            <div class="container rzp-container">
                <div class="row panel-heading">
                    Settlement ID :  <strong>' . $settlement_id . '</strong>
                </div>
                <div class="row panel-body">
                    <div class="col-md-12 panel-body-left">
                        <div class="row">
                            Collection of all transfers made on this Settlement
                        </div>

                        <div class="button-items-detail">
                            <div class="row">
                                <div class="col">Transfer Id</div>
                                <div class="col">Source</div>
                                <div class="col">Recipient</div>
                                <div class="col">Amount</div>
                                <div class="col">Created At</div>
                            </div>';
            foreach ($stl_transfers['items'] as $transfer) {
                echo '<div class="row panel-value">
                                <div class="col">' . $transfer['id'] . '</div>
                                <div class="col">' . $transfer['source'] . '</div>
                                <div class="col">' . $transfer['recipient'] . ' </div>
                                <div class="col"><span class="rzp-currency">₹ </span>' . (int)round($transfer['amount'] / 100) . '</div>
                                <div class="col">' . $transfer['created_at'] . '</div>
                            </div>';
            }
            echo '</div>
                    </div>
                </div>
            </div>
        </div>';

        }
    }

    function rzp_payment_details()
    {
        if (empty(sanitize_text_field($_REQUEST['id'])) || null == (sanitize_text_field($_REQUEST['id']))) {
            wp_die("This page consist some request parameters to view response");
        } else {

            $payment_id = $_REQUEST['id'];
            $Wc_Razorpay_Loader = new WC_Razorpay();

            $api = $Wc_Razorpay_Loader->getRazorpayApiInstance();
            $payment_detail = $api->payment->fetch($payment_id);

            $payment_transfers = $api->payment->fetch($payment_id)->transfers();

            $prev_url = admin_url('admin.php?page=razorpay_route_payments');

            $show = "jQuery('.overlay').show()";
            $hide = "jQuery('.overlay').hide()";

            $trf_details = '';
            $transferred_amount = 0;
            if ($payment_transfers['count'] != 0) {

                $trf_details = '<div class="button-items-detail">
                    <div class="row">
                        <div class="col">Transfer Id</div>
                        <div class="col">Amount</div>
                        <div class="col">Created At</div>
                    </div>';

                foreach ($payment_transfers['items'] as $transfer) {
                    $transferred_amount = $transferred_amount + $transfer['amount'];

                    $trf_details .= '<div class="row panel-value">
                                            <div class="col"><a href="?page=razorpay_transfers&id='.$transfer['id'].'">' . $transfer['id'] . '</a></div>
                                            <div class="col"><span class="rzp-currency">₹ </span>' . (int)round($transfer['amount'] / 100) . '</div>
                                            <div class="col">' . date("d F Y h:i A", strtotime('+5 hour +30 minutes', $transfer['created_at'])) . '</div>
                                        </div>';
                }
                $trf_details .= '</div> ';
            }

            echo '<div class="wrap">
                <div class="content-header">
                    <a href="' . $prev_url . '">
                        <span class="dashicons rzp-dashicons dashicons-arrow-left-alt"></span>  Payment List
                    </a>
                </div>
                <div class="container rzp-container">
                    <div class="row panel-heading">
                        <div class="textButton">Payment Details</div>
                    </div>
                    <div class="row panel-body">
                        <div class="col-md-12 panel-body-left">
                            <div class="row">
                                <div class="col-sm-4 panel-label">Payment ID</div>
                                <div class="col-sm-8 panel-value">' . $payment_detail["id"] . '</div>
                            </div>
                            <div class="row">
                                <div class="col-sm-4 panel-label">Amount</div>
                                <div class="col-sm-8 panel-value"><span class="rzp-currency">₹ </span>' . (int)round($payment_detail['amount'] / 100) . '</div>
                            </div>
                            <div class="row">
                                <div class="col-sm-4 panel-label">Status</div>
                                <div class="col-sm-8 panel-value">' . ucfirst($payment_detail['status']) . '</div>
                            </div>
                            <div class="row">
                                <div class="col-sm-4 panel-label">Order ID</div>
                                <div class="col-sm-8 panel-value">' . $payment_detail['order_id'] . '</div>
                            </div>
                            <div class="row">
                                <div class="col-sm-4 panel-label">Transfers</div>
                                <div class="col-sm-8 panel-value">';

            if ($payment_detail['status'] == 'created') {
                echo '--';
            } else {
                $trf_count = ($payment_transfers['count'] == 0) ? 'No' : $payment_transfers['count'];
                echo '<span>' . $trf_count . ' transfers created</span>';
            }


            if ($payment_detail['status'] == 'captured' && $payment_detail['amount'] > $transferred_amount) {
                echo '<button onclick="' . $show . '" class="button">Create Transfer</button>';
            }

            echo $trf_details;
            echo '</div>
                            </div>
                            <div class="row">
                                <div class="col-sm-4 panel-label">Created on</div>
                                <div class="col-sm-8 panel-value">' . date("d F Y h:i A", strtotime('+5 hour +30 minutes', $payment_detail['created_at'])) . '</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>';

            $payment_transfer_modal = '<div class="overlay">
                <div class="modal" id="paymentTransferModal" >
                    <div class="modal-dialog">
                        <!-- Modal content-->
                        <div class="modal-content">
                            <div class="modal-header">
                            <h4 class="modal-title">Create New Transfer</h4>
                                <button type="button" class="close" data-dismiss="modal" onclick="' . $hide . '">&times;</button>

                            </div>
                            <div class="modal-body">
                            <form method="POST" action="' . esc_url(admin_url('admin-post.php')) . '">
                                <div class="form-group">
                                <label class="">Transfer Amount</label>
                                <div class="input-group"><div class="input-group-addon">INR</div>
                                <div class="InputField ">
                                <input name="pay_trf_amount" type="number" autocomplete="off" class="form-control" id="payment_trf_amount" placeholder="Enter amount" value="'.(int)round(($payment_detail['amount'] - $transferred_amount)/ 100).'">
                                </div>
                                </div>
                                <p class="text-danger" id="payment_trf_error"></p>
                                </div>
                                <div class="form-group"><label>Account Number</label>
                                <div class="InputField ">
                                    <input type="text" name="pay_trf_account" class="form-control" placeholder="Linked account number">
                                </div>
                                </div>
                                <div class="form-group"><label>Settlement schedule</label>
                                <div class="RadioButton">
                                    <label><input type="radio" name="on_hold" class="enable_hold_until" value="on_hold_until" >
                                        <span>Schedule settlement on</span>
                                    </label>
                                    <input type="date" name="hold_until" id="hold_until"  min="' . date('Y-m-d', strtotime('+4 days')) . '" disabled >
                                </div>
                                <div class="RadioButton">
                                    <label><input type="radio" name="on_hold" class="disable_hold_until" value="1" >
                                        <span>Put on hold</span>
                                    </label>
                                </div>
                                <div class="RadioButton">
                                    <label><input type="radio" name="on_hold" class="disable_hold_until" value="0" checked>
                                        <span>Settle in next slot</span>
                                    </label>
                                </div> </div>
                                <div>
                                <button type="submit" onclick="' . $hide . '" name="trf_create" class="btn btn-primary" id="payment_transfer_btn">Create</button>
                                <input type="hidden" name="payment_id" value="' . $payment_detail['id'] . '">
                                <input type="hidden" name="action" value="rzp_payment_transfer">
                                </div>
                                </form>
                            </div>

                        </div>
                    </div>
                </div></div>
                <script type="text/javascript">
                    jQuery("' . '.overlay' . '").on("' . 'click' . '", function(e) {
                      if (e.target !== this) {
                        return;
                      }
                      jQuery("' . '.overlay' . '").hide();
                    });
                </script>';
            echo $payment_transfer_modal;
        }
    }

    function  check_direct_transfer_feature(){

        $Wc_Razorpay_Loader = new WC_Razorpay();

        $url = 'https://api.razorpay.com/v1/accounts/me/features';
        $context = stream_context_create(array(
            'http' => array(
                'header'  => "Authorization: Basic " . base64_encode($Wc_Razorpay_Loader->getSetting('key_id').":".$Wc_Razorpay_Loader->getSetting('key_secret'))
            ),
            "ssl"=>array(
                "verify_peer"=>false,
                "verify_peer_name"=>false,
            ),
        ));
        $features_data = file_get_contents($url, false, $context);
        $api_response = json_decode($features_data, true);
        $direct_transfer_btn = '';
        foreach ($api_response['assigned_features'] as $features){
            if($features['name'] == 'direct_transfer'){

                $show = "jQuery('.overlay').show()";
                $direct_transfer_btn = '<button class="btn btn-primary" onclick="' . $show . '">Create Direct Transfer</button>';

            }
        }
        echo  $direct_transfer_btn;
    }

}
