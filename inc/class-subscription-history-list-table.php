<?php
// inc/class-subscription-history-list-table.php

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Edel_Subscription_History_List_Table
 * Renders the list table for subscriptions based on user meta.
 */
class Edel_Subscription_History_List_Table extends WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'Subscription',
            'plural'   => 'Subscriptions',
            'ajax'     => false // No AJAX for this table (standard pagination)
        ]);
    }

    /**
     * Get the list of columns.
     */
    public function get_columns() {
        $columns = [
            'user_info'         => 'ユーザー',
            'subscription_id'   => 'Stripe Subscription ID', // ← キー名が正しいか
            'customer_id'       => 'Stripe Customer ID',
            'status'            => 'ステータス (WordPress側)',
            'user_registered'   => 'ユーザー登録日',
            'actions'           => '操作',
        ];
        return $columns;
    }

    /**
     * Get the list of sortable columns.
     */
    protected function get_sortable_columns() {
        $sortable_columns = array(
            // Define sortable columns - mapping display key to orderby value
            'user_info' => array('display_name', false),
            'user_registered' => array('user_registered', false), // Default sort
            // Sorting by meta values like status can be complex/slow, maybe omit initially
            // 'status' => array('subscription_status_meta', false),
        );
        return $sortable_columns;
    }

    /**
     * Prepare the items for the table to process.
     * Fetches users with subscription meta data using WP_User_Query.
     */
    public function prepare_items() {
        global $wpdb;
        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());

        // Pagination parameters
        $per_page = $this->get_items_per_page('subscriptions_per_page', 20); // Screen option key
        $current_page = $this->get_pagenum();

        // Sorting parameters
        $orderby = 'user_registered'; // Default sort column
        $sortable_columns = $this->get_sortable_columns();
        if (isset($_REQUEST['orderby']) && array_key_exists($_REQUEST['orderby'], $sortable_columns)) {
            $orderby_req = sanitize_key($_REQUEST['orderby']);
            $orderby = $sortable_columns[$orderby_req][0]; // Get the actual 'orderby' value (e.g., 'display_name')
        }
        $order = 'DESC'; // Default sort order
        if (isset($_REQUEST['order']) && in_array(strtoupper($_REQUEST['order']), ['ASC', 'DESC'], true)) {
            $order = strtoupper($_REQUEST['order']);
        }

        // WP_User_Query arguments
        $user_query_args = array(
            'number' => $per_page,
            'offset' => ($current_page - 1) * $per_page,
            'orderby' => $orderby,
            'order' => $order,
            'meta_query' => array(
                'relation' => 'AND', // Ensure all meta keys exist (though only one needed to find users)
                array(
                    'key' => EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_id',
                    'compare' => 'EXISTS' // Find users who have a subscription ID meta
                ),
                array( // Optionally ensure customer ID also exists
                    'key' => EDEL_STRIPE_PAYMENT_PREFIX . 'customer_id',
                    'compare' => 'EXISTS'
                ),
            )
        );

        // Execute the query
        $user_query = new WP_User_Query($user_query_args);
        $users = $user_query->get_results();
        $total_items = $user_query->get_total();

        error_log("[Sub History Table] WP_User_Query completed. Found {$total_items} users.");


        // Prepare items array for the list table
        $this->items = array();
        if (!empty($users)) {
            foreach ($users as $user) {
                error_log("[Sub History Table] Entering foreach loop for users...");

                $sub_id_meta = get_user_meta($user->ID, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_id', true);
                $sub_status_meta = get_user_meta($user->ID, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_status', true);
                $customer_id_meta = get_user_meta($user->ID, EDEL_STRIPE_PAYMENT_PREFIX . 'customer_id', true);
                error_log("[Sub History Table] User ID: {$user->ID} | Retrieved Sub ID Meta: " . print_r($sub_id_meta, true) . " | Retrieved Status Meta: " . print_r($sub_status_meta, true) . " | Retrieved Cust ID Meta: " . print_r($customer_id_meta, true));

                $this->items[] = array(
                    'user_id'              => $user->ID,
                    'user_email'           => $user->user_email,
                    'display_name'         => $user->display_name,
                    'user_registered'      => $user->user_registered,
                    'subscription_id'      => $sub_id_meta, // 取得した値を使う
                    'subscription_status'  => $sub_status_meta, // 取得した値を使う
                    'customer_id'          => $customer_id_meta, // 取得した値を使う
                );
            }
            error_log("[Sub History Table] Finished foreach loop.");
        } else {
            error_log("[Sub History Table] No users found matching meta query criteria. Loop skipped.");
        }

        // Set pagination arguments
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }

    /**
     * Handles the default column output.
     */
    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'user_registered':
                return isset($item[$column_name]) ? wp_date(get_option('date_format'), strtotime($item[$column_name])) : '---'; // Date only might be cleaner
            default:
                // Return the value if it exists in the item array, otherwise empty string
                return isset($item[$column_name]) ? esc_html($item[$column_name]) : '';
        }
    }

    /**
     * Handles the user_info column output (linked email).
     */
    protected function column_user_info($item) {
        if (!empty($item['user_id']) && !empty($item['user_email'])) {
            $edit_link = get_edit_user_link($item['user_id']);
            if ($edit_link) {
                return sprintf('<a href="%s">%s</a>', esc_url($edit_link), esc_html($item['user_email']));
            } else {
                return esc_html($item['user_email']);
            }
        }
        return '---';
    }

    /**
     * Handles the subscription_id column output with link to Stripe dashboard.
     */
    protected function column_subscription_id($item) { // ← メソッド名が正しいか
        if (!empty($item['subscription_id'])) { // ← 配列キーが正しいか
            $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
            $mode = $options['mode'] ?? 'test';
            $stripe_base_url = ($mode === 'live') ? 'https://dashboard.stripe.com/subscriptions/' : 'https://dashboard.stripe.com/test/subscriptions/';
            $link = $stripe_base_url . rawurlencode($item['subscription_id']);
            return sprintf('<code><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></code>', esc_url($link), esc_html($item['subscription_id']));
        }
        return '---';
    }

    /**
     * Handles the customer_id column output with link to Stripe dashboard.
     */
    protected function column_customer_id($item) {
        if (!empty($item['customer_id'])) {
            $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
            $mode = $options['mode'] ?? 'test';
            $stripe_base_url = ($mode === 'live') ? 'https://dashboard.stripe.com/customers/' : 'https://dashboard.stripe.com/test/customers/';
            $link = $stripe_base_url . rawurlencode($item['customer_id']);
            return sprintf('<code><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></code>', esc_url($link), esc_html($item['customer_id']));
        }
        return '---';
    }

    /**
     * Handles the status column output.
     */
    protected function column_status($item) {
        $status = $item['subscription_status'] ?? '不明'; // Get status from meta
        $status_label = ucfirst($status); // Capitalize first letter
        $color = '#777'; // Default color

        // Assign colors based on common Stripe statuses
        switch (strtolower($status)) {
            case 'active':
            case 'trialing':
                $color = 'green';
                break;
            case 'past_due':
            case 'payment_failed': // Custom status we added
                $color = 'orange';
                break;
            case 'canceled':
            case 'unpaid':
            case 'incomplete':
            case 'incomplete_expired':
                $color = 'red';
                break;
        }
        return sprintf('<span style="color: %s;">%s</span>', esc_attr($color), esc_html($status_label));
    }

    protected function column_actions($item) {
        $actions = []; // Store actions here

        // --- Cancel Action ---
        $status = strtolower($item['subscription_status'] ?? '');
        $inactive_statuses = ['canceled', 'incomplete_expired', 'unpaid']; // Already inactive statuses

        if (!in_array($status, $inactive_statuses)) {
            $cancel_nonce = wp_create_nonce(EDEL_STRIPE_PAYMENT_PREFIX . 'cancel_sub_' . $item['subscription_id']);
            $actions['cancel'] = sprintf(
                '<button type="button" class="button button-small edel-stripe-cancel-sub" data-subid="%s" data-nonce="%s" data-userid="%d">キャンセル要求</button>', // Changed text slightly
                esc_attr($item['subscription_id']),
                esc_attr($cancel_nonce),
                esc_attr($item['user_id'])
            );
        } else {
            // Optionally show something else if already canceled, e.g., '-' or 'Canceled'
            // $actions['cancel'] = 'キャンセル済み';
        }

        // --- ★追加: Sync Status Action ---
        // Always allow sync? Or only for non-canceled? Let's allow always for manual check.
        if (!empty($item['subscription_id'])) {
            $sync_nonce = wp_create_nonce(EDEL_STRIPE_PAYMENT_PREFIX . 'sync_sub_' . $item['subscription_id']);
            $actions['sync'] = sprintf(
                '<button type="button" class="button button-small edel-stripe-sync-sub" data-subid="%s" data-nonce="%s" data-userid="%d" style="margin-left: 5px;">ステータス同期</button>',
                esc_attr($item['subscription_id']),
                esc_attr($sync_nonce),
                esc_attr($item['user_id'])
            );
        }

        // Return actions separated by space or other separator
        return implode(' ', $actions);
    }

    /**
     * Message to be displayed when there are no subscription items.
     */
    public function no_items() {
        echo 'アクティブなサブスクリプション契約を持つユーザーが見つかりません。';
    }
} // End class