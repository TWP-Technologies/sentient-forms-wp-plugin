<?php
/**
 * REST API controller for local-first provider onboarding and credentials.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Local_Providers_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;

    protected string $rest_base = 'local/providers';

    private Sentient_Forms_Provider_Credentials_Repository $credentials;
    private Sentient_Forms_External_Service_Consent_Repository $consents;
    private Sentient_Forms_Model_Cache_Repository $model_cache;
    private Sentient_Forms_OpenRouter_Direct_Client $openrouter;
    private Sentient_Forms_Provider_Credential_Vault $vault;

    public function __construct(
        ?Sentient_Forms_Provider_Credentials_Repository $credentials = null,
        ?Sentient_Forms_External_Service_Consent_Repository $consents = null,
        ?Sentient_Forms_Model_Cache_Repository $model_cache = null,
        ?Sentient_Forms_OpenRouter_Direct_Client $openrouter = null,
        ?Sentient_Forms_Provider_Credential_Vault $vault = null
    )
    {
        parent::__construct();

        global $wpdb;

        $this->credentials = $credentials ?? new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $this->consents    = $consents ?? new Sentient_Forms_External_Service_Consent_Repository( $wpdb );
        $this->model_cache = $model_cache ?? new Sentient_Forms_Model_Cache_Repository( $wpdb );
        $this->openrouter  = $openrouter ?? new Sentient_Forms_OpenRouter_Direct_Client();
        $this->vault       = $vault ?? new Sentient_Forms_Provider_Credential_Vault();
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/credentials',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'list_credentials' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/openrouter/validate',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'validate_openrouter_key' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_openrouter_validate_args(),
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/openrouter/models',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'list_openrouter_models' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_openrouter_models_args(),
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/openrouter/models/refresh',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'refresh_openrouter_models' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_openrouter_model_refresh_args(),
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/sentient-managed/setup',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'setup_sentient_managed_proxy' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_sentient_managed_setup_args(),
                ],
            ]
        );
    }

    public function list_credentials( WP_REST_Request $request ): WP_REST_Response
    {
        $rows = array_map( [ $this, 'format_credential' ], $this->credentials->list() );

        return $this->prepare_item_for_response( $rows );
    }

    public function validate_openrouter_key( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $accepted = rest_sanitize_boolean( $request->get_param( 'accepted_external_service_terms' ) );
        if ( ! $accepted )
        {
            return new WP_Error(
                'sentient_forms_external_service_consent_required',
                __( 'You must accept the OpenRouter external-service disclosure before validating this key.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        $api_key            = trim( (string) $request->get_param( 'api_key' ) );
        $disclosure_version = sanitize_text_field( (string) $request->get_param( 'disclosure_version' ) );

        $consent_id = $this->consents->record(
            'openrouter',
            $disclosure_version,
            get_current_user_id() ?: null,
            [
                'action'       => 'validate_key',
                'request_ip'   => $this->request_ip_hash(),
                'save_enabled' => rest_sanitize_boolean( $request->get_param( 'save' ) ),
            ]
        );

        if ( is_wp_error( $consent_id ) )
        {
            return $consent_id;
        }

        $validation = $this->openrouter->validate_key( $api_key );
        if ( is_wp_error( $validation ) )
        {
            return $validation;
        }

        $key_status    = $this->format_openrouter_key_status( $validation );
        $local_status  = $this->credential_status_from_key_status( $key_status );
        $credential_id = null;

        if ( rest_sanitize_boolean( $request->get_param( 'save' ) ) )
        {
            $encrypted_secret = $this->vault->encrypt( $api_key );
            if ( is_wp_error( $encrypted_secret ) )
            {
                return $encrypted_secret;
            }

            $label = sanitize_text_field( (string) $request->get_param( 'label' ) );
            if ( '' === $label )
            {
                $label = isset( $key_status['label'] ) && is_string( $key_status['label'] ) && '' !== $key_status['label']
                    ? $key_status['label']
                    : __( 'OpenRouter key', 'sentient-forms' );
            }

            $credential_id = $this->credentials->create(
                [
                    'provider'          => 'openrouter',
                    'label'             => $label,
                    'auth_mode'         => 'manual_key',
                    'encrypted_secret'  => $encrypted_secret,
                    'status'            => $local_status,
                    'status_json'       => $key_status,
                    'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
                ]
            );

            if ( is_wp_error( $credential_id ) )
            {
                return $credential_id;
            }
        }

        return $this->prepare_item_for_response(
            [
                'provider'         => 'openrouter',
                'status'           => $local_status,
                'credential_id'    => $credential_id,
                'key_status'       => $key_status,
                'consent_recorded' => true,
                'consent_id'       => $consent_id,
            ]
        );
    }

    public function list_openrouter_models( WP_REST_Request $request ): WP_REST_Response
    {
        $limit     = max( 1, min( 1000, (int) $request->get_param( 'limit' ) ) );
        $free_only = rest_sanitize_boolean( $request->get_param( 'free_only' ) );
        $rows      = $this->model_cache->list( 'openrouter', true, $limit );

        return $this->prepare_item_for_response( $this->format_model_catalog_response( $rows, $free_only ) );
    }

    public function refresh_openrouter_models( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $accepted = rest_sanitize_boolean( $request->get_param( 'accepted_external_service_terms' ) );
        if ( ! $accepted )
        {
            return new WP_Error(
                'sentient_forms_external_service_consent_required',
                __( 'You must accept the OpenRouter external-service disclosure before refreshing model metadata.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        $disclosure_version = sanitize_text_field( (string) $request->get_param( 'disclosure_version' ) );
        $consent_id = $this->consents->record(
            'openrouter',
            $disclosure_version,
            get_current_user_id() ?: null,
            [
                'action'     => 'refresh_models',
                'request_ip' => $this->request_ip_hash(),
            ]
        );

        if ( is_wp_error( $consent_id ) )
        {
            return $consent_id;
        }

        $remote = $this->openrouter->list_models(
            [
                'output_modalities'    => $request->get_param( 'output_modalities' ) ?: 'text',
                'supported_parameters' => $request->get_param( 'supported_parameters' ),
            ]
        );

        if ( is_wp_error( $remote ) )
        {
            return $remote;
        }

        $models = isset( $remote['data'] ) && is_array( $remote['data'] ) ? $remote['data'] : [];
        if ( empty( $models ) )
        {
            return new WP_Error(
                'openrouter_empty_models',
                __( 'OpenRouter returned no model metadata.', 'sentient-forms' ),
                [ 'status' => 502 ]
            );
        }

        $normalised = [];
        foreach ( $models as $model )
        {
            if ( is_array( $model ) )
            {
                $normalised_model = $this->normalise_openrouter_model( $model );
                if ( null !== $normalised_model )
                {
                    $normalised[] = $normalised_model;
                }
            }
        }

        $expires_at = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
        $stored     = $this->model_cache->upsert_many( 'openrouter', $normalised, $expires_at );
        if ( is_wp_error( $stored ) )
        {
            return $stored;
        }

        $rows     = $this->model_cache->list( 'openrouter', true, 1000 );
        $response = $this->format_model_catalog_response( $rows, false );
        $response['consent_recorded'] = true;
        $response['consent_id']       = $consent_id;
        $response['stored']           = (int) $stored;

        return $this->prepare_item_for_response( $response );
    }

    public function setup_sentient_managed_proxy( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $accepted = rest_sanitize_boolean( $request->get_param( 'accepted_external_service_terms' ) );
        if ( ! $accepted )
        {
            return new WP_Error(
                'sentient_forms_external_service_consent_required',
                __( 'You must accept the Sentient managed proxy disclosure before enabling managed execution.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        $license_data = Sentient_Forms_Plugin::instance()->get_license_data();
        $account      = $this->resolve_managed_account_state( $license_data );
        if ( is_wp_error( $account ) )
        {
            return $account;
        }

        $disclosure_version = sanitize_text_field( (string) $request->get_param( 'disclosure_version' ) );
        $consent_id = $this->consents->record(
            'sentient_managed',
            $disclosure_version,
            get_current_user_id() ?: null,
            [
                'action'                              => 'setup_managed_proxy',
                'request_ip'                          => $this->request_ip_hash(),
                'license_id'                          => $account['license_id'],
                'site_id'                             => $account['site_id'],
                'local_site_identifier'               => $account['local_site_identifier'],
                'managed_proxy_selected'              => true,
                'direct_openrouter_billed_by_sentient' => false,
                'managed_proxy_billed_by_sentient'      => true,
            ]
        );

        if ( is_wp_error( $consent_id ) )
        {
            return $consent_id;
        }

        $label = sanitize_text_field( (string) $request->get_param( 'label' ) );
        if ( '' === $label )
        {
            $label = __( 'Sentient managed proxy', 'sentient-forms' );
        }

        $status_json = [
            'license_id'        => $account['license_id'],
            'site_id'           => $account['site_id'],
            'license_status'    => $account['status'],
            'proxy_key_present' => true,
            'billing_boundary'  => [
                'direct_openrouter_billed_by_sentient' => false,
                'managed_proxy_billed_by_sentient'     => true,
            ],
        ];

        $existing = $this->credentials->find_by_provider_auth_mode( 'sentient_managed', 'sentient_proxy' );
        if ( is_array( $existing ) )
        {
            $updated = $this->credentials->update_status( (int) $existing['id'], 'valid', $status_json );
            if ( is_wp_error( $updated ) )
            {
                return $updated;
            }

            $credential_id = (int) $existing['id'];
        }
        else
        {
            $credential_id = $this->credentials->create(
                [
                    'provider'          => 'sentient_managed',
                    'label'             => $label,
                    'auth_mode'         => 'sentient_proxy',
                    'status'            => 'valid',
                    'status_json'       => $status_json,
                    'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
                ]
            );

            if ( is_wp_error( $credential_id ) )
            {
                return $credential_id;
            }
        }

        $credential = $this->credentials->get( (int) $credential_id );

        return $this->prepare_item_for_response(
            [
                'provider'         => 'sentient_managed',
                'status'           => 'valid',
                'credential_id'    => (int) $credential_id,
                'credential'       => is_array( $credential ) ? $this->format_credential( $credential ) : null,
                'consent_recorded' => true,
                'consent_id'       => $consent_id,
                'account'          => $account,
                'billing_boundary' => [
                    'direct_openrouter_billed_by_sentient' => false,
                    'managed_proxy_billed_by_sentient'     => true,
                ],
            ]
        );
    }

    private function get_openrouter_validate_args(): array
    {
        return [
            'api_key' => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => [ $this, 'sanitize_secret_param' ],
                'validate_callback' => [ $this, 'validate_non_empty_string' ],
            ],
            'label' => [
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'save' => [
                'type'              => 'boolean',
                'required'          => false,
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'disclosure_version' => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ $this, 'validate_non_empty_string' ],
            ],
            'accepted_external_service_terms' => [
                'type'              => 'boolean',
                'required'          => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];
    }

    private function get_openrouter_models_args(): array
    {
        return [
            'free_only' => [
                'type'              => 'boolean',
                'required'          => false,
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'limit' => [
                'type'              => 'integer',
                'required'          => false,
                'default'           => 100,
                'minimum'           => 1,
                'maximum'           => 1000,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];
    }

    private function get_openrouter_model_refresh_args(): array
    {
        return [
            'disclosure_version' => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ $this, 'validate_non_empty_string' ],
            ],
            'accepted_external_service_terms' => [
                'type'              => 'boolean',
                'required'          => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'output_modalities' => [
                'type'              => 'string',
                'required'          => false,
                'default'           => 'text',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'supported_parameters' => [
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];
    }

    private function get_sentient_managed_setup_args(): array
    {
        return [
            'label' => [
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'disclosure_version' => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ $this, 'validate_non_empty_string' ],
            ],
            'accepted_external_service_terms' => [
                'type'              => 'boolean',
                'required'          => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];
    }

    public function sanitize_secret_param( mixed $value, ?WP_REST_Request $request = null, string $param = '' ): string
    {
        return trim( (string) $value );
    }

    public function validate_non_empty_string( mixed $value, ?WP_REST_Request $request = null, string $param = '' ): bool
    {
        return is_string( $value ) && '' !== trim( $value );
    }

    private function format_credential( array $row ): array
    {
        $secret_configured = ! empty( $row['encrypted_secret'] ) || ! empty( $row['constant_name'] );
        if ( 'sentient_managed' === (string) ( $row['provider'] ?? '' ) && 'sentient_proxy' === (string) ( $row['auth_mode'] ?? '' ) )
        {
            $license_data      = Sentient_Forms_Plugin::instance()->get_license_data();
            $secret_configured = '' !== trim( (string) ( $license_data['proxy_api_key'] ?? '' ) );
        }

        return [
            'id'                 => (int) $row['id'],
            'provider'           => (string) $row['provider'],
            'label'              => (string) $row['label'],
            'auth_mode'          => (string) $row['auth_mode'],
            'constant_name'      => isset( $row['constant_name'] ) ? (string) $row['constant_name'] : null,
            'status'             => (string) $row['status'],
            'status_json'        => is_array( $row['status_json'] ?? null ) ? $row['status_json'] : null,
            'last_validated_at'  => $row['last_validated_at'] ?? null,
            'created_at'         => $row['created_at'] ?? null,
            'updated_at'         => $row['updated_at'] ?? null,
            'secret_configured'  => $secret_configured,
        ];
    }

    private function resolve_managed_account_state( array $license_data ): array | WP_Error
    {
        $status             = sanitize_key( (string) ( $license_data['license_status'] ?? '' ) );
        $proxy_key_present  = '' !== trim( (string) ( $license_data['proxy_api_key'] ?? '' ) );
        $site_id            = sanitize_text_field( (string) ( $license_data['site_id'] ?? '' ) );
        $license_id         = sanitize_text_field( (string) ( $license_data['license_id'] ?? '' ) );
        $local_identifier   = sanitize_text_field( (string) ( $license_data['local_site_identifier'] ?? '' ) );

        if ( ! in_array( $status, [ 'active', 'trial', 'valid' ], true ) || ! $proxy_key_present || '' === $site_id || '' === $license_id )
        {
            return new WP_Error(
                'sentient_forms_sentient_managed_account_required',
                __( 'Activate a Sentient managed account before enabling managed proxy execution.', 'sentient-forms' ),
                [
                    'status' => 400,
                    'account' => [
                        'status'            => '' !== $status ? $status : 'inactive',
                        'proxy_key_present' => $proxy_key_present,
                        'site_id_present'   => '' !== $site_id,
                        'license_id_present' => '' !== $license_id,
                    ],
                ]
            );
        }

        return [
            'status'                => $status,
            'license_id'            => $license_id,
            'site_id'               => $site_id,
            'local_site_identifier' => $local_identifier,
            'proxy_key_present'     => true,
            'credential_ready'      => true,
        ];
    }

    private function format_openrouter_key_status( array $validation ): array
    {
        $data = isset( $validation['data'] ) && is_array( $validation['data'] ) ? $validation['data'] : $validation;

        $status = [];
        foreach ( [ 'label', 'usage', 'limit', 'limit_remaining', 'is_free_tier' ] as $key )
        {
            if ( array_key_exists( $key, $data ) )
            {
                $status[ $key ] = $data[ $key ];
            }
        }

        return $this->redact_sensitive_metadata( $status );
    }

    private function format_model_catalog_response( array $rows, bool $free_only ): array
    {
        $models      = [];
        $free_count  = 0;
        $stale_count = 0;

        foreach ( $rows as $row )
        {
            $formatted = $this->format_cached_model( $row );
            if ( $formatted['free'] )
            {
                ++$free_count;
            }

            if ( $formatted['stale'] )
            {
                ++$stale_count;
            }

            if ( $free_only && ! $formatted['free'] )
            {
                continue;
            }

            $models[] = $formatted;
        }

        return [
            'provider'       => 'openrouter',
            'source'         => 'local_cache',
            'total_cached'   => count( $rows ),
            'total_returned' => count( $models ),
            'free_count'     => $free_count,
            'stale_count'    => $stale_count,
            'models'         => $models,
        ];
    }

    private function format_cached_model( array $row ): array
    {
        $metadata = is_array( $row['metadata_json'] ?? null ) ? $row['metadata_json'] : [];

        return [
            'id'                   => (string) ( $row['model_id'] ?? $metadata['id'] ?? '' ),
            'name'                 => isset( $metadata['name'] ) ? (string) $metadata['name'] : (string) ( $row['model_id'] ?? '' ),
            'free'                 => ! empty( $metadata['free'] ),
            'context_length'       => isset( $metadata['context_length'] ) ? (int) $metadata['context_length'] : null,
            'input_modalities'     => isset( $metadata['input_modalities'] ) && is_array( $metadata['input_modalities'] ) ? array_values( $metadata['input_modalities'] ) : [],
            'output_modalities'    => isset( $metadata['output_modalities'] ) && is_array( $metadata['output_modalities'] ) ? array_values( $metadata['output_modalities'] ) : [],
            'supported_parameters' => isset( $metadata['supported_parameters'] ) && is_array( $metadata['supported_parameters'] ) ? array_values( $metadata['supported_parameters'] ) : [],
            'pricing'              => isset( $metadata['pricing'] ) && is_array( $metadata['pricing'] ) ? $metadata['pricing'] : [],
            'fetched_at'           => $row['fetched_at'] ?? null,
            'expires_at'           => $row['expires_at'] ?? null,
            'stale'                => isset( $row['expires_at'] ) && (string) $row['expires_at'] < current_time( 'mysql', true ),
        ];
    }

    private function normalise_openrouter_model( array $model ): ?array
    {
        $model_id = isset( $model['id'] ) ? sanitize_text_field( (string) $model['id'] ) : '';
        if ( '' === $model_id )
        {
            return null;
        }

        $architecture       = isset( $model['architecture'] ) && is_array( $model['architecture'] ) ? $model['architecture'] : [];
        $pricing            = isset( $model['pricing'] ) && is_array( $model['pricing'] ) ? $model['pricing'] : [];
        $supported_params   = isset( $model['supported_parameters'] ) && is_array( $model['supported_parameters'] ) ? array_values( $model['supported_parameters'] ) : [];
        $input_modalities   = isset( $architecture['input_modalities'] ) && is_array( $architecture['input_modalities'] ) ? array_values( $architecture['input_modalities'] ) : [];
        $output_modalities  = isset( $architecture['output_modalities'] ) && is_array( $architecture['output_modalities'] ) ? array_values( $architecture['output_modalities'] ) : [];

        return [
            'id'                   => $model_id,
            'name'                 => isset( $model['name'] ) ? sanitize_text_field( (string) $model['name'] ) : $model_id,
            'description'          => isset( $model['description'] ) ? wp_kses_post( (string) $model['description'] ) : '',
            'context_length'       => isset( $model['context_length'] ) ? absint( $model['context_length'] ) : null,
            'free'                 => $this->is_free_openrouter_model( $model_id, $pricing ),
            'pricing'              => $this->normalise_pricing( $pricing ),
            'input_modalities'     => array_map( 'sanitize_key', $input_modalities ),
            'output_modalities'    => array_map( 'sanitize_key', $output_modalities ),
            'supported_parameters' => array_map( 'sanitize_key', $supported_params ),
            'expiration_date'      => isset( $model['expiration_date'] ) ? sanitize_text_field( (string) $model['expiration_date'] ) : null,
        ];
    }

    private function is_free_openrouter_model( string $model_id, array $pricing ): bool
    {
        if ( str_ends_with( $model_id, ':free' ) )
        {
            return true;
        }

        foreach ( [ 'prompt', 'completion', 'request' ] as $price_key )
        {
            if ( ! array_key_exists( $price_key, $pricing ) )
            {
                return false;
            }

            if ( 0.0 !== (float) $pricing[ $price_key ] )
            {
                return false;
            }
        }

        return true;
    }

    private function normalise_pricing( array $pricing ): array
    {
        $normalised = [];
        foreach ( $pricing as $key => $value )
        {
            if ( is_scalar( $value ) )
            {
                $normalised[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
            }
        }

        return $normalised;
    }

    private function credential_status_from_key_status( array $key_status ): string
    {
        if ( array_key_exists( 'limit_remaining', $key_status ) && 0 >= (int) $key_status['limit_remaining'] )
        {
            return 'limited';
        }

        return 'valid';
    }

    private function redact_sensitive_metadata( mixed $value ): mixed
    {
        if ( ! is_array( $value ) )
        {
            return $value;
        }

        $redacted = [];
        foreach ( $value as $key => $item )
        {
            $key_string = is_string( $key ) ? strtolower( $key ) : (string) $key;
            if ( preg_match( '/(api[_-]?key|secret|token|credential|password)/', $key_string ) )
            {
                $redacted[ $key ] = '[redacted]';
                continue;
            }

            $redacted[ $key ] = $this->redact_sensitive_metadata( $item );
        }

        return $redacted;
    }

    private function request_ip_hash(): ?string
    {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        if ( '' === $ip )
        {
            return null;
        }

        return hash( 'sha256', $ip );
    }
}
