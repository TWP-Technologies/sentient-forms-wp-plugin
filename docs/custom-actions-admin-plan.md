# Custom Actions Admin & Proxy Plan

## Objectives
- Surface CPS custom actions CRUD in WordPress via REST proxy + SvelteKit SPA.
- Maintain parity with CPS envelopes and error semantics.
- Provide administrators clear feedback on validation errors, quota limits, and audit state.

## Work Breakdown
1. **Contract Alignment**
   - Import CPS JSON schemas into shared `contracts/` package.
   - Generate PHP request/response validators (consider `wp_json_validate` fallback).
   - Update SPA TypeScript types using schema-derived definitions.
2. **REST Proxy Enhancements**
   - Extend `includes/rest-api/` controllers with routes:
     - `GET /sentient-forms/v1/custom-actions`
     - `POST /sentient-forms/v1/custom-actions`
     - `PUT /sentient-forms/v1/custom-actions/(?P<id>[a-f0-9-]{36})`
     - `DELETE /sentient-forms/v1/custom-actions/(?P<id>[a-f0-9-]{36})`
   - Reuse `Sentient_Forms_Api_Client` to call CPS; ensure bearer token handling and retries.
   - Map CPS error codes (`insufficient_credits`, `validation_failed`, etc.) to WP_Error instances with translated messages.
   - **Status:** `GET /actions/definitions` now proxies CPS `/v1/actions/templates`, merging local metadata only when CPS is unavailable.
3. **Admin SPA UX**
   - Add “Custom Actions” list & detail routes (`/actions/custom`).
   - Implement create/edit forms with client-side validation (code slug, display name length, override constraints).
   - Support optimistic updates with rollback on CPS failure; show audit trail modal (read-only to start).
   - Provide CTA for archiving/reactivating actions with confirmations.
4. **State Management**
   - Extend `formActions` store or add dedicated `customActions` module handling pagination, filters, and status toggles.
   - Normalize data using IDs and maintain derived selectors (active vs archived).
5. **Testing & QA**
   - Vitest unit tests for stores, form validation, and API client hooks.
   - Playwright flows: list view, create action, update, archive, failure handling.
   - PHP integration tests hitting REST proxy with mocked CPS responses (`tests/phpunit/test-custom-actions-rest.php`, `vendor/bin/phpunit --filter ActionDefinitionsControllerTest` with an available WordPress test database).
   - Update `scripts/run-full-qa.sh` documentation to mention new tests, no script changes required.

## Dependencies
- CPS custom actions endpoints + schema merged and deployed.
- WordPress site must have valid license + proxy API key for CPS calls.

## Open Questions
- Should WordPress cache custom actions locally or always hit CPS? Initial assumption: cache minimal metadata in transients for performance; revisit after MVP.
- Audit trail visibility: do we need a dedicated tab or is metadata in detail view sufficient?
- Access control: confirm capability requirements (`manage_options` vs custom capability).

## Action Mapping UX Snapshot (2025-11-13)
- The **Actions** tab now reflects the selected form source (Gravity Forms initially) and lists every detected form with badges that summarize mapping counts and Sentient Forms enablement.
- Each row links directly into `/actions/{sourceSlug}/{formId}` via hash-based routing so the SPA can operate safely inside the WordPress admin without rewriting the core URL.
- The form-level detail view shows the current credit balance, execution status, and linked CPS actions. Administrators can enable/disable mappings, refresh execution metadata, and inspect entry-specific telemetry.
- Trigger hook management is now interactive: clicking **Edit hooks** opens an inline editor with the allowed Gravity Forms hooks (validation vs. after submission). Changes persist via the Form Actions REST controller and mirror immediately in the status panel.
- All new flows rely on the normalized REST payloads (`credits/balance`, action definitions, form actions) so the Vitest/unit suites can assert real API behavior.
