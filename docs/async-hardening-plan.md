# Async Handler Hardening Plan

## Current State Summary
- Gravity Forms adapter dispatches async jobs via Action Scheduler or WP-Cron fallback.
- `dispatch_evaluation()` is unimplemented; evaluation flow missing.
- Logging limited to `error_log` under `WP_DEBUG`.
- No retry/backoff configuration; relies on Action Scheduler defaults.
- Multi-adapter support unverified.

## Target Outcomes
1. Reliable async execution with idempotent retries.
2. Evaluation flow available for post-processing actions.
3. Adapter interface documented and enforced across integrations.
4. Observability hooks for success/failure metrics.

## Implementation Steps
1. **Adapter Contract Definition**
   - Document required methods (`finalize_async_success`, `finalize_async_error`, `finalize_async_evaluation`) and ship a dedicated interface under `includes/interfaces/`.
   - Require adapters that opt into async flows to implement the interface and ensure they expose an adapter slug so the async handler can resolve them at runtime.
   - Record how adapters must surface a stable `execution_request_id` for retries to align with CPS idempotency enforcement.
2. **Evaluation Dispatch**
   - Implement `Sentient_Forms_Async_Handler::dispatch_evaluation()` to enqueue evaluation jobs with the dedicated hook `sentient_forms_evaluate_action`.
   - Provide a `Sentient_Forms_Plugin::dispatch_action_evaluation()` helper so adapters/actions can queue evaluation/cleanup work after CPS responses without blocking submission threads.
   - Ensure evaluation jobs carry entry/form context plus the CPS response payload rather than recalling the CPS endpoint.
3. **Retry & Backoff Policy**
   - Configure Action Scheduler/WP-Cron jobs with an explicit retry limit (default 3 attempts) and exponential backoff (e.g., 60s, 180s, 540s).
   - Include job context metadata (`attempt`, `max_attempts`, `last_error`) so logging hooks and the SPA can reflect retry state.
   - When retries exhaust, mark the job as failed, fire the failure hook, and surface admin notices.
4. **Multi-Adapter Support**
   - Ensure the async handler resolves adapters via `form_source`; surface actionable errors if an adapter declines async responsibilities.
   - Add PHPUnit coverage for the new interface and registry lookups (use mocks/stubs until additional adapters land).
5. **Observability Enhancements**
   - Integrate with WordPress logging filters to emit structured entries for success/failure and expose `do_action( 'sentient_forms_async_success|failure' )`.
   - Feed consecutive-failure state into admin notices and, eventually, the SPA.
6. **Testing**
   - PHPUnit: execution + evaluation success/failure, retry exhaustion, adapter interface contract.
   - Integration smoke: run jobs in a test environment using Action Scheduler queue runner.
   - Manual QA: verify entries are not double-processed and logs contain expected metadata; confirm admin notices fire on repeated failures.

## Risks & Mitigations
- **Duplicate Execution**: ensure execution_request_id cached before enqueueing retries.
- **Scheduler Absence**: if Action Scheduler missing, fallback to WP-Cron should warn administrators; add admin notice.
- **Performance**: avoid synchronous CPS calls in cron context; ensure timeouts respected.
