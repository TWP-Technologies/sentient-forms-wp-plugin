# WordPress.org Production Release Runbook

Production releases are cut from the `production` branch. The GitHub release package is a review-ready WordPress.org ZIP, but WordPress.org SVN/upload remains a manual approval step.

## Required Gates

Run these against the exact source and package artifact:

- `php scripts/validate-release-version.php`
- `php scripts/verify-wporg-source.php`
- `php scripts/scan-wporg-package.php --source-tree`
- `php scripts/audit-wporg-licenses.php`
- `php scripts/validate-wporg-readme.php --offline`
- `php scripts/build-wporg-package.php`
- package equivalents for scan, license audit, readme validation, and source verification
- Plugin Check in strict mode with `vendor` excluded
- exact-package browser/user-path dogfood before any submit recommendation

## Artifact Evidence

Each production package workflow must publish:

- `sentient-forms.zip`
- `sentient-forms.zip.sha256`
- `sentient-forms-wporg-manifest.json`
- the clean package directory used to create the ZIP

The manifest records the package path, ZIP hash, version, stable tag, source reference, Git ref, Git commit, and required gate status.

## Generated Asset Source

The package includes the exact SvelteKit admin app source under `admin-app`.
For WordPress.org-safe package paths, the source files are stored under
`admin-app/source` with a `source-map.json`; restore the original SvelteKit
route filenames before rebuilding:

```sh
cd admin-app
bun install --frozen-lockfile
bun run restore:source
bun run build:wp
```

Do not rely on private GitHub source links for WordPress.org review artifacts.
