<?php
/**
 * Validate CI workflow path filters for known high-cost workflows.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$plugin_root = dirname( __DIR__ );
$workflow    = $plugin_root . '/.github/workflows/release-please.yml';

$issues = [];

if ( ! file_exists( $workflow ) )
{
    $issues[] = 'Missing .github/workflows/release-please.yml.';
}
else
{
    $paths = sentient_forms_workflow_push_paths( $workflow );
    if ( [] === $paths )
    {
        $issues[] = 'release-please.yml push trigger must declare paths for release-relevant files.';
    }
    else
    {
        $scenarios = [
            'docs-only changes skip Release Please' => [
                'files' => [ 'docs/usage.md', 'README.md' ],
                'runs'  => false,
            ],
            'agent metadata changes skip Release Please' => [
                'files' => [ 'AGENTS.md', 'agent-logs/session.log' ],
                'runs'  => false,
            ],
            'runtime source changes run Release Please' => [
                'files' => [ 'includes/rest-api/controllers/class-form-actions-controller.php' ],
                'runs'  => true,
            ],
            'admin app source changes run Release Please' => [
                'files' => [ 'admin-app/src/routes/(app)/actions/custom/new/+page.svelte' ],
                'runs'  => true,
            ],
            'release workflow changes run Release Please' => [
                'files' => [ '.github/workflows/release-please.yml' ],
                'runs'  => true,
            ],
            'release manifests run Release Please' => [
                'files' => [ '.release-please-manifest.json', 'CHANGELOG.md' ],
                'runs'  => true,
            ],
            'package scripts run Release Please' => [
                'files' => [ 'scripts/build-wporg-package.php' ],
                'runs'  => true,
            ],
            'lockfile changes run Release Please' => [
                'files' => [ 'composer.lock' ],
                'runs'  => true,
            ],
        ];

        foreach ( $scenarios as $label => $scenario )
        {
            $runs = sentient_forms_workflow_paths_match_any( $paths, $scenario['files'] );
            if ( $runs !== $scenario['runs'] )
            {
                $expectation = $scenario['runs'] ? 'run' : 'skip';
                $issues[]    = "{$label}: expected {$expectation}; files=" . implode( ', ', $scenario['files'] );
            }
        }
    }
}

if ( [] !== $issues )
{
    echo "Sentient Forms workflow path filter validation failed:\n";
    foreach ( $issues as $issue )
    {
        echo "- {$issue}\n";
    }
    exit( 1 );
}

echo "Sentient Forms workflow path filter validation passed.\n";

/**
 * Return the path include patterns configured on the release workflow push event.
 *
 * @return array<int,string>
 */
function sentient_forms_workflow_push_paths( string $workflow ): array
{
    $lines     = file( $workflow, FILE_IGNORE_NEW_LINES );
    $push_body = sentient_forms_indented_child_block( is_array( $lines ) ? $lines : [], 2, 'push' );
    if ( [] === $push_body )
    {
        return [];
    }

    $paths_body = sentient_forms_indented_child_block( $push_body, 4, 'paths' );
    if ( [] === $paths_body )
    {
        return [];
    }

    $patterns = [];
    foreach ( $paths_body as $line )
    {
        if ( ! preg_match( '/^\s{6}-\s+(.+)$/', $line, $matches ) )
        {
            continue;
        }

        $patterns[] = trim( trim( $matches[1] ), "'\"" );
    }

    return array_values( array_filter( $patterns, static fn ( string $pattern ): bool => '' !== $pattern ) );
}

/**
 * Extract a YAML child block by indentation and key name.
 *
 * @param array<int,string> $lines
 *
 * @return array<int,string>
 */
function sentient_forms_indented_child_block( array $lines, int $indent, string $key ): array
{
    $start = null;
    $key_pattern = '/^\s{' . $indent . '}' . preg_quote( $key, '/' ) . ':\s*$/';
    foreach ( $lines as $index => $line )
    {
        if ( preg_match( $key_pattern, $line ) )
        {
            $start = $index + 1;
            break;
        }
    }

    if ( null === $start )
    {
        return [];
    }

    $block = [];
    for ( $index = $start, $count = count( $lines ); $index < $count; $index++ )
    {
        $line = $lines[ $index ];
        if ( '' !== trim( $line ) && preg_match( '/^\s{0,' . $indent . '}\S/', $line ) )
        {
            break;
        }

        $block[] = $line;
    }

    return $block;
}

/**
 * Return whether any changed file matches the configured path include patterns.
 *
 * @param array<int,string> $patterns
 * @param array<int,string> $files
 */
function sentient_forms_workflow_paths_match_any( array $patterns, array $files ): bool
{
    foreach ( $files as $file )
    {
        foreach ( $patterns as $pattern )
        {
            if ( sentient_forms_workflow_path_matches( $pattern, $file ) )
            {
                return true;
            }
        }
    }

    return false;
}

function sentient_forms_workflow_path_matches( string $pattern, string $file ): bool
{
    $pattern = trim( $pattern );
    if ( '' === $pattern || str_starts_with( $pattern, '!' ) )
    {
        return false;
    }

    $regex = preg_quote( str_replace( '\\', '/', $pattern ), '/' );
    $regex = str_replace( '\*\*', '.*', $regex );
    $regex = str_replace( '\*', '[^/]*', $regex );

    return 1 === preg_match( '/^' . $regex . '$/', str_replace( '\\', '/', $file ) );
}
