<?php
/**
 * Regression tests for shared/CPS contract schema parity.
 *
 * @package SentientForms\Tests
 */

class ContractSchemaParityTest extends WP_UnitTestCase
{
    public function test_custom_action_response_schema_is_in_sync_between_shared_and_cps(): void
    {
        $workspace_root = dirname( __DIR__, 3 );
        $shared_path    = $workspace_root . '/contracts/v1/actions/custom-action-response.schema.json';
        $cps_path       = $workspace_root . '/Sentient-Forms-Central-Proxy-Server/contracts/v1/actions/custom-action-response.schema.json';

        if ( ! file_exists( $shared_path ) || ! file_exists( $cps_path ) ) {
            $this->markTestSkipped(
                sprintf(
                    'Contract parity requires shared + CPS schema files. shared=%s exists=%s, cps=%s exists=%s',
                    $shared_path,
                    file_exists( $shared_path ) ? 'yes' : 'no',
                    $cps_path,
                    file_exists( $cps_path ) ? 'yes' : 'no'
                )
            );
        }

        $shared_schema = $this->decode_schema_file( $shared_path );
        $cps_schema    = $this->decode_schema_file( $cps_path );

        $normalized_shared = $this->normalize_json_value( $shared_schema );
        $normalized_cps    = $this->normalize_json_value( $cps_schema );

        $shared_required = $this->extract_required_keys( $shared_schema );
        $cps_required    = $this->extract_required_keys( $cps_schema );

        $shared_properties = $this->extract_top_level_property_keys( $shared_schema );
        $cps_properties    = $this->extract_top_level_property_keys( $cps_schema );

        $missing_required_in_shared = array_values( array_diff( $cps_required, $shared_required ) );
        $missing_required_in_cps    = array_values( array_diff( $shared_required, $cps_required ) );
        $missing_properties_in_shared = array_values( array_diff( $cps_properties, $shared_properties ) );
        $missing_properties_in_cps    = array_values( array_diff( $shared_properties, $cps_properties ) );

        $failure_message = sprintf(
            "Custom-action schema drift detected.\nMissing required in shared: %s\nMissing required in cps: %s\nMissing top-level properties in shared: %s\nMissing top-level properties in cps: %s\nFirst diff: %s",
            $this->format_key_list( $missing_required_in_shared ),
            $this->format_key_list( $missing_required_in_cps ),
            $this->format_key_list( $missing_properties_in_shared ),
            $this->format_key_list( $missing_properties_in_cps ),
            $this->first_diff_snippet( $normalized_shared, $normalized_cps )
        );

        $this->assertSame( $normalized_shared, $normalized_cps, $failure_message );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode_schema_file( string $path ): array
    {
        $raw = file_get_contents( $path );
        $this->assertNotFalse( $raw, sprintf( 'Unable to read schema file: %s', $path ) );

        try {
            $decoded = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
        } catch ( JsonException $exception ) {
            $this->fail(
                sprintf(
                    'Invalid JSON in schema file %s: %s',
                    $path,
                    $exception->getMessage()
                )
            );
        }

        $this->assertIsArray( $decoded, sprintf( 'Schema root must be an object array: %s', $path ) );

        return $decoded;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private function normalize_json_value( mixed $value ): mixed
    {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        if ( array_is_list( $value ) ) {
            $normalized_list = [];
            foreach ( $value as $item ) {
                $normalized_list[] = $this->normalize_json_value( $item );
            }

            return $normalized_list;
        }

        $normalized_map = [];
        $keys           = array_keys( $value );
        sort( $keys, SORT_STRING );

        foreach ( $keys as $key ) {
            $normalized_map[ $key ] = $this->normalize_json_value( $value[ $key ] );
        }

        return $normalized_map;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<int, string>
     */
    private function extract_required_keys( array $schema ): array
    {
        $required = $schema['required'] ?? [];
        $this->assertIsArray( $required, 'Schema required must be an array.' );

        $keys = [];
        foreach ( $required as $required_key ) {
            $this->assertIsString( $required_key, 'Schema required keys must be strings.' );
            $keys[] = $required_key;
        }

        sort( $keys, SORT_STRING );

        return $keys;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<int, string>
     */
    private function extract_top_level_property_keys( array $schema ): array
    {
        $properties = $schema['properties'] ?? null;
        $this->assertIsArray( $properties, 'Schema properties must be an object/array.' );

        $keys = array_keys( $properties );
        sort( $keys, SORT_STRING );

        return $keys;
    }

    /**
     * @param array<int, string> $keys
     */
    private function format_key_list( array $keys ): string
    {
        if ( [] === $keys ) {
            return '(none)';
        }

        return implode( ', ', $keys );
    }

    /**
     * @param array<string, mixed> $normalized_shared
     * @param array<string, mixed> $normalized_cps
     */
    private function first_diff_snippet( array $normalized_shared, array $normalized_cps ): string
    {
        $shared_json = json_encode( $normalized_shared, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        $cps_json    = json_encode( $normalized_cps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        if ( ! is_string( $shared_json ) || ! is_string( $cps_json ) ) {
            return 'Unable to encode normalized schema JSON.';
        }

        if ( $shared_json === $cps_json ) {
            return 'No line-level diff available (normalized JSON is identical).';
        }

        $shared_lines = explode( "\n", $shared_json );
        $cps_lines    = explode( "\n", $cps_json );
        $max_lines    = max( count( $shared_lines ), count( $cps_lines ) );

        for ( $index = 0; $index < $max_lines; $index++ ) {
            $shared_line = $shared_lines[ $index ] ?? '<EOF>';
            $cps_line    = $cps_lines[ $index ] ?? '<EOF>';

            if ( $shared_line !== $cps_line ) {
                return sprintf(
                    'line %d | shared: %s | cps: %s',
                    $index + 1,
                    $shared_line,
                    $cps_line
                );
            }
        }

        return 'Diff detected but no differing line snippet could be determined.';
    }
}
