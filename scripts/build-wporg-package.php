<?php
/**
 * Build a clean Sentient Forms WordPress.org package directory.
 *
 * The package intentionally excludes tests, logs, docs, non-runtime lockfiles, and
 * top-level build tooling. Runtime dependencies and generated admin assets are
 * copied explicitly; generated asset source is documented through the public
 * release source URL in readme.txt and assets/dist/SOURCE.md.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$plugin_root    = dirname( __DIR__ );
$workspace_root = dirname( $plugin_root );

date_default_timezone_set( getenv( 'SENTIENT_FORMS_PACKAGE_TZ' ) ?: getenv( 'TZ' ) ?: 'America/Chicago' );

$timestamp      = date( 'Y/m/d/His' );
$default_base   = $workspace_root . '/temp/' . $timestamp . '-wporg-package';
$output_base    = $argv[1] ?? $default_base;
$package_dir    = rtrim( $output_base, DIRECTORY_SEPARATOR ) . '/sentient-forms';

$include_paths = [
    'assets',
    'contracts/action-facet-policy-catalog.v1.json',
    'contracts/action-source-compatibility.v1.json',
    'includes',
    'languages',
    'CHANGELOG.md',
    'readme.txt',
    'sentient-forms.php',
];

$runtime_dependency_root = materialize_locked_runtime_dependencies( $plugin_root, $output_base );
$resolved_output_base    = realpath( $output_base );
if ( false === $resolved_output_base )
{
    throw new RuntimeException( "Unable to resolve package output base: {$output_base}" );
}
$inventory_path = rtrim( $resolved_output_base, '/\\' ) . DIRECTORY_SEPARATOR . 'sentient-forms.inventory.json';
try
{
    if ( is_link( $inventory_path ) )
    {
        throw new RuntimeException( "Package inventory output must not be a symlink: {$inventory_path}" );
    }
    if ( is_file( $inventory_path ) && ! unlink( $inventory_path ) )
    {
        throw new RuntimeException( "Unable to replace package inventory: {$inventory_path}" );
    }
    if ( is_link( $package_dir ) )
    {
        throw new RuntimeException( "Package output directory must not be a symlink: {$package_dir}" );
    }
    if ( file_exists( $package_dir ) )
    {
        remove_directory( $package_dir );
    }
    if ( ! mkdir( $package_dir, 0775, true ) && ! is_dir( $package_dir ) )
    {
        throw new RuntimeException( "Unable to create package directory: {$package_dir}" );
    }

    foreach ( $include_paths as $relative )
    {
        $source = $plugin_root . '/' . $relative;
        if ( ! file_exists( $source ) || is_link( $source ) )
        {
            throw new RuntimeException( "Required package input is unavailable: {$relative}" );
        }

        $target = $package_dir . '/' . $relative;
        if ( is_dir( $source ) )
        {
            copy_directory( $source, $target );
            continue;
        }
        copy_file( $source, $target );
    }

    $runtime_vendor = $runtime_dependency_root . '/vendor';
    if ( ! is_file( $runtime_vendor . '/autoload.php' ) )
    {
        throw new RuntimeException( 'Locked Composer install did not materialize the runtime autoloader.' );
    }
    if ( ! is_file( $runtime_vendor . '/woocommerce/action-scheduler/action-scheduler.php' ) )
    {
        throw new RuntimeException( 'Locked Composer install did not materialize Action Scheduler.' );
    }
    copy_directory(
        $runtime_vendor . '/woocommerce/action-scheduler',
        $package_dir . '/vendor/woocommerce/action-scheduler'
    );

    write_runtime_composer_manifest( $plugin_root . '/composer.json', $package_dir . '/composer.json' );

    if ( ! is_dir( $package_dir . '/assets/dist' ) )
    {
        throw new RuntimeException( 'Package is missing built admin assets. Run bun run build:wp first.' );
    }
    write_package_inventory( $package_dir, $inventory_path );
}
catch ( Throwable $error )
{
    if ( is_dir( $package_dir ) )
    {
        remove_directory( $package_dir );
    }
    if ( is_file( $inventory_path ) && ! is_link( $inventory_path ) )
    {
        @unlink( $inventory_path );
    }
    throw $error;
}
finally
{
    remove_runtime_dependency_directory( $runtime_dependency_root );
}

echo "Built WordPress.org package directory:\n{$package_dir}\n";
echo "Built expected package inventory:\n{$inventory_path}\n";

/**
 * Install locked production dependencies in an isolated, disposable tree.
 */
