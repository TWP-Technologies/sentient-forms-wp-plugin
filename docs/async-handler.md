# Sentient Forms Async Handler

_Last updated: 2025-11-16_

## Why it exists
Gravity Forms (and future adapters) need to run expensive CPS actions without blocking form submissions. The async handler wraps WordPress Action Scheduler (or core `wp-cron`) and exposes two entry points:

- **Execution jobs** (`sentient_forms_process_action`) – run CPS actions outside the request lifecycle.
- **Evaluation jobs** (`sentient_forms_evaluate_action`) – finalize asynchronous workflows (e.g., apply CPS results back to entries).

Both pathways share exponential backoff, adapter callbacks, and consent-aware logging.

## Dedicated queue & filters

- Every job is enqueued into the `sentient_forms_async` Action Scheduler group. Overwrite the group by filtering `sentient_forms_async_scheduler_group` if a site needs to dedicate a different queue/runner. The group label shown in the Action Scheduler UI can be customized via `sentient_forms_async_scheduler_group_label`.
- The metadata store captures each job’s queue group, Action Scheduler action ID (when available), and the full payload used to requeue jobs. Use `sentient_forms_async_metadata_payload` to redact or shape the stored payload (e.g., remove sensitive entry fields) and `sentient_forms_async_metadata_jobs` to filter/sort the collection before telemetry or CLI consumers read it.

## Configurable retries & backoff

- Async retry settings now live under the dedicated REST route `sentient-forms/v1/async-settings` with GET/PUT support. The WordPress SPA (Settings → “Async retry policy”) calls the same endpoint and mirrors the latest values from `window.sentientFormsConfig.asyncSettings`.
- The PHP layer exposes the same controls via WP-CLI: `wp sentient-forms async settings` prints the active policy, and `wp sentient-forms async settings --max-attempts=5 --base-delay=120 --max-delay=1800` updates the option in one shot.
- `Sentient_Forms_Async_Handler` injects the configured `max_attempts`, `backoff_base_delay`, and `backoff_max_delay` into every job context so retries and CLI requeues keep the same policy even if you change defaults later.

## Scheduling execution jobs
```php
$handler = Sentient_Forms_Plugin::instance()->get_async_handler();
$handler->schedule_action(
    'spam_detection_v1',        // action ID registered with Sentient_Forms_Action_Registry
    $entry_payload,             // data passed to the action's ->execute()
    $action_settings,           // per-action configuration (LLM hints, etc.)
    [
        'form_source' => 'gravity_forms',
        'execution_request_id' => $request_id, // optional idempotency key
        'max_attempts' => 3                   // defaults to Sentient_Forms_Async_Handler::MAX_ATTEMPTS
    ]
);
```

`Sentient_Forms_Async_Handler::schedule_action()` automatically:
1. Verifies the action exists.
2. Normalizes the context (`attempt`, `max_attempts`, `job_type`).
3. Enqueues the job via Action Scheduler (preferred) or `wp_schedule_single_event` fallback.

Failures trigger exponential backoff (base 60 seconds, capped at 1 hour). On the final failure, the handler emits `sentient_forms_async_failure` and notifies the adapter via `finalize_async_error()`.

## Dispatching evaluation jobs
Adapters that need a second-phase evaluation (e.g., apply CPS output to form entries) may call:
```php
$handler->dispatch_evaluation(
    [
        'adapter_id' => 'gravity_forms',
        'entry_id'   => $entry_id,
        'form_id'    => $form_id,
        'action_id'  => 'spam_detection_v1',
        'payload'    => $evaluation_payload,
        'context'    => [ 'job_type' => 'evaluation' ],
        'run_at'     => time() + 120, // optional delay
    ]
);
```
The handler will invoke `Sentient_Forms_Async_Capable_Adapter_Interface::finalize_async_evaluation()` on the matching adapter. Retries/backoff mirror the execution flow.
Every evaluation job receives an `evaluation_request_id` derived from the adapter/action/context payload. The ID is stored in `sentient_async_requests` with `record_type = evaluation`, so resubmitting the same job data within the retention window prevents duplicate follow-ups and keeps the CPS ledger accurate.

> Example: The Gravity Forms adapter listens to `sentient_forms_async_evaluation_jobs` and automatically enqueues an evaluation job whenever CPS returns an `evaluation_payload`, so entry notes are updated once CPS responses land even if the original submission thread finished.

### Auto-dispatching evaluation jobs
Not every adapter/action can call `dispatch_evaluation()` directly. When an execution job succeeds, the async handler gathers follow-up work from:

