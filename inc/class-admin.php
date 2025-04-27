<?php

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

require_once EDEL_STRIPE_PAYMENT_PATH . '/inc/class-payment-history-list-table.php';
require_once EDEL_STRIPE_PAYMENT_PATH . '/inc/class-subscription-history-list-table.php';


class EdelStripePaymentAdmin {
    function admin_menu() {
        add_menu_page(
            EDEL_STRIPE_PAYMENT_NAME . ' メイン', // Window Title (実際はサブメニュー名が優先される)
            EDEL_STRIPE_PAYMENT_NAME,             // Menu Title
            'manage_options',                     // Capability
            EDEL_STRIPE_PAYMENT_SLUG . '-main',   // ★ トップレベルメニューのスラッグ
            array($this, 'show_payment_history_page'), // ★ メインは決済履歴表示関数に
            'dashicons-money',                    // Icon
            10                                    // Position
        );

        // ★ サブメニュー: 決済履歴 (トップレベルと同じ関数を呼ぶ)
        add_submenu_page(
            EDEL_STRIPE_PAYMENT_SLUG . '-main',   // Parent Slug
            '決済履歴',                           // Page Title
            '決済履歴',                           // Menu Title
            'manage_options',                     // Capability
            EDEL_STRIPE_PAYMENT_SLUG . '-main',   // Menu Slug (トップレベルと同じ)
            array($this, 'show_payment_history_page'), // ★ コールバック関数名を変更
            1                                     // Position
        );

        add_submenu_page(
            EDEL_STRIPE_PAYMENT_SLUG . '-main',   // Parent Slug
            'サブスク履歴',                       // Page Title
            'サブスク履歴',                       // Menu Title
            'manage_options',                     // Capability
            EDEL_STRIPE_PAYMENT_SLUG . '-sub-history', // ★ 新しいスラッグ
            array($this, 'show_subscription_history_page'), // ★ 新しいコールバック関数
            2                                     // Position
        );

        add_submenu_page(
            EDEL_STRIPE_PAYMENT_SLUG . '-main',   // Parent Slug
            '設定',                               // Page Title
            '設定',                               // Menu Title
            'manage_options',                     // Capability
            EDEL_STRIPE_PAYMENT_SLUG . '-setting', // Menu Slug
            array($this, 'show_setting_page'),    // Function
            99                                    // Position (設定を最後に)
        );
    }

    /**
     * Enqueues admin scripts and styles (currently placeholder).
     */
    function admin_enqueue($hook) {
        // Check if we are on one of the plugin's admin pages
        if (strpos($hook, EDEL_STRIPE_PAYMENT_SLUG) === false) {
            return;
        }

        $version = (defined('EDEL_STRIPE_PAYMENT_DEVELOP') && true === EDEL_STRIPE_PAYMENT_DEVELOP) ? time() : EDEL_STRIPE_PAYMENT_VERSION;

        // Enqueue CSS for admin styles (including tabs)
        wp_enqueue_style(
            EDEL_STRIPE_PAYMENT_SLUG . '-admin',
            EDEL_STRIPE_PAYMENT_URL . '/css/admin.css',
            array(),
            $version
        );

        // Enqueue JS for tab handling
        wp_enqueue_script(
            EDEL_STRIPE_PAYMENT_SLUG . '-admin',
            EDEL_STRIPE_PAYMENT_URL . '/js/admin.js',
            array('jquery'),
            $version,
            true // Load in footer
        );
    }

    /**
     * Adds action links to the plugin list table.
     */
    function plugin_action_links($links) {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=' . EDEL_STRIPE_PAYMENT_SLUG . '-setting')) . '">設定</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    function show_subscription_history_page() {
        // Instantiate the Subscription List Table class
        $list_table = new Edel_Subscription_History_List_Table();
        // Prepare items (fetch user meta data, sort, paginate)
        $list_table->prepare_items();
?>
        <div class="wrap">
            <h1>サブスクリプション履歴</h1>
            <p>サブスクリプション契約を持つユーザーの一覧です。（現在のステータスはWebhookで同期されます）</p>

            <?php // ★ Display the list table (Needs form for sorting/pagination links)
            ?>
            <form method="get">
                <?php // Required hidden fields for list table
                ?>
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
                <?php // Keep orderby and order if set by user
                if (isset($_REQUEST['orderby'])) {
                    echo '<input type="hidden" name="orderby" value="' . esc_attr($_REQUEST['orderby']) . '" />';
                }
                if (isset($_REQUEST['order'])) {
                    echo '<input type="hidden" name="order" value="' . esc_attr($_REQUEST['order']) . '" />';
                }
                ?>
                <?php $list_table->display(); ?>
            </form>

        </div>
    <?php
    } // end show_subscription_history_page()

    /**
     * Displays the main page content (Payment History).
     */
    function show_payment_history_page() {
        // Instantiate the List Table class
        $list_table = new Edel_Payment_History_List_Table();
        // Prepare items (fetch data, sort, paginate)
        $list_table->prepare_items();
    ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p>Stripe経由で行われた決済の履歴です。</p>

            <form method="get">
                <?php // Hidden fields for list table processing (page, orderby, order)
                ?>
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
                <?php
                // Display the list table
                $list_table->display();
                ?>
            </form>

        </div>
    <?php
    }

