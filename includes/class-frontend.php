<?php
/**
 * Frontend shortcode rendering, assets, and AJAX subscription handling.
 *
 * @package ShieldedSignupsForListmonk
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSFLM_Frontend
{
    const OPTION_KEY = 'ssflm_settings';
    const NONCE_ACTION = 'ssflm_nonce_action';

    /**
     * Track whether shared frontend assets have been output for the page.
     *
     * @var bool
     */
    private $assets_output = false;

    /**
     * Track whether scripts have been enqueued for the page.
     *
     * @var bool
     */
    private $assets_enqueued = false;

    /**
     * Constructor.
     */
    public function __construct()
    {
        add_shortcode('listmonk_form', array($this, 'render_shortcode'));
        add_action('wp_ajax_ssflm_subscribe', array($this, 'handle_subscribe_request'));
        add_action('wp_ajax_nopriv_ssflm_subscribe', array($this, 'handle_subscribe_request'));
    }

    /**
     * Enqueue frontend assets only when the shortcode is rendered.
     *
     * @param array $settings Plugin settings.
     */
    private function enqueue_assets($settings)
    {
        if ($this->assets_enqueued) {
            return;
        }

        $script_dependencies = array();

        if (!empty($settings['turnstile_site_key']) && !wp_script_is('ssflm-turnstile', 'registered')) {
            wp_register_script(
                'ssflm-turnstile',
                'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
                array(),
                null,
                true
            );
        }

        if (!empty($settings['turnstile_site_key'])) {
            wp_enqueue_script('ssflm-turnstile');
            $script_dependencies[] = 'ssflm-turnstile';
        }

        wp_enqueue_script(
            'ssflm-form-handler',
            SSFLM_PLUGIN_URL . 'assets/js/form-handler.js',
            $script_dependencies,
            '1.0.0',
            true
        );

        wp_localize_script(
            'ssflm-form-handler',
            'SSFLM_CONFIG',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(self::NONCE_ACTION),
                'siteKey' => isset($settings['turnstile_site_key']) ? $settings['turnstile_site_key'] : '',
            )
        );

        $this->assets_enqueued = true;
    }

    /**
     * Shortcode renderer.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render_shortcode($atts)
    {
        $settings = get_option(self::OPTION_KEY, array());

        $atts = shortcode_atts(
            array(
                'type' => 'inline',
                'list' => '',
                'attributes' => '{}',
                'trackurl' => 'false',
                'headline' => __('Join Our Newsletter', 'shieldedsignups-for-listmonk'),
                'description' => __('No spam. Just meaningful updates.', 'shieldedsignups-for-listmonk'),
                'buttontext' => __('Join Newsletter', 'shieldedsignups-for-listmonk'),
                'legal' => __('By subscribing, you agree to our {privacy policy}. You can opt out at any time.', 'shieldedsignups-for-listmonk'),
                'thankyou_title' => __('Thank You!', 'shieldedsignups-for-listmonk'),
                'thankyou_message' => __('Please click the confirmation link in the email we just sent.', 'shieldedsignups-for-listmonk'),
                'custom_css' => '',
            ),
            $atts,
            'listmonk_form'
        );

        $type = in_array($atts['type'], array('inline', 'popup'), true) ? $atts['type'] : 'inline';
        $attributes_data = json_decode($atts['attributes'], true);
        if (!is_array($attributes_data)) {
            $attributes_data = array();
        }
        $track_url = filter_var($atts['trackurl'], FILTER_VALIDATE_BOOLEAN);
        $attributes = wp_json_encode($this->sanitize_attributes($attributes_data));
        $headline = sanitize_text_field($atts['headline']);
        $description = sanitize_text_field($atts['description']);
        $buttontext = sanitize_text_field($atts['buttontext']);
        $legal = sanitize_text_field($atts['legal']);
        $thankyou_title = sanitize_text_field($atts['thankyou_title']);
        $thankyou_message = sanitize_text_field($atts['thankyou_message']);
        $custom_css = $this->sanitize_custom_css($atts['custom_css']);
        $default_list_id = isset($settings['list_id']) ? absint($settings['list_id']) : 0;
        $requested_list_id = absint($atts['list']);
        $list_id = $requested_list_id > 0 ? $requested_list_id : $default_list_id;
        $form_nonce = wp_create_nonce($this->build_form_nonce_action($list_id));

        $this->enqueue_assets($settings);

        ob_start();
        if (!$this->assets_output) {
            echo '<style id="ssflm-styles">' . $this->get_inline_styles() . '</style>';
            $this->assets_output = true;
        }

        if (!empty($custom_css)) {
            echo '<style id="' . wp_unique_id("ssflm-custom-css") . '">' . $custom_css . '</style>';
        }

        if ('popup' === $type):
            ?>
            <div class="ssflm-popup-container" aria-hidden="true">
                <div class="ssflm-popup-backdrop"></div>
                <div class="ssflm-panel ssflm-panel-popup" role="dialog" aria-modal="true" aria-label="Newsletter signup">
                    <button class="ssflm-popup-close" type="button" aria-label="Close popup">&times;</button>
                    <?php $this->render_form_markup($type, $attributes, $track_url, $headline, $description, $buttontext, $legal, $thankyou_title, $thankyou_message, $list_id, $form_nonce); ?>
                </div>
            </div>
            <?php
        else:
            ?>
            <div class="ssflm-panel ssflm-panel-inline">
                <?php $this->render_form_markup($type, $attributes, $track_url, $headline, $description, $buttontext, $legal, $thankyou_title, $thankyou_message, $list_id, $form_nonce); ?>
            </div>
            <?php
        endif;

        return ob_get_clean();
    }
    
    /**
     * Shared form markup.
     */
    private function render_form_markup($type, $attributes, $track_url, $headline, $description, $buttontext, $legal, $thankyou_title, $thankyou_message, $list_id, $form_nonce)
    {
        $name_id = wp_unique_id('ssflm_name_');
        $email_id = wp_unique_id('ssflm_email_');
        $privacy_policy_url = get_privacy_policy_url();
        $legal_parts = preg_split('/\{([^{}]+)\}/', (string) $legal, -1, PREG_SPLIT_DELIM_CAPTURE);
        $legal_content = '';
        if (is_array($legal_parts)) {
            foreach ($legal_parts as $index => $part) {
                if (0 === $index % 2) {
                    $legal_content .= esc_html($part);
                    continue;
                }

                $link_text = trim($part);
                if ('' === $link_text) {
                    continue;
                }

                if (!empty($privacy_policy_url)) {
                    $legal_content .= '<a href="' . esc_url($privacy_policy_url) . '" rel="nofollow">' . esc_html($link_text) . '</a>';
                } else {
                    $legal_content .= esc_html($link_text);
                }
            }
        }
        ?>
        <form class="ssflm-form" method="post" data-form-type="<?php echo esc_attr($type); ?>"
            data-track-url="<?php echo $track_url ? '1' : '0'; ?>">
            <h3 class="ssflm-title"><?php echo esc_html($headline); ?></h3>
            <p class="ssflm-subtitle"><?php echo esc_html($description); ?></p>

            <div class="ssflm-inputs-row">
                <div class="ssflm-input-group">
                    <label for="<?php echo esc_attr($name_id); ?>"
                        class="ssflm-label"><?php esc_html_e('Name (optional)', 'shieldedsignups-for-listmonk'); ?></label>
                    <input id="<?php echo esc_attr($name_id); ?>" class="ssflm-input" type="text" name="name"
                        autocomplete="name" placeholder="Your name (optional)" />
                </div>

                <div class="ssflm-input-group">
                    <label for="<?php echo esc_attr($email_id); ?>"
                        class="ssflm-label"><?php esc_html_e('Email (required)', 'shieldedsignups-for-listmonk'); ?></label>
                    <input id="<?php echo esc_attr($email_id); ?>" class="ssflm-input" type="email" name="email" required
                        autocomplete="email" placeholder="your@email.com" />
                </div>

                <div class="ssflm-input-group ssflm-submit-group">
                    <button class="ssflm-submit" type="submit"
                        data-default-text="<?php echo esc_attr($buttontext); ?>"><?php echo esc_html($buttontext); ?></button>
                </div>
            </div>

            <small
                class="ssflm-legal"><?php echo wp_kses($legal_content, array('a' => array('href' => array(), 'rel' => array()))); ?></small>


            <input type="hidden" name="attributes" value="<?php echo esc_attr($attributes); ?>" />
            <input type="hidden" name="list_id" value="<?php echo esc_attr((string) absint($list_id)); ?>" />
            <input type="hidden" name="nonce" value="<?php echo esc_attr($form_nonce); ?>" />
            <div class="ssflm-message" role="status" aria-live="polite"></div>
            <div class="ssflm-turnstile"></div>
        </form>
        <div class="ssflm-thank-you" style="display:none;" aria-live="polite">
            <h3><?php echo esc_html($thankyou_title); ?></h3>
            <p><?php echo esc_html($thankyou_message); ?></p>
        </div>
        <?php
    }

    /**
     * AJAX subscribe endpoint.
     */
    public function handle_subscribe_request()
    {
        $requested_list_id = isset($_POST['list_id']) ? absint(wp_unslash($_POST['list_id'])) : 0;

        if (!check_ajax_referer($this->build_form_nonce_action($requested_list_id), 'nonce', false)) {
            wp_send_json_error(
                array(
                    'code' => 'invalid_nonce',
                    'message' => __('Invalid request.', 'shieldedsignups-for-listmonk'),
                ),
                403
            );
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $attributes_json = isset($_POST['attributes']) ? wp_unslash($_POST['attributes']) : '{}';
        $turnstile_token = isset($_POST['turnstile_token']) ? sanitize_text_field(wp_unslash($_POST['turnstile_token'])) : '';

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(
                array(
                    'code' => 'invalid_email',
                    'message' => __('Please enter a valid email address.', 'shieldedsignups-for-listmonk'),
                ),
                400
            );
        }

        $settings = get_option(self::OPTION_KEY, array());
        $target_list_id = $requested_list_id > 0 ? $requested_list_id : (isset($settings['list_id']) ? absint($settings['list_id']) : 0);

        if (empty($settings['turnstile_secret_key'])) {
            wp_send_json_error(
                array(
                    'code' => 'configuration_error',
                    'message' => __('Turnstile is not configured.', 'shieldedsignups-for-listmonk'),
                ),
                500
            );
        }

        $verification = $this->verify_turnstile_token($turnstile_token, $settings['turnstile_secret_key']);
        if (is_wp_error($verification) || empty($verification['success'])) {
            wp_send_json_error(
                array(
                    'code' => 'invalid_turnstile',
                    'message' => __('Invalid Turnstile.', 'shieldedsignups-for-listmonk'),
                ),
                400
            );
        }

        $attributes = json_decode($attributes_json, true);
        if (!is_array($attributes)) {
            $attributes = array();
        }

        $attributes = $this->sanitize_attributes($attributes);

        $listmonk = new SSFLM_Listmonk_API($settings);
        $result = $listmonk->subscribe(
            $email,
            $name,
            $attributes,
            $target_list_id
        );

        if (is_wp_error($result)) {
            $code = $result->get_error_code();
            $message = $result->get_error_message();
            $status = 500;

            if ('email_exists' === $code) {
                wp_send_json_success(
                    array(
                        'message' => __('Thank you for subscribing!', 'shieldedsignups-for-listmonk'),
                    )
                );
            } elseif ('connection_failed' === $code) {
                $status = 502;
                $message = __('Connection to listmonk failed.', 'shieldedsignups-for-listmonk');
            }

            wp_send_json_error(
                array(
                    'code' => $code,
                    'message' => $message,
                ),
                $status
            );
        }

        wp_send_json_success(
            array(
                'message' => __('Thank you for subscribing!', 'shieldedsignups-for-listmonk'),
            )
        );
    }

    /**
     * Sanitize subscriber attributes recursively.
     *
     * @param mixed $attributes Raw attributes payload.
     * @param int   $depth Current recursion depth.
     * @return array
     */
    private function sanitize_attributes($attributes, $depth = 0)
    {
        if (!is_array($attributes) || $depth > 5) {
            return array();
        }

        $sanitized = array();
        foreach ($attributes as $key => $value) {
            if (count($sanitized) >= 50) {
                break;
            }

            $sanitized_key = is_string($key) ? preg_replace('/[^a-zA-Z0-9_.-]/', '', $key) : '';
            if (empty($sanitized_key)) {
                continue;
            }

            $sanitized_key = substr($sanitized_key, 0, 64);

            if (is_array($value)) {
                $sanitized[$sanitized_key] = $this->sanitize_attributes($value, $depth + 1);
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value)) {
                $sanitized[$sanitized_key] = $value;
                continue;
            }

            if (null === $value) {
                $sanitized[$sanitized_key] = '';
                continue;
            }

            $sanitized[$sanitized_key] = sanitize_text_field((string) $value);

            if ('source_url' === $sanitized_key) {
                $url_value = esc_url_raw((string) $value, array('http', 'https'));
                if (!empty($url_value)) {
                    $sanitized[$sanitized_key] = $url_value;
                }
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize custom CSS from shortcode attributes.
     *
     * @param mixed $custom_css Raw custom CSS.
     * @return string
     */
    private function sanitize_custom_css($custom_css)
    {
        if (!is_scalar($custom_css)) {
            return '';
        }

        $custom_css = wp_unslash((string) $custom_css);
        $custom_css = wp_strip_all_tags($custom_css);
        $custom_css = trim($custom_css);

        return $custom_css;
    }

    /**
     * Verify Turnstile token server-side.
     */
    private function verify_turnstile_token($token, $secret_key)
    {
        if (empty($token) || empty($secret_key)) {
            return new WP_Error('invalid_turnstile', __('Invalid Turnstile token.', 'shieldedsignups-for-listmonk'));
        }

        $response = wp_remote_post(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            array(
                'timeout' => 10,
                'body' => array(
                    'secret' => $secret_key,
                    'response' => $token,
                    'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
                ),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return is_array($data) ? $data : array('success' => false);
    }

    /**
     * Build nonce action for a specific list id.
     *
     * @param int $list_id Target list id.
     * @return string
     */
    private function build_form_nonce_action($list_id)
    {
        return self::NONCE_ACTION . '|' . absint($list_id);
    }

    /**
     * Inline CSS.
     *
     * @return string
     */
    private function get_inline_styles()
    {
        return '
			:root {
				--ssflm-primary: #0f766e;
				--ssflm-text: #0a0a0a;
				--ssflm-surface: #e7ebf6;
				--ssflm-border: #0f1728fd;
				--ssflm-input-background: #f1f1f1fd;
				--ssflm-input-color: var(--ssflm-text);
                --ssflm-danger: #710505;
			}
			.ssflm-panel {
				width: 100%;
				padding: 1.5rem;
				border-radius: 16px;
				border: 1px solid var(--ssflm-border);
				background: var(--ssflm-surface);
				color: var(--ssflm-text);
				animation: ssflm-pop 0.4s ease;
			}
			.ssflm-form {
				display: flex;
				flex-direction: column;
				gap: 0.5rem;
			}
			.ssflm-title {
				margin: 0 0 0.25rem 0;
				font-size: 1.4rem;
				line-height: 1.2;
				font-weight: 600;
			}
			.ssflm-subtitle {
				margin: 0 0 0.75rem 0;
				font-size: 0.9rem;
			}
			.ssflm-inputs-row {
				display: flex;
				gap: 0.6rem;
				flex-wrap: wrap;
			}
			.ssflm-input-group {
				display: flex;
				flex-direction: column;
				flex: 1;
				min-width: 120px;
			}
			.ssflm-submit-group {
				min-width: 110px;
				justify-content: flex-end;
			}
			.ssflm-label {
				font-size: 0.8rem;
				font-weight: 600;
				margin-bottom: 0.25rem;
			}
			.ssflm-form input.ssflm-input {
				padding: 0.65rem 0.75rem;
				border: 1.5px solid var(--ssflm-border);
				border-radius: 6px;
				background: var(--ssflm-input-background);
				color: var(--ssflm-input-color);
				font-size: 0.95rem;
			}
			.ssflm-input::placeholder {
				color: var(--ssflm-text);
                opacity: 0.7;
			}
			.ssflm-form input.ssflm-input:focus {
				outline: none;
				background: var(--ssflm-input-background);
				border-color: var(--ssflm-primary);
				box-shadow: 0 0 0 1px var(--ssflm-primary);
			}
			.ssflm-submit {
				padding: 0.65rem 1.2rem;
				border: 1px solid var(--ssflm-primary);
				border-radius: 6px;
				background: var(--ssflm-primary);
				color: #ffffff;
				font-size: 0.95rem;
				font-weight: 600;
				cursor: pointer;
				transition: background-color 0.2s ease, transform 0.15s ease;
				white-space: nowrap;
			}
			.ssflm-submit:hover {
				opacity: 0.9;
				transform: translateY(-1px);
			}
			.ssflm-submit:active {
				transform: translateY(0);
			}
			.ssflm-submit.is-loading {
				opacity: 0.7;
				cursor: not-allowed;
			}
			.ssflm-turnstile {
				margin-top: 0.5rem;
			}
            .ssflm-legal {
                display: block;
                margin-top: 0.4rem;
                font-size: 0.75rem;
                line-height: 1.35;
            }
			.ssflm-message {
				min-height: 1.2em;
				font-size: 0.85rem;
				margin-top: 0.25rem;
			}
			.ssflm-message.is-error {
				color: var(--ssflm-danger);
			}
			.ssflm-message.is-success {
				color: var(--ssflm-primary);
			}
			.ssflm-popup-container {
				position: fixed;
				inset: 0;
				display: none;
				place-items: center;
				z-index: 99999;
				padding: 1rem;
			}
			.ssflm-popup-container.is-active {
				display: grid;
			}
			.ssflm-popup-backdrop {
				position: fixed;
				inset: 0;
				background: rgba(2, 6, 23, 0.8);
				backdrop-filter: blur(10px);
			}
			.ssflm-panel-popup {
				position: relative;
				z-index: 2;
			}
            .ssflm-popup-close {
                position: fixed;
                right: 1rem;
                top: 1rem;
                font-size: 2.5rem;
                line-height: 1;
                border: 0;
                background: transparent;
                color: rgba(255, 255, 255, 0.7);
                cursor: pointer;
                transition: color 0.2s ease;
            }
			.ssflm-popup-close:hover {
				color: #ffffff;
			}
			.ssflm-thank-you {
				text-align: center;
				padding: 1rem 0;
            }
            .ssflm-thank-you.is-visible {
                display: block !important;
                animation: ssflm-celebrate 0.7s ease forwards;
			}
			.ssflm-thank-you h3 {
				margin: 0 0 0.3rem 0;
				font-size: 1.5rem;
			}
			.ssflm-thank-you p {
				margin: 0;
				font-size: 0.95rem;
			}
            .ssflm-form.is-hidden {
                display: none;
            }
			@keyframes ssflm-pop {
				from {
					opacity: 0;
					transform: translateY(8px) scale(0.97);
				}
				to {
					opacity: 1;
					transform: translateY(0) scale(1);
				}
			}
			@keyframes ssflm-celebrate {
				0% {
					opacity: 0;
					transform: scale(0.9);
				}
				70% {
					opacity: 1;
					transform: scale(1.02);
				}
				100% {
					opacity: 1;
					transform: scale(1);
				}
			}
			@media (max-width: 640px) {
				.ssflm-panel {
					padding: 1.2rem;
					border-radius: 12px;
				}
				.ssflm-title {
					font-size: 1.2rem;
				}
				.ssflm-inputs-row {
					flex-direction: column;
				}
				.ssflm-input-group,
				.ssflm-submit-group {
					width: 100%;
				}
				.ssflm-submit-group {
					justify-content: stretch;
				}
				.ssflm-submit {
					width: 100%;
				}
			}
		';
    }
}

new SSFLM_Frontend();
