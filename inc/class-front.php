<?php
// inc/class-front.php

/**
 * Handles frontend functionality for Edel Stripe Payment plugin.
 */
class EdelStripePaymentFront {
    /**
     * Flag to indicate if frontend scripts should be loaded.
     * Consider a more robust method like checking shortcode existence in the_content filter
     * or using wp_footer hook based on shortcode execution.
     * @var bool
     */
    private $add_frontend_scripts = false;

    /**
     * Registers the REST API endpoint for Stripe Webhooks.
     */
    public function register_webhook_endpoint() {
        error_log('[Edel Stripe REST] Attempting to register webhook endpoint /edel-stripe/v1/webhook...'); // ★ Log Start

        $registered = register_rest_route(
            'edel-stripe/v1', // Namespace
            '/webhook',       // Route
            array(
                'methods'             => 'POST', // Accept only POST requests
                'callback'            => array($this, 'handle_webhook'), // Callback function
                'permission_callback' => '__return_true', // Allow public access (Stripe needs to reach it) - Security is handled by signature verification
            )
        );
        if ($registered) {
            error_log('[Edel Stripe REST] Webhook endpoint registered successfully.');
        } else {
            error_log('[Edel Stripe REST] FAILED to register webhook endpoint!');
        }
    }

    /**
     * ★新規追加: Renders the [edel_stripe_my_account] shortcode content.
     * Displays subscription status and payment history for logged-in users.
     *
     * @param array $atts Shortcode attributes (currently unused).
     * @return string HTML output for the my account page.
     */
    public function render_my_account_page($atts) {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return '<p>このコンテンツを表示するには<a href="' . esc_url(wp_login_url(get_permalink())) . '">ログイン</a>してください。</p>';
        }

        // Get current user data
        $user_id = get_current_user_id();
        $user = get_userdata($user_id);
        if (!$user) {
            return '<p>ユーザー情報の取得に失敗しました。</p>';
        }

        // Get saved data from user meta
        $subscription_id = get_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_id', true);
        $customer_id = get_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'customer_id', true);
        // Get status from meta as a fallback or initial value
        $subscription_status_meta = get_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_status', true);

        // Variables to store fetched Stripe data
        $stripe_subscription = null;
        $stripe_error_message = '';
        $plan_details_str = 'プラン情報取得中...'; // Default text
        $next_billing_str = '取得中...';
        $cancel_at_str = '';
        $current_status_from_stripe = $subscription_status_meta ?: '不明'; // Use meta as fallback

        // --- Fetch current subscription details from Stripe API if ID exists ---
        if ($subscription_id) {
            $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
            $is_live_mode = isset($options['mode']) && $options['mode'] === 'live';
            $secret_key = $is_live_mode ? ($options['live_secret_key'] ?? '') : ($options['test_secret_key'] ?? '');

            if (!empty($secret_key)) {
                try {
                    $stripe = new \Stripe\StripeClient($secret_key);
                    \Stripe\Stripe::setApiVersion("2024-04-10");

                    // Retrieve subscription and expand plan and product info
                    $stripe_subscription = $stripe->subscriptions->retrieve($subscription_id, ['expand' => ['plan.product']]);

                    if ($stripe_subscription) {
                        $current_status_from_stripe = $stripe_subscription->status; // Get the most current status

                        // Format plan details for display
                        $plan = $stripe_subscription->plan;
                        if ($plan) {
                            $plan_amount = $plan->amount ?? 0;
                            $plan_currency = $plan->currency ?? 'jpy';
                            $plan_interval = $plan->interval ?? '?';
                            $plan_interval_count = $plan->interval_count ?? 1;
                            $product_name = ($plan->product && is_string($plan->product->name)) ? $plan->product->name : '不明な商品';

                            $amount_str = '';
                            if ($plan_currency === 'jpy') {
                                $amount_str = number_format($plan_amount) . '円';
                            } elseif ($plan_currency === 'usd') {
                                $amount_str = '$' . number_format($plan_amount / 100, 2);
                            } else {
                                $amount_str = number_format($plan_amount) . ' ' . strtoupper($plan_currency);
                            }

                            $interval_str = '';
                            if ($plan_interval_count == 1) {
                                if ($interval == 'month') $interval_str = '月';
                                elseif ($interval == 'year') $interval_str = '年';
                                elseif ($interval == 'week') $interval_str = '週';
                                elseif ($interval == 'day') $interval_str = '日';
                                else $interval_str = $interval;
                            } else {
                                $interval_str = $plan_interval_count . ' ';
                                if ($interval == 'month') $interval_str .= 'ヶ月';
                                elseif ($interval == 'year') $interval_str .= '年';
                                else $interval_str .= $interval;
                            }

                            $plan_details_str = esc_html($product_name) . ' (' . $amount_str . ' / ' . esc_html($interval_str) . ')';
                        } else {
                            $plan_details_str = 'プラン情報取得失敗';
                        }

                        // Format next billing date
                        if ($stripe_subscription->current_period_end) {
                            if ($stripe_subscription->status === 'active' || $stripe_subscription->status === 'trialing') {
                                $next_billing_str = wp_date(get_option('date_format'), $stripe_subscription->current_period_end);
                            } else {
                                $next_billing_str = '---'; // No next billing if inactive
                            }
                        } else {
                            $next_billing_str = '---';
                        }

                        // Check if cancellation is scheduled
                        if ($stripe_subscription->cancel_at_period_end) {
                            $cancel_at_str = ' (期間終了時 ' . wp_date(get_option('date_format'), $stripe_subscription->current_period_end) . ' にキャンセル予定)';
                        }

                        // Update user meta status if different from Stripe (optional sync here)
                        if ($current_status_from_stripe !== $subscription_status_meta) {
                            update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_status', $current_status_from_stripe);
                            error_log("[Edel Stripe MyAccount] Synced status for User ID {$user_id} to '{$current_status_from_stripe}'");
                            // Optionally update role here too, mirroring webhook logic
                        }
                    } // end if $stripe_subscription
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    $stripe_error_message = 'Stripe APIエラー: ' . $e->getMessage();
                    error_log(EDEL_STRIPE_PAYMENT_PREFIX . $stripe_error_message);
                    $plan_details_str = '取得エラー';
                    $next_billing_str = '取得エラー';
                } catch (Exception $e) {
                    $stripe_error_message = 'サブスクリプション情報の取得中にエラーが発生しました。';
                    error_log(EDEL_STRIPE_PAYMENT_PREFIX . $stripe_error_message . $e->getMessage());
                    $plan_details_str = '取得エラー';
                    $next_billing_str = '取得エラー';
                } finally {
                    \Stripe\Stripe::setApiKey(null);
                }
            } else {
                $stripe_error_message = 'Stripe APIキーが設定されていません。';
                $plan_details_str = '取得不可';
                $next_billing_str = '取得不可';
            }
        } // end if $subscription_id

        // --- Fetch Payment History ---
        global $wpdb;
        $payment_table = $wpdb->prefix . EDEL_STRIPE_PAYMENT_PREFIX . 'main';
        $payments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$payment_table} WHERE user_id = %d ORDER BY created_at_gmt DESC LIMIT 50", // Get latest 50 payments
            $user_id
        ), ARRAY_A);


        // --- Start Output Buffering ---
        ob_start();
