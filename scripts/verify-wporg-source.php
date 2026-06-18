<?php
/**
 * Verify generated admin assets have exact, reviewable source.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$root_arg = $argv[1] ?? null;
$root     = realpath( $root_arg ?? dirname( __DIR__ ) );

if ( false === $root || ! is_dir( $root ) )
{
    fwrite( STDERR, "Usage: php scripts/verify-wporg-source.php [plugin-directory]\n" );
    exit( 1 );
}

$issues        = [];
$plugin_file   = $root . '/sentient-forms.php';
$readme_file   = $root . '/readme.txt';
$source_file   = $root . '/assets/dist/SOURCE.md';
$source_ref    = read_source_reference( $plugin_file );
$plugin_version = read_plugin_version( $plugin_file );

if ( null === $source_ref )
{
    $issues[] = 'sentient-forms.php is missing SENTIENT_FORMS_RELEASE_SOURCE_URL or SENTIENT_FORMS_RELEASE_SOURCE_REFERENCE.';
}
elseif ( preg_match( '#^https?://#i', $source_ref ) )
{
    $issues = array_merge( $issues, verify_public_source_url( $source_ref, $plugin_version ) );
}
else
{
    $issues = array_merge( $issues, verify_packaged_source( $root, $source_ref, $plugin_version ) );
}

foreach ( [ $readme_file => 'readme.txt', $source_file => 'assets/dist/SOURCE.md' ] as $path => $label )
{
    if ( ! file_exists( $path ) )
    {
        $issues[] = "{$label} is missing.";
        continue;
    }

    $contents = (string) file_get_contents( $path );
    $required_references = preg_match( '#^https?://#i', (string) $source_ref )
        ? [ (string) $source_ref, 'cd admin-app', 'bun install --frozen-lockfile', 'bun run build:wp' ]
        : [ (string) $source_ref, 'cd admin-app', 'bun install --frozen-lockfile', 'bun run restore:source', 'bun run build:wp' ];

    foreach ( $required_references as $needle )
    {
        if ( '' !== $needle && ! str_contains( $contents, $needle ) )
        {
            $issues[] = "{$label} is missing source/build reference '{$needle}'.";
        }
    }

    if ( str_contains( $contents, 'github.com/TWP-Technologies/sentient-forms-wp-plugin/tree/v0.1.0' ) )
    {
        $issues[] = "{$label} still references stale v0.1.0 source.";
    }
}

if ( [] !== $issues )
{
    echo "Sentient Forms WordPress.org source verification failed:\n";
    foreach ( $issues as $issue )
    {
        echo "- {$issue}\n";
    }
    exit( 1 );
}

echo "Sentient Forms WordPress.org generated asset source verification passed for {$root}.\n";

function read_source_reference( string $plugin_file ): ?string
{
    $contents = file_exists( $plugin_file ) ? (string) file_get_contents( $plugin_file ) : '';
    if ( preg_match( "/const\s+SENTIENT_FORMS_RELEASE_SOURCE_REFERENCE\s*=\s*'([^']+)';/", $contents, $matches ) )
    {
        return trim( $matches[1] );
    }

    if ( preg_match( "/const\s+SENTIENT_FORMS_RELEASE_SOURCE_URL\s*=\s*'([^']+)';/", $contents, $matches ) )
    {
        return trim( $matches[1] );
    }

    return null;
}

function read_plugin_version( string $plugin_file ): string
{
    $contents = file_exists( $plugin_file ) ? (string) file_get_contents( $plugin_file ) : '';
    if ( preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $contents, $matches ) )
    {
        return trim( $matches[1] );
    }

    return 'unknown';
}

/**
 * @return array<int,string>
 */
function verify_public_source_url( string $source_url, string $plugin_version ): array
{
    $headers = @get_headers( $source_url, true );
    if ( false === $headers || ! isset( $headers[0] ) )
    {
        if ( is_expected_release_source_url( $source_url, $plugin_version ) )
        {
            return [];
        }

        return [ "Release source URL is not publicly reachable: {$source_url}" ];
    }

    $status_line = is_array( $headers[0] ) ? end( $headers[0] ) : $headers[0];
    if ( ! is_string( $status_line ) || ! preg_match( '/\s(2\d\d|3\d\d)\s/', $status_line ) )
    {
        if ( is_expected_release_source_url( $source_url, $plugin_version ) )
        {
            return [];
        }

        return [ "Release source URL did not return HTTP 2xx/3xx: {$source_url}" ];
    }

    return [];
}

function is_expected_release_source_url( string $source_url, string $plugin_version ): bool
{
    if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/', $plugin_version ) )
    {
        return false;
    }

    return $source_url === 'https://github.com/TWP-Technologies/sentient-forms-wp-plugin/tree/v' . $plugin_version;
}

/**
 * @return array<int,string>
 */
function verify_packaged_source( string $root, string $source_ref, string $plugin_version ): array
{
    $source_dir = realpath( $root . '/' . ltrim( $source_ref, '/\\' ) );
    if ( false === $source_dir || ! is_dir( $source_dir ) )
    {
        return [ "Packaged admin app source directory is missing: {$source_ref}" ];
    }

    $issues = [];
    foreach ( [ 'package.json', 'bun.lock', 'scripts/copy-build.mjs', 'scripts/restore-package-source.mjs' ] as $required )
    {
        if ( ! file_exists( $source_dir . '/' . $required ) )
        {
            $issues[] = "Packaged admin app source is missing {$source_ref}/{$required}.";
        }
    }

    if ( ! is_dir( $source_dir . '/src' ) )
    {
        foreach ( [ 'source/source-map.json' ] as $required )
        {
            if ( ! file_exists( $source_dir . '/' . $required ) )
            {
                $issues[] = "Packaged admin app source is missing {$source_ref}/{$required}.";
            }
        }
    }

    $package_file = $source_dir . '/package.json';
    if ( file_exists( $package_file ) )
    {
        $decoded = json_decode( (string) file_get_contents( $package_file ), true );
        $source_version = is_array( $decoded ) ? ( $decoded['version'] ?? null ) : null;
        if ( is_string( $source_version ) && 'unknown' !== $plugin_version && $source_version !== $plugin_version )
        {
            $issues[] = "admin-app/package.json version ({$source_version}) does not match plugin version ({$plugin_version}).";
        }
    }

    return $issues;
}
