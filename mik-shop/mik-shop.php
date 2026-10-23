<?php
/**
 * Plugin Name: MIK Shop
 * Description: Магазин MIK: собственные кабинеты, корзина, избранное, заказы и пакетный импорт; товары WooCommerce.
 * Version: 1.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: SYSTEM404
 * License: GPL-2.0-or-later
 * Text Domain: mik-shop
 */
defined('ABSPATH') || exit;
define('MIK_VERSION', '1.2.0');
define('MIK_PATH', plugin_dir_path(__FILE__));
define('MIK_URL', plugin_dir_url(__FILE__));
foreach (['settings','layout','db','auth','catalog','commerce','documents','importer','remote-import','api','shortcodes','admin'] as $mik_file) {
    require_once MIK_PATH . 'includes/class-' . $mik_file . '.php';
}
register_activation_hook(__FILE__, ['MIK\\DB', 'install']);
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('mik_cleanup'); });
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () { echo '<div class="notice notice-error"><p>MIK Shop: установите и активируйте WooCommerce.</p></div>'; });
        return;
    }
    if (get_option('mik_schema_version') !== MIK_VERSION) { \MIK\DB::install(); }
    \MIK\Settings::upgrade();
    \MIK\Catalog::hooks();
    \MIK\API::hooks();
    \MIK\Shortcodes::hooks();
    if (is_admin()) { \MIK\Admin::hooks(); }
});
add_action('mik_cleanup', ['MIK\\Auth', 'cleanup']);

