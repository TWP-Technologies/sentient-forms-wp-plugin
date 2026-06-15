# WordPress.org Production Release Runbook

Production releases are cut from the `production` branch. The GitHub release package is a review-ready WordPress.org ZIP, but WordPress.org SVN/upload remains a manual approval step.

## WordPress.org SVN Publish

After WordPress.org approval, publish through the manual-only GitHub Actions workflow `WordPress.org SVN Publish`.

1. Run the workflow with `mode=dry-run` and the target tag, such as `v0.3.11`.
2. Review the uploaded SVN evidence artifact, especially `svn-status.txt` and `svn-diff.patch`.
3. Re-run the workflow with `mode=publish` and `confirm=sentient-forms/<version>`.
4. Approve the protected `wporg-svn-production` environment only after the dry-run diff is correct.

The workflow downloads the existing GitHub release ZIP, SHA256, and manifest. It does not rebuild the package during SVN publication. It commits plugin code only under `trunk/` and `tags/<version>/`.

WordPress.org listing media is intentionally separate from code publication. Do not use this workflow to publish top-level SVN `assets/`. When listing media is added, use the Sentient Forms logomark only for the plugin icon/listing picture.

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

The manifest records the package path, ZIP hash, version, stable tag, public source URL, Git ref, Git commit, and required gate status.

## Generated Asset Source

The package does not include the SvelteKit admin app source or build tooling.
Generated JavaScript and CSS in `assets/dist` must point to the immutable public
GitHub source tag for the same release. From that public source tag, rebuild the
assets with:

```sh
cd admin-app
bun install --frozen-lockfile
bun run build:wp
```

Do not upload the WordPress.org ZIP until the source tag referenced in
`sentient-forms.php`, `readme.txt`, and `assets/dist/SOURCE.md` is publicly
reachable.
