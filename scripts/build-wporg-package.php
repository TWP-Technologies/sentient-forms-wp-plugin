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
    'includes',
    'languages',
    'CHANGELOG.md',
    'sentient-forms.php',
];

if ( file_exists( $plugin_root . '/readme.txt' ) )
{
    $include_paths[] = 'readme.txt';
}

if ( file_exists( $package_dir ) )
{
    remove_directory( $package_dir );
}

if ( ! mkdir( $package_dir, 0775, true ) && ! is_dir( $package_dir ) )
{
    fwrite( STDERR, "Unable to create package directory: {$package_dir}\n" );
    exit( 1 );
}

$runtime_dependency_root = materialize_locked_runtime_dependencies( $plugin_root );
try
{
    foreach ( $include_paths as $relative )
    {
        $source = $plugin_root . '/' . $relative;
        if ( ! file_exists( $source ) )
        {
            continue;
        }

        $target = $package_dir . '/' . $relative;
        if ( is_dir( $source ) )
        {
            copy_directory( $source, $target );
            continue;
        }

        copy_file( $source, $target );
    }

    $action_scheduler = $runtime_dependency_root . '/vendor/woocommerce/action-scheduler';
    if ( ! is_file( $action_scheduler . '/action-scheduler.php' ) )
    {
        throw new RuntimeException( 'Locked Composer install did not materialize Action Scheduler.' );
    }
    copy_directory( $action_scheduler, $package_dir . '/vendor/woocommerce/action-scheduler' );

    write_runtime_composer_manifest( $plugin_root . '/composer.json', $package_dir . '/composer.json' );

    foreach ( [ 'languages' ] as $required_directory )
    {
        $required_path = $package_dir . '/' . $required_directory;
        if ( ! is_dir( $required_path ) && ! mkdir( $required_path, 0775, true ) )
        {
            throw new RuntimeException( "Unable to create required package directory: {$required_path}" );
        }
    }

    if ( ! is_dir( $package_dir . '/assets/dist' ) )
    {
        throw new RuntimeException( 'Package is missing built admin assets. Run bun run build:wp first.' );
    }
}
finally
{
    remove_runtime_dependency_directory( $runtime_dependency_root );
}

echo "Built WordPress.org package directory:\n{$package_dir}\n";

/**
 * Install production dependencies from the committed lockfile in an isolated tree.
 */
