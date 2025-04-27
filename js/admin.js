// js/admin.js
jQuery(document).ready(function ($) {
    // --- Settings Page Tabs ---
    var $settingsWrapper = $('.edel-stripe-settings'); // Settings page container

    if ($settingsWrapper.length > 0) {
        var $navTabs = $settingsWrapper.find('.nav-tab-wrapper a.nav-tab');
        var $tabContents = $settingsWrapper.find('.tab-content');

        // Function to activate a tab
        function activateTab(target) {
            // Deactivate all tabs and content
            $navTabs.removeClass('nav-tab-active');
            $tabContents.removeClass('active-tab').hide(); // Hide inactive tabs

            // Activate the clicked tab and corresponding content
            $navTabs.filter('[href="' + target + '"]').addClass('nav-tab-active');
            $(target).addClass('active-tab').show(); // Show active tab

            // Optional: Store the active tab's hash in localStorage
            if (window.localStorage) {
                localStorage.setItem('edelStripeActiveTab', target);
            }
        }

        // Handle tab clicks
        $navTabs.on('click', function (e) {
            e.preventDefault();
            var target = $(this).attr('href');
            activateTab(target);
        });

        // Activate tab on page load (check localStorage first, then hash, then default to first)
        var activeTab = '#tab-common'; // Default tab
        if (window.localStorage) {
            var storedTab = localStorage.getItem('edelStripeActiveTab');
            if (storedTab && $navTabs.filter('[href="' + storedTab + '"]').length) {
                activeTab = storedTab;
            }
        }
        // Check URL hash as well (overrides localStorage if present)
        if (window.location.hash && $navTabs.filter('[href="' + window.location.hash + '"]').length) {
            activeTab = window.location.hash;
        }

        // Ensure the nav tab link also gets the active class
        $navTabs.filter('[href="' + activeTab + '"]').addClass('nav-tab-active');
        // Activate the content div
        $(activeTab).addClass('active-tab').show();
    } // end if settings wrapper exists

    // --- Copy to Clipboard for Price IDs ---
    // Uses the Clipboard API (requires HTTPS or localhost)
    $('.copy-clipboard').on('click', function (e) {
        e.preventDefault();
        var textToCopy = $(this).data('clipboard-text');
        var $button = $(this); // Store button element

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(textToCopy).then(
                () => {
                    // Success feedback
                    const originalText = $button.text();
                    $button.text('コピー完了!');
                    setTimeout(() => {
                        $button.text(originalText);
                    }, 1500);
                },
                (err) => {
                    // Error feedback (e.g., browser doesn't support it well)
                    console.error('Clipboard API copy failed: ', err);
                    alert('クリップボードへのコピーに失敗しました。');
                }
            );
        } else {
            // Fallback for older browsers or insecure contexts (less reliable)
            try {
                var tempInput = document.createElement('input');
                tempInput.style = 'position: absolute; left: -1000px; top: -1000px';
                tempInput.value = textToCopy;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);

                // Success feedback (fallback)
                const originalText = $button.text();
                $button.text('コピー完了!');
                setTimeout(() => {
                    $button.text(originalText);
                }, 1500);
            } catch (err) {
                console.error('Fallback copy failed: ', err);
                alert('クリップボードへのコピーに失敗しました。');
            }
        }
    });

    $('#wpbody').on('click', '.edel-stripe-cancel-sub', function (e) {
        e.preventDefault();
        var $button = $(this);
        var subId = $button.data('subid');
        var nonce = $button.data('nonce');
        var userId = $button.data('userid'); // For potential future use

        // 確認ダイアログ
        if (
            !confirm(
                subId + ' のサブスクリプションを期間終了時にキャンセルしますか？\n（即時キャンセルではありません。Stripe側での処理となります。）'
            )
        ) {
            return;
        }

        // ボタンを無効化 & スピナー表示（スピナー要素があれば）
        $button.prop('disabled', true).after(' <span class="spinner is-active" style="vertical-align: middle;"></span>');

        // AJAXリクエスト実行
        $.post(ajaxurl, {
            // ajaxurl はWordPressがadminで定義するグローバル変数
            action: 'edel_stripe_cancel_subscription', // PHP側で登録したアクション
            nonce: nonce,
            sub_id: subId
            // user_id: userId // 必要なら送信
        })
            .done(function (response) {
                if (response.success) {
                    // 成功メッセージ表示 (簡潔に)
                    $button.closest('td').html('<span style="color:green;">キャンセル要求済</span>');
                    // alert(response.data.message);
                } else {
                    // 失敗メッセージ表示
                    alert('エラー: ' + response.data.message);
                    $button.prop('disabled', false); // 再試行可能にする
                }
            })
            .fail(function () {
                alert('サーバーとの通信に失敗しました。');
                $button.prop('disabled', false);
            })
            .always(function () {
                // スピナー削除
                $button.siblings('.spinner').remove();
            });
    });
    // --- ★ Subscription Cancel Button Handler End ---

    $('#wpbody').on('click', '.edel-stripe-sync-sub', function (e) {
        e.preventDefault();
        var $button = $(this);
        var subId = $button.data('subid');
        var nonce = $button.data('nonce');
        var userId = $button.data('userid');
        var $row = $button.closest('tr');
        var $statusCell = $row.find('td.column-status');
        // ★ スピナー要素を作成時に変数に格納
        var $spinner = $('<span class="spinner is-active" style="vertical-align: middle; margin-left: 5px;"></span>');

        if (!subId || !nonce || !userId) {
            alert('同期に必要な情報がボタンから取得できませんでした。');
            return;
        }

        // Disable button, remove old spinner, add new one
        $button.prop('disabled', true);
        $button.siblings('.spinner').remove(); // Clear previous just in case
        $button.after($spinner); // Add the spinner
        if ($statusCell.length > 0) $statusCell.css('opacity', 0.5);

        // AJAXリクエスト実行
        $.post(ajaxurl, {
            action: 'edel_stripe_sync_subscription',
            nonce: nonce,
            sub_id: subId,
            user_id: userId
        })
            .done(function (response) {
                if (response.success) {
                    $button.text('同期完了!');
                    if ($statusCell.length > 0 && typeof response.data.new_status !== 'undefined') {
                        // ステータス表示を動的に更新
                        var newStatus = response.data.new_status;
                        var statusHtml =
                            '<span style="color: #777;">' + escapeHtml(newStatus.charAt(0).toUpperCase() + newStatus.slice(1)) + '</span>';
                        if (['active', 'trialing'].includes(newStatus)) {
                            statusHtml =
                                '<span style="color: green;">' + escapeHtml(newStatus.charAt(0).toUpperCase() + newStatus.slice(1)) + '</span>';
                        } else if (['canceled', 'unpaid', 'incomplete_expired'].includes(newStatus)) {
                            statusHtml =
                                '<span style="color: red;">' + escapeHtml(newStatus.charAt(0).toUpperCase() + newStatus.slice(1)) + '</span>';
                        } else if (['past_due', 'payment_failed', 'incomplete'].includes(newStatus)) {
                            statusHtml =
                                '<span style="color: orange;">' + escapeHtml(newStatus.charAt(0).toUpperCase() + newStatus.slice(1)) + '</span>';
                        }
                        $statusCell.html(statusHtml).css('opacity', 1);
                    } else {
                        if ($statusCell.length > 0) $statusCell.css('opacity', 1);
                    }
                    // ボタンテキストを元に戻す
                    setTimeout(function () {
                        if ($button.prop('disabled')) {
                            /* Avoid resetting if user clicked again quickly? */
                        }
                        $button.text('ステータス同期');
                        $button.prop('disabled', false);
                    }, 2000);
                } else {
                    alert('同期エラー: ' + response.data.message);
                    $button.prop('disabled', false);
                    $button.text('同期失敗');
                    setTimeout(function () {
                        $button.text('ステータス同期');
                    }, 3000);
                    if ($statusCell.length > 0) $statusCell.css('opacity', 1);
                }
            })
            .fail(function () {
                alert('サーバーとの通信に失敗しました。');
                $button.prop('disabled', false);
                $button.text('通信失敗');
                setTimeout(function () {
                    $button.text('ステータス同期');
                }, 3000);
                if ($statusCell.length > 0) $statusCell.css('opacity', 1);
            })
            .always(function () {
                // ★★★ 修正：作成したスピナーオブジェクトを直接削除 ★★★
                $spinner.remove();
                // ★★★ 修正ここまで ★★★

                // ボタンの有効化は done/fail 内の setTimeout で行うか、ここで強制的に行うか
                $button.prop('disabled', false); // Ensure button is enabled
            });
    });
    // --- Subscription Sync Status Button Handler End ---

    $('#wpbody').on('click', '.edel-stripe-refund', function (e) {
        e.preventDefault();
        var $button = $(this);
        var piId = $button.data('piid');
        var nonce = $button.data('nonce');
        var amount = parseInt($button.data('amount'), 10); // Get amount (smallest unit)
        var currency = ($button.data('currency') || 'jpy').toLowerCase(); // Get currency

        if (!piId || !nonce || isNaN(amount)) {
            alert('返金処理に必要な情報がボタンから取得できませんでした。');
            return;
        }

        // Format amount for confirmation message
        var formattedAmount = '';
        if (currency === 'jpy') {
            formattedAmount = amount.toLocaleString() + '円';
        } else if (currency === 'usd') {
            formattedAmount = '$' + (amount / 100).toFixed(2);
        } else {
            formattedAmount = amount.toLocaleString() + ' ' + currency.toUpperCase();
        }

        // 確認ダイアログ
        if (!confirm(formattedAmount + ' の支払い (ID: ' + piId + ') を全額返金しますか？\nこの操作は取り消せません。')) {
            return; // Cancel if user clicks 'Cancel'
        }

        // Disable button and show spinner
        $button.prop('disabled', true).text('返金処理中...');
        $button.after(' <span class="spinner is-active" style="vertical-align: middle;"></span>');
        var $actionsCell = $button.closest('td'); // Store cell for potential update

        // AJAXリクエスト実行
        $.post(ajaxurl, {
            action: 'edel_stripe_refund_payment', // PHP側で登録したアクション
            nonce: nonce, // Nonce for verification
            pi_id: piId // Payment Intent ID to refund
            // amount: amount_to_refund // 部分返金の場合はここに追加
        })
            .done(function (response) {
                if (response.success) {
                    // 成功メッセージ表示 (ボタンを置き換えるなど)
                    $actionsCell.html('<span style="color:green;">返金要求済</span>'); // Replace button with text
                    // alert(response.data.message); // Or use alert
                    // Note: Actual status update in table happens via webhook 'charge.refunded'
                } else {
                    // 失敗メッセージ表示
                    alert('返金エラー: ' + response.data.message);
                    $button.prop('disabled', false); // Enable button again
                    $button.text('返金'); // Restore original text
                }
            })
            .fail(function () {
                // AJAX通信自体が失敗
                alert('サーバーとの通信に失敗しました。');
                $button.prop('disabled', false);
                $button.text('返金');
            })
            .always(function () {
                // スピナー削除
                $button.siblings('.spinner').remove(); // This selector might fail if button was replaced
                $actionsCell.find('.spinner').remove(); // More robust: find spinner within the cell
            });
    });
    // --- ★ Payment Refund Button Handler End ★ ---

    // Helper function to escape HTML (simple version)
    function escapeHtml(text) {
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        if (text && typeof text === 'string') {
            return text.replace(/[&<>"']/g, function (m) {
                return map[m];
            });
        }
        return text; // Return original if not string
    }
}); // End document ready
