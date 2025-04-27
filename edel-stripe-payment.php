<?php

/**
 * Plugin Name: Edel Stripe Payment
 * Plugin URI: https://edel-hearts.com/edel-stripe-payment
 * Description: Stripe Elementsを利用し、WordPressに安全なカード決済（買い切り）を導入。ショートコード設置、ユーザー連携、管理機能付き。
 * Version: 1.0.2
 * Author: yabea
 * Author URI: https://edel-hearts.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Tested up to: 6.5
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) exit();

// ★★★ Namespace Import (use文) はファイルのこの位置に記述 ★★★
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;


// --- 定数定義 ---
$plugin_data = get_file_data(__FILE__, array('plugin_name' => 'Plugin Name', 'version' => 'Version'));
define('EDEL_STRIPE_PAYMENT_URL', plugins_url('', __FILE__));
define('EDEL_STRIPE_PAYMENT_PATH', dirname(__FILE__));
define('EDEL_STRIPE_PAYMENT_BASENAME', plugin_basename(__FILE__));
define('EDEL_STRIPE_PAYMENT_NAME', $plugin_data['plugin_name']);
define('EDEL_STRIPE_PAYMENT_SLUG', 'edel-stripe-payment');
define('EDEL_STRIPE_PAYMENT_PREFIX', 'edel_stripe_payment_');
define('EDEL_STRIPE_PAYMENT_VERSION', $plugin_data['version']);
define('EDEL_STRIPE_PAYMENT_DEVELOP', true); // 開発中は true, リリース時は false
define('EDEL_STRIPE_PAYMENT_REQUIRED_PHP', '7.4');


// --- Composer Autoloader ---
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Edel Stripe Payment: Composer autoloaderが見つかりません。<code>composer install</code> を実行してください。プラグインは動作しません。</p></div>';
    });
    return; // Autoloaderがないと Stripe PHP ライブラリが使えないため停止
}


// --- PHPバージョンチェック ---
if (version_compare(PHP_VERSION, EDEL_STRIPE_PAYMENT_REQUIRED_PHP, '<')) {
    add_action('admin_notices', 'edel_stripe_payment_php_version_notice');
    // deactivate_plugins(EDEL_STRIPE_PAYMENT_BASENAME); // 必要なら自動停止
    return; // 低いバージョンの場合はここで停止
}
/** PHPバージョンが低い場合に通知を表示する関数 */
function edel_stripe_payment_php_version_notice() {
?>
    <div class="notice notice-error is-dismissible">
        <p><?php printf(esc_html__('Edel Stripe Payment requires PHP version %s or later. You are running version %s.', 'edel-stripe-payment'), esc_html(EDEL_STRIPE_PAYMENT_REQUIRED_PHP), esc_html(PHP_VERSION)); ?></p>
    </div>
<?php
}


// --- 必要なクラスファイルを読み込む ---
require_once EDEL_STRIPE_PAYMENT_PATH . '/inc/class-admin.php';
require_once EDEL_STRIPE_PAYMENT_PATH . '/inc/class-front.php';
// ※List Tableクラスも admin.php かここで読み込む必要あり
require_once EDEL_STRIPE_PAYMENT_PATH . '/inc/class-payment-history-list-table.php';


// --- 有効化フック（テーブル作成） ---
// コールバックには Admin クラスの static メソッドを指定
register_activation_hook(__FILE__, array('EdelStripePaymentAdmin', 'create_custom_table'));


// --- Plugin Update Checker の初期化関数 ---
/**
 * Initializes the Plugin Update Checker library.
 * Hooked to 'plugins_loaded'.
 */
function edel_stripe_initialize_updater() {
    $puc_file = EDEL_STRIPE_PAYMENT_PATH . '/plugin-update-checker/plugin-update-checker.php';
    if (file_exists($puc_file)) {
        require_once $puc_file; // ライブラリ本体を読み込む
    } else {
        error_log('Edel Stripe Payment: Plugin Update Checker library not found at ' . $puc_file);
        return; // ライブラリがない場合は初期化しない
    }

    try {
        // PucFactory クラスが存在するか確認
        if (!class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
            error_log('Edel Stripe Payment: PucFactory class not found after requiring library.');
            return;
        }

        require_once(EDEL_PLUGIN_TEMPLATE_PATH . '/inc/plugin-update-checker/plugin-update-checker.php');
        $myUpdateChecker = PucFactory::buildUpdateChecker(
            'https://edel-hearts.com/wp-content/uploads/version/edel-stripe-payment.json',
            __FILE__,
            EDEL_STRIPE_PAYMENT_SLUG
        );
    } catch (Exception $e) {
        error_log('Error initializing Plugin Update Checker for Edel Stripe Payment: ' . $e->getMessage());
    }
}
// plugins_loaded フックで初期化関数を呼び出す
add_action('plugins_loaded', 'edel_stripe_initialize_updater');