1. `context['evaluation_jobs']` – jobs passed in when the original execution was scheduled.
2. `result['evaluation_jobs']` (or `result['evaluation_payload']`) – arrays returned by the action itself.
3. The `sentient_forms_async_evaluation_jobs` filter – last chance for adapters or mu-plugins to add jobs based on the execution context/result.

Each entry in these collections should resemble:

```php
[
    'adapter_id' => 'gravity_forms',
    'entry_id'   => 123,
    'form_id'    => 45,
    'action_id'  => 'entry_evaluation',
    'payload'    => [ 'result' => $cps_payload ],
    'delay'      => 120, // optional seconds before scheduling
]
```

By default, adapters inherit `entry_id`, `form_id`, and `action_id` from the execution job so most follow-up definitions only need to provide a payload.

> Debugging tip: when `WP_DEBUG` and `WP_DEBUG_LOG` are enabled (or the `sentient_forms_enable_debug_evaluation_logging` filter returns true), the plugin logs every payload passed through `sentient_forms_async_evaluation_jobs` via the `sentient_forms_debug_evaluation_payload` action. These entries land in `wp-content/debug.log` and are safe to remove once staging validation is complete.

### Duplicate evaluation requests

Evaluation jobs are idempotent. If the ledger already contains the same `evaluation_request_id`, the handler records the attempt as `skipped` with the message “Duplicate evaluation request blocked”, emits the async event `evaluation_duplicate_blocked`, and does **not** enqueue another job. This keeps health dashboards green while preserving an audit trail for accidental double-enqueues.

## Adapter contract
Adapters must implement `Sentient_Forms_Async_Capable_Adapter_Interface` to participate:
- `finalize_async_success( $context, $result )`
- `finalize_async_error( $context, WP_Error $error )`
- `finalize_async_evaluation( $context, array $payload )`

The async handler resolves adapters via `Sentient_Forms_Form_Adapter_Registry`, so ensure your adapter is registered during plugin bootstrap.

### Context payload reference

Every job receives a normalized `context` array that the handler enriches before enqueueing:

| Key | Description |
| --- | --- |
| `action_id` | Sentient Forms action identifier (e.g., `spam_detection_v1`). |
| `form_source` | Adapter slug such as `gravity_forms`; derived from `adapter_id` when omitted. |
| `adapter_id` | Optional; used when the evaluation job needs an adapter that differs from `form_source`. |
| `form_id` / `entry_id` | Adapter-provided identifiers for downstream bookkeeping and logging. |
| `execution_request_id` | Deterministic idempotency key generated from the form payload and central action. |
| `evaluation_request_id` | Present on evaluation jobs; deterministic hash used to dedupe `dispatch_evaluation()` calls. |
| `attempt` / `max_attempts` | Current retry counters. Defaults to `1` / `Sentient_Forms_Async_Handler::MAX_ATTEMPTS`. |
| `job_type` | `execution` or `evaluation`. Helps adapters branch logic. |
| `last_error` | The most recent error message (set only after a failed attempt). |

> **Idempotency:** `Sentient_Forms_Plugin::process_action_async()` caches `execution_request_id` values in a transient for one hour. If the same payload is submitted twice, the second invocation is ignored so CPS is not double-billed. If you override the cache horizon, update both the plugin code and this document.

## Telemetry & logging
- Local logging (`error_log`) only fires when `global_settings.debug_mode` is enabled.
- Consent-aware events now fire through `do_action( 'sentient_forms_async_event', $payload )` when **either** telemetry opt-in is enabled **or** debug mode is on. Payload shape:

```php
[
    'event'     => 'success|failed|retry_scheduled|evaluation_success|evaluation_failed|evaluation_retry_scheduled',
    'context'   => [ ... normalized job context ... ],
    'payload'   => [ 'error' => '...', 'run_at' => 1234567890 ] // or CPS result data
    'timestamp' => 1731763200
]
```

Admins can hook `sentient_forms_async_event` to bridge into site-specific logging or alerting systems:
```php
add_action( 'sentient_forms_async_event', function ( array $event ) {
    if ( $event['event'] === 'failed' ) {
        error_log( '[Sentient Forms async failure] ' . wp_json_encode( $event ) );
    }
} );
```

Setting telemetry opt-in to “Off” (via the SPA toggle or REST controller) suppresses these events unless debug mode overrides it.

## Backoff & retries
- Initial delay: 60 seconds.
- Delay grows exponentially per attempt and caps at one hour.
- `context['max_attempts']` defaults to 3 but can be overridden per job.

The handler automatically re-queues jobs with updated context (`attempt`, `last_error`, `run_at`).

## Metadata store & CLI helpers

Async job metadata is stored (max 50 entries) to power troubleshooting and CLI actions:

