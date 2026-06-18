=== Sentient Forms ===
Contributors: twptech
Tags: forms, ai, gravity-forms, openrouter, automation
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.3.14
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI actions for Gravity Forms: lead scoring, spam review, entry summaries, and WordPress logs.

== Description ==

Sentient Forms adds AI actions to Gravity Forms submissions: lead scoring (A/B/C/Reject), spam review, entry summaries, content checks, and WordPress logs. Other form builders are not supported in this release.

A TWP Technologies, LLC product. Product site: https://sentientforms.com. Public development: https://github.com/TWP-Technologies/sentient-forms-wp-plugin.

Local settings, action definitions, form mappings, execution logs, provider settings, and saved results are stored in WordPress.

Use your OpenRouter key for direct execution, or connect Sentient Forms Managed Execution for managed paid features. AI-generated Site Context needs Managed Execution or a paid, web-capable OpenRouter model; you can also write it manually.

== Source ==

JavaScript and CSS in `assets/dist` are generated from `admin-app` in the public release source: https://github.com/TWP-Technologies/sentient-forms-wp-plugin/tree/v0.3.14. Build with `cd admin-app`, `bun install --frozen-lockfile`, and `bun run build:wp`.

== External services ==

This plugin sends data to external services only for provider paths and features an administrator enables.

OpenRouter direct execution:

* Service: OpenRouter
* Endpoint: https://openrouter.ai/
* When used: after an administrator connects OpenRouter and runs direct provider validation, a form action, or AI-generated Site Context. Site Context needs a paid, web-capable OpenRouter model.
* Data sent: selected form fields, prompt/action instructions, model identifier, and request metadata for form actions. Site Context sends the site URL, public-site research prompt, selected model, and request metadata; web-capable models may search or fetch public pages.
* Account required: an OpenRouter account/API key. Site Context requires a paid, non-free, web-capable model.
* Terms: https://openrouter.ai/terms
* Privacy policy: https://openrouter.ai/privacy

Sentient Forms Managed Execution:

* Service: Sentient Forms
* Endpoint: https://api.sentientforms.com/
* When used: optional managed account, billing, metering, managed model execution, support diagnostics, or administrator-enabled managed features.
* Data sent: account/site identifiers, billing state, and, for managed AI execution, selected form fields and prompt/action instructions. Managed Site Context sends the site URL, public-site research prompt, selected model, and request metadata; web-capable models may search or fetch public pages.
* Account required: a Sentient Forms account may be required for managed paid features. Direct OpenRouter does not require Sentient payment.
* Terms: https://sentientforms.com/terms
* Privacy policy: https://sentientforms.com/privacy

Optional Sentient Forms telemetry:

* Service: Sentient Forms
* Endpoint: https://api.sentientforms.com/v1/sites/telemetry and https://api.sentientforms.com/v1/telemetry/async
* When used: only after an administrator opts in and the site has a connected Sentient Forms site identity.
* Data sent: telemetry consent state and metadata-only events: plugin/runtime versions, provider path, action code, execution request ID, adapter, job status, attempt counts, and sanitized error or warning codes.
* Data not sent: form field contents, prompts, model outputs/results, raw error messages, visitor identifiers, saved provider secrets, billing secrets, or OpenRouter BYOK payloads.
* Account required: a Sentient Forms site identity is required for telemetry sync and delivery.
* Terms: https://sentientforms.com/terms
* Privacy policy: https://sentientforms.com/privacy

Administrator-configured webhooks:

* Service: the webhook URL entered by the site administrator.
* Endpoint: the administrator-provided webhook URL.
* When used: only when an administrator explicitly enables a post-execution webhook for an action.
* Data sent: form entry identifier, local action context, action result, and configured webhook metadata needed by the receiver.
* Account required: depends on the administrator-provided webhook receiver.
* Terms: provided by the administrator-chosen webhook receiver.
* Privacy policy: provided by the administrator-chosen webhook receiver.

Realtime Clarification Assistant:

* Service: OpenRouter direct execution or Sentient Forms Managed Execution, depending on the selected provider path.
* Endpoint: the same provider endpoint disclosed above for the selected execution path.
* When used: only when an administrator maps the Realtime Clarification Assistant to a Gravity Forms form and enables realtime suggestions.
* Data sent: visible field values collected before submission, realtime action instructions, form metadata, selected model identifier, and request metadata needed to return suggestions.
* Account required: depends on provider path. OpenRouter requires an account/API key. Managed execution may require a Sentient Forms account.
* Visitor disclosure: site owners should disclose realtime AI suggestions in their public privacy policy or form copy before enabling this feature, because selected in-progress field values can be sent before final submission.
* Terms and privacy policy: the same provider terms and privacy policy disclosed above for the selected execution path.

No OpenRouter or Sentient AI execution request should be sent until an administrator has configured and accepted the relevant provider disclosure. No telemetry event should be queued or sent until an administrator opts in and a Sentient Forms site identity exists.

== Installation ==

1. Upload the `sentient-forms` folder to `/wp-content/plugins/`, or install the plugin through the WordPress plugin installer.
2. Activate Sentient Forms in WordPress.
3. Open the Sentient Forms admin screen.
4. Connect OpenRouter for direct local-first execution, or connect Sentient Forms Managed Execution if you want managed paid usage.
5. Create or select an action, map it to a Gravity Forms form, and run a test submission. Current form-action workflows require Gravity Forms.

== Frequently Asked Questions ==

= Do I need a paid Sentient Forms account? =

No. The plugin is intended to provide a functional direct path through your own OpenRouter credentials. Sentient Forms Managed Execution is optional.

= Does Sentient Forms receive my form data when I use direct OpenRouter execution? =

No. Direct OpenRouter execution is performed from your WordPress site to OpenRouter. Sentient Forms does not receive direct OpenRouter BYOK/free execution payloads.

= Can AI generate Site Context with OpenRouter free models? =

No. AI-generated Site Context requires either Sentient Forms Managed Execution or a configured paid, web-capable OpenRouter model because the generation step uses public-site research. Free OpenRouter routes can still be used for supported form-action workflows where the selected model and provider allow them. You can also write Site Context manually without any paid model.

= What data is stored locally? =

The plugin stores local provider settings, action templates, custom actions, form mappings, execution events, selected results, consent records, and migration records in WordPress.

= Can I remove local data? =

The plugin includes WordPress personal data export/erase integration for local execution results that contain a verified email address. It also supports retention cleanup for execution events and configurable uninstall behavior.

= Which form builders are supported? =

Gravity Forms is required for the current form-action workflows. Other form builders are planned but are not supported in this release.

= Where is the source for the compressed admin JavaScript? =

See "Source" above and `assets/dist/SOURCE.md` in the package.

== Screenshots ==

1. Review leads with A/B/C/Reject grades and rationale.
2. See AI results on each Gravity Forms entry.
3. Tune Lead Scoring with site context and examples.
4. Track action runs by status, form, entry, model, and result.
5. Map AI actions to a Gravity Forms form.
6. View setup state, providers, forms, and recent runs.

== Changelog ==

= 0.3.11 =

* Make generated admin asset source and build instructions prominent for WordPress.org review.

= 0.3.10 =

* Harden realtime structured-output requests so reasoning-capable OpenRouter models do not consume the completion budget before returning JSON.

= 0.3.9 =

* Fix paginated realtime suggestions and add safe structured-output failure diagnostics.

= 0.3.8 =

* Enforce strict OpenRouter JSON Schema output and require structured-capable models for realtime suggestions.

= 0.3.7 =

* Harden public realtime suggestions so stale WordPress REST nonces cannot block logged-in visitors; realtime requests now use only the Sentient Forms form-scoped nonce.

= 0.3.6 =

* Remove UTF-8 BOMs that could contaminate WordPress JSON responses, add source/package encoding gates, and improve admin diagnostics for invalid JSON responses.

= 0.3.5 =

* Remove admin app development source and build tooling from the WordPress.org package, document the public source tag for generated assets, and harden WordPress.org review checks.

= 0.1.1 =

* Prepare the WordPress.org production package pipeline, include admin app source for generated assets, and harden serviceware disclosures.

= 0.1.0 =

* Initial local-first migration baseline.