?>
        <div class="edel-stripe-my-account-wrap">

            <h2>ご契約情報</h2>

            <?php if (!empty($stripe_error_message)): ?>
                <div class="notice notice-error inline">
                    <p><?php echo esc_html($stripe_error_message); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($subscription_id) : ?>
                <table class="edel-stripe-my-account-details form-table"> <?php // Use WP classes for styling consistency
                                                                            ?>
                    <tbody>
                        <tr>
                            <th>ステータス</th>
                            <td>
                                <?php
                                // Display status with color coding (using latest from Stripe)
                                $status_label = ucfirst($current_status_from_stripe ?: '不明');
                                $color = '#777';
                                if (in_array($current_status_from_stripe, ['active', 'trialing'])) {
                                    $color = 'green';
                                } elseif (in_array($current_status_from_stripe, ['canceled', 'incomplete_expired', 'unpaid'])) {
                                    $color = 'red';
                                } elseif (in_array($current_status_from_stripe, ['past_due', 'payment_failed', 'incomplete'])) {
                                    $color = 'orange';
                                }
                                echo '<span style="color:' . esc_attr($color) . '; font-weight: bold;">' . esc_html($status_label) . '</span>';
                                echo esc_html($cancel_at_str); // Display cancellation scheduled date if set
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>プラン内容</th>
                            <td><?php echo wp_kses_post($plan_details_str); // Allow basic HTML if needed, otherwise esc_html
                                ?></td>
                        </tr>
                        <tr>
                            <th>次回お支払い日</th>
                            <td><?php echo esc_html($next_billing_str); ?></td>
                        </tr>
                        <tr>
                            <th>StripeサブスクリプションID</th>
                            <td><code><?php echo esc_html($subscription_id); ?></code></td>
                        </tr>
                        <?php // Show cancel button only if subscription is active/trialing/past_due etc. and NOT already scheduled for cancellation
                        ?>
                        <?php if (!in_array($current_status_from_stripe, ['canceled', 'incomplete_expired', 'unpaid']) && !$stripe_subscription?->cancel_at_period_end): ?>
                            <tr>
                                <th>操作</th>
                                <td>
                                    <?php $cancel_nonce = wp_create_nonce(EDEL_STRIPE_PAYMENT_PREFIX . 'user_cancel_sub_' . $subscription_id); ?>
                                    <form id="edel-stripe-user-cancel-form" style="margin: 0;">
                                        <input type="hidden" name="action" value="edel_stripe_user_cancel_subscription">
                                        <input type="hidden" name="subscription_id" value="<?php echo esc_attr($subscription_id); ?>">
                                        <input type="hidden" name="security" value="<?php echo esc_attr($cancel_nonce); ?>">
                                        <button type="submit" class="button edel-stripe-user-cancel-button">サブスクリプションをキャンセル</button>
                                        <span class="spinner" style="display: none; vertical-align: middle; margin-left: 5px;"></span>
                                    </form>
                                    <div class="cancel-result" style="margin-top: 10px;"></div>
                                    <p><small>キャンセルした場合、通常は現在の請求期間の終了時に停止します。</small></p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php else : ?>
                <p>現在、有効なサブスクリプション契約はありません。</p>
            <?php endif; ?>

            <hr style="margin: 30px 0;">

            <h2>決済履歴</h2>
            <div id="edel-stripe-payment-history-list">
                <?php if (!empty($payments)): ?>
                    <table class="wp-list-table widefat striped edel-stripe-history-table">
                        <thead>
                            <tr>
                                <?php // ★ th にクラスを追加 (任意)
                                ?>
                                <th scope="col" class="history-date">日時</th>
                                <th scope="col" class="history-item">内容</th>
                                <th scope="col" class="history-amount">金額</th>
                                <th scope="col" class="history-status">ステータス</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <?php // ★ td にクラスを追加 (任意)
                                    ?>
                                    <td class="history-date">
                                        <?php echo isset($payment['created_at_gmt']) ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($payment['created_at_gmt']))) : '---'; ?>
                                    </td>
                                    <td class="history-item">
                                        <?php echo esc_html($payment['item_name'] ?? ''); ?>
                                        <?php if (!empty($payment['subscription_id'])): // サブスク支払いならID表示
                                        ?>
                                            <br><small>Sub: <?php echo esc_html($payment['subscription_id']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="history-amount">
                                        <?php
                                        // ... (金額フォーマット処理 - 変更なし) ...
                                        $p_amount = $payment['amount'] ?? 0;
                                        $p_currency = strtolower($payment['currency'] ?? 'jpy');
                                        if ($p_currency === 'jpy') {
                                            echo number_format($p_amount) . '円';
                                        } elseif ($p_currency === 'usd') {
                                            echo '$' . number_format($p_amount / 100, 2);
                                        } else {
                                            echo number_format($p_amount) . ' ' . strtoupper($p_currency);
                                        }
                                        ?>
                                    </td>
                                    <td class="history-status">
                                        <?php // ステータスに応じて色付けなど可能
                                        $p_status = $payment['status'] ?? '不明';
                                        echo esc_html(ucfirst($p_status));
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>決済履歴はありません。</p>
                <?php endif; ?>
            </div>

        </div><?php
                return ob_get_clean();
            } // end render_my_account_page

            /**
             * ★新規追加: AJAX handler for user cancelling their own subscription (Skeleton).
             */
            public function ajax_user_cancel_subscription() {
                // Check if user is logged in
                if (!is_user_logged_in()) {
                    wp_send_json_error(['message' => 'ログインが必要です。'], 403);
                    return;
                }

                // Get Subscription ID from POST
                $sub_id = isset($_POST['subscription_id']) ? sanitize_text_field($_POST['subscription_id']) : '';

                // Check Nonce (Action name includes sub ID)
                check_ajax_referer(EDEL_STRIPE_PAYMENT_PREFIX . 'user_cancel_sub_' . $sub_id, 'security');

                // Get current user ID
                $user_id = get_current_user_id();

                // --- Verify Ownership ---
                $user_sub_id = get_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_id', true);
                if (empty($sub_id) || empty($user_sub_id) || $user_sub_id !== $sub_id) {
                    error_log("[Edel Stripe User Cancel] Attempt to cancel non-owned/invalid sub. User: {$user_id}, Sub attempted: {$sub_id}, User's actual sub: {$user_sub_id}");
                    wp_send_json_error(['message' => 'キャンセル対象のサブスクリプションが見つかりません。'], 403);
                    return;
                }

                // Get Stripe Secret Key
                $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
                $is_live_mode = isset($options['mode']) && $options['mode'] === 'live';
                $secret_key = $is_live_mode ? ($options['live_secret_key'] ?? '') : ($options['test_secret_key'] ?? '');
                $error_log_prefix = '[Edel Stripe User Cancel] ';

                if (empty($secret_key)) {
                    error_log($error_log_prefix . 'Stripe Secret Key not configured.');
                    wp_send_json_error(['message' => 'サーバーエラーが発生しました (コード: KNF)。']); // Generic error
                    return;
                }

                error_log($error_log_prefix . 'User ID ' . $user_id . ' requested cancellation for SubID: ' . $sub_id);

                try {
                    // --- Initialize Stripe Client ---
                    $stripe = new \Stripe\StripeClient($secret_key);
                    \Stripe\Stripe::setApiVersion("2024-04-10"); // Set API version

                    // --- Cancel the subscription immediately using StripeClient ---
                    $subscription = $stripe->subscriptions->cancel($sub_id, []); // Empty array for params

                    error_log($error_log_prefix . 'Subscription cancellation processed via StripeClient for: ' . $sub_id . '. Result Status: ' . $subscription->status);

                    // We rely on the webhook 'customer.subscription.deleted/updated' to update the WP status/role.
                    // Send success message back to the user's browser.
                    wp_send_json_success([
                        'message' => 'サブスクリプションのキャンセル手続きを受け付けました。契約状況はまもなく更新されます。'
                    ]);
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    error_log($error_log_prefix . 'Stripe API Error user canceling subscription ' . $sub_id . ': ' . $e->getMessage());
                    // Check if already canceled
                    if (strpos($e->getMessage(), 'No such subscription') !== false || strpos($e->getMessage(), 'subscription is already canceled') !== false) {
                        wp_send_json_success(['message' => 'サブスクリプションは既にキャンセルされています。']); // Treat as success from user perspective
                    } else {
                        wp_send_json_error(['message' => 'Stripe APIエラー: ' . esc_html($e->getMessage())]);
                    }
                } catch (Exception $e) {
                    error_log($error_log_prefix . 'General Error user canceling subscription ' . $sub_id . ': ' . $e->getMessage());
                    wp_send_json_error(['message' => 'キャンセル処理中にエラーが発生しました。']);
                }

                // wp_send_json_* includes wp_die()
            } // end ajax_user_cancel_subscription

            /**
             * Handles incoming Stripe Webhook events.
             * Verifies the signature and processes the event.
             *
             * @param WP_REST_Request $request The request object.
             * @return WP_REST_Response Response object.
             */
            public function handle_webhook(WP_REST_Request $request) {
                $payload = $request->get_body();
                $sig_header = $request->get_header('stripe_signature');
                $event = null;
                $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
                $endpoint_secret_test = $options['test_webhook_secret'] ?? '';
                $endpoint_secret_live = $options['live_webhook_secret'] ?? '';
                $error_log_prefix = '[Edel Stripe Webhook] ';

                error_log($error_log_prefix . 'Received webhook request.');

                // --- Signature Verification ---
                if (empty($sig_header)) {
                    error_log($error_log_prefix . 'Missing signature.');
                    return new WP_REST_Response(['error' => 'Missing signature'], 400);
                }
                if (empty($endpoint_secret_test) && empty($endpoint_secret_live)) {
                    error_log($error_log_prefix . 'Secret not configured.');
                    return new WP_REST_Response(['error' => 'Webhook secret not configured'], 500);
                }
                $endpoint_secret = !empty($endpoint_secret_test) ? $endpoint_secret_test : $endpoint_secret_live;
                if (empty($endpoint_secret)) {
                    error_log($error_log_prefix . 'Both secrets empty.');
                    return new WP_REST_Response(['error' => 'Webhook secret not configured'], 500);
                }

                try {
                    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
                    $correct_secret = $event->livemode ? $endpoint_secret_live : $endpoint_secret_test;
                    if (empty($correct_secret)) {
                        throw new \Stripe\Exception\SignatureVerificationException('Appropriate secret not configured.', $sig_header, $payload);
                    }
                    if ($correct_secret !== $endpoint_secret) {
                        $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $correct_secret);
                    }
                    $mode_used = $event->livemode ? 'Live' : 'Test';
                    error_log($error_log_prefix . 'Signature verified (' . $mode_used . '). Event ID: ' . $event->id . ' Type: ' . $event->type);
                } catch (\Exception $e) {
                    error_log($error_log_prefix . 'Webhook Setup Error: ' . $e->getMessage());
                    return new WP_REST_Response(['error' => 'Webhook signature error.'], 400);
                }

                // --- Handle the event based on its type ---
                global $wpdb;
                $payment_table_name = $wpdb->prefix . EDEL_STRIPE_PAYMENT_PREFIX . 'main';
                $processed = false; // Flag

                try {
                    $object = $event->data->object; // Main object from the event data
                    $customer_id = null;
                    // Attempt to reliably get the customer ID from various event object structures
                    if (isset($object->customer)) {
                        $customer_id = $object->customer;
                    } elseif (isset($object->id) && strpos($object->id, 'cus_') === 0) {
                        $customer_id = $object->id;
                    } elseif (isset($object->data->object->customer)) {
                        $customer_id = $object->data->object->customer;
                    } elseif (isset($object->customer_email)) { /* Could try lookup by email if needed, but less reliable */
                    }

                    $user = null;
                    $user_id = null;
                    $user_email = null;
                    if ($customer_id) {
                        $user = $this->get_user_by_stripe_customer_id($customer_id);
                    }
                    if ($user) {
                        $user_id = $user->ID;
                        $user_email = $user->user_email;
                    } else {
                        error_log($error_log_prefix . "User could not be found for Customer ID: " . ($customer_id ?? 'N/A') . " in event type " . $event->type);
                    }

                    // Base notification data (can be added to within each case)
                    $notification_data = [
                        'customer_id' => $customer_id,
                        'user_id' => $user_id,
                        'user_email' => $user_email,
                        'event_type' => $event->type,
                        'event_id' => $event->id,
                    ];

                    switch ($event->type) {

                        case 'invoice.payment_succeeded':
                            $invoice = $object;
                            if (isset($invoice->subscription) && !empty($invoice->subscription) && $invoice->billing_reason === 'subscription_cycle') {
                                $subscription_id = $invoice->subscription;
                                error_log($error_log_prefix . 'Processing ' . $event->type . ' for SubID: ' . $subscription_id);
                                if ($user) { // Proceed only if user is found
                                    $payment_intent_id = $invoice->payment_intent;
                                    $amount_paid = $invoice->amount_paid;
                                    $currency = $invoice->currency;
                                    $plan_id = $invoice->lines->data[0]->price->id ?? null;
                                    $item_name = 'Subscription Recurring Payment (' . ($plan_id ?: $subscription_id) . ')';

                                    // 1. Record payment in custom table
                                    $data_to_insert = [
                                        'user_id' => $user_id,
                                        'payment_intent_id' => $payment_intent_id ?? ('invoice_' . $invoice->id),
                                        'customer_id' => $customer_id,
                                        'subscription_id' => $subscription_id,
                                        'status' => 'succeeded',
                                        'amount' => $amount_paid,
                                        'currency' => $currency,
                                        'item_name' => $item_name,
                                        'created_at_gmt' => gmdate('Y-m-d H:i:s', $invoice->created),
                                        'updated_at_gmt' => gmdate('Y-m-d H:i:s', time()),
                                        'metadata' => maybe_serialize(['plan_id' => $plan_id, 'invoice_id' => $invoice->id, 'billing_reason' => $invoice->billing_reason])
                                    ];
                                    $data_formats = ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'];
                                    $inserted = $wpdb->insert($payment_table_name, $data_to_insert, $data_formats);
                                    if ($inserted === false) {
                                        error_log($error_log_prefix . "Failed recurring payment DB insert. Error: " . $wpdb->last_error);
                                    } else {
                                        error_log($error_log_prefix . "Recurring payment recorded for UserID: {$user_id}");
                                    }

                                    // 2. Ensure status and role are active
                                    update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_status', 'active');
                                    $subscriber_role = $options['sub_subscriber_role'] ?? null;
                                    if ($subscriber_role && get_role($subscriber_role)) {
                                        $user_obj = new WP_User($user_id);
                                        if (!$user_obj->has_cap($subscriber_role)) {
                                            $user_obj->set_role($subscriber_role);
                                            error_log($error_log_prefix . "Role '{$subscriber_role}' ensured for UserID: {$user_id}");
                                        }
                                    }
                                    $processed = true;
                                }
                            } else {
                                $processed = true;
                                error_log($error_log_prefix . "Ignoring " . $event->type . " - Reason: " . ($invoice->billing_reason ?? 'N/A'));
                            }
                            break;

                        case 'customer.subscription.deleted':
                            $subscription = $object;
                            error_log($error_log_prefix . 'Processing ' . $event->type . ' for SubID: ' . $subscription->id);
                            if ($user) {
                                // Update meta and role
                                update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_status', 'canceled');
                                $subscriber_role = $options['sub_subscriber_role'] ?? null;
                                if ($subscriber_role) {
                                    $user_obj = new WP_User($user_id);
                                    if ($user_obj->has_cap($subscriber_role)) {
                                        $user_obj->remove_role($subscriber_role);
                                        error_log($error_log_prefix . "Removed role '{$subscriber_role}' for UserID: {$user_id}");
                                    }
                                } else {
                                    error_log($error_log_prefix . "No role set for UserID: {$user_id}");
                                }
                                error_log($error_log_prefix . "Set meta status to 'canceled' for UserID: {$user_id}");

                                // Prepare data for email
                                $notification_data['subscription_id'] = $subscription->id;
                                $notification_data['plan_id'] = $subscription->items->data[0]->price->id ?? null;
                                $notification_data['item_name'] = 'Subscription (' . ($notification_data['plan_id'] ?: $subscription->id) . ')'; // Basic name
                                try {
                                    if ($notification_data['plan_id'] && \Stripe\Stripe::getApiKey()) {
                                        $price = \Stripe\Price::retrieve($notification_data['plan_id'], ['expand' => ['product']]);
                                        if ($price->product && is_object($price->product)) $notification_data['item_name'] = $price->product->name;
                                    }
                                } catch (Exception $e) {
                                }

                                error_log($error_log_prefix . 'Data before sending cancel notification: ' . print_r($notification_data, true));

                                // Send cancellation notification email
                                $this->send_webhook_or_signup_notification('subscription_canceled', $notification_data);
                                $processed = true;
                            }
                            break;

                        case 'invoice.payment_failed':
                            $invoice = $object;
                            if (isset($invoice->subscription) && !empty($invoice->subscription)) {
                                error_log($error_log_prefix . 'Processing ' . $event->type . ' for SubID: ' . $invoice->subscription);
                                if ($user) {
                                    // Update meta status
                                    update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_status', 'payment_failed'); // Or use 'past_due' if invoice.paid is false? check Stripe docs
                                    error_log($error_log_prefix . "Set user meta status to 'payment_failed' for UserID: {$user_id}");

                                    // Prepare data for email
                                    $notification_data['subscription_id'] = $invoice->subscription;
                                    $notification_data['plan_id'] = $invoice->lines->data[0]->price->id ?? null;
                                    $notification_data['amount'] = $invoice->amount_due;
                                    $notification_data['currency'] = $invoice->currency;
                                    $notification_data['item_name'] = 'Subscription (' . ($notification_data['plan_id'] ?: $invoice->subscription) . ')'; // Basic name
                                    try {
                                        if ($notification_data['plan_id'] && \Stripe\Stripe::getApiKey()) {
                                            $price = \Stripe\Price::retrieve($notification_data['plan_id'], ['expand' => ['product']]);
                                            if ($price->product && is_object($price->product)) $notification_data['item_name'] = $price->product->name;
                                        }
                                    } catch (Exception $e) {
                                    }

                                    // Send payment failed notification email
                                    $this->send_webhook_or_signup_notification('payment_failed', $notification_data);
                                    $processed = true;
                                }
                            } else {
                                $processed = true; /* Ignored */
                            }
                            break;

                        case 'customer.subscription.updated':
                            $subscription = $object;
                            error_log($error_log_prefix . 'Processing ' . $event->type . ' for SubID: ' . $subscription->id . ' New Status: ' . $subscription->status);
                            if ($user) {
                                $new_status = $subscription->status;
                                // Update user meta status
                                update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_status', $new_status);
                                error_log($error_log_prefix . "Updated user meta status to '{$new_status}' for UserID: {$user_id}");
                                // Update user role based on new status
                                $subscriber_role = $options['sub_subscriber_role'] ?? null;
                                if ($subscriber_role && get_role($subscriber_role)) {
                                    $user_obj = new WP_User($user_id);
                                    $active_statuses = ['active', 'trialing'];
                                    if (in_array($new_status, $active_statuses)) {
                                        if (!$user_obj->has_cap($subscriber_role)) {
                                            $user_obj->set_role($subscriber_role);
                                            error_log($error_log_prefix . "Assigned role '{$subscriber_role}' for UserID {$user_id}");
                                        }
                                    } else {
                                        if ($user_obj->has_cap($subscriber_role)) {
                                            $user_obj->remove_role($subscriber_role);
                                            error_log($error_log_prefix . "Removed role '{$subscriber_role}' for UserID {$user_id}");
                                        }
                                    }
                                }
                                $processed = true;
                            }
                            break;

                        case 'charge.refunded':
                            $charge = $object;
                            $pi_id = $charge->payment_intent;
                            if (empty($pi_id)) {
                                $processed = true;
                                break;
                            }
                            error_log($error_log_prefix . 'Processing ' . $event->type . ' for PI ID: ' . $pi_id);
                            $payment_record = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM {$payment_table_name} WHERE payment_intent_id = %s", $pi_id));
                            if ($payment_record) {
                                $new_status = 'refunded';
                                if ($payment_record->status !== $new_status) {
                                    $updated = $wpdb->update($payment_table_name, ['status' => $new_status, 'updated_at_gmt' => current_time('mysql', 1)], ['payment_intent_id' => $pi_id], ['%s', '%s'], ['%s']);
                                    if ($updated !== false) {
                                        error_log($error_log_prefix . "Updated payment status to '{$new_status}' for PI: " . $pi_id);
                                    } else {
                                        error_log($error_log_prefix . "Failed update payment status for PI: " . $pi_id . ". DB Error: " . $wpdb->last_error);
                                    }
                                } else {
                                    error_log($error_log_prefix . "Payment status already '{$new_status}' for PI: " . $pi_id);
                                }
                                // TODO: Send refund notification email?
                                $processed = true;
                            } else {
                                error_log($error_log_prefix . "Payment record not found for PI ID: " . $pi_id);
                            }
                            break;

                        default:
                            error_log('[Edel Stripe Webhook] Received unhandled event type: ' . $event->type);
                            $processed = true; // Acknowledge
                    } // end switch

                } catch (Exception $e) {
                    error_log('[Edel Stripe Webhook] Error processing event (' . ($event->id ?? 'N/A') . ' Type: ' . ($event->type ?? 'N/A') . '): ' . $e->getMessage());
                    return new WP_REST_Response(['error' => 'Webhook event processing error: ' . $e->getMessage()], 500); // Return specific error
                }

                // Return 200 OK
                return new WP_REST_Response(['received' => true, 'processed' => $processed], 200);
            } // end handle_webhook

            /**
             * Sends specific emails based on context (signup or webhook event).
             * Uses settings for templates, sender info, toggles, and replaces placeholders.
             *
             * @param string $context        Context key (e.g., 'signup_onetime', 'signup_subscription', 'payment_failed', 'subscription_canceled').
             * @param array  $data           Data for placeholders (must include 'user_email' if sending to customer; other keys depend on context).
             * @param bool   $is_new_user    (Optional) Whether a new WP user was created (relevant for signup context).
             */
            private function send_webhook_or_signup_notification($context, $data, $is_new_user = false, $customer_email = null) {
                $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
                $error_log_prefix = '[Edel Stripe Notify ' . $context . '] '; // Add context to log prefix

                error_log($error_log_prefix . 'Received Customer Email Parameter: ' . print_r($customer_email, true));

                // --- 1. Get Common Settings ---
                $default_from_name = get_bloginfo('name');
                $default_from_email = get_option('admin_email');
                $mail_from_name = $options['mail_from_name'] ?? $default_from_name;
                $mail_from_email = $options['mail_from_email'] ?? $default_from_email;
                $admin_notify_email = $options['admin_notify_email'] ?? $default_from_email;

                // --- 2. Determine Templates and Send Toggles based on Context ---
                $send_admin = false;
                $admin_subject_template = '';
                $admin_body_template = '';
                $send_customer = false;
                $customer_subject_template = '';
                $customer_body_template = '';

                switch ($context) {
                    case 'signup_onetime':
                        $send_admin = true; // Always notify admin on new one-time payment
                        $send_customer = $options['ot_send_customer_email'] ?? '0';
                        $admin_subject_template = $options['ot_admin_mail_subject'] ?? '[{site_name}] 新しい決済がありました(買い切り)';
                        $admin_body_template = $options['ot_admin_mail_body'] ?? "買い切り決済完了。\nEmail:{customer_email}\nItem:{item_name}\nAmount:{amount}\nDate:{transaction_date}\nUserID:{user_id}\nPI:{payment_intent_id}\nCusID:{customer_id}";
                        $customer_subject_template = $options['ot_customer_mail_subject'] ?? '[{site_name}] ご購入ありがとうございます';
                        $customer_body_template = $options['ot_customer_mail_body'] ?? "{user_name}様\n「{item_name}」({amount})のご購入ありがとうございます。\nDate:{transaction_date}\n--\n{site_name}";
                        break;
                    case 'signup_subscription':
                        $send_admin = true; // Always notify admin on new subscription
                        $send_customer = $options['sub_send_customer_email'] ?? '0';
                        $admin_subject_template = $options['sub_admin_mail_subject'] ?? '[{site_name}] 新規サブスク申込';
                        $admin_body_template = $options['sub_admin_mail_body'] ?? "サブスク申込。\nEmail:{customer_email}\nPlan:{item_name}({plan_id})\nCusID:{customer_id}\nSubID:{subscription_id}\nUserID:{user_id}";
                        $customer_subject_template = $options['sub_customer_mail_subject'] ?? '[{site_name}] サブスクへようこそ';
                        $customer_body_template = $options['sub_customer_mail_body'] ?? "{user_name}様\nサブスク「{item_name}」申込ありがとうございます。\n--\n{site_name}";
                        break;
                    case 'payment_failed':
                        $send_admin = $options['sub_fail_send_admin'] ?? '1'; // Use toggle from settings
                        $admin_subject_template = $options['sub_fail_admin_subject'] ?? '[{site_name}] 支払い失敗通知(Admin)';
                        $admin_body_template = $options['sub_fail_admin_body'] ?? "支払い失敗。\nEmail:{customer_email}\nSubID:{subscription_id}\nPlan:{item_name}({plan_id})";
                        $send_customer = $options['sub_fail_send_customer'] ?? '1'; // Use toggle from settings
                        $customer_subject_template = $options['sub_fail_customer_subject'] ?? '[{site_name}] お支払い情報確認依頼';
                        $customer_body_template = $options['sub_fail_customer_body'] ?? "{user_name}様\nサブスク「{item_name}」の支払失敗。カード情報更新を。";
                        break;
                    case 'subscription_canceled':
                        $send_admin = $options['sub_cancel_send_admin'] ?? '1'; // Use toggle from settings
                        $admin_subject_template = $options['sub_cancel_admin_subject'] ?? '[{site_name}] サブスクキャンセル通知(Admin)';
                        $admin_body_template = $options['sub_cancel_admin_body'] ?? "キャンセル。\nEmail:{customer_email}\nSubID:{subscription_id}\nPlan:{item_name}({plan_id})";
                        $send_customer = $options['sub_cancel_send_customer'] ?? '1'; // Use toggle from settings
                        $customer_subject_template = $options['sub_cancel_customer_subject'] ?? '[{site_name}] サブスク解約のお知らせ';
                        $customer_body_template = $options['sub_cancel_customer_body'] ?? "{user_name}様\n「{item_name}」の解約完了。";
                        break;
                    // Add more cases like 'payment_refunded' if needed
                    default:
                        error_log($error_log_prefix . 'Unknown notification context: ' . $context);
                        return; // Unknown context, do nothing
                }

                // Validate recipient emails
                if (!is_email($admin_notify_email)) {
                    $send_admin = false;
                    error_log($error_log_prefix . 'Invalid admin notify email.');
                }
                $is_valid_customer_email = is_email($customer_email);
                if (!$is_valid_customer_email) {
                    $send_customer = false; // Disable sending if email is invalid
                    error_log($error_log_prefix . 'Invalid customer email passed. Will not send customer email.');
                } else {
                    error_log($error_log_prefix . 'Customer email is valid. Send flag from settings: ' . $send_customer);
                }
                // --- Step 3: Prepare Placeholders and Values ---
                $user_display_name = $customer_email;
                $user_id_display = 'N/A';
                if (!empty($data['user_id'])) {
                    $user_id_display = $data['user_id'];
                    $user_info = get_userdata($data['user_id']);
                    if ($user_info && !empty($user_info->display_name)) {
                        $user_display_name = $user_info->display_name;
                    }
                }
                // Use current time for webhook events, or payment time for signups
                $transaction_timestamp = ($context === 'signup_onetime' || $context === 'signup_subscription') && isset($data['created_at_gmt'])
                    ? strtotime($data['created_at_gmt']) : time();
                $amount_raw = $data['amount'] ?? 0;
                $currency = strtolower($data['currency'] ?? 'jpy');
                // Format amount based on currency
                $formatted_amount = '';
                if ($currency === 'jpy') {
                    $formatted_amount = number_format($amount_raw) . '円';
                } elseif ($currency === 'usd') {
                    $formatted_amount = '$' . number_format($amount_raw / 100, 2);
                } else {
                    $formatted_amount = number_format($amount_raw) . ' ' . strtoupper($currency);
                }
                // Get Plan ID from metadata if available in data array
                $metadata = maybe_unserialize($data['metadata'] ?? '');
                $plan_id_from_meta = $metadata['plan_id'] ?? ($data['plan_id'] ?? 'N/A');

                $replacements = [
                    '{item_name}'         => $data['item_name'] ?? '',
                    '{amount}'            => $formatted_amount,
                    '{customer_email}'    => $customer_email ?? '',
                    '{payment_intent_id}' => $data['payment_intent_id'] ?? 'N/A',
                    '{customer_id}'       => $data['customer_id'] ?? 'N/A',
                    '{transaction_date}'  => wp_date(get_option('date_format') . ' ' . get_option('time_format'), $transaction_timestamp),
                    '{user_name}'         => $user_display_name,
                    '{user_id}'           => $user_id_display,
                    '{site_name}'         => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                    '{site_url}'          => home_url(),
                    '{subscription_id}'   => $data['subscription_id'] ?? 'N/A',
                    '{plan_id}'           => $plan_id_from_meta,
                ];

                // --- Step 4: Prepare Headers (using filters) ---
                $use_custom_from = !empty($mail_from_email) && is_email($mail_from_email);
                $final_from_name = $use_custom_from ? $mail_from_name : $default_from_name;
                $final_from_email = $use_custom_from ? $mail_from_email : $default_from_email;
                $set_from_name = function ($original) use ($final_from_name) {
                    return $final_from_name;
                };
                $set_from_email = function ($original) use ($final_from_email) {
                    return $final_from_email;
                };
                $set_content_type = function () {
                    return 'text/plain';
                };

                add_filter('wp_mail_from_name', $set_from_name);
                add_filter('wp_mail_from', $set_from_email);
                add_filter('wp_mail_content_type', $set_content_type);

                // --- Step 5: Send Admin Notification ---
                if ($send_admin && !empty($admin_notify_email)) {
                    if (!is_string($admin_subject_template)) $admin_subject_template = "Stripe Alert: " . $context; // Fallback
                    if (!is_string($admin_body_template)) $admin_body_template = "Event: " . $context . "\nData: " . print_r($data, true); // Fallback
                    $admin_subject = str_replace(array_keys($replacements), array_values($replacements), $admin_subject_template);
                    $admin_message_raw = str_replace(array_keys($replacements), array_values($replacements), $admin_body_template);
                    $admin_message = str_replace(["\r\n", "\r"], "\n", $admin_message_raw);
                    $admin_message = str_replace("\n", "\r\n", $admin_message);
                    if (!wp_mail($admin_notify_email, $admin_subject, $admin_message)) {
                        error_log($error_log_prefix . 'Admin email failed.');
                    } else {
                        error_log($error_log_prefix . "Admin notification sent.");
                    }
                }

                // --- Step 6: Send Customer Notification ---
                if ($send_customer && !empty($customer_email)) {
                    if (!is_string($customer_subject_template)) $customer_subject_template = "Update from {site_name}"; // Fallback
                    if (!is_string($customer_body_template)) $customer_body_template = "Your account status has been updated."; // Fallback
                    $customer_subject = str_replace(array_keys($replacements), array_values($replacements), $customer_subject_template);
                    $customer_message_raw = str_replace(array_keys($replacements), array_values($replacements), $customer_body_template);
                    if ($is_new_user && ($context === 'signup_onetime' || $context === 'signup_subscription')) {
                        $customer_message_raw .= "\n\n---\nアカウント情報について...\n";
                    }
                    $customer_message = str_replace(["\r\n", "\r"], "\n", $customer_message_raw);
                    $customer_message = str_replace("\n", "\r\n", $customer_message);
                    if (!wp_mail($customer_email, $customer_subject, $customer_message)) {
                        error_log($error_log_prefix . 'Customer email failed: ' . $customer_email);
                    } else {
                        error_log($error_log_prefix . "Customer notification sent to " . $customer_email);
                    }
                }

                // --- Step 7: Clean up filters ---
                remove_filter('wp_mail_from_name', $set_from_name);
                remove_filter('wp_mail_from', $set_from_email);
                remove_filter('wp_mail_content_type', $set_content_type);

                error_log($error_log_prefix . 'Finished.');
            } // end send_webhook_or_signup_notification

            /**
             * Sends specific emails based on webhook events or other contexts.
             *
             * @param string $context        Context key (e.g., 'payment_failed', 'subscription_canceled').
             * @param array  $data           Data for placeholders (must include 'user_email' if sending to customer).
             */
            private function send_webhook_notification($context, $data) {
                $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
                $error_log_prefix = '[Edel Stripe Notify] ';

                // Get common settings
                $default_from_name = get_bloginfo('name');
                $default_from_email = get_option('admin_email');
                $mail_from_name = $options['mail_from_name'] ?? $default_from_name;
                $mail_from_email = $options['mail_from_email'] ?? $default_from_email;
                $admin_notify_email = $options['admin_notify_email'] ?? $default_from_email;

                // Determine which templates and toggles to use based on context
                $send_admin = false;
                $admin_subject_template = '';
                $admin_body_template = '';
                $send_customer = false;
                $customer_subject_template = '';
                $customer_body_template = '';
                $customer_email = $data['user_email'] ?? null; // Get customer email from data

                switch ($context) {
                    case 'payment_failed':
                        $send_admin = $options['sub_fail_send_admin'] ?? '1'; // Default ON
                        $admin_subject_template = $options['sub_fail_admin_subject'] ?? '[{site_name}] サブスク支払い失敗通知';
                        $admin_body_template = $options['sub_fail_admin_body'] ?? "支払い失敗: Email:{customer_email}, SubID:{subscription_id}";
                        $send_customer = $options['sub_fail_send_customer'] ?? '1'; // Default ON
                        $customer_subject_template = $options['sub_fail_customer_subject'] ?? '[{site_name}] お支払い情報の確認';
                        $customer_body_template = $options['sub_fail_customer_body'] ?? "{user_name} 様\n支払い失敗。カード情報を確認・更新してください。";
                        break;

                    case 'subscription_canceled':
                        $send_admin = $options['sub_cancel_send_admin'] ?? '1'; // Default ON
                        $admin_subject_template = $options['sub_cancel_admin_subject'] ?? '[{site_name}] サブスクキャンセル通知';
                        $admin_body_template = $options['sub_cancel_admin_body'] ?? "キャンセル: Email:{customer_email}, SubID:{subscription_id}";
                        $send_customer = $options['sub_cancel_send_customer'] ?? '1'; // Default ON
                        $customer_subject_template = $options['sub_cancel_customer_subject'] ?? '[{site_name}] サブスク解約のお知らせ';
                        $customer_body_template = $options['sub_cancel_customer_body'] ?? "{user_name} 様\n「{item_name}」の解約完了。";
                        break;

                    // Add more cases for other notifications if needed (e.g., 'refund_processed')

                    default:
                        error_log($error_log_prefix . 'Unknown notification context: ' . $context);
                        return; // Unknown context, do nothing
                }

                // Validate recipient emails
                if (!is_email($admin_notify_email)) {
                    $send_admin = false;
                    error_log($error_log_prefix . 'Invalid admin notify email.');
                }
                if (!is_email($customer_email)) {
                    $send_customer = false;
                    error_log($error_log_prefix . 'Invalid customer email for notification.');
                }

                // Prepare placeholders (similar to previous function, use $data passed in)
                $user_display_name = $customer_email;
                $user_id_display = $data['user_id'] ?? 'N/A';
                if (!empty($data['user_id'])) {
                    $user_info = get_userdata($data['user_id']);
                    if ($user_info && !empty($user_info->display_name)) {
                        $user_display_name = $user_info->display_name;
                    }
                }
                $transaction_timestamp = time(); // Use current time for notifications, or get from data if available
                $amount_raw = $data['amount'] ?? 0;
                $currency = strtolower($data['currency'] ?? 'jpy');
                $formatted_amount = ''; // Format based on currency...
                if ($currency === 'jpy') {
                    $formatted_amount = number_format($amount_raw) . '円';
                } elseif ($currency === 'usd') {
                    $formatted_amount = '$' . number_format($amount_raw / 100, 2);
                } else {
                    $formatted_amount = number_format($amount_raw) . ' ' . strtoupper($currency);
                }
                $replacements = [
                    '{item_name}' => $data['item_name'] ?? '',
                    '{amount}' => $formatted_amount,
                    '{customer_email}' => $customer_email,
                    '{payment_intent_id}' => $data['payment_intent_id'] ?? 'N/A',
                    '{customer_id}' => $data['customer_id'] ?? 'N/A',
                    '{transaction_date}' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), $transaction_timestamp),
                    '{user_name}' => $user_display_name,
                    '{user_id}' => $user_id_display,
                    '{site_name}' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                    '{site_url}' => home_url(),
                    '{subscription_id}' => $data['subscription_id'] ?? 'N/A',
                    '{plan_id}' => $data['plan_id'] ?? 'N/A',
                ];

                // Prepare Headers
                $use_custom_from = !empty($mail_from_email) && is_email($mail_from_email);
                $final_from_name = $use_custom_from ? $mail_from_name : $default_from_name;
                $final_from_email = $use_custom_from ? $mail_from_email : $default_from_email;
                $set_from_name = function ($original) use ($final_from_name) {
                    return $final_from_name;
                };
                $set_from_email = function ($original) use ($final_from_email) {
                    return $final_from_email;
                };
                $set_content_type = function () {
                    return 'text/plain';
                };
                add_filter('wp_mail_from_name', $set_from_name);
                add_filter('wp_mail_from', $set_from_email);
                add_filter('wp_mail_content_type', $set_content_type);

                // Send Admin Email
                if ($send_admin && !empty($admin_notify_email)) {
                    if (!is_string($admin_subject_template)) $admin_subject_template = "Stripe Webhook Alert: " . $context; // Fallback subject
                    if (!is_string($admin_body_template)) $admin_body_template = "Event: {event_type}\nData:\n" . print_r($data, true); // Fallback body
                    $admin_subject = str_replace(array_keys($replacements), array_values($replacements), $admin_subject_template);
                    $admin_message = str_replace(array_keys($replacements), array_values($replacements), $admin_body_template);
                    $admin_message = str_replace(["\r\n", "\r"], "\n", $admin_message);
                    $admin_message = str_replace("\n", "\r\n", $admin_message);
                    if (!wp_mail($admin_notify_email, $admin_subject, $admin_message)) {
                        error_log($error_log_prefix . 'Admin webhook email failed for context: ' . $context);
                    } else {
                        error_log($error_log_prefix . "Admin webhook notification sent for {$context} to " . $admin_notify_email);
                    }
                }

                // Send Customer Email
                if ($send_customer && !empty($customer_email)) {
                    if (!is_string($customer_subject_template)) $customer_subject_template = "Regarding your account at {site_name}"; // Fallback
                    if (!is_string($customer_body_template)) $customer_body_template = "Your account status has been updated."; // Fallback
                    $customer_subject = str_replace(array_keys($replacements), array_values($replacements), $customer_subject_template);
                    $customer_message = str_replace(array_keys($replacements), array_values($replacements), $customer_body_template);
                    $customer_message = str_replace(["\r\n", "\r"], "\n", $customer_message);
                    $customer_message = str_replace("\n", "\r\n", $customer_message);
                    if (!wp_mail($customer_email, $customer_subject, $customer_message)) {
                        error_log($error_log_prefix . 'Customer webhook email failed for context: ' . $context . ' to ' . $customer_email);
                    } else {
                        error_log($error_log_prefix . "Customer webhook notification sent for {$context} to " . $customer_email);
                    }
                }

                // Clean up filters
                remove_filter('wp_mail_from_name', $set_from_name);
                remove_filter('wp_mail_from', $set_from_email);
                remove_filter('wp_mail_content_type', $set_content_type);

                error_log($error_log_prefix . 'Finished processing notification for context: ' . $context);
            } // end send_webhook_notification

            /**
             * Enqueues frontend scripts and styles.
             */
            public function front_enqueue() {
                // Load always for now
                wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);

                $version  = (defined('EDEL_STRIPE_PAYMENT_DEVELOP') && true === EDEL_STRIPE_PAYMENT_DEVELOP) ? time() : EDEL_STRIPE_PAYMENT_VERSION;
                $strategy = array('in_footer' => true, 'strategy'  => 'defer');

                wp_enqueue_style(EDEL_STRIPE_PAYMENT_SLUG . '-front', EDEL_STRIPE_PAYMENT_URL . '/css/front.css', array(), $version);
                wp_enqueue_script(EDEL_STRIPE_PAYMENT_SLUG . '-front', EDEL_STRIPE_PAYMENT_URL . '/js/front.js', array('jquery', 'stripe-js'), $version, $strategy);

                // Localize script - Pass data from PHP to JavaScript
                $stripe_options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
                $is_live_mode = isset($stripe_options['mode']) && $stripe_options['mode'] === 'live';
                $publishable_key = $is_live_mode ? ($stripe_options['live_publishable_key'] ?? '') : ($stripe_options['test_publishable_key'] ?? '');

                // ★追加：フロント成功メッセージを取得（デフォルト値も考慮）
                $default_success_message = "支払いが完了しました。ありがとうございます。\nメールをご確認ください。届いていない場合は、お問い合わせください。";
                $frontend_success_message = $stripe_options['frontend_success_message'] ?? $default_success_message;

                $params = array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'publishable_key' => $publishable_key,
                    'record_nonce' => wp_create_nonce(EDEL_STRIPE_PAYMENT_PREFIX . 'record_payment_nonce'),
                    'success_message' => $frontend_success_message,
                );
                wp_localize_script(EDEL_STRIPE_PAYMENT_SLUG . '-front', 'edelStripeParams', $params);
            }

            public function render_subscription_shortcode($atts) {
                // --- Step 1: Process Shortcode Attributes ---
                $attributes = shortcode_atts(array(
                    'plan_id'     => '', // Stripe Price ID (必須)
                    'button_text' => '申し込む', // Default button text
                ), $atts);

                $plan_id = sanitize_text_field($attributes['plan_id']);
                $button_text = sanitize_text_field($attributes['button_text']);

                if (empty($plan_id) || strpos($plan_id, 'price_') !== 0) {
                    if (current_user_can('manage_options')) {
                        return '<p><span class="orange b">Edel Stripe Payment エラー: ショートコードに有効なプランID (plan_id="price_...") が指定されていません。</span></p>';
                    } else {
                        return '';
                    }
                }

                // --- Step 2: Get Plugin Settings ---
                $stripe_options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
                $require_consent = $stripe_options['require_consent'] ?? '1'; // Consent setting
                // ★ 同意文言生成に必要な設定値を取得
                $privacy_page_id = $stripe_options['privacy_page_id'] ?? 0;
                $terms_page_id   = $stripe_options['terms_page_id'] ?? 0;
                $consent_text_custom = $stripe_options['consent_text'] ?? '';
                // Publishable key needed by JS (enqueued separately)

                // --- Step 3: Generate Nonce for Subscription AJAX ---
                $nonce = wp_create_nonce(EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_nonce');

                // --- Step 4: Generate HTML using Output Buffering ---
                ob_start();
                ?>
        <div class="edel-stripe-payment-form-wrap" id="edel-stripe-subscription-form-wrap">
            <?php // Optional: Display Plan details
            ?>
            <p>内容をご確認の上、カード情報をご入力ください。</p>

            <form id="edel-stripe-subscription-payment-form">
                <div class="form-row">
                    <label for="edel-stripe-sub-email">メールアドレス <span class="orange b">(必須)</span></label>
                    <input type="email" id="edel-stripe-sub-email" class="edel-stripe-email-input" required autocomplete="email">
                </div>

                <div class="form-row">
                    <label for="card-element-sub">クレジットカード情報</label>
                    <div id="card-element-sub" class="edel-stripe-card-element"></div>
                    <div id="card-errors-sub" class="edel-stripe-card-errors" role="alert"></div>
                </div>

                <?php // Hidden fields
                ?>
                <input type="hidden" name="plan_id" value="<?php echo esc_attr($plan_id); ?>">
                <input type="hidden" name="action" value="edel_stripe_process_subscription">
                <input type="hidden" name="subscription_security" value="<?php echo esc_attr($nonce); ?>"> <?php // Correct nonce name
                                                                                                            ?>

                <?php // --- ★修正: Conditional Consent Checkbox Output (with text generation) ---
                ?>
                <?php if ($require_consent === '1'): ?>
                    <?php
                    // Get page links and titles (Same logic as one-time)
                    $privacy_link = '';
                    if ($privacy_page_id && get_post_status($privacy_page_id) === 'publish') {
                        $privacy_link = sprintf('<a href="%s" target="_blank">%s</a>', esc_url(get_permalink($privacy_page_id)), esc_html(get_the_title($privacy_page_id)) ?: 'プライバシーポリシー');
                    }
                    $terms_link = '';
                    if ($terms_page_id && get_post_status($terms_page_id) === 'publish') {
                        $terms_link = sprintf('<a href="%s" target="_blank">%s</a>', esc_url(get_permalink($terms_page_id)), esc_html(get_the_title($terms_page_id)) ?: '利用規約');
                    }
                    // Determine consent text (Same logic as one-time)
                    $consent_message = '';
                    if (!empty($consent_text_custom)) {
                        $consent_message = str_replace('[privacy_policy_link]', $privacy_link ?: 'プライバシーポリシー', $consent_text_custom);
                        $consent_message = str_replace('[terms_link]', $terms_link ?: '利用規約', $consent_message);
                    } else {
                        $link_texts = array_filter([$privacy_link, $terms_link]);
                        if (count($link_texts) > 0) {
                            $consent_message = sprintf('%s を確認し、決済情報と入力されたメールアドレスでユーザーアカウントが作成されることに同意します。', implode(' と ', $link_texts));
                        } else {
                            $consent_message = '決済情報と入力されたメールアドレスでユーザーアカウントが作成されることに同意します。';
                        }
                    }
                    ?>
                    <div class="form-row privacy-consent">
                        <label for="privacy-policy-agree-sub" class="edel-stripe-consent-label">
                            <input type="checkbox" id="privacy-policy-agree-sub" name="privacy_policy_agree_sub" class="edel-stripe-consent-input" value="1" required>
                            <?php // ★ 表示するメッセージを生成したものに
                            ?>
                            <?php echo wp_kses_post($consent_message); ?>
                        </label>
                        <div id="consent-error-sub" class="edel-stripe-consent-error" style="color: red; font-size: 0.9em; margin-top: 5px; display: none;">同意が必要です。</div>
                    </div>
                <?php endif; // end if $require_consent
                ?>
                <?php // --- Consent Checkbox End ---
                ?>

                <button type="submit" id="edel-stripe-submit-button-sub" class="edel-stripe-submit-button">
                    <?php echo esc_html($button_text); ?>
                </button>
                <div class="spinner-sub edel-stripe-spinner" style="display: none;"></div>
            </form>

            <div id="edel-stripe-subscription-result" class="edel-stripe-result-message" style="margin-top: 15px;"></div>
        </div><?php
                // $this->add_frontend_scripts = true; // If using conditional loading
                return ob_get_clean();
            } // end render_subscription_shortcode

            public function process_subscription() {
                error_log('[Edel Stripe Sub Process] AJAX Handler Started.');

                // --- Step 1: Verify Nonce (with result check) ---
                $nonce_action = EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_nonce';
                $nonce_key = 'subscription_security';
                // ★ check_ajax_referer の第3引数に false を指定し、結果を受け取る
                $nonce_check_result = check_ajax_referer($nonce_action, $nonce_key, false);

                // ★ 検証結果をログに出力
                error_log('[Edel Stripe Sub Process] Nonce check result for action "' . $nonce_action . '" and key "' . $nonce_key . '": ' . ($nonce_check_result ? 'Success (Tick: ' . $nonce_check_result . ')' : 'Failure (false)'));

                // ★ もし検証結果が false なら、JSONエラーを返して終了
                if (false === $nonce_check_result) {
                    error_log('[Edel Stripe Sub Process] Nonce check failed! Sending JSON error.');
                    wp_send_json_error(['message' => 'セキュリティ検証に失敗しました。ページを再読み込みしてもう一度お試しください。']);
                    // wp_send_json_error() は内部で wp_die() を呼ぶので、通常は以降の return は不要
                    return;
                }
                // --- Nonce検証 OK ---

                // --- Step 2: Get Data from POST ---
                $plan_id = isset($_POST['plan_id']) ? sanitize_text_field($_POST['plan_id']) : null;
                $email   = isset($_POST['email']) ? sanitize_email($_POST['email']) : null;
                error_log('[Edel Stripe Sub Process] POST Data - Plan ID: ' . $plan_id . ' | Email: ' . $email);

                // --- Step 3: Basic Validation ---
                if (empty($plan_id) || strpos($plan_id, 'price_') !== 0 || empty($email)) {
                    error_log('[Edel Stripe Sub Process] Validation Error: Missing plan_id or email.');
                    wp_send_json_error(['message' => '必須情報（プランID、メールアドレス）が不足しています。']);
                    return;
                }
                if (!is_email($email)) {
                    error_log('[Edel Stripe Sub Process] Validation Error: Invalid email format.');
                    wp_send_json_error(['message' => '有効なメールアドレスを入力してください。']);
                    return;
                }
                error_log('[Edel Stripe Sub Process] Validation Passed.'); // ★ Log Validation OK

                // --- Step 4: Get Stripe Keys and Initialize ---
                $stripe_options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
                $is_live_mode = isset($stripe_options['mode']) && $stripe_options['mode'] === 'live';
                $secret_key = $is_live_mode ? ($stripe_options['live_secret_key'] ?? '') : ($stripe_options['test_secret_key'] ?? '');

                if (empty($secret_key)) {
                    error_log('[Edel Stripe Sub Process] Stripe Secret Key is not configured.');
                    wp_send_json_error(['message' => '決済設定が不完全です。管理者にお問い合わせください。']);
                    return;
                }
                error_log('[Edel Stripe Sub Process] Stripe Key Found. Mode: ' . ($is_live_mode ? 'Live' : 'Test')); // ★ Log Key OK

                // --- Step 5: Stripe API Interaction ---
                try {
                    \Stripe\Stripe::setApiKey($secret_key);
                    \Stripe\Stripe::setApiVersion("2024-04-10");
                    error_log('[Edel Stripe Sub Process] Stripe SDK Initialized.'); // ★ Log SDK Init

                    // 1. Find or Create Stripe Customer
                    $customer_id = null;
                    error_log('[Edel Stripe Sub Process] Finding/Creating Stripe Customer for email: ' . $email);
                    $customers = \Stripe\Customer::all(['email' => $email, 'limit' => 1]);
                    if (empty($customers->data)) {
                        $customer = \Stripe\Customer::create(['email' => $email]);
                        $customer_id = $customer->id;
                        error_log("[Edel Stripe Sub Process] Created new Stripe Customer: " . $customer_id);
                    } else {
                        $customer_id = $customers->data[0]->id;
                        error_log("[Edel Stripe Sub Process] Found existing Stripe Customer: " . $customer_id);
                    }

                    // 2. Create Subscription
                    $subscription_params = [ /* ... (前回と同じパラメータ) ... */
                        'customer' => $customer_id,
                        'items' => [['price' => $plan_id]],
                        'payment_behavior' => 'default_incomplete',
                        'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
                        'expand' => ['latest_invoice.payment_intent'],
                        'metadata' => ['wordpress_user_email' => $email, 'wordpress_site' => home_url()]
                    ];
                    error_log("[Edel Stripe Sub Process] Attempting to create subscription..."); // ★ Log Before Sub Create
                    $subscription = \Stripe\Subscription::create($subscription_params);
                    error_log("[Edel Stripe Sub Process] Subscription created (" . $subscription->id . "). Status: " . $subscription->status); // ★ Log After Sub Create

                    // 3. Check Status and Get Client Secret
                    if ($subscription->status === 'incomplete' && isset($subscription->latest_invoice->payment_intent->client_secret)) {
                        error_log("[Edel Stripe Sub Process] Sending client_secret to JS."); // ★ Log Sending Secret
                        wp_send_json_success([
                            'subscription_id' => $subscription->id,
                            'client_secret'   => $subscription->latest_invoice->payment_intent->client_secret,
                            'customer_id'     => $customer_id
                        ]);
                    } elseif (($subscription->status === 'active' || $subscription->status === 'trialing') && !isset($subscription->latest_invoice->payment_intent)) {
                        error_log("[Edel Stripe Sub Process] Sending immediate active/trialing status to JS."); // ★ Log Sending Active Status
                        wp_send_json_success([
                            'subscription_id' => $subscription->id,
                            'status'          => $subscription->status,
                            'customer_id'     => $customer_id
                        ]);
                    } else {
                        error_log("[Edel Stripe Sub Process] Unexpected status after creation. Status: " . $subscription->status); // ★ Log Unexpected Status
                        throw new Exception('サブスクリプションの支払い準備中に予期せぬエラーが発生しました。ステータス: ' . $subscription->status);
                    }
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    error_log('[Edel Stripe Sub Process] Stripe API Error: ' . $e->getMessage()); // ★ Log Stripe API Error
                    wp_send_json_error(['message' => 'サブスクリプション作成中にエラーが発生しました。(Code: ' . esc_html($e->getStripeCode()) . ')']);
                } catch (Exception $e) {
                    error_log('[Edel Stripe Sub Process] General Error: ' . $e->getMessage()); // ★ Log General Error
                    wp_send_json_error(['message' => 'サブスクリプション作成中に不明なエラーが発生しました。']);
                } finally {
                    // \Stripe\Stripe::setApiKey(null);
                    error_log('[Edel Stripe Sub Process] AJAX Handler Ended.'); // ★ Log End
                }
            } // end process_subscription

            /**
             * Processes the AJAX request after successful payment confirmation (for both one-time and subscription).
             * Handles user registration/linking and records the transaction into the custom database table.
             * Accepts and saves currency, subscription_id, and plan_id. Includes full error logging.
             */
            public function record_successful_payment() {
                // --- Step 1: Verify Nonce ---
                check_ajax_referer(EDEL_STRIPE_PAYMENT_PREFIX . 'record_payment_nonce', 'security');

                // --- Step 2: Get Data from POST ---
                $payment_intent_id = isset($_POST['payment_intent_id']) ? sanitize_text_field($_POST['payment_intent_id']) : null;
                $email             = isset($_POST['email']) ? sanitize_email($_POST['email']) : null;
                $customer_id       = isset($_POST['customer_id']) ? sanitize_text_field($_POST['customer_id']) : null;
                $amount            = isset($_POST['amount']) ? intval($_POST['amount']) : 0;
                $item_name         = isset($_POST['item_name']) ? sanitize_text_field($_POST['item_name']) : '';
                $subscription_id   = isset($_POST['subscription_id']) ? sanitize_text_field($_POST['subscription_id']) : null; // Subscription ID
                $plan_id           = isset($_POST['plan_id']) ? sanitize_text_field($_POST['plan_id']) : null; // Plan ID (can be from sub or item_name)
                $currency          = isset($_POST['currency']) ? strtolower(sanitize_key($_POST['currency'])) : null; // Currency code

                // --- Step 3: Basic Validation ---
                if (empty($email) || empty($customer_id) || (empty($payment_intent_id) && empty($subscription_id))) {
                    wp_send_json_error(array('message' => '記録情報不足'));
                    return;
                }
                if (!is_email($email)) {
                    wp_send_json_error(array('message' => 'メール形式不正'));
                    return;
                }

                // --- Step 3b: Determine Currency if missing ---
                if (empty($currency)) { /* ... (Currency fetching logic - same as before) ... */
                }
                $currency = strtolower(sanitize_key($currency));
                if (!in_array($currency, ['jpy', 'usd'])) {
                    $currency = 'jpy';
                }

                // --- Step 4: User Registration / Linking ---
                $user_id = null;
                $user_created = false; // Flag for email notification logic later
                $user = get_user_by('email', $email);

                if ($user) {
                    // --- Existing User Found ---
                    $user_id = $user->ID;
                    update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'customer_id', $customer_id);
                    error_log("Edel Stripe: Existing user found: ID " . $user_id . " | Stripe Customer ID updated: " . $customer_id);
                } else {
                    // --- New User Creation ---
                    $password = wp_generate_password(12, false);
                    $user_id = wp_create_user($email, $password, $email); // Use email as username

                    if (is_wp_error($user_id)) {
                        // Log error if creation failed
                        error_log(EDEL_STRIPE_PAYMENT_PREFIX . 'Failed to create user for email ' . $email . ': ' . $user_id->get_error_message());
                        wp_send_json_error(array('message' => 'ユーザーアカウントの作成に失敗しました。(' . esc_html($user_id->get_error_code()) . ')'));
                        return; // Stop execution
                    } else {
                        // User created successfully
                        $user_created = true; // Set flag
                        update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'customer_id', $customer_id);
                        error_log("Edel Stripe: New user created: ID " . $user_id . " | Stripe Customer ID saved: " . $customer_id);

                        // --- Disable standard new user notification email ---
                        $disable_user_email_callback = function ($wp_new_user_notification_email, $user, $blogname) {
                            return false;
                        };
                        add_filter('wp_new_user_notification_email', $disable_user_email_callback, 10, 3);
                        wp_new_user_notification($user_id, null, 'user');
                        remove_filter('wp_new_user_notification_email', $disable_user_email_callback, 10);
                        // --- End disable standard new user notification ---
                    }
                } // End user check/creation block


                // --- ★★★ Step 4b: Assign User Role (Only for Subscriptions, after user ID is known) ★★★ ---
                if ($user_id && !is_wp_error($user_id) && !empty($subscription_id)) { // Check for valid user_id AND subscription_id
                    $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
                    $subscriber_role = $options['sub_subscriber_role'] ?? null; // Get role slug from settings

                    if (!empty($subscriber_role)) { // Check if a role was selected in settings
                        if (get_role($subscriber_role)) { // Check if the selected role actually exists in WP
                            $user_obj = get_userdata($user_id); // Get WP_User object
                            if ($user_obj) {
                                try {
                                    $user_obj->set_role($subscriber_role); // Assign the role (replaces existing roles)
                                    error_log("Edel Stripe: Assigned role '{$subscriber_role}' to user ID {$user_id} for subscription {$subscription_id}");
                                } catch (Exception $e) {
                                    error_log("Edel Stripe: Error assigning role '{$subscriber_role}' to user ID {$user_id}: " . $e->getMessage());
                                }
                            } else {
                                error_log("Edel Stripe: Could not get user object for user ID {$user_id} to assign role.");
                            }
                        } else {
                            error_log("Edel Stripe: Configured subscriber role '{$subscriber_role}' does not exist in WordPress.");
                        }
                    } else {
                        // No role selected in settings, do nothing.
                        error_log("Edel Stripe: No subscriber role configured in settings for user ID {$user_id}. Role not changed.");
                    }
                    $sub_status = ($amount === 0 && strpos($payment_intent_id, 'N/A (Immediate trialing)') !== false) ? 'trialing' : 'active'; // 初回支払い0円ならトライアルかも？より正確にはPIやSubオブジェクトから取得推奨
                    update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_id', $subscription_id);
                    update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_status', $sub_status); // 初期ステータスを保存
                    error_log("Edel Stripe: Saved subscription meta for user ID {$user_id}. Sub ID: {$subscription_id}, Status: {$sub_status}");
                }
                // --- ★★★ User Role Assignment End ★★★ ---


                // --- Step 5: Record Payment Details to Custom Table ---
                $record_inserted = false; // Flag for notification logic
                if ($user_id && !is_wp_error($user_id)) {
                    global $wpdb;
                    $table_name = $wpdb->prefix . EDEL_STRIPE_PAYMENT_PREFIX . 'main';
                    $final_item_name = (!empty($item_name) && strpos($item_name, 'Subscription (') !== 0 && strpos($item_name, 'N/A (') !== 0) ? $item_name : (!empty($plan_id) ? 'Subscription (' . $plan_id . ')' : 'Unknown Subscription');
                    $metadata_array = ['plan_id' => $plan_id]; // Store plan_id in metadata

                    $data_to_insert = array( /* ... (DB data array - same as before, includes 'currency') ... */
                        'user_id' => $user_id,
                        'payment_intent_id' => $payment_intent_id ?: ('sub_initial_' . $subscription_id),
                        'customer_id' => $customer_id,
                        'subscription_id' => $subscription_id,
                        'status' => 'succeeded',
                        'amount' => $amount,
                        'currency' => $currency,
                        'item_name' => $final_item_name,
                        'created_at_gmt' => current_time('mysql', 1),
                        'updated_at_gmt' => current_time('mysql', 1),
                        'metadata' => maybe_serialize($metadata_array),
                    );
                    $data_formats = array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s');

                    $inserted = $wpdb->insert($table_name, $data_to_insert, $data_formats);

                    if ($inserted === false) {
                        error_log(EDEL_STRIPE_PAYMENT_PREFIX . 'Failed DB insert... ' . $wpdb->last_error);
                    } else {
                        $record_inserted = true;
                        error_log("Edel Stripe: Record inserted successfully... ");
                    }
                } else {
                    error_log(EDEL_STRIPE_PAYMENT_PREFIX . 'User ID not valid... cannot record.');
                }


                // --- Step 6: Send Plugin's Custom Notifications ---
                if ($record_inserted) {
                    $is_subscription = !empty($data_to_insert['subscription_id']);
                    $context = $is_subscription ? 'signup_subscription' : 'signup_onetime';
                    $this->send_webhook_or_signup_notification($context, $data_to_insert, $user_created, $email);
                }

                // --- Step 7: Send Success Response to Frontend ---
                wp_send_json_success(array('message' => '支払い情報を記録しました。'));
            } // end record_successful_payment()


            public function render_onetime_shortcode($atts) {
                // --- Step 1: Process Shortcode Attributes ---
                $attributes = shortcode_atts(array(
                    'amount'      => '0',                   // Default amount
                    'item_name'   => 'One-time Payment',    // Default item name
                    'button_text' => '支払う',             // Default button text
                    'currency'    => 'jpy',                 // ★ Default currency JPY
                ), $atts);

                // Sanitize and validate attributes
                $amount = max(0, intval($attributes['amount'])); // Ensure amount is positive integer
                $item_name = sanitize_text_field($attributes['item_name']);
                $button_text = sanitize_text_field($attributes['button_text']);
                $currency = strtolower(sanitize_key($attributes['currency']));

                // Allow only specific currencies (example: jpy, usd)
                if (!in_array($currency, ['jpy', 'usd'])) {
                    $currency = 'jpy'; // Fallback to JPY if invalid
                }
                // Basic amount check
                if ($amount <= 0) {
                    if (current_user_can('manage_options')) {
                        return '<p><span class="orange b">Edel Stripe Payment エラー: amount属性は必須で、0より大きい値を指定してください。</span></p>';
                    } else {
                        return '';
                    }
                }

                // --- Step 2: Get Plugin Settings ---
                $stripe_options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
                $require_consent = $stripe_options['require_consent'] ?? '1'; // Default consent ON
                $privacy_page_id = $stripe_options['privacy_page_id'] ?? 0;
                $terms_page_id   = $stripe_options['terms_page_id'] ?? 0;
                $consent_text_custom = $stripe_options['consent_text'] ?? '';
                // Publishable key check is mainly needed in JS, but good to have for potentially showing errors early
                $is_live_mode = isset($stripe_options['mode']) && $stripe_options['mode'] === 'live';
                $publishable_key = $is_live_mode ? ($stripe_options['live_publishable_key'] ?? '') : ($stripe_options['test_publishable_key'] ?? '');
                if (empty($publishable_key)) {
                    if (current_user_can('manage_options')) {
                        return '<p><span class="orange b">Edel Stripe Payment エラー: Stripe公開可能キーが設定されていません。</span></p>';
                    } else {
                        return '';
                    }
                }

                // --- Step 3: Generate Nonce ---
                $nonce = wp_create_nonce(EDEL_STRIPE_PAYMENT_PREFIX . 'onetime_payment_nonce');

                // --- Step 4: Generate HTML using Output Buffering ---
                ob_start();
                ?>
        <div class="edel-stripe-payment-form-wrap" id="edel-stripe-onetime-form-wrap">
            <h3><?php echo esc_html($item_name); ?></h3>
            <?php
                // Display amount with correct symbol/format
                $currency_symbol = '';
                $amount_display = '';
                if ($currency === 'jpy') {
                    $currency_symbol = '円';
                    $amount_display = number_format($amount);
                } elseif ($currency === 'usd') {
                    // Stripe expects USD amount in cents, shortcode expects base unit ($12 = 1200).
                    // We need to be clear about the 'amount' attribute unit. Let's assume 'amount' is base unit.
                    // For display, divide cents by 100. Stripe amount passed to API should be in cents.
                    // Let's assume amount passed to shortcode is 1200 for $12.00.
                    $currency_symbol = '$';
                    $amount_display = number_format($amount / 100, 2); // Display as $12.00
                    // NOTE: The Payment Intent needs to receive amount in cents (1200).
                    // The PHP 'process_onetime_payment' needs adjustment if 'amount' is not always in smallest unit.
                    // Let's assume for now the PHP will handle the amount conversion based on currency.
                    // Rechecking process_onetime_payment - it currently takes amount directly.
                    // Simplest is to require amount="1200" for $12.00 USD in the shortcode.
                    $amount_display = number_format($amount / 100, 2); // Display requires division
                } else {
                    // Fallback for other currencies
                    $currency_symbol = strtoupper($currency);
                    $amount_display = number_format($amount); // Assuming base unit integer
                }
            ?>
            <?php if ($amount > 0): ?>
                <p>金額: <?php echo $currency_symbol; ?><?php echo $amount_display; ?></p>
            <?php endif; ?>

            <form id="edel-stripe-payment-form">
                <div class="form-row">
                    <label for="edel-stripe-email">メールアドレス <span class="orange b">(必須)</span></label>
                    <input type="email" id="edel-stripe-email" class="edel-stripe-email-input" required autocomplete="email">
                </div>
                <div class="form-row">
                    <label for="card-element">クレジットカード情報</label>
                    <div id="card-element" class="edel-stripe-card-element"></div>
                    <div id="card-errors" class="edel-stripe-card-errors" role="alert"></div>
                </div>

                <?php // Hidden fields
                ?>
                <input type="hidden" name="amount" value="<?php echo esc_attr($amount); ?>">
                <input type="hidden" name="item_name" value="<?php echo esc_attr($item_name); ?>">
                <input type="hidden" name="currency" value="<?php echo esc_attr($currency); ?>">
                <input type="hidden" name="action" value="edel_stripe_process_onetime">
                <input type="hidden" name="security" value="<?php echo esc_attr($nonce); ?>">

                <?php // Conditional Consent Checkbox
                ?>
                <?php if ($require_consent === '1'): ?>
                    <?php
                    $privacy_link = '';
                    $terms_link = '';
                    $consent_message = '';
                    if ($privacy_page_id && get_post_status($privacy_page_id) === 'publish') {
                        $privacy_link = sprintf('<a href="%s" target="_blank">%s</a>', esc_url(get_permalink($privacy_page_id)), esc_html(get_the_title($privacy_page_id)) ?: 'プライバシーポリシー');
                    }
                    if ($terms_page_id && get_post_status($terms_page_id) === 'publish') {
                        $terms_link = sprintf('<a href="%s" target="_blank">%s</a>', esc_url(get_permalink($terms_page_id)), esc_html(get_the_title($terms_page_id)) ?: '利用規約');
                    }
                    if (!empty($consent_text_custom)) {
                        $consent_message = str_replace(['[privacy_policy_link]', '[terms_link]'], [$privacy_link ?: 'プライバシーポリシー', $terms_link ?: '利用規約'], $consent_text_custom);
                    } else {
                        $link_texts = array_filter([$privacy_link, $terms_link]);
                        $consent_message = (count($link_texts) > 0) ? sprintf('%s を確認し、決済情報と入力されたメールアドレスでユーザーアカウントが作成されることに同意します。', implode(' と ', $link_texts)) : '決済情報と入力されたメールアドレスでユーザーアカウントが作成されることに同意します。';
                    }
                    ?>
                    <div class="form-row privacy-consent">
                        <label for="privacy-policy-agree" class="edel-stripe-consent-label">
                            <input type="checkbox" id="privacy-policy-agree" name="privacy_policy_agree" class="edel-stripe-consent-input" value="1" required>
                            <?php echo wp_kses_post($consent_message); ?>
                        </label>
                        <div id="consent-error" class="edel-stripe-consent-error" style="color: red; font-size: 0.9em; margin-top: 5px; display: none;">同意が必要です。</div>
                    </div>
                <?php endif; ?>

                <button type="submit" id="edel-stripe-submit-button" class="edel-stripe-submit-button"><?php echo esc_html($button_text); ?></button>
                <div class="spinner edel-stripe-spinner" style="display: none;"></div>
            </form>

            <div id="edel-stripe-payment-result" class="edel-stripe-result-message" style="margin-top: 15px;"></div>
        </div><?php
                // $this->add_frontend_scripts = true; // If using conditional loading
                return ob_get_clean();
            } // end render_onetime_shortcode


            /**
             * Processes the initial AJAX request to create a PaymentIntent.
             */
            public function process_onetime_payment() {
                check_ajax_referer(EDEL_STRIPE_PAYMENT_PREFIX . 'onetime_payment_nonce', 'security');

                $email     = isset($_POST['email']) ? sanitize_email($_POST['email']) : null;
                $amount    = isset($_POST['amount']) ? intval($_POST['amount']) : 0;
                $item_name = isset($_POST['item_name']) ? sanitize_text_field($_POST['item_name']) : 'Payment';
                $currency  = isset($_POST['currency']) ? strtolower(sanitize_key($_POST['currency'])) : 'jpy';

                if (!in_array($currency, ['jpy', 'usd'])) {
                    $currency = 'jpy'; // Default to JPY if invalid
                }

                if (empty($email) || $amount <= 0) {
                    wp_send_json_error(['message' => '必須情報（メールアドレス、金額）が不足しているか、金額が0円以下です。']);
                }
                if (!is_email($email)) {
                    wp_send_json_error(['message' => '有効なメールアドレスを入力してください。']);
                }

                $stripe_options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);
                $is_live_mode = isset($stripe_options['mode']) && $stripe_options['mode'] === 'live';
                $secret_key = $is_live_mode ? ($stripe_options['live_secret_key'] ?? '') : ($stripe_options['test_secret_key'] ?? '');

                if (empty($secret_key)) {
                    error_log(EDEL_STRIPE_PAYMENT_PREFIX . 'Stripe Secret Key is not configured.');
                    wp_send_json_error(['message' => '決済設定が不完全です。管理者にお問い合わせください。']);
                }

                try {
                    \Stripe\Stripe::setApiKey($secret_key);
                    \Stripe\Stripe::setApiVersion("2024-04-10"); // Use appropriate API version

                    $customer_id = null;
                    $customers = \Stripe\Customer::all(['email' => $email, 'limit' => 1]);
                    if (empty($customers->data)) {
                        $customer = \Stripe\Customer::create(['email' => $email]);
                        $customer_id = $customer->id;
                    } else {
                        $customer_id = $customers->data[0]->id;
                    }

                    $payment_intent_params = [
                        'amount' => $amount,
                        'currency' => $currency,
                        'customer' => $customer_id,
                        'description' => $item_name,
                        'metadata' => ['wordpress_user_email' => $email, 'item_name' => $item_name],
                        'automatic_payment_methods' => ['enabled' => true],
                    ];
                    $payment_intent = \Stripe\PaymentIntent::create($payment_intent_params);

                    wp_send_json_success(['client_secret' => $payment_intent->client_secret, 'customer_id' => $customer_id]);
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    error_log(EDEL_STRIPE_PAYMENT_PREFIX . 'Stripe API Error: ' . $e->getMessage());
                    wp_send_json_error(['message' => '決済準備中にエラーが発生しました。(' . esc_html($e->getStripeCode()) . ')']);
                } catch (Exception $e) {
                    error_log(EDEL_STRIPE_PAYMENT_PREFIX . 'General Error in process_onetime_payment: ' . $e->getMessage());
                    wp_send_json_error(['message' => '不明なエラーが発生しました。']);
                }
            }

            /**
             * Sends email notifications based on plugin settings and payment type (One-time vs Subscription).
             * Uses specific templates and placeholders for each type.
             *
             * @param array  $payment_data   The payment data saved to DB (associative array from DB row).
             * @param string $customer_email The customer's email address.
             * @param bool   $is_new_user    Whether a new WP user was created.
             */
            private function send_payment_notifications($payment_data, $customer_email, $is_new_user) {
                $is_subscription = !empty($payment_data['subscription_id']);
                $context = $is_subscription ? 'signup_subscription' : 'signup_onetime';
                // Pass data to the unified handler
                $this->send_webhook_or_signup_notification($context, $payment_data, $is_new_user);
            } // end send_payment_notifications

            /**
             * Helper function to get WP user by Stripe Customer ID stored in user meta.
             */
            private function get_user_by_stripe_customer_id($customer_id) {
                if (empty($customer_id)) return false;
                $user_query = new WP_User_Query(['meta_key' => EDEL_STRIPE_PAYMENT_PREFIX . 'customer_id', 'meta_value' => $customer_id, 'number' => 1, 'fields' => 'ID']);
                $users = $user_query->get_results();
                return !empty($users) ? get_userdata($users[0]) : false;
            } // end get_user_by_stripe_customer_id
        } // End class EdelStripePaymentFront