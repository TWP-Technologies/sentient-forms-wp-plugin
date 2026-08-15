<?php
/**
 * REST API controller for local-first provider onboarding and credentials.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Local_Providers_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;

    protected string $rest_base = 'local/providers';

    private Sentient_Forms_Provider_Credentials_Repository $credentials;
    private Sentient_Forms_External_Service_Consent_Repository $consents;
    private Sentient_Forms_Model_Cache_Repository $model_cache;
    private Sentient_Forms_OpenRouter_Direct_Client $openrouter;
    private Sentient_Forms_Provider_Credential_Vault $vault;
    private Sentient_Forms_Local_Action_Model_Selection_Service $model_selection_service;

    public function __construct(
        ?Sentient_Forms_Provider_Credentials_Repository $credentials = null,
        ?Sentient_Forms_External_Service_Consent_Repository $consents = null,
        ?Sentient_Forms_Model_Cache_Repository $model_cache = null,
        ?Sentient_Forms_OpenRouter_Direct_Client $openrouter = null,
        ?Sentient_Forms_Provider_Credential_Vault $vault = null,
        ?Sentient_Forms_Local_Action_Model_Selection_Service $model_selection_service = null
    )
    {
        parent::__construct();

        global $wpdb;

        $this->credentials = $credentials ?? new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $this->consents    = $consents ?? new Sentient_Forms_External_Service_Consent_Repository( $wpdb );
        $this->model_cache = $model_cache ?? new Sentient_Forms_Model_Cache_Repository( $wpdb );
        $this->openrouter  = $openrouter ?? new Sentient_Forms_OpenRouter_Direct_Client();
        $this->vault       = $vault ?? new Sentient_Forms_Provider_Credential_Vault();
        $this->model_selection_service = $model_selection_service ?? new Sentient_Forms_Local_Action_Model_Selection_Service(
            new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb ),
            $this->credentials,
            new Sentient_Forms_Form_Mappings_Repository( $wpdb )
        );
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
            '/' . $this->rest_base . '/credentials/(?P<id>[\d]+)',
            [
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'delete_credential' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_credential_delete_args(),
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
            '/' . $this->rest_base . '/openrouter/constant',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'save_openrouter_constant' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_openrouter_constant_args(),
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

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/sentient-managed/revoke',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'revoke_sentient_managed_proxy' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_sentient_managed_revoke_args(),
                ],
            ]
        );
    }

    public function list_credentials( WP_REST_Request $request ): WP_REST_Response
    {
        $rows = array_map( [ $this, 'format_credential' ], $this->credentials->list() );

        return $this->prepare_item_for_response( $rows );
    }

    public function delete_credential( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $id         = absint( $request->get_param( 'id' ) );
        $credential = $this->credentials->get( $id );
        if ( null === $credential )
        {
            return new WP_Error(
                'sentient_forms_credential_not_found',
                __( 'Provider credential could not be found.', 'sentient-forms' ),
                [ 'status' => 404 ]
            );
        }

        $deleted = $this->model_selection_service->delete_credential_if_unreferenced( $id );
        if ( is_wp_error( $deleted ) )
        {
            return $deleted;
        }

        return $this->prepare_item_for_response(
            [
                'deleted'    => true,
                'credential' => $this->format_credential( $credential ),
            ]
        );
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

            if ( in_array( $local_status, [ 'valid', 'limited' ], true ) )
            {
                $this->model_selection_service->repair_all_custom_actions();
                Sentient_Forms_Site_Context_Controller::maybe_rearm_first_generation_after_provider_setup();
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

    public function save_openrouter_constant( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $accepted = rest_sanitize_boolean( $request->get_param( 'accepted_external_service_terms' ) );
        if ( ! $accepted )
        {
            return new WP_Error(
                'sentient_forms_external_service_consent_required',
                __( 'You must accept the OpenRouter external-service disclosure before validating this server secret.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        $constant_name       = strtoupper( sanitize_text_field( (string) $request->get_param( 'constant_name' ) ) );
        $disclosure_version  = sanitize_text_field( (string) $request->get_param( 'disclosure_version' ) );

        $constant_validation = Sentient_Forms_Provider_Secret_Resolver::validate_constant_name( $constant_name );
        if ( is_wp_error( $constant_validation ) )
        {
            return $this->restify_secret_resolution_error( $constant_validation );
        }

        $consent_id = $this->consents->record(
            'openrouter',
            $disclosure_version,
            get_current_user_id() ?: null,
            [
                'action'        => 'validate_constant',
                'request_ip'    => $this->request_ip_hash(),
                'auth_mode'     => 'constant',
                'constant_name' => $constant_name,
                'save_enabled'  => true,
            ]
        );

        if ( is_wp_error( $consent_id ) )
        {
            return $consent_id;
        }

        $api_key = Sentient_Forms_Provider_Secret_Resolver::resolve_constant_secret( $constant_name );
        if ( is_wp_error( $api_key ) )
        {
            return $this->restify_secret_resolution_error( $api_key );
        }

        $validation = $this->openrouter->validate_key( $api_key );
        if ( is_wp_error( $validation ) )
        {
            return $validation;
        }

        $key_status   = $this->format_openrouter_key_status( $validation );
        $local_status = $this->credential_status_from_key_status( $key_status );
        $label        = sanitize_text_field( (string) $request->get_param( 'label' ) );
        if ( '' === $label )
        {
            $label = __( 'OpenRouter server secret', 'sentient-forms' );
        }

        $existing = $this->credentials->find_by_provider_auth_mode( 'openrouter', 'constant' );
        if ( is_array( $existing ) )
        {
            $updated = $this->credentials->update(
                (int) $existing['id'],
                [
                    'label'             => $label,
                    'auth_mode'         => 'constant',
                    'encrypted_secret'  => null,
                    'constant_name'     => $constant_name,
                    'status'            => $local_status,
                    'status_json'       => $key_status,
                    'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
                ]
            );

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
                    'provider'          => 'openrouter',
                    'label'             => $label,
                    'auth_mode'         => 'constant',
                    'constant_name'     => $constant_name,
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

        if ( in_array( $local_status, [ 'valid', 'limited' ], true ) )
        {
            $this->model_selection_service->repair_all_custom_actions();
            Sentient_Forms_Site_Context_Controller::maybe_rearm_first_generation_after_provider_setup();
        }

        return $this->prepare_item_for_response(
            [
                'provider'         => 'openrouter',
                'status'           => $local_status,
                'credential_id'    => $credential_id,
                'key_status'       => $key_status,
                'consent_recorded' => true,
                'consent_id'       => $consent_id,
                'auth_mode'        => 'constant',
                'constant_name'    => $constant_name,
            ]
        );
    }

    public function list_openrouter_models( WP_REST_Request $request ): WP_REST_Response
    {
        $limit     = max( 1, min( 1000, (int) $request->get_param( 'limit' ) ) );
        $free_only = rest_sanitize_boolean( $request->get_param( 'free_only' ) );
        $zdr_only  = rest_sanitize_boolean( $request->get_param( 'zdr_only' ) );
        $fetch_limit = ( $free_only || $zdr_only ) ? 1000 : $limit;
        $rows        = $this->model_cache->list( 'openrouter', true, $fetch_limit );
        $response    = $this->format_model_catalog_response( $rows, $free_only, $zdr_only );
        if ( count( $response['models'] ) > $limit )
        {
            $response['models']         = array_slice( $response['models'], 0, $limit );
            $response['total_returned'] = count( $response['models'] );
        }

        return $this->prepare_item_for_response( $response );
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

        do_action( 'sentient_forms_openrouter_model_refresh_consent_recorded', $consent_id, $disclosure_version );

        $response = $this->refresh_openrouter_model_catalog(
            [
                'output_modalities'    => $request->get_param( 'output_modalities' ) ?: 'text',
                'supported_parameters' => $request->get_param( 'supported_parameters' ),
            ]
        );

        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        $response['consent_recorded'] = true;
        $response['consent_id']       = $consent_id;

        return $this->prepare_item_for_response( $response );
    }

    /**
     * Refresh the local OpenRouter model cache from the public OpenRouter catalog.
     *
     * @param array<string,mixed> $args Optional OpenRouter model filters.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function refresh_openrouter_model_catalog( array $args = [] ): array | WP_Error
    {
        $remote = $this->openrouter->list_models(
            [
                'output_modalities'    => $args['output_modalities'] ?? 'text',
                'supported_parameters' => $args['supported_parameters'] ?? null,
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

        $warnings       = [];
        $zdr_ids        = null;
        $zdr_checked_at = gmdate( 'Y-m-d H:i:s' );
        $zdr_remote     = $this->openrouter->list_models( [ 'zdr' => true ] );
        if ( is_wp_error( $zdr_remote ) )
        {
            $warnings[] = [
                'code'    => 'openrouter_zdr_models_unavailable',
                'message' => __( 'OpenRouter model metadata was refreshed, but ZDR eligibility could not be verified. Try refreshing again before using ZDR filters.', 'sentient-forms' ),
            ];
        }
        elseif ( ! is_array( $zdr_remote['data'] ?? null ) )
        {
            $warnings[] = [
                'code'    => 'openrouter_zdr_models_invalid',
                'message' => __( 'OpenRouter returned invalid ZDR model metadata. Try refreshing again before using ZDR filters.', 'sentient-forms' ),
            ];
        }
        else
        {
            $zdr_ids = $this->openrouter_model_id_set( is_array( $zdr_remote['data'] ?? null ) ? $zdr_remote['data'] : [] );
        }

        $normalised = [];
        foreach ( $models as $model )
        {
            if ( is_array( $model ) )
            {
                $normalised_model = $this->normalise_openrouter_model( $model );
                if ( null !== $normalised_model )
                {
                    if ( is_array( $zdr_ids ) )
                    {
                        $normalised_model['zdr_eligible']   = isset( $zdr_ids[ $normalised_model['id'] ] );
                        $normalised_model['zdr_source']     = 'openrouter_models_zdr_filter';
                        $normalised_model['zdr_checked_at'] = $zdr_checked_at;
                    }
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
        $response = $this->format_model_catalog_response( $rows, false, false );
        $response['stored']           = (int) $stored;
        $response['warnings']         = $warnings;

        return $response;
    }

    public function setup_sentient_managed_proxy( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $accepted = rest_sanitize_boolean( $request->get_param( 'accepted_external_service_terms' ) );
        if ( ! $accepted )
        {
            return new WP_Error(
                'sentient_forms_external_service_consent_required',
                __( 'You must accept the Sentient Forms managed-service disclosure before enabling managed execution.', 'sentient-forms' ),
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
            $label = __( 'Sentient Forms managed service', 'sentient-forms' );
        }

        $status_json = [
            'license_id'        => $account['license_id'],
            'site_id'           => $account['site_id'],
            'license_status'    => $account['status'],
            'proxy_key_present' => true,
            'managed_consent'   => [
                'state'                    => 'accepted',
                'disclosure_version'       => $disclosure_version,
                'consent_id'               => (int) $consent_id,
                'accepted_at'              => gmdate( 'Y-m-d H:i:s' ),
                'stripe_plan_changed'      => false,
                'managed_proxy_selected'   => true,
            ],
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
        Sentient_Forms_Site_Context_Controller::maybe_rearm_first_generation_after_provider_setup();

        return $this->prepare_item_for_response(
            [
                'provider'         => 'sentient_managed',
                'status'           => 'valid',
                'credential_id'    => (int) $credential_id,
                'credential'       => is_array( $credential ) ? $this->format_credential( $credential ) : null,
                'consent_recorded' => true,
                'consent_id'       => $consent_id,
                'consent_state'    => 'accepted',
                'account'          => $account,
                'billing_boundary' => [
                    'direct_openrouter_billed_by_sentient' => false,
                    'managed_proxy_billed_by_sentient'     => true,
                ],
            ]
        );
    }

    public function revoke_sentient_managed_proxy( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $confirmed = rest_sanitize_boolean( $request->get_param( 'confirm_managed_service_revocation' ) );
        if ( ! $confirmed )
        {
            return new WP_Error(
                'sentient_forms_managed_service_revocation_confirmation_required',
                __( 'Confirm that managed-service consent should be revoked for this WordPress site.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        $license_data       = Sentient_Forms_Plugin::instance()->get_license_data();
        $disclosure_version = sanitize_text_field( (string) $request->get_param( 'disclosure_version' ) );
        $credential         = $this->credentials->find_by_provider_auth_mode( 'sentient_managed', 'sentient_proxy' );
        $now                = gmdate( 'Y-m-d H:i:s' );

        $consent_id = $this->consents->record(
            'sentient_managed',
            $disclosure_version,
            get_current_user_id() ?: null,
            [
                'action'                              => 'revoke_managed_proxy',
                'request_ip'                          => $this->request_ip_hash(),
                'license_id'                          => sanitize_text_field( (string) ( $license_data['license_id'] ?? '' ) ),
                'site_id'                             => sanitize_text_field( (string) ( $license_data['site_id'] ?? '' ) ),
                'local_site_identifier'               => sanitize_text_field( (string) ( $license_data['local_site_identifier'] ?? '' ) ),
                'managed_proxy_selected'              => false,
                'managed_provider_disabled_locally'    => true,
                'stripe_plan_changed'                 => false,
                'direct_openrouter_billed_by_sentient' => false,
                'managed_proxy_billed_by_sentient'      => true,
            ]
        );

        if ( is_wp_error( $consent_id ) )
        {
            return $consent_id;
        }

        $credential_id = null;
        if ( is_array( $credential ) )
        {
            $credential_id = (int) $credential['id'];
            $status_json   = is_array( $credential['status_json'] ?? null ) ? $credential['status_json'] : [];
            $status_json['managed_consent'] = [
                'state'                         => 'revoked',
                'disclosure_version'            => $disclosure_version,
                'consent_id'                    => (int) $consent_id,
                'revoked_at'                    => $now,
                'stripe_plan_changed'           => false,
                'managed_proxy_selected'        => false,
                'managed_provider_disabled_locally' => true,
            ];
            $status_json['billing_boundary'] = [
                'direct_openrouter_billed_by_sentient' => false,
                'managed_proxy_billed_by_sentient'     => true,
            ];

            $updated = $this->credentials->update_status( $credential_id, 'disabled', $status_json );
            if ( is_wp_error( $updated ) )
            {
                return $updated;
            }

            $this->model_selection_service->repair_all_custom_actions();
            $this->model_selection_service->repair_all_bundled_form_mappings();
        }

        $updated_credential = null !== $credential_id ? $this->credentials->get( $credential_id ) : null;

        return $this->prepare_item_for_response(
            [
                'provider'         => 'sentient_managed',
                'status'           => 'disabled',
                'credential_id'    => $credential_id,
                'credential'       => is_array( $updated_credential ) ? $this->format_credential( $updated_credential ) : null,
                'consent_recorded' => true,
                'consent_id'       => (int) $consent_id,
                'consent_state'    => 'revoked',
                'billing_boundary' => [
                    'direct_openrouter_billed_by_sentient' => false,
                    'managed_proxy_billed_by_sentient'     => true,
                ],
            ]
        );
    }

    private function get_credential_delete_args(): array
    {
        return [
            'id' => [
                'type'              => 'integer',
                'required'          => true,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];
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

    private function get_openrouter_constant_args(): array
    {
        return [
            'constant_name' => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ $this, 'validate_secret_constant_name' ],
            ],
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
            'zdr_only' => [
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

    private function get_sentient_managed_revoke_args(): array
    {
        return [
            'disclosure_version' => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ $this, 'validate_non_empty_string' ],
            ],
            'confirm_managed_service_revocation' => [
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

    /**
     * Validate generic constant syntax at the REST layer.
     *
     * Provider ownership policy is enforced in the handler so callers continue
     * to receive stable sentient_forms_* error codes for policy failures.
     */
    public function validate_secret_constant_name( mixed $value, ?WP_REST_Request $request = null, string $param = '' ): true | WP_Error
    {
        if ( ! is_string( $value ) || ! Sentient_Forms_Provider_Secret_Resolver::has_valid_constant_name_format( $value ) )
        {
            return new WP_Error(
                'sentient_forms_invalid_secret_constant',
                __( 'Constant or environment variable name must use uppercase letters, numbers, and underscores only.', 'sentient-forms' )
            );
        }

        return true;
    }

    private function format_credential( array $row ): array
    {
        $auth_mode         = (string) ( $row['auth_mode'] ?? '' );
        $constant_name     = isset( $row['constant_name'] ) ? (string) $row['constant_name'] : null;
        $secret_configured = ! empty( $row['encrypted_secret'] ) || ! empty( $row['constant_name'] );
        if ( 'constant' === $auth_mode )
        {
            $secret_configured = null !== $constant_name
                && Sentient_Forms_Provider_Secret_Resolver::is_constant_secret_configured( $constant_name );
        }

        $is_managed_proxy = 'sentient_managed' === (string) ( $row['provider'] ?? '' ) && 'sentient_proxy' === (string) ( $row['auth_mode'] ?? '' );
        if ( $is_managed_proxy )
        {
            $license_data      = Sentient_Forms_Plugin::instance()->get_license_data();
            $secret_configured = '' !== trim( (string) ( $license_data['proxy_api_key'] ?? '' ) );
        }

        $status_json = is_array( $row['status_json'] ?? null ) ? $this->redact_sensitive_metadata( $row['status_json'] ) : null;
        if ( $is_managed_proxy )
        {
            $status_json                    = is_array( $status_json ) ? $status_json : [];
            $status_json['managed_consent'] = $this->latest_managed_consent_state();
        }

        return [
            'id'                 => (int) $row['id'],
            'provider'           => (string) $row['provider'],
            'label'              => (string) $row['label'],
            'auth_mode'          => $auth_mode,
            'constant_name'      => $constant_name,
            'status'             => (string) $row['status'],
            'status_json'        => $status_json,
            'last_validated_at'  => $row['last_validated_at'] ?? null,
            'created_at'         => $row['created_at'] ?? null,
            'updated_at'         => $row['updated_at'] ?? null,
            'secret_configured'  => $secret_configured,
        ];
    }

    private function latest_managed_consent_state(): array
    {
        $latest = $this->consents->latest_for_provider( 'sentient_managed' );
        if ( ! is_array( $latest ) )
        {
            return [
                'state' => 'missing',
            ];
        }

        $metadata = is_array( $latest['metadata_json'] ?? null ) ? $latest['metadata_json'] : [];
        $action   = sanitize_key( (string) ( $metadata['action'] ?? '' ) );
        $state    = 'revoke_managed_proxy' === $action ? 'revoked' : 'accepted';
        $at_key   = 'revoked' === $state ? 'revoked_at' : 'accepted_at';

        return [
            'state'                         => $state,
            'action'                        => $action,
            'consent_id'                    => (int) ( $latest['id'] ?? 0 ),
            'disclosure_version'            => sanitize_text_field( (string) ( $latest['disclosure_version'] ?? '' ) ),
            $at_key                         => sanitize_text_field( (string) ( $latest['accepted_at'] ?? '' ) ),
            'managed_proxy_selected'        => rest_sanitize_boolean( $metadata['managed_proxy_selected'] ?? ( 'accepted' === $state ) ),
            'stripe_plan_changed'           => rest_sanitize_boolean( $metadata['stripe_plan_changed'] ?? false ),
            'managed_provider_disabled_locally' => rest_sanitize_boolean( $metadata['managed_provider_disabled_locally'] ?? ( 'revoked' === $state ) ),
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
                __( 'Activate a Sentient Forms managed-service account before enabling managed execution.', 'sentient-forms' ),
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

    private function restify_secret_resolution_error( WP_Error $error ): WP_Error
    {
        $code = $error->get_error_code();
        $data = $error->get_error_data( $code );
        if ( is_array( $data ) && isset( $data['status'] ) )
        {
            return $error;
        }

        return new WP_Error(
            $code,
            $error->get_error_message( $code ),
            [ 'status' => 400 ]
        );
    }

    private function format_model_catalog_response( array $rows, bool $free_only, bool $zdr_only ): array
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

            if ( $zdr_only && true !== $formatted['zdr_eligible'] )
            {
                continue;
            }

            $models[] = $formatted;
        }

        return [
            'provider'        => 'openrouter',
            'source'          => 'local_cache',
            'total_cached'    => count( $rows ),
            'total_returned'  => count( $models ),
            'free_count'      => $free_count,
            'stale_count'     => $stale_count,
            'zdr_filtered'    => $zdr_only,
            'models'          => $models,
            'refresh_consent' => $this->format_openrouter_model_refresh_consent(),
        ];
    }

    private function format_openrouter_model_refresh_consent(): array
    {
        $latest = $this->consents->latest_for_provider_action( 'openrouter', 'refresh_models' );
        if ( ! is_array( $latest ) )
        {
            return [
                'state'              => 'missing',
                'disclosure_version' => null,
                'consent_id'         => null,
                'accepted_at'        => null,
            ];
        }

        return [
            'state'              => 'accepted',
            'disclosure_version' => (string) ( $latest['disclosure_version'] ?? '' ),
            'consent_id'         => isset( $latest['id'] ) ? (int) $latest['id'] : null,
            'accepted_at'        => isset( $latest['accepted_at'] ) ? (string) $latest['accepted_at'] : null,
        ];
    }

    private function format_cached_model( array $row ): array
    {
        $metadata = is_array( $row['metadata_json'] ?? null ) ? $row['metadata_json'] : [];
        $zdr      = $this->format_zdr_eligibility( $metadata );
        $tags     = true === $zdr['eligible'] ? [ 'zdr' ] : [];

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
            'zdr_eligible'         => $zdr['eligible'],
            'zdr_source'           => $zdr['source'],
            'zdr_checked_at'       => $zdr['checked_at'],
            'tags'                 => $tags,
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

    private function openrouter_model_id_set( array $models ): array
    {
        $ids = [];
        foreach ( $models as $model )
        {
            if ( is_array( $model ) && isset( $model['id'] ) && is_scalar( $model['id'] ) )
            {
                $model_id = sanitize_text_field( (string) $model['id'] );
                if ( '' !== $model_id )
                {
                    $ids[ $model_id ] = true;
                }
            }
        }

        return $ids;
    }

    private function format_zdr_eligibility( array $metadata ): array
    {
        $eligible = array_key_exists( 'zdr_eligible', $metadata )
            ? rest_sanitize_boolean( $metadata['zdr_eligible'] )
            : null;
        $source = isset( $metadata['zdr_source'] ) && is_scalar( $metadata['zdr_source'] )
            ? sanitize_key( (string) $metadata['zdr_source'] )
            : null;
        $checked_at = isset( $metadata['zdr_checked_at'] ) && is_scalar( $metadata['zdr_checked_at'] )
            ? sanitize_text_field( (string) $metadata['zdr_checked_at'] )
            : null;

        return [
            'eligible'   => $eligible,
            'source'     => '' !== (string) $source ? $source : null,
            'checked_at' => '' !== (string) $checked_at ? $checked_at : null,
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
