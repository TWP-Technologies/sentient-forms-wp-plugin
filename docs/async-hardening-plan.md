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
   - Document required methods (`finalize_async_success`, `finalize_async_error`, new `finalize_async_evaluation`).
   - Add PHP interface in `includes/adapters/` and update adapters to implement it.
   - Record how adapters must surface a stable `execution_request_id` for retries to align with CPS idempotency enforcement.
2. **Evaluation Dispatch**
   - Implement `dispatch_evaluation()` to enqueue evaluation jobs with dedicated hook (`sentient_forms_evaluate_action`).
   - Create handler mirroring execution flow but skipping CPS call if result cached.
3. **Retry & Backoff Policy**
   - Configure Action Scheduler jobs with explicit retry limit (e.g., 3 attempts) and exponential backoff.
   - Include job context metadata (`attempt`, `last_error`) for logging/reporting.
4. **Multi-Adapter Support**
   - Ensure registry resolves adapter by `form_source`; add defensive errors if adapter missing capabilities.
   - Add tests for non-Gravity adapters (use mocks/stubs until real adapters exist).
5. **Observability Enhancements**
   - Integrate with WordPress logging filters to emit structured entries.
   - Optionally fire custom actions (`sentient_forms_async_success`, `sentient_forms_async_failure`) for external observers.
   - Feed results into admin notices when repeated failures occur.
6. **Testing**
   - PHPUnit: execution + evaluation success/failure, retry exhaustion, adapter interface contract.
   - Integration smoke: run jobs in a test environment using Action Scheduler queue runner.
   - Manual QA: verify entries are not double-processed and logs contain expected metadata.

## Risks & Mitigations
- **Duplicate Execution**: ensure execution_request_id cached before enqueueing retries.
- **Scheduler Absence**: if Action Scheduler missing, fallback to WP-Cron should warn administrators; add admin notice.
- **Performance**: avoid synchronous CPS calls in cron context; ensure timeouts respected.
