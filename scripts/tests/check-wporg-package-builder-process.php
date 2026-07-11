<?php

/**
 * Verify that the package builder bounds and terminates Composer subprocesses.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );
$builder     = $plugin_root . '/scripts/build-wporg-package.php';
$fixture     = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
    . DIRECTORY_SEPARATOR
    . 'sentient-forms-package-process-'
    . bin2hex( random_bytes( 8 ) )
    . '.phar';
$output      = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
    . DIRECTORY_SEPARATOR
    . 'sentient-forms-package-output-'
    . bin2hex( random_bytes( 8 ) );
$errors      = [];

try
{
    file_put_contents(
        $fixture,
        "<?php\nfwrite(STDERR, 'https://user:super-secret@example.test/private\\n');\nfwrite(STDERR, str_repeat('x', 262144));\nexit(7);\n"
    );
    $result = run_bounded_process(
        [ PHP_BINARY, $builder, $output ],
        $plugin_root,
        [
            'COMPOSER_BINARY' => $fixture,
            'SENTIENT_FORMS_COMPOSER_TIMEOUT_SECONDS' => '3',
        ],
        8.0
    );
    if ( $result['timed_out'] )
    {
        $errors[] = 'The package builder deadlocked while Composer filled stderr.';
    }
    if ( 0 === $result['status'] )
    {
        $errors[] = 'The package builder accepted a failed Composer install.';
    }
    $diagnostic = $result['stdout'] . $result['stderr'];
    if ( str_contains( $diagnostic, 'super-secret' ) )
    {
        $errors[] = 'Composer diagnostics exposed URL credentials.';
    }
    if ( strlen( $diagnostic ) > 65536 )
    {
        $errors[] = 'Composer diagnostics were not bounded.';
    }

    file_put_contents( $fixture, "<?php\nsleep(10);\nexit(0);\n" );
    $timeout_result = run_bounded_process(
        [ PHP_BINARY, $builder, $output ],
        $plugin_root,
        [
            'COMPOSER_BINARY' => $fixture,
            'SENTIENT_FORMS_COMPOSER_TIMEOUT_SECONDS' => '1',
        ],
        6.0
    );
    if ( $timeout_result['timed_out'] )
    {
        $errors[] = 'The package builder did not enforce its Composer timeout.';
    }
    if ( ! str_contains( strtolower( $timeout_result['stdout'] . $timeout_result['stderr'] ), 'timed out' ) )
    {
        $errors[] = 'The package builder timeout did not produce a stable diagnostic.';
    }
}
catch ( Throwable $error )
{
    $errors[] = $error->getMessage();
}
finally
{
    @unlink( $fixture );
    remove_owned_output_directory( $output );
}

if ( [] !== $errors )
{
    fwrite( STDERR, "WP.org package builder process test failed:\n" );
    foreach ( $errors as $error )
    {
        fwrite( STDERR, "- {$error}\n" );
    }
    exit( 1 );
}

echo "WP.org package builder process test passed.\n";

/**
 * @param list<string>         $command
 * @param array<string,string> $extra_environment
 * @return array{status:int,stdout:string,stderr:string,timed_out:bool}
 */
function run_bounded_process(
    array $command,
    string $working_directory,
    array $extra_environment,
    float $timeout_seconds
): array
{
    $stdout_path = tempnam( sys_get_temp_dir(), 'sf-package-test-stdout-' );
    $stderr_path = tempnam( sys_get_temp_dir(), 'sf-package-test-stderr-' );
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
        throw new RuntimeException( 'Unable to create package process capture files.' );
    }

    $process = proc_open(
        $command,
        [
            1 => [ 'file', $stdout_path, 'w' ],
            2 => [ 'file', $stderr_path, 'w' ],
        ],
        $pipes,
        $working_directory,
        array_merge( getenv(), $extra_environment )
    );
    if ( ! is_resource( $process ) )
    {
        @unlink( $stdout_path );
        @unlink( $stderr_path );
        throw new RuntimeException( 'Unable to start package builder process.' );
    }

    $deadline  = microtime( true ) + $timeout_seconds;
    $timed_out = false;
    $observed_exit_code = null;
    while ( true )
    {
        $status = proc_get_status( $process );
        if ( ! $status['running'] )
        {
            $observed_exit_code = $status['exitcode'];
            break;
        }
        if ( microtime( true ) >= $deadline )
        {
            $timed_out = true;
            proc_terminate( $process );
            usleep( 200000 );
            $status = proc_get_status( $process );
            if ( $status['running'] )
            {
                proc_terminate( $process, 9 );
            }
            break;
        }
        usleep( 50000 );
    }
    $close_status = proc_close( $process );
    $status_code  = is_int( $observed_exit_code ) && $observed_exit_code >= 0
        ? $observed_exit_code
        : $close_status;
    $stdout      = (string) file_get_contents( $stdout_path );
    $stderr      = (string) file_get_contents( $stderr_path );
    @unlink( $stdout_path );
    @unlink( $stderr_path );

    return [
        'status'    => $status_code,
        'stdout'    => $stdout,
        'stderr'    => $stderr,
        'timed_out' => $timed_out,
    ];
}

function remove_owned_output_directory( string $path ): void
{
    if ( ! is_dir( $path ) || ! str_starts_with( basename( $path ), 'sentient-forms-package-output-' ) )
    {
        return;
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
