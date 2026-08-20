=== Sentient Forms ===
Contributors: twptech
Tags: forms, ai, automation, openrouter, form-automation
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI actions for Gravity Forms, Contact Form 7, WPForms, and Elementor Pro Forms: lead scoring, spam review, summaries, and logs.

== Description ==

Sentient Forms adds AI Actions to Gravity Forms, Contact Form 7, WPForms, and Elementor Pro Forms submissions: lead scoring (A/B/C/Reject), spam review, entry summaries, content checks, and WordPress logs. Webmasters can use the built-in Actions or create and manage their own Actions in WordPress, including starting from a built-in Action.

Spam Detection and Content Quality Validation can run during validation on all four supported Form Sources. Contact Form 7, WPForms, and Elementor Pro Forms support validation plus after-submission Actions through the Submission Ledger; Elementor Pro Forms requires its Forms APIs. The Realtime Clarification Assistant is available only for Gravity Forms. Native notes, spam status, notification controls, and webhook controls vary by Form Source; the Submission Ledger is the shared result surface where native entry features are unavailable.

A TWP Technologies, LLC product: https://sentientforms.com. Public source: https://github.com/TWP-Technologies/sentient-forms-wp-plugin.

Optional diagnostic consent records allowlisted runtime metadata in WordPress only. Sentient Forms does not remotely queue or deliver those local diagnostic events.

Use your OpenRouter key for direct execution, or connect Sentient Forms Managed Execution for managed paid features. AI-generated Site Context needs Managed Execution or a paid, web-capable OpenRouter model; you can also write it manually.

== Source ==

JavaScript and CSS in `assets/dist` are generated from `admin-app` in the public release source: https://github.com/TWP-Technologies/sentient-forms-wp-plugin/tree/v0.12.0. Build with `cd admin-app`, `bun install --frozen-lockfile`, and `bun run build:wp`.

== External services ==

This plugin sends data to external services only for provider paths and features an administrator enables.

OpenRouter direct execution:

* Service: OpenRouter
* Endpoint: https://openrouter.ai/
* When used: after an administrator connects OpenRouter and runs direct validation, an Action, or AI-generated Site Context.
* Data sent: selected mapped form fields, Action instructions, model, and request metadata. Site Context sends the site URL and research prompt; web-capable models may fetch public pages.
* Account required: an OpenRouter account/API key. Site Context requires a paid, non-free, web-capable model.
* Terms: https://openrouter.ai/terms
* Privacy policy: https://openrouter.ai/privacy

Sentient Forms Managed Execution:

* Service: Sentient Forms
* Endpoint: https://api.sentientforms.com/
* When used: optional managed account, billing, metering, model execution, support diagnostics, or enabled managed features.
* Data sent: account/site identifiers and billing state; managed AI execution also sends selected mapped fields, Action instructions, model, and request metadata. Site Context sends the site URL and research prompt; web-capable models may fetch public pages.
* Account required: a Sentient Forms account may be required for managed paid features. Direct OpenRouter does not require Sentient payment.
* Terms: https://sentientforms.com/terms
* Privacy policy: https://sentientforms.com/privacy

Administrator-configured webhooks:

* Service: the webhook URL entered by the site administrator.
* Endpoint: the administrator-provided webhook URL.
* When used: only when an administrator explicitly enables a post-execution webhook for an action.
* Data sent: entry identifier, Action context and result, and configured webhook metadata.
* Account required: depends on the administrator-provided webhook receiver.
* Terms: provided by the administrator-chosen webhook receiver.
* Privacy policy: provided by the administrator-chosen webhook receiver.

Realtime Clarification Assistant:

* Service: OpenRouter direct execution or Sentient Forms Managed Execution, depending on the selected provider path.
* Endpoint: the same provider endpoint disclosed above for the selected execution path.
* When used: only when an administrator maps the Realtime Clarification Assistant to a Gravity Forms form and enables realtime suggestions.
* Data sent: visible pre-submission field values, Action instructions, form metadata, model, and request metadata.
* Account required: depends on provider path. OpenRouter requires an account/API key. Managed execution may require a Sentient Forms account.
* Visitor disclosure: site owners should disclose realtime AI suggestions before enabling them because selected field values can be sent before submission.
* Terms and privacy policy: the same provider terms and privacy policy disclosed above for the selected execution path.

