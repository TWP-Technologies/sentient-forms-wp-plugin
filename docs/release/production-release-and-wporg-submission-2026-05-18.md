# Production Release and WordPress.org Submission Path

## Launch Posture

Sentient Forms launches as a Gravity Forms v1 plugin with a functional local OpenRouter path and optional public Sentient managed execution. The WordPress.org listing must not be submitted until the managed service is live enough for the readme and screenshots to be true.

Only a human may submit the plugin to WordPress.org. Automation prepares the package, GitHub release, evidence, screenshots, and SVN publish workflow, but it does not press submit.

## Release Authority

The `master` branch is the live source branch, but an ordinary push to `master` is not a production release. Release Please owns version calculation and release PR creation. The release workflow creates the GitHub tag/release only after the release version surfaces agree, the admin assets build, the WordPress.org package validates, and the release zip plus SHA256 are ready to attach.

Release automation requires a GitHub App installation token, not the default `GITHUB_TOKEN`, because Release Please-generated PRs must trigger the version-sync and validation workflows. Configure repository variables `RELEASE_BOT_CLIENT_ID` and `RELEASE_BOT_LOGIN` plus repository secret `RELEASE_BOT_PRIVATE_KEY`; the workflows fail closed when any value is missing or the release PR author is not the expected bot.

The initial Release Please baseline is anchored to `66de36b17d14a26b97693cbbede72e0982d88b4d` at version `0.1.0`. Do not remove that bootstrap SHA unless a matching `v0.1.0` release/tag exists or Release Please has already created a later release PR.

Required flow:

1. Merge staging-ready work into `master`.
2. Release Please opens or updates a release PR from Conventional Commits.
3. The Release PR Sync workflow updates WordPress-specific version surfaces and rebuilt assets in that PR.
4. CI, Plugin Check, package scans, and security checks pass on the release PR.
5. A human merges the release PR.
6. The release workflow validates and packages the merged release state.
7. The release workflow creates the immutable tag and GitHub release with the WordPress.org zip plus SHA256 attached.

The WordPress.org SVN publish remains a separate manually approved workflow after the plugin is accepted by WordPress.org, because SVN pushes immediately affect the public plugin directory.

## WordPress.org Gate

Do not submit until all of these are true:

- `TWP-Technologies/sentient-forms-wp-plugin` is public and contains the source for the minified admin assets linked from `readme.txt`.
- `https://sentientforms.com/terms` and `https://sentientforms.com/privacy` return public 200 responses and match the plugin external-service disclosures.
- `https://api.sentientforms.com/v1/health` and `/v2/health` return public success responses.
- Production managed checkout, webhook handling, license/bootstrap, managed execution, metering, reconcile dry-run, and monitoring have production evidence.
- Plugin Check, package scan, license audit, readme validation, REST/security matrix, Browser MCP packaged-plugin dogfood, and one final remote review-bot pass all pass or have explicitly accepted findings.
- Screenshots show the actual packaged plugin for Gravity Forms setup, provider setup, managed billing, execution logs/results, and settings/privacy.

## Branch Protection Footnote

GitHub currently reports branch protection unavailable for the private repository state/account plan. Once the WP plugin repository is public, enable branch protection on `master` with required CI/package checks, required review, no force pushes, and no branch deletion. If signed-commit enforcement blocks release-bot commits, prefer a GitHub App/bot signing path over weakening human commit-signing expectations.
