<?php

/**
 * Verify locked dependency failures, redaction, bounds, and timeouts.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );
$builder     = $plugin_root . '/scripts/build-wporg-package.php';
$fixture     = $plugin_root . '/.item14-composer-fixture.phar';
$output      = $plugin_root . '/.item14-package-process-output';
$nested_link = $plugin_root . '/assets/.item14-nested-source-link';
$nested_target = $plugin_root . '/.item14-nested-source-target';
$empty_source = $plugin_root . '/assets/item14-empty-source-directory';
$composer_token = 'ghp_item14SensitiveToken1234567890';
$composer_password = 'item14-composer-password';
$composer_auth = json_encode(
    [
        'github-oauth' => [ 'github.com' => $composer_token ],
        'http-basic'   => [
            'repo.example.test' => [
                'username' => 'item14-builder',
                'password' => $composer_password,
            ],
        ],
    ],
    JSON_UNESCAPED_SLASHES
);
$errors      = [];

try
{
    putenv( 'COMPOSER_AUTH=' . $composer_auth );
    putenv( 'ITEM14_UNRELATED_SECRET=must-not-reach-composer' );
    file_put_contents(
        $fixture,
        "<?php\n"
        . "if (false !== getenv('ITEM14_UNRELATED_SECRET')) { fwrite(STDERR, 'UNRELATED_ENV_PRESENT\\n'); }\n"
        . "fwrite(STDERR, (string) getenv('COMPOSER_AUTH') . \"\\n\");\n"
        . "fwrite(STDERR, 'https://user:super-secret@example.test/private\\n');\n"
        . "fwrite(STDERR, str_repeat('x', 262144));\nexit(7);\n"
    );
    $failed = run_builder( $builder, $plugin_root, $output, $fixture, 3, 8.0 );
    if ( $failed['outer_timeout'] )
    {
        $errors[] = 'The builder deadlocked while Composer filled stderr.';
    }
    if ( 0 === $failed['status'] )
    {
        $errors[] = 'The builder accepted a failed locked dependency command.';
    }
    $diagnostic = $failed['stdout'] . $failed['stderr'];
    if ( str_contains( $diagnostic, 'super-secret' ) )
    {
        $errors[] = 'Composer diagnostics exposed URL credentials.';
    }
    foreach ( [ $composer_token, $composer_password ] as $sensitive_value )
    {
        if ( str_contains( $diagnostic, $sensitive_value ) )
        {
            $errors[] = 'Composer diagnostics exposed a configured Composer auth value.';
        }
    }
    if ( str_contains( $diagnostic, 'UNRELATED_ENV_PRESENT' ) )
    {
        $errors[] = 'Composer inherited an unrelated parent environment value.';
    }
    if ( strlen( $diagnostic ) > 65536 )
    {
        $errors[] = 'Composer diagnostics were not bounded.';
    }

    file_put_contents( $fixture, "<?php\nsleep(10);\nexit(0);\n" );
    $timed_out = run_builder( $builder, $plugin_root, $output, $fixture, 1, 6.0 );
    if ( $timed_out['outer_timeout'] )
    {
        $errors[] = 'The builder did not enforce its own Composer timeout.';
    }
    if ( 0 === $timed_out['status'] )
    {
        $errors[] = 'The builder accepted a timed-out dependency command.';
    }
    if ( ! str_contains( strtolower( $timed_out['stdout'] . $timed_out['stderr'] ), 'timed out' ) )
    {
        $errors[] = 'The builder timeout did not produce a stable diagnostic.';
    }

    file_put_contents(
        $fixture,
        <<<'PHP'
<?php
$working_directory = '';
foreach ( $argv as $argument )
{
    if ( str_starts_with( $argument, '--working-dir=' ) )
    {
        $working_directory = substr( $argument, strlen( '--working-dir=' ) );
    }
}
if ( '' === $working_directory || ! is_dir( $working_directory ) )
{
    fwrite( STDERR, "Invalid working directory: {$working_directory}\n" );
    exit( 9 );
}
if ( in_array( 'install', $argv, true ) )
{
    mkdir( $working_directory . '/vendor/woocommerce/action-scheduler', 0775, true );
    file_put_contents( $working_directory . '/vendor/autoload.php', "<?php\n" );
    file_put_contents(
        $working_directory . '/vendor/woocommerce/action-scheduler/action-scheduler.php',
        "<?php\n"
    );
}
exit( 0 );
PHP
    );
    mkdir( $nested_target, 0775, true );
    file_put_contents( $nested_target . '/linked.php', "<?php\n" );
    if ( ! create_directory_link( $nested_target, $nested_link ) )
    {
        $errors[] = 'The nested source symlink fixture could not be created.';
    }
    else
    {
        $linked_source = run_builder( $builder, $plugin_root, $output, $fixture, 3, 8.0 );
        if ( 0 === $linked_source['status'] )
        {
            $errors[] = 'The builder accepted a nested source symlink.';
        }
        remove_directory_link( $nested_link );
    }
    $relative_composer = run_builder(
        $builder,
        $plugin_root,
        $output,
        basename( $fixture ),
        3,
        8.0
    );
    if ( 0 !== $relative_composer['status'] )
    {
        $errors[] = 'The builder rejected a valid relative Composer PHAR path.';
    }
    mkdir( $empty_source, 0775, true );
    $empty_directory = run_builder( $builder, $plugin_root, $output, $fixture, 3, 8.0 );
    if ( 0 !== $empty_directory['status'] )
    {
        $errors[] = 'The builder rejected an otherwise valid package containing an empty source directory.';
    }
    @rmdir( $empty_source );
    $relative_output = '.item14-package-relative-output';
    $relative = run_builder( $builder, $plugin_root, $relative_output, $fixture, 3, 8.0 );
    if ( 0 !== $relative['status'] )
    {
        $errors[] = 'The builder rejected a valid relative output base: ' . trim( $relative['stderr'] );
    }
    elseif ( ! is_file( $plugin_root . '/' . $relative_output . '/sentient-forms.inventory.json' ) )
    {
        $errors[] = 'The builder did not produce an expected package inventory.';
    }
}
finally
{
    putenv( 'COMPOSER_AUTH' );
    putenv( 'ITEM14_UNRELATED_SECRET' );
    @unlink( $fixture );
    remove_directory_link( $nested_link );
    @unlink( $nested_target . '/linked.php' );
    @rmdir( $nested_target );
    @rmdir( $empty_source );
    remove_owned_directory( $output );
    remove_owned_directory( $plugin_root . '/.item14-package-relative-output' );
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

echo "WP.org package builder process test passed: failure, redaction, bounds, and timeout.\n";

function create_directory_link( string $target, string $link ): bool
{
    if ( 'Windows' !== PHP_OS_FAMILY )
    {
        return symlink( $target, $link );
    }

    $command = 'mklink /J ' . escapeshellarg( $link ) . ' ' . escapeshellarg( $target );
    $process = proc_open(
        $command,
        [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
        $pipes
    );
    if ( ! is_resource( $process ) )
    {
        return false;
    }
    stream_get_contents( $pipes[1] );
    stream_get_contents( $pipes[2] );
    fclose( $pipes[1] );
    fclose( $pipes[2] );
    return 0 === proc_close( $process ) && is_dir( $link );
}

function remove_directory_link( string $link ): void
{
    if ( is_link( $link ) )
    {
        @unlink( $link );
        return;
    }

    @rmdir( $link );
}

/**
 * @return array{status:int,stdout:string,stderr:string,outer_timeout:bool}
 */
