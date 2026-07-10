# Form Source Adapter Boundaries

Sentient Forms behavior should be centralized unless the behavior is genuinely native to a specific Form Source plugin. The WP plugin defines the adapter contract, its shape, lifecycle vocabulary, capability descriptors, and result-effect semantics. Adapters fulfill that contract with source-specific implementations for Gravity Forms, Contact Form 7, WPForms, Elementor Pro Forms, or future sources.

## Rules

- Keep baseline parity source-neutral: the Sentient Forms Submission Ledger and linked action runs are the canonical cross-source surface.
- Treat native entry links, notes, entry metadata, spam status, notification controls, and validation hooks as capabilities, not assumptions.
- Do not branch on a Form Source slug in shared services when an adapter capability or optional interface can express the difference.
- Do not let adapters redefine product semantics. Shared Sentient Forms services decide what an effect means; adapters only implement how that effect is discovered, captured, linked, or applied in the source plugin.
- Native enrichments must return explicit outcomes: `applied`, `unsupported`, `skipped`, or `failed`, with a stable reason.
- Unsupported native enrichment is acceptable when the ledger baseline still captures the submission and action run. Do not describe unsupported enrichment as full native parity.
- Add or update a public-behavior test before moving a native effect behind an adapter contract.
- Current broad support claims must consider Gravity Forms, Contact Form 7, WPForms, and Elementor Pro Forms. Elementor support means Elementor Pro Forms, not free Elementor without the Forms APIs.

## Current Slice

`Sentient_Forms_Accepted_Submission_Adapter_Interface` is the optional accepted-submission lifecycle contract. Adapters declare the source-native accepted hook and normalize native submission data; the lifecycle-neutral `Sentient_Forms_Form_Source_Workflow_Runner` owns ledger capture and linkage, workflow configuration, mapping planning, conditions, dependency and idempotency context, capability-based native-effect filtering, and async scheduling without branching on Form Source identifiers. Normalized payloads may contribute source-neutral native entry identity, submitted time, and provider metadata; the runner alone passes those fields to ledger capture. Contact Form 7 enters this lifecycle only from `wpcf7_mail_sent`, after mail has been sent successfully. WPForms enters only from `wpforms_process_complete` with its four-argument hook signature and preserves native entry identity when available. Elementor Pro Forms enters only from `elementor_pro/forms/new_record` with its two-argument hook signature. Earlier processing hooks do not enter the shared runner. Validation will enter this same runner through its own thin lifecycle method rather than a sibling validation orchestrator.

The canonical Elementor Pro Forms identifier is `elementor_pro_forms`. Upgrade migration moves the former `elementor_forms` option namespaces, every plugin-owned local table with a `form_source` column, and queued async adapter identities forward once. Existing canonical option or ledger-setting rows win deterministic collisions; the legacy duplicate is removed rather than overwriting canonical data. Runtime lookup does not retain the former identifier as an alias.

`Sentient_Forms_Native_Effects_Adapter_Interface` is the optional native-effects contract. The first implemented vertical slices route local `store_result`, `entry_note`, `meta`, `spam_note`, `mark_as_spam`, `post_execution_entry_note`, and `post_execution_action_results` effects to a registered adapter when that adapter opts in, while preserving existing Gravity behavior and explicit unsupported results for adapters that do not opt in. These effects now use the registered native-effects adapter for any opted-in Form Source, including Gravity Forms, and fall back to the legacy Gravity writes/status behavior only when no native-effects adapter is registered.

Gravity Forms now implements the same optional interface for `store_result`, `entry_note`, `meta`, `spam_note`, `mark_as_spam`, `post_execution_entry_note`, and `post_execution_action_results`, backed by the existing Gravity metadata, note, entry-meta, spam-status, and post-execution audit APIs. The shared applier has been rerouted through that Gravity interface for these local result effects.

Follow-up slices should continue moving source-specific behavior behind the same style of contract:

- explicit unsupported native-effects implementations for CF7, WPForms, and Elementor Pro Forms unless a verified native capability exists
- provider preview/admin links where they still depend on source-specific branches

## Agent Context Placement

Keep the root instruction files concise. Put durable adapter rules here, implementation-specific reminders in `wp-plugin/AGENTS.md`, and executable constraints in PHPUnit/Vitest/Playwright tests. Use skills for repeated workflows such as TDD, architecture review, and browser greenlight validation instead of expanding every prompt with long process text.