function materialize_locked_runtime_dependencies( string $plugin_root, string $output_base ): string
{
    foreach ( [ 'composer.json', 'composer.lock' ] as $manifest )
    {
        $path = $plugin_root . '/' . $manifest;
        if ( ! is_file( $path ) || is_link( $path ) )
        {
            throw new RuntimeException( "Package source is missing {$manifest}." );
        }
    }

    if ( ! is_dir( $output_base ) && ! mkdir( $output_base, 0775, true ) )
    {
        throw new RuntimeException( "Unable to create package output base: {$output_base}" );
    }
    if ( is_link( $output_base ) )
    {
        throw new RuntimeException( "Package output base must not be a symlink: {$output_base}" );
    }
    $resolved_output_base = realpath( $output_base );
    if ( false === $resolved_output_base )
    {
        throw new RuntimeException( "Unable to resolve package output base: {$output_base}" );
    }
    $directory = rtrim( $resolved_output_base, '/\\' )
        . DIRECTORY_SEPARATOR
        . '.sentient-forms-runtime-'
        . bin2hex( random_bytes( 12 ) );
    if ( ! mkdir( $directory, 0700, true ) )
    {
        throw new RuntimeException( "Unable to create runtime dependency directory: {$directory}" );
    }

    try
    {
        copy_file( $plugin_root . '/composer.json', $directory . '/composer.json' );
        copy_file( $plugin_root . '/composer.lock', $directory . '/composer.lock' );
        $composer = resolve_composer_command();

        $validate = run_locked_composer_command(
            array_merge(
                $composer,
                [ 'validate', '--working-dir=' . $directory, '--strict', '--no-check-publish', '--no-interaction' ]
            ),
            $directory
        );
        if ( 0 !== $validate['status'] )
        {
            throw new RuntimeException(
                "Locked production dependency validation failed.\n"
                . trim( $validate['stdout'] . "\n" . $validate['stderr'] )
            );
        }

        $install = run_locked_composer_command(
            array_merge(
                $composer,
                [
                    'install',
                    '--working-dir=' . $directory,
                    '--no-dev',
                    '--prefer-dist',
                    '--no-interaction',
                    '--no-progress',
                    '--no-scripts',
                    '--no-plugins',
                    '--classmap-authoritative',
                ]
            ),
            $directory
        );
        if ( 0 !== $install['status'] )
        {
            throw new RuntimeException(
                "Locked production dependency install failed.\n"
                . trim( $install['stdout'] . "\n" . $install['stderr'] )
            );
        }

        return $directory;
    }
    catch ( Throwable $error )
    {
        remove_runtime_dependency_directory( $directory );
        throw $error;
    }
}

/**
 * @param list<string> $command
 * @return array{status:int,stdout:string,stderr:string}
 */
