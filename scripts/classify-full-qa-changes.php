<?php

/**
 * Classify changed repository paths for the required Full QA workflow.
 *
 * Paths are read one per line from STDIN. Output uses GitHub Actions' output
 * format so the same executable contract serves local tests and CI.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$force = in_array( '--force', $argv, true );
$paths = [];

while ( false !== ( $line = fgets( STDIN ) ) )
{
    $path = trim( str_replace( '\\', '/', $line ) );
    if ( '' === $path )
    {
        continue;
    }
    if ( str_starts_with( $path, './' ) )
    {
        $path = substr( $path, 2 );
    }
    if ( ! is_safe_repository_path( $path ) )
    {
        fwrite( STDERR, "Unsafe changed path: {$path}\n" );
        exit( 1 );
    }
    $paths[] = $path;
}

$requires_full_qa = $force || [] === $paths;
if ( ! $requires_full_qa )
{
    foreach ( $paths as $path )
    {
        if ( ! is_inexpensive_documentation_path( $path ) )
        {
            $requires_full_qa = true;
            break;
        }
    }
}

$classification = $requires_full_qa ? 'full_qa' : 'docs_only';
echo "classification={$classification}\n";
echo 'full_qa=' . ( $requires_full_qa ? 'true' : 'false' ) . "\n";
echo 'docs_only=' . ( $requires_full_qa ? 'false' : 'true' ) . "\n";

function is_safe_repository_path( string $path ): bool
{
    if ( str_starts_with( $path, '/' ) || str_starts_with( $path, '//' ) )
    {
        return false;
    }
    if ( preg_match( '/^[A-Za-z]:\//', $path ) || preg_match( '/[\x00-\x1F\x7F]/', $path ) )
    {
        return false;
    }

    foreach ( explode( '/', $path ) as $part )
    {
        if ( '' === $part || '.' === $part || '..' === $part )
        {
            return false;
        }
    }
    return true;
}

function is_inexpensive_documentation_path( string $path ): bool
{
    if ( in_array( basename( $path ), [ 'AGENTS.md', 'GEMINI.md' ], true ) )
    {
        return true;
    }
    if ( str_starts_with( $path, 'docs/' ) || str_starts_with( $path, 'agent-logs/' ) )
    {
        return true;
    }

    return ! str_contains( $path, '/' ) && str_ends_with( strtolower( $path ), '.md' );
}
