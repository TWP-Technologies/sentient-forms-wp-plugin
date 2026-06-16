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
| Preserve `AI actions for Gravity Forms` positioning | MKT-009 | 0 | Short description and opening description remain unchanged. |
| Compress external-service copy | MKT-006 | -606 | Plain-language rewrite keeps service names, data categories, account requirements, terms/privacy links, telemetry limits, webhook responsibility, and Realtime Clarification disclosure. |
| Add this ledger | MKT-010 | 0 | Tracked outside `readme.txt`; no listing byte impact. |
| Add 10 KB preflight to release checklist | MKT-066 | 0 | Tracked outside `readme.txt`; no listing byte impact. |
| Add six WordPress.org screenshot captions | MKT-013 | +339 | Terse captions preserve the `9700` byte target while matching `screenshot-1.png` through `screenshot-6.png`. |
| Final current readme | MKT-010/MKT-013 | 9641 | `59` bytes under target, `359` bytes under hard maximum. |

## Required Checks

- Measure with UTF-8 byte count after any readme edit.
- Run `php scripts/validate-wporg-readme.php --offline`.
- Run package/source checks before any release, SVN dry run, or screenshot
  caption addition.
- Keep tags unchanged unless a separate keyword task explicitly approves a tag
  change.
