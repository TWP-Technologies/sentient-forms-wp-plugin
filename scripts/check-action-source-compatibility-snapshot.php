<?php
/**
 * Dependency-free verifier for the public Action/Form Source projection.
 *
 * @package Sentient_Forms
 */

declare(strict_types=1);

final class Sentient_Forms_Action_Source_Compatibility_Snapshot_Verifier
{
    /**
     * Projection-semantic sources, sorted before hashing.
     *
     * The verifier includes itself so algorithm or allowlist changes invalidate
     * a previously generated snapshot. The generated snapshot is intentionally
     * excluded.
     *
     * @return array<int, string>
     */
    public static function source_paths(): array
    {
        $paths = [
            'includes/actions/class-sentient-forms-action-source-compatibility-manifest.php',
            'includes/adapters/forms/class-forms-adapter-registry.php',
            'includes/adapters/forms/class-sentient-forms-contact-form-7-adapter.php',
            'includes/adapters/forms/class-sentient-forms-elementor-forms-adapter.php',
            'includes/adapters/forms/class-sentient-forms-gravity-forms-adapter.php',
            'includes/adapters/forms/class-sentient-forms-wpforms-adapter.php',
            'includes/class-sentient-forms-bundled-action-templates.php',
            'includes/interfaces/interface-sentient-forms-accepted-submission-adapter.php',
            'includes/interfaces/interface-sentient-forms-action-source-compatibility-manifest.php',
            'includes/interfaces/interface-sentient-forms-native-effects-capabilities-adapter.php',
            'includes/interfaces/interface-sentient-forms-native-entry-capabilities-adapter.php',
            'includes/interfaces/interface-sentient-forms-native-validation-effects-adapter.php',
            'includes/interfaces/interface-sentient-forms-realtime-adapter.php',
            'includes/interfaces/interface-sentient-forms-validation-adapter.php',
            'includes/rest-api/class-form-sources.php',
            'includes/services/class-sentient-forms-action-facet-catalog.php',
            'includes/services/class-sentient-forms-action-policy-resolver.php',
            'includes/services/class-sentient-forms-form-source-lifecycles.php',
            'scripts/check-action-source-compatibility-snapshot.php',
        ];
        sort( $paths, SORT_STRING );

        return $paths;
    }

    /**
     * Compute SHA-256 over sorted, path-framed, newline-normalized sources.
     *
     * Each path and content block is framed as `<byte-length>:<bytes>`. Source
     * CRLF and lone CR line endings normalize to LF before content framing.
     *
     * @param string $plugin_root Absolute plugin root.
     *
     * @return string Source digest.
     * @throws LogicException When a semantic source is missing or unreadable.
     */
    public static function source_sha256( string $plugin_root ): string
    {
        $plugin_root = rtrim( str_replace( '\\', '/', $plugin_root ), '/' );
        $hash        = hash_init( 'sha256' );

        foreach ( self::source_paths() as $path )
        {
            $absolute = $plugin_root . '/' . $path;
            if ( ! is_file( $absolute ) || ! is_readable( $absolute ) )
            {
                throw new LogicException(
                    'sentient_forms_action_source_projection_missing_source:' . $path
                );
            }

            $contents = file_get_contents( $absolute );
            if ( false === $contents )
            {
                throw new LogicException(
                    'sentient_forms_action_source_projection_unreadable_source:' . $path
                );
            }
            $normalized = str_replace( [ "\r\n", "\r" ], "\n", $contents );
            hash_update( $hash, strlen( $path ) . ':' . $path );
            hash_update( $hash, strlen( $normalized ) . ':' . $normalized );
        }

        return hash_final( $hash );
    }

    /**
     * Verify that a checked projection snapshot matches its semantic sources.
     *
     * @param string      $plugin_root  Absolute plugin root.
     * @param string|null $snapshot_path Optional snapshot override.
     *
     * @return string The verified source digest.
     * @throws LogicException When semantic sources or the checked snapshot are missing or stale.
     * @throws JsonException When the checked snapshot is not valid JSON.
     */
    public static function verify_snapshot( string $plugin_root, ?string $snapshot_path = null ): string
    {
        $snapshot_path = $snapshot_path
            ?? rtrim( str_replace( '\\', '/', $plugin_root ), '/' ) . '/contracts/action-source-compatibility.v1.json';
        if ( ! is_file( $snapshot_path ) || ! is_readable( $snapshot_path ) )
        {
            throw new LogicException( 'sentient_forms_action_source_projection_snapshot_missing' );
        }

        $snapshot = json_decode(
            (string) file_get_contents( $snapshot_path ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $expected = self::source_sha256( $plugin_root );
        $actual   = is_array( $snapshot ) && is_string( $snapshot['source_sha256'] ?? null )
            ? $snapshot['source_sha256']
            : '';
        if ( ! hash_equals( $expected, $actual ) )
        {
            throw new LogicException( 'sentient_forms_action_source_projection_snapshot_stale' );
        }

        return $expected;
    }
}

$executed_script = isset( $_SERVER['SCRIPT_FILENAME'] )
    ? realpath( (string) $_SERVER['SCRIPT_FILENAME'] )
    : false;
if ( PHP_SAPI === 'cli' && false !== $executed_script && __FILE__ === $executed_script )
{
    try
    {
        $digest = Sentient_Forms_Action_Source_Compatibility_Snapshot_Verifier::verify_snapshot(
            dirname( __DIR__ )
        );
        fwrite( STDOUT, 'Action source compatibility snapshot OK: ' . $digest . PHP_EOL );
        exit( 0 );
    }
    catch ( Throwable $error )
    {
        fwrite( STDERR, $error->getMessage() . PHP_EOL );
        exit( 1 );
    }
}
