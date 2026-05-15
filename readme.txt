=== Sentient Forms ===
Contributors: twptech
Tags: forms, ai, gravity-forms, openrouter, automation
Requires at least: 6.8.0
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run AI-powered form actions from WordPress with local settings, local logs, and optional managed execution.

== Description ==

Sentient Forms adds AI-assisted actions to WordPress forms. Gravity Forms is the first supported form builder.

The plugin is moving to a local-first architecture. Site-owned configuration, action definitions, form mappings, execution logs, provider settings, and saved results are stored in your WordPress database.

You can use the plugin without a paid Sentient Forms account by connecting your own OpenRouter API key and choosing models available to your OpenRouter account, including OpenRouter free models when available. Optional Sentient managed execution is intended for users who want Sentient Forms to handle paid proxy execution, billing, and metering.

= External services =

This plugin can connect to external AI services, depending on which provider path you enable.

OpenRouter direct execution:

* Service: OpenRouter
* Endpoint: https://openrouter.ai/
* When used: when an administrator connects OpenRouter and runs a direct provider validation or form action.
* Data sent: the selected form fields, prompt/action instructions, model identifier, and request metadata needed to complete the AI request.
* Account required: an OpenRouter account and API key are required for direct execution.
* Terms: https://openrouter.ai/terms
* Privacy policy: https://openrouter.ai/privacy

Sentient managed execution:

* Service: Sentient Forms
* Endpoint: https://api.sentientforms.com/
* When used: only for optional Sentient managed account, billing, metering, managed model execution, support diagnostics, or other administrator-enabled managed features.
* Data sent: account/site identifiers, billing state, and, for managed AI execution only, the selected form fields and prompt/action instructions needed to complete the request.
* Account required: a Sentient Forms account may be required for managed paid features. The direct OpenRouter path does not require Sentient payment.
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

No OpenRouter or Sentient AI execution request should be sent until an administrator has configured and accepted the relevant provider disclosure.

== Installation ==

1. Upload the `sentient-forms` folder to `/wp-content/plugins/`, or install the plugin through the WordPress plugin installer.
2. Activate Sentient Forms in WordPress.
3. Open the Sentient Forms admin screen.
4. Connect OpenRouter for direct local-first execution, or connect Sentient managed execution if you want managed paid usage.
5. Create or select an action, map it to a Gravity Forms form, and run a test submission.

== Frequently Asked Questions ==

= Do I need a paid Sentient Forms account? =

No. The plugin is intended to provide a functional direct path through your own OpenRouter credentials. Sentient managed execution is optional.

= Does Sentient Forms receive my form data when I use direct OpenRouter execution? =

No. Direct OpenRouter execution is performed from your WordPress site to OpenRouter. Sentient Forms does not receive direct OpenRouter BYOK/free execution payloads.

= What data is stored locally? =

The plugin stores local provider settings, action templates, custom actions, form mappings, execution events, selected results, consent records, and migration records in WordPress.

= Can I remove local data? =

The plugin includes WordPress personal data export/erase integration for local execution results that contain a verified email address. It also supports retention cleanup for execution events and configurable uninstall behavior.

= Which form builders are supported? =

Gravity Forms is the first supported adapter.

= Where is the source for the compressed admin JavaScript? =

The JavaScript and CSS files in `assets/dist` are generated from the SvelteKit admin app source. Public source and build tooling for this release are published at https://github.com/TWP-Technologies/sentient-forms-release-source/tree/v0.1.0. Build with `bun install --frozen-lockfile` and `bun run build:wp` from the `admin-app/` directory.

== Changelog ==

= 0.1.0 =

* Initial local-first migration baseline.
