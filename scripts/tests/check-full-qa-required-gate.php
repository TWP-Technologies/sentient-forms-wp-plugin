<?php

/**
 * Exercise the aggregate Full QA required gate through its CLI boundary.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );
$gate        = $plugin_root . '/scripts/require-full-qa-gates.php';
$errors      = [];

if ( ! is_file( $gate ) )
{
    fwrite( STDERR, "Full QA required gate test failed:\n- Missing scripts/require-full-qa-gates.php.\n" );
    exit( 1 );
}

$accepted = [
    'required QA succeeded' => [ 'success', 'true', 'success' ],
    'docs-only QA skipped'  => [ 'success', 'false', 'skipped' ],
];
foreach ( $accepted as $label => [ $classifier_result, $required, $qa_result ] )
{
    $result = run_gate( $gate, $classifier_result, $required, $qa_result );
    if ( 0 !== $result['status'] )
    {
        $errors[] = "{$label} was rejected: " . trim( $result['stderr'] );
    }
}

$rejected = [
    'missing classifier producer' => [ '', 'true', 'success' ],
    'skipped classifier producer' => [ 'skipped', 'false', 'skipped' ],
    'failed classifier producer'  => [ 'failure', 'true', 'success' ],
    'missing requirement output'  => [ 'success', '', 'success' ],
    'invalid requirement output'  => [ 'success', 'maybe', 'success' ],
    'missing QA producer'         => [ 'success', 'true', '' ],
    'skipped required QA'         => [ 'success', 'true', 'skipped' ],
    'cancelled required QA'       => [ 'success', 'true', 'cancelled' ],
    'failed required QA'          => [ 'success', 'true', 'failure' ],
    'unexpected docs-only QA run' => [ 'success', 'false', 'success' ],
];
foreach ( $rejected as $label => [ $classifier_result, $required, $qa_result ] )
{
    $result = run_gate( $gate, $classifier_result, $required, $qa_result );
    if ( 0 === $result['status'] )
    {
        $errors[] = "{$label} was accepted.";
    }
}

if ( [] !== $errors )
{
    fwrite( STDERR, "Full QA required gate test failed:\n" );
    foreach ( $errors as $error )
    {
        fwrite( STDERR, "- {$error}\n" );
    }
    exit( 1 );
}

echo sprintf(
    "Full QA required gate test passed: %d accepted and %d fail-closed cases.\n",
    count( $accepted ),
    count( $rejected )
);

/**
 * @return array{status:int,stderr:string}
 */
function run_gate( string $gate, string $classifier_result, string $required, string $qa_result ): array
{
    $process = proc_open(
        [ PHP_BINARY, $gate ],
        [
            1 => [ 'pipe', 'w' ],
            2 => [ 'pipe', 'w' ],
        ],
        $pipes,
        null,
        array_merge(
            getenv(),
            [
                'CLASSIFIER_RESULT' => $classifier_result,
                'FULL_QA_REQUIRED'  => $required,
                'FULL_QA_RESULT'    => $qa_result,
            ]
        )
    );
    if ( ! is_resource( $process ) )
    {
        return [ 'status' => 1, 'stderr' => 'Unable to start aggregate gate.' ];
    }
    stream_get_contents( $pipes[1] );
    fclose( $pipes[1] );
    $stderr = (string) stream_get_contents( $pipes[2] );
    fclose( $pipes[2] );

    return [ 'status' => proc_close( $process ), 'stderr' => $stderr ];
}
