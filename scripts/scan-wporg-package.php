<?php
/**
 * Scan a Sentient Forms plugin package tree for early WordPress.org blockers.
 *
 * This is intentionally conservative. It is not a substitute for Plugin Check,
 * PHPCS, or manual review, but it catches expensive packaging mistakes early.
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

    fwrite( STDERR, "Usage: php scripts/scan-wporg-package.php [--source-tree] [plugin-directory]\n" );
    exit( 1 );
}

$root = realpath( $root_arg ?? dirname( __DIR__ ) );

if ( false === $root || ! is_dir( $root ) )
{
    fwrite( STDERR, "Usage: php scripts/scan-wporg-package.php [--source-tree] [plugin-directory]\n" );
    exit( 1 );
}

$forbidden_dirs = [
    '.git',
    '.github',
    '.githooks',
    'admin-app',
    'admin-app/node_modules',
    'agent-logs',
    'build',
    'docs',
    'node_modules',
    'phpcs-rulesets',
    'scripts',
    'temp',
    'tests',
    'tests/wordpress',
    'tests/wordpress-tests-lib',
    'tests/wp-temp',
    'vendor/bin',
];

$source_tree_skip_dirs = [
    '.git',
    '.github',
    '.githooks',
    'admin-app/.svelte-kit',
    'admin-app/build',
    'admin-app/node_modules',
    'agent-logs',
    'docs',
    'node_modules',
    'temp',
    'tests',
    'vendor',
];

$forbidden_file_patterns = [
    '/\.map$/',
    '/\.env(?:\..*)?$/',
    '/composer\.lock$/',
    '/bun\.lock$/',
    '/package-lock\.json$/',
    '/pnpm-lock\.yaml$/',
    '/yarn\.lock$/',
];

$runtime_extensions     = [ 'php', 'js', 'css', 'mjs', 'html', 'txt' ];
$remote_scan_extensions = [ 'php', 'js', 'css', 'mjs', 'html' ];
$allowed_url_hosts = [
    '127.0.0.1',
    'actionscheduler.org',
    'automattic.com',
    'core.trac.wordpress.org',
    'crontab.guru',
    'deliciousbrains.com',
    'en.wikipedia.org',
    'example.com',
    'example.test',
    'github.com',
    'json-schema.org',
    'localhost',
    'openrouter.ai',
    'php.net',
    'reactflow.dev',
    'schemas.getpostman.com',
    'secure.php.net',
    'sentientforms.com',
    'standardschema.dev',
    'svelte.dev',
    'svelteflow.dev',
    'tailwindcss.com',
    'www.gnu.org',
    'www.w3.org',
    'wordpress.org',
];

$remote_url_pattern  = '/https?:\/\/[^\s\'")<>`\}\]]+/i';
$direct_http_pattern = '/\b(curl_exec|curl_init|file_get_contents\s*\(\s*[\'"]https?:\/\/)/i';

$issues = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ( $iterator as $item )
{
    $path     = $item->getPathname();
    $relative = ltrim( str_replace( $root, '', $path ), DIRECTORY_SEPARATOR );
    $relative = str_replace( DIRECTORY_SEPARATOR, '/', $relative );

    if ( $source_tree )
    {
        foreach ( $source_tree_skip_dirs as $dir )
        {
            if ( $relative === $dir || str_starts_with( $relative, $dir . '/' ) )
            {
                continue 2;
            }
        }
    }

    foreach ( $forbidden_dirs as $dir )
    {
        if ( $relative === $dir || str_starts_with( $relative, $dir . '/' ) )
        {
            if ( ! $source_tree )
            {
                $issues[] = "Forbidden directory in package: {$relative}";
            }
            continue 2;
        }
    }

    if ( ! $item->isFile() )
    {
        continue;
    }

    foreach ( $forbidden_file_patterns as $pattern )
    {
        if ( preg_match( $pattern, $relative ) )
        {
            if ( ! $source_tree )
            {
                $issues[] = "Forbidden file in package: {$relative}";
            }
            continue 2;
        }
    }

    $extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
    if ( ! in_array( $extension, $runtime_extensions, true ) )
    {
        continue;
    }

    if ( str_starts_with( $relative, 'docs/' ) || str_starts_with( $relative, 'tests/' ) )
    {
        continue;
    }

    $contents = file_get_contents( $path );
    if ( false === $contents )
    {
        $issues[] = "Could not read file: {$relative}";
        continue;
    }

    if ( in_array( $extension, $remote_scan_extensions, true ) )
    {
        preg_match_all( $remote_url_pattern, $contents, $matches );
        foreach ( $matches[0] ?? [] as $url )
        {
            if ( is_dynamic_url_template_fragment( $url ) )
            {
                continue;
            }

            if ( is_allowed_url( $url, $allowed_url_hosts ) )
            {
                continue;
            }

            $issues[] = "Unexpected remote asset/service URL in runtime file {$relative}: {$url}";
            break;
        }
    }

    if ( ! $source_tree && 'php' === $extension && ! str_starts_with( $relative, 'vendor/' ) && ! has_direct_access_guard( $contents ) )
    {
        $issues[] = "Missing direct access guard in PHP file: {$relative}";
    }

    if ( 'php' === $extension && 'scripts/scan-wporg-package.php' !== $relative && preg_match( $direct_http_pattern, $contents, $matches ) )
    {
        $issues[] = "Potential non-WP HTTP call in {$relative}: {$matches[0]}";
    }
}

if ( ! file_exists( $root . '/sentient-forms.php' ) )
{
    $issues[] = 'Missing sentient-forms.php plugin entry point.';
}

if ( ! file_exists( $root . '/readme.txt' ) )
{
    $issues[] = 'Missing readme.txt.';
}
else
{
    $issues = array_merge( $issues, validate_readme( $root . '/readme.txt', $root . '/sentient-forms.php' ) );
}

if ( ! $source_tree && ! is_dir( $root . '/assets/dist' ) )
{
    $issues[] = 'Missing built admin assets at assets/dist.';
}
else
{
    $issues = array_merge( $issues, validate_compressed_asset_source_metadata( $root ) );
}

if ( [] !== $issues )
{
    echo "Sentient Forms WordPress.org package scan failed:\n";
    foreach ( $issues as $issue )
    {
        echo "- {$issue}\n";
    }
    exit( 1 );
}

$mode = $source_tree ? 'source tree' : 'package';
echo "Sentient Forms WordPress.org {$mode} scan passed for {$root}.\n";

/**
 * Return whether a discovered URL token is only part of a bundled dynamic
 * template expression, such as a validator constructing an IPv6 URL.
 */
