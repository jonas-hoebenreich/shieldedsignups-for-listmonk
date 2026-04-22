<?php
/**
 * Admin settings page and AJAX verification handlers.
 *
 * @package ShieldedSignupsForListmonk
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSFLM_Admin
{
    const OPTION_KEY = 'ssflm_settings';
    const NONCE_ACTION = 'ssflm_admin_nonce_action';
    const PAGE_SLUG = 'shieldedsignups-for-listmonk';

    /**
     * Constructor.
     */
    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_ssflm_verify_listmonk', array($this, 'verify_listmonk'));
    }

    /**
     * Register plugin settings.
     */
    public function register_settings()
    {
        register_setting(
            'ssflm_settings_group',
            self::OPTION_KEY,
            array($this, 'sanitize_settings')
        );
    }

    /**
     * Sanitize settings fields.
     *
     * @param array $input Raw option data.
     * @return array
     */
    public function sanitize_settings($input)
    {
        $sanitized = array();

        $sanitized['turnstile_site_key'] = isset($input['turnstile_site_key']) ? sanitize_text_field($input['turnstile_site_key']) : '';
        $sanitized['turnstile_secret_key'] = isset($input['turnstile_secret_key']) ? sanitize_text_field($input['turnstile_secret_key']) : '';
        $sanitized['listmonk_api_url'] = isset($input['listmonk_api_url']) ? esc_url_raw($input['listmonk_api_url']) : '';
        $sanitized['listmonk_auth_mode'] = isset($input['listmonk_auth_mode']) && 'basic' === $input['listmonk_auth_mode'] ? 'basic' : 'api';
        $sanitized['listmonk_api_key'] = isset($input['listmonk_api_key']) ? sanitize_text_field($input['listmonk_api_key']) : '';
        $sanitized['listmonk_username'] = isset($input['listmonk_username']) ? sanitize_text_field($input['listmonk_username']) : '';
        $sanitized['listmonk_password'] = isset($input['listmonk_password']) ? sanitize_text_field($input['listmonk_password']) : '';
        $sanitized['list_id'] = isset($input['list_id']) ? absint($input['list_id']) : 0;

        if ('basic' === $sanitized['listmonk_auth_mode']) {
            $sanitized['listmonk_api_key'] = '';
        } else {
            $sanitized['listmonk_password'] = '';
        }

        return $sanitized;
    }

    /**
     * Register the settings page.
     */
    public function register_admin_page()
    {
        add_options_page(
            __('ShieldedSignups for Listmonk', 'shieldedsignups-for-listmonk'),
            __('ShieldedSignups', 'shieldedsignups-for-listmonk'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_admin_page')
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook_suffix Current admin hook.
     */
    public function enqueue_assets($hook_suffix)
    {
        if ('settings_page_' . self::PAGE_SLUG !== $hook_suffix) {
            return;
        }

        wp_enqueue_script(
            'ssflm-admin',
            SSFLM_PLUGIN_URL . 'assets/js/admin.js',
            array(),
            '1.0.0',
            true
        );

        wp_localize_script(
            'ssflm-admin',
            'SSFLM_ADMIN_CONFIG',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(self::NONCE_ACTION),
            )
        );
    }

    /**
     * Render settings page.
     */
    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option(self::OPTION_KEY, array());
        $auth_mode = isset($settings['listmonk_auth_mode']) ? $settings['listmonk_auth_mode'] : 'api';
        ?>
        <div class="wrap ssflm-admin-wrap">
            <h1><?php esc_html_e('ShieldedSignups for Listmonk', 'shieldedsignups-for-listmonk'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('ssflm_settings_group'); ?>

                <div class="ssflm-admin-section">
                    <h2><?php esc_html_e('Cloudflare Turnstile', 'shieldedsignups-for-listmonk'); ?></h2>
                    <p>
                        <?php esc_html_e('Create a widget in the Cloudflare dashboard: go to Turnstile, select Add widget, choose a widget name, configure hostname management, pick a widget mode, then save and copy the site key and secret key.', 'shieldedsignups-for-listmonk'); ?>
                    </p>
                    <p>
                        <a href="https://developers.cloudflare.com/turnstile/get-started/widget-management/dashboard/"
                            target="_blank" rel="noreferrer noopener">
                            <?php esc_html_e('Turnstile dashboard documentation', 'shieldedsignups-for-listmonk'); ?>
                        </a>
                    </p>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label
                                    for="turnstile_site_key"><?php esc_html_e('Site Key', 'shieldedsignups-for-listmonk'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="turnstile_site_key"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[turnstile_site_key]" class="regular-text"
                                    value="<?php echo esc_attr(isset($settings['turnstile_site_key']) ? $settings['turnstile_site_key'] : ''); ?>" />
                                <p class="description">
                                    <?php esc_html_e('Use the site key in your frontend widget. The secret key stays server-side only.', 'shieldedsignups-for-listmonk'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label
                                    for="turnstile_secret_key"><?php esc_html_e('Secret Key', 'shieldedsignups-for-listmonk'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="turnstile_secret_key"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[turnstile_secret_key]" class="regular-text"
                                    value="<?php echo esc_attr(isset($settings['turnstile_secret_key']) ? $settings['turnstile_secret_key'] : ''); ?>" />
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="ssflm-admin-section">
                    <h2><?php esc_html_e('listmonk', 'shieldedsignups-for-listmonk'); ?></h2>
                    <p>
                        <?php esc_html_e('Create an API user in listmonk under Users, then use that username with either a Basic Auth password or an API token, depending on the authentication mode selected below.', 'shieldedsignups-for-listmonk'); ?>
                    </p>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label
                                    for="listmonk_api_url"><?php esc_html_e('API URL', 'shieldedsignups-for-listmonk'); ?></label>
                            </th>
                            <td>
                                <input type="url" id="listmonk_api_url"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[listmonk_api_url]" class="regular-text"
                                    placeholder="https://listmonk.example.com"
                                    value="<?php echo esc_attr(isset($settings['listmonk_api_url']) ? $settings['listmonk_api_url'] : ''); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label
                                    for="listmonk_username"><?php esc_html_e('Username', 'shieldedsignups-for-listmonk'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="listmonk_username"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[listmonk_username]" class="regular-text"
                                    required
                                    value="<?php echo esc_attr(isset($settings['listmonk_username']) ? $settings['listmonk_username'] : ''); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Authentication', 'shieldedsignups-for-listmonk'); ?></th>
                            <td>
                                <label class="ssflm-switch">
                                    <input type="checkbox" class="ssflm-auth-toggle" <?php checked('basic' === $auth_mode); ?> />
                                    <span><?php esc_html_e('Use username/password auth instead of API token', 'shieldedsignups-for-listmonk'); ?></span>
                                </label>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[listmonk_auth_mode]"
                                    class="ssflm-auth-mode-field" value="<?php echo esc_attr($auth_mode); ?>" />
                            </td>
                        </tr>
                    </table>

                    <div class="ssflm-auth-panel ssflm-auth-panel-api" <?php echo 'basic' === $auth_mode ? 'style="display:none;"' : ''; ?>>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label
                                        for="listmonk_api_key"><?php esc_html_e('API Token', 'shieldedsignups-for-listmonk'); ?></label>
                                </th>
                                <td>
                                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                        <input type="password" id="listmonk_api_key"
                                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[listmonk_api_key]"
                                            class="regular-text"
                                            value="<?php echo esc_attr(isset($settings['listmonk_api_key']) ? $settings['listmonk_api_key'] : ''); ?>" />
                                        <button type="button" class="button ssflm-verify-listmonk"
                                            data-action="ssflm_verify_listmonk"><?php esc_html_e('Verify listmonk', 'shieldedsignups-for-listmonk'); ?></button>
                                        <span class="ssflm-admin-status" data-status-for="listmonk"></span>
                                    </div>
                                    <p class="description">
                                        <?php esc_html_e('Use the token value for the selected listmonk user.', 'shieldedsignups-for-listmonk'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="ssflm-auth-panel ssflm-auth-panel-basic" <?php echo 'basic' === $auth_mode ? '' : 'style="display:none;"'; ?>>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label
                                        for="listmonk_password"><?php esc_html_e('Password', 'shieldedsignups-for-listmonk'); ?></label>
                                </th>
                                <td>
                                    <input type="password" id="listmonk_password"
                                        name="<?php echo esc_attr(self::OPTION_KEY); ?>[listmonk_password]" class="regular-text"
                                        value="<?php echo esc_attr(isset($settings['listmonk_password']) ? $settings['listmonk_password'] : ''); ?>" />
                                    <button type="button" class="button ssflm-verify-listmonk"
                                        data-action="ssflm_verify_listmonk"><?php esc_html_e('Verify listmonk', 'shieldedsignups-for-listmonk'); ?></button>
                                    <span class="ssflm-admin-status" data-status-for="listmonk"></span>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label
                                    for="list_id"><?php esc_html_e('Default Target List ID', 'shieldedsignups-for-listmonk'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="list_id" name="<?php echo esc_attr(self::OPTION_KEY); ?>[list_id]"
                                    class="small-text" min="1"
                                    value="<?php echo esc_attr(isset($settings['list_id']) ? $settings['list_id'] : ''); ?>" />
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Verify listmonk connection via AJAX.
     */
    public function verify_listmonk()
    {
        $this->assert_ajax_nonce();

        $settings = get_option(self::OPTION_KEY, array());

        if (isset($_POST['listmonk_api_url'])) {
            $settings['listmonk_api_url'] = esc_url_raw(wp_unslash($_POST['listmonk_api_url']));
        }

        if (isset($_POST['listmonk_auth_mode'])) {
            $settings['listmonk_auth_mode'] = 'basic' === wp_unslash($_POST['listmonk_auth_mode']) ? 'basic' : 'api';
        }

        if (isset($_POST['listmonk_username'])) {
            $settings['listmonk_username'] = sanitize_text_field(wp_unslash($_POST['listmonk_username']));
        }

        if (isset($_POST['listmonk_api_key'])) {
            $settings['listmonk_api_key'] = sanitize_text_field(wp_unslash($_POST['listmonk_api_key']));
        }

        if (isset($_POST['listmonk_password'])) {
            $settings['listmonk_password'] = sanitize_text_field(wp_unslash($_POST['listmonk_password']));
        }

        $api = new SSFLM_Listmonk_API($settings);
        $result = $api->verify_connection();

        if (is_wp_error($result)) {
            wp_send_json_error(
                array(
                    'code' => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ),
                500
            );
        }

        wp_send_json_success(
            array(
                'message' => __('listmonk connection verified.', 'shieldedsignups-for-listmonk'),
            )
        );
    }

    /**
     * Ensure AJAX requests are authorized.
     */
    private function assert_ajax_nonce()
    {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(
                array(
                    'code' => 'invalid_request',
                    'message' => __('Invalid request.', 'shieldedsignups-for-listmonk'),
                ),
                403
            );
        }
    }
}
