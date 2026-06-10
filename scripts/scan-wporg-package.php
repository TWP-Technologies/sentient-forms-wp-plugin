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
    'admin-app/.svelte-kit',
    'admin-app/build',
    'admin-app/docs',
    'admin-app/node_modules',
    'admin-app/playwright-report',
    'admin-app/test-results',
    'admin-app/tests',
    'agent-logs',
    'build',
    'docs',
    'mu-plugins',
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
    'assets/dist',
    'docs',
    'mu-plugins',
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
    '/\.svelte(?:\.[jt]s)?$/',
    '/\.tsx?$/',
    '/\.toml$/',
    '/package-lock\.json$/',
    '/pnpm-lock\.yaml$/',
    '/yarn\.lock$/',
    '/(?:^|\/)(?:playwright\.config\.ts|vitest\.config\.ts|svelte\.config\.js|vite\.config\.[cm]?[jt]s|tsconfig(?:\.[^.]+)?\.json)$/',
];

$runtime_extensions     = [ 'php', 'js', 'css', 'mjs', 'html', 'txt' ];
$remote_scan_extensions = [ 'php', 'js', 'css', 'mjs', 'html' ];
$allowed_url_hosts = [
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
    'twp.tech',
    'www.gnu.org',
    'www.w3.org',
    'wordpress.org',
];

