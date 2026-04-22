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
     * Constructor.
     */
    public function __construct()
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_shortcode('listmonk_form', array($this, 'render_shortcode'));
        add_action('wp_ajax_ssflm_subscribe', array($this, 'handle_subscribe_request'));
        add_action('wp_ajax_nopriv_ssflm_subscribe', array($this, 'handle_subscribe_request'));
    }

    /**
     * Enqueue frontend assets.
     */
    public function enqueue_assets()
    {
        $settings = get_option(self::OPTION_KEY, array());

        wp_register_style('ssflm-styles', false, array(), '1.0.0');
        wp_enqueue_style('ssflm-styles');
        wp_add_inline_style('ssflm-styles', $this->get_inline_styles());

        wp_enqueue_script(
            'ssflm-form-handler',
            SSFLM_PLUGIN_URL . 'assets/js/form-handler.js',
            array(),
            '1.0.0',
            true
        );

        wp_enqueue_script(
            'ssflm-turnstile',
            'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
            array(),
            null,
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
    }

    /**
     * Shortcode renderer.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render_shortcode($atts)
    {
        $atts = shortcode_atts(
            array(
                'type' => 'inline',
                'attributes' => '{}',
                'headline' => 'Join Our Newsletter',
                'description' => 'No spam. Just meaningful updates.',
                'buttontext' => 'Join Newsletter',
                'thankyou_title' => 'Thank You!',
                'thankyou_message' => 'Please click the confirmation link in the email we just sent.',
                'variables' => '',
            ),
            $atts,
            'listmonk_form'
        );

        $type = in_array($atts['type'], array('inline', 'popup'), true) ? $atts['type'] : 'inline';
        $attributes_data = json_decode($atts['attributes'], true);
        if (!is_array($attributes_data)) {
            $attributes_data = array();
        }
        $attributes = wp_json_encode($attributes_data);
        $headline = sanitize_text_field($atts['headline']);
        $description = sanitize_text_field($atts['description']);
        $buttontext = sanitize_text_field($atts['buttontext']);
        $thankyou_title = sanitize_text_field($atts['thankyou_title']);
        $thankyou_message = sanitize_text_field($atts['thankyou_message']);
        $variables = sanitize_text_field($atts['variables']);

        ob_start();
        if ('popup' === $type):
            ?>
            <div class="ssflm-popup-container" aria-hidden="true">
                <div class="ssflm-popup-backdrop"></div>
                <div class="ssflm-panel ssflm-panel-popup" role="dialog" aria-modal="true" aria-label="Newsletter signup">
                    <button class="ssflm-popup-close" type="button" aria-label="Close popup">&times;</button>
                    <?php $this->render_form_markup($type, $attributes, $headline, $description, $buttontext, $thankyou_title, $thankyou_message, $variables); ?>
                </div>
            </div>
            <?php
        else:
            ?>
            <div class="ssflm-panel ssflm-panel-inline">
                <?php $this->render_form_markup($type, $attributes, $headline, $description, $buttontext, $thankyou_title, $thankyou_message, $variables); ?>
            </div>
            <?php
        endif;

        return ob_get_clean();
    }

    /**
     * Shared form markup.
     */
    private function render_form_markup($type, $attributes, $headline, $description, $buttontext, $thankyou_title, $thankyou_message, $variables)
    {
        $name_id = uniqid('ssflm_name_', false);
        $email_id = uniqid('ssflm_email_', false);
        $style = '';
        if (!empty($variables)) {
            $style = 'style="' . esc_attr($variables) . '"';
        }
        ?>
        <form class="ssflm-form" method="post" data-form-type="<?php echo esc_attr($type); ?>" <?php echo $style; ?>>
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

            <input type="hidden" name="attributes" value="<?php echo esc_attr($attributes); ?>" />
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
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
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

        $listmonk = new SSFLM_Listmonk_API($settings);
        $result = $listmonk->subscribe(
            $email,
            $name,
            $attributes,
            isset($settings['list_id']) ? absint($settings['list_id']) : 0
        );

        if (is_wp_error($result)) {
            $code = $result->get_error_code();
            $message = $result->get_error_message();
            $status = 500;

            if ('email_exists' === $code) {
                $status = 409;
                $message = __('Email already exists.', 'shieldedsignups-for-listmonk');
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
				--ssflm-surface: #99afe333;
				--ssflm-border: #0f1728fd;
				--ssflm-input-background: #f1f1f1fd;
				--ssflm-input-color: var(--ssflm-text);
                --ssflm-danger: #710505;
			}
			.ssflm-panel {
				width: min(100%, 480px);
				padding: 1.5rem;
				border-radius: 16px;
				border: 1px solid var(--ssflm-border);
				background: var(--ssflm-surface);
				backdrop-filter: blur(10px);
				-webkit-backdrop-filter: blur(10px);
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
				color: rgba(255, 255, 255, 0.5);
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
				position: absolute;
				inset: 0;
				background: rgba(2, 6, 23, 0.6);
				backdrop-filter: blur(3px);
			}
			.ssflm-panel-popup {
				position: relative;
				z-index: 2;
			}
			.ssflm-popup-close {
				position: absolute;
				right: 0.5rem;
				top: 0.5rem;
				font-size: 1.5rem;
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
