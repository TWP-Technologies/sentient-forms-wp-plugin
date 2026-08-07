<?php
/**
 * Validate Release Please workflow invariants that protect packaged releases.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$plugin_root = dirname( __DIR__ );
$workflow         = $plugin_root . '/.github/workflows/release-please.yml';
$sync_workflow    = $plugin_root . '/.github/workflows/release-pr-sync.yml';
$package_workflow = $plugin_root . '/.github/workflows/wporg-package.yml';
$issues           = [];

if ( ! file_exists( $workflow ) )
{
    $issues[] = 'Missing .github/workflows/release-please.yml.';
}
else
{
    $contents = file_get_contents( $workflow );
    if ( ! is_string( $contents ) || '' === trim( $contents ) )
    {
        $issues[] = 'release-please.yml is empty or unreadable.';
    }
    else
    {
        sentient_forms_validate_release_workflow( $contents, $issues );
    }
}

if ( ! file_exists( $sync_workflow ) )
{
    $issues[] = 'Missing .github/workflows/release-pr-sync.yml.';
}
else
{
    $sync_contents = file_get_contents( $sync_workflow );
    if ( ! is_string( $sync_contents ) || '' === trim( $sync_contents ) )
    {
        $issues[] = 'release-pr-sync.yml is empty or unreadable.';
    }
    else
    {
        sentient_forms_validate_release_pr_sync_workflow( $sync_contents, $issues );
    }
}

if ( ! file_exists( $package_workflow ) )
{
    $issues[] = 'Missing .github/workflows/wporg-package.yml.';
}
else
{
    $package_contents = file_get_contents( $package_workflow );
    if ( ! is_string( $package_contents ) || '' === trim( $package_contents ) )
    {
        $issues[] = 'wporg-package.yml is empty or unreadable.';
    }
    else
    {
        sentient_forms_validate_reviewed_package_inputs( $package_contents, 'wporg-package.yml', $issues );
    }
}

if ( [] !== $issues )
{
    echo "Sentient Forms release workflow validation failed:\n";
    foreach ( $issues as $issue )
    {
        echo "- {$issue}\n";
    }
    exit( 1 );
}

echo "Sentient Forms release workflow validation passed.\n";

/**
 * Validate the packaged-release workflow can complete the Release Please label lifecycle.
 *
 * @param array<int,string> $issues
 */
function sentient_forms_validate_release_workflow( string $contents, array &$issues ): void
{
    sentient_forms_validate_reviewed_package_inputs( $contents, 'release-please.yml', $issues );

    if ( false === strpos( $contents, 'skip-github-release: true' ) )
    {
        $issues[] = 'Release Please must keep skip-github-release: true so the package job owns release assets.';
    }

    if ( false === strpos( $contents, 'Create GitHub release with package assets' ) )
    {
        $issues[] = 'release-please.yml must create the packaged GitHub release after package preflight.';
    }

    if ( false === strpos( $contents, 'Mark Release Please PR tagged' ) )
    {
        $issues[] = 'release-please.yml must mark the merged Release Please PR as tagged after package release success.';
    }

    $required_fragments = [
        'repos/${GITHUB_REPOSITORY}/commits/${GITHUB_SHA}/pulls' => 'resolve the release PR from the release commit',
        'autorelease: pending'                                  => 'remove the pending Release Please label',
        'autorelease: tagged'                                   => 'add the tagged Release Please label',
        'gh issue edit'                                         => 'edit labels through the issue API used by GitHub PR labels',
        'steps.release-state.outputs.publish == \'true\''       => 'run label reconciliation only for publishing release commits',
    ];

    foreach ( $required_fragments as $fragment => $description )
    {
        if ( false === strpos( $contents, $fragment ) )
        {
            $issues[] = "release-please.yml must {$description}.";
        }
    }

    $label_step_position = strpos( $contents, 'Mark Release Please PR tagged' );
    $upload_position     = strpos( $contents, 'Upload release package assets' );
    $create_position     = strpos( $contents, 'Create GitHub release with package assets' );
    $artifact_position   = strpos( $contents, 'Upload package artifact' );

    if (
        false !== $label_step_position
        && false !== $upload_position
        && false !== $create_position
        && false !== $artifact_position
        && ( $label_step_position < $upload_position || $label_step_position < $create_position || $label_step_position > $artifact_position )
    )
    {
        $issues[] = 'Release Please PR label reconciliation must run after release asset upload/create steps and before artifact upload.';
    }
}

/**
 * Ensure release packages use only inputs committed to the reviewed source tree.
 *
 * @param array<int,string> $issues
 */
function sentient_forms_validate_reviewed_package_inputs( string $contents, string $workflow_name, array &$issues ): void
{
    if ( false !== strpos( $contents, 'composer openrouter-model-snapshot' ) )
    {
        $issues[] = "{$workflow_name} must not refresh the OpenRouter model snapshot while packaging a release.";
    }

    if ( false !== strpos( $contents, 'bun run build:wp' ) )
    {
        $issues[] = "{$workflow_name} must package the reviewed admin assets instead of rebuilding them.";
    }

    if (
        false !== strpos( $contents, 'bun install --frozen-lockfile' )
        && false === strpos( $contents, 'bun run generated:freshness:check' )
    )
    {
        $issues[] = "{$workflow_name} may install admin dependencies only when verifying generated asset freshness.";
    }
}

/**
 * Validate Release PR Sync only runs for exact Release Please branches.
 *
 * @param array<int,string> $issues
 */
function sentient_forms_validate_release_pr_sync_workflow( string $contents, array &$issues ): void
{
    if ( false === strpos( $contents, 'bun run build:wp' ) )
    {
        $issues[] = 'release-pr-sync.yml must rebuild and commit the reviewed admin assets on the release branch.';
    }

    if ( false !== strpos( $contents, "contains(github.head_ref, 'release-please')" ) )
    {
        $issues[] = 'release-pr-sync.yml must not use broad release-please substring matching for job gating.';
    }

    if ( false === strpos( $contents, "github.head_ref == 'release-please--branches--production'" ) )
    {
        $issues[] = 'release-pr-sync.yml must explicitly allow the exact Release Please production branch name.';
    }

    if ( false === strpos( $contents, "startsWith(github.head_ref, 'release-please--branches--production--components--')" ) )
    {
        $issues[] = 'release-pr-sync.yml must explicitly allow Release Please production component branch names.';
    }

    if ( false === strpos( $contents, "github.head_ref == 'release-please/branches/production'" ) )
    {
        $issues[] = 'release-pr-sync.yml must explicitly allow the exact Release Please slash-style production branch name.';
    }

    if ( false === strpos( $contents, "startsWith(github.head_ref, 'release-please/branches/production/components/')" ) )
    {
        $issues[] = 'release-pr-sync.yml must explicitly allow Release Please slash-style production component branch names.';
    }
}