function run_locked_composer_command( array $command, string $working_directory ): array
{
    $stdout_path = tempnam( $working_directory, '.composer-stdout-' );
    $stderr_path = tempnam( $working_directory, '.composer-stderr-' );
    if ( false === $stdout_path || false === $stderr_path )
    {
        throw new RuntimeException( 'Unable to create Composer diagnostic capture files.' );
    }

    $process = null;
    $environment = composer_subprocess_environment();
    try
    {
        $process = proc_open(
            $command,
            [
                1 => [ 'file', $stdout_path, 'w' ],
                2 => [ 'file', $stderr_path, 'w' ],
            ],
            $pipes,
            $working_directory,
            $environment
        );
        if ( ! is_resource( $process ) )
        {
            throw new RuntimeException( 'Unable to start Composer for locked runtime dependencies.' );
        }

        $timeout_seconds = composer_command_timeout_seconds();
        $deadline        = microtime( true ) + $timeout_seconds;
        $observed_exit   = null;
        while ( true )
        {
            $status = proc_get_status( $process );
            if ( ! $status['running'] )
            {
                $observed_exit = $status['exitcode'];
                break;
            }
            if ( microtime( true ) >= $deadline )
            {
                terminate_composer_process( $process );
                proc_close( $process );
                $process = null;
                throw new RuntimeException(
                    sprintf( 'Locked production dependency command timed out after %d seconds.', $timeout_seconds )
                );
            }
            usleep( 50000 );
        }

        $close_status = proc_close( $process );
        $process      = null;
        $status_code  = is_int( $observed_exit ) && $observed_exit >= 0 ? $observed_exit : $close_status;

        return [
            'status' => $status_code,
            'stdout' => redact_composer_diagnostic( read_bounded_composer_diagnostic( $stdout_path ), $environment ),
            'stderr' => redact_composer_diagnostic( read_bounded_composer_diagnostic( $stderr_path ), $environment ),
        ];
    }
    finally
    {
        if ( is_resource( $process ) )
        {
            terminate_composer_process( $process );
            proc_close( $process );
        }
        @unlink( $stdout_path );
        @unlink( $stderr_path );
    }
}

/**
 * Pass only the operating-system and Composer settings required by the child.
 *
 * @return array<string,string>
 */
function composer_subprocess_environment(): array
{
    $allowed = [
        'PATH',
        'SystemRoot',
        'WINDIR',
        'HOME',
        'USERPROFILE',
        'APPDATA',
        'LOCALAPPDATA',
        'TEMP',
        'TMP',
        'TMPDIR',
        'COMPOSER_AUTH',
        'COMPOSER_HOME',
        'COMPOSER_CACHE_DIR',
        'COMPOSER_ALLOW_SUPERUSER',
        'SSL_CERT_FILE',
        'SSL_CERT_DIR',
        'HTTP_PROXY',
        'HTTPS_PROXY',
        'NO_PROXY',
        'http_proxy',
        'https_proxy',
        'no_proxy',
    ];
    $environment = [];
    foreach ( $allowed as $name )
    {
        $value = getenv( $name );
        if ( false !== $value )
        {
            $environment[ $name ] = $value;
        }
    }
    return $environment;
}

function composer_command_timeout_seconds(): int
{
    $configured = getenv( 'SENTIENT_FORMS_COMPOSER_TIMEOUT_SECONDS' );
    if ( false === $configured || '' === trim( $configured ) )
    {
        return 300;
    }
    if ( ! ctype_digit( trim( $configured ) ) )
    {
        throw new RuntimeException( 'SENTIENT_FORMS_COMPOSER_TIMEOUT_SECONDS must be an integer.' );
    }

    $seconds = (int) $configured;
    if ( $seconds < 1 || $seconds > 900 )
    {
        throw new RuntimeException( 'SENTIENT_FORMS_COMPOSER_TIMEOUT_SECONDS must be between 1 and 900.' );
    }
    return $seconds;
}

/**
 * @param resource $process
 */
function terminate_composer_process( $process ): void
{
    @proc_terminate( $process );
    $deadline = microtime( true ) + 2.0;
    do
    {
        $status = proc_get_status( $process );
        if ( ! $status['running'] )
        {
            return;
        }
        usleep( 50000 );
    }
    while ( microtime( true ) < $deadline );

    @proc_terminate( $process, 9 );
}

function read_bounded_composer_diagnostic( string $path, int $limit = 16384 ): string
{
    $size = filesize( $path );
    if ( false === $size || 0 === $size )
    {
        return '';
    }
    if ( $size <= $limit )
    {
        return (string) file_get_contents( $path );
    }

    $handle = fopen( $path, 'rb' );
    if ( false === $handle )
    {
        return '';
    }
    $half  = intdiv( $limit, 2 );
    $first = (string) fread( $handle, $half );
    fseek( $handle, -$half, SEEK_END );
    $last = (string) fread( $handle, $half );
    fclose( $handle );

    return $first . "\n...[Composer diagnostic truncated]...\n" . $last;
}

