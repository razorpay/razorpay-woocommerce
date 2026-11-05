<?php

if (!defined('ABSPATH'))
{
    exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

if (!class_exists('WP_List_Table'))
{
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

function rzpGetAbandonedCheckoutsTableName()
{
    global $wpdb;

    return $wpdb->prefix . 'rzp_abandoned_checkouts';
}

function rzpCreateAbandonedCheckoutsTable()
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $tableName = rzpGetAbandonedCheckoutsTableName();

    $sql = "CREATE TABLE IF NOT EXISTS $tableName (
        `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        `wc_order_id` bigint(20) NOT NULL,
        `rzp_order_id` varchar(64) NOT NULL,
        `customer_name` varchar(255) NULL,
        `customer_email` varchar(255) NULL,
        `customer_phone` varchar(32) NULL,
        `customer_details` longtext NULL,
        `cart_items` longtext NULL,
        `cart_total` decimal(16,4) NOT NULL DEFAULT 0,
        `currency` varchar(8) NULL,
        `checkout_type` varchar(32) NOT NULL DEFAULT 'magic_checkout',
        `status` varchar(20) NOT NULL DEFAULT 'abandoned',
        `recovered_at` datetime NULL,
        `created_at` datetime NOT NULL,
        `updated_at` datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY wc_order_id (wc_order_id),
        KEY rzp_order_id (rzp_order_id),
        KEY status (status)
    ) " . $wpdb->get_charset_collate() . ";";

    dbDelta($sql);
}

$rzpAbandonedCheckoutsSetup = get_option('rzp_abandoned_checkouts_setup');

if (($rzpAbandonedCheckoutsSetup === 'yes') === false)
{
    global $wpdb;

    try
    {
        rzpCreateAbandonedCheckoutsTable();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', rzpGetAbandonedCheckoutsTableName())) === rzpGetAbandonedCheckoutsTableName())
        {
            update_option('rzp_abandoned_checkouts_setup', 'yes');
            rzpLogInfo('Razorpay abandoned checkouts table created.');
        }
    }
    catch (Throwable $e)
    {
        rzpLogError('Razorpay abandoned checkouts table creation failed: ' . $e->getMessage());
        delete_option('rzp_abandoned_checkouts_setup');
    }
}

function rzpSaveAbandonedCheckout($razorpayData)
{
    global $wpdb;

    $response = array(
        'status'  => false,
        'message' => '',
    );

    if (empty($razorpayData['id']) || empty($razorpayData['receipt']))
    {
        $response['message'] = 'razorpay order id or receipt missing';

        return $response;
    }

    $tableName = rzpGetAbandonedCheckoutsTableName();

    $wcOrderId = absint($razorpayData['receipt']);
    $order     = wc_get_order($wcOrderId);

    $customerDetails = (isset($razorpayData['customer_details']) && is_array($razorpayData['customer_details'])) ? $razorpayData['customer_details'] : array();
    $billingAddress  = (isset($customerDetails['billing_address']) && is_array($customerDetails['billing_address'])) ? $customerDetails['billing_address'] : array();
    $shippingAddress = (isset($customerDetails['shipping_address']) && is_array($customerDetails['shipping_address'])) ? $customerDetails['shipping_address'] : array();

    $customerName  = $shippingAddress['name'] ?? ($billingAddress['name'] ?? '');
    $customerEmail = $customerDetails['email'] ?? '';
    $customerPhone = $customerDetails['contact'] ?? ($shippingAddress['contact'] ?? '');

    if ($order instanceof WC_Order)
    {
        if (empty($customerEmail) === true)
        {
            $customerEmail = $order->get_billing_email();
        }

        if (empty($customerPhone) === true)
        {
            $customerPhone = $order->get_billing_phone();
        }

        if (empty(trim((string) $customerName)) === true)
        {
            $customerName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        }
    }

    $cartItems = array();

    if ($order instanceof WC_Order)
    {
        foreach ($order->get_items() as $item)
        {
            $cartItems[] = array(
                'name'         => $item->get_name(),
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'quantity'     => $item->get_quantity(),
                'total'        => $item->get_total(),
            );
        }
    }

    $cartTotal = 0;

    if (isset($razorpayData['amount']) && $razorpayData['amount'] > 0)
    {
        $cartTotal = ((int) $razorpayData['amount']) / 100;
    }

    $currentTime = current_time('mysql');

    $data = array(
        'wc_order_id'     => $wcOrderId,
        'rzp_order_id'    => sanitize_text_field($razorpayData['id']),
        'customer_name'   => sanitize_text_field($customerName),
        'customer_email'  => sanitize_email($customerEmail),
        'customer_phone'  => sanitize_text_field($customerPhone),
        'customer_details' => wp_json_encode(array(
            'billing'  => $billingAddress,
            'shipping' => $shippingAddress,
        )),
        'cart_items'    => wp_json_encode($cartItems),
        'cart_total'    => (float) $cartTotal,
        'currency'      => isset($razorpayData['currency']) ? sanitize_text_field($razorpayData['currency']) : get_woocommerce_currency(),
        'checkout_type' => 'magic_checkout',
        'status'        => 'abandoned',
        'created_at'    => $currentTime,
        'updated_at'    => $currentTime,
    );

    $existingId = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT id FROM $tableName WHERE rzp_order_id = %s", $data['rzp_order_id'])
    );

    if ($existingId > 0)
    {
        unset($data['created_at'], $data['status']);

        $updated = $wpdb->update($tableName, $data, array('id' => $existingId));

        $response['status']  = ($updated !== false);
        $response['message'] = $response['status'] ? 'abandoned checkout updated' : $wpdb->last_error;
    }
    else
    {
        $inserted = $wpdb->insert($tableName, $data);

        $response['status']  = ($inserted !== false);
        $response['message'] = $response['status'] ? 'abandoned checkout saved' : $wpdb->last_error;
    }

    if ($response['status'] === false)
    {
        rzpLogError('Razorpay abandoned checkout save failed: ' . $response['message']);
    }

    return $response;
}

function rzpMarkAbandonedCheckoutRecovered($wcOrderId)
{
    global $wpdb;

    $wcOrderId = absint($wcOrderId);

    if ($wcOrderId === 0)
    {
        return;
    }

    $tableName = rzpGetAbandonedCheckoutsTableName();

    $currentTime = current_time('mysql');

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE $tableName SET status = %s, recovered_at = %s, updated_at = %s WHERE wc_order_id = %d AND status = %s",
            'recovered',
            $currentTime,
            $currentTime,
            $wcOrderId,
            'abandoned'
        )
    );
}

function rzpDeleteAbandonedCheckouts(array $ids)
{
    global $wpdb;

    $ids = array_filter(array_map('absint', $ids));

    if (empty($ids) === true)
    {
        return;
    }

    $tableName = rzpGetAbandonedCheckoutsTableName();

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));

    $wpdb->query(
        $wpdb->prepare("DELETE FROM $tableName WHERE id IN ($placeholders)", $ids)
    );
}

function rzpGetAbandonedCheckoutOrderEditUrl($wcOrderId)
{
    if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && OrderUtil::custom_orders_table_usage_is_enabled())
    {
        return admin_url('admin.php?page=wc-orders&action=edit&id=' . $wcOrderId);
    }

    return admin_url('post.php?post=' . $wcOrderId . '&action=edit');
}

function rzpFormatAbandonedCheckoutAddress($address)
{
    if (empty($address) || !is_array($address))
    {
        return '';
    }

    $fields = array('name', 'contact', 'line1', 'line2', 'city', 'state', 'country', 'zipcode');

    $parts = array();

    foreach ($fields as $field)
    {
        if (isset($address[$field]) && $address[$field] !== '')
        {
            $parts[] = $address[$field];
        }
    }

    return implode(', ', $parts);
}

class RZP_Abandoned_Checkouts_Table extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct(array(
            'singular' => 'abandoned_checkout',
            'plural'   => 'rzp_abandoned_checkouts',
            'ajax'     => false,
        ));
    }

    public function get_columns()
    {
        return array(
            'cb'             => '<input type="checkbox" />',
            'customer'       => 'Customer',
            'customer_phone' => 'Phone',
            'cart_total'     => 'Cart Total',
            'items'          => 'Items',
            'status'         => 'Status',
            'wc_order_id'    => 'Order',
            'created_at'     => 'Abandoned At',
        );
    }

    protected function get_sortable_columns()
    {
        return array(
            'cart_total' => array('cart_total', false),
            'status'     => array('status', false),
            'created_at' => array('created_at', true),
        );
    }

    protected function get_bulk_actions()
    {
        return array(
            'delete' => 'Delete',
        );
    }

    public function no_items()
    {
        echo 'No abandoned checkouts found.';
    }

    protected function rzpGetSearchTerm()
    {
        return isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
    }

    protected function rzpGetStatusFilter()
    {
        $status = isset($_REQUEST['status']) ? sanitize_key(wp_unslash($_REQUEST['status'])) : 'all';

        return in_array($status, array('all', 'abandoned', 'recovered'), true) ? $status : 'all';
    }

    protected function rzpBuildWhereClause(array &$args)
    {
        global $wpdb;

        $where = ' WHERE 1=1';

        $status = $this->rzpGetStatusFilter();

        if ($status !== 'all')
        {
            $where .= ' AND status = %s';
            $args[] = $status;
        }

        $search = $this->rzpGetSearchTerm();

        if ($search !== '')
        {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s)';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }

        return $where;
    }

    public function prepare_items()
    {
        global $wpdb;

        $tableName = rzpGetAbandonedCheckoutsTableName();

        $perPage = 20;
        $currentPage = $this->get_pagenum();

        $args = array();
        $where = $this->rzpBuildWhereClause($args);

        $countQuery = "SELECT COUNT(*) FROM $tableName$where";

        if (empty($args) === false)
        {
            $countQuery = $wpdb->prepare($countQuery, $args);
        }

        $totalItems = (int) $wpdb->get_var($countQuery);

        $allowedOrderBy = array('cart_total', 'status', 'created_at');
        $orderBy = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'created_at';
        $orderBy = in_array($orderBy, $allowedOrderBy, true) ? $orderBy : 'created_at';

        $order = 'DESC';

        if (isset($_GET['order']) && sanitize_key(wp_unslash($_GET['order'])) === 'asc')
        {
            $order = 'ASC';
        }

        $offset = ($currentPage - 1) * $perPage;

        $itemsQuery = "SELECT * FROM $tableName$where ORDER BY $orderBy $order LIMIT %d OFFSET %d";

        $itemsArgs = array_merge($args, array($perPage, $offset));

        $this->items = $wpdb->get_results($wpdb->prepare($itemsQuery, $itemsArgs), ARRAY_A);

        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns(), 'customer');

        $this->set_pagination_args(array(
            'total_items' => $totalItems,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($totalItems / $perPage),
        ));
    }

    protected function get_views()
    {
        global $wpdb;

        $tableName = rzpGetAbandonedCheckoutsTableName();

        $counts = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status = 'abandoned' THEN 1 ELSE 0 END), 0) AS abandoned,
                COALESCE(SUM(CASE WHEN status = 'recovered' THEN 1 ELSE 0 END), 0) AS recovered
            FROM $tableName",
            ARRAY_A
        );

        $counts = array_map('intval', (array) $counts);

        $search = $this->rzpGetSearchTerm();
        $current = $this->rzpGetStatusFilter();

        $baseUrl = admin_url('admin.php');

        $views = array();

        $views['all'] = sprintf(
            '<a href="%s" class="%s">All <span class="count">(%s)</span></a>',
            esc_url(add_query_arg(array_filter(array('page' => 'rzp-abandoned-checkouts', 's' => $search), 'strlen'))),
            ($current === 'all') ? 'current' : '',
            number_format_i18n($counts['total'])
        );

        $views['abandoned'] = sprintf(
            '<a href="%s" class="%s">Abandoned <span class="count">(%s)</span></a>',
            esc_url(add_query_arg(array_filter(array('page' => 'rzp-abandoned-checkouts', 'status' => 'abandoned', 's' => $search), 'strlen'))),
            ($current === 'abandoned') ? 'current' : '',
            number_format_i18n($counts['abandoned'])
        );

        $views['recovered'] = sprintf(
            '<a href="%s" class="%s">Recovered <span class="count">(%s)</span></a>',
            esc_url(add_query_arg(array_filter(array('page' => 'rzp-abandoned-checkouts', 'status' => 'recovered', 's' => $search), 'strlen'))),
            ($current === 'recovered') ? 'current' : '',
            number_format_i18n($counts['recovered'])
        );

        return $views;
    }

    protected function column_cb($item)
    {
        return sprintf('<input type="checkbox" name="abandoned_checkout[]" value="%s" />', esc_attr($item['id']));
    }

    protected function column_customer($item)
    {
        $name  = (string) $item['customer_name'];
        $email = (string) $item['customer_email'];

        $viewUrl = admin_url('admin.php?page=rzp-abandoned-checkouts&view=' . $item['id']);

        $deleteUrl = wp_nonce_url(
            admin_url('admin.php?page=rzp-abandoned-checkouts&action=delete&id=' . $item['id']),
            'rzp-delete-abandoned-checkout_' . $item['id']
        );

        $actions = array(
            'view'   => sprintf('<a href="%s">View</a>', esc_url($viewUrl)),
            'delete' => sprintf('<a href="%s">Delete</a>', esc_url($deleteUrl)),
        );

        $customerHtml = '<strong>' . esc_html(($name !== '') ? $name : '(no name)') . '</strong><br />';

        if ($email !== '')
        {
            $customerHtml .= sprintf('<a href="mailto:%s">%s</a>', esc_attr($email), esc_html($email));
        }
        else
        {
            $customerHtml .= '&mdash;';
        }

        return $customerHtml . $this->row_actions($actions);
    }

    protected function column_customer_phone($item)
    {
        return !empty($item['customer_phone']) ? esc_html($item['customer_phone']) : '&mdash;';
    }

    protected function column_cart_total($item)
    {
        return wp_kses_post(wc_price((float) $item['cart_total'], array('currency' => $item['currency'])));
    }

    protected function column_items($item)
    {
        $cartItems = json_decode((string) $item['cart_items'], true);

        if (is_array($cartItems) === false)
        {
            return '0 items';
        }

        $quantity = 0;

        foreach ($cartItems as $cartItem)
        {
            $quantity += (int) ($cartItem['quantity'] ?? 0);
        }

        return sprintf('%d item(s), %d qty', count($cartItems), $quantity);
    }

    protected function column_status($item)
    {
        if ($item['status'] === 'recovered')
        {
            return '<mark class="order-status status-completed"><span>Recovered</span></mark>';
        }

        return '<mark class="order-status status-failed"><span>Abandoned</span></mark>';
    }

    protected function column_wc_order_id($item)
    {
        $wcOrderId = (int) $item['wc_order_id'];

        return sprintf(
            '<a href="%s">#%d</a>',
            esc_url(rzpGetAbandonedCheckoutOrderEditUrl($wcOrderId)),
            $wcOrderId
        );
    }

    protected function column_created_at($item)
    {
        return esc_html($item['created_at']);
    }

    public function column_default($item, $columnName)
    {
        return isset($item[$columnName]) ? esc_html($item[$columnName]) : '';
    }
}

add_action('admin_menu', 'rzpAbandonedCheckoutsAdminMenu');

function rzpAbandonedCheckoutsAdminMenu()
{
    add_submenu_page(
        'woocommerce',
        'Abandoned Checkouts',
        'Abandoned Checkouts',
        'manage_woocommerce',
        'rzp-abandoned-checkouts',
        'rzpAbandonedCheckoutsPage'
    );
}

function rzpHandleAbandonedCheckoutsActions()
{
    $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

    if ($action !== 'delete')
    {
        return;
    }

    $ids = isset($_REQUEST['abandoned_checkout']) ? array_map('absint', (array) wp_unslash($_REQUEST['abandoned_checkout'])) : array();
    $ids = array_filter($ids);

    if (isset($_REQUEST['id']) && absint($_REQUEST['id']) > 0)
    {
        $singleId = absint($_REQUEST['id']);

        check_admin_referer('rzp-delete-abandoned-checkout_' . $singleId);

        $ids = array($singleId);
    }
    else
    {
        check_admin_referer('bulk-rzp_abandoned_checkouts');
    }

    rzpDeleteAbandonedCheckouts($ids);

    wp_safe_redirect(remove_query_arg(array('action', 'action2', '_wpnonce', '_wp_http_referer', 'id', 'abandoned_checkout')));
    exit;
}

function rzpRenderAbandonedCheckoutsStats()
{
    global $wpdb;

    $tableName = rzpGetAbandonedCheckoutsTableName();

    $stats = $wpdb->get_row(
        "SELECT
            COUNT(*) AS total_count,
            COALESCE(SUM(CASE WHEN status = 'abandoned' THEN 1 ELSE 0 END), 0) AS abandoned_count,
            COALESCE(SUM(CASE WHEN status = 'abandoned' THEN cart_total ELSE 0 END), 0) AS abandoned_value,
            COALESCE(SUM(CASE WHEN status = 'recovered' THEN 1 ELSE 0 END), 0) AS recovered_count,
            COALESCE(SUM(CASE WHEN status = 'recovered' THEN cart_total ELSE 0 END), 0) AS recovered_value
        FROM $tableName",
        ARRAY_A
    );

    if (empty($stats) === true)
    {
        return;
    }

    $totalCount = (int) $stats['total_count'];

    $recoveryRate = ($totalCount > 0) ? round(((int) $stats['recovered_count'] / $totalCount) * 100, 1) : 0;

    $tiles = array(
        array('label' => 'Abandoned', 'value' => number_format_i18n((int) $stats['abandoned_count'])),
        array('label' => 'Abandoned Value', 'value' => wp_kses_post(wc_price((float) $stats['abandoned_value']))),
        array('label' => 'Recovered', 'value' => number_format_i18n((int) $stats['recovered_count'])),
        array('label' => 'Recovered Value', 'value' => wp_kses_post(wc_price((float) $stats['recovered_value']))),
        array('label' => 'Recovery Rate', 'value' => esc_html($recoveryRate) . '%'),
    );

    echo '<div style="display:flex;gap:16px;margin:16px 0 4px 0;flex-wrap:wrap;">';

    foreach ($tiles as $tile)
    {
        printf(
            '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px 16px;min-width:150px;">
                <div style="font-size:12px;color:#646970;text-transform:uppercase;">%s</div>
                <div style="font-size:20px;font-weight:600;">%s</div>
            </div>',
            esc_html($tile['label']),
            $tile['value']
        );
    }

    echo '</div>';
}

function rzpAbandonedCheckoutsPage()
{
    if (!current_user_can('manage_woocommerce'))
    {
        wp_die('You do not have permission to access this page.');
    }

    rzpHandleAbandonedCheckoutsActions();

    if (isset($_GET['view']))
    {
        rzpRenderAbandonedCheckoutDetailView(absint($_GET['view']));

        return;
    }

    $listTable = new RZP_Abandoned_Checkouts_Table();
    $listTable->prepare_items();

    $exportArgs = array('action' => 'rzp_abandoned_checkouts_export');

    $exportStatus = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';

    if (in_array($exportStatus, array('abandoned', 'recovered'), true))
    {
        $exportArgs['status'] = $exportStatus;
    }

    $exportSearch = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

    if ($exportSearch !== '')
    {
        $exportArgs['s'] = $exportSearch;
    }

    $exportUrl = wp_nonce_url(
        add_query_arg($exportArgs, admin_url('admin-post.php')),
        'rzp-export-abandoned-checkouts'
    );

    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">Abandoned Checkouts</h1>';
    printf('<a href="%s" class="page-title-action">Export CSV</a>', esc_url($exportUrl));
    echo '<hr class="wp-header-end" />';

    rzpRenderAbandonedCheckoutsStats();

    echo '<form id="rzp-abandoned-checkouts-filter" method="get">';
    echo '<input type="hidden" name="page" value="rzp-abandoned-checkouts" />';
    wp_nonce_field('bulk-rzp_abandoned_checkouts');
    $listTable->views();
    $listTable->search_box('Search', 'rzp-abandoned-checkouts');
    $listTable->display();
    echo '</form>';
    echo '</div>';
}

function rzpRenderAbandonedCheckoutDetailView($id)
{
    global $wpdb;

    $id = absint($id);

    $tableName = rzpGetAbandonedCheckoutsTableName();

    $record = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $tableName WHERE id = %d", $id),
        ARRAY_A
    );

    $backUrl = admin_url('admin.php?page=rzp-abandoned-checkouts');

    echo '<div class="wrap">';

    if (empty($record) === true)
    {
        echo '<h1>Abandoned Checkout</h1>';
        printf('<p><a href="%s">&larr; Back to abandoned checkouts</a></p>', esc_url($backUrl));
        echo '<p>Abandoned checkout not found.</p>';
        echo '</div>';

        return;
    }

    $customerDetails = json_decode((string) $record['customer_details'], true);
    $cartItems = json_decode((string) $record['cart_items'], true);

    $customerDetails = is_array($customerDetails) ? $customerDetails : array();
    $cartItems = is_array($cartItems) ? $cartItems : array();

    $billingAddress = $customerDetails['billing'] ?? array();
    $shippingAddress = $customerDetails['shipping'] ?? array();

    $deleteUrl = wp_nonce_url(
        admin_url('admin.php?page=rzp-abandoned-checkouts&action=delete&id=' . $record['id']),
        'rzp-delete-abandoned-checkout_' . $record['id']
    );

    echo '<h1 class="wp-heading-inline">Abandoned Checkout #' . esc_html($record['id']) . '</h1>';
    echo ' <mark class="order-status ' . (($record['status'] === 'recovered') ? 'status-completed' : 'status-failed') . '"><span>' . esc_html(ucfirst($record['status'])) . '</span></mark>';
    echo '<hr class="wp-header-end" />';

    printf('<p><a href="%s">&larr; Back to abandoned checkouts</a></p>', esc_url($backUrl));

    echo '<div id="poststuff">';
    echo '<div id="post-body" style="display:flex;gap:16px;flex-wrap:wrap;">';

    echo '<div style="flex:2;min-width:340px;">';

    echo '<h2>Cart Items</h2>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>Item</th><th>Product</th><th>Qty</th><th>Total</th></tr></thead><tbody>';

    foreach ($cartItems as $cartItem)
    {
        $productId = (int) ($cartItem['product_id'] ?? 0);
        $variationId = (int) ($cartItem['variation_id'] ?? 0);
        $productLabel = $productId;

        if ($variationId > 0)
        {
            $productLabel = $productId . ' / ' . $variationId;
        }

        printf(
            '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
            esc_html((string) ($cartItem['name'] ?? '')),
            esc_html((string) $productLabel),
            esc_html((string) ($cartItem['quantity'] ?? '')),
            esc_html((string) ($cartItem['total'] ?? ''))
        );
    }

    echo '</tbody></table>';

    echo '<p style="margin-top:12px;"><strong>Cart Total:</strong> ' . wp_kses_post(wc_price((float) $record['cart_total'], array('currency' => $record['currency']))) . '</p>';
    echo '<p><strong>Abandoned At:</strong> ' . esc_html($record['created_at']) . '</p>';

    if ($record['recovered_at'] !== null && $record['recovered_at'] !== '')
    {
        echo '<p><strong>Recovered At:</strong> ' . esc_html($record['recovered_at']) . '</p>';
    }

    echo '<p><strong>WooCommerce Order:</strong> <a href="' . esc_url(rzpGetAbandonedCheckoutOrderEditUrl((int) $record['wc_order_id'])) . '">#' . esc_html($record['wc_order_id']) . '</a> &nbsp; <strong>Razorpay Order:</strong> ' . esc_html($record['rzp_order_id']) . '</p>';

    echo '<p style="margin-top:16px;">';
    printf('<a href="%s" class="button button-secondary">Delete</a>', esc_url($deleteUrl));
    echo '</p>';

    echo '</div>';

    echo '<div style="flex:1;min-width:260px;">';

    echo '<h2>Customer</h2>';
    echo '<table class="form-table">';
    printf('<tr><th>Name</th><td>%s</td></tr>', !empty($record['customer_name']) ? esc_html($record['customer_name']) : '&mdash;');

    if (!empty($record['customer_email']))
    {
        printf('<tr><th>Email</th><td><a href="mailto:%s">%s</a></td></tr>', esc_attr($record['customer_email']), esc_html($record['customer_email']));
    }
    else
    {
        echo '<tr><th>Email</th><td>&mdash;</td></tr>';
    }

    printf('<tr><th>Phone</th><td>%s</td></tr>', !empty($record['customer_phone']) ? esc_html($record['customer_phone']) : '&mdash;');
    echo '</table>';

    echo '<h2>Shipping Address</h2>';
    echo '<p>' . esc_html(rzpFormatAbandonedCheckoutAddress($shippingAddress)) . '</p>';

    echo '<h2>Billing Address</h2>';
    echo '<p>' . esc_html(rzpFormatAbandonedCheckoutAddress($billingAddress)) . '</p>';

    echo '</div>';

    echo '</div>';
    echo '</div>';
    echo '</div>';
}

add_action('admin_post_rzp_abandoned_checkouts_export', 'rzpExportAbandonedCheckoutsCsv');

function rzpExportAbandonedCheckoutsCsv()
{
    if (!current_user_can('manage_woocommerce'))
    {
        wp_die('You do not have permission to export abandoned checkouts.');
    }

    check_admin_referer('rzp-export-abandoned-checkouts');

    global $wpdb;

    $tableName = rzpGetAbandonedCheckoutsTableName();

    $where = ' WHERE 1=1';
    $args = array();

    $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';

    if (in_array($status, array('abandoned', 'recovered'), true))
    {
        $where .= ' AND status = %s';
        $args[] = $status;
    }

    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

    if ($search !== '')
    {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where .= ' AND (customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s)';
        $args[] = $like;
        $args[] = $like;
        $args[] = $like;
    }

    $query = "SELECT * FROM $tableName$where ORDER BY created_at DESC";

    if (empty($args) === false)
    {
        $query = $wpdb->prepare($query, $args);
    }

    $records = $wpdb->get_results($query, ARRAY_A);

    $filename = 'razorpay-abandoned-checkouts-' . gmdate('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');

    fputcsv($output, array(
        'ID',
        'WC Order ID',
        'Razorpay Order ID',
        'Status',
        'Customer Name',
        'Customer Email',
        'Customer Phone',
        'Cart Total',
        'Currency',
        'Items',
        'Abandoned At',
        'Recovered At',
        'Billing Address',
        'Shipping Address',
    ));

    foreach ($records as $record)
    {
        $customerDetails = json_decode((string) $record['customer_details'], true);
        $cartItems = json_decode((string) $record['cart_items'], true);

        $customerDetails = is_array($customerDetails) ? $customerDetails : array();
        $cartItems = is_array($cartItems) ? $cartItems : array();

        $items = array();

        foreach ($cartItems as $cartItem)
        {
            $items[] = ($cartItem['quantity'] ?? 1) . ' x ' . ($cartItem['name'] ?? '');
        }

        fputcsv($output, array(
            $record['id'],
            $record['wc_order_id'],
            $record['rzp_order_id'],
            $record['status'],
            $record['customer_name'],
            $record['customer_email'],
            $record['customer_phone'],
            $record['cart_total'],
            $record['currency'],
            implode('; ', $items),
            $record['created_at'],
            $record['recovered_at'],
            rzpFormatAbandonedCheckoutAddress($customerDetails['billing'] ?? array()),
            rzpFormatAbandonedCheckoutAddress($customerDetails['shipping'] ?? array()),
        ));
    }

    fclose($output);

    exit;
}
