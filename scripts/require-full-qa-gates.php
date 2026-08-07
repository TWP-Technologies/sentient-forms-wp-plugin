<?php

/**
 * Fail closed unless the classified Full QA producers reached valid results.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$classifier_result = trim( (string) getenv( 'CLASSIFIER_RESULT' ) );
$full_qa_required  = trim( (string) getenv( 'FULL_QA_REQUIRED' ) );
$full_qa_result    = trim( (string) getenv( 'FULL_QA_RESULT' ) );

if ( 'success' !== $classifier_result )
{
    fail_required_gate( 'Full QA classification did not succeed', $classifier_result );
}
if ( ! in_array( $full_qa_required, [ 'true', 'false' ], true ) )
{
    fail_required_gate( 'Full QA classifier returned an invalid requirement', $full_qa_required );
}

$expected_result = 'true' === $full_qa_required ? 'success' : 'skipped';
if ( $expected_result !== $full_qa_result )
{
    fail_required_gate( "Full QA expected {$expected_result}", $full_qa_result );
}

echo "Full QA required gate passed: required={$full_qa_required}; result={$full_qa_result}.\n";

function fail_required_gate( string $message, string $actual ): never
{
    $display = '' === $actual ? '[missing]' : $actual;
    fwrite( STDERR, "{$message}; received {$display}.\n" );
    exit( 1 );
}
