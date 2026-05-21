=== Sentient Forms ===
Contributors: twptech
Tags: forms, ai, gravity-forms, openrouter, automation
Requires at least: 6.8.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run AI-powered form actions from WordPress with local settings, local logs, and optional managed execution.

== Description ==

Sentient Forms adds AI-assisted actions to WordPress forms. Gravity Forms is the first supported form builder.

The plugin is moving to a local-first architecture. Site-owned configuration, action definitions, form mappings, execution logs, provider settings, and saved results are stored in your WordPress database.

You can use the plugin without a paid Sentient Forms account by connecting your own OpenRouter API key and choosing models available to your OpenRouter account, including OpenRouter free models when available. Optional Sentient Forms Managed Execution is intended for users who want Sentient Forms to handle paid proxy execution, billing, and metering.

= External services =

This plugin can connect to external AI services, depending on which provider path you enable.

OpenRouter direct execution:

* Service: OpenRouter
* Endpoint: https://openrouter.ai/
* When used: when an administrator connects OpenRouter and runs a direct provider validation, form action, or AI-generated Site Context refresh through the direct provider path.
* Data sent: for form actions, the selected form fields, prompt/action instructions, model identifier, and request metadata needed to complete the AI request. For AI-generated Site Context, the site URL, public-site research prompt, selected model identifier, and request metadata are sent, and enabled web-capable models may use web search or fetch against public site pages.
* Account required: an OpenRouter account and API key are required for direct execution.
* Terms: https://openrouter.ai/terms
* Privacy policy: https://openrouter.ai/privacy

Sentient Forms Managed Execution:

* Service: Sentient Forms
* Endpoint: https://api.sentientforms.com/
* When used: only for optional Sentient Forms managed account, billing, metering, managed model execution, support diagnostics, or other administrator-enabled managed features.
* Data sent: account/site identifiers, billing state, and, for managed AI execution only, the selected form fields and prompt/action instructions needed to complete the request. For managed AI-generated Site Context, the site URL, public-site research prompt, selected model identifier, and request metadata are sent, and enabled web-capable models may use web search or fetch against public site pages.
* Account required: a Sentient Forms account may be required for managed paid features. The direct OpenRouter path does not require Sentient payment.
* Terms: https://sentientforms.com/terms
* Privacy policy: https://sentientforms.com/privacy

Optional Sentient Forms telemetry:

* Service: Sentient Forms
* Endpoint: https://api.sentientforms.com/v1/sites/telemetry and https://api.sentientforms.com/v1/telemetry/async
* When used: only after an administrator opts into telemetry and this site has a connected Sentient Forms site identity.
* Data sent: telemetry consent state and metadata-only operational events such as plugin/runtime versions, provider path, action code, execution request ID, adapter, job status, attempt counts, and sanitized error or warning codes.
* Data not sent: form field contents, prompts, model outputs/results, raw error messages, visitor identifiers, saved provider secrets, billing secrets, or OpenRouter BYOK payloads.
* Account required: a Sentient Forms site identity is required for telemetry sync and delivery.
* Terms: https://sentientforms.com/terms
* Privacy policy: https://sentientforms.com/privacy

Administrator-configured webhooks:

* Service: the webhook URL entered by the site administrator.
* Endpoint: the administrator-provided webhook URL.
* When used: only when an administrator explicitly enables a post-execution webhook for an action.
* Data sent: the form entry identifier, local action context, action result, and configured webhook action metadata needed by the webhook receiver.
* Account required: depends on the administrator-provided webhook receiver.
* Terms: provided by the administrator-chosen webhook receiver.
* Privacy policy: provided by the administrator-chosen webhook receiver.

No OpenRouter or Sentient AI execution request should be sent until an administrator has configured and accepted the relevant provider disclosure. No telemetry event should be queued or sent until an administrator opts in and a Sentient Forms site identity exists.

== Installation ==

1. Upload the `sentient-forms` folder to `/wp-content/plugins/`, or install the plugin through the WordPress plugin installer.
2. Activate Sentient Forms in WordPress.
3. Open the Sentient Forms admin screen.
4. Connect OpenRouter for direct local-first execution, or connect Sentient Forms Managed Execution if you want managed paid usage.
5. Create or select an action, map it to a Gravity Forms form, and run a test submission.

== Frequently Asked Questions ==

= Do I need a paid Sentient Forms account? =

No. The plugin is intended to provide a functional direct path through your own OpenRouter credentials. Sentient Forms Managed Execution is optional.

= Does Sentient Forms receive my form data when I use direct OpenRouter execution? =

No. Direct OpenRouter execution is performed from your WordPress site to OpenRouter. Sentient Forms does not receive direct OpenRouter BYOK/free execution payloads.

= What data is stored locally? =

The plugin stores local provider settings, action templates, custom actions, form mappings, execution events, selected results, consent records, and migration records in WordPress.

= Can I remove local data? =

The plugin includes WordPress personal data export/erase integration for local execution results that contain a verified email address. It also supports retention cleanup for execution events and configurable uninstall behavior.

= Which form builders are supported? =

Gravity Forms is the first supported adapter.

= Where is the source for the compressed admin JavaScript? =

The JavaScript and CSS files in `assets/dist` are generated from the SvelteKit admin app source. Public source and build tooling for this release are published at https://github.com/TWP-Technologies/sentient-forms-wp-plugin/tree/v0.1.0. Build with `bun install --frozen-lockfile` and `bun run build:wp` from the `admin-app/` directory.

== Changelog ==

= 0.1.0 =

* Initial local-first migration baseline.
