<?php
/**
 * Bump Sentient Forms release version surfaces.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

if ( ! isset( $argv[0] ) || realpath( (string) $argv[0] ) !== __FILE__ )
{
    fwrite( STDERR, "Run this script directly: php scripts/bump-release-version.php <patch|minor|major> [version]\n" );
    exit( 1 );
}

$plugin_root = getenv( 'SENTIENT_FORMS_PLUGIN_ROOT' ) ?: dirname( __DIR__ );
$bump        = $argv[1] ?? 'patch';

if ( ! in_array( $bump, [ 'patch', 'minor', 'major' ], true ) )
{
    fwrite( STDERR, "Usage: php scripts/bump-release-version.php <patch|minor|major> [version]\n" );
    exit( 1 );
}

$explicit_version = $argv[2] ?? null;
$current_version  = read_plugin_version( $plugin_root . '/sentient-forms.php' );
$next_version     = is_string( $explicit_version ) && '' !== trim( $explicit_version )
    ? normalize_version( $explicit_version )
    : bump_version( $current_version, $bump );
$tag              = 'v' . $next_version;
$source_reference = 'admin-app';

update_text_file(
    $plugin_root . '/sentient-forms.php',
    [
        '/^(\s*\*\s*Version:\s*).+$/m' => '${1}' . $next_version,
        "/(const\s+SENTIENT_FORMS_VERSION\s*=\s*)'[^']+';/" => '${1}' . "'" . $next_version . "';",
        "/(const\s+SENTIENT_FORMS_RELEASE_SOURCE_REFERENCE\s*=\s*)'[^']+';/" => '${1}' . "'" . $source_reference . "';",
    ]
);

update_text_file(
    $plugin_root . '/readme.txt',
    [
        '/^(Stable tag:\s*).+$/m' => '${1}' . $next_version,
        '/Sentient Forms [0-9]+\.[0-9]+\.[0-9]+/' => 'Sentient Forms ' . $next_version,
    ]
);

$package_file = $plugin_root . '/admin-app/package.json';
if ( file_exists( $package_file ) )
{
    $json = json_decode( file_get_contents( $package_file ), true );
    if ( ! is_array( $json ) )
    {
        fwrite( STDERR, "Unable to parse {$package_file}\n" );
        exit( 1 );
    }

    $json['version'] = $next_version;
    $pretty = json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    if ( ! is_string( $pretty ) )
    {
        fwrite( STDERR, "Unable to encode {$package_file}\n" );
        exit( 1 );
    }

    $pretty = preg_replace_callback(
        '/^( +)/m',
        static fn ( array $matches ): string => str_repeat( ' ', (int) ( strlen( $matches[1] ) / 2 ) ),
        $pretty
    );
    file_put_contents( $package_file, $pretty . PHP_EOL );
}

$manifest_file = $plugin_root . '/.release-please-manifest.json';
if ( file_exists( $manifest_file ) )
{
    $json = json_decode( file_get_contents( $manifest_file ), true );
    if ( ! is_array( $json ) )
    {
        fwrite( STDERR, "Unable to parse {$manifest_file}\n" );
        exit( 1 );
    }

    $json['.'] = $next_version;
    $pretty = json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    if ( ! is_string( $pretty ) )
    {
        fwrite( STDERR, "Unable to encode {$manifest_file}\n" );
        exit( 1 );
    }

    file_put_contents( $manifest_file, $pretty . PHP_EOL );
}

printf( "Bumped Sentient Forms from %s to %s (%s)\n", $current_version, $next_version, $tag );

function read_plugin_version( string $plugin_file ): string
{
    $contents = file_get_contents( $plugin_file );
    if ( false === $contents || ! preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $contents, $matches ) )
    {
        fwrite( STDERR, "Missing plugin Version header in {$plugin_file}\n" );
        exit( 1 );
    }

    return normalize_version( trim( $matches[1] ) );
}

function normalize_version( string $version ): string
{
    $version = ltrim( trim( $version ), 'v' );
    if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/', $version ) )
    {
        fwrite( STDERR, "Invalid semantic version: {$version}\n" );
        exit( 1 );
    }

    return $version;
}

function bump_version( string $version, string $bump ): string
{
    $base = preg_replace( '/-.+$/', '', $version );
    $parts = array_map( 'intval', explode( '.', (string) $base ) );
    [ $major, $minor, $patch ] = $parts + [ 0, 0, 0 ];

    if ( 'major' === $bump )
    {
        return ( $major + 1 ) . '.0.0';
    }

    if ( 'minor' === $bump )
    {
        return $major . '.' . ( $minor + 1 ) . '.0';
    }

    return $major . '.' . $minor . '.' . ( $patch + 1 );
}

/**
 * @param array<string,string> $replacements Regex replacement map.
 */
function update_text_file( string $path, array $replacements ): void
{
    $contents = file_get_contents( $path );
    if ( false === $contents )
    {
        fwrite( STDERR, "Unable to read {$path}\n" );
        exit( 1 );
    }

    foreach ( $replacements as $pattern => $replacement )
    {
        $contents = preg_replace( $pattern, $replacement, $contents, 1 );
        if ( null === $contents )
        {
            fwrite( STDERR, "Replacement failed for {$path}: {$pattern}\n" );
            exit( 1 );
        }
    }

    file_put_contents( $path, $contents );
}
