<?php
/**
 * REST API Form Action Config Controller for the Sentient Forms plugin.
 * Handles routes for form-level action configuration (hierarchical spam examples).
 *
 * @package    SentientForms
 * @subpackage REST_API\Controllers
 * @since      0.1.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Form_Action_Config_Controller
 * Manages REST API endpoints for form-level action configuration (Phase 1: Hierarchical Examples).
 * 
 * This controller stores configuration at the form level, allowing settings to persist
 * even when action mappings are deleted. Settings here act as defaults for all mappings
 * of a given action within a specific form.
 */
class Sentient_Forms_Form_Action_Config_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;

    /**
     * The option prefix for form action configs.
     *
     * @var string
     */
    private const OPTION_PREFIX = 'sentient_forms_form_config_';

    /**
     * The base of this controller's routes.
     *
     * @var string
     * @since 0.1.0
     */
    protected string $rest_base = 'forms/(?P<form_source>[a-z0-9_-]+)/(?P<form_id>[\d]+)/action-config';

    /**
     * Registers the routes for the form action config controller.
     *
     * @since 0.1.0
     */
    public function register_routes(): void
    {
        // GET /forms/{source}/{id}/action-config - List all action configs for a form
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_form_configs' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'form_source' => [
                            'description'       => __( 'Form source slug (e.g., gravity-forms).', 'sentient-forms' ),
                            'type'              => 'string',
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_key',
                        ],
                        'form_id' => [
                            'description'       => __( 'Form ID.', 'sentient-forms' ),
                            'type'              => 'integer',
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ],
        );

        // GET/POST/DELETE /forms/{source}/{id}/action-config/{action_id}
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<action_id>[a-z0-9_-]+)',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_action_config' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_action_config_args(),
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'update_action_config' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => array_merge(
                        $this->get_action_config_args(),
                        $this->get_update_args()
                    ),
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'delete_action_config' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_action_config_args(),
                ],
            ],
        );

        // GET/POST /actions/{action_id}/defaults - Global action defaults (top of hierarchy)
        register_rest_route(
            $this->namespace,
            '/actions/(?P<action_id>[a-z0-9_-]+)/defaults',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_action_defaults' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'action_id' => [
                            'description'       => __( 'Action ID (e.g., spam_detection_v1).', 'sentient-forms' ),
                            'type'              => 'string',
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_key',
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'update_action_defaults' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => array_merge(
                        [
                            'action_id' => [
                                'description'       => __( 'Action ID (e.g., spam_detection_v1).', 'sentient-forms' ),
                                'type'              => 'string',
                                'required'          => true,
                                'sanitize_callback' => 'sanitize_key',
                            ],
                        ],
                        $this->get_update_args()
                    ),
                ],
            ],
        );
    }

    /**
     * Gets route arguments for action config endpoints.
     *
     * @return array
     */
    private function get_action_config_args(): array
    {
        return [
            'form_source' => [
                'description'       => __( 'Form source slug (e.g., gravity-forms).', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_key',
            ],
            'form_id' => [
                'description'       => __( 'Form ID.', 'sentient-forms' ),
                'type'              => 'integer',
                'required'          => true,
                'sanitize_callback' => 'absint',
            ],
            'action_id' => [
                'description'       => __( 'Action ID (e.g., spam_detection_v1).', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_key',
            ],
        ];
    }

    /**
     * Gets update-specific route arguments.
     *
     * @return array
     */
    private function get_update_args(): array
    {
        return [
            'spam_positive_examples' => [
                'description'       => __( 'Examples of legitimate submissions.', 'sentient-forms' ),
                'type'              => 'array',
                'items'             => [ 'type' => 'string' ],
                'sanitize_callback' => [ $this, 'sanitize_string_array' ],
            ],
            'spam_negative_examples' => [
                'description'       => __( 'Examples of spam submissions.', 'sentient-forms' ),
                'type'              => 'array',
                'items'             => [ 'type' => 'string' ],
                'sanitize_callback' => [ $this, 'sanitize_string_array' ],
            ],
            'include_site_context' => [
                'description'       => __( 'Site context inclusion setting.', 'sentient-forms' ),
                'type'              => 'string',
                'enum'              => [ 'global', 'always', 'never' ],
                'sanitize_callback' => 'sanitize_key',
            ],
            'model_override' => [
                'description'       => __( 'Model override for this action on this form.', 'sentient-forms' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'model_selection' => [
                'description'       => __( 'Structured model selection for this action scope.', 'sentient-forms' ),
                'type'              => 'object',
                'sanitize_callback' => [ $this, 'sanitize_model_selection' ],
            ],
            'suppress_notifications_on_spam' => [
                'description'       => __( 'Whether blocking spam classifications should suppress form-submission notifications.', 'sentient-forms' ),
                'type'              => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'skip_downstream_on_spam' => [
                'description'       => __( 'Whether downstream mappings should skip when this spam action classifies spam.', 'sentient-forms' ),
                'type'              => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'spam_result_display_mode' => [
                'description'       => __( 'Whether spam analysis notes should be stored for none, spam-only, or all results.', 'sentient-forms' ),
                'type'              => 'string',
                'enum'              => [ 'none', 'spam_only', 'all_results', 'entry_note', 'silent' ],
                'sanitize_callback' => [ $this, 'sanitize_spam_result_display_mode' ],
            ],
            'spam_indicators_display' => [
                'description'       => __( 'How much spam-indicator detail to include in entry notes.', 'sentient-forms' ),
                'type'              => 'string',
                'enum'              => [ 'simple', 'detailed' ],
                'sanitize_callback' => [ $this, 'sanitize_spam_indicators_display' ],
            ],
        ];
    }

    /**
     * Sanitizes an array of strings.
     *
     * @param mixed $value Value to sanitize.
     * @return array
     */
    public function sanitize_string_array( $value ): array
    {
        if ( !is_array( $value ) )
        {
            return [];
        }

        return array_map( 'sanitize_text_field', array_filter( $value, 'is_string' ) );
    }

    /**
     * Sanitizes a structured model selection payload.
     *
     * @param mixed $value Value to sanitize.
     * @return array|null
     */
    public function sanitize_model_selection( $value ): ?array
    {
        if ( !is_array( $value ) )
        {
            return null;
        }

        $primary = isset( $value['primary'] ) ? sanitize_text_field( (string) $value['primary'] ) : '';
        if ( $primary === '' )
        {
            return null;
        }

        $backup = null;
        if ( isset( $value['backup'] ) && is_string( $value['backup'] ) )
        {
            $sanitized_backup = sanitize_text_field( $value['backup'] );
            $backup           = $sanitized_backup !== '' ? $sanitized_backup : null;
        }

        $selection = [
            'primary'   => $primary,
            'backup'    => $backup,
            'is_preset' => isset( $value['is_preset'] ) ? (bool) $value['is_preset'] : false,
        ];

        if ( isset( $value['provider'] ) && is_scalar( $value['provider'] ) )
        {
            $provider = sanitize_key( (string) $value['provider'] );
            if ( in_array( $provider, [ 'openrouter', 'sentient_managed' ], true ) )
            {
                $selection['provider'] = $provider;
            }
        }

        if ( isset( $value['credential_id'] ) && is_scalar( $value['credential_id'] ) )
        {
            $credential_id = absint( $value['credential_id'] );
            if ( $credential_id > 0 )
            {
                $selection['credential_id'] = $credential_id;
            }
        }

        $reasoning = isset( $value['reasoning'] ) ? sanitize_key( (string) $value['reasoning'] ) : '';
        if ( in_array( $reasoning, [ 'none', 'minimal', 'low', 'medium', 'high', 'xhigh' ], true ) )
        {
            $selection['reasoning'] = $reasoning;
        }

        $tools = $this->sanitize_model_tool_settings( $value['tools'] ?? null );
        if ( [] !== $tools )
        {
            $selection['tools'] = $tools;
        }

        return $selection;
    }

    /**
     * Sanitizes optional OpenRouter server-tool controls.
     *
     * @param mixed $value Tool settings.
     * @return array<string, mixed>
     */
    private function sanitize_model_tool_settings( $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $settings = [];
        foreach ( [ 'web_search', 'web_fetch', 'datetime' ] as $tool_key )
        {
            if ( ! is_array( $value[ $tool_key ] ?? null ) )
            {
                continue;
            }

            $mode = sanitize_key( (string) ( $value[ $tool_key ]['mode'] ?? 'inherit' ) );
            if ( ! in_array( $mode, [ 'inherit', 'off', 'auto', 'required' ], true ) )
            {
                $mode = 'inherit';
            }

            if ( 'inherit' === $mode )
            {
                continue;
            }

            $settings[ $tool_key ] = [ 'mode' => $mode ];

            if ( 'web_search' === $tool_key )
            {
                $max_results = absint( $value[ $tool_key ]['max_results'] ?? 0 );
                if ( $max_results > 0 )
                {
                    $settings[ $tool_key ]['max_results'] = min( 10, $max_results );
                }
            }
        }

        $tool_choice = sanitize_key( (string) ( $value['tool_choice'] ?? 'inherit' ) );
        if ( in_array( $tool_choice, [ 'off', 'auto', 'required' ], true ) )
        {
            $settings['tool_choice'] = $tool_choice;
        }

        return $settings;
    }

    /**
     * @param mixed $value
     */
    public function sanitize_spam_result_display_mode( $value ): string
    {
        $value = sanitize_key( (string) $value );

        return match ( $value ) {
            'entry_note' => 'all_results',
            'silent'     => 'none',
            'none',
            'spam_only',
            'all_results' => $value,
            default      => 'all_results',
        };
    }

    /**
     * @param mixed $value
     */
    public function sanitize_spam_indicators_display( $value ): string
    {
        return 'detailed' === sanitize_key( (string) $value ) ? 'detailed' : 'simple';
    }

    /**
     * Normalizes a config payload for API responses and legacy option reads.
     *
     * @param mixed $config Raw config value.
     * @return array
     */
    private function normalize_action_config( $config ): array
    {
        if ( !is_array( $config ) )
        {
            return [];
        }

        if ( empty( $config['model_selection'] ) && !empty( $config['model_override'] ) && is_string( $config['model_override'] ) )
        {
            $config['model_selection'] = [
                'primary'   => sanitize_text_field( $config['model_override'] ),
                'backup'    => null,
                'is_preset' => str_starts_with( (string) $config['model_override'], 'sf_' ),
            ];
        }

        if ( isset( $config['model_selection'] ) )
        {
            $config['model_selection'] = $this->sanitize_model_selection( $config['model_selection'] );
            if ( $config['model_selection'] === null )
            {
                unset( $config['model_selection'] );
            }
        }

        foreach ( [ 'suppress_notifications_on_spam', 'skip_downstream_on_spam' ] as $field )
        {
            if ( array_key_exists( $field, $config ) )
            {
                $config[ $field ] = rest_sanitize_boolean( $config[ $field ] );
            }
        }

        if ( array_key_exists( 'spam_result_display_mode', $config ) )
        {
            $config['spam_result_display_mode'] = $this->sanitize_spam_result_display_mode( $config['spam_result_display_mode'] );
        }

        if ( array_key_exists( 'spam_indicators_display', $config ) )
        {
            $config['spam_indicators_display'] = $this->sanitize_spam_indicators_display( $config['spam_indicators_display'] );
        }

        return $config;
    }

    /**
     * Generates the option key for a form's action configs.
     *
     * @param string $form_source Form source slug.
     * @param int    $form_id     Form ID.
     * @return string
     */
    private function get_option_key( string $form_source, int $form_id ): string
    {
        return self::OPTION_PREFIX . $form_source . '_' . $form_id;
    }

    /**
     * Gets all action configs for a form.
     *
     * @param string $form_source Form source slug.
     * @param int    $form_id     Form ID.
     * @return array<string, array>
     */
    private function get_all_configs( string $form_source, int $form_id ): array
    {
        $option_key = $this->get_option_key( $form_source, $form_id );
        $configs    = get_option( $option_key, [] );

        return is_array( $configs ) ? $configs : [];
    }

    /**
     * Saves all action configs for a form.
     *
     * @param string              $form_source Form source slug.
     * @param int                 $form_id     Form ID.
     * @param array<string,array> $configs     The configs to save.
     * @return bool
     */
    private function save_all_configs( string $form_source, int $form_id, array $configs ): bool
    {
        $option_key = $this->get_option_key( $form_source, $form_id );

        // If empty, delete the option
        if ( empty( $configs ) )
        {
            return delete_option( $option_key );
        }

        return update_option( $option_key, $configs, false );
    }

    /**
     * Gets all action configs for a form.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_form_configs( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source = $request->get_param( 'form_source' );
        $form_id     = (int) $request->get_param( 'form_id' );

        $configs = array_map( [ $this, 'normalize_action_config' ], $this->get_all_configs( $form_source, $form_id ) );

        return $this->prepare_item_for_response( [
            'form_source' => $form_source,
            'form_id'     => $form_id,
            'configs'     => $configs,
        ] );
    }

    /**
     * Gets a specific action config for a form.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_action_config( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source = $request->get_param( 'form_source' );
        $form_id     = (int) $request->get_param( 'form_id' );
        $action_id   = $request->get_param( 'action_id' );

        $configs       = $this->get_all_configs( $form_source, $form_id );
        $action_config = $this->normalize_action_config( $configs[ $action_id ] ?? [] );

        return $this->prepare_item_for_response( [
            'form_source' => $form_source,
            'form_id'     => $form_id,
            'action_id'   => $action_id,
            'config'      => $action_config,
        ] );
    }

    /**
     * Updates a specific action config for a form.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_action_config( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source = $request->get_param( 'form_source' );
        $form_id     = (int) $request->get_param( 'form_id' );
        $action_id   = $request->get_param( 'action_id' );

        $configs       = $this->get_all_configs( $form_source, $form_id );
        $action_config = $this->normalize_action_config( $configs[ $action_id ] ?? [] );

        // Update only provided fields
        $updateable_fields = [
            'spam_positive_examples',
            'spam_negative_examples',
            'include_site_context',
            'model_override',
            'model_selection',
            'suppress_notifications_on_spam',
            'skip_downstream_on_spam',
            'spam_result_display_mode',
            'spam_indicators_display',
        ];

        foreach ( $updateable_fields as $field )
        {
            if ( $request->has_param( $field ) )
            {
                $value = $request->get_param( $field );
                
                // If value is null or empty array and field exists, remove it
                if ( $value === null || ( is_array( $value ) && empty( $value ) ) )
                {
                    unset( $action_config[ $field ] );
                }
                else
                {
                    $action_config[ $field ] = $value;
                    if ( $field === 'model_selection' )
                    {
                        unset( $action_config['model_override'] );
                    }
                }
            }
        }

        // If the action config is empty, remove it entirely
        if ( empty( $action_config ) )
        {
            unset( $configs[ $action_id ] );
        }
        else
        {
            $action_config['updated_at']   = current_time( 'mysql' );
            $configs[ $action_id ]         = $action_config;
        }

        $saved = $this->save_all_configs( $form_source, $form_id, $configs );

        if ( !$saved && !empty( $configs ) )
        {
            return $this->prepare_error_response(
                'save_failed',
                __( 'Failed to save form action config.', 'sentient-forms' ),
                500,
            );
        }

        return $this->prepare_item_for_response( [
            'form_source' => $form_source,
            'form_id'     => $form_id,
            'action_id'   => $action_id,
            'config'      => $this->normalize_action_config( $action_config ),
        ] );
    }

    /**
     * Deletes a specific action config for a form.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function delete_action_config( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source = $request->get_param( 'form_source' );
        $form_id     = (int) $request->get_param( 'form_id' );
        $action_id   = $request->get_param( 'action_id' );

        $configs = $this->get_all_configs( $form_source, $form_id );

        if ( !isset( $configs[ $action_id ] ) )
        {
            return $this->prepare_error_response(
                'not_found',
                __( 'Action config not found.', 'sentient-forms' ),
                404,
            );
        }

        unset( $configs[ $action_id ] );
        $this->save_all_configs( $form_source, $form_id, $configs );

        return $this->prepare_item_for_response( [
            'deleted'     => true,
            'form_source' => $form_source,
            'form_id'     => $form_id,
            'action_id'   => $action_id,
        ] );
    }

    /**
     * Option prefix for global action defaults.
     */
    private const ACTION_DEFAULTS_PREFIX = 'sentient_forms_action_defaults_';

    /**
     * Gets the option key for global action defaults.
     *
     * @param string $action_id Action ID.
     * @return string
     */
    private function get_action_defaults_key( string $action_id ): string
    {
        return self::ACTION_DEFAULTS_PREFIX . $action_id;
    }

    /**
     * Gets global defaults for an action (top of hierarchy).
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_action_defaults( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $action_id = $request->get_param( 'action_id' );
        $option_key = $this->get_action_defaults_key( $action_id );
        $config = $this->normalize_action_config( get_option( $option_key, [] ) );

        return $this->prepare_item_for_response( [
            'action_id' => $action_id,
            'config'    => is_array( $config ) ? $config : (object) [],
        ] );
    }

    /**
     * Updates global defaults for an action.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_action_defaults( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $action_id = $request->get_param( 'action_id' );
        $option_key = $this->get_action_defaults_key( $action_id );
        $config = get_option( $option_key, [] );

        if ( !is_array( $config ) )
        {
            $config = [];
        }

        // Update only provided fields
        $updateable_fields = [
            'spam_positive_examples',
            'spam_negative_examples',
            'include_site_context',
            'model_override',
            'model_selection',
            'suppress_notifications_on_spam',
            'skip_downstream_on_spam',
            'spam_result_display_mode',
            'spam_indicators_display',
        ];

        foreach ( $updateable_fields as $field )
        {
            if ( $request->has_param( $field ) )
            {
                $value = $request->get_param( $field );
                
                // If value is null or empty array, remove the field
                if ( $value === null || ( is_array( $value ) && empty( $value ) ) )
                {
                    unset( $config[ $field ] );
                }
                else
                {
                    $config[ $field ] = $value;
                    if ( $field === 'model_selection' )
                    {
                        unset( $config['model_override'] );
                    }
                }
            }
        }

        // If the config is empty, delete the option
        if ( empty( $config ) )
        {
            delete_option( $option_key );
        }
        else
        {
            $config['updated_at'] = current_time( 'mysql' );
            update_option( $option_key, $config, false );
        }

        return $this->prepare_item_for_response( [
            'action_id' => $action_id,
            'config'    => $this->normalize_action_config( $config ),
        ] );
    }

    /**
     * Retrieves the schema for the form action config response.

     *
     * @return array|null Item schema data.
     */
    public function get_item_schema(): ?array
    {
        if ( $this->schema )
        {
            return $this->schema;
        }

        $this->schema = [
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'form-action-config',
            'type'       => 'object',
            'properties' => [
                'form_source' => [
                    'description' => __( 'Form source slug.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'form_id' => [
                    'description' => __( 'Form ID.', 'sentient-forms' ),
                    'type'        => 'integer',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'action_id' => [
                    'description' => __( 'Action ID.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'config' => [
                    'description' => __( 'Action configuration.', 'sentient-forms' ),
                    'type'        => 'object',
                    'context'     => [ 'view', 'edit' ],
                    'properties'  => [
                        'spam_positive_examples' => [
                            'type'  => 'array',
                            'items' => [ 'type' => 'string' ],
                        ],
                        'spam_negative_examples' => [
                            'type'  => 'array',
                            'items' => [ 'type' => 'string' ],
                        ],
                        'include_site_context' => [
                            'type' => 'string',
                            'enum' => [ 'global', 'always', 'never' ],
                        ],
                        'model_override' => [
                            'type' => 'string',
                        ],
                        'model_selection' => [
                            'type'       => 'object',
                            'properties' => [
                                'primary' => [
                                    'type' => 'string',
                                ],
                                'backup' => [
                                    'type' => [ 'string', 'null' ],
                                ],
                                'is_preset' => [
                                    'type' => 'boolean',
                                ],
                            ],
                        ],
                        'updated_at' => [
                            'type'     => 'string',
                            'format'   => 'date-time',
                            'readonly' => true,
                        ],
                    ],
                ],
            ],
        ];

        return $this->schema;
    }
}
