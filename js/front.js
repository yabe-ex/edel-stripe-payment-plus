jQuery(document).ready(function ($) {
    // --- グローバル変数と初期チェック ---
    var stripe, elements, cardElement, cardElementSub; // Stripe関連オブジェクト
    var edelStripeParams = window.edelStripeParams || {}; // PHPからのパラメータ

    // 必須パラメータのチェック
    if (
        !edelStripeParams.publishable_key ||
        !edelStripeParams.ajax_url ||
        !edelStripeParams.record_nonce ||
        typeof edelStripeParams.success_message === 'undefined'
    ) {
        console.error('Edel Stripe Params not loaded correctly from wp_localize_script.');
        $('#edel-stripe-payment-result, #edel-stripe-subscription-result').addClass('error').text('エラー: 決済設定(JS)が読み込めませんでした。');
        return;
    }

    // Stripe.js初期化
    try {
        stripe = Stripe(edelStripeParams.publishable_key);
        elements = stripe.elements();
    } catch (error) {
        console.error('Stripe initialization failed:', error);
        $('#edel-stripe-payment-result, #edel-stripe-subscription-result').addClass('error').text('エラー: Stripeの初期化に失敗しました。');
        return;
    }

    // Card Elementの共通スタイル
    var style = {
        base: {
            iconColor: '#666',
            color: '#333',
            fontWeight: '500',
            fontFamily: 'inherit',
            fontSize: '16px',
            fontSmoothing: 'antialiased',
            ':-webkit-autofill': { color: '#333' },
            '::placeholder': { color: '#aab7c4' }
        },
        invalid: { iconColor: '#dc3545', color: '#dc3545' }
    };
    var cardElementOptions = { style: style, hidePostalCode: true };

    // --- 買い切りフォーム用 Card Element 初期化 & イベントリスナー ---
    var cardElementContainer = $('#card-element');
    if (cardElementContainer.length > 0) {
        try {
            cardElement = elements.create('card', cardElementOptions);
            cardElement.mount('#card-element');
            cardElement.on('change', function (event) {
                $('#card-errors').text(event.error ? event.error.message : '');
            });
            initializeOnetimeFormHandler(cardElement); // ★ フォームハンドラーを別関数に
        } catch (error) {
            console.error('Stripe Card Element (onetime) failed:', error);
            $('#card-errors').text('カード入力欄の表示に失敗しました。');
        }
    }

    // --- サブスクフォーム用 Card Element 初期化 & イベントリスナー ---
    var cardElementSubContainer = $('#card-element-sub');
    if (cardElementSubContainer.length > 0) {
        try {
            cardElementSub = elements.create('card', cardElementOptions);
            cardElementSub.mount('#card-element-sub');
            cardElementSub.on('change', function (event) {
                $('#card-errors-sub').text(event.error ? event.error.message : '');
            });
            initializeSubscriptionFormHandler(cardElementSub); // フォームハンドラー呼び出し
        } catch (error) {
            console.error('Stripe Card Element (subscription) failed:', error);
            $('#card-errors-sub').text('カード入力欄表示失敗');
        }
    }

    // =============================================
    // === 買い切りフォーム処理関数 ==============
    // =============================================
    function initializeOnetimeFormHandler(cardElementInstance) {
        var form = $('#edel-stripe-payment-form');
        // Check if the specific form and card element exist for this handler
        if (form.length === 0 || !cardElementInstance) return;

        // Get elements specific to this form
        var submitButton = form.find('#edel-stripe-submit-button');
        var resultMessage = form.siblings('#edel-stripe-payment-result'); // Assuming result div is sibling or adjust selector
        var spinner = form.find('.edel-stripe-spinner');
        var errorDiv = form.find('#card-errors');
        var consentCheckbox = form.find('#privacy-policy-agree');
        var consentErrorDiv = form.find('#consent-error');

        form.on('submit', function (event) {
            event.preventDefault();

            // Consent check
            if (consentCheckbox.length > 0) {
                if (!consentCheckbox.is(':checked')) {
                    if (consentErrorDiv.length > 0) consentErrorDiv.text('同意チェックボックスを確認してください。').show();
                    return;
                } else {
                    if (consentErrorDiv.length > 0) consentErrorDiv.hide();
                }
            }

            // UI Reset
            submitButton.prop('disabled', true);
            if (spinner.length > 0) spinner.show();
            if (resultMessage.length > 0) resultMessage.removeClass('success error info').text('');
            if (errorDiv.length > 0) errorDiv.text('');

            // Get form values
            var userEmail = form.find('#edel-stripe-email').val();
            var securityNonce = form.find('input[name="security"]').val();
            var amount = form.find('input[name="amount"]').val();
            var itemName = form.find('input[name="item_name"]').val();
            var currency = form.find('input[name="currency"]').val() || 'jpy'; // ★ 通貨を取得
            var customerId;

            // Step 1: Request Payment Intent Creation (★ Add currency to data)
            $.post(edelStripeParams.ajax_url, {
                action: 'edel_stripe_process_onetime',
                security: securityNonce,
                email: userEmail,
                amount: amount,
                item_name: itemName,
                currency: currency // ★★★ この行を追加 ★★★
            })
                .done(function (response) {
                    // Step 2: Handle Backend Response
                    if (response.success && response.data.client_secret) {
                        customerId = response.data.customer_id;
                        var clientSecret = response.data.client_secret;

                        // Step 3: Confirm Card Payment & Handle Actions
                        stripe
                            .confirmCardPayment(clientSecret, {
                                payment_method: { card: cardElementInstance, billing_details: { email: userEmail } }
                            })
                            .then(function (result) {
                                // Step 4: Handle Payment Result
                                if (result.error) {
                                    // Payment Failed
                                    if (resultMessage.length > 0)
                                        resultMessage
                                            .removeClass('success info')
                                            .addClass('error')
                                            .text(result.error.message || '支払いを完了できませんでした。');
                                    submitButton.prop('disabled', false);
                                    if (spinner.length > 0) spinner.hide();
                                } else if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                                    // Payment Succeeded
                                    if (resultMessage.length > 0)
                                        resultMessage
                                            .removeClass('success error')
                                            .addClass('info')
                                            .text('支払いが完了しました。情報を記録しています...');
                                    var paymentIntentId = result.paymentIntent.id;
                                    var actualAmount = result.paymentIntent.amount;
                                    var actualCurrency = result.paymentIntent.currency; // Get actual currency

                                    // Step 5: Send data for recording
                                    $.post(edelStripeParams.ajax_url, {
                                        action: 'edel_stripe_record_payment',
                                        security: edelStripeParams.record_nonce,
                                        payment_intent_id: paymentIntentId,
                                        email: userEmail,
                                        customer_id: customerId || '',
                                        amount: actualAmount,
                                        currency: actualCurrency, // Send actual currency used
                                        item_name: itemName
                                    })
                                        .done(function (recordResponse) {
                                            /* ...Record success handling... */ if (resultMessage.length > 0) {
                                                if (recordResponse.success) {
                                                    resultMessage
                                                        .removeClass('error info')
                                                        .addClass('success')
                                                        .text(edelStripeParams.success_message || '記録完了');
                                                    cardElementInstance.clear();
                                                } else {
                                                    resultMessage
                                                        .removeClass('success info')
                                                        .addClass('error')
                                                        .text(recordResponse.data.message || '記録エラー');
                                                }
                                            }
                                        })
                                        .fail(function () {
                                            /* ...Record AJAX fail handling... */ if (resultMessage.length > 0)
                                                resultMessage.removeClass('success info').addClass('error').text('記録通信失敗');
                                        })
                                        .always(function () {
                                            submitButton.prop('disabled', false);
                                            if (spinner.length > 0) spinner.hide();
                                        });
                                } else {
                                    // Payment not succeeded (e.g. processing)
                                    if (resultMessage.length > 0)
                                        resultMessage
                                            .removeClass('success error')
                                            .addClass('info')
                                            .text('支払いが確定しませんでした。ステータス: ' + result.paymentIntent.status);
                                    submitButton.prop('disabled', false);
                                    if (spinner.length > 0) spinner.hide();
                                }
                            }); // End confirmCardPayment.then
                    } else {
                        // Failed to create Payment Intent
                        if (resultMessage.length > 0)
                            resultMessage
                                .removeClass('success info')
                                .addClass('error')
                                .text(response.data.message || '決済の準備ができませんでした。');
                        submitButton.prop('disabled', false);
                        if (spinner.length > 0) spinner.hide();
                    }
                }) // End initial AJAX .done
                .fail(function () {
                    // Initial AJAX failed
                    if (resultMessage.length > 0)
                        resultMessage.removeClass('success info').addClass('error').text('決済サーバーとの通信に失敗しました。');
                    submitButton.prop('disabled', false);
                    if (spinner.length > 0) spinner.hide();
                }); // End initial AJAX call
        }); // End form.on('submit')
    } // End initializeOnetimeFormHandler

    // ==================================================
    // === サブスクフォーム処理関数 =====================
    // ==================================================
    function initializeSubscriptionFormHandler(cardElementInstance) {
        // Use more robust selector if IDs might have random numbers
        // var formSub = $('form[id^="edel-stripe-subscription-payment-form-"]');
        var formSub = $('#edel-stripe-subscription-payment-form'); // Use static ID if PHP is changed
        if (formSub.length === 0 || !cardElementInstance) return;

        var submitButton = $('#edel-stripe-submit-button-sub'); // Use static ID
        var resultMessage = $('#edel-stripe-subscription-result'); // Use static ID
        var spinner = formSub.find('.spinner-sub'); // Use specific class
        var errorDiv = $('#card-errors-sub'); // Use static ID
        var consentCheckbox = $('#privacy-policy-agree-sub'); // Use static ID
        var consentErrorDiv = $('#consent-error-sub'); // Use static ID

        formSub.on('submit', function (event) {
            event.preventDefault();

            // 同意チェック
            if (consentCheckbox.length > 0) {
                /* ... */ if (!consentCheckbox.is(':checked')) {
                    if (consentErrorDiv.length > 0) consentErrorDiv.show();
                    return;
                } else {
                    if (consentErrorDiv.length) consentErrorDiv.hide();
                }
            }

            // UIリセット
            submitButton.prop('disabled', true);
            if (spinner.length > 0) spinner.show();
            if (resultMessage.length > 0) resultMessage.removeClass('success error info').text('');
            if (errorDiv.length > 0) errorDiv.text('');

            // フォーム値取得
            var userEmail = $('#edel-stripe-sub-email').val(); // Use static ID
            var securityNonce = formSub.find('input[name="subscription_security"]').val(); // ★ Use correct nonce name
            var planId = formSub.find('input[name="plan_id"]').val();
            var customerId; // For storing customer ID from response

            // Step 1: バックエンドにサブスク作成依頼
            $.post(edelStripeParams.ajax_url, {
                action: 'edel_stripe_process_subscription',
                subscription_security: securityNonce,
                email: userEmail,
                plan_id: planId
            })
                .done(function (response) {
                    // Step 2: バックエンド応答処理
                    if (response.success) {
                        customerId = response.data.customer_id; // Store customer ID
                        var subscriptionId = response.data.subscription_id; // Store sub ID

                        if (response.data.client_secret) {
                            // 初回支払いに認証が必要
                            var clientSecret = response.data.client_secret;

                            // Step 3: フロントエンドで支払い確認＆認証
                            stripe
                                .confirmCardPayment(clientSecret, {
                                    payment_method: { card: cardElementInstance, billing_details: { email: userEmail } }
                                })
                                .then(function (result) {
                                    // Step 4: 結果処理
                                    if (result.error) {
                                        // 支払い失敗
                                        if (resultMessage.length > 0)
                                            resultMessage
                                                .removeClass('success info')
                                                .addClass('error')
                                                .text(result.error.message || 'お支払いを完了できませんでした。');
                                        submitButton.prop('disabled', false);
                                        if (spinner.length > 0) spinner.hide();
                                    } else {
                                        // 支払い成功 or ステータス確認
                                        if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                                            // 初回支払い成功 -> サーバーに記録依頼
                                            if (resultMessage.length > 0)
                                                resultMessage
                                                    .removeClass('success error')
                                                    .addClass('info')
                                                    .text('お申し込みが完了しました。情報を記録しています...');

                                            var paymentIntentId = result.paymentIntent.id;
                                            var actualAmount = result.paymentIntent.amount; // ★ 実金額
                                            var actualCurrency = result.paymentIntent.currency; // ★ 実通貨コード
                                            // Fetch amount and item name for recording (or pass defaults)
                                            // var amount = result.paymentIntent.amount / 100;
                                            var itemNameForRecord = 'Subscription (' + planId + ')'; // Construct item name

                                            // Step 5: 記録用AJAX呼び出し
                                            $.post(edelStripeParams.ajax_url, {
                                                action: 'edel_stripe_record_payment',
                                                security: edelStripeParams.record_nonce,
                                                payment_intent_id: paymentIntentId,
                                                subscription_id: subscriptionId, // ★ Pass subscription ID
                                                plan_id: planId,
                                                email: userEmail,
                                                customer_id: customerId || '',
                                                amount: actualAmount, // ★ 実金額
                                                currency: actualCurrency, // ★ 実通貨コード
                                                item_name: itemNameForRecord
                                            })
                                                .done(function (recordResponse) {
                                                    /* ...記録結果表示...*/ if (resultMessage.length > 0) {
                                                        if (recordResponse.success) {
                                                            resultMessage
                                                                .removeClass('error info')
                                                                .addClass('success')
                                                                .text(edelStripeParams.success_message || 'お申し込みが完了し、記録されました。');
                                                            cardElementInstance.clear();
                                                        } else {
                                                            resultMessage
                                                                .removeClass('success info')
                                                                .addClass('error')
                                                                .text(
                                                                    recordResponse.data.message ||
                                                                        'お申し込みは完了しましたが、記録中にエラーが発生しました。'
                                                                );
                                                            console.error('RecErr:', recordResponse.data);
                                                        }
                                                    }
                                                })
                                                .fail(function () {
                                                    /* ...記録通信失敗...*/ if (resultMessage.length > 0)
                                                        resultMessage
                                                            .removeClass('success info')
                                                            .addClass('error')
                                                            .text('お申し込みは完了しましたが、記録サーバーとの通信に失敗しました。');
                                                })
                                                .always(function () {
                                                    submitButton.prop('disabled', false);
                                                    if (spinner.length > 0) spinner.hide();
                                                });
                                        } else {
                                            // 支払い未完了
                                            if (resultMessage.length > 0)
                                                resultMessage
                                                    .removeClass('success error')
                                                    .addClass('info')
                                                    .text(
                                                        'お支払いが完了しませんでした。ステータス: ' +
                                                            (result.paymentIntent ? result.paymentIntent.status : '不明')
                                                    );
                                            submitButton.prop('disabled', false);
                                            if (spinner.length > 0) spinner.hide();
                                        }
                                    } // End if/else result.error
                                }); // End confirmCardPayment.then
                        } else if (response.data.status === 'active' || response.data.status === 'trialing') {
                            // トライアルなどで即時有効化された場合
                            var subscriptionId = response.data.subscription_id;
                            if (resultMessage.length > 0)
                                resultMessage
                                    .removeClass('success error')
                                    .addClass('info')
                                    .text('お申し込みを受け付けました。情報を記録しています...');
                            // Step 5 (alt): 記録用AJAX呼び出し (支払いPIなし)
                            $.post(edelStripeParams.ajax_url, {
                                action: 'edel_stripe_record_payment',
                                security: edelStripeParams.record_nonce,
                                payment_intent_id: 'N/A (Immediate ' + response.data.status + ')',
                                subscription_id: subscriptionId, // ★ Pass subscription ID
                                plan_id: planId,
                                email: userEmail,
                                customer_id: customerId || '',
                                amount: 0, // ★ Amount is 0 for trial/immediate active
                                item_name: 'Subscription (' + planId + ') - ' + response.data.status
                            })
                                .done(function (recordResponse) {
                                    /* ...記録結果表示...*/ if (resultMessage.length > 0) {
                                        if (recordResponse.success) {
                                            resultMessage
                                                .removeClass('error info')
                                                .addClass('success')
                                                .text(edelStripeParams.success_message || 'お申し込みを受け付け、記録されました。');
                                            cardElementInstance.clear();
                                        } else {
                                            resultMessage
                                                .removeClass('success info')
                                                .addClass('error')
                                                .text(recordResponse.data.message || 'お申し込みは受け付けましたが、記録中にエラーが発生しました。');
                                            console.error('RecErr:', recordResponse.data);
                                        }
                                    }
                                })
                                .fail(function () {
                                    /* ...記録通信失敗...*/ if (resultMessage.length > 0)
                                        resultMessage
                                            .removeClass('success info')
                                            .addClass('error')
                                            .text('お申し込みは受け付けましたが、記録サーバーとの通信に失敗しました。');
                                })
                                .always(function () {
                                    submitButton.prop('disabled', false);
                                    if (spinner.length > 0) spinner.hide();
                                });
                        } else {
                            // 予期せぬ成功応答
                            if (resultMessage.length > 0)
                                resultMessage
                                    .removeClass('success info')
                                    .addClass('error')
                                    .text('予期せぬ応答がありました。(' + response.data.status + ')');
                            submitButton.prop('disabled', false);
                            if (spinner.length > 0) spinner.hide();
                        }
                    } else {
                        // バックエンドでサブスク作成自体に失敗した場合
                        if (resultMessage.length > 0)
                            resultMessage
                                .removeClass('success info')
                                .addClass('error')
                                .text(response.data.message || 'お申し込み処理を開始できませんでした。');
                        submitButton.prop('disabled', false);
                        if (spinner.length > 0) spinner.hide();
                    }
                }) // End initial AJAX .done
                .fail(function () {
                    // 最初のAJAX通信失敗
                    if (resultMessage.length > 0)
                        resultMessage.removeClass('success info').addClass('error').text('申込サーバーとの通信に失敗しました。');
                    submitButton.prop('disabled', false);
                    if (spinner.length > 0) spinner.hide();
                }); // End initial AJAX call
        }); // End formSub.on('submit')
    } // End initializeSubscriptionFormHandler

    // Use event delegation in case shortcode is loaded dynamically
    // Target the form directly by its ID
    $(document).on('submit', '#edel-stripe-user-cancel-form', function (e) {
        e.preventDefault(); // Prevent default form submission

        var $form = $(this);
        var $button = $form.find('.edel-stripe-user-cancel-button');
        var $spinner = $form.find('.spinner'); // Get spinner added by PHP
        // Find result area relative to the form, assuming it's a sibling
        var $resultArea = $form.siblings('.cancel-result');

        // Get data from hidden fields
        var subId = $form.find('input[name="subscription_id"]').val();
        var nonce = $form.find('input[name="security"]').val();
        var action = $form.find('input[name="action"]').val(); // Should be 'edel_stripe_user_cancel_subscription'

        if (!subId || !nonce || !action) {
            alert('キャンセル処理に必要な情報が不足しています。');
            return;
        }

        // Confirmation dialog
        if (!confirm('本当にこのサブスクリプションをキャンセルしますか？\n（通常、現在の請求期間の終了時にキャンセルされます）')) {
            return; // Stop if user cancels
        }

        // Disable button and show spinner
        $button.prop('disabled', true).text('キャンセル処理中...');
        // Ensure spinner element exists and show it
        if ($spinner.length === 0) {
            // Add spinner if it wasn't included in PHP (fallback)
            $spinner = $('<span class="spinner is-active" style="vertical-align: middle; margin-left: 5px;"></span>');
            $button.after($spinner);
        }
        $spinner.addClass('is-active').show(); // Ensure it's visible
        if ($resultArea.length > 0) $resultArea.removeClass('success error').text('');

        $.post(edelStripeParams.ajax_url, {
            action: action, // アクション名
            security: nonce, // Nonce値
            subscription_id: subId // サブスクID
        })
            .done(function (response) {
                // AJAX成功
                if (response.success) {
                    // PHP側で成功応答
                    if ($resultArea.length > 0) {
                        $resultArea.addClass('success').text(response.data.message || 'キャンセル要求を受け付けました。');
                    } else {
                        alert(response.data.message || 'キャンセル要求を受け付けました。');
                    }
                    // ボタンとスピナーを削除
                    $button.remove();
                    $spinner.remove();
                } else {
                    // PHP側でエラー応答
                    if ($resultArea.length > 0) {
                        $resultArea.addClass('error').text(response.data.message || 'キャンセル処理中にエラーが発生しました。');
                    } else {
                        alert('キャンセルエラー: ' + (response.data.message || '不明なエラー'));
                    }
                    $button.prop('disabled', false).text('サブスクリプションをキャンセル'); // ボタンを元に戻す
                }
            })
            .fail(function () {
                // AJAX通信失敗
                if ($resultArea.length > 0) {
                    $resultArea.addClass('error').text('サーバーとの通信に失敗しました。');
                } else {
                    alert('サーバーとの通信に失敗しました。');
                }
                $button.prop('disabled', false).text('サブスクリプションをキャンセル');
            })
            .always(function () {
                // スピナー削除 (念のため)
                $spinner.remove();
                // ボタンの状態は done/fail で制御済み
            });
    }); // End user cancel form submit handler
}); // End document ready
