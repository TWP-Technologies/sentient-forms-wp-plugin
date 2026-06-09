<?php
/**
 * Validate that release version surfaces agree.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$plugin_root = getenv( 'SENTIENT_FORMS_PLUGIN_ROOT' ) ?: dirname( __DIR__ );
$plugin_file = $plugin_root . '/sentient-forms.php';
$readme_file = $plugin_root . '/readme.txt';
$package_file = $plugin_root . '/admin-app/package.json';
$manifest_file = $plugin_root . '/.release-please-manifest.json';

$versions = [
    'plugin header' => read_regex( $plugin_file, '/^\s*\*\s*Version:\s*(.+)$/mi' ),
    'plugin const' => read_regex( $plugin_file, "/const\s+SENTIENT_FORMS_VERSION\s*=\s*'([^']+)';/" ),
    'readme stable tag' => read_regex( $readme_file, '/^Stable tag:\s*(.+)$/mi' ),
];

if ( file_exists( $package_file ) )
{
    $json = json_decode( (string) file_get_contents( $package_file ), true );
    if ( ! is_array( $json ) || ! isset( $json['version'] ) || ! is_string( $json['version'] ) )
    {
        fwrite( STDERR, "admin-app/package.json is missing a string version field.\n" );
        exit( 1 );
    }

    $versions['admin app package'] = trim( $json['version'] );
}

if ( file_exists( $manifest_file ) )
{
    $json = json_decode( (string) file_get_contents( $manifest_file ), true );
    if ( ! is_array( $json ) || ! isset( $json['.'] ) || ! is_string( $json['.'] ) )
    {
        fwrite( STDERR, ".release-please-manifest.json is missing a string root package version.\n" );
        exit( 1 );
    }

    $versions['release-please manifest'] = trim( $json['.'] );
}
else
{
    fwrite( STDERR, ".release-please-manifest.json is missing.\n" );
    exit( 1 );
}

$unique_versions = array_values( array_unique( $versions ) );
if ( 1 !== count( $unique_versions ) )
{
    fwrite( STDERR, "Release version surfaces do not agree:\n" );
    foreach ( $versions as $name => $version )
    {
        fwrite( STDERR, "- {$name}: {$version}\n" );
    }
    exit( 1 );
}

$version = $unique_versions[0];
$source_reference = read_source_reference( $plugin_file );
if ( '' === $source_reference )
{
    fwrite( STDERR, "Release source URL/reference is empty.\n" );
    exit( 1 );
}

$readme = (string) file_get_contents( $readme_file );
if ( ! str_contains( $readme, $source_reference ) )
{
    fwrite( STDERR, "readme.txt is missing release source reference {$source_reference}.\n" );
    exit( 1 );
}

if ( str_contains( $readme, 'github.com/TWP-Technologies/sentient-forms-wp-plugin/tree/v0.1.0' ) )
{
    fwrite( STDERR, "readme.txt still references stale v0.1.0 source.\n" );
    exit( 1 );
}

echo "Sentient Forms release version surfaces agree at {$version}.\n";

function read_regex( string $path, string $pattern ): string
{
    $contents = file_get_contents( $path );
    if ( false === $contents )
    {
        fwrite( STDERR, "Unable to read {$path}\n" );
        exit( 1 );
    }

    if ( ! preg_match( $pattern, $contents, $matches ) )
    {
        fwrite( STDERR, "Missing expected version surface in {$path}: {$pattern}\n" );
        exit( 1 );
    }

    return trim( (string) $matches[1] );
}

function read_source_reference( string $plugin_file ): string
{
    $contents = file_get_contents( $plugin_file );
    if ( false === $contents )
    {
        fwrite( STDERR, "Unable to read {$plugin_file}\n" );
        exit( 1 );
    }

    foreach ( [ 'SENTIENT_FORMS_RELEASE_SOURCE_URL', 'SENTIENT_FORMS_RELEASE_SOURCE_REFERENCE' ] as $constant )
    {
        if ( preg_match( "/const\s+{$constant}\s*=\s*'([^']+)';/", $contents, $matches ) )
        {
            return trim( (string) $matches[1] );
        }
    }

    fwrite( STDERR, "Missing release source URL/reference in {$plugin_file}.\n" );
    exit( 1 );
}
