<?php
/**
 * listmonk API communication wrapper.
 *
 * @package ShieldedSignupsForListmonk
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSFLM_Listmonk_API
{
    /**
     * API base URL.
     *
     * @var string
     */
    private $api_url;

    /**
     * API username.
     *
     * @var string
     */
    private $username;

    /**
     * API password.
     *
     * @var string
     */
    private $password;

    /**
     * API key/token.
     *
     * @var string
     */
    private $api_key;

    /**
     * Authentication mode.
     *
     * @var string
     */
    private $auth_mode;

    /**
     * Constructor.
     *
     * @param array $settings Plugin settings.
     */
    public function __construct($settings)
    {
        $this->api_url = isset($settings['listmonk_api_url']) ? untrailingslashit($settings['listmonk_api_url']) : '';
        $this->auth_mode = isset($settings['listmonk_auth_mode']) && 'basic' === $settings['listmonk_auth_mode'] ? 'basic' : 'api';
        $this->username = $settings['listmonk_username'] ?? '';
        $this->password = $settings['listmonk_password'] ?? '';
        $this->api_key = $settings['listmonk_api_key'] ?? '';
    }

    /**
     * Verify listmonk connectivity and credentials.
     *
     * @return true|WP_Error
     */
    public function verify_connection()
    {
        if (empty($this->api_url)) {
            return new WP_Error('configuration_error', __('listmonk API URL is not configured.', 'shieldedsignups-for-listmonk'));
        }

        $response = wp_remote_get(
            $this->api_url . '/api/lists?per_page=1',
            array(
                'timeout' => 15,
                'headers' => $this->get_auth_headers(),
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('connection_failed', __('Connection to listmonk failed.', 'shieldedsignups-for-listmonk'));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return new WP_Error('auth_failed', __('listmonk rejected the credentials or API URL.', 'shieldedsignups-for-listmonk'));
        }

        return true;
    }

    /**
     * Subscribe a user to listmonk.
     *
     * @param string $email      Subscriber email.
     * @param string $name       Subscriber name.
     * @param array  $attributes Subscriber attributes.
     * @param int    $list_id    Target list ID.
     * @return array|WP_Error
     */
    public function subscribe($email, $name, $attributes, $list_id)
    {
        if (empty($this->api_url) || empty($list_id)) {
            return new WP_Error('configuration_error', __('listmonk is not fully configured.', 'shieldedsignups-for-listmonk'));
        }

        $endpoint = $this->api_url . '/api/subscribers';
        $payload = array(
            'email' => $email,
            'name' => $name,
            'status' => 'enabled',
            'lists' => array((int) $list_id),
        );

        if (is_array($attributes) && !empty($attributes)) {
            $payload['attribs'] = $attributes;
        }

        $headers = array_merge(
            array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            $this->get_auth_headers()
        );


        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => 15,
                'headers' => $headers,
                'body' => wp_json_encode($payload),
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('connection_failed', __('Connection to listmonk failed.', 'shieldedsignups-for-listmonk'));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (200 === $code || 201 === $code) {
            return array(
                'success' => true,
                'data' => is_array($data) ? $data : array(),
            );
        }

        $message = '';
        if (is_array($data)) {
            if (!empty($data['message']) && is_string($data['message'])) {
                $message = $data['message'];
            } elseif (!empty($data['error']) && is_string($data['error'])) {
                $message = $data['error'];
            }
        }

        if (409 === $code || false !== stripos($message, 'exists')) {
            return new WP_Error('email_exists', __('Email already exists.', 'shieldedsignups-for-listmonk'));
        }

        return new WP_Error(
            'listmonk_error',
            !empty($message)
            ? $message
            : __('listmonk rejected the subscription request.', 'shieldedsignups-for-listmonk')
        );
    }

    /**
     * Build auth headers based on configured auth mode.
     *
     * @return array
     */
    private function get_auth_headers()
    {
        if ('basic' === $this->auth_mode && !empty($this->username) && !empty($this->password)) {
            return array(
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            );
        }

        if (!empty($this->username) && !empty($this->api_key)) {
            return array(
                'Authorization' => 'token ' . trim($this->username) . ':' . trim($this->api_key),
            );
        }

        return array();
    }
}
