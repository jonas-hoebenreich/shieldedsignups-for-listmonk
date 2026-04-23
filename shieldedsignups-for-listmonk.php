<?php
/**
 * Plugin Name: ShieldedSignups for Listmonk
 * Plugin URI: https://shieldedsignups.jonh.eu/
 * Description: Turnstile-protected inline and popup newsletter forms that subscribe users to listmonk.
 * Version: 1.0.0
 * Author: ShieldedSignups
 * License: GPL-2.0-or-later
 * Text Domain: shieldedsignups-for-listmonk
 *
 * @package ShieldedSignupsForListmonk
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SSFLM_PLUGIN_FILE')) {
    define('SSFLM_PLUGIN_FILE', __FILE__);
}

if (!defined('SSFLM_PLUGIN_URL')) {
    define('SSFLM_PLUGIN_URL', plugin_dir_url(__FILE__));
}

require_once plugin_dir_path(__FILE__) . 'includes/class-listmonk-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-frontend.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin.php';

new SSFLM_Admin();
new SSFLM_Frontend();
