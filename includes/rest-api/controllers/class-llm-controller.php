<?php
/**
 * REST API LLM Models Controller class for the Sentient Forms plugin.
 * Handles routes for retrieving information about available LLM models.
 *
 * @package    SentientForms
 * @subpackage REST_API\Controllers
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Llm_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;
    protected string $rest_base = 'llms/models';

    private Sentient_Forms_Admin_Permission $permission_checker;

    /**
     * Transient key for caching model definitions.
     */
    const MODELS_TRANSIENT_KEY = 'sentient_forms_llm_definitions_cache';

    /**
     * TTL for the cached model list in seconds.
     */
    const MODELS_TRANSIENT_TTL = 3 * 60 * 60; // 3 hours

    /**
     * Transient key for caching API error responses.
     */
    const API_ERROR_TRANSIENT_KEY = 'sentient_forms_llm_api_error_cache';

    /**
     * TTL for caching API error responses in seconds (e.g., 5 minutes).
     */
    const API_ERROR_TRANSIENT_TTL = 5 * 60;

    public function __construct()
    {
        parent::__construct();

        if ( !class_exists( 'Sentient_Forms_Admin_Permission' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Sentient Forms: Sentient_Forms_Admin_Permission class not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        $this->permission_checker = new Sentient_Forms_Admin_Permission();
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_models' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_collection_params(),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ],
        );
    }

    /**
     * Normalizes a single model item from the raw API response to conform to the plugin's schema.
     *
     * @param array $raw_model_item The raw model data from the API.
     *
     * @return array The normalized model data.
     */
    private function normalise_remote_model( array $raw_model_item ): array
    {
        $schema_properties = $this->get_item_schema()[ 'items' ][ 'properties' ] ?? [];
        $normalised_item   = [];

        // Iterate over schema properties to ensure all expected keys are present and correctly typed.
        foreach ( $schema_properties as $key => $details )
        {
            $value = $raw_model_item[ $key ] ?? null; // Get value or null if not set

            switch ( $key )
            {
                case 'id':
                case 'name':
                case 'description':
                case 'provider':
                case 'status':
                case 'cost_tier':
                    $normalised_item[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : '';
                    break;
                case 'capabilities':
                    // Ensure capabilities are always an array of strings.
                    if ( is_string( $value ) )
                    {
                        $value = explode( ',', $value );
                        $value = array_map( 'trim', $value );
                    }
                    $normalised_item[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : [];
                    break;
                case 'is_default_for_free_tier':
                    // Ensure boolean type
                    if ( is_string( $value ) )
                    {
                        $normalised_item[ $key ] = filter_var( strtolower( $value ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
                    }
                    elseif ( is_numeric( $value ) )
                    {
                        $normalised_item[ $key ] = (bool)$value;
                    }
                    else
                    {
                        $normalised_item[ $key ] = is_bool( $value ) ? $value : false;
                    }
                    break;
                case 'family':
                    $normalised_item[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : null;
                    break;
                default:
                    // For any other properties defined in schema but not explicitly handled,
                    // assign them if they exist in raw data, or null.
                    $normalised_item[ $key ] = $value;
                    break;
            }
        }
        return $normalised_item;
    }

    public function get_models( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        // Get request parameters
        $statuses_param     = $request->get_param( 'status' );
        $providers_param    = $request->get_param( 'provider' );
        $capabilities_param = $request->get_param( 'capabilities' );
        $force_refresh      = $request->get_param( 'force_refresh' );
        $force_refresh      = filter_var( $force_refresh, FILTER_VALIDATE_BOOLEAN );

        // Initialize WP_Error object to collect all validation errors
        $errors = new WP_Error();

        // Parse and validate enum parameters
        $statuses = $this->parse_enum_params( $statuses_param, Sentient_Forms_Llm_Status::class );
        if ( is_wp_error( $statuses ) )
        {
            $errors->merge_from( $statuses );
        }

        $providers = $this->parse_enum_params( $providers_param, Sentient_Forms_Llm_Provider::class );
        if ( is_wp_error( $providers ) )
        {
            $errors->merge_from( $providers );
        }

        $capabilities = $this->parse_enum_params( $capabilities_param, Sentient_Forms_Llm_Capability::class );
        if ( is_wp_error( $capabilities ) )
        {
            $errors->merge_from( $capabilities );
        }

        // If there are any validation errors, return them all at once
        if ( $errors->has_errors() )
        {
            return $errors;
        }

        // Attempt to retrieve models data from cache if not forcing refresh
        $models_data = $force_refresh ? false : get_transient( self::MODELS_TRANSIENT_KEY );

        if ( false === $models_data )
        {
            // If API error is cached and we are not forcing a refresh, return cached error.
            if ( !$force_refresh )
            {
                $cached_api_error = get_transient( self::API_ERROR_TRANSIENT_KEY );
                if ( is_wp_error( $cached_api_error ) )
                {
                    return $cached_api_error;
                }
            }

            $api_key = '';
            if ( class_exists( 'Sentient_Forms_Plugin' ) )
            {
                $api_key = Sentient_Forms_Plugin::instance()->get_proxy_api_key();
            }

            if ( empty( $api_key ) )
            {
                // This error is critical and should not be cached long-term in the API_ERROR_TRANSIENT_KEY
                return $this->prepare_error_response( 'missing_api_key', __( 'Missing proxy API key.', 'sentient-forms' ), 400 );
            }

            if ( !class_exists( 'Sentient_Forms_Llm_Api_Client' ) )
            {
                // This error is critical and should not be cached long-term
                return $this->prepare_error_response( 'missing_api_client', __( 'LLM API client not found.', 'sentient-forms' ), 500 );
            }

            $client   = new Sentient_Forms_Llm_Api_Client( $api_key );
            $response = $client->get_available_models();

            if ( is_wp_error( $response ) )
            {
                // Cache the API error for a short duration to prevent hammering the API
                set_transient( self::API_ERROR_TRANSIENT_KEY, $response, self::API_ERROR_TRANSIENT_TTL );
                sentient_forms_debug_log(
                    'Sentient Forms LLM API returned an error.',
                    [
                        'error_code'    => $response->get_error_code(),
                        'error_message' => $response->get_error_message(),
                    ]
                );
                return $response;
            }

            // Clear any cached API error on successful response
            delete_transient( self::API_ERROR_TRANSIENT_KEY );

            if ( !is_array( $response ) )
            {
                // This indicates an unexpected response format from the API
                $api_error = $this->prepare_error_response( 'invalid_response', __( 'Invalid data from CPS.', 'sentient-forms' ), 500 );
                set_transient( self::API_ERROR_TRANSIENT_KEY, $api_error, self::API_ERROR_TRANSIENT_TTL );
                sentient_forms_debug_log(
                    'Sentient Forms LLM API returned an invalid response.',
                    [
                        'response_type' => gettype( $response ),
                    ]
                );
                return $api_error;
            }

            // Normalise each model in the response array using the new method.
            $normalised_api_response = array_map( [ $this, 'normalise_remote_model' ], $response );

            // Cache the normalised data.
            set_transient( self::MODELS_TRANSIENT_KEY, $normalised_api_response, self::MODELS_TRANSIENT_TTL );
            $models_data = $normalised_api_response; // Ensure $models_data for subsequent filtering is the normalised data.
            sentient_forms_debug_log( 'Sentient Forms LLM cache miss fetched fresh model data.' );
        }
        else
        {
            if ( !$force_refresh )
            {
                sentient_forms_debug_log( 'Sentient Forms LLM cache hit used cached model data.' );
            }
        }

        // Prepare filter values
        $status_values   = $statuses ? array_map( static fn( $s ) => $s->value, $statuses ) : null;
        $provider_values = $providers ? array_map( static fn( $p ) => $p->value, $providers ) : null;
        $cap_values      = $capabilities ? array_map( static fn( $c ) => $c->value, $capabilities ) : null;

        // Filter models based on parameters
        $filtered_models = array_values(
            array_filter(
                $models_data,
                static function ( $model ) use ( $status_values, $provider_values, $cap_values ): bool {
                    // Filter by status
                    if ( $status_values !== null && !in_array( $model[ 'status' ] ?? null, $status_values, true ) )
                    {
                        return false;
                    }

                    // Filter by provider
                    if ( $provider_values !== null && !in_array( $model[ 'provider' ] ?? null, $provider_values, true ) )
                    {
                        return false;
                    }

                    // Filter by capabilities (all specified $cap_values must be present in the model's capabilities)
                    if ( $cap_values !== null )
                    {
                        // $model_capabilities is already an array due to normalise_remote_model
                        $model_capabilities = $model[ 'capabilities' ] ?? [];
                        if ( !empty( array_diff( $cap_values, $model_capabilities ) ) ) // array_diff checks for value presence
                        {
                            return false;
                        }
                    }
                    return true;
                },
            ),
        );
        return $this->prepare_item_for_response( $filtered_models );
    }

    /**
     * Parses and validates an enum parameter against a specified enum class.
     *
     * @param mixed                    $param      The input parameter, which can be a string (comma-separated values),
     *                                             an array, or null.
     * @param class-string<BackedEnum> $enum_class The fully qualified class name of the enum to validate against.
     *
     * @return WP_Error|array|null Returns an array of enum instances if valid, null if the parameter is
     * empty, or a WP_Error object if the parameter is invalid.
     */
    private function parse_enum_params( mixed $param, string $enum_class ): WP_Error | array | null
    {
        if ( empty( $param ) )
        {
            return null;
        }

        if ( is_string( $param ) )
        {
            $param = explode( ',', $param );
        }

        if ( !is_array( $param ) )
        {
            // This error should be added to the $errors object in the calling method.
            return new WP_Error(
                'rest_invalid_param_format', __( 'Invalid parameter format. Expected string or array.', 'sentient-forms' ), [ 'status' => 400 ],
            );
        }

        if ( !is_subclass_of( $enum_class, BackedEnum::class ) )
        {
            // This is a server-side configuration error.
            sentient_forms_debug_log(
                'Sentient Forms invalid enum class provided to parse enum params.',
                [
                    'enum_class' => $enum_class,
                ]
            );
            return new WP_Error(
                'rest_server_error', __( 'Server configuration error for parameter validation.', 'sentient-forms' ), [ 'status' => 500 ],
            );
        }

        $result         = [];
        $invalid_values = [];
        foreach ( $param as $value )
        {
            $trimmed_value = trim( (string)$value );
            if ( '' === $trimmed_value )
            {
                continue;
            } // Skip empty values if part of a comma-separated string like "active,"

            $enum_case = $enum_class::tryFrom( $trimmed_value );
            if ( !$enum_case )
            {
                $invalid_values[] = $trimmed_value;
            }
            else
            {
                $result[] = $enum_case;
            }
        }

        if ( !empty( $invalid_values ) )
        {
            $allowed_cases = array_map( fn( $case ) => $case->value, $enum_class::cases() );
            return new WP_Error(
                'rest_invalid_param_value', sprintf(
                /* translators: 1: invalid parameter values, 2: enum class name, 3: comma-separated allowed enum values. */
                __(
                    'Invalid value(s) for parameter: %1$s. Allowed values for %2$s are: %3$s.',
                    'sentient-forms',
                ),
                implode( ', ', array_map( 'esc_html', $invalid_values ) ),
                esc_html( ( new ReflectionClass( $enum_class ) )->getShortName() ),
                implode( ', ', array_map( 'esc_html', $allowed_cases ) ),
            ),  [ 'status' => 400 ],
            );
        }

        return empty( $result ) ? null : $result; // Return null if all items were empty strings after trim
    }

    public function get_collection_params(): array
    {
        $statuses     = array_map( static fn( $s ) => $s->value, Sentient_Forms_Llm_Status::cases() );
        $providers    = array_map( static fn( $p ) => $p->value, Sentient_Forms_Llm_Provider::cases() );
        $capabilities = array_map( static fn( $c ) => $c->value, Sentient_Forms_Llm_Capability::cases() );

        $params = parent::get_collection_params();

        $params[ 'status' ]       = [
            'description'       => __( 'Filter by status. Comma-separated string or array of strings.', 'sentient-forms' ),
            'type'              => [ 'string', 'array' ], // Allow both for flexibility, parsing handles it.
            'enum'              => $statuses, // Relevant if type is 'string'
            'items'             => [ 'type' => 'string', 'enum' => $statuses ], // Relevant if type is 'array'
            'sanitize_callback' => [ $this, 'sanitize_text_array' ],
        ];
        $params[ 'provider' ]     = [
            'description'       => __( 'Filter by provider. Comma-separated string or array of strings.', 'sentient-forms' ),
            'type'              => [ 'string', 'array' ],
            'enum'              => $providers,
            'items'             => [ 'type' => 'string', 'enum' => $providers ],
            'sanitize_callback' => [ $this, 'sanitize_text_array' ],
        ];
        $params[ 'capabilities' ] = [
            'description'       => __( 'Filter by capabilities (requires all). Comma-separated string or array of strings.', 'sentient-forms' ),
            'type'              => [ 'string', 'array' ],
            'enum'              => $capabilities,
            'items'             => [ 'type' => 'string', 'enum' => $capabilities, ],
            'sanitize_callback' => [ $this, 'sanitize_text_array' ],
        ];

        return $params;
    }

    public function get_item_schema(): ?array
    {
        if ( $this->schema )
        {
            return $this->schema;
        }

        $this->schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title'   => 'sentient_forms_llm_models',
            'type'    => 'array',
            'items'   => [
                'type'       => 'object',
                'properties' => [
                    'id'                       => [
                        'type'        => 'string',
                        'description' => __( 'Unique identifier for the LLM model.', 'sentient-forms' ),
                    ],
                    'name'                     => [
                        'type'        => 'string',
                        'description' => __( 'Human-readable name of the LLM model.', 'sentient-forms' ),
                    ],
                    'description'              => [ 'type' => 'string', 'description' => __( 'Description of the LLM model.', 'sentient-forms' ) ],
                    'provider'                 => [
                        'type'        => 'string',
                        'description' => __( 'Provider of the LLM model (e.g., google, openai).', 'sentient-forms' ),
                    ],
                    'status'                   => [
                        'type'        => 'string',
                        'description' => __( 'Current status of the model (e.g., active, preview).', 'sentient-forms' ),
                    ],
                    'cost_tier'                => [ 'type' => 'string', 'description' => __( 'Relative cost tier of the model.', 'sentient-forms' ) ],
                    'capabilities'             => [
                        'type'        => 'array',
                        'items'       => [ 'type' => 'string' ],
                        'description' => __( 'List of capabilities the model supports.', 'sentient-forms' ),
                    ],
                    'is_default_for_free_tier' => [
                        'type'        => 'boolean',
                        'description' => __(
                            'Indicates if this model is a default option for free tier users.',
                            'sentient-forms',
                        ),
                    ],
                    'family'                   => [
                        'type'        => [ 'string', 'null' ],
                        'description' => __( 'The model family (e.g., Gemini, GPT-4).', 'sentient-forms' ),
                    ],
                ],
                'required'   => [ 'id', 'name', 'provider', 'status', 'cost_tier', 'capabilities', 'is_default_for_free_tier' ],
                // Core fields expected
            ],
        ];
        return $this->schema;
    }

    /**
     * Sanitizes an array of text values, ensuring all elements are trimmed and safely escaped.
     *
     * @param mixed $value The input value, which can be a string (comma-separated) or an array.
     *
     * @return array An array of sanitized and trimmed text values.
     */
    private function sanitize_text_array( mixed $value ): array
    {
        if ( is_string( $value ) )
        {
            $value = explode( ',', $value );
        }

        $trimmed_values   = array_map( 'trim', (array)$value );
        $sanitized_values = array_map( 'sanitize_text_field', $trimmed_values );
        return array_filter( $sanitized_values, fn( $v ) => !empty( $v ) );
    }
}
