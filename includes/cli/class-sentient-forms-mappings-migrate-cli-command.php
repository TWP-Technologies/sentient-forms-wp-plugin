<?php
/**
 * WP-CLI command for CPS mapping migration.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

if ( defined( '\\WP_CLI' ) && WP_CLI && ! class_exists( 'Sentient_Forms_Mappings_Migrate_CLI_Command' ) )
{
    class Sentient_Forms_Mappings_Migrate_CLI_Command
    {
        /**
         * Migrate local form-action mappings into CPS mappings.
         *
         * ## OPTIONS
         *
         * [--all]
         * : Migrate every discovered form option key.
         *
         * [--form-source=<slug>]
         * : Restrict migration to one form source (requires --form-id).
         *
         * [--form-id=<id>]
         * : Restrict migration to one provider-native form id (requires --form-source).
         *
         * [--apply]
         * : Persist changes in CPS. Without this flag, command runs dry-run only.
         *
         * [--include-disabled]
         * : Include mappings where is_action_enabled_for_form is false.
         *
         * [--format=<format>]
         * : table|json. Default: table.
         *
         * ## EXAMPLES
         *
         *     wp sentient-forms mappings migrate --all
         *     wp sentient-forms mappings migrate --all --apply
         *     wp sentient-forms mappings migrate --form-source=gravity_forms --form-id=1 --apply
         */
        public function migrate( array $args, array $assoc_args ): void
        {
            $run_all          = array_key_exists( 'all', $assoc_args );
            $apply            = array_key_exists( 'apply', $assoc_args );
            $include_disabled = array_key_exists( 'include-disabled', $assoc_args );
            $form_source      = isset( $assoc_args['form-source'] )
                ? sanitize_key( (string) $assoc_args['form-source'] )
                : null;
            $form_id          = isset( $assoc_args['form-id'] )
                ? sanitize_text_field( rawurldecode( trim( (string) $assoc_args['form-id'] ) ) )
                : null;
            $format           = isset( $assoc_args['format'] )
                ? sanitize_key( (string) $assoc_args['format'] )
                : 'table';

            if ( ! in_array( $format, [ 'table', 'json' ], true ) )
            {
                WP_CLI::error( 'Invalid --format value. Allowed values: table, json.' );
            }

            $has_specific_scope = null !== $form_source || null !== $form_id;
            if ( $run_all && $has_specific_scope )
            {
                WP_CLI::error( 'Use either --all or --form-source/--form-id, not both.' );
            }

            if ( ! $run_all && ! $has_specific_scope )
            {
                WP_CLI::error( 'Provide --all or both --form-source and --form-id.' );
            }

            if ( ( null === $form_source ) xor ( null === $form_id ) )
            {
                WP_CLI::error( 'Both --form-source and --form-id are required for scoped migration.' );
            }

            $service = new Sentient_Forms_Mappings_Migration_Service();
            $result  = $service->migrate(
                [
                    'apply'            => $apply,
                    'include_disabled' => $include_disabled,
                    'form_source'      => $form_source,
                    'form_id'          => $form_id,
                ]
            );

            if ( is_wp_error( $result ) )
            {
                WP_CLI::error(
                    sprintf(
                        '%s (%s)',
                        $result->get_error_message(),
                        $result->get_error_code()
                    )
                );
            }

            if ( 'json' === $format )
            {
                WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
            }
            else
            {
                $this->render_table( $result );
                $this->render_summary_lines( $result );
            }

            $totals = $result['totals'] ?? [];
            if ( (int) ( $totals['error'] ?? 0 ) > 0 )
            {
                WP_CLI::halt( 1 );
            }

            if ( ! $apply )
            {
                WP_CLI::warning( 'Dry-run only. Re-run with --apply to persist mapping changes in CPS.' );
                return;
            }

            WP_CLI::success( 'CPS mapping migration completed.' );
        }

        /**
         * @param array{
         *   forms?: array<int, array<string, mixed>>,
         *   totals?: array<string, mixed>
         * } $result
         */
        private function render_table( array $result ): void
        {
            $rows = [];
            foreach ( $result['forms'] ?? [] as $form_summary )
            {
                $form_source = isset( $form_summary['form_source'] ) && is_scalar( $form_summary['form_source'] )
                    ? (string) $form_summary['form_source']
                    : '';
                $form_id     = isset( $form_summary['form_id'] ) && is_scalar( $form_summary['form_id'] )
                    ? (string) $form_summary['form_id']
                    : '';

                foreach ( $form_summary['operations'] ?? [] as $operation )
                {
                    if ( ! is_array( $operation ) )
                    {
                        continue;
                    }

                    $rows[] = [
                        'form_source'      => $form_source,
                        'form_id'          => $form_id,
                        'local_mapping_id' => isset( $operation['local_mapping_id'] ) ? (string) $operation['local_mapping_id'] : '',
                        'central_action_id'=> isset( $operation['central_action_id'] ) ? (string) $operation['central_action_id'] : '',
                        'action_type'      => isset( $operation['action_type'] ) ? (string) $operation['action_type'] : '',
                        'operation'        => isset( $operation['operation'] ) ? (string) $operation['operation'] : '',
                        'cps_mapping_id'   => isset( $operation['cps_mapping_id'] ) ? (string) $operation['cps_mapping_id'] : '',
                        'reason'           => isset( $operation['reason'] ) ? (string) $operation['reason'] : '',
                        'message'          => isset( $operation['message'] ) ? (string) $operation['message'] : '',
                    ];
                }
            }

            if ( empty( $rows ) )
            {
                WP_CLI::warning( 'No eligible mappings found for migration scope.' );
                return;
            }

            WP_CLI\Utils\format_items(
                'table',
                $rows,
                [ 'form_source', 'form_id', 'local_mapping_id', 'central_action_id', 'action_type', 'operation', 'cps_mapping_id', 'reason', 'message' ]
            );
        }

        /**
         * @param array{totals?: array<string, mixed>, forms?: array<int, array<string, mixed>>} $result
         */
        private function render_summary_lines( array $result ): void
        {
            $totals = $result['totals'] ?? [];
            $forms  = is_array( $result['forms'] ?? null ) ? $result['forms'] : [];

            WP_CLI::line(
                sprintf(
                    'Forms: %d | create: %d | update: %d | skip: %d | error: %d',
                    count( $forms ),
                    (int) ( $totals['create'] ?? 0 ),
                    (int) ( $totals['update'] ?? 0 ),
                    (int) ( $totals['skip'] ?? 0 ),
                    (int) ( $totals['error'] ?? 0 )
                )
            );
        }
    }

    $sentient_forms_migrate_cli = new Sentient_Forms_Mappings_Migrate_CLI_Command();
    WP_CLI::add_command( 'sentient-forms mappings migrate', [ $sentient_forms_migrate_cli, 'migrate' ] );
}
