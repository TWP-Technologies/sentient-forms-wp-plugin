<?php
/**
 * REST API controller for optimized admin dashboard bootstrap data.
 *
 * @package SentientForms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Admin_Dashboard_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;

    protected string $rest_base = 'admin/dashboard-summary';

    private Sentient_Forms_Action_Templates_Repository $templates;
    private Sentient_Forms_Local_Custom_Actions_Repository $custom_actions;
    private Sentient_Forms_Provider_Credentials_Repository $provider_credentials;
    private Sentient_Forms_Execution_Events_Repository $events;

    public function __construct()
    {
        parent::__construct();

        global $wpdb;
        $this->templates            = new Sentient_Forms_Action_Templates_Repository( $wpdb );
        $this->custom_actions       = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $this->provider_credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $this->events               = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        if ( ! class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Required dependency Sentient_Forms_Managed_Usage_Sanitizer not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_summary' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'include_health' => [
                            'type'              => 'boolean',
                            'default'           => false,
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                    ],
                ],
            ]
        );
    }

    public function get_summary( WP_REST_Request $request ): WP_REST_Response
    {
        $section_errors = [];
        $summary = [
            'generated_at'   => gmdate( 'c' ),
            'providers'      => $this->resolve_summary_section(
                'providers',
                function (): array {
                    return array_map( [ $this, 'format_provider_credential' ], $this->provider_credentials->list() );
                },
                [],
                $section_errors
            ),
            'templates'      => $this->resolve_summary_section(
                'templates',
                function (): array {
                    return array_map( [ $this, 'format_action_template' ], $this->templates->list_active() );
                },
                [],
                $section_errors
            ),
            'custom_actions' => $this->resolve_summary_section(
                'custom_actions',
                function (): array {
                    return array_map( [ $this, 'format_custom_action' ], $this->custom_actions->list( 'active' ) );
                },
                [],
                $section_errors
            ),
            'recent_events'  => $this->resolve_summary_section(
                'recent_events',
                function (): array {
                    return array_map( [ $this, 'format_execution_event' ], $this->events->list_recent( 100 ) );
                },
                [],
                $section_errors
            ),
            'license'        => $this->resolve_summary_section(
                'license',
                function (): array {
                    return $this->local_license_summary();
                },
                $this->empty_license_summary(),
                $section_errors
            ),
        ];

        if ( true === (bool) $request->get_param( 'include_health' ) )
        {
            $summary['async_health'] = $this->resolve_summary_section(
                'async_health',
                function (): array {
                    return Sentient_Forms_Plugin::instance()->get_async_health_service()->evaluate();
                },
                $this->unavailable_async_health_summary(),
                $section_errors
            );
        }

        if ( [] !== $section_errors )
        {
            $summary['section_errors'] = $section_errors;
        }

        return $this->prepare_item_for_response(
            $summary
        );
    }

    private function resolve_summary_section( string $section, callable $resolver, mixed $fallback, array &$section_errors ): mixed
    {
        try
        {
            return $resolver();
        }
        catch ( Throwable $throwable )
        {
            $this->log_summary_section_failure( $section, $throwable );
            $section_errors[] = $this->format_summary_section_error( $section );
            return $fallback;
        }
    }

    private function format_summary_section_error( string $section ): array
    {
        return [
            'section' => $section,
            'code'    => 'dashboard_' . sanitize_key( $section ) . '_unavailable',
            'message' => $this->dashboard_section_error_message( $section ),
        ];
    }

    private function dashboard_section_error_message( string $section ): string
    {
        switch ( $section )
        {
            case 'providers':
                return __( 'Provider data is temporarily unavailable.', 'sentient-forms' );
            case 'templates':
                return __( 'Action template data is temporarily unavailable.', 'sentient-forms' );
            case 'custom_actions':
                return __( 'Custom action data is temporarily unavailable.', 'sentient-forms' );
            case 'recent_events':
                return __( 'Recent run data is temporarily unavailable.', 'sentient-forms' );
            case 'license':
                return __( 'License data is temporarily unavailable.', 'sentient-forms' );
            case 'async_health':
                return __( 'Background health data is temporarily unavailable.', 'sentient-forms' );
            default:
                return __( 'Dashboard data is temporarily unavailable.', 'sentient-forms' );
        }
    }

    private function log_summary_section_failure( string $section, Throwable $throwable ): void
    {
        if ( ! function_exists( 'sentient_forms_debug_log' ) )
        {
            return;
        }

        sentient_forms_debug_log(
            'Sentient Forms dashboard summary section failed.',
            [
                'section'   => $section,
                'exception' => get_class( $throwable ),
                'message'   => $throwable->getMessage(),
            ]
        );
    }

    private function empty_license_summary(): array
    {
        return [
            'status'            => 'inactive',
            'proxy_key_present' => false,
            'tier'              => null,
            'expires_at'        => null,
            'last_synced'       => null,
            'license_id'        => null,
            'site_id'           => null,
        ];
    }

    private function unavailable_async_health_summary(): array
    {
        return [
            'queue_depth'            => 0,
            'oldest_run_at'          => null,
            'oldest_overdue_seconds' => null,
            'recent_failures'        => [],
            'warnings'               => [
                [
                    'code'    => 'async_health_unavailable',
                    'level'   => 'warning',
                    'message' => __( 'Background health details are temporarily unavailable.', 'sentient-forms' ),
                    'data'    => [],
                ],
            ],
        ];
    }

    private function local_license_summary(): array
    {
        $license_data = Sentient_Forms_Plugin::instance()->get_license_data();

        return [
            'status'            => $license_data['license_status'] ?? 'inactive',
            'proxy_key_present' => ! empty( $license_data['proxy_api_key'] ),
            'tier'              => $license_data['tier'] ?: null,
            'expires_at'        => $license_data['expiry_date'] ?: null,
            'last_synced'       => $license_data['last_synced'] ?: null,
            'license_id'        => $license_data['license_id'] ?: null,
            'site_id'           => $license_data['site_id'] ?: null,
        ];
    }

    private function format_provider_credential( array $row ): array
    {
        $auth_mode         = (string) ( $row['auth_mode'] ?? '' );
        $constant_name     = isset( $row['constant_name'] ) ? (string) $row['constant_name'] : null;
        $secret_configured = ! empty( $row['encrypted_secret'] ) || ! empty( $constant_name );
        if ( 'constant' === $auth_mode && class_exists( 'Sentient_Forms_Provider_Secret_Resolver' ) )
        {
            $secret_configured = null !== $constant_name
                && Sentient_Forms_Provider_Secret_Resolver::is_constant_secret_configured( $constant_name );
        }

        $is_managed_proxy = 'sentient_managed' === (string) ( $row['provider'] ?? '' ) && 'sentient_proxy' === $auth_mode;
        if ( $is_managed_proxy )
        {
            $license_data      = Sentient_Forms_Plugin::instance()->get_license_data();
            $secret_configured = '' !== trim( (string) ( $license_data['proxy_api_key'] ?? '' ) );
        }

        return [
            'id'                => (int) ( $row['id'] ?? 0 ),
            'provider'          => (string) ( $row['provider'] ?? '' ),
            'label'             => (string) ( $row['label'] ?? '' ),
            'auth_mode'         => $auth_mode,
            'constant_name'     => $constant_name,
            'status'            => (string) ( $row['status'] ?? 'unknown' ),
            'status_json'       => is_array( $row['status_json'] ?? null ) ? $this->redact_sensitive_metadata( $row['status_json'] ) : null,
            'last_validated_at' => $row['last_validated_at'] ?? null,
            'created_at'        => $row['created_at'] ?? null,
            'updated_at'        => $row['updated_at'] ?? null,
            'secret_configured' => $secret_configured,
        ];
    }

    private function format_action_template( array $row ): array
    {
        return [
            'id'                       => (int) ( $row['id'] ?? 0 ),
            'source'                   => $row['source'] ?? null,
            'external_id'              => $row['external_id'] ?? null,
            'code'                     => $row['code'] ?? null,
            'display_name'             => $row['display_name'] ?? null,
            'description'              => $row['description'] ?? null,
            'prompt_template'          => $row['prompt_template'] ?? null,
            'default_model'            => $row['default_model'] ?? null,
            'structured_output_schema' => $row['structured_output_schema'] ?? null,
            'override_schema'          => $row['override_schema'] ?? null,
            'version'                  => $row['version'] ?? null,
            'is_active'                => ! empty( $row['is_active'] ),
            'created_at'               => $row['created_at'] ?? null,
            'updated_at'               => $row['updated_at'] ?? null,
        ];
    }

    private function format_custom_action( array $row ): array
    {
        return [
            'id'                   => (int) ( $row['id'] ?? 0 ),
            'external_id'          => $row['external_id'] ?? null,
            'template_id'          => isset( $row['template_id'] ) ? (int) $row['template_id'] : null,
            'code'                 => $row['code'] ?? null,
            'display_name'         => $row['display_name'] ?? null,
            'definition_json'      => $row['definition_json'] ?? [],
            'model_selection_json' => $row['model_selection_json'] ?? null,
            'status'               => $row['status'] ?? null,
            'created_at'           => $row['created_at'] ?? null,
            'updated_at'           => $row['updated_at'] ?? null,
        ];
    }

    private function format_execution_event( array $row ): array
    {
        $row = Sentient_Forms_Managed_Usage_Sanitizer::sanitize_event_fields( $row );
        $form_source = isset( $row['form_source'] ) && is_scalar( $row['form_source'] )
            ? sanitize_key( (string) $row['form_source'] )
            : null;

        return [
            'id'                   => (int) ( $row['id'] ?? 0 ),
            'execution_request_id' => $row['execution_request_id'] ?? null,
            'mapping_id'           => isset( $row['mapping_id'] ) ? (int) $row['mapping_id'] : null,
            'form_source'          => $form_source,
            'form_id'              => $row['form_id'] ?? null,
            'entry_id'             => $this->format_execution_event_entry_id( $row['entry_id'] ?? null, $form_source ),
            'provider'             => $row['provider'] ?? null,
            'model'                => $row['model'] ?? null,
            'status'               => $row['status'] ?? null,
            'token_usage_json'     => $row['token_usage_json'] ?? null,
            'cost_json'            => $row['cost_json'] ?? null,
            'result_json'          => $row['result_json'] ?? null,
            'error_code'           => $row['error_code'] ?? null,
            'error_message'        => $row['error_message'] ?? null,
            'payload_digest'       => $row['payload_digest'] ?? null,
            'created_at'           => $row['created_at'] ?? null,
            'updated_at'           => $row['updated_at'] ?? null,
            'expires_at'           => $row['expires_at'] ?? null,
        ];
    }

    private function format_execution_event_entry_id( mixed $entry_id, ?string $form_source ): ?string
    {
        if ( null === $entry_id || ! is_scalar( $entry_id ) )
        {
            return null;
        }

        $entry_id = sanitize_text_field( (string) $entry_id );
        if ( '' === $entry_id )
        {
            return null;
        }

        if ( null !== $form_source )
        {
            $native_entry = Sentient_Forms_Form_Sources::native_entry_capability_for_form_source( $form_source );
            if ( is_array( $native_entry ) && array_key_exists( 'id', $native_entry ) && ! $native_entry['id'] )
            {
                return null;
            }
        }

        return $entry_id;
    }

    private function redact_sensitive_metadata( mixed $value ): mixed
    {
        if ( is_string( $value ) )
        {
            return $this->redact_secret_patterns( $value );
        }

        if ( ! is_array( $value ) )
        {
            return $value;
        }

        $redacted = [];
        foreach ( $value as $key => $item )
        {
            $key_string = is_string( $key ) ? strtolower( $key ) : (string) $key;
            if (
                preg_match(
                    '/(api[_-]?key|secret|token|credential|password|authorization|auth[_-]?header|bearer|encrypted(?:[_-]|$))/',
                    $key_string
                )
            )
            {
                $redacted[ $key ] = '[redacted]';
                continue;
            }

            $redacted[ $key ] = $this->redact_sensitive_metadata( $item );
        }

        return $redacted;
    }

    private function redact_secret_patterns( string $value ): string
    {
        $redacted = preg_replace( '/sk-or-[A-Za-z0-9._:-]{4,}/', 'sk-or-[redacted]', $value );
        $redacted = is_string( $redacted )
            ? preg_replace( '/Bearer\s+[A-Za-z0-9._~+\/=-]{4,}/i', 'Bearer [redacted]', $redacted )
            : $redacted;

        return is_string( $redacted ) ? $redacted : $value;
    }
}