- `wp sentient-forms async list` – display the queue/group, status, attempts, and last error for each job in the store.
- `wp sentient-forms async requeue <job-id>` – clone a recorded payload/context and enqueue a fresh job (recorded under a new job ID with `requeued_from` for traceability). The command falls back to Action Scheduler’s internal store if metadata payload filters removed the `data`/`settings` arrays.
- `wp sentient-forms async purge [--status=success,failed] [--older-than=<minutes>]` – prune historical metadata without deleting in-flight jobs. Use this when the log gets noisy but you don’t want to wipe everything via `wp sentient-forms async clear`.
- `wp sentient-forms async settings [--max-attempts=N --base-delay=seconds --max-delay=seconds]` – inspect or update the global retry/backoff policy.
- `wp sentient-forms async status` – return the current queue depth plus any outstanding health warnings (see notices below).
- `wp sentient-forms async-requests list [--record-type=job|evaluation|telemetry] [--status=queued|running|success|failed] [--limit=N]` – inspect the persistent idempotency ledger.
- `wp sentient-forms async-requests purge --older-than=<minutes>` – bulk-remove old request hashes (default retention is 24h; filter `sentient_forms_async_request_ttl` to adjust). 
- REST: `GET /sentient-forms/v1/async-health` (nonce + `manage_options`) returns the same payload consumed by the SPA health card so headless dashboards can poll the queue/backoff state.
- Telemetry queue: events (job success/failure + health warnings) are persisted in `sentient_async_requests` with `record_type=telemetry`. The cron hook `sentient_forms_flush_telemetry` drains the queue every 5 minutes via `wp_safe_remote_post` to CPS. **Recommended:** configure a real server cron to run `wp cron event run sentient_forms_flush_telemetry` rather than relying on pseudo-cron traffic.

> **Note:** The `sentient_forms_async_metadata_payload` filter runs before payloads are stored. Removing keys like `data` or `settings` limits what the requeue CLI command can replay.

## Hooks summary
| Hook | Purpose |
| --- | --- |
| `sentient_forms_process_action` | Action Scheduler entry point for execution jobs (arguments: action_id, data, settings, execution_request_id, context). |
| `sentient_forms_evaluate_action` | Action Scheduler entry point for evaluation jobs (argument: payload with normalized context). |
| `sentient_forms_async_success` | Fires after an async job succeeds (context + result). |
| `sentient_forms_async_failure` | Fires after all retries are exhausted (context + WP_Error). |
| `sentient_forms_async_event` | New structured event stream; see Telemetry & logging. |
| `sentient_forms_async_health_warning` | Fires whenever the admin health monitor detects a warning (queue backlog, repeated failures, missing scheduler). |
| `sentient_forms_async_evaluation_jobs` | Filter the list of follow-up evaluation jobs after a successful execution. Return an array of job definitions (`adapter_id`, `entry_id`, `payload`, optional `delay`). |
| Filters: `sentient_forms_async_scheduler_group`, `sentient_forms_async_scheduler_group_label`, `sentient_forms_async_metadata_payload`, `sentient_forms_async_metadata_jobs` | Customize queue grouping and metadata retention.

## Admin notices & telemetry hooks

- `Sentient_Forms_Admin` now evaluates async health information (queue depth, recent failures, scheduler availability) on each wp-admin load. If the queue exceeds `sentient_forms_async_queue_threshold` (default 20), the Action Scheduler hooks are missing, or a single action fails more than `sentient_forms_async_failure_threshold` times inside the last hour, a dismissible wp-admin notice is rendered and the `sentient_forms_async_health_warning` hook runs with the warning payload.
- Dismissing a notice suppresses the same warning code for 24 hours (adjustable via `sentient_forms_async_notice_dismiss_ttl`).
- SPA `/settings` view mirrors the same warnings via the underlying REST/bootstrap payload, so headless and PHP surfaces stay in sync.
- Telemetry events (`async_job_success`, `async_job_failure`, `async_health_warning`) map to the schema in `contracts/v1/async-telemetry.json`. Events enqueue instantly but are sent out-of-band; use `wp sentient-forms async-requests list --status=telemetry_failed --record-type=telemetry` to inspect failures.
- The telemetry flush job now authenticates with the CPS proxy API key (Bearer token) before POSTing to `/v1/telemetry/async`. Ensure sites are fully activated; otherwise the queue remains in `telemetry_queued` status until a proxy key exists.

Refer to `includes/class-async-handler.php` and `includes/interfaces/interface-sentient-forms-async-capable-adapter.php` for the latest implementation details.
- **Persistent idempotency store** – every async job writes to `{$wpdb->prefix}sentient_async_requests` so retries across outages never double-bill CPS. The default retention is 24 hours; filter `sentient_forms_async_request_ttl` or update via the CLI purge command if you need a longer ledger.
