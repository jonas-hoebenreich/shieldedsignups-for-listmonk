=== ShieldedSignups for Listmonk ===
Contributors: flinnn
Tags: newsletter, listmonk, cloudflare, turnstile, popup, signup
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turnstile-protected newsletter signup forms for listmonk with inline and popup modes.

== Description ==

ShieldedSignups for Listmonk adds secure newsletter signup forms to WordPress and sends subscriptions to your listmonk instance.

Features:

- Cloudflare Turnstile server-side verification.
- listmonk subscriber creation through the `/api/subscribers` endpoint.
- Inline and popup form rendering via shortcode.
- Popup triggers: 5-second delay and exit-intent.
- Glassmorphism UI with animated success state.
- WordPress nonce protection on AJAX requests.
- Graceful error handling for common failure cases.

== Installation ==

1. Upload the `shieldedsignups-for-listmonk` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the WordPress Plugins screen.
3. Go to `Settings -> ShieldedSignups`.
4. Configure:
   - Cloudflare Turnstile Site Key
   - Cloudflare Turnstile Secret Key
   - listmonk API URL
  - listmonk Username
  - listmonk credentials (Password for Basic Auth or API Token)
   - Target List ID

== Usage ==

Add one of the following shortcodes to any post, page, or widget:

- Inline form:
  `[listmonk_form type="inline"]`

- Popup form:
  `[listmonk_form type="popup"]`

Optional attributes payload (JSON string) can be passed with:

`[listmonk_form type="inline" attributes="{\"source\":\"wordpress\"}"]`

Customize the success state text with:

`[listmonk_form type="inline" thankyou_title="You're in!" thankyou_message="Check your inbox and click the confirmation link."]`

== Frequently Asked Questions ==

= Are listmonk credentials exposed in frontend JavaScript? =

No. Credentials are stored in WordPress settings and used only in server-side requests.

= What happens if Turnstile validation fails? =

The request is rejected and the user sees an `Invalid Turnstile` error.

= What happens if the email is already subscribed? =

The plugin returns an `Email already exists` error from the AJAX response.

= Which credential method does listmonk use? =

If API Token is set, it is used as `Authorization: token username:token`. Otherwise, Basic Auth with Username/Password is used.

== Changelog ==

= 1.0.0 =

- Initial release.
- Added admin settings for Turnstile and listmonk.
- Added shortcode rendering for inline and popup forms.
- Added secure AJAX submission with nonce validation.
- Added server-side Turnstile verification.
- Added listmonk subscriber API integration.
- Added glassmorphism form styling and animated success transition.

== Upgrade Notice ==

= 1.0.0 =

Initial release.
