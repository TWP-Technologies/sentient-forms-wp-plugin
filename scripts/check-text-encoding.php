<?php
/**
 * Fail release/package checks when text files contain a UTF-8 BOM.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$source_tree = false;
$root_arg    = null;

foreach ( array_slice( $argv, 1 ) as $arg )
{
    if ( '--source-tree' === $arg )
    {
        $source_tree = true;
        continue;
    }

    if ( null === $root_arg )
    {
        $root_arg = $arg;
        continue;
    }

    usage();
}

$root = realpath( $root_arg ?? dirname( __DIR__ ) );
if ( false === $root || ! is_dir( $root ) )
{
    usage();
}

$issues = [];
foreach ( list_text_files( $root, $source_tree ) as $relative => $path )
{
    if ( has_utf8_bom( $path ) )
    {
        $issues[] = "UTF-8 BOM found: {$relative}";
    }
}

if ( [] !== $issues )
{
    echo "Sentient Forms text encoding check failed:\n";
    foreach ( $issues as $issue )
    {
        echo "- {$issue}\n";
    }
    exit( 1 );
}

$mode = $source_tree ? 'source tree' : 'directory';
echo "Sentient Forms text encoding check passed for {$mode}: {$root}\n";

function usage(): void
{
    fwrite( STDERR, "Usage: php scripts/check-text-encoding.php [--source-tree] [directory]\n" );
    exit( 1 );
}

/**
 * @return array<string,string>
 */
function list_text_files( string $root, bool $source_tree ): array
{
    if ( $source_tree )
    {
        $tracked = git_tracked_files( $root );
        if ( [] !== $tracked )
        {
            $files = [];
            foreach ( $tracked as $relative )
            {
                $relative = normalize_relative_path( $relative );
                $path     = $root . '/' . $relative;
                if ( is_file( $path ) && is_text_candidate( $relative ) )
                {
                    $files[ $relative ] = $path;
                }
            }

            return $files;
        }
    }

    $files    = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ( $iterator as $item )
    {
        if ( ! $item->isFile() )
        {
            continue;
        }

        $path     = $item->getPathname();
        $relative = normalize_relative_path( ltrim( str_replace( $root, '', $path ), DIRECTORY_SEPARATOR ) );
        if ( str_starts_with( $relative, '.git/' ) || ! is_text_candidate( $relative ) )
        {
            continue;
        }

        $files[ $relative ] = $path;
    }

    return $files;
}

/**
 * @return array<int,string>
 */
function git_tracked_files( string $root ): array
{
    $redirect = DIRECTORY_SEPARATOR === '\\' ? '2>NUL' : '2>/dev/null';
    $command  = sprintf( 'git -C %s ls-files -z %s', escapeshellarg( $root ), $redirect );
    $output   = shell_exec( $command );
    if ( ! is_string( $output ) || '' === $output )
    {
        return [];
    }

    return array_values(
        array_filter(
            explode( "\0", $output ),
            static fn ( string $value ): bool => '' !== $value
        )
    );
}

function normalize_relative_path( string $path ): string
{
    return str_replace( DIRECTORY_SEPARATOR, '/', str_replace( '\\', '/', $path ) );
}

function is_text_candidate( string $relative ): bool
{
    $extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
    if (
        in_array(
            $extension,
            [
                'cjs',
                'css',
                'dist',
                'html',
                'inc',
                'ini',
                'js',
                'json',
                'lock',
                'md',
                'mjs',
                'neon',
                'php',
                'phtml',
                'sh',
                'sql',
                'svg',
                'svelte',
                'ts',
                'tsx',
                'txt',
                'xml',
                'yaml',
                'yml',
            ],
            true
        )
    )
    {
        return true;
    }

    return in_array(
        strtolower( basename( $relative ) ),
        [ '.editorconfig', '.gitattributes', 'license', 'readme', 'changelog' ],
        true
    );
}

function has_utf8_bom( string $path ): bool
{
    $handle = fopen( $path, 'rb' );
    if ( false === $handle )
    {
        return false;
    }

    $bytes = fread( $handle, 3 );
    fclose( $handle );

    return "\xEF\xBB\xBF" === $bytes;
}
