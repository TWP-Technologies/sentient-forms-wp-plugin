<?php

/**
 * Classify whether changed paths require the expensive Full QA workflow job.
 *
 * Paths are read one per line from STDIN. The output is suitable for appending
 * directly to GitHub Actions' GITHUB_OUTPUT file.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$force = in_array( '--force', $argv, true );
$paths = [];
$is_inexpensive_documentation_path = static function ( string $path ): bool
{
    $basename = basename( $path );
    if ( in_array( $basename, [ 'AGENTS.md', 'GEMINI.md' ], true ) )
    {
        return true;
    }

    if ( str_starts_with( $path, 'docs/' ) || str_starts_with( $path, 'agent-logs/' ) )
    {
        return true;
    }

    return ! str_contains( $path, '/' ) && str_ends_with( strtolower( $path ), '.md' );
};

while ( false !== ( $line = fgets( STDIN ) ) )
{
    $path = trim( str_replace( '\\', '/', $line ) );
    if ( '' !== $path )
    {
        $paths[] = str_starts_with( $path, './' ) ? substr( $path, 2 ) : $path;
    }
}

$requires_full_qa = $force || [] === $paths;
if ( ! $requires_full_qa )
{
    foreach ( $paths as $path )
    {
        if ( ! $is_inexpensive_documentation_path( $path ) )
        {
            $requires_full_qa = true;
            break;
        }
    }
}

echo 'full_qa=' . ( $requires_full_qa ? 'true' : 'false' ) . PHP_EOL;
echo 'docs_only=' . ( $requires_full_qa ? 'false' : 'true' ) . PHP_EOL;
