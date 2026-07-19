# Sentient Forms Async Handler

_Last updated: 2026-07-18_

## Purpose

Sentient Forms runs asynchronous plugin-owned Form Mapping work without keeping raw form payloads in the queue. The retained handler has two job types:

- `sentient_forms_process_local_mapping` resolves and executes a persisted local Form Mapping from identifiers.
- `sentient_forms_evaluate_action` performs an optional adapter-owned follow-up evaluation.

The former `sentient_forms_process_action` CPS Action runtime is retired. It is not registered, cannot be scheduled through the plugin, and cannot be replayed through WP-CLI.

## Local Mapping Jobs

Call `Sentient_Forms_Async_Handler::schedule_local_mapping()` with a numeric persisted Form Mapping ID plus Form Source, form, entry or Submission Ledger, and execution identifiers:

```php
$scheduled = Sentient_Forms_Plugin::instance()
    ->get_async_handler()
    ->schedule_local_mapping(
        $mapping_id,
        [ 'id' => $form_id ],
        [ 'id' => $entry_id ],
        [
            'form_source'          => 'gravity_forms',
            'execution_request_id' => $execution_request_id,
            'submission_uuid'      => $submission_uuid,
        ]
    );
```

The queue payload contains identifiers and normalized execution context. The worker reloads the authoritative Form Mapping, Action definition, form, and entry or Submission Ledger row at execution time. It does not serialize the raw form submission into Action Scheduler.

Every job receives a durable `execution_request_id` and payload digest in `sentient_async_requests`. Duplicate active or successful identities are blocked; a digest conflict fails closed. Transient provider failures may retry according to the configured retry policy. Authentication, configuration, and other permanent provider failures do not retry.

## Dependency Handling

Local jobs may carry `dependency_mapping_ids` and `dependency_execution_request_ids` in their context. The worker:

- waits by re-enqueueing the same identifier-only local job while a dependency is active;
- executes after required successful or non-spam outcomes;
- records a single skipped execution event when a prerequisite fails, is skipped, or classifies the submission as spam under the mapping policy.

Dependency waiting never falls back to the retired CPS Action hook.

## Evaluation Jobs

Adapters or integrations may call `dispatch_evaluation()` directly:

```php
Sentient_Forms_Plugin::instance()->get_async_handler()->dispatch_evaluation(
    [
        'adapter_id' => 'gravity_forms',
        'entry_id'   => $entry_id,
        'form_id'    => $form_id,
        'action_id'  => $action_code,
        'payload'    => $evaluation_payload,
        'context'    => [ 'job_type' => 'evaluation' ],
    ]
);
```

After a successful local mapping, the handler also gathers follow-up definitions from `context.evaluation_jobs`, `result.evaluation_jobs`, `result.evaluation_payload`, and the `sentient_forms_async_evaluation_jobs` filter. Each evaluation receives its own deterministic `evaluation_request_id` and `record_type = evaluation` idempotency record.

## Queue and Retry Configuration

Action Scheduler is preferred, with WordPress cron as the fallback. Jobs use the `sentient_forms_async` group unless `sentient_forms_async_scheduler_group` changes it. The global retry policy is available through:

- REST: `sentient-forms/v1/async-settings`.
- WP-CLI: `wp sentient-forms async settings`.

The handler copies `max_attempts`, `backoff_base_delay`, and `backoff_max_delay` into each job context so an in-flight job keeps the policy under which it was admitted.

## Legacy Queue Retirement

Installer version `2026.07.18.v1` retires pre-cutover `sentient_forms_process_action` jobs:

1. All WordPress cron occurrences are unscheduled.
2. Claimed-pending or in-progress Action Scheduler rows hold the database version gate and are never canceled.
3. Once no runner owns a legacy row, pending rows are canceled across every Action Scheduler group.
4. Existing complete, failed, and canceled scheduler history is preserved.
5. A completed scheduler row without plugin-owned terminal evidence becomes `indeterminate`.

`indeterminate` is terminal and non-replayable even after the ordinary request TTL. It is also excluded from request-ledger purges so expiry cannot turn unknown provider side effects into a second execution.

## Metadata and WP-CLI

The metadata store retains at most 50 recent job summaries for health and support tooling. `sentient_forms_async_metadata_payload` may reduce the stored payload further.

- `wp sentient-forms async list` lists recorded jobs.
- `wp sentient-forms async status` reports backlog, stale queue, and failure warnings.
- `wp sentient-forms async reconcile` compares active metadata with terminal Action Scheduler state.
- `wp sentient-forms async requeue <job-id>` may requeue supported retained jobs, but rejects retired CPS and indeterminate jobs.
- `wp sentient-forms async purge` removes matching metadata only.
- `wp sentient-forms async-requests list` and `purge` operate on the durable idempotency ledger; indeterminate rows are preserved.

## Adapter Contract

An adapter participating in asynchronous finalization implements `Sentient_Forms_Async_Capable_Adapter_Interface`:

- `finalize_async_success( array $context, array $result )`
- `finalize_async_error( array $context, WP_Error $error )`
- `finalize_async_evaluation( array $context, array $payload )`

The handler resolves the adapter through the authoritative Form Source registry. Form Source adapters normalize source data and apply native outcomes; they do not choose entitlement or provider routing.

## Hooks

| Hook | Purpose |
| --- | --- |
| `sentient_forms_process_local_mapping` | Execute one persisted local Form Mapping from identifiers. |
| `sentient_forms_evaluate_action` | Execute an adapter-owned evaluation follow-up. |
| `sentient_forms_async_success` | Announce successful retained async execution. |
| `sentient_forms_async_failure` | Announce terminal retained async failure. |
| `sentient_forms_async_event` | Structured consent/debug-aware health and lifecycle event. |
| `sentient_forms_async_evaluation_jobs` | Add evaluation definitions after successful local execution. |
| `sentient_forms_async_job_scheduled` | Observe queue scheduling for diagnostics and tests. |

See `includes/class-async-handler.php`, `includes/services/class-sentient-forms-async-request-store.php`, and `includes/interfaces/interface-sentient-forms-async-capable-adapter.php` for the executable contract.
