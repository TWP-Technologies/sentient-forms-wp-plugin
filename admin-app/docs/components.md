# UI Component Guidelines

The admin SPA exposes a set of typed, Tailwind-powered primitives under `$lib/components/ui`. Use these building blocks to keep styling consistent.

## Imports

```svelte
<script lang="ts">
  import { Button, Card, Section, Badge, Alert, Skeleton } from '$lib/components/ui';
</script>
```

## Button

- Variants: `primary` (default), `secondary`, `ghost`, `danger`.
- Sizes: `sm`, `md`, `lg`.
- Props: `loading`, `iconOnly` toggle aria/busy state and layout.

```svelte
<Button variant="secondary" size="sm">Refresh</Button>
```

## Card

Wrap content in a neutral container. Optional `title` / `subtitle` props render a header, or supply `slot="action"` for buttons.

```svelte
<Card title="License" subtitle="Sentient Forms Pro">
  <p>Content...</p>
</Card>
```

## Section

Standard page wrapper with heading/description and an `actions` slot.

```svelte
<Section heading="Dashboard" description="Overview">
  <Button slot="actions">Refresh</Button>
  <!-- page content -->
</Section>
```

## Badge & Alert

- `Badge` variants: `neutral`, `success`, `warning`, `danger`, `info`.
- `Alert` variants: `info`, `success`, `warning`, `danger` and accept additional classes.

## Skeleton

Placeholder element for loading states.

```svelte
<Skeleton className="sf:h-4 sf:w-32" />
```

## Form Fields

Use the typed form primitives whenever possible to ensure labels, help text, and error messaging remain accessible.

- `InputField`: text, email, password, number, etc.
- `SelectField`: dropdowns with placeholder support.
- `TextareaField`: multi-line inputs with auto-sized rows.
- `FormField`: low-level wrapper if you need to compose custom inputs.

```svelte
<InputField
  id="license"
  bind:value={licenseKey}
  label="License key"
  help="Find this in your Sentient Forms receipt"
  error={licenseError}
  required
/>
```
- Read-only values (such as the detected Site URL) can be passed via the `value` prop with `disabled` set to `true` to mirror the activation screen.

## Toggles

Use `Toggle` components for boolean settings; they emit a `change` event with `{ checked }`.

```svelte
<Toggle
  id="telemetry"
  bind:checked={telemetryEnabled}
  label="Send telemetry"
  description="Improve the product by sharing anonymized metrics."
/>\n```\n\n## Validation Summary\n\nSurface form-level validation with `ValidationSummary` and link errors back to their fields.\n\n```svelte\n<ValidationSummary\n  issues={[\n    { id: 'license', message: 'Enter your license key' },\n    { message: 'Agree to the terms of service' }\n  ]}\n/>\n```\n\n## Notifications

Use `notifications` store (`$lib/stores/notifications`) to surface transient messages:

```ts
import { notifications } from '$lib/stores/notifications';
notifications.success('Settings saved');
```

Remember to keep any new classes prefixed with `sf-` and prefer the shared utilities before introducing custom patterns. For the full palette and spacing scale, reference [`design-tokens.md`](./design-tokens.md) before adding bespoke values.
