# Design Tokens

This reference documents the canonical Tailwind tokens used by the Sentient Forms admin SPA. Every visual decision should map to one of the entries below; introduce new tokens only after design review.

## Color Palette (`theme.extend.colors`)

| Token | Shades | Intended Usage |
| --- | --- | --- |
| `primary` | `50`, `100`, `500`, `600`, `700` | Primary CTA buttons, focused states, key links |
| `success` | `50`, `500`, `600` | Positive indicators, activation success banners |
| `warning` | `50`, `500`, `600` | Non-blocking alerts, quota nearing limits |
| `danger` | `50`, `500`, `600` | Destructive buttons, failure alerts |
| `muted` | `100`, `200`, `400`, `500` | Layout chrome, borders, subdued text |

Usage guidance:
- Prefer semantic utilities (`sf:bg-primary-500`) and avoid literal hex values in components.
- Combine with opacity utilities (`sf:bg-primary-500/90`) rather than defining duplicate shades.

## Typography

- Font family `sf:font-sans` maps to `Inter` with the Tailwind default fallbacks.
- Use `sf:text-sm`, `sf:text-base`, or `sf:text-lg` with corresponding `sf:font-medium` or `sf:font-semibold` for hierarchy.
- Headings in Section/Card components provide built-in sizing—reuse them where possible.

## Shadows & Radii

| Token | Value | Use |
| --- | --- | --- |
| `sf:shadow-card` | `0 1px 2px 0 rgba(15, 23, 42, 0.08)` | Card container elevation |
| `sf:shadow-inset-card` | `inset 0 1px 2px rgba(15, 23, 42, 0.06)` | Panels that should appear recessed |
| `sf:rounded-lg` | `0.75rem` | Default component corners |

## Spacing Scale Additions

- `sf:p-18` / `sf:m-18` (`4.5rem`) for wide gutters in page layouts

## Introducing or Updating Tokens

1. Open a design review issue describing the new token, the use cases, and why existing tokens do not suffice.
2. Update `tailwind.config.cjs` and regenerate this document with the new entry.
3. Provide example screenshots showing the before/after impact.
4. Run `bun run tailwind:check` to ensure no raw classes slipped in, and add/update unit tests where relevant.

## Quick Checklist

- [ ] Token exists in `tailwind.config.cjs`.
- [ ] Documentation in this file updated.
- [ ] Screenshots or mocks attached to PR.
- [ ] Bundle/a11y budgets still pass (`bun run qa:full`).
- [ ] Reviewer sign-off from design owner.
