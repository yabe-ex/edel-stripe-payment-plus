<?php
// inc/class-subscription-history-list-table.php

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Edel_Subscription_History_List_Table
 * Renders the list table for active subscriptions based on user meta.
 */
class Edel_Subscription_History_List_Table extends WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'Subscription',
            'plural'   => 'Subscriptions',
            'ajax'     => false
        ]);
    }

    /**
     * Get the list of columns.
     */
    public function get_columns() {
        $columns = [
            'user_info'     => 'ユーザー',
            'sub_id'        => 'Stripe Subscription ID',
            'customer_id'   => 'Stripe Customer ID',
            'status'        => 'ステータス (WP側)',
            'user_registered' => 'ユーザー登録日', // Or maybe subscription start date if stored?
            // Add Plan ID / Name column later? Requires fetching Price/Product data.
        ];
        return $columns;
    }

    /**
     * Get the list of sortable columns.
     */
    protected function get_sortable_columns() {
        $sortable_columns = array(
            // Sorting by user meta is tricky, maybe sort by user registration date?
            'user_info' => array('display_name', false), // Sort by display name
            'user_registered' => array('user_registered', false),
        );
        return $sortable_columns;
    }

    /**
     * Prepare the items for the table to process.
     * Fetches users with subscription meta data.
     */
    public function prepare_items() {
        global $wpdb;
        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());

        // Pagination parameters
        $per_page = $this->get_items_per_page('subscriptions_per_page', 20);
        $current_page = $this->get_pagenum();

        // Sorting parameters
        $orderby = 'user_registered'; // Default sort column
        if (isset($_REQUEST['orderby']) && array_key_exists($_REQUEST['orderby'], $this->get_sortable_columns())) {
            $orderby = sanitize_key($_REQUEST['orderby']);
            if ($orderby == 'user_info') $orderby = 'display_name'; // Map user_info to display_name for query
        }
        $order = 'DESC'; // Default sort order
        if (isset($_REQUEST['order']) && in_array(strtoupper($_REQUEST['order']), ['ASC', 'DESC'], true)) {
            $order = strtoupper($_REQUEST['order']);
        }

        // Query users who have a subscription ID stored in meta
        $meta_query_args = array(
            'key' => EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_id',
            'compare' => 'EXISTS'
        );
        $user_query_args = array(
            'meta_query' => array($meta_query_args),
            'number' => $per_page,
            'offset' => ($current_page - 1) * $per_page,
            'orderby' => $orderby,
            'order' => $order,
        );

        $user_query = new WP_User_Query($user_query_args);
        $users = $user_query->get_results();
        $total_items = $user_query->get_total();

        // Prepare items for display
        $this->items = array();
        if (!empty($users)) {
            foreach ($users as $user) {
                $this->items[] = array(
                    'user_id'          => $user->ID,
                    'user_email'       => $user->user_email,
                    'display_name'     => $user->display_name,
                    'user_registered'  => $user->user_registered,
                    'subscription_id'  => get_user_meta($user->ID, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_id', true),
                    'subscription_status' => get_user_meta($user->ID, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_status', true),
                    'customer_id'      => get_user_meta($user->ID, EDEL_STRIPE_PAYMENT_PREFIX . 'customer_id', true),
                );
            }
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
            case 'status':
                // Display status saved in user meta (might be 'active', 'trialing', or empty/outdated)
                $status = !empty($item['subscription_status']) ? $item['subscription_status'] : '不明';
                // Add styling based on status (optional)
                if ($status === 'active' || $status === 'trialing') {
                    return '<span style="color: green;">' . esc_html(ucfirst($status)) . '</span>'; // Example styling
                } else {
                    return '<span style="color: #aaa;">' . esc_html(ucfirst($status)) . '</span>';
                }
            case 'user_registered':
                return isset($item[$column_name]) ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item[$column_name])) : '---';
            case 'sub_id': // Corresponds to Stripe Subscription ID
            case 'customer_id':
                return isset($item[$column_name]) ? '<code>' . esc_html($item[$column_name]) . '</code>' : '---';
            default:
                return 'N/A';
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
     * Handles the sub_id column output with link to Stripe dashboard.
     */
    protected function column_sub_id($item) {
        if (!empty($item['subscription_id'])) {
            $stripe_base_url = 'https://dashboard.stripe.com/test/subscriptions/'; // Default to test
            if (strpos($item['subscription_id'], 'sub_') === 0) { // Live IDs start with 'sub_'
                // Check if plugin is in live mode (requires fetching options)
                $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
                if (isset($options['mode']) && $options['mode'] === 'live') {
                    $stripe_base_url = 'https://dashboard.stripe.com/subscriptions/';
                }
            }
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
            $stripe_base_url = 'https://dashboard.stripe.com/test/customers/'; // Default to test
            $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
            if (isset($options['mode']) && $options['mode'] === 'live') {
                $stripe_base_url = 'https://dashboard.stripe.com/customers/';
            }
            $link = $stripe_base_url . rawurlencode($item['customer_id']);
            return sprintf('<code><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></code>', esc_url($link), esc_html($item['customer_id']));
        }
        return '---';
    }

    /**
     * Optional: Message to be displayed when there are no items
     */
    public function no_items() {
        _e('アクティブなサブスクリプションを持つユーザーが見つかりません。', 'edel-stripe-payment'); // Need text domain
    }
} // End class