// --- メインプラグインクラス ---
class EdelStripePayment {
    private $admin_instance;
    private $front_instance;

    // コンストラクタでインスタンスを受け取る（もしくは init で new する）
    public function __construct() {
        // init の外で require 済みなので、ここでは new するだけ
        $this->admin_instance = new EdelStripePaymentAdmin();
        $this->front_instance = new EdelStripePaymentFront();
    }

    /**
     * Initialize hooks.
     */
    public function init() {
        // 管理画面側のフック登録
        add_action('admin_menu', array($this->admin_instance, 'admin_menu'));
        add_filter('plugin_action_links_' . EDEL_STRIPE_PAYMENT_BASENAME, array($this->admin_instance, 'plugin_action_links'));
        add_action('admin_enqueue_scripts', array($this->admin_instance, 'admin_enqueue'));

        $cancel_sub_action = 'edel_stripe_cancel_subscription';
        add_action('wp_ajax_' . $cancel_sub_action, array($this->admin_instance, 'ajax_cancel_subscription'));
        $sync_sub_action = 'edel_stripe_sync_subscription';
        add_action('wp_ajax_' . $sync_sub_action, array($this->admin_instance, 'ajax_sync_subscription'));
        $refund_action = 'edel_stripe_refund_payment';
        add_action('wp_ajax_' . $refund_action, array($this->admin_instance, 'ajax_refund_payment'));
        $refund_action = 'edel_stripe_refund_payment';
        add_action('wp_ajax_' . $refund_action, array($this->admin_instance, 'ajax_refund_payment'));

        // フロントエンド側のフック登録
        add_action('wp_enqueue_scripts', array($this->front_instance, 'front_enqueue'));
        add_shortcode('stripe_onetime', array($this->front_instance, 'render_onetime_shortcode'));
        add_shortcode('edel_stripe_subscription', array($this->front_instance, 'render_subscription_shortcode'));
        add_shortcode('edel_stripe_my_account', array($this->front_instance, 'render_my_account_page'));

        // AJAXハンドラー登録
        $ajax_action_name = 'edel_stripe_process_onetime';
        add_action('wp_ajax_' . $ajax_action_name, array($this->front_instance, 'process_onetime_payment'));
        add_action('wp_ajax_nopriv_' . $ajax_action_name, array($this->front_instance, 'process_onetime_payment'));
        $record_action_name = 'edel_stripe_record_payment';
        add_action('wp_ajax_' . $record_action_name, array($this->front_instance, 'record_successful_payment'));
        add_action('wp_ajax_nopriv_' . $record_action_name, array($this->front_instance, 'record_successful_payment'));

        // ★サブスク： サブスク作成用★
        $sub_action_name = 'edel_stripe_process_subscription'; // JSから送られる action 名
        add_action('wp_ajax_' . $sub_action_name, array($this->front_instance, 'process_subscription'));
        add_action('wp_ajax_nopriv_' . $sub_action_name, array($this->front_instance, 'process_subscription'));

        $user_cancel_action = 'edel_stripe_user_cancel_subscription';
        add_action('wp_ajax_' . $user_cancel_action, array($this->front_instance, 'ajax_user_cancel_subscription')); // ログインユーザー専用

        // REST API エンドポイント（Webhook用）の登録
        add_action('rest_api_init', array($this->front_instance, 'register_webhook_endpoint'));
    }
}

// --- プラグインの初期化 ---
// PHPバージョンチェックを通過した場合のみインスタンス化してinitを実行
if (version_compare(PHP_VERSION, EDEL_STRIPE_PAYMENT_REQUIRED_PHP, '>=')) {
    // メインクラスをインスタンス化
    $edelStripePaymentInstance = new EdelStripePayment();
    $edelStripePaymentInstance->init();
}

?>