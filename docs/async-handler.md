# Sentient Forms Async Handler

_Last updated: 2025-11-16_

## Why it exists
Gravity Forms (and future adapters) need to run expensive CPS actions without blocking form submissions. The async handler wraps WordPress Action Scheduler (or core `wp-cron`) and exposes two entry points:

- **Execution jobs** (`sentient_forms_process_action`) – run CPS actions outside the request lifecycle.
- **Evaluation jobs** (`sentient_forms_evaluate_action`) – finalize asynchronous workflows (e.g., apply CPS results back to entries).

Both pathways share exponential backoff, adapter callbacks, and consent-aware logging.

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

## Adapter contract
Adapters must implement `Sentient_Forms_Async_Capable_Adapter_Interface` to participate:
- `finalize_async_success( $context, $result )`
- `finalize_async_error( $context, WP_Error $error )`
- `finalize_async_evaluation( $context, array $payload )`

The async handler resolves adapters via `Sentient_Forms_Form_Adapter_Registry`, so ensure your adapter is registered during plugin bootstrap.

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

## Hooks summary
| Hook | Purpose |
| --- | --- |
| `sentient_forms_process_action` | Action Scheduler entry point for execution jobs (arguments: action_id, data, settings, execution_request_id, context). |
| `sentient_forms_evaluate_action` | Action Scheduler entry point for evaluation jobs (argument: payload with normalized context). |
| `sentient_forms_async_success` | Fires after an async job succeeds (context + result). |
| `sentient_forms_async_failure` | Fires after all retries are exhausted (context + WP_Error). |
| `sentient_forms_async_event` | New structured event stream; see Telemetry & logging. |

Refer to `includes/class-async-handler.php` and `includes/interfaces/interface-sentient-forms-async-capable-adapter.php` for the latest implementation details.