/**
 * @param array<string,string> $environment
 */
function redact_composer_diagnostic( string $diagnostic, array $environment ): string
{
    $sensitive_values = [];
    foreach ( $environment as $name => $value )
    {
        if ( '' !== $value && preg_match( '/(?:AUTH|TOKEN|PASSWORD|SECRET|KEY)/i', $name ) )
        {
            $sensitive_values[] = $value;
        }
    }
    $composer_auth = $environment['COMPOSER_AUTH'] ?? '';
    $decoded_auth  = json_decode( $composer_auth, true );
    if ( is_array( $decoded_auth ) )
    {
        $iterator = new RecursiveIteratorIterator( new RecursiveArrayIterator( $decoded_auth ) );
        foreach ( $iterator as $value )
        {
            if ( is_string( $value ) && '' !== $value )
            {
                $sensitive_values[] = $value;
            }
        }
    }
    usort( $sensitive_values, static fn ( string $left, string $right ): int => strlen( $right ) <=> strlen( $left ) );
    if ( [] !== $sensitive_values )
    {
        $diagnostic = str_replace( array_unique( $sensitive_values ), '[redacted]', $diagnostic );
    }

    $patterns = [
        '#(https?://)[^/\s:@]+:[^@\s/]+@#i' => '$1[redacted]@',
        '/\b(authorization|api[_-]?key|password|secret|token)\s*[:=]\s*\S+/i' => '$1=[redacted]',
        '/\b(?:sk|pk)_[A-Za-z0-9_-]{12,}\b/' => '[redacted-key]',
        '/\bgh[pousr]_[A-Za-z0-9_]{20,}\b/' => '[redacted-key]',
        '/\bBearer\s+\S+/i' => 'Bearer [redacted]',
    ];

    return (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $diagnostic );
}

/**
 * @return list<string>
 */
function resolve_composer_command(): array
{
    $configured = getenv( 'COMPOSER_BINARY' ) ?: 'composer';
    $lowercase  = strtolower( $configured );
    if ( str_ends_with( $lowercase, '.phar' ) )
    {
        $resolved = realpath( $configured );
        if ( false === $resolved || ! is_file( $resolved ) )
        {
            throw new RuntimeException( "COMPOSER_BINARY does not exist: {$configured}" );
        }
        return [ PHP_BINARY, $resolved ];
    }
    if ( 'Windows' === PHP_OS_FAMILY
        && ( str_ends_with( $lowercase, '.bat' ) || str_ends_with( $lowercase, '.cmd' ) ) )
    {
        $sibling_phar = dirname( $configured ) . DIRECTORY_SEPARATOR . 'composer.phar';
        if ( is_file( $sibling_phar ) )
        {
            $resolved = realpath( $sibling_phar );
            if ( false !== $resolved )
            {
                return [ PHP_BINARY, $resolved ];
            }
        }
        throw new RuntimeException( 'Composer batch wrappers require a sibling composer.phar.' );
    }
    if ( 'Windows' !== PHP_OS_FAMILY || 'composer' !== $lowercase )
    {
        return [ $configured ];
    }

    foreach ( explode( PATH_SEPARATOR, getenv( 'PATH' ) ?: '' ) as $path )
    {
        $candidate = rtrim( trim( $path, '"' ), DIRECTORY_SEPARATOR )
            . DIRECTORY_SEPARATOR
            . 'composer.phar';
        if ( is_file( $candidate ) )
        {
            $resolved = realpath( $candidate );
            if ( false !== $resolved )
            {
                return [ PHP_BINARY, $resolved ];
            }
        }
    }

    throw new RuntimeException( 'Unable to locate composer.phar; set COMPOSER_BINARY explicitly.' );
}

