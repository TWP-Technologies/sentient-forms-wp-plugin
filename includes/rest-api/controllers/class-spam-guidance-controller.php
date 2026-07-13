<?php
/**
 * Spam guidance curation REST controller.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Spam_Guidance_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;

    private const ACTION_ID = 'spam_detection_v1';
    private const FORM_CONFIG_PREFIX = 'sentient_forms_form_config_';
    private const ACTION_DEFAULTS_PREFIX = 'sentient_forms_action_defaults_';
    private const FORM_ACTIONS_PREFIX = 'sentient_forms_actions_';
    private const MAX_EXAMPLES = 10;
    private const MAX_EXAMPLE_LENGTH = 800;

    protected string $rest_base = 'spam-guidance';

    private Sentient_Forms_Form_Entry_Search_Service $entry_search;
    private Sentient_Forms_Spam_Guidance_Rationale_Service $rationale_service;
    private ?Sentient_Forms_Form_Mappings_Repository $mappings = null;

    public function __construct(
        ?Sentient_Forms_Form_Entry_Search_Service $entry_search = null,
        ?Sentient_Forms_Form_Mappings_Repository $mappings = null,
        ?Sentient_Forms_Spam_Guidance_Rationale_Service $rationale_service = null
    )
    {
        parent::__construct();
        $this->entry_search      = $entry_search ?? new Sentient_Forms_Form_Entry_Search_Service();
        $this->mappings          = $mappings;
        $this->rationale_service = $rationale_service ?? new Sentient_Forms_Spam_Guidance_Rationale_Service();
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/forms/(?P<form_source>[a-z0-9_-]+)/(?P<form_id>[^/]+)/entries/search',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'search_entries' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'form_source' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_key',
                        ],
                        'form_id'     => [
                            'required'          => true,
                            'sanitize_callback' => [ $this, 'sanitize_form_id_arg' ],
                        ],
                        'q'           => [
                            'required'          => false,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'limit'       => [
                            'required'          => false,
                            'sanitize_callback' => 'absint',
                        ],
                        'status'      => [
                            'required'          => false,
                            'sanitize_callback' => 'sanitize_key',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/forms/(?P<form_source>[a-z0-9_-]+)/(?P<form_id>[^/]+)/examples',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'append_example' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'form_source' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_key',
                        ],
                        'form_id'     => [
                            'required'          => true,
                            'sanitize_callback' => [ $this, 'sanitize_form_id_arg' ],
                        ],
                    ],
                ],
            ]
        );
    }

    public function sanitize_form_id_arg( mixed $value ): string
    {
        return $this->sanitize_form_id_value( $value );
    }

    public function search_entries( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $form_source = sanitize_key( (string) $request['form_source'] );
        $form_id     = $this->route_form_id( $request );
        $query       = sanitize_text_field( (string) ( $request->get_param( 'q' ) ?? '' ) );
        $limit       = max( 1, min( 50, absint( $request->get_param( 'limit' ) ?: 10 ) ) );
        $status      = sanitize_key( (string) ( $request->get_param( 'status' ) ?: 'all' ) );

        $result = $this->entry_search->search( $form_source, $form_id, $query, $limit, $status );
        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return $this->prepare_item_for_response( $result );
    }

    public function append_example( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $form_source  = sanitize_key( (string) $request['form_source'] );
        $form_id      = $this->route_form_id( $request );
        $target_scope = sanitize_key( (string) ( $request->get_param( 'target_scope' ) ?: 'form' ) );
        $label        = sanitize_key( (string) ( $request->get_param( 'label' ) ?: '' ) );
        $entry_id     = sanitize_text_field( (string) ( $request->get_param( 'entry_id' ) ?: '' ) );
        $mapping_id   = $this->resolve_mapping_identifier( $request->get_param( 'mapping_id' ) );
        $rationale    = $this->sanitize_required_text( $request->get_param( 'rationale' ) );
        $manual_text  = $this->sanitize_required_text( $request->get_param( 'text' ) );

        if ( ! in_array( $target_scope, [ 'form', 'mapping', 'action' ], true ) )
        {
            return $this->invalid_payload_error( 'target_scope' );
        }

        if ( ! in_array( $label, [ 'ham', 'spam' ], true ) )
        {
            return $this->invalid_payload_error( 'label' );
        }

        $resolved_entry = null;
        if ( '' !== $entry_id )
        {
            $resolved_entry = $this->entry_search->resolve_entry( $form_source, $form_id, $entry_id );
            if ( is_wp_error( $resolved_entry ) )
            {
                return $resolved_entry;
            }
        }

        $text = '' !== $manual_text
            ? $manual_text
            : ( is_array( $resolved_entry ) ? $this->text_from_entry_summary( $resolved_entry ) : '' );
        if ( '' === $text )
        {
            return $this->invalid_payload_error( 'text' );
        }

        if ( '' === $entry_id && '' === $rationale )
        {
            return $this->invalid_payload_error( 'rationale' );
        }

        $field  = 'ham' === $label ? 'spam_positive_examples' : 'spam_negative_examples';
        $config = $this->load_scope_config( $target_scope, $form_source, $form_id, $mapping_id );
        if ( is_wp_error( $config ) )
        {
            return $config;
        }

        $examples = is_array( $config[ $field ] ?? null ) ? $config[ $field ] : [];
        if ( count( $examples ) >= self::MAX_EXAMPLES )
        {
            return $this->prepare_error_response(
                'sentient_forms_spam_example_cap_reached',
                __( 'This guidance list already has 10 examples.', 'sentient-forms' ),
                409,
                [ 'field' => $field ]
            );
        }

        $example_source = $this->build_example_source( $form_source, $form_id, $entry_id, is_array( $resolved_entry ) ? $resolved_entry : null );
        $example        = [
            'text'      => mb_substr( $text, 0, self::MAX_EXAMPLE_LENGTH ),
            'rationale' => '' !== $entry_id ? '' : mb_substr( $rationale, 0, self::MAX_EXAMPLE_LENGTH ),
            'source'    => $example_source,
        ];

        if ( $this->has_duplicate_example( $examples, $example ) )
        {
            return $this->prepare_error_response(
                'sentient_forms_spam_example_duplicate',
                __( 'This entry is already saved as a spam guidance example for the selected scope.', 'sentient-forms' ),
                409,
                [ 'field' => $field ]
            );
        }

        $generation = null;
        if ( '' !== $entry_id )
        {
            $generation = $this->rationale_service->generate(
                [
                    'label'             => $label,
                    'form_source'       => $form_source,
                    'form_id'           => $form_id,
                    'target_scope'      => $target_scope,
                    'text'              => $example['text'],
                    'entry'             => is_array( $resolved_entry ) ? $resolved_entry : [],
                    'existing_guidance' => [
                        'spam_positive_examples' => is_array( $config['spam_positive_examples'] ?? null ) ? $config['spam_positive_examples'] : [],
                        'spam_negative_examples' => is_array( $config['spam_negative_examples'] ?? null ) ? $config['spam_negative_examples'] : [],
                    ],
                ]
            );
            if ( is_wp_error( $generation ) )
            {
                return $generation;
            }

            $generated_rationale = $this->sanitize_required_text( $generation['rationale'] ?? null );
            if ( '' === $generated_rationale )
            {
                return new WP_Error(
                    'sentient_forms_spam_rationale_empty',
                    __( 'Rationale generation returned an empty rationale.', 'sentient-forms' ),
                    [ 'status' => 502 ]
                );
            }
            $example['rationale'] = $generated_rationale;
        }

        $examples[]       = $example;
        $config[ $field ] = $examples;
        $config           = $this->sanitize_config_examples( $config );

        $saved = $this->save_scope_config( $target_scope, $form_source, $form_id, $mapping_id, $config );
        if ( is_wp_error( $saved ) )
        {
            return $saved;
        }

        return $this->prepare_item_for_response(
            [
                'target_scope' => $target_scope,
                'label'        => $label,
                'config'       => $saved,
                'generation'   => is_array( $generation )
                    ? array_filter(
                        [
                            'route'                     => isset( $generation['route'] ) && is_scalar( $generation['route'] ) ? sanitize_key( (string) $generation['route'] ) : '',
                            'model'                     => isset( $generation['model'] ) && is_scalar( $generation['model'] ) ? sanitize_text_field( (string) $generation['model'] ) : '',
                            'provider_observation_type' => isset( $generation['provider_observation_type'] ) && is_scalar( $generation['provider_observation_type'] )
                                ? sanitize_key( (string) $generation['provider_observation_type'] )
                                : '',
                            'provider_observation_id'   => isset( $generation['provider_observation_id'] ) && is_scalar( $generation['provider_observation_id'] )
                                ? sanitize_text_field( (string) $generation['provider_observation_id'] )
                                : '',
                            'route_decision_reason'     => isset( $generation['route_decision_reason'] ) && is_scalar( $generation['route_decision_reason'] )
                                ? sanitize_key( (string) $generation['route_decision_reason'] )
                                : '',
                        ],
                        static fn( string $value ): bool => '' !== $value
                    )
                        : null,
            ]
        );
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function load_scope_config( string $target_scope, string $form_source, string $form_id, int | string $mapping_id ): array | WP_Error
    {
        if ( 'action' === $target_scope )
        {
            $config = get_option( self::ACTION_DEFAULTS_PREFIX . self::ACTION_ID, [] );
            return is_array( $config ) ? $config : [];
        }

        if ( 'mapping' === $target_scope )
        {
            if ( is_int( $mapping_id ) )
            {
                return $this->load_repository_mapping_config( $form_source, $form_id, $mapping_id );
            }

            if ( '' !== $mapping_id )
            {
                return $this->load_option_mapping_config( $form_source, $form_id, $mapping_id );
            }

            return $this->invalid_payload_error( 'mapping_id' );
        }

        $configs = get_option( $this->form_config_option_key( $form_source, $form_id ), [] );
        if ( ! is_array( $configs ) )
        {
            return [];
        }

        return is_array( $configs[ self::ACTION_ID ] ?? null ) ? $configs[ self::ACTION_ID ] : [];
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function save_scope_config( string $target_scope, string $form_source, string $form_id, int | string $mapping_id, array $config ): array | WP_Error
    {
        $config['updated_at'] = current_time( 'mysql' );

        if ( 'action' === $target_scope )
        {
            update_option( self::ACTION_DEFAULTS_PREFIX . self::ACTION_ID, $config, false );
            return $config;
        }

        if ( 'mapping' === $target_scope )
        {
            if ( is_int( $mapping_id ) )
            {
                $updated = $this->mappings_repository()->update( $mapping_id, [ 'settings_json' => $config ] );
                if ( is_wp_error( $updated ) )
                {
                    return $updated;
                }

                return is_array( $updated['settings_json'] ?? null ) ? $updated['settings_json'] : $config;
            }

            if ( '' !== $mapping_id )
            {
                return $this->save_option_mapping_config( $form_source, $form_id, $mapping_id, $config );
            }

            return $this->invalid_payload_error( 'mapping_id' );
        }

        $option_key = $this->form_config_option_key( $form_source, $form_id );
        $configs    = get_option( $option_key, [] );
        if ( ! is_array( $configs ) )
        {
            $configs = [];
        }

        $configs[ self::ACTION_ID ] = $config;
        update_option( $option_key, $configs, false );

        return $config;
    }

    private function form_config_option_key( string $form_source, string $form_id ): string
    {
        return self::FORM_CONFIG_PREFIX . sanitize_key( $form_source ) . '_' . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( $form_id );
    }

    private function resolve_mapping_identifier( mixed $mapping_id ): int | string
    {
        if ( ! is_scalar( $mapping_id ) )
        {
            return '';
        }

        $mapping_id = sanitize_text_field( (string) $mapping_id );
        if ( ctype_digit( $mapping_id ) )
        {
            return absint( $mapping_id );
        }

        if ( str_starts_with( $mapping_id, 'local_first_' ) )
        {
            $local_mapping_id = substr( $mapping_id, strlen( 'local_first_' ) );
            return ctype_digit( $local_mapping_id ) ? absint( $local_mapping_id ) : $mapping_id;
        }

        return 1 === preg_match( '/^[A-Za-z0-9_:-]+$/', $mapping_id ) ? $mapping_id : '';
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function load_repository_mapping_config( string $form_source, string $form_id, int $mapping_id ): array | WP_Error
    {
        if ( $mapping_id <= 0 )
        {
            return $this->invalid_payload_error( 'mapping_id' );
        }

        $mapping = $this->mappings_repository()->get( $mapping_id );
        if ( ! is_array( $mapping ) )
        {
            return new WP_Error( 'sentient_forms_mapping_not_found', __( 'Mapping could not be found.', 'sentient-forms' ), [ 'status' => 404 ] );
        }

        if ( sanitize_key( (string) ( $mapping['form_source'] ?? '' ) ) !== sanitize_key( $form_source )
            || sanitize_text_field( (string) ( $mapping['form_id'] ?? '' ) ) !== sanitize_text_field( $form_id ) )
        {
            return new WP_Error( 'sentient_forms_mapping_scope_mismatch', __( 'Mapping does not belong to this form.', 'sentient-forms' ), [ 'status' => 409 ] );
        }

        return is_array( $mapping['settings_json'] ?? null ) ? $mapping['settings_json'] : [];
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function load_option_mapping_config( string $form_source, string $form_id, string $mapping_id ): array | WP_Error
    {
        $location = $this->find_option_mapping_location( $form_source, $form_id, $mapping_id );
        if ( is_wp_error( $location ) )
        {
            return $location;
        }

        $mapping = $location['mapping'];
        if ( ! is_array( $mapping ) )
        {
            return new WP_Error( 'sentient_forms_mapping_not_found', __( 'Mapping could not be found.', 'sentient-forms' ), [ 'status' => 404 ] );
        }

        if ( sanitize_key( (string) ( $mapping['central_action_id'] ?? '' ) ) !== self::ACTION_ID )
        {
            return new WP_Error( 'sentient_forms_mapping_scope_mismatch', __( 'Mapping is not a spam detection action.', 'sentient-forms' ), [ 'status' => 409 ] );
        }

        return $mapping;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function save_option_mapping_config( string $form_source, string $form_id, string $mapping_id, array $config ): array | WP_Error
    {
        $location = $this->find_option_mapping_location( $form_source, $form_id, $mapping_id );
        if ( is_wp_error( $location ) )
        {
            return $location;
        }

        $actions = is_array( $location['actions'] ?? null ) ? $location['actions'] : [];
        $key     = isset( $location['key'] ) && is_scalar( $location['key'] ) ? (string) $location['key'] : '';
        $shape   = isset( $location['shape'] ) && is_scalar( $location['shape'] ) ? (string) $location['shape'] : '';
        if ( '' === $key || ! in_array( $shape, [ 'wrapped', 'top_level' ], true ) )
        {
            return new WP_Error( 'sentient_forms_mapping_not_found', __( 'Mapping could not be found.', 'sentient-forms' ), [ 'status' => 404 ] );
        }

        $existing     = is_array( $location['mapping'] ?? null ) ? $location['mapping'] : [];
        $next_mapping = array_merge( $existing, $config );
        if ( empty( $next_mapping['local_mapping_id'] ) )
        {
            $next_mapping['local_mapping_id'] = $mapping_id;
        }

        if ( 'wrapped' === $shape )
        {
            $actions['actions'][ $key ] = $next_mapping;
        }
        else
        {
            $actions[ $key ] = $next_mapping;
        }

        $option_key = isset( $location['option_key'] ) && is_scalar( $location['option_key'] ) ? (string) $location['option_key'] : '';
        if ( '' === $option_key )
        {
            return new WP_Error( 'sentient_forms_mapping_not_found', __( 'Mapping could not be found.', 'sentient-forms' ), [ 'status' => 404 ] );
        }

        update_option( $option_key, $actions, false );

        return $next_mapping;
    }

    /**
     * @return array{option_key:string,actions:array<string,mixed>,shape:string,key:string,mapping:array<string,mixed>}|WP_Error
     */
    private function find_option_mapping_location( string $form_source, string $form_id, string $mapping_id ): array | WP_Error
    {
        foreach ( $this->form_actions_option_keys( $form_source, $form_id ) as $option_key )
        {
            $actions = get_option( $option_key, null );
            if ( ! is_array( $actions ) )
            {
                continue;
            }

            if ( isset( $actions['actions'] ) && is_array( $actions['actions'] ) )
            {
                foreach ( $actions['actions'] as $key => $mapping )
                {
                    if ( is_array( $mapping ) && $this->option_mapping_matches( (string) $key, $mapping, $mapping_id ) )
                    {
                        return [
                            'option_key' => $option_key,
                            'actions'    => $actions,
                            'shape'      => 'wrapped',
                            'key'        => (string) $key,
                            'mapping'    => $mapping,
                        ];
                    }
                }
            }

            foreach ( $actions as $key => $mapping )
            {
                if ( 'actions' === $key || ! is_array( $mapping ) )
                {
                    continue;
                }

                if ( $this->option_mapping_matches( (string) $key, $mapping, $mapping_id ) )
                {
                    return [
                        'option_key' => $option_key,
                        'actions'    => $actions,
                        'shape'      => 'top_level',
                        'key'        => (string) $key,
                        'mapping'    => $mapping,
                    ];
                }
            }
        }

        return new WP_Error( 'sentient_forms_mapping_not_found', __( 'Mapping could not be found.', 'sentient-forms' ), [ 'status' => 404 ] );
    }

    /**
     * @param array<string,mixed> $mapping
     */
    private function option_mapping_matches( string $key, array $mapping, string $mapping_id ): bool
    {
        foreach ( [ $key, $mapping['local_mapping_id'] ?? null, $mapping['mapping_id'] ?? null ] as $candidate )
        {
            if ( is_scalar( $candidate ) && sanitize_text_field( (string) $candidate ) === $mapping_id )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function form_actions_option_keys( string $form_source, string $form_id ): array
    {
        $source  = sanitize_key( $form_source );
        $current = self::FORM_ACTIONS_PREFIX . $source . '_' . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( $form_id );
        $legacy  = array_map(
            static fn ( string $suffix ): string => self::FORM_ACTIONS_PREFIX . $source . '_' . $suffix,
            Sentient_Forms_Provider_Form_Id_Keys::legacy_option_suffixes( $source, $form_id )
        );

        return array_values( array_unique( array_merge( [ $current ], $legacy ) ) );
    }

    private function route_form_id( WP_REST_Request $request ): string
    {
        $url_params = $request->get_url_params();
        $raw        = isset( $url_params['form_id'] ) && is_scalar( $url_params['form_id'] )
            ? (string) $url_params['form_id']
            : (string) $request->get_param( 'form_id' );

        return $this->sanitize_form_id_value( $raw );
    }

    private function sanitize_form_id_value( mixed $value ): string
    {
        $raw = is_scalar( $value ) ? (string) $value : '';
        for ( $i = 0; $i < 2 && str_contains( $raw, '%' ); ++$i )
        {
            $decoded = rawurldecode( $raw );
            if ( $decoded === $raw )
            {
                break;
            }

            $raw = $decoded;
        }

        return sanitize_text_field( $raw );
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function sanitize_config_examples( array $config ): array
    {
        foreach ( [ 'spam_positive_examples', 'spam_negative_examples' ] as $field )
        {
            if ( array_key_exists( $field, $config ) )
            {
                $config[ $field ] = $this->sanitize_spam_guidance_examples( $config[ $field ] );
            }
        }

        return $config;
    }

    /**
     * @param mixed $value
     * @return array<int,array<string,mixed>>
     */
    private function sanitize_spam_guidance_examples( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $sanitized = [];
        foreach ( $value as $example )
        {
            if ( ! is_array( $example ) )
            {
                continue;
            }

            $text      = $this->sanitize_required_text( $example['text'] ?? null );
            $rationale = $this->sanitize_required_text( $example['rationale'] ?? null );
            if ( '' === $text || '' === $rationale )
            {
                continue;
            }

            $item = [
                'text'      => $text,
                'rationale' => $rationale,
            ];
            $source = $this->sanitize_example_source( $example['source'] ?? null );
            if ( null !== $source )
            {
                $item['source'] = $source;
            }

            $sanitized[] = $item;
            if ( count( $sanitized ) >= self::MAX_EXAMPLES )
            {
                break;
            }
        }

        return $sanitized;
    }

    private function sanitize_required_text( mixed $value ): string
    {
        if ( ! is_scalar( $value ) )
        {
            return '';
        }

        return mb_substr( trim( sanitize_textarea_field( (string) $value ) ), 0, self::MAX_EXAMPLE_LENGTH );
    }

    /**
     * @return array<string,mixed>
     */
    private function build_example_source( string $form_source, string $form_id, string $entry_id, ?array $entry ): array
    {
        $source = [
            'kind'                => '' !== $entry_id ? 'entry' : 'manual',
            'form_source'         => sanitize_key( $form_source ),
            'form_id'             => sanitize_text_field( $form_id ),
            'entry_id'            => '' !== $entry_id ? sanitize_text_field( $entry_id ) : '',
            'selected_at'         => gmdate( 'c' ),
            'selected_by_user_id' => get_current_user_id() ?: null,
        ];

        $native_entry_id = is_array( $entry ) && isset( $entry['native_entry_id'] ) && is_scalar( $entry['native_entry_id'] )
            ? sanitize_text_field( (string) $entry['native_entry_id'] )
            : '';
        if ( '' !== $native_entry_id )
        {
            $source['native_entry_id'] = $native_entry_id;
        }

        return $source;
    }

    private function sanitize_example_source( mixed $source ): ?array
    {
        if ( ! is_array( $source ) )
        {
            return null;
        }

        $kind = isset( $source['kind'] ) && is_scalar( $source['kind'] ) ? sanitize_key( (string) $source['kind'] ) : '';
        if ( ! in_array( $kind, [ 'manual', 'entry' ], true ) )
        {
            return null;
        }

        $sanitized = [ 'kind' => $kind ];
        foreach ( [ 'form_source', 'form_id', 'entry_id', 'native_entry_id', 'selected_at' ] as $field )
        {
            if ( array_key_exists( $field, $source ) && is_scalar( $source[ $field ] ) )
            {
                $value = 'form_source' === $field
                    ? sanitize_key( (string) $source[ $field ] )
                    : sanitize_text_field( (string) $source[ $field ] );
                if ( '' !== $value )
                {
                    $sanitized[ $field ] = $value;
                }
            }
        }

        if ( array_key_exists( 'selected_by_user_id', $source ) )
        {
            $sanitized['selected_by_user_id'] = null === $source['selected_by_user_id']
                ? null
                : absint( $source['selected_by_user_id'] );
        }

        return $sanitized;
    }

    private function text_from_entry_summary( array $entry ): string
    {
        $lines = [];
        foreach ( is_array( $entry['field_summary'] ?? null ) ? $entry['field_summary'] : [] as $field )
        {
            if ( ! is_array( $field ) )
            {
                continue;
            }

            $label = isset( $field['label'] ) && is_scalar( $field['label'] )
                ? trim( sanitize_text_field( (string) $field['label'] ) )
                : '';
            $value = isset( $field['value'] ) && is_scalar( $field['value'] )
                ? trim( sanitize_textarea_field( (string) $field['value'] ) )
                : '';
            if ( '' === $value )
            {
                continue;
            }

            $lines[] = ( '' !== $label ? $label : 'Field' ) . ': ' . $value;
        }

        return mb_substr( implode( "\n", $lines ), 0, self::MAX_EXAMPLE_LENGTH );
    }

    /**
     * @param array<int,array<string,mixed>> $examples
     * @param array<string,mixed>            $example
     */
    private function has_duplicate_example( array $examples, array $example ): bool
    {
        $new_text       = strtolower( trim( (string) ( $example['text'] ?? '' ) ) );
        $new_entry_id   = strtolower( trim( (string) ( $example['source']['entry_id'] ?? '' ) ) );
        $new_form_source = sanitize_key( (string) ( $example['source']['form_source'] ?? '' ) );
        $new_form_id     = sanitize_text_field( (string) ( $example['source']['form_id'] ?? '' ) );

        foreach ( $examples as $existing )
        {
            if ( ! is_array( $existing ) )
            {
                continue;
            }

            $existing_text = strtolower( trim( (string) ( $existing['text'] ?? '' ) ) );
            if ( '' !== $new_text && $existing_text === $new_text )
            {
                return true;
            }

            $existing_entry_id = strtolower( trim( (string) ( $existing['source']['entry_id'] ?? '' ) ) );
            if ( '' !== $new_entry_id
                && $existing_entry_id === $new_entry_id
                && sanitize_key( (string) ( $existing['source']['form_source'] ?? '' ) ) === $new_form_source
                && sanitize_text_field( (string) ( $existing['source']['form_id'] ?? '' ) ) === $new_form_id )
            {
                return true;
            }
        }

        return false;
    }

    private function invalid_payload_error( string $field ): WP_Error
    {
        return $this->prepare_error_response(
            'sentient_forms_spam_example_invalid_payload',
            __( 'Spam guidance example payload is invalid.', 'sentient-forms' ),
            400,
            [ 'field' => $field ]
        );
    }

    private function mappings_repository(): Sentient_Forms_Form_Mappings_Repository
    {
        if ( $this->mappings )
        {
            return $this->mappings;
        }

        global $wpdb;
        $this->mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        return $this->mappings;
    }
}
