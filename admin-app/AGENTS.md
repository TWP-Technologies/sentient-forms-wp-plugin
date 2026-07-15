# Sentient Forms Admin App Instructions

This directory contains the Svelte 5 WordPress-admin SPA. Follow the parent `wp-plugin/AGENTS.md` rules plus these UI-specific standards.

## UI Standards

- Use Svelte 5 runes and callback props. Do not add `createEventDispatcher`, legacy `$:` reactive statements, or `on:event` syntax.
- Prefer shared admin-app components over one-off route markup when a pattern appears on more than one screen.
- Navigational controls that represent real destinations must render as anchors styled as buttons, not only `<button>` elements, so WebMasters can right-click, open in a new tab, and screen readers can identify links correctly.
- Prefer icons or SVGs for compact utility actions, table actions, and repeated controls when the action remains clear. Provide accessible labels and tooltips for icon-only controls.
- Keep text on primary, save, destructive, and otherwise high-consequence actions where clarity matters more than compactness.
- Add tooltips for terms a typical WordPress WebMaster could misunderstand without documentation, especially managed-service, model, scoring, webhook, self-improvement, and privacy settings.
- For tabular LLM output, clip long text in rows and provide a full-detail surface. Do not make prompts or model outputs artificially shorter just to fit a table.
- For admin overlays, account for the WordPress admin bar and use a z-index high enough to sit above wp-admin chrome.
- Visual changes require screenshot-driven iteration before claiming a UI path is greenlit.

## Security Practices

- JavaScript and TypeScript do not validate runtime payloads. Zod is required defense in depth for this admin app.
- Every REST response, imported/exported JSON document, persisted configuration value, migration payload, cache/session value, and unknown browser/runtime payload MUST be parsed by a named Zod schema before it reaches application state or rendering.
- Fetch unknown values through the shared transport and parse them through the endpoint-schema registry before caching. Invalid network or cached values must fail safely, and malformed cache entries must be evicted.
- Derive boundary types with `z.infer`; handwritten interfaces are allowed only for post-parse view models.
- Do not add generic `request<T>()`, `wpFetch<T>()`, `as T`, or equivalent trust casts at network/storage boundaries. A narrow exception must be documented inline and backed by a test demonstrating why no structured schema applies, such as a raw file download.
- Zod errors exposed to the UI or logs must contain safe endpoint/schema/issue metadata, never the raw sensitive payload.
