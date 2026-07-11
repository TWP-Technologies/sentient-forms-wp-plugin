<?php

/**
 * Fail closed when changed files contain whitespace defects hidden by narrow legacy QA scopes.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$plugin_root = dirname( __DIR__ );
$base        = changed_file_quality_base( array_slice( $argv, 1 ), $plugin_root );
if ( null === $base )
{
    fwrite( STDERR, "Unable to resolve a changed-file quality base commit.\n" );
    exit( 1 );
}

$diff_check = run_quality_process( [ 'git', 'diff', '--check', $base, '--' ], $plugin_root );
if ( 0 !== $diff_check['status'] )
{
    fwrite( STDERR, $diff_check['stdout'] . $diff_check['stderr'] );
    exit( 1 );
}

$changed = run_quality_process(
    [ 'git', 'diff', '--name-only', '--diff-filter=ACMR', '-z', $base, '--', '*.php' ],
    $plugin_root
);
if ( 0 !== $changed['status'] )
{
    fwrite( STDERR, $changed['stderr'] ?: $changed['stdout'] );
    exit( 1 );
}

$php_files = array_values(
    array_filter(
        explode( "\0", $changed['stdout'] ),
        static fn( string $path ): bool => '' !== $path && is_file( $plugin_root . '/' . $path )
    )
);
if ( [] === $php_files )
{
    echo "Changed-file quality guard passed: no changed PHP files.\n";
    exit( 0 );
}

$phpcs = getenv( 'SENTIENT_FORMS_PHPCS_BINARY' )
    ?: $plugin_root . '/vendor/squizlabs/php_codesniffer/bin/phpcs';
if ( ! is_file( $phpcs ) )
{
    fwrite( STDERR, "Changed-file quality guard requires PHP_CodeSniffer at {$phpcs}.\n" );
    exit( 1 );
}

$command = [
    PHP_BINARY,
    $phpcs,
    '--sniffs=Generic.WhiteSpace.DisallowTabIndent',
    '--report=full',
];
foreach ( $php_files as $php_file )
{
    $command[] = $plugin_root . '/' . $php_file;
}

$phpcs_result = run_quality_process( $command, $plugin_root );
if ( 0 !== $phpcs_result['status'] )
{
    fwrite( STDERR, $phpcs_result['stdout'] . $phpcs_result['stderr'] );
    exit( 1 );
}

echo sprintf(
    "Changed-file quality guard passed: %d changed PHP file(s) checked.\n",
    count( $php_files )
);

/**
 * @param list<string> $arguments
 */
function changed_file_quality_base( array $arguments, string $plugin_root ): ?string
{
    $candidate = '';
    foreach ( $arguments as $argument )
    {
        if ( str_starts_with( $argument, '--base=' ) )
        {
            $candidate = substr( $argument, strlen( '--base=' ) );
            break;
        }
    }
    if ( '' === $candidate )
    {
        $candidate = trim( (string) getenv( 'SENTIENT_FORMS_QA_BASE' ) );
    }

    if ( '' !== $candidate && ! preg_match( '/^0+$/', $candidate ) )
    {
        $resolved = run_quality_process(
            [ 'git', 'rev-parse', '--verify', $candidate . '^{commit}' ],
            $plugin_root
        );
        return 0 === $resolved['status'] && '' !== trim( $resolved['stdout'] )
            ? trim( $resolved['stdout'] )
            : null;
    }

    foreach ( [ 'production', 'origin/production', 'HEAD^' ] as $reference )
    {
        $resolved = run_quality_process(
            [ 'git', 'rev-parse', '--verify', $reference . '^{commit}' ],
            $plugin_root
        );
        if ( 0 === $resolved['status'] && '' !== trim( $resolved['stdout'] ) )
        {
            return trim( $resolved['stdout'] );
        }
    }

    return null;
}

/**
 * @param list<string> $command
 * @return array{status:int,stdout:string,stderr:string}
 */
function run_quality_process( array $command, string $working_directory ): array
{
    $stdout_path = tempnam( sys_get_temp_dir(), 'sf-quality-stdout-' );
    $stderr_path = tempnam( sys_get_temp_dir(), 'sf-quality-stderr-' );
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
        return [
            'status' => 1,
            'stdout' => '',
            'stderr' => 'Unable to allocate changed-file quality output captures.' . PHP_EOL,
        ];
    }

    $process = proc_open(
        $command,
        [
            1 => [ 'file', $stdout_path, 'w' ],
            2 => [ 'file', $stderr_path, 'w' ],
        ],
        $pipes,
        $working_directory
    );
    if ( ! is_resource( $process ) )
    {
        @unlink( $stdout_path );
        @unlink( $stderr_path );
        return [
            'status' => 1,
            'stdout' => '',
            'stderr' => 'Unable to start changed-file quality process.' . PHP_EOL,
        ];
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
