# Contributing to Sentient Forms

Thanks for helping improve Sentient Forms. This repository contains the WordPress
plugin source, the Svelte admin app source, package tooling, and tests.

## Development Setup

- Install PHP dependencies with `composer install`.
- Install admin app dependencies from `admin-app/` with `bun install --frozen-lockfile`.
- Build WordPress admin assets from `admin-app/` with `bun run build:wp`.

## Local Checks

Run the narrowest checks that match your change, then run the package checks
before release-facing changes:

- PHP style: `composer phpcs`
- WordPress.org source scan: `composer wporg-scan`
- Package build: `composer wporg-build-package`
- Admin lint/type/test: run `bun run lint`, `bun run check`, and `bun run test` from `admin-app/`
- Full admin QA when UI behavior changes: `bun run qa:full` from `admin-app/`

## Pull Requests

- Keep PRs focused and explain the user-visible behavior change.
- Include the exact checks you ran.
- Include screenshots for admin UI changes.
- Do not commit secrets, local `.env` files, screenshots, temporary captures,
  generated test reports, or agent/session logs.
- For release-facing changes, confirm `readme.txt`, plugin version metadata,
  source metadata for generated assets, and WordPress.org package scans remain
  aligned.

## License

By contributing, you agree that your contribution is licensed under the same
GPL-2.0-or-later license as Sentient Forms.