    public function ajax_refund_payment() {
        // Nonce検証 (アクション名に PI ID を含める)
        $pi_id = isset($_POST['pi_id']) ? sanitize_text_field($_POST['pi_id']) : '';
        // 注意: Nonceアクション名がJS側と一致しているか確認してください
        // (JSが data-nonce 属性の値を 'nonce' キーで送る想定)
        check_ajax_referer(EDEL_STRIPE_PAYMENT_PREFIX . 'refund_payment_' . $pi_id, 'nonce');

        // 権限チェック
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '権限がありません。']);
            return;
        }

        // 必須データチェック
        if (empty($pi_id)) {
            wp_send_json_error(['message' => 'Payment Intent IDが指定されていません。']);
            return;
        }

        // Stripe Secret Key 取得
        $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
        $is_live_mode = isset($options['mode']) && $options['mode'] === 'live';
        $secret_key = $is_live_mode ? ($options['live_secret_key'] ?? '') : ($options['test_secret_key'] ?? '');
        $error_log_prefix = '[Edel Stripe Admin Refund] ';

        if (empty($secret_key)) {
            error_log($error_log_prefix . 'Stripe Secret Key not configured.');
            wp_send_json_error(['message' => 'Stripe APIキーが設定されていません。']);
            return;
        }

        error_log($error_log_prefix . 'Refund requested for PI ID: ' . $pi_id);

        try {
            // --- StripeClient を使って返金処理 ---
            $stripe = new \Stripe\StripeClient($secret_key);
            \Stripe\Stripe::setApiVersion("2024-04-10"); // APIバージョン指定

            // 返金を作成 (全額返金)
            // 部分返金の場合は 'amount' => 金額(最小単位) を追加
            $refund = $stripe->refunds->create([
                'payment_intent' => $pi_id,
                // 'amount' => 500, // 例: 500円(JPY) or 500セント($5.00 USD)の部分返金
                'reason' => 'requested_by_customer', // 返金理由（任意）
                'metadata' => [ // メタデータ（任意）
                    'wp_user_id' => get_current_user_id(),
                    'refunded_from' => 'WP Admin'
                ]
            ]);

            // 返金オブジェクトのステータスを確認 (succeeded, pending, failed, requires_action)
            error_log($error_log_prefix . 'Refund processed for PI: ' . $pi_id . '. Refund ID: ' . $refund->id . '. Status: ' . $refund->status);

            if ($refund->status === 'succeeded' || $refund->status === 'pending') {
                // 成功または処理中の場合（非同期で完了することがある）
                wp_send_json_success([
                    'message' => 'Stripeへの返金要求は成功しました。ステータスの最終的な更新はWebhook経由で行われます。Refund ID: ' . esc_html($refund->id),
                    'refund_status' => $refund->status
                ]);
            } else {
                // 返金自体が失敗した場合 (レアケース)
                throw new Exception('返金処理が失敗しました。Stripeステータス: ' . $refund->status);
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Stripe APIエラー (例: 返金済み、PIが見つからないなど)
            error_log($error_log_prefix . 'Stripe API Error processing refund for PI ' . $pi_id . ': ' . $e->getMessage());
            wp_send_json_error(['message' => 'Stripe APIエラー: ' . esc_html($e->getMessage())]);
        } catch (Exception $e) {
            // その他のエラー
            error_log($error_log_prefix . 'General Error processing refund for PI ' . $pi_id . ': ' . $e->getMessage());
            wp_send_json_error(['message' => '返金処理中にエラーが発生しました: ' . esc_html($e->getMessage())]);
        }
        // finally ブロックは不要

        // wp_send_json_* は wp_die() を呼ぶ
    } // end ajax_refund_payment

    public function ajax_cancel_subscription() {
        // Nonce検証
        $sub_id = isset($_POST['sub_id']) ? sanitize_text_field($_POST['sub_id']) : '';
        check_ajax_referer(EDEL_STRIPE_PAYMENT_PREFIX . 'cancel_sub_' . $sub_id, 'nonce');

        // 権限チェック
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '権限がありません。']);
            return;
        }

        // Stripeシークレットキー取得
        $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
        $is_live_mode = isset($options['mode']) && $options['mode'] === 'live';
        $secret_key = $is_live_mode ? ($options['live_secret_key'] ?? '') : ($options['test_secret_key'] ?? '');

        if (empty($sub_id) || empty($secret_key)) {
            wp_send_json_error(['message' => '必要な情報（サブスクリプションIDまたはAPIキー）が不足しています。']);
            return;
        }

        $error_log_prefix = '[Edel Stripe Admin] ';

        try {
            // --- ★ StripeClient を使ってキャンセル ★ ---
            // StripeClient を初期化
            $stripe = new \Stripe\StripeClient($secret_key);
            \Stripe\Stripe::setApiVersion("2024-04-10"); // APIバージョン指定は Client でも有効

            error_log($error_log_prefix . 'Attempting immediate cancellation for SubID: ' . $sub_id . ' using StripeClient.');

            // subscriptions->cancel を呼び出す
            $subscription = $stripe->subscriptions->cancel($sub_id, []); // 第2引数はオプションパラメータ配列

            error_log($error_log_prefix . 'Subscription cancellation processed via StripeClient for: ' . $sub_id . '. Result Status: ' . $subscription->status);

            wp_send_json_success(['message' => 'Stripeサブスクリプションを即時キャンセルしました。ステータス変更はWebhook経由で反映されます。']);
            // --- ★ StripeClient 利用ここまで ★ ---

        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log($error_log_prefix . 'Stripe API Error canceling subscription ' . $sub_id . ': ' . $e->getMessage());
            if (strpos($e->getMessage(), 'No such subscription') !== false || strpos($e->getMessage(), 'subscription is already canceled') !== false) {
                wp_send_json_success(['message' => 'サブスクリプションは既にキャンセルされているようです。']);
            } else {
                wp_send_json_error(['message' => 'Stripe APIエラー: ' . esc_html($e->getMessage())]);
            }
        } catch (Exception $e) {
            error_log($error_log_prefix . 'General Error canceling subscription ' . $sub_id . ': ' . $e->getMessage());
            wp_send_json_error(['message' => 'サブスクリプションのキャンセル中にエラーが発生しました。']);
        }
        // finally ブロックは不要

        // wp_send_json_* は wp_die() を呼ぶので、ここで終了
    } // end ajax_cancel_subscription


    public function ajax_sync_subscription() {
        // Check Nonce - Action name includes sub ID, nonce value sent in 'nonce' POST var
        $sub_id = isset($_POST['sub_id']) ? sanitize_text_field($_POST['sub_id']) : '';
        check_ajax_referer(EDEL_STRIPE_PAYMENT_PREFIX . 'sync_sub_' . $sub_id, 'nonce');

        // Check capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '権限がありません。']);
            return;
        }

        // Get User ID passed from JS (linked to the subscription row)
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

        // Check required data
        if (empty($sub_id) || empty($user_id)) {
            wp_send_json_error(['message' => '必要な情報（サブスクリプションIDまたはユーザーID）が不足しています。']);
            return;
        }

        // Get Stripe Secret Key
        $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
        $is_live_mode = isset($options['mode']) && $options['mode'] === 'live';
        $secret_key = $is_live_mode ? ($options['live_secret_key'] ?? '') : ($options['test_secret_key'] ?? '');
        $error_log_prefix = '[Edel Stripe Admin Sync] ';

        if (empty($secret_key)) {
            error_log($error_log_prefix . 'Stripe Secret Key not configured.');
            wp_send_json_error(['message' => 'Stripe APIキーが設定されていません。']);
            return;
        }

        error_log($error_log_prefix . 'Manual Sync requested for SubID: ' . $sub_id . ' for UserID: ' . $user_id);

        try {
            // Initialize Stripe Client
            $stripe = new \Stripe\StripeClient($secret_key);
            \Stripe\Stripe::setApiVersion("2024-04-10"); // Ensure consistent API version

            // --- Retrieve Subscription from Stripe ---
            $subscription = $stripe->subscriptions->retrieve($sub_id, []);
            $new_status = $subscription->status; // Get current status from Stripe
            error_log($error_log_prefix . 'Retrieved subscription ' . $sub_id . '. Current Stripe Status: ' . $new_status);

            // --- Update WordPress Data ---
            $user = get_userdata($user_id); // Get user data object
            if (!$user) {
                error_log($error_log_prefix . 'User not found for UserID: ' . $user_id);
                throw new Exception('ユーザーが見つかりません (ID: ' . $user_id . ')');
            }

            // 1. Update User Meta Status
            update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_status', $new_status);
            error_log($error_log_prefix . "Updated user meta status to '{$new_status}' for UserID: {$user_id}");

            // 2. Update User Role based on Status
            $subscriber_role = $options['sub_subscriber_role'] ?? null;
            if ($subscriber_role && get_role($subscriber_role)) {
                $user_obj = new WP_User($user_id); // We already have $user, but let's use new for consistency
                $active_statuses = ['active', 'trialing'];

                if (in_array($new_status, $active_statuses)) {
                    // Add/Set role if status is active
                    if (!$user_obj->has_cap($subscriber_role)) {
                        $user_obj->set_role($subscriber_role);
                        error_log($error_log_prefix . "Assigned role '{$subscriber_role}' for UserID {$user_id} during sync (status: {$new_status})");
                    } else {
                        error_log($error_log_prefix . "User ID {$user_id} already has role '{$subscriber_role}'. No change needed.");
                    }
                } else {
                    // Remove role if status is inactive
                    if ($user_obj->has_cap($subscriber_role)) {
                        $user_obj->remove_role($subscriber_role);
                        error_log($error_log_prefix . "Removed role '{$subscriber_role}' for UserID {$user_id} during sync (status: {$new_status})");
                    } else {
                        error_log($error_log_prefix . "User ID {$user_id} does not have role '{$subscriber_role}'. No role removed.");
                    }
                }
            } else if ($subscriber_role) {
                error_log($error_log_prefix . "Configured subscriber role '{$subscriber_role}' does not exist.");
            }

            // --- Send Success Response ---
            wp_send_json_success([
                'message' => 'Stripeから最新情報を取得し、ステータスと権限を同期しました。',
                'new_status' => $new_status // Return new status for potential JS update
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log($error_log_prefix . 'Stripe API Error syncing subscription ' . $sub_id . ': ' . $e->getMessage());
            wp_send_json_error(['message' => 'Stripe APIエラー: ' . esc_html($e->getMessage())]);
        } catch (Exception $e) {
            error_log($error_log_prefix . 'General Error syncing subscription ' . $sub_id . ': ' . $e->getMessage());
            wp_send_json_error(['message' => '同期処理中にエラーが発生しました: ' . esc_html($e->getMessage())]);
        } finally {
            // Reset API key if needed (though StripeClient doesn't use global key)
        }
    } // end ajax_sync_subscription

    /**
     * Displays the settings page content with API key validation.
     */
    /**
     * Displays the settings page content with Tabs and complete form fields.
     */
    function show_setting_page() {
        // --- 保存処理 ---
        if (isset($_POST['submit_stripe_settings']) && check_admin_referer(EDEL_STRIPE_PAYMENT_PREFIX . 'submit_stripe_settings')) {
            $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);

            // === 共通設定 ===
            $options['mode']                 = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'test';
            $options['test_publishable_key'] = isset($_POST['test_publishable_key']) ? sanitize_text_field(trim($_POST['test_publishable_key'])) : '';
            $options['live_publishable_key'] = isset($_POST['live_publishable_key']) ? sanitize_text_field(trim($_POST['live_publishable_key'])) : '';
            $options['test_webhook_secret']  = isset($_POST['test_webhook_secret']) ? sanitize_text_field(trim($_POST['test_webhook_secret'])) : '';
            $options['live_webhook_secret']  = isset($_POST['live_webhook_secret']) ? sanitize_text_field(trim($_POST['live_webhook_secret'])) : '';
            $options['test_secret_key']      = isset($_POST['test_secret_key']) ? sanitize_text_field(trim($_POST['test_secret_key'])) : '';
            $options['live_secret_key']      = isset($_POST['live_secret_key']) ? sanitize_text_field(trim($_POST['live_secret_key'])) : '';
            $options['mail_from_name']       = isset($_POST['mail_from_name']) ? sanitize_text_field($_POST['mail_from_name']) : '';
            $options['mail_from_email']      = isset($_POST['mail_from_email']) ? sanitize_email($_POST['mail_from_email']) : '';
            $options['admin_notify_email']   = isset($_POST['admin_notify_email']) ? sanitize_email($_POST['admin_notify_email']) : '';
            // メール形式検証
            if (!empty($options['mail_from_email']) && !is_email($options['mail_from_email'])) {
                add_settings_error('edel_stripe_options', 'invalid_from_email', '共通設定: 送信元メールアドレスの形式が正しくありません。');
                $options['mail_from_email'] = '';
            }
            if (!empty($options['admin_notify_email']) && !is_email($options['admin_notify_email'])) {
                add_settings_error('edel_stripe_options', 'invalid_admin_email', '共通設定: 管理者受信メールアドレスの形式が正しくありません。');
                $options['admin_notify_email'] = '';
            }

            // === 買い切り設定 ===
            $options['require_consent']      = isset($_POST['require_consent']) ? '1' : '0';
            $options['privacy_page_id']      = isset($_POST['privacy_page_id']) ? intval($_POST['privacy_page_id']) : 0;
            $options['terms_page_id']        = isset($_POST['terms_page_id']) ? intval($_POST['terms_page_id']) : 0;
            $options['consent_text']         = isset($_POST['consent_text']) ? wp_kses_post($_POST['consent_text']) : '';
            $options['frontend_success_message'] = isset($_POST['frontend_success_message']) ? sanitize_textarea_field($_POST['frontend_success_message']) : '';
            $options['ot_send_customer_email']  = isset($_POST['ot_send_customer_email']) ? '1' : '0';
            $options['ot_admin_mail_subject']   = isset($_POST['ot_admin_mail_subject']) ? sanitize_text_field($_POST['ot_admin_mail_subject']) : '';
            $options['ot_admin_mail_body']      = isset($_POST['ot_admin_mail_body']) ? wp_kses_post($_POST['ot_admin_mail_body']) : '';
            $options['ot_customer_mail_subject'] = isset($_POST['ot_customer_mail_subject']) ? sanitize_text_field($_POST['ot_customer_mail_subject']) : '';
            $options['ot_customer_mail_body']   = isset($_POST['ot_customer_mail_body']) ? wp_kses_post($_POST['ot_customer_mail_body']) : '';

            // === サブスク設定 ===
            $options['sub_subscriber_role']     = isset($_POST['sub_subscriber_role']) ? sanitize_text_field($_POST['sub_subscriber_role']) : '';
            $options['sub_send_customer_email'] = isset($_POST['sub_send_customer_email']) ? '1' : '0';
            $options['sub_admin_mail_subject']  = isset($_POST['sub_admin_mail_subject']) ? sanitize_text_field($_POST['sub_admin_mail_subject']) : '';
            $options['sub_admin_mail_body']     = isset($_POST['sub_admin_mail_body']) ? wp_kses_post($_POST['sub_admin_mail_body']) : '';
            $options['sub_customer_mail_subject'] = isset($_POST['sub_customer_mail_subject']) ? sanitize_text_field($_POST['sub_customer_mail_subject']) : '';
            $options['sub_customer_mail_body']  = isset($_POST['sub_customer_mail_body']) ? wp_kses_post($_POST['sub_customer_mail_body']) : '';

            // 支払い失敗時メール設定の取得・サニタイズ
            $options['sub_fail_send_admin'] = isset($_POST['sub_fail_send_admin']) ? '1' : '0';
            $options['sub_fail_admin_subject'] = isset($_POST['sub_fail_admin_subject']) ? sanitize_text_field($_POST['sub_fail_admin_subject']) : '';
            $options['sub_fail_admin_body'] = isset($_POST['sub_fail_admin_body']) ? wp_kses_post($_POST['sub_fail_admin_body']) : '';
            $options['sub_fail_send_customer'] = isset($_POST['sub_fail_send_customer']) ? '1' : '0';
            $options['sub_fail_customer_subject'] = isset($_POST['sub_fail_customer_subject']) ? sanitize_text_field($_POST['sub_fail_customer_subject']) : '';
            $options['sub_fail_customer_body'] = isset($_POST['sub_fail_customer_body']) ? wp_kses_post($_POST['sub_fail_customer_body']) : '';
            // キャンセル完了時メール設定の取得・サニタイズ
            $options['sub_cancel_send_admin'] = isset($_POST['sub_cancel_send_admin']) ? '1' : '0';
            $options['sub_cancel_admin_subject'] = isset($_POST['sub_cancel_admin_subject']) ? sanitize_text_field($_POST['sub_cancel_admin_subject']) : '';
            $options['sub_cancel_admin_body'] = isset($_POST['sub_cancel_admin_body']) ? wp_kses_post($_POST['sub_cancel_admin_body']) : '';
            $options['sub_cancel_send_customer'] = isset($_POST['sub_cancel_send_customer']) ? '1' : '0';
            $options['sub_cancel_customer_subject'] = isset($_POST['sub_cancel_customer_subject']) ? sanitize_text_field($_POST['sub_cancel_customer_subject']) : '';
            $options['sub_cancel_customer_body'] = isset($_POST['sub_cancel_customer_body']) ? wp_kses_post($_POST['sub_cancel_customer_body']) : '';

            // アンインストール時の設定
            $options['delete_data_on_uninstall'] = isset($_POST['delete_data_on_uninstall']) ? '1' : '0';

            // --- APIキー有効性チェック ---
            $test_key_valid = null;
            $live_key_valid = null;
            if (!empty($options['test_secret_key'])) {
                try {
                    \Stripe\Stripe::setApiKey($options['test_secret_key']);
                    \Stripe\Account::retrieve();
                    $test_key_valid = true;
                } catch (\Exception $e) {
                    add_settings_error('edel_stripe_options', 'invalid_test_key', 'テスト用シークレットキーが無効か接続エラーです: ' . esc_html($e->getMessage()));
                    $test_key_valid = false;
                }
            }
            if (!empty($options['live_secret_key'])) {
                try {
                    \Stripe\Stripe::setApiKey($options['live_secret_key']);
                    \Stripe\Account::retrieve();
                    $live_key_valid = true;
                } catch (\Exception $e) {
                    add_settings_error('edel_stripe_options', 'invalid_live_key', '本番用シークレットキーが無効か接続エラーです: ' . esc_html($e->getMessage()));
                    $live_key_valid = false;
                }
            }
            \Stripe\Stripe::setApiKey(null);

            // --- オプション更新 ---
            update_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', $options);

            // --- メッセージ表示 ---
            settings_errors('edel_stripe_options');
            if (empty(get_settings_errors('edel_stripe_options'))) {
                $success_message = "設定を保存しました。";
                if (!empty($options['test_secret_key'])) {
                    $success_message .= ($test_key_valid === true) ? " テストキーは有効です。" : "";
                }
                if (!empty($options['live_secret_key'])) {
                    $success_message .= ($live_key_valid === true) ? " 本番キーは有効です。" : "";
                }
                echo "<div class='notice notice-success is-dismissible'><p>{$success_message}</p></div>";
            }
        }

        // --- 設定値の読み込み ---
        $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options');
        // Common
        $mode                   = $options['mode'] ?? 'test';
        $test_publishable_key   = $options['test_publishable_key'] ?? '';
        $live_publishable_key   = $options['live_publishable_key'] ?? '';
        $test_secret_key        = $options['test_secret_key'] ?? '';
        $live_secret_key        = $options['live_secret_key'] ?? '';
        $test_webhook_secret    = $options['test_webhook_secret'] ?? '';
        $live_webhook_secret    = $options['live_webhook_secret'] ?? '';
        $default_from_name      = get_bloginfo('name');
        $default_from_email     = get_option('admin_email');
        $mail_from_name         = $options['mail_from_name'] ?? $default_from_name;
        $mail_from_email        = $options['mail_from_email'] ?? $default_from_email;
        $admin_notify_email     = $options['admin_notify_email'] ?? $default_from_email;
        // One-time
        $require_consent        = $options['require_consent'] ?? '1';
        $privacy_page_id        = $options['privacy_page_id'] ?? 0;
        $terms_page_id          = $options['terms_page_id'] ?? 0;
        $consent_text           = $options['consent_text'] ?? '';
        $default_ot_success_msg = "支払いが完了しました。ありがとうございます。\nメールをご確認ください。届いていない場合は、お問い合わせください。";
        $frontend_success_message = $options['frontend_success_message'] ?? $default_ot_success_msg;
        $ot_send_customer_email = $options['ot_send_customer_email'] ?? '0';

        // ★★★ 買い切り用デフォルトテンプレート定義 ★★★
        $default_ot_admin_subject = '[{site_name}] 新しい決済がありました(買い切り)';
        $default_ot_admin_body = "買い切り決済が完了しました。\n\n購入者Email: {customer_email}\n商品/内容: {item_name}\n金額: {amount}\n決済日時: {transaction_date}\n\nPayment Intent ID: {payment_intent_id}\nCustomer ID: {customer_id}\nWordPress User ID: {user_id}";
        $default_ot_customer_subject = '[{site_name}] ご購入ありがとうございます';
        $default_ot_customer_body = "{user_name} 様\n\n「{item_name}」のご購入ありがとうございます。\n金額: {amount}\n日時: {transaction_date}\n\n--\n{site_name}\n{site_url}";
        // ★★★ ここまで ★★★
        $ot_admin_mail_subject  = $options['ot_admin_mail_subject'] ?? $default_ot_admin_subject; // ★ デフォルト値を使用
        $ot_admin_mail_body     = $options['ot_admin_mail_body'] ?? $default_ot_admin_body; // ★ デフォルト値を使用
        $ot_customer_mail_subject = $options['ot_customer_mail_subject'] ?? $default_ot_customer_subject; // ★ デフォルト値を使用
        $ot_customer_mail_body  = $options['ot_customer_mail_body'] ?? $default_ot_customer_body; // ★ デフォルト値を使用
        // Subscription
        $sub_subscriber_role    = $options['sub_subscriber_role'] ?? '';
        $sub_send_customer_email = $options['sub_send_customer_email'] ?? '0';
        // ★ サブスク用デフォルトテンプレート定義 ★
        $default_sub_admin_subject = '[{site_name}] 新しいサブスクリプション申込がありました';
        $default_sub_admin_body = "サブスクリプション申込がありました。\n\n購入者Email: {customer_email}\nプラン: {item_name} ({plan_id})\n顧客ID: {customer_id}\nサブスクID: {subscription_id}\nUser ID: {user_id}";
        $default_sub_customer_subject = '[{site_name}] サブスクリプションへようこそ';
        $default_sub_customer_body = "{user_name} 様\n\nサブスクリプション「{item_name}」へのお申し込みありがとうございます。\n\nマイアカウントページから契約状況をご確認いただけます。\n\n--\n{site_name}\n{site_url}";
        // ★支払い失敗時デフォルト★
        $default_sub_fail_admin_subject = '[{site_name}] サブスク支払い失敗通知(Admin)';
        $default_sub_fail_admin_body = "支払い失敗。\nEmail:{customer_email}\nSubID:{subscription_id}\nPlan:{item_name}({plan_id})";
        $default_sub_fail_customer_subject = '[{site_name}] お支払い情報確認依頼';
        $default_sub_fail_customer_body = "{user_name} 様\nサブスク「{item_name}」の支払失敗。カード情報更新を。";
        // ★キャンセル時デフォルト★
        $default_sub_cancel_admin_subject = '[{site_name}] サブスクキャンセル通知(Admin)';
        $default_sub_cancel_admin_body = "キャンセル。\nEmail:{customer_email}\nSubID:{subscription_id}\nPlan:{item_name}({plan_id})";
        $default_sub_cancel_customer_subject = '[{site_name}] サブスク解約のお知らせ';
        $default_sub_cancel_customer_body = "{user_name} 様\n「{item_name}」の解約完了。";
        // ★ここまでデフォルト定義★
        $sub_admin_mail_subject = $options['sub_admin_mail_subject'] ?? $default_sub_admin_subject;
        $sub_admin_mail_body    = $options['sub_admin_mail_body'] ?? $default_sub_admin_body;
        $sub_customer_mail_subject = $options['sub_customer_mail_subject'] ?? $default_sub_customer_subject;
        $sub_customer_mail_body = $options['sub_customer_mail_body'] ?? $default_sub_customer_body;
        $sub_fail_send_admin = $options['sub_fail_send_admin'] ?? '1';
        $sub_fail_admin_subject = $options['sub_fail_admin_subject'] ?? $default_sub_fail_admin_subject;
        $sub_fail_admin_body = $options['sub_fail_admin_body'] ?? $default_sub_fail_admin_body;
        $sub_fail_send_customer = $options['sub_fail_send_customer'] ?? '1';
        $sub_fail_customer_subject = $options['sub_fail_customer_subject'] ?? $default_sub_fail_customer_subject;
        $sub_fail_customer_body = $options['sub_fail_customer_body'] ?? $default_sub_fail_customer_body;
        $sub_cancel_send_admin = $options['sub_cancel_send_admin'] ?? '1';
        $sub_cancel_admin_subject = $options['sub_cancel_admin_subject'] ?? $default_sub_cancel_admin_subject;
        $sub_cancel_admin_body = $options['sub_cancel_admin_body'] ?? $default_sub_cancel_admin_body;
        $sub_cancel_send_customer = $options['sub_cancel_send_customer'] ?? '1';
        $sub_cancel_customer_subject = $options['sub_cancel_customer_subject'] ?? $default_sub_cancel_customer_subject;
        $sub_cancel_customer_body = $options['sub_cancel_customer_body'] ?? $default_sub_cancel_customer_body;
        $delete_data_on_uninstall = $options['delete_data_on_uninstall'] ?? '0'; // デフォルトは OFF ('0')


        // Placeholders list
        $placeholders = '<code>{item_name}</code>, <code>{amount}</code>, <code>{customer_email}</code>, <code>{payment_intent_id}</code>(買い切り), <code>{customer_id}</code>, <code>{transaction_date}</code>, <code>{user_name}</code>, <code>{user_id}</code>, <code>{site_name}</code>, <code>{site_url}</code>, <code>{subscription_id}</code>(サブスク), <code>{plan_id}</code>(サブスク)';
        // Editable roles
        $editable_roles = array_reverse(get_editable_roles());

    ?>
        <div class="wrap edel-stripe-settings">
            <h1><?php echo esc_html(EDEL_STRIPE_PAYMENT_NAME); ?> 設定</h1>
            <?php settings_errors('edel_stripe_options'); ?>

            <nav class="nav-tab-wrapper wp-clearfix" aria-label="二次ナビゲーション">
                <a href="#tab-common" class="nav-tab nav-tab-active">共通設定</a>
                <a href="#tab-onetime" class="nav-tab">買い切り設定</a>
                <a href="#tab-subscription" class="nav-tab">サブスク設定</a>
            </nav>

            <form method="POST">
                <?php wp_nonce_field(EDEL_STRIPE_PAYMENT_PREFIX . 'submit_stripe_settings'); ?>

                <?php // --- Tab Content: Common ---
                ?>
                <div id="tab-common" class="tab-content active-tab">
                    <h2>APIキー設定</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="mode">動作環境</label></th>
                            <td>
                                <select name="mode" id="mode">
                                    <option value="test" <?php selected($mode, "test"); ?>>テスト環境</option>
                                    <option value="live" <?php selected($mode, "live"); ?>>本番環境</option>
                                </select>
                                <p class="description">「テスト環境」では実際の請求は発生しません。「本番環境」に切り替える前に十分なテストを行ってください。</p>
                            </td>
                        </tr>
                        <tr>
                            <th>テスト用APIキー</th>
                            <td>
                                <p class="key"><label for="test_publishable_key">公開可能キー (pk_test_...)</label><input type="text" id="test_publishable_key" name="test_publishable_key" value="<?php echo esc_attr($test_publishable_key); ?>" class="regular-text" placeholder="pk_test_..."></p>
                                <p class="key"><label for="test_secret_key">シークレットキー (sk_test_...)</label><input type="password" id="test_secret_key" name="test_secret_key" value="<?php echo esc_attr($test_secret_key); ?>" class="regular-text" placeholder="sk_test_..."></p>
                                <p class="description">Stripeダッシュボード > 開発者 > APIキー で確認できます（<a href="https://dashboard.stripe.com/test/apikeys" target="_blank">テスト用APIキーはこちら</a>）。テスト環境データを表示中にしてください。</p>
                            </td>
                        </tr>
                        <tr>
                            <th>本番用APIキー</th>
                            <td>
                                <p class="key"><label for="live_publishable_key">公開可能キー (pk_live_...)</label><input type="text" id="live_publishable_key" name="live_publishable_key" value="<?php echo esc_attr($live_publishable_key); ?>" class="regular-text" placeholder="pk_live_..."></p>
                                <p class="key"><label for="live_secret_key">シークレットキー (sk_live_...)</label><input type="password" id="live_secret_key" name="live_secret_key" value="<?php echo esc_attr($live_secret_key); ?>" class="regular-text" placeholder="sk_live_..."></p>
                                <p class="description">Stripeダッシュボード > 開発者 > APIキー で確認できます（<a href="https://dashboard.stripe.com/apikeys" target="_blank">本番用APIキーはこちら</a>）。本番環境データを表示中にしてください。</p>
                            </td>
                        </tr>
                    </table>
                    <hr>
                    <h2>Webhook設定</h2>
                    <p>Stripeからのイベント通知を安全に受信するために、Webhook署名シークレットを設定します。</p>
                    <table class="form-table">
                        <tr>
                            <th><label for="test_webhook_secret">テスト環境用<br>Webhook署名シークレット</label></th>
                            <td>
                                <input type="password" id="test_webhook_secret" name="test_webhook_secret" value="<?php echo esc_attr($test_webhook_secret); ?>" class="regular-text" placeholder="whsec_test_...">
                                <p class="description">Stripeダッシュボード > 開発者 > Webhook > (作成したエンドポイント) > 「署名シークレット」で確認できます。（テストモード用）</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="live_webhook_secret">本番環境用<br>Webhook署名シークレット</label></th>
                            <td>
                                <input type="password" id="live_webhook_secret" name="live_webhook_secret" value="<?php echo esc_attr($live_webhook_secret); ?>" class="regular-text" placeholder="whsec_...">
                                <p class="description">Stripeダッシュボード > 開発者 > Webhook > (作成したエンドポイント) > 「署名シークレット」で確認できます。（本番モード用）</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Webhook エンドポイントURL</th>
                            <td>
                                <code><?php echo esc_url(home_url('/wp-json/edel-stripe/v1/webhook')); ?></code>
                                <p class="description">このURLをStripeダッシュボードのWebhook設定に登録してください。リッスンするイベントを選択する必要があります（例: <code>invoice.payment_succeeded</code>, <code>invoice.payment_failed</code>, <code>customer.subscription.updated</code>, <code>customer.subscription.deleted</code> など）。</p>
                            </td>
                        </tr>
                    </table>
                    <hr>
                    <h2>共通メール設定</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="mail_from_name">送信者名</label></th>
                            <td><input type="text" id="mail_from_name" name="mail_from_name" value="<?php echo esc_attr($mail_from_name); ?>" class="regular-text" placeholder="<?php echo esc_attr($default_from_name); ?>">
                                <p class="description">全ての通知メールの「From」ヘッダー名。空の場合はサイト名「<?php echo esc_html($default_from_name); ?>」</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="mail_from_email">送信元メールアドレス</label></th>
                            <td><input type="email" id="mail_from_email" name="mail_from_email" value="<?php echo esc_attr($mail_from_email); ?>" class="regular-text" placeholder="<?php echo esc_attr($default_from_email); ?>">
                                <p class="description">全ての通知メールの「From」ヘッダーアドレス。空の場合は管理者アドレス「<?php echo esc_html($default_from_email); ?>」。<span class="orange b">※注意: サーバーによっては書き換えが制限されます。</span></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="admin_notify_email">管理者 受信メールアドレス</label></th>
                            <td><input type="email" id="admin_notify_email" name="admin_notify_email" value="<?php echo esc_attr($admin_notify_email); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                                <p class="description">決済関連の管理者通知を受け取るアドレス。空の場合は「<?php echo esc_html(get_option('admin_email')); ?>」</p>
                            </td>
                        </tr>
                    </table>
                </div><?php // --- Tab Content: One-time ---
                        ?>
                <div id="tab-onetime" class="tab-content">
                    <h2>同意チェックボックス設定</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="require_consent">表示設定</label></th>
                            <td><label><input type="checkbox" id="require_consent" name="require_consent" value="1" <?php checked($require_consent, '1'); ?>> 決済フォームに同意チェックボックスを表示し必須にする</label>
                                <p class="description"><span class="orange b">注意：アカウント作成を伴うため同意取得推奨</span></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="privacy_page_id">プライバシーポリシーページ</label></th>
                            <td><?php wp_dropdown_pages(['name' => 'privacy_page_id', 'id' => 'privacy_page_id', 'show_option_none' => '— 指定しない —', 'option_none_value' => '0', 'selected' => $privacy_page_id, 'post_status' => 'publish']); ?>
                                <p class="description">同意文言に含めるプライバシーポリシーページを選択。</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="terms_page_id">利用規約ページ (任意)</label></th>
                            <td><?php wp_dropdown_pages(['name' => 'terms_page_id', 'id' => 'terms_page_id', 'show_option_none' => '— 指定しない —', 'option_none_value' => '0', 'selected' => $terms_page_id, 'post_status' => 'publish']); ?>
                                <p class="description">同意文言に含める利用規約ページを選択。</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="consent_text">同意文言 (任意)</label></th>
                            <td><textarea id="consent_text" name="consent_text" rows="4" class="large-text" placeholder="<?php echo esc_attr('{privacy_policy_link} と {terms_link} を確認し、アカウント作成に同意します。'); ?>"><?php echo esc_textarea($consent_text); ?></textarea>
                                <p class="description">空の場合はデフォルト文言。<code>[privacy_policy_link]</code> と <code>[terms_link]</code> が使えます。</p>
                            </td>
                        </tr>
                    </table>
                    <hr>
                    <h2>メール通知設定（買い切り）</h2>
                    <table class="form-table">
                        <tr>
                            <th colspan="2">
                                <h3>管理者向け通知メール</h3>
                            </th>
                        </tr>
                        <tr>
                            <th><label for="ot_admin_mail_subject">件名</label></th>
                            <td><input type="text" id="ot_admin_mail_subject" name="ot_admin_mail_subject" value="<?php echo esc_attr($ot_admin_mail_subject); ?>" class="large-text" placeholder="<?php echo esc_attr($default_admin_subject); // Use default as placeholder
                                                                                                                                                                                                    ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="ot_admin_mail_body">本文</label></th>
                            <td><textarea id="ot_admin_mail_body" name="ot_admin_mail_body" rows="6" class="large-text"><?php echo esc_textarea($ot_admin_mail_body); ?></textarea>
                                <p class="description">利用可能なプレースホルダー: <?php echo $placeholders; ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th colspan="2">
                                <h3>購入者向け通知メール</h3>
                            </th>
                        </tr>
                        <tr>
                            <th><label for="ot_send_customer_email">送信設定</label></th>
                            <td><label><input type="checkbox" id="ot_send_customer_email" name="ot_send_customer_email" value="1" <?php checked($ot_send_customer_email, '1'); ?>> 購入者へ完了メールを送信する</label>
                                <p class="description">Stripeからの領収書メールと重複注意。</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ot_customer_mail_subject">件名</label></th>
                            <td><input type="text" id="ot_customer_mail_subject" name="ot_customer_mail_subject" value="<?php echo esc_attr($ot_customer_mail_subject); ?>" class="large-text" placeholder="<?php echo esc_attr($default_customer_subject); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="ot_customer_mail_body">本文</label></th>
                            <td><textarea id="ot_customer_mail_body" name="ot_customer_mail_body" rows="6" class="large-text"><?php echo esc_textarea($ot_customer_mail_body); ?></textarea>
                                <p class="description">利用可能なプレースホルダー: <?php echo $placeholders; ?></p>
                            </td>
                        </tr>
                    </table>
                    <hr>
                    <h2>フロント表示メッセージ設定（買い切り）</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="frontend_success_message">決済成功時メッセージ</label></th>
                            <td><textarea id="frontend_success_message" name="frontend_success_message" rows="3" class="large-text" placeholder="<?php echo esc_attr($default_ot_success_msg); ?>"><?php echo esc_textarea($frontend_success_message); ?></textarea>
                                <p class="description">フォームに表示される最終メッセージ。</p>
                            </td>
                        </tr>
                    </table>

                    <hr>
                    <h2><span class="b">使い方：ショートコード</span></h2>
                    <p>以下のショートコードを固定ページや投稿の本文内に貼り付けることで、決済フォームを表示できます。</p>
                    <p><code>[stripe_onetime amount="1000" item_name="サンプル商品" button_text="今すぐ購入"]</code></p>
                    <p><strong>属性（Attributes）:</strong></p>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li><code>amount</code> (<span class="orange b">必須</span>): 決済金額（日本円の整数）。例: <code>1000</code> は1000円。</li>
                        <li><code>item_name</code> (任意): 商品やサービスの名前。決済フォームの見出しやメールで使用されます。デフォルト: "One-time Payment"</li>
                        <li><code>button_text</code> (任意): 決済ボタンに表示されるテキスト。デフォルト: "支払う"</li>
                    </ul>

                </div><?php // --- Tab Content: Subscription ---
                        ?>
                <div id="tab-subscription" class="tab-content">
                    <h2>利用可能なサブスクリプションプラン (Stripe)</h2>
                    <p>Stripeダッシュボードで作成済みの「継続タイプ」の「価格」一覧です。ショートコード <code>[edel_stripe_subscription plan_id="ここにIDを記述"]</code> で使用します。</p>
                    <div id="edel-stripe-plan-list">
                        <?php // --- Stripe Plan Lister code (変更なし) ---
                        $secret_key = ($mode === 'live') ? $live_secret_key : $test_secret_key;
                        $display_data = array();
                        $error_message = '';
                        if (!empty($secret_key)) {
                            try {
                                \Stripe\Stripe::setApiKey($secret_key);
                                $prices = \Stripe\Price::all(['type' => 'recurring', 'active' => true, 'limit' => 100]);
                                foreach ($prices->data as $price) {
                                    $product_name = '取得エラー';
                                    $product_id = $price->product ?? null;
                                    if ($product_id) {
                                        try {
                                            $product = \Stripe\Product::retrieve($product_id);
                                            $product_name = $product->name ?? '名前なし';
                                        } catch (\Exception $e) {
                                            error_log('Edel Product Error:' . $e->getMessage());
                                        }
                                    }
                                    $interval = $price->recurring->interval ?? '?';
                                    $interval_count = $price->recurring->interval_count ?? 1;
                                    $interval_str = $interval_count > 1 ? $interval_count . ' ' : '';
                                    if ($interval == 'month') $interval_str .= $interval_count > 1 ? 'ヶ月' : '月';
                                    elseif ($interval == 'year') $interval_str .= $interval_count > 1 ? '年' : '年';
                                    else $interval_str .= $interval;
                                    $trial_str = ($price->recurring->trial_period_days) ? $price->recurring->trial_period_days . '日間' : 'なし';
                                    $amount_str = isset($price->unit_amount) ? number_format($price->unit_amount) . '円' : '?';
                                    $display_data[] = ['product_name' => $product_name, 'price_id' => $price->id, 'amount_str' => $amount_str . ' / ' . esc_html($interval_str), 'trial_str' => $trial_str];
                                }
                                \Stripe\Stripe::setApiKey(null);
                            } catch (\Exception $e) {
                                $error_message = 'Stripe API エラー: ' . $e->getMessage();
                                error_log(EDEL_STRIPE_PAYMENT_PREFIX . $error_message);
                                \Stripe\Stripe::setApiKey(null);
                            }
                        } else {
                            $error_message = 'Stripe APIキー未設定';
                        }
                        if (!empty($error_message)) {
                            echo '<div class="notice notice-warning inline"><p>' . esc_html($error_message) . '</p></div>';
                        } elseif (empty($display_data)) {
                            echo '<div class="notice notice-info inline"><p>有効な継続課金プランが見つかりません。</p></div>';
                        } else { ?>
                            <table class="wp-list-table widefat striped">
                                <thead>
                                    <tr>
                                        <th>プラン名</th>
                                        <th>価格ID (plan_id)</th>
                                        <th>金額/期間</th>
                                        <th>トライアル</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($display_data as $data): ?>
                                        <tr>
                                            <td><?php echo esc_html($data['product_name']); ?></td>
                                            <td><code><?php echo esc_html($data['price_id']); ?></code> <button type="button" class="button button-small copy-clipboard" data-clipboard-text="<?php echo esc_attr($data['price_id']); ?>">コピー</button></td>
                                            <td><?php echo esc_html($data['amount_str']); ?></td>
                                            <td><?php echo esc_html($data['trial_str']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="description">ショートコードの <code>plan_id</code> 属性には上記の「価格ID」を指定してください。</p>
                            <button type="button" class="button" onclick="location.reload();">最新の情報に更新</button>
                        <?php } ?>
                    </div>
                    <hr>
                    <h2>ユーザー権限設定</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="sub_subscriber_role">有効な購読者の権限</label></th>
                            <td>
                                <select name="sub_subscriber_role" id="sub_subscriber_role">
                                    <option value="" <?php selected($sub_subscriber_role, ''); ?>>— 権限を変更しない —</option>
                                    <?php foreach ($editable_roles as $role => $details): ?>
                                        <option value="<?php echo esc_attr($role); ?>" <?php selected($sub_subscriber_role, $role); ?>>
                                            <?php echo esc_html(translate_user_role($details['name'])); ?> (<?php echo esc_html($role); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">サブスクリプションが有効なユーザーに自動で割り当てるロール。キャンセルで削除されます（他ロール保持）。</p>
                            </td>
                        </tr>
                    </table>
                    <hr>
                    <h2>メール通知設定（サブスクリプション）</h2>
                    <p>サブスクリプションに関連するイベント発生時に送信されるメールです。</p>
                    <table class="form-table">
                        <tr>
                            <th colspan="2">
                                <h3>管理者向け通知メール (申込成功時)</h3>
                            </th>
                        </tr>
                        <tr>
                            <th><label for="sub_admin_mail_subject">件名</label></th>
                            <td><input type="text" id="sub_admin_mail_subject" name="sub_admin_mail_subject" value="<?php echo esc_attr($sub_admin_mail_subject); ?>" class="large-text"></td>
                        </tr>
                        <tr>
                            <th><label for="sub_admin_mail_body">本文</label></th>
                            <td><textarea id="sub_admin_mail_body" name="sub_admin_mail_body" rows="6" class="large-text"><?php echo esc_textarea($sub_admin_mail_body); ?></textarea>
                                <p class="description">利用可能なプレースホルダー: <?php echo $placeholders; ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th colspan="2">
                                <h3>購入者向け通知メール (申込成功時)</h3>
                            </th>
                        </tr>
                        <tr>
                            <th><label for="sub_send_customer_email">送信設定</label></th>
                            <td><label><input type="checkbox" id="sub_send_customer_email" name="sub_send_customer_email" value="1" <?php checked($sub_send_customer_email, '1'); ?>> 申込完了時に購入者へメールを送信する</label></td>
                        </tr>
                        <tr>
                            <th><label for="sub_customer_mail_subject">件名</label></th>
                            <td><input type="text" id="sub_customer_mail_subject" name="sub_customer_mail_subject" value="<?php echo esc_attr($sub_customer_mail_subject); ?>" class="large-text"></td>
                        </tr>
                        <tr>
                            <th><label for="sub_customer_mail_body">本文</label></th>
                            <td><textarea id="sub_customer_mail_body" name="sub_customer_mail_body" rows="6" class="large-text"><?php echo esc_textarea($sub_customer_mail_body); ?></textarea>
                                <p class="description">利用可能なプレースホルダー: <?php echo $placeholders; ?></p>
                            </td>
                        </tr>

                        <tr>
                            <td colspan="2">
                                <hr>
                            </td>
                        </tr> <?php // Separator
                                ?>

                        <tr>
                            <th colspan="2">
                                <h3>管理者向け通知メール (支払い失敗時)</h3>
                            </th>
                        </tr>
                        <tr>
                            <th><label for="sub_fail_send_admin">送信設定</label></th>
                            <td><label><input type="checkbox" id="sub_fail_send_admin" name="sub_fail_send_admin" value="1" <?php checked($sub_fail_send_admin, '1'); ?>> 支払い失敗時に管理者へメールを送信する</label></td>
                        </tr>
                        <tr>
                            <th><label for="sub_fail_admin_subject">件名</label></th>
                            <td><input type="text" id="sub_fail_admin_subject" name="sub_fail_admin_subject" value="<?php echo esc_attr($sub_fail_admin_subject); ?>" class="large-text"></td>
                        </tr>
                        <tr>
                            <th><label for="sub_fail_admin_body">本文</label></th>
                            <td><textarea id="sub_fail_admin_body" name="sub_fail_admin_body" rows="6" class="large-text"><?php echo esc_textarea($sub_fail_admin_body); ?></textarea>
                                <p class="description">利用可能なプレースホルダー: <?php echo $placeholders; ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th colspan="2">
                                <h3>購入者向け通知メール (支払い失敗時)</h3>
                            </th>
                        </tr>
                        <tr>
                            <th><label for="sub_fail_send_customer">送信設定</label></th>
                            <td><label><input type="checkbox" id="sub_fail_send_customer" name="sub_fail_send_customer" value="1" <?php checked($sub_fail_send_customer, '1'); ?>> 支払い失敗時に購入者へメールを送信する</label></td>
                        </tr>
                        <tr>
                            <th><label for="sub_fail_customer_subject">件名</label></th>
                            <td><input type="text" id="sub_fail_customer_subject" name="sub_fail_customer_subject" value="<?php echo esc_attr($sub_fail_customer_subject); ?>" class="large-text"></td>
                        </tr>
                        <tr>
                            <th><label for="sub_fail_customer_body">本文</label></th>
                            <td><textarea id="sub_fail_customer_body" name="sub_fail_customer_body" rows="6" class="large-text"><?php echo esc_textarea($sub_fail_customer_body); ?></textarea>
                                <p class="description">利用可能なプレースホルダー: <?php echo $placeholders; ?></p>
                            </td>
                        </tr>

                        <tr>
                            <td colspan="2">
                                <hr>
                            </td>
                        </tr> <?php // Separator
                                ?>

                        <tr>
                            <th colspan="2">
                                <h3>管理者向け通知メール (キャンセル完了時)</h3>
                            </th>
                        </tr>
                        <tr>
                            <th><label for="sub_cancel_send_admin">送信設定</label></th>
                            <td><label><input type="checkbox" id="sub_cancel_send_admin" name="sub_cancel_send_admin" value="1" <?php checked($sub_cancel_send_admin, '1'); ?>> キャンセル時に管理者へメールを送信する</label></td>
                        </tr>
                        <tr>
                            <th><label for="sub_cancel_admin_subject">件名</label></th>
                            <td><input type="text" id="sub_cancel_admin_subject" name="sub_cancel_admin_subject" value="<?php echo esc_attr($sub_cancel_admin_subject); ?>" class="large-text"></td>
                        </tr>
                        <tr>
                            <th><label for="sub_cancel_admin_body">本文</label></th>
                            <td><textarea id="sub_cancel_admin_body" name="sub_cancel_admin_body" rows="6" class="large-text"><?php echo esc_textarea($sub_cancel_admin_body); ?></textarea>
                                <p class="description">利用可能なプレースホルダー: <?php echo $placeholders; ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th colspan="2">
                                <h3>購入者向け通知メール (キャンセル完了時)</h3>
                            </th>
                        </tr>
                        <tr>
                            <th><label for="sub_cancel_send_customer">送信設定</label></th>
                            <td><label><input type="checkbox" id="sub_cancel_send_customer" name="sub_cancel_send_customer" value="1" <?php checked($sub_cancel_send_customer, '1'); ?>> キャンセル時に購入者へメールを送信する</label></td>
                        </tr>
                        <tr>
                            <th><label for="sub_cancel_customer_subject">件名</label></th>
                            <td><input type="text" id="sub_cancel_customer_subject" name="sub_cancel_customer_subject" value="<?php echo esc_attr($sub_cancel_customer_subject); ?>" class="large-text"></td>
                        </tr>
                        <tr>
                            <th><label for="sub_cancel_customer_body">本文</label></th>
                            <td><textarea id="sub_cancel_customer_body" name="sub_cancel_customer_body" rows="6" class="large-text"><?php echo esc_textarea($sub_cancel_customer_body); ?></textarea>
                                <p class="description">利用可能なプレースホルダー: <?php echo $placeholders; ?></p>
                            </td>
                        </tr>
                    </table>
                    <hr>
                    <h2>アンインストール設定</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="delete_data_on_uninstall">データ削除</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="delete_data_on_uninstall" name="delete_data_on_uninstall" value="1" <?php checked($delete_data_on_uninstall, '1'); ?>>
                                    <span style="color: red; font-weight: bold;">プラグイン削除時に、データベーステーブルと設定情報を完全に削除する</span>
                                </label>
                                <p class="description">
                                    <span style="color: red;">注意: このオプションを有効にすると、プラグインを「削除」した際に、全ての決済履歴・サブスク履歴（カスタムテーブル）とプラグイン設定が完全に失われ、元に戻すことはできません。</span><br>
                                    プラグインを一時的に「停止」するだけではデータは削除されません。通常はこのオプションを有効にする必要はありません。<br>
                                    <span style="color: red;">※ユーザーメタ情報（Stripe顧客ID、サブスクID/ステータス）は、このオプションを有効にしても削除されません。</span>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
                <p class="submit">
                    <input type="submit" name="submit_stripe_settings" class="button button-primary" value="設定を保存">
                </p>
            </form>
        </div>
        <?php // Tab/Copy JS
        ?>
        <script>
            jQuery(document).ready(function($) {
                // Tab logic from previous response
                $('.edel-stripe-settings .nav-tab-wrapper a').on('click', function(e) {
                    e.preventDefault();
                    var target = $(this).attr('href');
                    $('.edel-stripe-settings .nav-tab').removeClass('nav-tab-active');
                    $('.edel-stripe-settings .tab-content').removeClass('active-tab').hide();
                    $(this).addClass('nav-tab-active');
                    $(target).addClass('active-tab').show();
                    if (window.localStorage) localStorage.setItem('edelStripeActiveTab', target);
                });
                var activeTab = '#tab-common';
                if (window.localStorage) {
                    var storedTab = localStorage.getItem('edelStripeActiveTab');
                    if (storedTab && $('.edel-stripe-settings .nav-tab-wrapper a[href="' + storedTab + '"]').length) activeTab = storedTab;
                }
                if (window.location.hash && $('.edel-stripe-settings .nav-tab-wrapper a[href="' + window.location.hash + '"]').length) activeTab = window.location.hash;
                $('.edel-stripe-settings .nav-tab-wrapper a[href="' + activeTab + '"]').addClass('nav-tab-active');
                $(activeTab).addClass('active-tab').show();
                // Copy logic from previous response
                $('.copy-clipboard').on('click', function(e) {
                    e.preventDefault();
                    var text = $(this).data('clipboard-text'),
                        btn = $(this);
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text).then(() => {
                            var txt = btn.text();
                            btn.text('コピー完了!');
                            setTimeout(() => {
                                btn.text(txt);
                            }, 1500);
                        }, () => {
                            alert('コピー失敗');
                        });
                    } else {
                        try {
                            var i = document.createElement("input");
                            i.style = "position:absolute;left:-1000px;top:-1000px";
                            i.value = text;
                            document.body.appendChild(i);
                            i.select();
                            document.execCommand("copy");
                            document.body.removeChild(i);
                            var txt = btn.text();
                            btn.text('コピー完了!');
                            setTimeout(() => {
                                btn.text(txt);
                            }, 1500);
                        } catch (err) {
                            alert('コピー失敗');
                        }
                    }
                });
            });
        </script>
<?php
    } // end show_setting_page()

    /**
     * Creates the custom database table on plugin activation with logging.
     */
    public static function create_custom_table() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        global $wpdb;
        $table_name = $wpdb->prefix . EDEL_STRIPE_PAYMENT_PREFIX . 'main';

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            payment_intent_id VARCHAR(255) NOT NULL,
            customer_id VARCHAR(255) NOT NULL,
            subscription_id VARCHAR(255) DEFAULT NULL,
            status VARCHAR(50) NOT NULL,
            amount BIGINT NOT NULL,
            currency VARCHAR(3) NOT NULL,
            item_name TEXT DEFAULT NULL,
            created_at_gmt DATETIME NOT NULL,
            updated_at_gmt DATETIME NOT NULL,
            metadata LONGTEXT DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_payment_intent_id (payment_intent_id),
            KEY idx_customer_id (customer_id),
            KEY idx_subscription_id (subscription_id),
            KEY idx_status (status),
            KEY idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        dbDelta($sql);
    } // end create_custom_table()
} // End class EdelStripePaymentAdmin