function remove_runtime_dependency_directory( string $path ): void
{
    if ( ! is_dir( $path ) )
    {
        return;
    }
    if ( ! str_starts_with( basename( $path ), '.sentient-forms-runtime-' ) )
    {
        throw new RuntimeException( "Refusing to remove unexpected dependency path: {$path}" );
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $iterator as $item )
    {
        $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
    }
    rmdir( $path );
}

/**
 * Copy a directory recursively while excluding non-runtime artifacts.
 */
function copy_directory( string $source, string $target ): void
{
    assert_regular_source_tree( $source );
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ( $iterator as $item )
    {
        $source_path = $item->getPathname();
        $relative    = ltrim( str_replace( $source, '', $source_path ), DIRECTORY_SEPARATOR );
        $relative    = str_replace( DIRECTORY_SEPARATOR, '/', $relative );

        if ( should_skip_package_path( $relative, $item->isDir() ) )
        {
            continue;
        }

        $target_path = $target . '/' . $relative;
        if ( $item->isDir() )
        {
            if ( ! is_dir( $target_path ) && ! mkdir( $target_path, 0775, true ) )
            {
                throw new RuntimeException( "Unable to create directory: {$target_path}" );
            }
            continue;
        }

        copy_file( $source_path, $target_path );
    }
}

/**
 * Reject links and other reparse aliases anywhere in a copied source tree.
 */
