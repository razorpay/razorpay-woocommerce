<?php

require_once __DIR__ .'/../woo-razorpay.php';
require_once __DIR__ .'/../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

add_action('setup_extra_setting_fields', 'addRouteModuleSettingFields');
add_action('admin_post_rzp_direct_transfer', 'razorpayDirectTransfer');
add_action('admin_post_rzp_reverse_transfer', 'razorpayReverseTransfer');
add_action('admin_post_rzp_settlement_change', 'razorpaySettlementUpdate');
add_action('admin_post_rzp_payment_transfer', 'razorpayPaymentTransfer');

add_action( 'check_route_enable_status', 'razorpayRouteModule',0 );
do_action('check_route_enable_status');

function addRouteModuleSettingFields(&$defaultFormFields){
    if( get_woocommerce_currency() == "INR") {

        $routeEnableFields = array(
            'route_enable' => array(
                'title' => __('Route Module'),
                'type' => 'checkbox',
                'label' => __('Enable route module?'),
                'description' => "<span>For Route payments / transfers, first create a linked account <a href='https://dashboard.razorpay.com/app/route/payments' target='_blank'>here</a></span><br/><br/>Route Documentation - <a href='https://razorpay.com/docs/route/' target='_blank'>View</a>",
                'default' => 'no'
            )
        );
        $defaultFormFields = array_merge($defaultFormFields, $routeEnableFields);
    }
}

function razorpayRouteModule(){

    if(isset(get_option('woocommerce_razorpay_settings')['route_enable'])){
        $routeSettingField = get_option('woocommerce_razorpay_settings')['route_enable'];

        if($routeSettingField == 'yes')
        {
            add_action('admin_menu',  'rzpAddPluginPage');
            add_action('admin_enqueue_scripts', 'adminEnqueueScriptsFunc', 0);

            add_filter( 'woocommerce_product_data_tabs', 'transferDataTab', 90 , 1 );
            add_action( 'woocommerce_product_data_panels', 'productTransferDataFields' );
            add_action( 'woocommerce_process_product_meta', 'woocommerce_process_transfer_meta_fields_save' );
            add_action( 'add_meta_boxes', 'paymentTransferMetaBox' );

        }
    }
}

