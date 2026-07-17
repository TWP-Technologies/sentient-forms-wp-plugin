# WordPress.org Readme Byte-Budget Ledger

This ledger covers the marketing/compliance bundle for `MKT-010`, `MKT-009`,
`MKT-006`, and `MKT-066`.

## Budget Rules

- Target maximum: `9700` UTF-8 bytes.
- Hard maximum: `10000` UTF-8 bytes.
- Update this ledger before adding screenshots, FAQ items, expanded action copy,
  or other `readme.txt` content.
- If a proposed edit would exceed `9700` bytes, remove or replace weaker copy in
  the same change. Do not rely on the hard maximum as ordinary working room.

## Current Ledger

| Item | MKT ID | Readme byte impact | Notes |
|---|---:|---:|---|
| Baseline after strict fast-lane copy | MKT-001/MKT-002 | 9908 | Existing `production` readme before this bundle. |
| Expand support positioning to Gravity Forms, Contact Form 7, and WPForms | WPORG-0.6.1 | +36 | Short description, opening copy, FAQ, install steps, screenshots, and service disclosures now reflect the three supported form sources while pruning stale readme changelog entries. |
| Replace plugin-name tag with generic automation tag | WPORG-0.6.1 | 0 | The support-copy task approved a keyword refresh; WordPress.org readme rules disallow competitor plugin names as tags. |
| Compress external-service copy | MKT-006 | -606 | Plain-language rewrite keeps service names, data categories, account requirements, terms/privacy links, telemetry limits, webhook responsibility, and Realtime Clarification disclosure. |
| Add this ledger | MKT-010 | 0 | Tracked outside `readme.txt`; no listing byte impact. |
| Add 10 KB preflight to release checklist | MKT-066 | 0 | Tracked outside `readme.txt`; no listing byte impact. |
| Add six WordPress.org screenshot captions | MKT-013 | +339 | Terse captions preserve the `9700` byte target while matching `screenshot-1.png` through `screenshot-6.png`. |
| Final 0.11.0 readme | MKT-010/MKT-013/WPORG-0.6.1 | 9682 | Release reconciliation keeps the listing `18` bytes under target and `318` bytes under the hard maximum. |

## Required Checks

- Measure with UTF-8 byte count after any readme edit.
- Run `php scripts/validate-wporg-readme.php --offline`.
- Run package/source checks before any release, SVN dry run, or screenshot
  caption addition.
- Keep tags unchanged unless a separate keyword task explicitly approves a tag
  change.
