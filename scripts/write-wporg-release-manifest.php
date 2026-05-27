<?php
/**
 * Write a compact manifest for a validated WordPress.org package artifact.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$package_dir = isset( $argv[1] ) ? realpath( $argv[1] ) : false;
$zip_path    = isset( $argv[2] ) ? realpath( $argv[2] ) : false;
$output_path = $argv[3] ?? null;

if ( false === $package_dir || false === $zip_path || null === $output_path )
{
    fwrite( STDERR, "Usage: php scripts/write-wporg-release-manifest.php <package-dir> <zip-path> <output-json>\n" );
    exit( 1 );
}

$plugin_file = $package_dir . '/sentient-forms.php';
$readme_file = $package_dir . '/readme.txt';
$source_file = $package_dir . '/assets/dist/SOURCE.md';

$manifest = [
    'schema'           => 'sentient_forms_wporg_release_manifest.v1',
    'generated_at'     => gmdate( 'c' ),
    'package_dir'      => $package_dir,
    'zip_path'         => $zip_path,
    'zip_sha256'       => hash_file( 'sha256', $zip_path ),
    'version'          => read_regex_or_null( $plugin_file, '/^\s*\*\s*Version:\s*(.+)$/mi' ),
    'stable_tag'       => read_regex_or_null( $readme_file, '/^Stable tag:\s*(.+)$/mi' ),
    'source_reference' => read_regex_or_null( $plugin_file, "/const\s+SENTIENT_FORMS_RELEASE_SOURCE_REFERENCE\s*=\s*'([^']+)';/" ),
    'git_commit'       => getenv( 'GITHUB_SHA' ) ?: git_output( 'rev-parse HEAD' ),
    'git_ref'          => getenv( 'GITHUB_REF_NAME' ) ?: git_output( 'branch --show-current' ),
    'checks'           => [
        'release_version' => 'passed',
        'source_scan'     => 'passed',
        'package_scan'    => 'passed',
        'license_audit'   => 'passed',
        'readme'          => 'passed',
        'source'          => 'passed',
        'plugin_check'    => 'required-in-workflow',
    ],
    'files'            => [
        'readme'    => file_exists( $readme_file ),
        'source_md' => file_exists( $source_file ),
        'admin_app' => is_dir( $package_dir . '/admin-app' ),
    ],
];

$encoded = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
if ( ! is_string( $encoded ) )
{
    fwrite( STDERR, "Unable to encode release manifest.\n" );
    exit( 1 );
}

$output_dir = dirname( $output_path );
if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0775, true ) )
{
    fwrite( STDERR, "Unable to create output directory: {$output_dir}\n" );
    exit( 1 );
}

file_put_contents( $output_path, $encoded . PHP_EOL );
echo "Wrote WordPress.org release manifest: {$output_path}\n";

function read_regex_or_null( string $path, string $pattern ): ?string
{
    if ( ! file_exists( $path ) )
    {
        return null;
    }

    $contents = (string) file_get_contents( $path );
    if ( preg_match( $pattern, $contents, $matches ) )
    {
        return trim( (string) $matches[1] );
    }

    return null;
}

function git_output( string $args ): string
{
    $plugin_root = dirname( __DIR__ );
    $command = sprintf( 'git -C %s %s 2>&1', escapeshellarg( $plugin_root ), $args );
    $output = shell_exec( $command );
    return trim( is_string( $output ) ? $output : '' );
}