Spam Guidance:

* Service: OpenRouter direct execution or Sentient Forms Managed Execution, depending on the eligible provider path selected by Sentient Forms.
* Endpoint: the same provider endpoint disclosed above for the selected execution path.
* When used: only when an authenticated administrator with an active Sentient Forms subscription selects historical submissions and asks Sentient Forms to improve Spam Detection guidance and save a generated rationale.
* Data sent: selected historical submission excerpts, existing Spam Detection guidance, form metadata, model, and request metadata needed for the generated rationale.
* Provider order: eligible managed credits are used first. Paid Direct OpenRouter may be used as a fallback when configured and eligible.
* Account required: an active Sentient Forms subscription is required. Direct fallback also requires a configured OpenRouter account/API key.
* Terms and privacy policy: the same provider terms and privacy policy disclosed above for the selected execution path.

No OpenRouter or Sentient AI execution request should be sent until an administrator has configured and accepted the relevant provider disclosure. Local diagnostic events are recorded only after an administrator opts in and are never remotely queued or delivered.

== Installation ==

1. Upload the `sentient-forms` folder to `/wp-content/plugins/`, or install the plugin through the WordPress plugin installer.
2. Activate Sentient Forms in WordPress.
3. Open the Sentient Forms admin screen.
4. Connect OpenRouter for direct local-first execution, or connect Sentient Forms Managed Execution if you want managed paid usage.
5. Map an Action and run a test submission. Validation support and result surfaces follow the Form Source description above.

== Frequently Asked Questions ==

= Do I need a paid Sentient Forms account? =

No. The plugin is intended to provide a functional direct path through your own OpenRouter credentials. Sentient Forms Managed Execution is optional.

= Does Sentient Forms receive my form data when I use direct OpenRouter execution? =

No. Direct OpenRouter execution is performed from your WordPress site to OpenRouter. Sentient Forms does not receive direct OpenRouter BYOK/free execution payloads.

= Can AI generate Site Context with OpenRouter free models? =

No. AI-generated Site Context requires either Sentient Forms Managed Execution or a configured paid, web-capable OpenRouter model because the generation step uses public-site research. Free OpenRouter routes can still be used for supported form-action workflows where the selected model and provider allow them. You can also write Site Context manually without any paid model.

= What data is stored locally? =

The plugin stores local provider settings, Action templates, custom Actions, form mappings, Execution Events, Submission Ledger records, selected results, consent records, and migration records in WordPress.

= Can I remove local data? =

The plugin includes WordPress personal data export/erase integration for local execution results that contain a verified email address. Administrators can set retention for both Execution Events and Submission Ledger records, run cleanup, and configure uninstall behavior.

= Which form builders are supported? =

Gravity Forms, Contact Form 7, WPForms, and Elementor Pro Forms are supported. Spam Detection and Content Quality Validation run during validation on all four. Gravity Forms also supports realtime clarification and the deepest native effects. The other sources use the Submission Ledger for after-submission results and do not support realtime suggestions. Native effects follow each Form Source descriptor. Elementor Pro Forms requires its Forms APIs.

= Where is the source for the compressed admin JavaScript? =

See "Source" above and `assets/dist/SOURCE.md` in the package.

== Screenshots ==

1. Review leads with A/B/C/Reject grades and rationale.
2. Review saved results in native entries and the Submission Ledger.
3. Tune Lead Scoring with site context and examples.
4. Track action runs by status, form source, entry, model, and result.
5. Map Actions to supported forms.
6. View setup state, providers, form sources, and recent runs.

== Changelog ==

= 0.12.0 =

* Add validation support for Spam Detection and Content Quality Validation across Gravity Forms, Contact Form 7, WPForms, and Elementor Pro Forms.
* Add customer-created Actions, Spam Guidance from selected historical submissions, and separate retention controls for Execution Events and Submission Ledger records.
* Preserve Form Source-specific native effects while using the Submission Ledger as the shared after-submission result surface.