function is_dynamic_url_template_fragment( string $url ): bool
{
    return str_contains( $url, '${' );
}

/**
 * Return whether a discovered URL is an allowed docs/example/service URL.
 *
 * @param string            $url           Discovered URL.
 * @param array<int,string> $allowed_hosts Hostnames allowed by the scanner.
 */
function is_allowed_url( string $url, array $allowed_hosts ): bool
{
    $host = wporg_scan_parse_host( $url );
    if ( null === $host )
    {
        return false;
    }

    foreach ( $allowed_hosts as $allowed_host )
    {
        if ( $host === $allowed_host || str_ends_with( $host, '.' . $allowed_host ) )
        {
            return true;
        }
    }

    return false;
}

/**
 * Parse a host from a URL without requiring WordPress helpers.
 */
function wporg_scan_parse_host( string $url ): ?string
{
    $parts = parse_url( rtrim( $url, '`.;,}]' ) );
    if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || ! is_string( $parts['host'] ) )
    {
        return null;
    }

    return strtolower( $parts['host'] );
}

/**
 * Return whether a runtime PHP file blocks direct web access.
 */
function has_direct_access_guard( string $contents ): bool
{
    if ( str_contains( $contents, 'defined( \'ABSPATH\' )' ) || str_contains( $contents, 'defined(\'ABSPATH\')' ) )
    {
        return true;
    }

    return str_contains( $contents, 'defined( "ABSPATH" )' ) || str_contains( $contents, 'defined("ABSPATH")' );
}

/**
 * Validate the WordPress.org readme fields that should never regress.
 *
 * @return array<int,string>
 */