$remote_url_pattern  = '/https?:\/\/[^\s\'")<>`\}\]]+/i';
$direct_http_pattern = '/\b(curl_exec|curl_init|file_get_contents\s*\(\s*[\'"]https?:\/\/)/i';
$forbidden_runtime_markers = [
    'dev-nonce',
    'dev-ajax',
    'dev-site',
    '127.0.0.1:8080',
    'localhost:5173',
];
$sensitive_wp_secret_constants = [
    'AUTH_KEY',
    'AUTH_SALT',
    'SECURE_AUTH_KEY',
    'SECURE_AUTH_SALT',
    'LOGGED_IN_KEY',
    'LOGGED_IN_SALT',
    'NONCE_KEY',
    'NONCE_SALT',
];

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
    if ( ! $source_tree && 'mjs' === $extension && ! str_starts_with( $relative, 'assets/dist/' ) )
    {
        $issues[] = "Forbidden development JavaScript module in package: {$relative}";
        continue;
    }

    if ( ! in_array( $extension, $runtime_extensions, true ) )
    {
        continue;
    }

    if ( str_starts_with( $relative, 'docs/' ) || str_starts_with( $relative, 'tests/' ) )
    {
        continue;
    }

    if ( has_utf8_bom( $path ) )
    {
        $issues[] = "UTF-8 BOM found in runtime text file: {$relative}";
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

    foreach ( $forbidden_runtime_markers as $marker )
    {
        if ( str_starts_with( $relative, 'scripts/' ) )
        {
            break;
        }

        if ( str_contains( $contents, $marker ) )
        {
            $issues[] = "Forbidden development marker in runtime file {$relative}: {$marker}";
            break;
        }
    }

    if ( ! $source_tree && 'php' === $extension && ! str_starts_with( $relative, 'vendor/' ) && ! has_direct_access_guard( $contents ) )
    {
        $issues[] = "Missing direct access guard in PHP file: {$relative}";
    }

    if ( 'php' === $extension && ! str_starts_with( $relative, 'vendor/' ) && ! str_starts_with( $relative, 'scripts/' ) )
    {
        $sensitive_constant = find_sensitive_wp_secret_constant_access( $contents, $sensitive_wp_secret_constants );
        if ( null !== $sensitive_constant )
        {
            $issues[] = "Sensitive WordPress auth constant access found in runtime PHP file {$relative}: {$sensitive_constant}";
        }

        if ( preg_match( '/<\s*script\b/i', $contents ) )
        {
            $issues[] = "Raw script tag found in runtime PHP file: {$relative}";
        }

        if ( preg_match( '/<\s*style\b/i', $contents ) )
        {
            $issues[] = "Raw style tag found in runtime PHP file: {$relative}";
        }

        if ( preg_match( '/\son[a-z]+\s*=/i', $contents ) )
        {
            $issues[] = "Inline event handler found in runtime PHP file: {$relative}";
        }
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
else
{
    $issues = array_merge( $issues, validate_plugin_headers( $root . '/sentient-forms.php' ) );
}

if ( ! file_exists( $root . '/readme.txt' ) )
{
    $issues[] = 'Missing readme.txt.';
}
else
{
    $issues = array_merge( $issues, validate_readme( $root . '/readme.txt', $root . '/sentient-forms.php' ) );
}

if ( ! $source_tree )
{
    if ( ! is_dir( $root . '/assets/dist' ) )
    {
        $issues[] = 'Missing built admin assets at assets/dist.';
    }
    else
    {
        $issues = array_merge( $issues, validate_admin_runtime_metadata( $root ) );
        $issues = array_merge( $issues, validate_compressed_asset_source_metadata( $root, $root . '/sentient-forms.php' ) );
    }
}

if ( ! $source_tree )
{
    $issues = array_merge( $issues, validate_package_composer_metadata( $root ) );
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
 * Validate the main plugin headers that WordPress.org upload checks enforce.
 *
 * @return array<int,string>
 */
function validate_plugin_headers( string $plugin_file ): array
{
    $contents = file_get_contents( $plugin_file );
    if ( false === $contents )
    {
        return [ 'Could not read sentient-forms.php plugin entry point.' ];
    }

    $plugin_uri = normalize_plugin_header_uri( parse_plugin_header_value( $contents, 'Plugin URI' ) );
    $author_uri = normalize_plugin_header_uri( parse_plugin_header_value( $contents, 'Author URI' ) );
    $issues     = [];

    if ( null === $plugin_uri )
    {
        $issues[] = 'Plugin URI header is required for this package.';
    }

    if ( null === $author_uri )
    {
        $issues[] = 'Author URI header is required for this package.';
    }

    if ( null !== $plugin_uri && null !== $author_uri && $plugin_uri === $author_uri )
    {
        $issues[] = 'Plugin URI and Author URI must not be the same URL.';
    }

    $requires_at_least = parse_plugin_header_value( $contents, 'Requires at least' );
    if ( null === $requires_at_least )
    {
        $issues[] = 'Requires at least header is required for this package.';
    }
    elseif ( ! is_wordpress_core_version_without_patch( $requires_at_least ) )
    {
        $issues[] = "Requires at least header must use major.minor format without a patch component; found {$requires_at_least}.";
    }

    return $issues;
}

/**
 * Validate the generated admin runtime metadata used instead of the SvelteKit
 * fallback HTML file, which contains an inline bootstrap script.
 *
 * @return array<int,string>
 */
function validate_admin_runtime_metadata( string $root ): array
{
    $issues       = [];
    $dist_dir     = $root . '/assets/dist';
    $runtime_file = $dist_dir . '/runtime.json';
    $index_file   = $dist_dir . '/index.html';

    if ( file_exists( $index_file ) )
    {
        $issues[] = 'Generated admin assets must not include assets/dist/index.html; use runtime.json metadata instead.';
    }

    if ( ! file_exists( $runtime_file ) )
    {
        $issues[] = 'Generated admin assets are missing assets/dist/runtime.json.';
        return $issues;
    }

    $decoded = json_decode( (string) file_get_contents( $runtime_file ), true );
    $key     = is_array( $decoded ) ? ( $decoded['sveltekitRuntimeKey'] ?? null ) : null;
    if ( ! is_string( $key ) || ! preg_match( '/^__sveltekit_[a-z0-9]+$/', $key ) )
    {
        $issues[] = 'Generated admin runtime metadata is missing a valid SvelteKit runtime key.';
    }

    return $issues;
}

/**
 * Detect actual reads of WordPress auth key/salt constants while allowing
 * quoted denylist strings used to reject unsafe user-provided constant names.
 *
 * @param array<int,string> $sensitive_names
 */
function find_sensitive_wp_secret_constant_access( string $contents, array $sensitive_names ): ?string
{
    $name_pattern = implode( '|', array_map( static fn ( string $name ): string => preg_quote( $name, '/' ), $sensitive_names ) );
    if ( preg_match( '/\b(?:defined|constant)\s*\(\s*[\'"](' . $name_pattern . ')[\'"]\s*\)/', $contents, $matches ) )
    {
        return $matches[1];
    }

    $sensitive_lookup = array_fill_keys( $sensitive_names, true );
    foreach ( token_get_all( $contents ) as $token )
    {
        if ( ! is_array( $token ) )
        {
            continue;
        }

        [ $type, $text ] = $token;
        if ( T_STRING === $type && isset( $sensitive_lookup[ $text ] ) )
        {
            return $text;
        }
    }

    return null;
}

/**
 * Parse a main plugin file header value.
 */
function parse_plugin_header_value( string $contents, string $field ): ?string
{
    if ( ! preg_match( '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi', $contents, $matches ) )
    {
        return null;
    }

    $value = trim( $matches[1] );
    return '' === $value ? null : $value;
}

/**
 * Normalize plugin header URIs for equality checks.
 */
function normalize_plugin_header_uri( ?string $uri ): ?string
{
    if ( null === $uri )
    {
        return null;
    }

    $parts = parse_url( $uri );
    if ( ! is_array( $parts ) || empty( $parts['host'] ) )
    {
        return strtolower( rtrim( $uri, '/' ) );
    }

    $scheme = strtolower( $parts['scheme'] ?? 'https' );
    $host   = strtolower( $parts['host'] );
    $path   = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';

    return "{$scheme}://{$host}{$path}";
}

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

    $readme_requires_at_least = parse_readme_header_value( $readme, 'Requires at least' );
    if ( null !== $readme_requires_at_least && ! is_wordpress_core_version_without_patch( $readme_requires_at_least ) )
    {
        $issues[] = "readme.txt Requires at least must use major.minor format without a patch component; found {$readme_requires_at_least}.";
    }

    $plugin_contents = file_exists( $plugin_file ) ? file_get_contents( $plugin_file ) : false;
    if ( is_string( $plugin_contents ) )
    {
        $plugin_requires_at_least = parse_plugin_header_value( $plugin_contents, 'Requires at least' );
        if ( null !== $readme_requires_at_least && null !== $plugin_requires_at_least && $readme_requires_at_least !== $plugin_requires_at_least )
        {
            $issues[] = "readme.txt Requires at least ({$readme_requires_at_least}) does not match plugin header ({$plugin_requires_at_least}).";
        }
    }

    foreach ( [ 'OpenRouter', 'Sentient Forms Managed Execution', 'Data sent', 'Terms', 'Privacy policy', 'Realtime Clarification Assistant', 'Gravity Forms' ] as $required_disclosure )
    {
        if ( false === stripos( $readme, $required_disclosure ) )
        {
            $issues[] = "readme.txt external-service disclosure is missing '{$required_disclosure}'.";
        }
    }

    $source_reference = read_release_source_reference( $plugin_file );
    if ( null === $source_reference )
    {
        $issues[] = 'sentient-forms.php is missing SENTIENT_FORMS_RELEASE_SOURCE_URL or SENTIENT_FORMS_RELEASE_SOURCE_REFERENCE.';
    }

    foreach ( required_generated_asset_source_references( $source_reference ) as $required_source_reference )
    {
        if ( false === stripos( $readme, $required_source_reference ) )
        {
            $issues[] = "readme.txt compressed-source disclosure is missing '{$required_source_reference}'.";
        }
    }

    return $issues;
}

function is_wordpress_core_version_without_patch( string $version ): bool
{
    return 1 === preg_match( '/^\d+\.\d+$/', trim( $version ) );
}

/**
 * Validate source metadata for generated, compressed admin assets.
 *
 * @return array<int,string>
 */
function validate_compressed_asset_source_metadata( string $root, string $plugin_file ): array
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

    $source_reference = read_release_source_reference( $plugin_file );
    if ( null === $source_reference )
    {
        $issues[] = 'sentient-forms.php is missing SENTIENT_FORMS_RELEASE_SOURCE_URL or SENTIENT_FORMS_RELEASE_SOURCE_REFERENCE.';
    }

    foreach ( required_generated_asset_source_references( $source_reference ) as $required_source_reference )
    {
        if ( false === stripos( $source, $required_source_reference ) )
        {
            $issues[] = "assets/dist/SOURCE.md is missing '{$required_source_reference}'.";
        }
    }

    return $issues;
}

/**
 * Validate packaged Composer metadata does not describe omitted dev tooling.
 *
 * @return array<int,string>
 */
function validate_package_composer_metadata( string $root ): array
{
    $composer = $root . '/composer.json';
    if ( ! file_exists( $composer ) )
    {
        return [];
    }

    $decoded = json_decode( (string) file_get_contents( $composer ), true );
    if ( ! is_array( $decoded ) )
    {
        return [ 'Package composer.json is not valid JSON.' ];
    }

    $issues = [];
    foreach ( [ 'require-dev', 'scripts' ] as $field )
    {
        if ( ! empty( $decoded[ $field ] ) )
        {
            $issues[] = "Package composer.json must not include {$field}.";
        }
    }

    if ( ! empty( $decoded['config']['allow-plugins'] ) )
    {
        $issues[] = 'Package composer.json must not include development allow-plugins config.';
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
 * Read the source reference used to document generated asset source.
 */
function read_release_source_reference( string $plugin_file ): ?string
{
    if ( ! file_exists( $plugin_file ) )
    {
        return null;
    }

    $contents = (string) file_get_contents( $plugin_file );
    foreach ( [ 'SENTIENT_FORMS_RELEASE_SOURCE_URL', 'SENTIENT_FORMS_RELEASE_SOURCE_REFERENCE' ] as $constant )
    {
        if ( preg_match( "/const\s+{$constant}\s*=\s*'([^']+)';/", $contents, $matches ) )
        {
            $value = trim( (string) $matches[1] );
            return '' === $value ? null : $value;
        }
    }

    return null;
}

/**
 * Return generated asset source references required in reviewer-facing docs.
 *
 * @return array<int,string>
 */
function required_generated_asset_source_references( ?string $source_reference ): array
{
    $references = [ 'admin-app', 'cd admin-app', 'bun install --frozen-lockfile', 'bun run build:wp' ];

    if ( null !== $source_reference )
    {
        array_unshift( $references, $source_reference );
    }

    return $references;
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
