# Sentient Forms Async Handler

This document describes the current local-first background execution system. Historical release evidence and retired transport designs remain in dated goals, release artifacts, and Git history; they are not current runtime guidance.

## Responsibilities

`Sentient_Forms_Async_Handler` coordinates two provider-neutral job types:

- Local mapping jobs execute a persisted plugin mapping through the local workflow services.
- Evaluation jobs apply or finalize follow-up work through the registered Form Source adapter.

The handler owns scheduling, retry metadata, idempotency records, Submission Ledger linkage, execution-event recording, dependency ordering, and local diagnostic events. It does not own Action definitions or select a provider independently. The plugin Action Catalog, mapping, facet policy, and provider-routing services determine executable behavior before or during the workflow.

Jobs run in the `sentient_forms_async` Action Scheduler group when Action Scheduler is available, with WordPress cron as the fallback scheduler. The queue group and label remain filterable through `sentient_forms_async_scheduler_group` and `sentient_forms_async_scheduler_group_label`.

## Local mapping jobs

Use `schedule_local_mapping()` with a valid local mapping ID, Form Source, form ID, entry or submission identifier, and workflow context. The method:

1. Normalizes the source and identifiers.
2. Generates or preserves an `execution_request_id`.
3. Blocks an active or completed duplicate request.
4. Records queued request and execution-event state.
5. Enqueues `sentient_forms_process_local_mapping`.

The worker reloads the persisted mapping and Action definition. A stale legacy mapping is not executed: the workflow records a terminal linked event with `legacy_mapping_requires_migration` and returns a failed outcome. Provider identity is resolved from the persisted mapping and Action before the first execution event, so even an early managed failure is classified correctly. Unknown identity is recorded as `unclassified`, never guessed as Direct OpenRouter.

Transient failures use the retry policy captured in the job context. Permanent provider or configuration failures terminate without retry. A terminal result calls the adapter's success or error finalizer and preserves the `submission_uuid` link when the Submission Ledger is enabled.

## Evaluation jobs

Use `dispatch_evaluation()` with an adapter/Form Source identifier, entry and form identifiers, Action identifier, evaluation payload, and context. It creates a stable `evaluation_request_id` and records that request with `record_type = evaluation`.

Duplicate evaluation requests are marked `skipped` with `evaluation_duplicate_blocked` and are not scheduled again. Eligible jobs run through `sentient_forms_evaluate_action` and resolve an adapter through the authoritative Form Source registry. Dependency gates can delay or skip downstream work without inventing source-specific behavior in the handler.

Adapters that finalize background work implement `Sentient_Forms_Async_Capable_Adapter_Interface`. Optional native effects remain adapter capabilities; the Submission Ledger and linked execution events are the source-neutral audit surface.

## Retry and idempotency

The default retry policy is three attempts with exponential backoff from 60 seconds, capped by the configured maximum. Administrators can change the global retry settings through the async settings screen or the equivalent WP-CLI command. Each queued job captures its effective settings so a later configuration change does not alter an already-running retry chain.

The persistent async request store prevents duplicate local mapping and evaluation work across PHP processes. The metadata store records queue status and the filtered payload needed for inspection or explicit requeue. `sentient_forms_async_metadata_payload` runs before a payload is retained; deployments should remove any field they do not need for recovery. Changing this filter can intentionally make a job impossible to requeue.

Useful WP-CLI operations include:

- `wp sentient-forms async list`
- `wp sentient-forms async status`
- `wp sentient-forms async settings`
- `wp sentient-forms async requeue <job-id>`
- `wp sentient-forms async reconcile`
- `wp sentient-forms async purge`
- `wp sentient-forms async-requests list`
- `wp sentient-forms async-requests purge`

Reconciliation is dry-run by default and should be applied only after reviewing the proposed Action Scheduler status corrections.

## Metadata-only local diagnostics

`sentient_forms_async_event` is a local WordPress action for site-specific diagnostics. It fires only after the administrator enables the optional diagnostic-consent setting. Debug mode cannot enable the hook or widen its payload. When the separate on-site logging setting is enabled, the plugin attaches a default listener that writes the allowlisted envelope to its masked JSONL log.

The hook receives exactly this envelope:

```php
[
    'event'     => 'local_mapping_failure',
    'metadata'  => [
        'schema_version'       => 'sentient_forms_local_async_diagnostic.v1',
        'plugin_version'       => SENTIENT_FORMS_VERSION,
        'action_code'          => 'entry_summary_v1',
        'execution_request_id' => 'request-id',
        'provider_path'        => 'managed',
        'status'               => 'failed',
        'error_code'           => 'safe_machine_code',
    ],
    'timestamp' => time(),
]
```

Only scalar values from the implementation allowlist can appear in `metadata`: Action identifiers, execution request ID, adapter/Form Source, provider path, job type, retry counters, status, safe reason/error/warning codes, durations, queue wait, and scheduled time. Form or entry IDs, form field values, prompts, model output, raw errors, secrets, billing data, and currency are not exposed through this hook.

No telemetry leaves the WordPress site in this release. There is no remote diagnostic queue, synchronization, or CPS delivery path. Any rows left by a retired telemetry implementation are transitioned to `telemetry_retired` with a local cancellation reason and are never marked as sent.

Example custom local listener (the plugin's default writer uses the masked logger instead):

```php
add_action(
    'sentient_forms_async_event',
    static function ( array $event ): void {
        if ( 'local_mapping_failure' === ( $event['event'] ?? '' ) )
        {
            error_log( '[Sentient Forms local diagnostic] ' . wp_json_encode( $event ) );
        }
    }
);
```

Custom listeners are responsible for their own log access, payload handling, and retention policy. Turning off on-site logging disables the plugin's default writer; turning off diagnostic consent stops event generation entirely.

## Operational hooks

- `sentient_forms_async_job_scheduled` fires after a background job is scheduled.
- `sentient_forms_async_success` fires after successful local or evaluation work.
- `sentient_forms_async_failure` fires after a terminal failure.
- `sentient_forms_async_event` exposes the allowlisted local diagnostic envelope above.
- `sentient_forms_async_health_warning` reports local queue-health warnings.
- `sentient_forms_async_evaluation_jobs` filters source-neutral follow-up evaluation jobs.
- `sentient_forms_async_metadata_payload` filters a payload before metadata retention.
- `sentient_forms_async_metadata_jobs` filters the diagnostic job list.

Admin health surfaces report queue depth, scheduler availability, and repeated failures. Dismissing a warning changes only its local presentation; it does not change job state or contact an external service.

Refer to `includes/class-async-handler.php`, `includes/services/class-sentient-forms-async-metadata-store.php`, and `includes/interfaces/interface-sentient-forms-async-capable-adapter.php` for the executable contracts.
