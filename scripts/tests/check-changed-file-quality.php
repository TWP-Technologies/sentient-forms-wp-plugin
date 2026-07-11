<?php

/**
 * Exercise the changed-file quality guard through its CLI boundary.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );
$guard       = $plugin_root . '/scripts/check-changed-file-quality.php';
$errors      = [];

if ( ! is_file( $guard ) )
{
    fwrite( STDERR, "Changed-file quality guard test failed:\n- Missing scripts/check-changed-file-quality.php.\n" );
    exit( 1 );
}

$fixture_root = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
    . DIRECTORY_SEPARATOR
    . 'sentient-forms-quality-guard-'
    . bin2hex( random_bytes( 8 ) );

try
{
    mkdir( $fixture_root . '/scripts', 0775, true );
    mkdir( $fixture_root . '/vendor/bin', 0775, true );
    copy( $guard, $fixture_root . '/scripts/check-changed-file-quality.php' );

    $commands = [
        [ 'git', 'init', '--initial-branch=production' ],
        [ 'git', 'config', 'user.email', 'quality-guard@example.test' ],
        [ 'git', 'config', 'user.name', 'Quality Guard Test' ],
    ];
    foreach ( $commands as $command )
    {
        $result = run_process( $command, $fixture_root );
        if ( 0 !== $result['status'] )
        {
            throw new RuntimeException( trim( $result['stderr'] ?: $result['stdout'] ) );
        }
    }

    file_put_contents( $fixture_root . '/baseline.php', "<?php\n\necho 'baseline';\n" );
    file_put_contents( $fixture_root . '/changed.php', "<?php\n\necho 'original';\n" );
    run_required_process( [ 'git', 'add', '.' ], $fixture_root );
    run_required_process( [ 'git', 'commit', '-m', 'test: baseline' ], $fixture_root );
    $base = trim( run_required_process( [ 'git', 'rev-parse', 'HEAD' ], $fixture_root )['stdout'] );

    file_put_contents( $fixture_root . '/changed.php', "<?php\n\t echo 'bad';    \n" );
    $red = run_process(
        [ PHP_BINARY, $fixture_root . '/scripts/check-changed-file-quality.php', '--base=' . $base ],
        $fixture_root,
        [ 'SENTIENT_FORMS_PHPCS_BINARY' => $plugin_root . '/vendor/squizlabs/php_codesniffer/bin/phpcs' ]
    );
    if ( 0 === $red['status'] )
    {
        $errors[] = 'The guard accepted changed PHP containing a tab and trailing whitespace.';
    }
    if ( ! str_contains( $red['stdout'] . $red['stderr'], 'changed.php' ) )
    {
        $errors[] = 'The guard failure did not identify changed.php.';
    }

    file_put_contents( $fixture_root . '/changed.php', "<?php\n\necho 'good';\n" );
    $green = run_process(
        [ PHP_BINARY, $fixture_root . '/scripts/check-changed-file-quality.php', '--base=' . $base ],
        $fixture_root,
        [ 'SENTIENT_FORMS_PHPCS_BINARY' => $plugin_root . '/vendor/squizlabs/php_codesniffer/bin/phpcs' ]
    );
    if ( 0 !== $green['status'] )
    {
        $errors[] = 'The guard rejected a whitespace-clean changed PHP file: ' . trim( $green['stderr'] ?: $green['stdout'] );
    }

    $invalid_base = run_process(
        [ PHP_BINARY, $fixture_root . '/scripts/check-changed-file-quality.php', '--base=missing-quality-base' ],
        $fixture_root,
        [ 'SENTIENT_FORMS_PHPCS_BINARY' => $plugin_root . '/vendor/squizlabs/php_codesniffer/bin/phpcs' ]
    );
    if ( 0 === $invalid_base['status'] )
    {
        $errors[] = 'The guard silently fell back after an explicit invalid base instead of failing closed.';
    }
}
catch ( Throwable $error )
{
    $errors[] = $error->getMessage();
}
finally
{
    remove_fixture_directory( $fixture_root );
}

if ( [] !== $errors )
{
    fwrite( STDERR, "Changed-file quality guard test failed:\n" );
    foreach ( $errors as $error )
    {
        fwrite( STDERR, "- {$error}\n" );
    }
    exit( 1 );
}

echo "Changed-file quality guard test passed.\n";

/**
 * @param list<string>         $command
 * @param array<string,string> $extra_environment
 * @return array{status:int,stdout:string,stderr:string}
 */
function run_process( array $command, string $working_directory, array $extra_environment = [] ): array
{
    $stdout_path = tempnam( sys_get_temp_dir(), 'sf-quality-test-stdout-' );
    $stderr_path = tempnam( sys_get_temp_dir(), 'sf-quality-test-stderr-' );
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
        throw new RuntimeException( 'Unable to allocate fixture output captures.' );
    }
    $descriptors = [
        1 => [ 'file', $stdout_path, 'w' ],
        2 => [ 'file', $stderr_path, 'w' ],
    ];
    $environment = array_merge( getenv(), $extra_environment );
    $process = proc_open( $command, $descriptors, $pipes, $working_directory, $environment );
    if ( ! is_resource( $process ) )
    {
        @unlink( $stdout_path );
        @unlink( $stderr_path );
        throw new RuntimeException( 'Unable to start fixture process.' );
    }

    $status = proc_close( $process );
    $stdout = file_get_contents( $stdout_path );
    $stderr = file_get_contents( $stderr_path );
    @unlink( $stdout_path );
    @unlink( $stderr_path );

    return [
        'status' => $status,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
    ];
}

/**
 * @param list<string> $command
 * @return array{status:int,stdout:string,stderr:string}
 */
function run_required_process( array $command, string $working_directory ): array
{
    $result = run_process( $command, $working_directory );
    if ( 0 !== $result['status'] )
    {
        throw new RuntimeException( trim( $result['stderr'] ?: $result['stdout'] ) );
    }
    return $result;
}

function remove_fixture_directory( string $path ): void
{
    if ( ! is_dir( $path ) || ! str_starts_with( basename( $path ), 'sentient-forms-quality-guard-' ) )
    {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $iterator as $item )
    {
        if ( $item->isDir() )
        {
            @chmod( $item->getPathname(), 0775 );
            @rmdir( $item->getPathname() );
            continue;
        }
        @chmod( $item->getPathname(), 0664 );
        @unlink( $item->getPathname() );
    }
    @rmdir( $path );
}
