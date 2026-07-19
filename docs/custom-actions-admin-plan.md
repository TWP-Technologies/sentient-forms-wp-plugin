# Custom Actions Local Authority

## Current contract

- WordPress owns custom Action definitions, configuration, form mappings, lifecycle eligibility, prompt construction, output schemas, and local results.
- The plugin's local repositories and `/sentient-forms/v1/custom-actions` REST routes are the executable authority for custom Actions.
- CPS receives an opaque `action_code` only when the selected provider route requires managed execution. It does not define, list, mutate, or synchronize WordPress custom Actions.
- CPS-owned JSON Schemas govern retained managed-service `/v2` requests and responses. They do not replace the plugin Action Catalog.

## Admin behavior

- The Custom Actions screen lists, creates, edits, archives, and reactivates definitions stored in WordPress.
- Client and server validation keep Action codes, display names, prompts, model selection, output schemas, and status changes within the plugin contract.
- Form mappings link local definitions to supported Form Sources and lifecycle hooks.
- Managed subscription and credit state affects entitlement and provider routing; it does not move Action-definition ownership into CPS.

## Assurance

- PHP tests cover local repositories and REST authorization, validation, persistence, and error envelopes.
- Svelte and Playwright tests cover list, create, update, archive, reactivation, invalid data, and unavailable-endpoint states.
- Cross-repository concordance checks verify product-intent references, plugin definitions, facet overlays, source compatibility, and CPS `/v2` schemas.

## Historical note

Early versions planned CPS CRUD proxies and remote template synchronization. That design was retired when the plugin became the local control plane. New work must not restore `/v1` CPS Action-template, custom-Action, mapping, or execution dependencies.
