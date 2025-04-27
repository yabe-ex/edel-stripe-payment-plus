<?php
// inc/class-payment-history-list-table.php

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Edel_Payment_History_List_Table
 * Renders the list table using data from the custom database table.
 */
class Edel_Payment_History_List_Table extends WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'Payment',
            'plural'   => 'Payments',
            'ajax'     => false
        ]);
    }

    /**
     * Get the list of columns.
     */
    public function get_columns() {
        $columns = [
            //'cb'                => '<input type="checkbox" />',
            'payment_time'      => '日時',
            'user_info'         => 'ユーザー',
            'item_name'         => '内容',
            'amount'            => '金額',
            'status'            => 'ステータス',
            'payment_intent_id' => 'Stripe Payment Intent ID',
            'subscription_id'   => 'Stripe Subscription ID', // ★表示列に追加（任意）
            'actions'           => '操作', // ★操作列を追加
        ];
        return $columns;
    }

    /**
     * Get the list of sortable columns.
     * Sorting still uses the GMT database column for accuracy.
     */
    protected function get_sortable_columns() {
        $sortable_columns = array(
            'payment_time'   => array('created_at_gmt', false),
            'amount'         => array('amount', false),
            'status'         => array('status', false), // ★ステータスでもソート可能に
            // user_info は JOIN 先のため少し複雑になるので保留
        );
        return $sortable_columns;
    }

    /**
     * Prepare the items for the table to process.
     * Fetches data directly from the custom database table.
     */
    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . EDEL_STRIPE_PAYMENT_PREFIX . 'main';
        $user_table = $wpdb->users;

        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());

        // Pagination Parameters
        $per_page = $this->get_items_per_page('payments_per_page', 20);
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        // Sorting Parameters
        // Use 'created_at_gmt' for sorting by date column key 'payment_time'
        $orderby = 'created_at_gmt'; // Default orderby column
        if (isset($_REQUEST['orderby']) && array_key_exists($_REQUEST['orderby'], $this->get_sortable_columns())) {
            // Get the actual DB column name from the sortable array
            $orderby_key = $_REQUEST['orderby'];
            $orderby = $this->get_sortable_columns()[$orderby_key][0];
            // Sanitize the DB column name (simple alphanumeric + underscore check)
            if (preg_match('/^[a-zA-Z0-9_]+$/', $orderby)) {
                // It's likely safe
            } else {
                $orderby = 'created_at_gmt'; // Fallback to default if invalid
            }
        }
        $order = 'DESC'; // Default order direction
        if (isset($_REQUEST['order']) && in_array(strtoupper($_REQUEST['order']), ['ASC', 'DESC'], true)) {
            $order = strtoupper($_REQUEST['order']);
        }

        // Get Total Items Count
        $sql_total = "SELECT COUNT(id) FROM {$table_name}";
        $total_items = $wpdb->get_var($sql_total);

        // Get Data for Current Page
        // Select all necessary columns including the GMT timestamp for sorting/conversion
        $sql_data = $wpdb->prepare(
            "SELECT p.*, u.user_email, u.display_name
             FROM {$table_name} p
             LEFT JOIN {$user_table} u ON p.user_id = u.ID
             ORDER BY {$orderby} {$order}
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );
        // Fetch data as associative arrays
        $this->items = $wpdb->get_results($sql_data, ARRAY_A);

        // Set Pagination Arguments
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
            case 'amount':
                // 金額をフォーマット（カンマ区切り）
                $amount_raw = $item['amount'] ?? 0; // DBから取得した値
                $amount_formatted = number_format($amount_raw);
                // 通貨コードを取得（小文字に変換）
                $currency_code = isset($item['currency']) ? strtolower($item['currency']) : 'jpy'; // デフォルトjpy

                // 通貨コードに応じて表示を切り替える
                if ($currency_code === 'jpy') {
                    // 日本円の場合：そのままカンマ区切り +「円」
                    return number_format($amount_raw) . '円';
                } elseif ($currency_code === 'usd') {
                    // ★ USDの場合：セント単位の値を100で割り、ドル表記（小数点2桁）にする +「$」
                    // 例：DBの値が 1200 なら、$12.00 と表示
                    return '$' . number_format($amount_raw / 100, 2);
                } else {
                    // その他の通貨（あまり想定していないが念のため）
                    // 金額をカンマ区切り + 大文字の通貨コード
                    return number_format($amount_raw) . ' ' . strtoupper($currency_code);
                }
                // break; // return するので break は不要

            case 'item_name':
            case 'status':
            case 'customer_id': // customer_id は専用メソッドがあるのでここから削除しても良い
                return isset($item[$column_name]) ? esc_html($item[$column_name]) : '';
                // payment_time, user_info, payment_intent_id, subscription_id は専用メソッドで処理
            default:
                // デバッグ用に未知のカラムは内容を表示（ただし本番では非表示推奨）
                // return isset($item[$column_name]) ? print_r($item[$column_name], true) : '';
                return ''; // 未知のカラムは空にする
        }
    } // end column_default

    protected function column_subscription_id($item) {
        if (!empty($item['subscription_id'])) {
            $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
            $mode = $options['mode'] ?? 'test';
            $stripe_base_url = ($mode === 'live') ? 'https://dashboard.stripe.com/subscriptions/' : 'https://dashboard.stripe.com/test/subscriptions/';
            $link = $stripe_base_url . rawurlencode($item['subscription_id']);
            return sprintf('<code><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></code>', esc_url($link), esc_html($item['subscription_id']));
        }
        return '---'; // サブスクでない場合はハイフン
    }

    protected function column_actions($item) {
        $actions = [];
        // 返金可能なステータスか（例: succeeded）＆ Payment Intent ID があるか
        if (isset($item['status']) && $item['status'] === 'succeeded' && !empty($item['payment_intent_id']) && strpos($item['payment_intent_id'], 'N/A') === false) {
            $refund_nonce = wp_create_nonce(EDEL_STRIPE_PAYMENT_PREFIX . 'refund_payment_' . $item['payment_intent_id']);
            $actions['refund'] = sprintf(
                // Added data-amount for JS confirmation message
                '<button type="button" class="button button-small edel-stripe-refund" data-piid="%s" data-nonce="%s" data-amount="%d" data-currency="%s">返金</button>',
                esc_attr($item['payment_intent_id']),
                esc_attr($refund_nonce),
                esc_attr($item['amount']), // Pass amount in smallest unit
                esc_attr($item['currency'] ?? 'jpy') // Pass currency
            );
        }

        // 他のアクション（例：詳細表示など）があればここに追加

        return implode(' ', $actions);
    } // end column_actions


    /**
     * ★修正: Handles the payment_time column output (WordPress local time).
     */
    protected function column_payment_time($item) {
        if (empty($item['created_at_gmt'])) {
            return '---';
        }

        $timestamp = strtotime($item['created_at_gmt']);
        if ($timestamp === false) {
            return esc_html($item['created_at_gmt']) . ' (不正な日付)';
        }

        return wp_date('Y-m-d H:i', $timestamp);
    }

    /**
     * ★修正: Handles the user_info column output (linked email only).
     */
    protected function column_user_info($item) {
        if (!empty($item['user_id']) && !empty($item['user_email'])) {
            $edit_link = get_edit_user_link($item['user_id']);
            if ($edit_link) {
                // Link the email to the user edit page
                return sprintf('<a href="%s">%s</a>', esc_url($edit_link), esc_html($item['user_email']));
            } else {
                // User exists but cannot get edit link (permissions?) - show email only
                return esc_html($item['user_email']);
            }
        } elseif (!empty($item['customer_id'])) {
            // If user_id is somehow null but we have customer ID, show email from DB if available
            if (!empty($item['user_email'])) {
                return esc_html($item['user_email']) . ' (ゲスト?)'; // Or 'Stripe顧客: ' . esc_html($item['customer_id'])
            } else {
                return '---'; // No user info available
            }
        }
        return '---'; // Default if no user info
    }

    /**
     * Handles the payment_intent_id column output with link to Stripe dashboard. (変更なし)
     */
    protected function column_payment_intent_id($item) {
        if (!empty($item['payment_intent_id'])) {
            $stripe_base_url = 'https://dashboard.stripe.com/test/payments/';
            if (strpos($item['payment_intent_id'], 'pi_') === 0 && strpos($item['payment_intent_id'], 'test') === false) {
                $stripe_base_url = 'https://dashboard.stripe.com/payments/';
            }
            $link = $stripe_base_url . rawurlencode($item['payment_intent_id']);
            return sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url($link), esc_html($item['payment_intent_id']));
        }
        return '';
    }

    // Sorting logic is now handled by SQL ORDER BY in prepare_items

} // End class