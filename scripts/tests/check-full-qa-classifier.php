<?php

/**
 * Exercise the Full QA change classifier through its CLI boundary.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );
$classifier  = $plugin_root . '/scripts/classify-full-qa-changes.php';
$errors      = [];

if ( ! is_file( $classifier ) )
{
    fwrite( STDERR, "Full QA classifier test failed:\n- Missing scripts/classify-full-qa-changes.php.\n" );
    exit( 1 );
}

$scenarios = [
    'root documentation'       => [ [ 'README.md' ], false ],
    'nested documentation'     => [ [ 'docs/architecture/runtime.md' ], false ],
    'agent guidance'           => [ [ 'admin-app/src/AGENTS.md' ], false ],
    'session log'              => [ [ 'agent-logs/session.md' ], false ],
    'PHP source'               => [ [ 'includes/class-sentient-forms-plugin.php' ], true ],
    'admin source'             => [ [ 'admin-app/src/lib/api/client.ts' ], true ],
    'contract snapshot'        => [ [ 'contracts/action-facet-policy-catalog.v1.json' ], true ],
    'test fixture'             => [ [ 'tests/fixtures/runtime.json' ], true ],
    'package builder'          => [ [ 'scripts/build-wporg-package.php' ], true ],
    'workflow'                 => [ [ '.github/workflows/full-qa.yml' ], true ],
    'packaged Markdown'        => [ [ 'assets/dist/SOURCE.md' ], true ],
    'mixed documentation/code' => [ [ 'README.md', 'sentient-forms.php' ], true ],
    'Windows path separators'  => [ [ 'contracts\\action-source-compatibility.v1.json' ], true ],
];

foreach ( $scenarios as $label => [ $paths, $expected_full_qa ] )
{
    $result = run_classifier( $classifier, $paths );
    if ( 0 !== $result['status'] )
    {
        $errors[] = "{$label}: classifier failed: " . trim( $result['stderr'] );
        continue;
    }

    $expected = $expected_full_qa ? 'full_qa' : 'docs_only';
    if ( ( $result['outputs']['classification'] ?? null ) !== $expected )
    {
        $errors[] = "{$label}: expected classification={$expected}.";
    }
    if ( ( $result['outputs']['full_qa'] ?? null ) !== ( $expected_full_qa ? 'true' : 'false' ) )
    {
        $errors[] = "{$label}: unexpected full_qa output.";
    }
}

$empty = run_classifier( $classifier, [] );
if ( 0 !== $empty['status'] || 'full_qa' !== ( $empty['outputs']['classification'] ?? null ) )
{
    $errors[] = 'An empty change set must fail closed to Full QA.';
}

$forced = run_classifier( $classifier, [ 'README.md' ], true );
if ( 0 !== $forced['status'] || 'full_qa' !== ( $forced['outputs']['classification'] ?? null ) )
{
    $errors[] = 'A forced run must require Full QA.';
}

foreach ( [ '../outside.php', '/absolute.php', 'C:\\absolute.php' ] as $unsafe_path )
{
    $unsafe = run_classifier( $classifier, [ $unsafe_path ] );
    if ( 0 === $unsafe['status'] )
    {
        $errors[] = "Unsafe changed path was accepted: {$unsafe_path}.";
    }
}

if ( [] !== $errors )
{
    fwrite( STDERR, "Full QA classifier test failed:\n" );
    foreach ( $errors as $error )
    {
        fwrite( STDERR, "- {$error}\n" );
    }
    exit( 1 );
}

echo sprintf( "Full QA classifier test passed: %d path classes plus fail-closed cases.\n", count( $scenarios ) );

/**
 * @param list<string> $paths
 * @return array{status:int,outputs:array<string,string>,stderr:string}
 */
function run_classifier( string $classifier, array $paths, bool $force = false ): array
{
    $command = [ PHP_BINARY, $classifier ];
    if ( $force )
    {
        $command[] = '--force';
    }

    $process = proc_open(
        $command,
        [
            0 => [ 'pipe', 'r' ],
            1 => [ 'pipe', 'w' ],
            2 => [ 'pipe', 'w' ],
        ],
        $pipes
    );
    if ( ! is_resource( $process ) )
    {
        return [ 'status' => 1, 'outputs' => [], 'stderr' => 'Unable to start classifier.' ];
    }

    fwrite( $pipes[0], implode( "\n", $paths ) . ( [] === $paths ? '' : "\n" ) );
    fclose( $pipes[0] );
    $stdout = (string) stream_get_contents( $pipes[1] );
    fclose( $pipes[1] );
    $stderr = (string) stream_get_contents( $pipes[2] );
    fclose( $pipes[2] );
    $status = proc_close( $process );
    $outputs = [];
    foreach ( preg_split( '/\r?\n/', trim( $stdout ) ) ?: [] as $line )
    {
        if ( preg_match( '/^([a-z_]+)=(.*)$/', $line, $matches ) )
        {
            $outputs[ $matches[1] ] = $matches[2];
        }
    }

    return [ 'status' => $status, 'outputs' => $outputs, 'stderr' => $stderr ];
}
