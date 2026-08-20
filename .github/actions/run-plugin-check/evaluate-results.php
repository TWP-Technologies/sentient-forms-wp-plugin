<?php
/**
 * Fail-closed evaluator for filtered WordPress Plugin Check output.
 *
 * @package Sentient_Forms
 */

declare(strict_types=1);

if ( 2 !== $argc || ! is_file( $argv[1] ) )
{
    fwrite( STDERR, "::error::Plugin Check results file is missing. Strict mode fails closed.\n" );
    exit( 1 );
}

$contents = file_get_contents( $argv[1] );
if ( false === $contents )
{
    fwrite( STDERR, "::error::Plugin Check results file is unreadable. Strict mode fails closed.\n" );
    exit( 1 );
}

$result = rtrim( str_replace( "\r", '', $contents ), "\n" );
if ( '' === $result )
{
    fwrite( STDOUT, "Plugin Check completed with no findings after configured ignores.\n" );
    exit( 0 );
}

if ( 'Success: Checks complete. No errors found.' === $result )
{
    fwrite( STDOUT, "Plugin Check completed with no findings.\n" );
    exit( 0 );
}

fwrite( STDERR, "::error::Plugin Check reported findings or unexpected output. Strict mode fails closed.\n" );
exit( 1 );