function validate_readme( string $readme_path, string $plugin_file ): array
{
    $issues  = [];
    $readme  = file_get_contents( $readme_path );
    $version = file_exists( $plugin_file ) ? parse_plugin_version( $plugin_file ) : null;

    if ( false === $readme )
    {
        return [ 'Could not read readme.txt.' ];
    }

    if ( ! preg_match( '/^===\s*Sentient Forms\s*===\s*$/m', $readme ) )
    {
        $issues[] = 'readme.txt is missing the Sentient Forms plugin title header.';
    }

    $stable_tag = parse_readme_header_value( $readme, 'Stable tag' );
    if ( null === $stable_tag )
    {
        $issues[] = 'readme.txt is missing Stable tag.';
    }
    elseif ( 'trunk' === strtolower( $stable_tag ) )
    {
        $issues[] = 'readme.txt uses Stable tag: trunk, which is not allowed for this package.';
    }
    elseif ( null !== $version && $stable_tag !== $version )
    {
        $issues[] = "readme.txt Stable tag ({$stable_tag}) does not match plugin Version ({$version}).";
    }

    foreach ( [ 'Requires at least', 'Requires PHP', 'License', 'License URI' ] as $field )
    {
        if ( null === parse_readme_header_value( $readme, $field ) )
        {
            $issues[] = "readme.txt is missing {$field}.";
        }
    }

    foreach ( [ 'OpenRouter', 'Sentient Forms Managed Execution', 'Data sent', 'Terms', 'Privacy policy' ] as $required_disclosure )
    {
        if ( false === stripos( $readme, $required_disclosure ) )
        {
            $issues[] = "readme.txt external-service disclosure is missing '{$required_disclosure}'.";
        }
    }

    foreach ( [ 'TWP-Technologies/sentient-forms-wp-plugin', 'bun run build:wp' ] as $required_source_reference )
    {
        if ( false === stripos( $readme, $required_source_reference ) )
        {
            $issues[] = "readme.txt compressed-source disclosure is missing '{$required_source_reference}'.";
        }
    }

    return $issues;
}

/**
 * Validate source metadata for generated, compressed admin assets.
 *
 * @return array<int,string>
 */
function validate_compressed_asset_source_metadata( string $root ): array
{
    $dist_dir = $root . '/assets/dist';
    if ( ! is_dir( $dist_dir ) || ! has_runtime_js_asset( $dist_dir ) )
    {
        return [];
    }

    $issues      = [];
    $source_file = $dist_dir . '/SOURCE.md';

    if ( ! file_exists( $source_file ) )
    {
        return [ 'Built admin assets are missing assets/dist/SOURCE.md with source and build instructions.' ];
    }

    $source = file_get_contents( $source_file );
    if ( false === $source )
    {
        return [ 'Could not read assets/dist/SOURCE.md.' ];
    }

    foreach ( [ 'TWP-Technologies/sentient-forms-wp-plugin', 'bun run build:wp' ] as $required_source_reference )
    {
        if ( false === stripos( $source, $required_source_reference ) )
        {
            $issues[] = "assets/dist/SOURCE.md is missing '{$required_source_reference}'.";
        }
    }

    return $issues;
}

/**
 * Return whether a dist directory contains runtime JavaScript assets.
 */
function has_runtime_js_asset( string $dist_dir ): bool
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dist_dir, FilesystemIterator::SKIP_DOTS )
    );

    foreach ( $iterator as $item )
    {
        if ( $item->isFile() && 'js' === strtolower( pathinfo( $item->getFilename(), PATHINFO_EXTENSION ) ) )
        {
            return true;
        }
    }

    return false;
}

/**
 * Parse a readme header value.
 */
function parse_readme_header_value( string $readme, string $field ): ?string
{
    if ( ! preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi', $readme, $matches ) )
    {
        return null;
    }

    return trim( $matches[1] );
}

/**
 * Parse the main plugin Version header.
 */
function parse_plugin_version( string $plugin_file ): ?string
{
    $contents = file_get_contents( $plugin_file );
    if ( false === $contents || ! preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $contents, $matches ) )
    {
        return null;
    }

    return trim( $matches[1] );
}
