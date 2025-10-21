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
<Skeleton className="sf-h-4 sf-w-32" />
```

## Notifications

Use `notifications` store (`$lib/stores/notifications`) to surface transient messages:

```ts
import { notifications } from '$lib/stores/notifications';
notifications.success('Settings saved');
```

Remember to keep any new classes prefixed with `sf-` and prefer the shared utilities before introducing custom patterns.