function assert_regular_source_tree( string $source ): void
{
    $source_real = realpath( $source );
    if ( false === $source_real || is_link( $source ) )
    {
        throw new RuntimeException( "Package source directory must be regular: {$source}" );
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ( $iterator as $item )
    {
        $path = $item->getPathname();
        $real = $item->getRealPath();
        $relative = ltrim( substr( $path, strlen( $source ) ), '/\\' );
        $expected = $source_real . DIRECTORY_SEPARATOR . $relative;
        if ( $item->isLink()
            || false === $real
            || normalize_filesystem_identity( $real ) !== normalize_filesystem_identity( $expected ) )
        {
            throw new RuntimeException( "Package source tree contains a link or alias: {$path}" );
        }
        if ( ! $item->isDir() && ! $item->isFile() )
        {
            throw new RuntimeException( "Package source tree contains a non-regular entry: {$path}" );
        }
    }
}

function normalize_filesystem_identity( string $path ): string
{
    $normalized = str_replace( '\\', '/', $path );
    return 'Windows' === PHP_OS_FAMILY ? strtolower( $normalized ) : $normalized;
}

/**
 * Copy a single file, creating the destination directory when necessary.
 */
function copy_file( string $source, string $target ): void
{
    $target_dir = dirname( $target );
    if ( ! is_dir( $target_dir ) && ! mkdir( $target_dir, 0775, true ) )
    {
        throw new RuntimeException( "Unable to create directory: {$target_dir}" );
    }

    if ( ! copy( $source, $target ) )
    {
        throw new RuntimeException( "Unable to copy {$source} to {$target}" );
    }
}

/**
 * Return whether a relative runtime path should be excluded from the package.
 */
function should_skip_package_path( string $relative, bool $is_dir ): bool
{
    $parts = explode( '/', $relative );

    foreach ( $parts as $part )
    {
        if ( str_starts_with( $part, '.' ) )
        {
            return true;
        }
    }

    if ( str_starts_with( $relative, 'admin/views/' ) )
    {
        return true;
    }

    if ( in_array( $relative, [ 'Dockerfile.dev', 'playwright.config.ts', 'vitest.config.ts' ], true ) )
    {
        return true;
    }

    if ( in_array( '.DS_Store', $parts, true ) || in_array( '__MACOSX', $parts, true ) )
    {
        return true;
    }

    if ( $is_dir && in_array( $relative, [ '.git', '.svelte-kit', 'build', 'docs', 'node_modules', 'playwright-report', 'test-results', 'tests' ], true ) )
    {
        return true;
    }

    return (bool) preg_match(
        '/(?:^|\/)(?:composer\.lock|bun\.lock|package-lock\.json|pnpm-lock\.yaml|yarn\.lock|.*\.map|\.env(?:\..*)?)$/',
        $relative
    );
}

/**
 * Write package-safe Composer metadata that does not advertise omitted tooling.
 */
function write_runtime_composer_manifest( string $source, string $target ): void
{
    if ( ! file_exists( $source ) )
    {
        return;
    }

    $decoded = json_decode( (string) file_get_contents( $source ), true );
    if ( ! is_array( $decoded ) )
    {
        throw new RuntimeException( "Unable to parse Composer manifest: {$source}" );
    }

    $runtime = [];
    foreach ( [ 'name', 'description', 'license', 'type', 'require' ] as $field )
    {
        if ( array_key_exists( $field, $decoded ) )
        {
            $runtime[ $field ] = $decoded[ $field ];
        }
    }

    $encoded = json_encode( $runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    if ( ! is_string( $encoded ) )
    {
        throw new RuntimeException( 'Unable to encode runtime Composer manifest.' );
    }

    copy_file_contents( $encoded . "\n", $target );
}

/**
 * Write file contents, creating the destination directory when necessary.
 */
function copy_file_contents( string $contents, string $target ): void
{
    $target_dir = dirname( $target );
    if ( ! is_dir( $target_dir ) && ! mkdir( $target_dir, 0775, true ) )
    {
        throw new RuntimeException( "Unable to create directory: {$target_dir}" );
    }

    if ( false === file_put_contents( $target, $contents ) )
    {
        throw new RuntimeException( "Unable to write {$target}" );
    }
}

/**
 * Record the exact package bytes outside the distributable for archive verification.
 */
function write_package_inventory( string $package_dir, string $inventory_path ): void
{
    $package_root = realpath( $package_dir );
    if ( false === $package_root || is_link( $package_dir ) )
    {
        throw new RuntimeException( "Package directory must be regular before inventory: {$package_dir}" );
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $package_root, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ( $iterator as $item )
    {
        if ( ! $item->isFile() || $item->isLink() )
        {
            throw new RuntimeException( "Package inventory contains a non-regular entry: {$item->getPathname()}" );
        }
        $real_path = $item->getRealPath();
        if ( false === $real_path
            || normalize_filesystem_identity( $real_path )
                !== normalize_filesystem_identity( $item->getPathname() ) )
        {
            throw new RuntimeException( "Package inventory contains a link or alias: {$item->getPathname()}" );
        }
        $relative = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr( $item->getPathname(), strlen( $package_root ) + 1 )
        );
        $size = $item->getSize();
        $sha256 = hash_file( 'sha256', $item->getPathname() );
        if ( false === $sha256 )
        {
            throw new RuntimeException( "Unable to hash package inventory entry: {$relative}" );
        }
        $files[] = [
            'path'   => $relative,
            'sha256' => $sha256,
            'size'   => $size,
        ];
    }
    usort(
        $files,
        static fn ( array $left, array $right ): int => strcmp( $left['path'], $right['path'] )
    );

    $encoded = json_encode(
        [ 'schema_version' => 1, 'files' => $files ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
    if ( ! is_string( $encoded ) )
    {
        throw new RuntimeException( 'Unable to encode expected package inventory.' );
    }

    $temporary = $inventory_path . '.tmp-' . bin2hex( random_bytes( 8 ) );
    try
    {
        if ( false === file_put_contents( $temporary, $encoded . "\n" )
            || ! rename( $temporary, $inventory_path ) )
        {
            throw new RuntimeException( "Unable to write expected package inventory: {$inventory_path}" );
        }
    }
    finally
    {
        @unlink( $temporary );
    }
}

/**
 * Remove a package directory recursively.
 */
function remove_directory( string $path ): void
{
    if ( basename( $path ) !== 'sentient-forms' )
    {
        throw new RuntimeException( "Refusing to remove unexpected path: {$path}" );
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $iterator as $item )
    {
        if ( $item->isDir() )
        {
            rmdir( $item->getPathname() );
            continue;
        }

        unlink( $item->getPathname() );
    }

    rmdir( $path );
}
