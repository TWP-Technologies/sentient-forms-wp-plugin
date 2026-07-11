<?php

/**
 * Verify that Full QA always reports a required result while docs-only changes stay inexpensive.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );
$classifier  = $plugin_root . '/scripts/classify-full-qa-changes.php';
$workflow    = $plugin_root . '/.github/workflows/full-qa.yml';
$errors      = [];
$run_classifier = static function ( string $classifier_path, array $paths, bool $force = false ): ?bool
{
    $command = [ PHP_BINARY, $classifier_path ];
    if ( $force )
    {
        $command[] = '--force';
    }

    $descriptor_spec = [
        0 => [ 'pipe', 'r' ],
        1 => [ 'pipe', 'w' ],
        2 => [ 'pipe', 'w' ],
    ];
    $process = proc_open( $command, $descriptor_spec, $pipes );
    if ( ! is_resource( $process ) )
    {
        return null;
    }

    fwrite( $pipes[0], implode( "\n", $paths ) . ( [] === $paths ? '' : "\n" ) );
    fclose( $pipes[0] );
    $stdout = stream_get_contents( $pipes[1] );
    fclose( $pipes[1] );
    stream_get_contents( $pipes[2] );
    fclose( $pipes[2] );
    $exit_code = proc_close( $process );

    if ( 0 !== $exit_code || ! preg_match( '/^full_qa=(true|false)\r?$/m', $stdout, $matches ) )
    {
        return null;
    }

    return 'true' === $matches[1];
};

foreach ( [ __FILE__, $classifier ] as $style_file )
{
    $style_contents = is_file( $style_file ) ? (string) file_get_contents( $style_file ) : '';
    if ( preg_match( '/(?:\)|\belse|\btry|\bfinally)[ \t]*\{[ \t]*$/m', $style_contents ) )
    {
        $errors[] = basename( $style_file ) . ' must use Allman braces.';
    }
}

if ( ! is_file( $classifier ) )
{
    $errors[] = 'Missing scripts/classify-full-qa-changes.php.';
}
else
{
    $scenarios = [
        'root Markdown is inexpensive' => [ [ 'README.md' ], false ],
        'nested documentation is inexpensive' => [ [ 'docs/architecture/form-source-adapter-boundaries.md' ], false ],
        'agent guidance is inexpensive' => [ [ 'admin-app/AGENTS.md' ], false ],
        'session logs are inexpensive' => [ [ 'agent-logs/session.md' ], false ],
        'PHP source requires Full QA' => [ [ 'includes/class-sentient-forms-plugin.php' ], true ],
        'admin source requires Full QA' => [ [ 'admin-app/src/lib/api/client.ts' ], true ],
        'contract schemas require Full QA' => [ [ 'contracts/cps-v2/managed/execute-success.schema.json' ], true ],
        'test fixtures require Full QA' => [ [ 'tests/fixtures/cps-v2-managed-execute-success.json' ], true ],
        'security and QA scripts require Full QA' => [ [ 'scripts/scan-wporg-package.php' ], true ],
        'workflow changes require Full QA' => [ [ '.github/workflows/full-qa.yml' ], true ],
        'Markdown under admin source requires Full QA' => [ [ 'admin-app/src/lib/runtime-contract.md' ], true ],
        'Markdown under contracts requires Full QA' => [ [ 'contracts/cps-v2/managed/contract.md' ], true ],
        'Markdown under fixtures requires Full QA' => [ [ 'tests/fixtures/runtime-payload.md' ], true ],
        'Markdown under workflows requires Full QA' => [ [ '.github/workflows/release-notes.md' ], true ],
        'Markdown under QA scripts requires Full QA' => [ [ 'scripts/security-policy.md' ], true ],
        'packaged asset Markdown requires Full QA' => [ [ 'assets/dist/SOURCE.md' ], true ],
        'PHP source-tree Markdown requires Full QA' => [ [ 'includes/runtime-contract.md' ], true ],
        'admin static Markdown requires Full QA' => [ [ 'admin-app/static/runtime.md' ], true ],
        'build Markdown requires Full QA' => [ [ 'build/package-contract.md' ], true ],
        'workflow agent guidance remains inexpensive' => [ [ '.github/AGENTS.md' ], false ],
        'admin source agent guidance remains inexpensive' => [ [ 'admin-app/src/GEMINI.md' ], false ],
        'contract agent guidance remains inexpensive' => [ [ 'contracts/AGENTS.md' ], false ],
        'test agent guidance remains inexpensive' => [ [ 'tests/GEMINI.md' ], false ],
        'script agent guidance remains inexpensive' => [ [ 'scripts/AGENTS.md' ], false ],
        'source-to-doc rename endpoints require Full QA' => [
            [ 'includes/class-sentient-forms-plugin.php', 'docs/class-sentient-forms-plugin.md' ],
            true,
        ],
        'mixed docs and source require Full QA' => [ [ 'README.md', 'sentient-forms.php' ], true ],
    ];

    foreach ( $scenarios as $label => [ $paths, $expected ] )
    {
        $actual = $run_classifier( $classifier, $paths );
        if ( $actual !== $expected )
        {
            $errors[] = sprintf(
                '%s: expected full_qa=%s, received %s.',
                $label,
                $expected ? 'true' : 'false',
                null === $actual ? 'invalid output' : ( $actual ? 'true' : 'false' )
            );
        }
    }

    $forced = $run_classifier( $classifier, [], true );
    if ( true !== $forced )
    {
        $errors[] = 'A forced/manual run must require Full QA.';
    }
}

if ( ! is_file( $workflow ) )
{
    $errors[] = 'Missing .github/workflows/full-qa.yml.';
}
else
{
    $contents = (string) file_get_contents( $workflow );
    $required_fragments = [
        'changes:' => 'always-running change classifier job',
        'full_qa: ${{ steps.classify.outputs.full_qa }}' => 'classifier output',
        'php scripts/tests/check-full-qa-workflow.php' => 'local workflow contract test',
        'needs: changes' => 'heavy QA dependency on classification',
        "if: \${{ needs.changes.outputs.full_qa == 'true' }}" => 'conditional heavy QA job',
        'required_full_qa:' => 'aggregate required gate',
        'if: ${{ always() }}' => 'always-reporting aggregate gate',
        'needs: [changes, full_qa]' => 'aggregate dependencies',
        'bun run zod:guard' => 'runtime schema trust-boundary gate',
    ];

    foreach ( $required_fragments as $fragment => $description )
    {
        if ( ! str_contains( $contents, $fragment ) )
        {
            $errors[] = "Full QA workflow is missing {$description}.";
        }
    }

    if ( str_contains( $contents, 'paths-ignore:' ) )
    {
        $errors[] = 'Full QA workflow must not use top-level paths-ignore suppression.';
    }

    if ( 2 !== substr_count( $contents, 'git diff --no-renames --name-only' ) )
    {
        $errors[] = 'Push and pull-request classification must both emit rename endpoints.';
    }
}

if ( [] !== $errors )
{
    fwrite( STDERR, "Full QA workflow contract failed:\n" );
    foreach ( $errors as $error )
    {
        fwrite( STDERR, "- {$error}\n" );
    }
    exit( 1 );
}

echo "Full QA workflow contract passed.\n";
