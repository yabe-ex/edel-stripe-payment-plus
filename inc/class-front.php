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
        register_rest_route(
            'edel-stripe/v1', // Namespace
            '/webhook',       // Route
            array(
                'methods'             => 'POST', // Accept only POST requests
                'callback'            => array($this, 'handle_webhook'), // Callback function
                'permission_callback' => '__return_true', // Allow public access (Stripe needs to reach it) - Security is handled by signature verification
            )
        );
        error_log('Edel Stripe: Webhook REST route registered.'); // Log registration
    }

    /**
     * ★新規追加: Handles incoming Stripe Webhook events.
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

        if (empty($sig_header)) {
            error_log($error_log_prefix . 'Missing signature.');
            return new WP_REST_Response(['error' => 'Missing signature'], 400);
        }
        if (empty($endpoint_secret_test) && empty($endpoint_secret_live)) {
            error_log($error_log_prefix . 'Secret not configured.');
            return new WP_REST_Response(['error' => 'Webhook secret not configured'], 500);
        }

        // Determine which secret to use based on preliminary parsing (or try test first)
        // A more robust way might check the event->livemode AFTER constructing with one secret,
        // but requires at least one secret to be potentially valid.
        // Let's try test first, then potentially live if needed and possible.
        // Or better: check livemode after constructing with test, then re-construct if needed.
        $endpoint_secret = $endpoint_secret_test; // Assume test first for construction attempt
        if (empty($endpoint_secret)) $endpoint_secret = $endpoint_secret_live; // Fallback to live if test is empty
        if (empty($endpoint_secret)) { // If both are empty after all
            error_log($error_log_prefix . 'Both webhook secrets are empty in settings.');
            return new WP_REST_Response(['error' => 'Webhook secret not configured'], 500);
        }


        try {
            // Construct the event object, this verifies the signature
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                $endpoint_secret
            );

            // Double check if the correct secret was used based on livemode
            $correct_secret = $event->livemode ? $endpoint_secret_live : $endpoint_secret_test;
            if (empty($correct_secret)) {
                throw new \Stripe\Exception\SignatureVerificationException('Appropriate webhook secret not configured for event mode.', $sig_header, $payload);
            }
            // If the secret used didn't match the event mode, reconstruct with the correct one
            if ($correct_secret !== $endpoint_secret) {
                $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $correct_secret);
            }
            $mode_used = $event->livemode ? 'Live' : 'Test';
            error_log($error_log_prefix . 'Signature verified (' . $mode_used . '). Event ID: ' . $event->id . ' Type: ' . $event->type);
        } catch (\UnexpectedValueException $e) { /* Invalid payload */
            error_log($error_log_prefix . 'Webhook Error (Invalid Payload): ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) { /* Invalid signature */
            error_log($error_log_prefix . 'Webhook Error (Invalid Signature): ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'Invalid signature'], 400);
        } catch (Exception $e) { /* Other construction errors */
            error_log($error_log_prefix . 'Webhook Error (Construction): ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'Webhook processing error'], 500);
        }

        // --- Handle the event based on its type ---
        global $wpdb;
        $table_name = $wpdb->prefix . EDEL_STRIPE_PAYMENT_PREFIX . 'main';

        try {
            switch ($event->type) {
                case 'invoice.payment_succeeded':
                    $invoice = $event->data->object;
                    // Check if it's for a subscription payment
                    if (isset($invoice->subscription) && !empty($invoice->subscription) && $invoice->billing_reason === 'subscription_cycle') {
                        error_log($error_log_prefix . 'Processing recurring payment success for SubID: ' . $invoice->subscription . ' InvoiceID: ' . $invoice->id);
                        $customer_id = $invoice->customer;
                        $subscription_id = $invoice->subscription;
                        $payment_intent_id = $invoice->payment_intent; // Might be null if paid out of band
                        $amount_paid = $invoice->amount_paid; // Amount in smallest unit
                        $currency = $invoice->currency;
                        $plan_id = null;
                        if (isset($invoice->lines->data[0]->price->id)) {
                            $plan_id = $invoice->lines->data[0]->price->id; // Price ID (plan)
                        }
                        $item_name = 'Subscription Recurring Payment (' . ($plan_id ?: $subscription_id) . ')'; // Item name

                        // Find WordPress user by Stripe Customer ID
                        $user = $this->get_user_by_stripe_customer_id($customer_id);
                        if ($user) {
                            $user_id = $user->ID;
                            // Record this payment in the custom table
                            $data_to_insert = [ /* ... Prepare data array ... */
                                'user_id' => $user_id,
                                'payment_intent_id' => $payment_intent_id ?? ('invoice_' . $invoice->id),
                                'customer_id' => $customer_id,
                                'subscription_id' => $subscription_id,
                                'status' => 'succeeded',
                                'amount' => $amount_paid,
                                'currency' => $currency,
                                'item_name' => $item_name,
                                'created_at_gmt' => gmdate('Y-m-d H:i:s', $invoice->created), // Use invoice creation time
                                'updated_at_gmt' => gmdate('Y-m-d H:i:s', time()),
                                'metadata' => maybe_serialize(['plan_id' => $plan_id, 'invoice_id' => $invoice->id])
                            ];
                            $data_formats = ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'];
                            $inserted = $wpdb->insert($table_name, $data_to_insert, $data_formats);
                            if ($inserted === false) {
                                error_log($error_log_prefix . "Failed to insert recurring payment record. DB Error: " . $wpdb->last_error);
                            }

                            // Update user meta status and role (ensure active)
                            update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_status', 'active');
                            $subscriber_role = $options['sub_subscriber_role'] ?? null;
                            if ($subscriber_role && get_role($subscriber_role)) {
                                $user_obj = new WP_User($user_id);
                                if (!$user_obj->has_cap($subscriber_role)) { // Add role if they don't have it
                                    $user_obj->add_role($subscriber_role);
                                    error_log($error_log_prefix . "Re-assigned role '{$subscriber_role}' on recurring payment success for user ID {$user_id}");
                                }
                            }
                            // Optionally send recurring payment success email? (Probably not needed if Stripe sends receipt)
                        } else {
                            error_log($error_log_prefix . "User not found for Customer ID: " . $customer_id . " in event invoice.payment_succeeded");
                        }
                    }
                    break;

                case 'invoice.payment_failed':
                    $invoice = $event->data->object;
                    // Check if it's related to a subscription
                    if (isset($invoice->subscription) && !empty($invoice->subscription)) {
                        error_log($error_log_prefix . 'Processing payment failure for SubID: ' . $invoice->subscription . ' InvoiceID: ' . $invoice->id);
                        $customer_id = $invoice->customer;
                        $subscription_id = $invoice->subscription;
                        // Find WordPress user
                        $user = $this->get_user_by_stripe_customer_id($customer_id);
                        if ($user) {
                            $user_id = $user->ID;
                            // Update user meta status (e.g., 'past_due', 'payment_failed')
                            // Stripe subscription status might update via customer.subscription.updated later
                            update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_status', 'payment_failed');
                            error_log($error_log_prefix . "Set user meta status to 'payment_failed' for user ID {$user_id}");
                            // TODO: Send payment failed notification email (using new templates?)
                            // TODO: Consider role removal based on retry logic/grace period (handle in customer.subscription.updated?)
                        } else {
                            error_log($error_log_prefix . "User not found for Customer ID: " . $customer_id . " in event invoice.payment_failed");
                        }
                    }
                    break;

                case 'customer.subscription.deleted':
                    $subscription = $event->data->object;
                    error_log($error_log_prefix . 'Processing subscription cancellation for SubID: ' . $subscription->id);
                    $customer_id = $subscription->customer;
                    // Find WordPress user
                    $user = $this->get_user_by_stripe_customer_id($customer_id);
                    if ($user) {
                        $user_id = $user->ID;
                        // Update user meta status
                        update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_status', 'canceled');
                        // Remove subscriber role
                        $subscriber_role = $options['sub_subscriber_role'] ?? null;
                        if ($subscriber_role) {
                            $user_obj = new WP_User($user_id);
                            if ($user_obj->has_cap($subscriber_role)) {
                                $user_obj->remove_role($subscriber_role);
                                error_log($error_log_prefix . "Removed role '{$subscriber_role}' for user ID {$user_id} due to subscription cancellation.");
                            }
                        }
                        // TODO: Send cancellation notification email?
                    } else {
                        error_log($error_log_prefix . "User not found for Customer ID: " . $customer_id . " in event customer.subscription.deleted");
                    }
                    break;

                case 'customer.subscription.updated':
                    $subscription = $event->data->object;
                    error_log($error_log_prefix . 'Processing subscription update for SubID: ' . $subscription->id . ' New Status: ' . $subscription->status);
                    $customer_id = $subscription->customer;
                    // Find WordPress user
                    $user = $this->get_user_by_stripe_customer_id($customer_id);
                    if ($user) {
                        $user_id = $user->ID;
                        $new_status = $subscription->status; // e.g., active, past_due, unpaid, canceled, trialing
                        // Update user meta status
                        update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_status', $new_status);
                        error_log($error_log_prefix . "Updated user meta status to '{$new_status}' for user ID {$user_id}");

                        // Update user role based on new status
                        $subscriber_role = $options['sub_subscriber_role'] ?? null;
                        if ($subscriber_role) {
                            $user_obj = new WP_User($user_id);
                            if (in_array($new_status, ['active', 'trialing'])) {
                                // Ensure user has the role
                                if (!$user_obj->has_cap($subscriber_role)) {
                                    $user_obj->add_role($subscriber_role); // Use add_role to avoid removing other roles maybe? Or stick to set_role? Let's use set_role for simplicity.
                                    $user_obj->set_role($subscriber_role);
                                    error_log($error_log_prefix . "Assigned role '{$subscriber_role}' on subscription update for user ID {$user_id}");
                                }
                            } else {
                                // If status is not active/trialing, remove the role
                                if ($user_obj->has_cap($subscriber_role)) {
                                    $user_obj->remove_role($subscriber_role);
                                    error_log($error_log_prefix . "Removed role '{$subscriber_role}' on subscription update (status: {$new_status}) for user ID {$user_id}");
                                }
                            }
                        }
                        // TODO: Update next billing date from $subscription->current_period_end ? Store in user meta?
                        // update_user_meta($user_id, EDEL_STRIPE_PAYMENT_PREFIX . 'subscription_next_bill', gmdate('Y-m-d H:i:s', $subscription->current_period_end));
                    } else {
                        error_log($error_log_prefix . "User not found for Customer ID: " . $customer_id . " in event customer.subscription.updated");
                    }
                    break;

                // Add other event handlers here if needed

                default:
                    error_log('[Edel Stripe Webhook] Received unhandled event type: ' . $event->type);
            }
        } catch (Exception $e) {
            // Catch errors during event processing
            error_log('[Edel Stripe Webhook] Error processing event (' . $event->id . ' Type: ' . $event->type . '): ' . $e->getMessage());
            // Return 500 so Stripe might retry? Or return 200 to prevent retries if error is permanent?
            // Let's return 500 for now to indicate processing failure.
            return new WP_REST_Response(['error' => 'Webhook event processing error'], 500);
        }


        // Return a 200 OK response to Stripe
        return new WP_REST_Response(['received' => true], 200);
    } // end handle_webhook

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
            // ↓↓↓ フロント成功メッセージを追加 ↓↓↓
            'success_message' => $frontend_success_message,
            // ↑↑↑ フロント成功メッセージを追加 ↑↑↑
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
                    $this->send_payment_notifications($data_to_insert, $email, $user_created);
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
                // --- Step 1: Get Email Settings ---
                $options = get_option(EDEL_STRIPE_PAYMENT_PREFIX . 'options', []);

                // Common settings (From Name, From Email, Admin Recipient)
                $default_from_name = get_bloginfo('name');
                $default_from_email = get_option('admin_email');
                $mail_from_name = $options['mail_from_name'] ?? $default_from_name;
                $mail_from_email = $options['mail_from_email'] ?? $default_from_email;
                $admin_notify_email = $options['admin_notify_email'] ?? $default_from_email;

                // --- Step 2: Determine Payment Type and Get Specific Templates ---
                $is_subscription = !empty($payment_data['subscription_id']); // Check if subscription ID exists

                // Initialize template variables
                $send_customer_email = '0';
                $admin_subject_template = '';
                $admin_body_template = '';
                $customer_subject_template = '';
                $customer_body_template = '';

                // ★ 決済タイプに応じて読み込むオプションキーとデフォルト値を切り替える
                if ($is_subscription) {
                    // === Load Subscription Email Settings ===
                    $send_customer_email = $options['sub_send_customer_email'] ?? '0';
                    // Default templates for Subscription
                    $default_admin_subject = '[{site_name}] 新しいサブスクリプション申込がありました';
                    $default_admin_body = "サブスクリプション申込がありました。\n\n購入者Email: {customer_email}\nプラン: {item_name} ({plan_id})\n顧客ID: {customer_id}\nサブスクID: {subscription_id}\nUser ID: {user_id}";
                    $default_customer_subject = '[{site_name}] サブスクリプションへようこそ';
                    $default_customer_body = "{user_name} 様\n\nサブスクリプション「{item_name}」へのお申し込みありがとうございます。\n\nマイアカウントページから契約状況をご確認いただけます。\n\n--\n{site_name}\n{site_url}";
                    // Get saved or default templates using 'sub_' prefixed keys
                    $admin_subject_template    = $options['sub_admin_mail_subject'] ?? $default_admin_subject;
                    $admin_body_template       = $options['sub_admin_mail_body'] ?? $default_admin_body;
                    $customer_subject_template = $options['sub_customer_mail_subject'] ?? $default_customer_subject;
                    $customer_body_template    = $options['sub_customer_mail_body'] ?? $default_customer_body;
                } else {
                    // === Load One-Time Payment Email Settings ===
                    $send_customer_email = $options['ot_send_customer_email'] ?? '0';
                    // Default templates for One-Time
                    $default_admin_subject = '[{site_name}] 新しい決済がありました(買い切り)';
                    $default_admin_body = "買い切り決済が完了しました。\n\n購入者Email: {customer_email}\n商品/内容: {item_name}\n金額: {amount}\n決済日時: {transaction_date}\n\nPayment Intent ID: {payment_intent_id}\nCustomer ID: {customer_id}\nWordPress User ID: {user_id}";
                    $default_customer_subject = '[{site_name}] ご購入ありがとうございます';
                    $default_customer_body = "{user_name} 様\n\n「{item_name}」のご購入ありがとうございます。\n金額: {amount}\n日時: {transaction_date}\n\n--\n{site_name}\n{site_url}";
                    // Get saved or default templates using 'ot_' prefixed keys
                    $admin_subject_template    = $options['ot_admin_mail_subject'] ?? $default_admin_subject;
                    $admin_body_template       = $options['ot_admin_mail_body'] ?? $default_admin_body;
                    $customer_subject_template = $options['ot_customer_mail_subject'] ?? $default_customer_subject;
                    $customer_body_template    = $options['ot_customer_mail_body'] ?? $default_customer_body;
                }

                // Validate recipient emails
                if (!is_email($admin_notify_email)) {
                    $admin_notify_email = get_option('admin_email'); /* log error */
                }
                if (!is_email($customer_email)) {
                    $send_customer_email = '0'; /* log error */
                }

                // --- Step 3: Prepare Placeholders and Values ---
                // (通貨フォーマット処理を含む - 変更なし)
                $user_display_name = $customer_email;
                $user_id_display = 'N/A';
                if (!empty($payment_data['user_id'])) {
                    $user_id_display = $payment_data['user_id'];
                    $user_info = get_userdata($payment_data['user_id']);
                    if ($user_info && !empty($user_info->display_name)) {
                        $user_display_name = $user_info->display_name;
                    }
                }
                $transaction_timestamp = isset($payment_data['created_at_gmt']) ? strtotime($payment_data['created_at_gmt']) : time();
                $amount_raw = $payment_data['amount'] ?? 0;
                $currency = strtolower($payment_data['currency'] ?? 'jpy');
                $formatted_amount = '';
                if ($currency === 'jpy') {
                    $formatted_amount = number_format($amount_raw) . '円';
                } elseif ($currency === 'usd') {
                    $formatted_amount = '$' . number_format($amount_raw / 100, 2);
                } else {
                    $formatted_amount = number_format($amount_raw) . ' ' . strtoupper($currency);
                }
                $metadata = maybe_unserialize($payment_data['metadata'] ?? '');
                $plan_id_from_meta = $metadata['plan_id'] ?? 'N/A'; // Get plan_id from metadata

                $replacements = [
                    '{item_name}'         => $payment_data['item_name'] ?? '',
                    '{amount}'            => $formatted_amount,
                    '{customer_email}'    => $customer_email,
                    '{payment_intent_id}' => $payment_data['payment_intent_id'] ?? 'N/A',
                    '{customer_id}'       => $payment_data['customer_id'] ?? 'N/A',
                    '{transaction_date}'  => wp_date(get_option('date_format') . ' ' . get_option('time_format'), $transaction_timestamp),
                    '{user_name}'         => $user_display_name,
                    '{user_id}'           => $user_id_display,
                    '{site_name}'         => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                    '{site_url}'          => home_url(),
                    '{subscription_id}'   => $payment_data['subscription_id'] ?? 'N/A',
                    '{plan_id}'           => $plan_id_from_meta, // Use value from metadata
                ];

                // --- Step 4: Prepare Headers (using filters - 変更なし) ---
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
                if (!empty($admin_notify_email)) {
                    // ★ 読み込んだテンプレート変数を使う
                    if (!is_string($admin_subject_template)) $admin_subject_template = $is_subscription ? $default_sub_admin_subject : $default_admin_subject;
                    if (!is_string($admin_body_template)) $admin_body_template = $is_subscription ? $default_sub_admin_body : $default_admin_body;

                    $admin_subject = str_replace(array_keys($replacements), array_values($replacements), $admin_subject_template);
                    $admin_message_raw = str_replace(array_keys($replacements), array_values($replacements), $admin_body_template);
                    $admin_message = str_replace(["\r\n", "\r"], "\n", $admin_message_raw);
                    $admin_message = str_replace("\n", "\r\n", $admin_message);

                    if (!wp_mail($admin_notify_email, $admin_subject, $admin_message)) { /* Log error */
                        error_log(EDEL_STRIPE_PAYMENT_PREFIX . ' Admin email failed');
                    } else { /* Log success */
                        error_log("Edel Stripe: Admin notification sent to " . $admin_notify_email);
                    }
                }

                // --- Step 6: Send Customer Notification (If enabled) ---
                if ($send_customer_email === '1' && !empty($customer_email)) {
                    // ★ 読み込んだテンプレート変数を使う
                    if (!is_string($customer_subject_template)) $customer_subject_template = $is_subscription ? $default_sub_customer_subject : $default_customer_subject;
                    if (!is_string($customer_body_template)) $customer_body_template = $is_subscription ? $default_sub_customer_body : $default_customer_body;

                    $customer_subject = str_replace(array_keys($replacements), array_values($replacements), $customer_subject_template);
                    $customer_message_raw = str_replace(array_keys($replacements), array_values($replacements), $customer_body_template);

                    if ($is_new_user) { /* ... Add new user text ... */
                    }
                    $customer_message = str_replace(["\r\n", "\r"], "\n", $customer_message_raw);
                    $customer_message = str_replace("\n", "\r\n", $customer_message);

                    if (!wp_mail($customer_email, $customer_subject, $customer_message)) { /* Log error */
                        error_log(EDEL_STRIPE_PAYMENT_PREFIX . ' Customer email failed: ' . $customer_email);
                    } else { /* Log success */
                        error_log("Edel Stripe: Customer notification sent to " . $customer_email);
                    }
                }

                // --- Step 7: Clean up filters (変更なし) ---
                remove_filter('wp_mail_from_name', $set_from_name);
                remove_filter('wp_mail_from', $set_from_email);
                remove_filter('wp_mail_content_type', $set_content_type);
            } // end send_payment_notifications()
        } // End class EdelStripePaymentFront