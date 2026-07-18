<?php
/**
 * Public-safe CPS /v2 contract snapshot tests.
 *
 * @package SentientForms\Tests
 */

class ContractSchemaParityTest extends WP_UnitTestCase
{
    private const SNAPSHOT_ROOT = __DIR__ . '/../../contracts/cps-v2';

    public function test_public_snapshot_covers_every_retained_plugin_cps_route(): void
    {
        $manifest = $this->decode_required_json_file( self::SNAPSHOT_ROOT . '/manifest.json' );
        $this->assertSame( 'v2', $manifest['contract_version'] ?? null );

        $routes = [];
        foreach ( $manifest['routes'] ?? [] as $route )
        {
            $this->assertIsArray( $route, 'Every CPS contract route must be an object.' );
            $this->assertNotSame( 'internal', $route['audience'] ?? null, 'Internal CPS routes must not enter the public plugin snapshot.' );

            $route_key = ( $route['method'] ?? '' ) . ' ' . ( $route['path'] ?? '' );
            $this->assertArrayNotHasKey( $route_key, $routes, 'Duplicate CPS contract route: ' . $route_key );
            $routes[ $route_key ] = $route;
        }

        $expected_routes = [
            'GET /v2/health',
            'POST /v2/account/sites/activate',
            'POST /v2/account/sites/deactivate',
            'GET /v2/account/sites/{site_id}',
            'POST /v2/account/checkout/start',
            'POST /v2/account/checkout/complete',
            'POST /v2/billing/checkout/session',
            'POST /v2/billing/checkout/top-up-session',
            'POST /v2/billing/portal/session',
            'POST /v2/billing/webhooks/stripe',
            'GET /v2/billing/state',
            'POST /v2/managed/execute',
            'GET /v2/metering/summary',
        ];

        $actual_routes = array_keys( $routes );
        sort( $actual_routes, SORT_STRING );
        sort( $expected_routes, SORT_STRING );
        $this->assertSame(
            $expected_routes,
            $actual_routes,
            'The public CPS contract snapshot must contain exactly the retained plugin-facing routes.'
        );

        foreach ( $expected_routes as $route_key )
        {
            $this->assertArrayHasKey( $route_key, $routes, 'Missing retained plugin CPS contract route: ' . $route_key );
            $schemas = $routes[ $route_key ]['schemas'] ?? null;
            $this->assertIsArray( $schemas, 'Route schemas must be declared for ' . $route_key );
            $this->assertArrayHasKey( 'success', $schemas, 'Success schema missing for ' . $route_key );
            $this->assertArrayHasKey( 'error', $schemas, 'Error schema missing for ' . $route_key );

            foreach ( $schemas as $schema_path )
            {
                $this->assertIsString( $schema_path );
                $this->decode_required_json_file( self::SNAPSHOT_ROOT . '/' . $schema_path );
            }
        }
    }

    public function test_public_snapshot_hashes_fail_closed(): void
    {
        $hashes = $this->decode_required_json_file( self::SNAPSHOT_ROOT . '/snapshot-hashes.json' );
        $this->assertNotEmpty( $hashes );

        $hashed_paths = array_keys( $hashes );
        sort( $hashed_paths, SORT_STRING );
        $this->assertSame( $this->snapshot_paths(), $hashed_paths, 'Every public snapshot file must have exactly one hash entry.' );

        foreach ( $hashes as $relative_path => $expected_hash )
        {
            $this->assertIsString( $relative_path );
            $this->assertIsString( $expected_hash );
            $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $expected_hash );

            $path = self::SNAPSHOT_ROOT . '/' . $relative_path;
            $this->assertFileExists( $path, 'Required public CPS contract snapshot is missing: ' . $path );
            $this->assertSame( $expected_hash, hash_file( 'sha256', $path ), 'CPS contract snapshot hash drifted: ' . $relative_path );
        }
    }

    public function test_managed_capability_vocabulary_matches_the_checked_cps_request_schema(): void
    {
        $schema = $this->decode_required_json_file(
            self::SNAPSHOT_ROOT . '/managed/execute-request.schema.json'
        );
        $policy = $schema['properties']['managed_capability_policy'] ?? null;
        $required = is_array( $policy )
            ? ( $policy['properties']['required_capabilities'] ?? null )
            : null;

        $this->assertIsArray( $policy );
        $this->assertFalse( $policy['additionalProperties'] ?? true );
        $this->assertSame( [ 'schema', 'required_capabilities' ], $policy['required'] ?? null );
        $this->assertSame(
            Sentient_Forms_Managed_Capability_Policy::SCHEMA,
            $policy['properties']['schema']['const'] ?? null
        );
        $this->assertIsArray( $required );
        $this->assertSame( 1, $required['minItems'] ?? null );
        $this->assertSame( 4, $required['maxItems'] ?? null );
        $this->assertTrue( $required['uniqueItems'] ?? false );
        $this->assertSame(
            Sentient_Forms_Managed_Capability_Policy::allowed_capabilities(),
            $required['items']['enum'] ?? null
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode_required_json_file( string $path ): array
    {
        $this->assertFileExists( $path, 'Required public CPS contract snapshot is missing: ' . $path );
        $decoded = json_decode( (string) file_get_contents( $path ), true );
        $this->assertIsArray( $decoded, 'Required public CPS contract snapshot is invalid JSON: ' . $path );

        return $decoded;
    }

    /**
     * @return array<int, string>
     */
    private function snapshot_paths(): array
    {
        $paths    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( self::SNAPSHOT_ROOT, FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file )
        {
            if ( ! $file->isFile() || 'snapshot-hashes.json' === $file->getFilename() )
            {
                continue;
            }

            $paths[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( self::SNAPSHOT_ROOT ) + 1 ) );
        }

        sort( $paths, SORT_STRING );

        return $paths;
    }
}