function materialize_locked_runtime_dependencies( string $plugin_root ): string
{
    foreach ( [ 'composer.json', 'composer.lock' ] as $manifest )
    {
        if ( ! is_file( $plugin_root . '/' . $manifest ) )
        {
            throw new RuntimeException( "Package source is missing {$manifest}." );
        }
    }

    $directory = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
        . DIRECTORY_SEPARATOR
        . 'sentient-forms-runtime-'
        . bin2hex( random_bytes( 12 ) );
    if ( ! mkdir( $directory, 0700, true ) )
    {
        throw new RuntimeException( "Unable to create runtime dependency directory: {$directory}" );
    }

    try
    {
        copy_file( $plugin_root . '/composer.json', $directory . '/composer.json' );
        copy_file( $plugin_root . '/composer.lock', $directory . '/composer.lock' );
        $command = array_merge( resolve_composer_command(), [
            'install',
            '--working-dir=' . $directory,
            '--no-dev',
            '--prefer-dist',
            '--no-interaction',
            '--no-progress',
            '--no-scripts',
            '--no-plugins',
            '--no-autoloader',
        ] );
        $result = run_locked_composer_install( $command );
        if ( 0 !== $result['status'] )
        {
            throw new RuntimeException(
                "Locked production dependency install failed.\n"
                . trim( $result['stdout'] . "\n" . $result['stderr'] )
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
 * Run Composer with bounded file captures so one noisy stream cannot deadlock the other.
 *
 * @param list<string> $command
 * @return array{status:int,stdout:string,stderr:string}
 */
function run_locked_composer_install( array $command ): array
{
    $stdout_path = tempnam( sys_get_temp_dir(), 'sentient-forms-composer-stdout-' );
    $stderr_path = tempnam( sys_get_temp_dir(), 'sentient-forms-composer-stderr-' );
    if ( false === $stdout_path || false === $stderr_path )
    {
        if ( is_string( $stdout_path ) )
        {
            @unlink( $stdout_path );
        }
        if ( is_string( $stderr_path ) )
        {
            @unlink( $stderr_path );
        }
        throw new RuntimeException( 'Unable to create Composer diagnostic capture files.' );
    }

    $process = null;
    try
    {
        $process = proc_open(
            $command,
            [
                1 => [ 'file', $stdout_path, 'w' ],
                2 => [ 'file', $stderr_path, 'w' ],
            ],
            $pipes
        );
        if ( ! is_resource( $process ) )
        {
            throw new RuntimeException( 'Unable to start Composer for locked runtime dependencies.' );
        }

        $timeout_seconds = composer_install_timeout_seconds();
        $deadline        = microtime( true ) + $timeout_seconds;
        $observed_exit   = null;
        while ( true )
        {
            $process_status = proc_get_status( $process );
            if ( ! $process_status['running'] )
            {
                $observed_exit = $process_status['exitcode'];
                break;
            }
            if ( microtime( true ) >= $deadline )
            {
                terminate_composer_process( $process );
                proc_close( $process );
                $process = null;
                throw new RuntimeException(
                    sprintf( 'Locked production dependency install timed out after %d seconds.', $timeout_seconds )
                );
            }
            usleep( 50000 );
        }

        $close_status = proc_close( $process );
        $process      = null;
        $status       = is_int( $observed_exit ) && $observed_exit >= 0 ? $observed_exit : $close_status;

        return [
            'status' => $status,
            'stdout' => redact_composer_diagnostic( read_bounded_composer_diagnostic( $stdout_path ) ),
            'stderr' => redact_composer_diagnostic( read_bounded_composer_diagnostic( $stderr_path ) ),
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

function composer_install_timeout_seconds(): int
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
 * Terminate Composer and give it a bounded grace period before forcing termination.
 *
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

function redact_composer_diagnostic( string $diagnostic ): string
{
    $patterns = [
        '#(https?://)[^/\s:@]+:[^@\s/]+@#i' => '$1[redacted]@',
        '/\b(authorization|api[_-]?key|password|secret|token)\s*[:=]\s*\S+/i' => '$1=[redacted]',
        '/\b(?:sk|pk)_[A-Za-z0-9_-]{12,}\b/' => '[redacted-key]',
        '/\bBearer\s+\S+/i' => 'Bearer [redacted]',
    ];

    return (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $diagnostic );
}

/**
 * Resolve Composer without relying on Windows PATHEXT shell behavior.
 *
 * @return list<string>
 */
function resolve_composer_command(): array
{
    $configured = getenv( 'COMPOSER_BINARY' ) ?: 'composer';
    $lowercase  = strtolower( $configured );
    if ( str_ends_with( $lowercase, '.phar' ) )
    {
        if ( ! is_file( $configured ) )
        {
            throw new RuntimeException( "COMPOSER_BINARY does not exist: {$configured}" );
        }
        return [ PHP_BINARY, $configured ];
    }
    if ( 'Windows' === PHP_OS_FAMILY
        && ( str_ends_with( $lowercase, '.bat' ) || str_ends_with( $lowercase, '.cmd' ) ) )
    {
        $sibling_phar = dirname( $configured ) . DIRECTORY_SEPARATOR . 'composer.phar';
        if ( is_file( $sibling_phar ) )
        {
            return [ PHP_BINARY, $sibling_phar ];
        }
        throw new RuntimeException(
            'COMPOSER_BINARY batch wrappers require a sibling composer.phar for shell-free execution.'
        );
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
            return [ PHP_BINARY, $candidate ];
        }
    }

    throw new RuntimeException(
        'Unable to locate composer.phar on PATH; set COMPOSER_BINARY to an executable or PHAR path.'
    );
}

/**
 * Remove an isolated runtime dependency materialization.
 */
function remove_runtime_dependency_directory( string $path ): void
{
    if ( ! is_dir( $path ) )
    {
        return;
    }
    if ( ! str_starts_with( basename( $path ), 'sentient-forms-runtime-' ) )
    {
        throw new RuntimeException( "Refusing to remove unexpected dependency path: {$path}" );
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

/**
 * Copy a directory recursively while excluding non-runtime artifacts.
 */
function copy_directory( string $source, string $target ): void
{
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