function rzpAddPluginPage()
{
    /* add pages & menu items */

    add_menu_page(esc_attr__('Razorpay Route woocommerce', 'textdomain'), esc_html__('Razorpay Route woocommerce', 'textdomain'),'administrator', 'razorpayRouteWoocommerce', 'razorpayRouteWoocommerce', '', 10);

    add_submenu_page( esc_attr__( '', 'textdomain' ), esc_html__( 'Razorpay Route woocommerce', 'textdomain' ),
        'Razorpay Route woocommerce', 'administrator','razorpayTransfers', 'razorpayTransfers' );

    add_submenu_page( esc_attr__( '', 'textdomain' ), esc_html__( 'Razorpay Route woocommerce', 'textdomain' ),
        'Razorpay Route woocommerce', 'administrator','razorpayRouteReversals', 'razorpayRouteReversals' );

    add_submenu_page( esc_attr__( '', 'textdomain' ), esc_html__( 'Razorpay Route woocommerce', 'textdomain' ),
        'Razorpay Route woocommerce', 'administrator','razorpayRoutePayments', 'razorpayRoutePayments' );

    add_submenu_page( esc_attr__( '', 'textdomain' ), esc_html__( 'Razorpay Route woocommerce', 'textdomain' ),
        'Razorpay Route woocommerce', 'administrator','razorpaySettlementTransfers', 'razorpaySettlementTransfers' );

    add_submenu_page( esc_attr__( '', 'textdomain' ), esc_html__( 'Razorpay Route woocommerce', 'textdomain' ),
        'Razorpay Route woocommerce', 'administrator','razorpayPaymentsView', 'razorpayPaymentsView' );

}

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

    protected function fetchRazorpayApiInstance()
    {
        $Wc_Razorpay_Loader = new WC_Razorpay();

        $api = $Wc_Razorpay_Loader->getRazorpayApiInstance();

        return $api;
    }

    protected function fetchSetting()
    {
        $Wc_Razorpay_Loader = new WC_Razorpay();

        $setting = $Wc_Razorpay_Loader->getSetting('key_id') . ":" . $Wc_Razorpay_Loader->getSetting('key_secret');

        return $setting;
    }

    protected function fetchFileContents($url, $context)
    {
        return @file_get_contents($url, false, $context);
    }

    function rzpTransfers()
    {
        echo '<div>
            <div class="wrap route-container">';

        $this->routeHeader();
        $this->checkDirectTransferFeature();
        $this->prepareItems();

        echo '<input type="hidden" name="page" value="" />
            <input type="hidden" name="section" value="issues" />';

        $this->views();

        echo '<form method="get">
            <input type="hidden" name="page" value="razorpayRouteWoocommerce">';

        $this->display();

        echo '</form></div>
            </div>';
        $hide = "jQuery('.overlay').hide()";

        $directTransferModal = '<div class="overlay">
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
                            <div class="form-group"><label>Linked Account Number</label>
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
        echo $directTransferModal;
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
            case 'la_name':
            case 'la_number':
                return $item[$column_name];

            default:

                return print_r($item, true); //Show the whole array for troubleshooting purposes
        }
    }

    /**
     * Prepare admin view
     */
    function prepareItems()
    {

        $perPage = 10;
        $currentPage = $this->get_pagenum();

        if (1 < $currentPage) {
            $offset = $perPage * ($currentPage - 1);
        } else {
            $offset = 0;
        }

        $transferPage = $this->getItems($perPage);
        $count = count($transferPage);

        $transferPages = array();
        for ($i = 0; $i < $count; $i++) {
            if ($i >= $offset && $i < $offset + $perPage) {
                $transferPages[] = $transferPage[$i];
            }
        }
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = array();
        $this->_column_headers = array($columns, $hidden, $sortable);

        $this->items = $transferPages;

        // Set the pagination
        $this->set_pagination_args(array(
            'total_items' => $count,
            'per_page' => $perPage,
            'total_pages' => ceil($count / $perPage)
        ));
    }

    function getItems($count)
    {
        $items = array();

        $api = $this->fetchRazorpayApiInstance();

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
                    'transfer_id' => '<a href="?page=razorpayTransfers&id=' . $transfer['id'] . '">' . $transfer['id'] . '</a>',
                    'source' => (($source_key[0] == 'order') || ($source_key[0] == 'pay'))? $transfer['source'] : 'Direct Transfer',
                    'recipient' => $transfer['recipient'],
                    'amount' => '<span class="rzp-currency">₹</span> ' . (int)round($transfer['amount'] / 100),
                    'created_at' => date("d F Y h:i A", strtotime('+5 hour +30 minutes', $transfer['created_at'])),
                    'transfer_status' => (empty($transfer['status'])) ? '' : ucwords($transfer['status']),
                    'settlement_status' => (empty($transfer['settlement_status'])) ? 'Not Applicable' : ucwords($transfer['settlement_status']),
                    'settlement_id' => (!empty($transfer['recipient_settlement_id'])) ? '<a href="?page=razorpaySettlementTransfers&id=' . $transfer['recipient_settlement_id'] . '">' . $transfer['recipient_settlement_id'] . '</a>' : '--',

                );
            }
        }
        return $items;
    }

    function rzpTransferDetails()
    {
        if (empty(sanitize_text_field($_REQUEST['id'])) || null == (sanitize_text_field($_REQUEST['id']))) {

            wp_die('<div class="error notice"><p>This page consist some request parameters to view response</p></div>');

        } else {

            $transferId = sanitize_text_field($_REQUEST['id']);
            
            $api = $this->fetchRazorpayApiInstance();

            $url = "transfers/" . $transferId . "/?expand[]=recipient_settlement";

            $transferDetail = $api->request->request("GET", $url);

            $prev_url = admin_url('admin.php?page=razorpayRouteWoocommerce');

            $show = "jQuery('.rev_trf_overlay').show()";
            $hide = "jQuery('.rev_trf_overlay').hide()";

            $showSetl = "jQuery('.trf_settlement_overlay').show()";
            $hideSetl = "jQuery('.trf_settlement_overlay').hide()";

            $sourceKey = explode('_',$transferDetail['source']) ;
            $source = (($sourceKey[0] == 'order') || ($sourceKey[0] == 'pay')) ? $transferDetail['source'] : "Direct Transfer" ;

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
                            <div class="col-sm-8 panel-value">' . $transferDetail["id"] . '</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 panel-label">Source</div>
                            <div class="col-sm-8 panel-value">' . $source . '</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 panel-label">Recipient</div>
                            <div class="col-sm-8 panel-value">' . $transferDetail['recipient'] . '</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 panel-label">Amount</div>
                            <div class="col-sm-8 panel-value"><span class="rzp-currency">₹ </span>' . (int)round($transferDetail['amount'] / 100) . '</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 panel-label">Transfer Status</div>
                            <div class="col-sm-8 panel-value"><span class="text-info">';
            if(!isset($transferDetail['status'])){ echo ''; }else{ echo ucwords($transferDetail['status']); }
            echo '</span>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 panel-label">Settlement Status</div>
                            <div class="col-sm-8 panel-value">';
            if(isset($transferDetail['settlement_status'])) {
                if ($transferDetail['settlement_status'] == 'on_hold' && $transferDetail['on_hold_until'] != '') {
                    echo '<span class="text-warning trf-status">Scheduled for ' . date("d M Y", $transferDetail['on_hold_until']) . '</span>';
                    echo '<span><a href="javascript:void(0);" onclick="' . $showSetl . '" >Change</a></span>';
                } elseif ($transferDetail['settlement_status'] == 'on_hold' && $transferDetail['on_hold_until'] == '') {
                    echo '<span class="text-danger trf-status">On Hold</span>';
                    echo '<span><a href="javascript:void(0);" onclick="' . $showSetl . '" >Change</a></span>';
                } elseif ($transferDetail['settlement_status'] == 'pending') {
                    echo '<span class="text-success trf-status">Pending</span>';
                    echo '<span><a href="javascript:void(0);" onclick="' . $showSetl . '" >Change</a></span>';
                } elseif ($transferDetail['settlement_status'] == '') {
                    echo '<span>Not Applicable</span>';
                } else {
                    echo '<span>' . ucwords($transferDetail['settlement_status']) . '</span>';
                }
            }

            echo '</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 panel-label">Amount Reversed</div>
                            <div class="col-sm-8 panel-value"><span class="rzp-currency">₹ </span>' . (int)round($transferDetail['amount_reversed'] / 100);
            if ($transferDetail["amount_reversed"] < $transferDetail["amount"]) {
                echo '<button onclick="' . $show . '" class="btn btn-primary">Create Reversal</button>';
            }
            echo '</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 panel-label">Created on</div>
                            <div class="col-sm-8 panel-value">' . date("d F Y h:i A", strtotime('+5 hour +30 minutes', $transferDetail['created_at'])) . '</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>';

            $reverseTransferModal = '<div class="rev_trf_overlay">
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
                                <input name="reversal_amount" type="number" autocomplete="off" class="form-control" id="trf_reversal_amount" placeholder="Enter the reversal amount" value="'.(int)round(($transferDetail['amount'] - $transferDetail['amount_reversed'])/ 100).'">
                                </div>
                                </div>
                                <p class="text-danger" id="trf_reverse_text"></p>
                                </div>

                                <div>

                                <button type="submit" onclick="' . $hide . '" name="create_reversal" id="reverse_transfer_btn" class="btn btn-primary">Create Reversal</button>
                                <input type="hidden" name="action" value="rzp_reverse_transfer">
                                <input type="hidden" name="transfer_id" value="' . $transferDetail['id'] . '">
                                <input type="hidden" name="transfer_amount" value="' . $transferDetail['amount'] . '">

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
            echo $reverseTransferModal;

            $onHoldStatus = $settleStatus = $holdUntilStatus = $holdDate = $disableDate = '';

            if ($transferDetail['on_hold_until'] != '') {
                $holdDate = date("Y-m-d", $transferDetail['on_hold_until']);
                $holdUntilStatus = "checked";
            }
            if ($transferDetail['on_hold'] == 1 && $transferDetail['on_hold_until'] == '') {
                $onHoldStatus = "checked";
                $disableDate = "disabled";
            }
            if ($transferDetail['on_hold'] == 0 && $transferDetail['recipient_settlement_id'] == '') {
                $settleStatus = "checked";
                $disableDate = "disabled";
            }

            $transferSettlementModal = '<div class="trf_settlement_overlay">
                <div class="modal" id="transferModal" >
                    <div class="modal-dialog">
                        <!-- Modal content-->
                        <div class="modal-content">
                            <div class="modal-header">
                            <h4 class="modal-title">Change Settlement Schedule</h4>
                                <button type="button" class="close" data-dismiss="modal" onclick="' . $hideSetl . '">&times;</button>

                            </div>
                            <div class="modal-body">
                            <form method="POST" action="' . esc_url(admin_url('admin-post.php')) . '">
                                <div class="RadioButton">
                                    <label><input type="radio" name="on_hold" class="enable_hold_until" value="on_hold_until" ' . $holdUntilStatus . '>
                                        <span>Schedule settlement on</span>
                                    </label>
                                    <input type="date" name="hold_until" id="hold_until"  min="' . date('Y-m-d', strtotime('+4 days')) . '" value="' . $holdDate . '" ' . $disableDate . ' >
                                </div>
                                <div class="RadioButton">
                                    <label><input type="radio" name="on_hold" class="disable_hold_until" value="1" ' . $onHoldStatus . '>
                                        <span>Put on hold</span>
                                    </label>
                                </div>
                                <div class="RadioButton">
                                    <label><input type="radio" name="on_hold" class="disable_hold_until" value="0" ' . $settleStatus . ' >
                                        <span>Settle in next slot</span>
                                    </label>
                                </div>

                                <div>

                                <button type="submit" onclick="' . $hideSetl . '" name="update_setl_status"  class="btn btn-primary">Save</button>
                                <input type="hidden" name="action" value="rzp_settlement_change">
                                <input type="hidden" name="transfer_id" value="' . $transferDetail['id'] . '">
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
            echo $transferSettlementModal;
        }
    }

    function routeHeader()
    {

        ?>
        <header id="rzp-route-header" class="rzp-route-header">
            <a <?php if ($_GET['page'] == "razorpayRouteWoocommerce") { ?> class="active" <?php } ?> href="?page=razorpayRouteWoocommerce">Transfers</a>
            <a <?php if ($_GET['page'] == "razorpayRoutePayments") { ?> class="active" <?php } ?> href="?page=razorpayRoutePayments">Payments</a>
            <a <?php if ($_GET['page'] == "razorpayRouteReversals") { ?> class="active" <?php } ?> href="?page=razorpayRouteReversals">Reversals</a>

        </header>
        <?php
    }

    function rzpTransferReversals()
    {
        echo '<div>
            <div class="wrap route-container">';

        $this->routeHeader();

        $this->prepareReversalItems();

        echo '<form method="get">
            <input type="hidden" name="page" value="razorpayRouteReversals">';

        $this->display();

        echo '</form></div>
            </div>';
    }

    function prepareReversalItems()
    {

        $perPage = 10;
        $currentPage = $this->get_pagenum();
        if (1 < $currentPage) {
            $offset = $perPage * ($currentPage - 1);
        } else {
            $offset = 0;
        }

        $reversalPage = $this->getReversalItems($perPage);
        $count = count($reversalPage);
        $reversalPages = array();

        for ($i = 0; $i < $count; $i++) {
            if ($i >= $offset && $i < $offset + $perPage) {
                $reversalPages[] = $reversalPage[$i];
            }
        }
        $columns = $this->getReversalColumns();
        $hidden = array();
        $sortable = array();
        $this->_column_headers = array($columns, $hidden, $sortable);

        $this->items = $reversalPages;

        // Set the pagination
        $this->set_pagination_args(array(
            'total_items' => $count,
            'per_page' => $perPage,
            'total_pages' => ceil($count / $perPage)
        ));
    }


    function getReversalColumns()
    {
        $columns = array(
            'reversal_id' => __('Reversal Id'),
            'transfer_id' => __('Transfer Id'),
            'amount' => __('Amount'),
            'created_at' => __('Created At'),
        );
        return $columns;
    }

    function getReversalItems($count)
    {
        $result = array();
        
        $api = $this->fetchRazorpayApiInstance();

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

    function rzpRoutePayments()
    {
        echo '<div>
            <div class="wrap route-container">';

        $this->routeHeader();

        $this->preparePaymentItems();

        echo '<form method="get">
            <input type="hidden" name="page" value="razorpayRoutePayments">';
        echo '<p class="pay_search_label">Search here for payments of linked account</p>';
        $this->search_box('search', 'search_id');
        $this->display();

        echo '</form></div>
            </div>';
    }

    function preparePaymentItems()
    {

        $perPage = 10;
        $currentPage = $this->get_pagenum();

        if (1 < $currentPage) {
            $offset = $perPage * ($currentPage - 1);
        } else {
            $offset = 0;
        }

        $accId = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';

        $paymentPage = $this->getPaymentItems($perPage, $accId);
        $count = count($paymentPage);
        $paymentPages = array();

        for ($i = 0; $i < $count; $i++) {
            if ($i >= $offset && $i < $offset + $perPage) {
                $paymentPages[] = $paymentPage[$i];
            }
        }
        $columns = $this->getPaymentColumns();
        $hidden = array();
        $sortable = array();
        $this->_column_headers = array($columns, $hidden, $sortable);

        $this->items = $paymentPages;

        // Set the pagination
        $this->set_pagination_args(array(
            'total_items' => $count,
            'per_page' => $perPage,
            'total_pages' => ceil($count / $perPage)
        ));
    }

    function getPaymentColumns()
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

    function getPaymentItems($count, $accId)
    {
        $result = array();
        
        $api = $this->fetchRazorpayApiInstance();

        try {
            $url = "payments/";
            $data = array(
                'count' => 100,
            );

            if (isset($accId) && $accId != '') {
                $api->request->addHeader('X-Razorpay-Account', $accId);
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
                    'payment_id' => ($accId != "") ? $payment['id'] : '<a href="?page=razorpayPaymentsView&id=' . $payment['id'] . '">' . $payment['id'] . '</a>',
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

    function rzpSettlementTransfers()
    {
        if (empty(sanitize_text_field($_REQUEST['id'])) || null == (sanitize_text_field($_REQUEST['id']))) {
            wp_die("This page consist some request parameters to view response");
        } else {

            $settlementId = sanitize_text_field($_REQUEST['id']);
            
            $api = $this->fetchRazorpayApiInstance();

            try {
                $data = array(
                    'recipient_settlement_id' => $settlementId
                );
                $settlTransfers = $api->transfer->all($data);

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
                    Settlement ID :  <strong>' . $settlementId . '</strong>
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
            foreach ($settlTransfers['items'] as $transfer) {
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

    function rzpPaymentDetails()
    {
        if (empty(sanitize_text_field($_REQUEST['id'])) || null == (sanitize_text_field($_REQUEST['id']))) {
            wp_die("This page consist some request parameters to view response");
        } else {

            $paymentId = sanitize_text_field($_REQUEST['id']);
            
            $api = $this->fetchRazorpayApiInstance();

            $paymentDetail = $api->payment->fetch($paymentId);

            $paymentTransfers = $api->payment->fetch($paymentId)->transfers();

            $prev_url = admin_url('admin.php?page=razorpayRoutePayments');

            $show = "jQuery('.overlay').show()";
            $hide = "jQuery('.overlay').hide()";

            $trfDetails = '';
            $transferredAmount = 0;
            if ($paymentTransfers['count'] != 0) {

                $trfDetails = '<div class="button-items-detail">
                    <div class="row">
                        <div class="col">Transfer Id</div>
                        <div class="col">Amount</div>
                        <div class="col">Created At</div>
                    </div>';

                foreach ($paymentTransfers['items'] as $transfer) {
                    $transferredAmount = $transferredAmount + $transfer['amount'];

                    $trfDetails .= '<div class="row panel-value">
                                            <div class="col"><a href="?page=razorpayTransfers&id='.$transfer['id'].'">' . $transfer['id'] . '</a></div>
                                            <div class="col"><span class="rzp-currency">₹ </span>' . (int)round($transfer['amount'] / 100) . '</div>
                                            <div class="col">' . date("d F Y h:i A", strtotime('+5 hour +30 minutes', $transfer['created_at'])) . '</div>
                                        </div>';
                }
                $trfDetails .= '</div> ';
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
                                <div class="col-sm-8 panel-value">' . $paymentDetail["id"] . '</div>
                            </div>
                            <div class="row">
                                <div class="col-sm-4 panel-label">Amount</div>
                                <div class="col-sm-8 panel-value"><span class="rzp-currency">₹ </span>' . (int)round($paymentDetail['amount'] / 100) . '</div>
                            </div>
                            <div class="row">
                                <div class="col-sm-4 panel-label">Status</div>
                                <div class="col-sm-8 panel-value">' . ucfirst($paymentDetail['status']) . '</div>
                            </div>
                            <div class="row">
                                <div class="col-sm-4 panel-label">Order ID</div>
                                <div class="col-sm-8 panel-value">' . $paymentDetail['order_id'] . '</div>
                            </div>
                            <div class="row">
                                <div class="col-sm-4 panel-label">Transfers</div>
                                <div class="col-sm-8 panel-value">';

            if ($paymentDetail['status'] == 'created') {
                echo '--';
            } else {
                $trfCount = ($paymentTransfers['count'] == 0) ? 'No' : $paymentTransfers['count'];
                echo '<span>' . $trfCount . ' transfers created</span>';
            }

            if ($paymentDetail['status'] == 'captured' && $paymentDetail['amount'] > $transferredAmount) {
                echo '<button onclick="' . $show . '" class="button">Create Transfer</button>';
            }

            echo $trfDetails;
            echo '</div>
                            </div>
                            <div class="row">
                                <div class="col-sm-4 panel-label">Created on</div>
                                <div class="col-sm-8 panel-value">' . date("d F Y h:i A", strtotime('+5 hour +30 minutes', $paymentDetail['created_at'])) . '</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>';

            $paymentTransferModal = '<div class="overlay">
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
                                <input name="pay_trf_amount" type="number" autocomplete="off" class="form-control" id="payment_trf_amount" placeholder="Enter amount" value="'.(int)round(($paymentDetail['amount'] - $transferredAmount)/ 100).'">
                                </div>
                                </div>
                                <p class="text-danger" id="payment_trf_error"></p>
                                </div>
                                <div class="form-group"><label>Linked Account Number</label>
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
                                <input type="hidden" name="payment_id" value="' . $paymentDetail['id'] . '">
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
            echo $paymentTransferModal;
        }
    }

    function  checkDirectTransferFeature(){

        $api = $this->fetchRazorpayApiInstance();

        $base_url = $api->getBaseUrl();

        $url = $base_url.'accounts/me/features';
        $context = stream_context_create(array(
            'http' => array(
                'header'  => "Authorization: Basic " . base64_encode($this->fetchSetting())
            ),
            "ssl"=>array(
                "verify_peer"=>false,
                "verify_peer_name"=>false,
            ),
        ));

        $directTransferBtn = '';
        $featuresData = $this->fetchFileContents($url, $context);
        if($featuresData !== false) {
            $apiResponse = json_decode($featuresData, true);
            foreach ($apiResponse['assigned_features'] as $features) {
                if ($features['name'] == 'direct_transfer') {

                    $show = "jQuery('.overlay').show()";
                    $directTransferBtn = '<button class="btn btn-primary" onclick="' . $show . '">Create Direct Transfer</button>';

                }
            }
        }
        echo  $directTransferBtn;
    }

}

function razorpayRouteWoocommerce()
{
    $rzpRoute = new RZP_Route();
    $rzpRoute->rzpTransfers();
}

function razorpayTransfers(){
    $rzpRoute = new RZP_Route();
    $rzpRoute->rzpTransferDetails();
}

function razorpayRouteReversals(){
    $rzpRoute = new RZP_Route();
    $rzpRoute->rzpTransferReversals();
}

function razorpayRoutePayments(){
    $rzpRoute = new RZP_Route();
    $rzpRoute->rzpRoutePayments();
}

function razorpaySettlementTransfers(){
    $rzpRoute = new RZP_Route();
    $rzpRoute->rzpSettlementTransfers();
}

function razorpayPaymentsView(){
    $rzpRoute = new RZP_Route();
    $rzpRoute->rzpPaymentDetails();
}

function adminEnqueueScriptsFunc()
{
    //$name, $src, $dependencies, $version, $in_footer
    wp_enqueue_script( 'route-script', plugin_dir_url(dirname(__FILE__)) . 'public/js/woo_route.js', array( 'jquery' ), null, true );
    wp_enqueue_script( 'bootstrap-script', plugin_dir_url(dirname(__FILE__)) . 'public/js/bootstrap.min.js', array( 'jquery' ), null, true );

    wp_register_style('bootstrap-css', plugin_dir_url(dirname(__FILE__))  . 'public/css/bootstrap.min.css',
        null, null);
    wp_register_style('woo_route-css', plugin_dir_url(dirname(__FILE__))  . 'public/css/woo_route.css',
        null, null);
    wp_enqueue_style('bootstrap-css');
    wp_enqueue_style('woo_route-css');

    wp_enqueue_script('jquery');
}

// Add a custom tab in edit product page settings
function transferDataTab( $tabs ) {
    $tabs['route'] = array(
        'label' => __( 'Razorpay Route', 'my_theme_domain' ),
        'target' => 'rzp_transfer_product_data',
        'priority' => 11,
    );
    return $tabs;
}

// Add the content to the custom tab in edit product page settings
function productTransferDataFields()
{
    global $woocommerce, $post;
    echo '<div class="rzp_transfer_custom_field panel woocommerce_options_panel" id="rzp_transfer_product_data">';

    // Radio Buttons field
    woocommerce_wp_radio( array(
        'id'            => 'rzp_transfer_from',
        'wrapper_class' => 'show_if_simple',
        'label'         => '',
        'description'   => __( 'You can transfer funds to linked accounts from order or payments', 'my_theme_domain' ),
        'desc_tip'      => true,
        'options'       => array(
            'from_order'       => __('Transfer from Order'),
            'from_payment'     => __('Transfer from Payment'),

        )
    ) );
    $LA_number_arr =   get_post_meta($post->ID, 'LA_number', true);
    $LA_amount_arr =   get_post_meta($post->ID, 'LA_transfer_amount', true);
    $LA_trf_status_arr =   get_post_meta($post->ID, 'LA_transfer_status', true);


    if (isset($LA_number_arr) && is_array($LA_number_arr) && isset($LA_amount_arr) && is_array($LA_amount_arr)) {
        $LA_transfer_count = count($LA_number_arr);
        for ($i = 0; $i < $LA_transfer_count; $i++) {
            if (!empty($LA_number_arr[$i]) && !empty($LA_amount_arr[$i])) {
                echo '<p><input type="text" name="LA_number[]" placeholder="Linked Account Number" value="' . $LA_number_arr[$i] . '">
                <input type="number" name="LA_transfer_amount[]" class="LA_transfer_amount" placeholder="Amount" value="' . $LA_amount_arr[$i] . '">
                <label class="trf_settlement_label">Hold Settlement:</label>  <select name="LA_transfer_status[]"><optgroup label="On Hold">';
                echo '<option value="1"';
                if ($LA_trf_status_arr[$i] == 1) {
                    echo "selected";
                }
                echo '> Yes</option><option value="0"';
                if ($LA_trf_status_arr[$i] == 0) {
                    echo "selected";
                }
                echo ' > No</option></optgroup></select> </p>';
            }

        }
    }

    echo '<p class="input_fields_wrap"> <a class="add_field_button button-secondary">Add Field</a>
            <input type="text" name="LA_number[]"  placeholder="Linked Account Number">
            <input type="number" name="LA_transfer_amount[]"  class="LA_transfer_amount" placeholder="Amount" >
            <label class="trf_settlement_label">Hold Settlement:</label>
            <select name="LA_transfer_status[]"><optgroup label="On Hold">
                <option value="1"> Yes</option>
                <option value="0" selected> No</option></optgroup>
            </select>
         </p>
    </div>
          <p class="text-danger" id="transfer_err_msg"></p>';

}

// Save the data of the custom tab in edit product page settings

function woocommerce_process_transfer_meta_fields_save( $post_id ){

    $wcRadio = isset( $_POST['rzp_transfer_from'] ) ? $_POST['rzp_transfer_from'] : '';
    update_post_meta( $post_id, 'rzp_transfer_from', $wcRadio );

    if(isset($_POST['LA_number']) && !empty($_POST['LA_number'])) {
        update_post_meta( $post_id, 'LA_number', $_POST['LA_number'] );
    }

    if(isset($_POST['LA_transfer_amount']) && !empty($_POST['LA_transfer_amount'])) {
        update_post_meta( $post_id, 'LA_transfer_amount', $_POST['LA_transfer_amount'] );
    }
    if(isset($_POST['LA_transfer_status']) && !empty($_POST['LA_transfer_status'])) {
        update_post_meta( $post_id, 'LA_transfer_status', $_POST['LA_transfer_status'] );
    }

}

//fetch transfers of order/payment in order edit page

function paymentTransferMetaBox() {
    add_meta_box(
        'rzp_trf_payment_meta',
        esc_html__( 'Razorpay transfers from Order / Payment', 'text-domain' ),
        'renderPaymentTransferMetaBox',
        'shop_order', // shop_order is the post type of the admin order page
        'normal', // change to 'side' to move box to side column
        'low'
    );

    add_meta_box(
        'rzp_payment_meta',
        esc_html__( 'Razorpay Payment ID', 'text-domain' ),
        'renderPaymentMetaBox',
        'shop_order', // shop_order is the post type of the admin order page
        'normal', // change to 'side' to move box to side column
        'low'
    );

}

function renderPaymentTransferMetaBox() {
    global $woocommerce, $post;
    $orderId= $post->ID;
    $rzpPaymentId = get_post_meta($orderId,'_transaction_id',true);

    $rzp = new WC_Razorpay();

    $api = $rzp->getRazorpayApiInstance();
    $url = "payments/".$rzpPaymentId."/transfers";

    $transfersData = $api->request->request("GET", $url);

    if(!empty($transfersData['items'])) {
        echo '<p><b>NOTE: </b>When refunding a payment that has transfers, create reversal from here and then refund the payment to the customer</p>';

        echo '<table class="wp-list-table widefat fixed striped table-view-list wp_list_test_links">
        <thead>
            <tr>
                <th>Transfer Id</th>
                <th>Source</th>
                <th>Recipient</th>
                <th>Amount</th>
                <th>Created At</th>
            </tr>
        </thead>
        <tbody>';

        foreach ($transfersData['items'] as $transfer) {
            echo '<tr>
                        <td><a href="?page=razorpayTransfers&id=' . $transfer['id'] . '">' . $transfer['id'] . '</a></td>
                        <td>' . $transfer['source'] . '</td>
                        <td>' . $transfer['recipient'] . '</td>
                        <td><span class="rzp-currency">₹ </span>' . (int)round($transfer['amount'] / 100) . '</td>
                        <td>' . date("d F Y H:i A", $transfer['created_at']) . '</td>
                    </tr>';
        }

        echo '</tbody>
    </table>';
    }else{
        echo '<p>No transfers found</p>';
    }

}

function renderPaymentMetaBox(){

    global $woocommerce, $post;
    $orderId= $post->ID;
    $rzpPaymentId = get_post_meta($orderId,'_transaction_id',true);

    echo '<p>'.$rzpPaymentId.' <span><a href="?page=razorpayPaymentsView&id='.$rzpPaymentId.'"><input type="button" class="button" value="View"></a></span></p>';

}

function razorpayDirectTransfer()
{
    $routeAction = new RZP_Route_Action();

    $routeAction->directTransfer();
}

function razorpayReverseTransfer()
{
    $routeAction = new RZP_Route_Action();

    $routeAction->reverseTransfer();
}

function razorpaySettlementUpdate()
{
    $routeAction = new RZP_Route_Action();

    $routeAction->updateTransferSettlement();
}

function razorpayPaymentTransfer()
{
    $routeAction = new RZP_Route_Action();

    $routeAction->createPaymentTransfer();
}
