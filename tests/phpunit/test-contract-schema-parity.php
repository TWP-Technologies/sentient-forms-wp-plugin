<?php
/**
 * Public-safe CPS /v2 contract snapshot tests.
 */

class ContractSchemaParityTest extends WP_UnitTestCase
{
    private const SNAPSHOT_ROOT = __DIR__ . '/../../contracts/cps-v2';

    public function test_public_snapshot_covers_every_retained_plugin_cps_route(): void
    {
        $manifest = $this->decode_json_file( self::SNAPSHOT_ROOT . '/manifest.json' );
        $routes   = [];
        foreach ( $manifest['routes'] ?? [] as $route )
        {
            if ( ! is_array( $route ) )
            {
                continue;
            }
            $routes[ ( $route['method'] ?? '' ) . ' ' . ( $route['path'] ?? '' ) ] = $route;
        }

        $expected = [
            'GET /v2/health',
            'POST /v2/account/sites/activate',
            'POST /v2/account/sites/deactivate',
            'GET /v2/account/sites/{site_id}',
            'POST /v2/account/checkout/start',
            'POST /v2/account/checkout/complete',
            'POST /v2/billing/checkout/session',
            'POST /v2/billing/checkout/top-up-session',
            'POST /v2/billing/portal/session',
            'GET /v2/billing/state',
            'POST /v2/managed/execute',
            'GET /v2/metering/summary',
        ];

        foreach ( $expected as $route_key )
        {
            $this->assertArrayHasKey( $route_key, $routes, 'Missing retained plugin CPS contract route: ' . $route_key );
            $schemas = $routes[ $route_key ]['schemas'] ?? null;
            $this->assertIsArray( $schemas, 'Route schemas must be declared for ' . $route_key );
            $this->assertArrayHasKey( 'success', $schemas, 'Success schema missing for ' . $route_key );
            if ( 'GET /v2/health' !== $route_key )
            {
                $this->assertArrayHasKey( 'error', $schemas, 'Error schema missing for ' . $route_key );
            }

            foreach ( $schemas as $schema_path )
            {
                $this->assertIsString( $schema_path );
                $this->assertFileExists( self::SNAPSHOT_ROOT . '/' . $schema_path );
                $this->decode_json_file( self::SNAPSHOT_ROOT . '/' . $schema_path );
            }
        }
    }

    public function test_public_snapshot_hashes_fail_closed(): void
    {
        $hashes = $this->decode_json_file( self::SNAPSHOT_ROOT . '/snapshot-hashes.json' );
        $this->assertNotEmpty( $hashes );

        foreach ( $hashes as $relative_path => $expected_hash )
        {
            $this->assertIsString( $relative_path );
            $this->assertIsString( $expected_hash );
            $path = self::SNAPSHOT_ROOT . '/' . $relative_path;
            $this->assertFileExists( $path );
            $this->assertSame( $expected_hash, hash_file( 'sha256', $path ), 'CPS contract snapshot hash drifted: ' . $relative_path );
        }
    }

    private function decode_json_file( string $path ): array
    {
        $this->assertFileExists( $path, 'Required public CPS contract snapshot is missing: ' . $path );
        $decoded = json_decode( (string) file_get_contents( $path ), true );
        $this->assertIsArray( $decoded, 'Required public CPS contract snapshot is invalid JSON: ' . $path );

        return $decoded;
    }
}