function run_builder(
    string $builder,
    string $working_directory,
    string $output,
    string $composer,
    int $composer_timeout,
    float $outer_timeout
): array
{
    $stdout_path = $working_directory . '/.item14-builder-stdout.txt';
    $stderr_path = $working_directory . '/.item14-builder-stderr.txt';
    $process = proc_open(
        [ PHP_BINARY, $builder, $output ],
        [
            1 => [ 'file', $stdout_path, 'w' ],
            2 => [ 'file', $stderr_path, 'w' ],
        ],
        $pipes,
        $working_directory,
        array_merge(
            getenv(),
            [
                'COMPOSER_BINARY' => $composer,
                'SENTIENT_FORMS_COMPOSER_TIMEOUT_SECONDS' => (string) $composer_timeout,
            ]
        )
    );
    if ( ! is_resource( $process ) )
    {
        throw new RuntimeException( 'Unable to start package builder.' );
    }

    $deadline = microtime( true ) + $outer_timeout;
    $outer_timed_out = false;
    $observed_exit = null;
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
            $outer_timed_out = true;
            proc_terminate( $process, 9 );
            break;
        }
        usleep( 50000 );
    }
    $close_status = proc_close( $process );
    $status_code = is_int( $observed_exit ) && $observed_exit >= 0 ? $observed_exit : $close_status;
    $stdout = (string) @file_get_contents( $stdout_path );
    $stderr = (string) @file_get_contents( $stderr_path );
    @unlink( $stdout_path );
    @unlink( $stderr_path );

    return [
        'status'        => $status_code,
        'stdout'        => $stdout,
        'stderr'        => $stderr,
        'outer_timeout' => $outer_timed_out,
    ];
}

function remove_owned_directory( string $path ): void
{
    if ( ! is_dir( $path )
        || ! in_array( basename( $path ), [ '.item14-package-process-output', '.item14-package-relative-output' ], true ) )
    {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $iterator as $item )
    {
        $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
    }
    @rmdir( $path );
}
