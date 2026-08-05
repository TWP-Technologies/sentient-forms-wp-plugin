<?php
/**
 * Verify the public Action facet policy snapshot against plugin-owned authority.
 *
 * @package Sentient_Forms
 */

final class Sentient_Forms_Action_Facet_Policy_Snapshot_Verifier
{
    private const SNAPSHOT_VERSION = 1;

    /**
     * @return array{snapshot_version:int,source_sha256:string,facets:array<string, array<string, mixed>>}
     */
    public static function build_snapshot( string $plugin_root ): array
    {
        $plugin_root = rtrim( $plugin_root, '/\\' );
        $source_path = $plugin_root . '/includes/services/class-sentient-forms-action-facet-catalog.php';
        if ( is_link( $source_path ) || ! is_file( $source_path ) )
        {
            throw new RuntimeException( 'The plugin-owned Action facet catalog source is unavailable.' );
        }

        if ( ! defined( 'ABSPATH' ) )
        {
            define( 'ABSPATH', $plugin_root . DIRECTORY_SEPARATOR );
        }
        require_once $source_path;

        $facets = ( new Sentient_Forms_Action_Facet_Catalog() )->definitions();
        ksort( $facets, SORT_STRING );

        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'source_sha256'    => hash_file( 'sha256', $source_path ),
            'facets'           => $facets,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function snapshot_matches( string $plugin_root, array $snapshot ): bool
    {
        return [ 'snapshot_version', 'source_sha256', 'facets' ] === array_keys( $snapshot )
            && self::build_snapshot( $plugin_root ) === $snapshot;
    }

    public static function expected_json( string $plugin_root ): string
    {
        return json_encode(
            self::build_snapshot( $plugin_root ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    public static function verify_snapshot( string $plugin_root ): void
    {
        $plugin_root = rtrim( $plugin_root, '/\\' );
        $snapshot_path = $plugin_root . '/contracts/action-facet-policy-catalog.v1.json';
        if ( is_link( $snapshot_path ) || ! is_file( $snapshot_path ) )
        {
            throw new RuntimeException( 'The Action facet policy snapshot is unavailable.' );
        }

        $bytes = file_get_contents( $snapshot_path );
        if ( ! is_string( $bytes ) )
        {
            throw new RuntimeException( 'The Action facet policy snapshot is unreadable.' );
        }

        try
        {
            $snapshot = json_decode( $bytes, true, 64, JSON_THROW_ON_ERROR );
        }
        catch ( JsonException $exception )
        {
            throw new RuntimeException( 'The Action facet policy snapshot is invalid JSON.', 0, $exception );
        }

        if ( ! is_array( $snapshot ) || array_is_list( $snapshot ) || ! self::snapshot_matches( $plugin_root, $snapshot ) )
        {
            throw new RuntimeException( 'The Action facet policy snapshot differs from the plugin-owned catalog.' );
        }

        if ( ! hash_equals( self::expected_json( $plugin_root ), $bytes ) )
        {
            throw new RuntimeException( 'The Action facet policy snapshot bytes are not canonical.' );
        }
    }
}

if ( PHP_SAPI === 'cli' && isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ )
{
    $plugin_root = dirname( __DIR__ );
    try
    {
        Sentient_Forms_Action_Facet_Policy_Snapshot_Verifier::verify_snapshot( $plugin_root );
        $count = count( Sentient_Forms_Action_Facet_Policy_Snapshot_Verifier::build_snapshot( $plugin_root )['facets'] );
        fwrite( STDOUT, "Action facet policy snapshot OK: {$count} facet(s).\n" );
    }
    catch ( Throwable $exception )
    {
        fwrite( STDERR, $exception->getMessage() . "\n" );
        exit( 1 );
    }
}
