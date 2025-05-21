<?php
/**
 * REST API License Controller class for the Sentient Forms plugin.
 * Handles routes related to managing the plugin's license.
 *
 * @package    SentientForms
 * @subpackage REST_API\Controllers
 * @since      0.1.0
 */

// Ensure this file is loaded within WordPress.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_License_Controller
 * Manages REST API endpoints for plugin license.
 */
class Sentient_Forms_License_Controller extends Abstract_Sentient_Forms_Base_Controller
{

    /**
     * The base of this controller's routes.
     *
     * @var string
     * @since 0.1.0
     */
    protected string $rest_base = 'license';

    /**
     * Instance of a specific license validator (if created).
     * For this example, we might use generic validation or inline it.
     *
     * @var mixed|null
     * @since 0.1.0
     */
    // private $validator; // Example: private Sentient_Forms_License_Validator $validator;

    /**
     * Instance of the admin permission checker.
     *
     * @var Sentient_Forms_Admin_Permission
     * @since 0.1.0
     */
    private Sentient_Forms_Admin_Permission $permission_checker;

    /**
     * Option key where license data is stored.
     */
    const LICENSE_KEY_OPTION        = 'sentient_forms_license_key';
    const LICENSE_STATUS_OPTION     = 'sentient_forms_license_status';
    const LICENSE_KEY_REGEX_PATTERN = '/^[0-7][0-9a-hA-Hj-kJ-Km-nM-Np-tP-Tv-zV-Z]{25}$/'; // ULID

    /**
     * Constructor.
     *
     * @since 0.1.0
     */
    public function __construct()
    {
        parent::__construct();

        // if ( class_exists( 'Sentient_Forms_License_Validator' ) ) {
        //     $this->validator = new Sentient_Forms_License_Validator();
        // }

        if ( !class_exists( 'Sentient_Forms_Admin_Permission' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Required dependency Sentient_Forms_Admin_Permission not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }
        
        $this->permission_checker = new Sentient_Forms_Admin_Permission();
    }

    /**
     * Registers the routes for the license controller.
     *
     * @since 0.1.0
     */
    public function register_routes(): void
    {
        // Route to get current license status and key (partially masked)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_license_info' ],
                    'permission_callback' => [ $this->permission_checker, 'can_manage_settings' ],
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ],
        );

        // Route to activate a license
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/activate',
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'activate_license' ],
                    'permission_callback' => [ $this->permission_checker, 'can_manage_settings' ],
                    'args'                => [
                        'license_key' => [
                            'required'          => true,
                            'type'              => 'string',
                            'description'       => __( 'The license key to activate.', 'sentient-forms' ),
                            'sanitize_callback' => 'sanitize_text_field',
                            'validate_callback' => [ $this, 'validate_license_key_format' ],
                        ],
                    ],
                ],
            ],
        );

        // Route to deactivate the current license
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/deactivate',
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'deactivate_license' ],
                    'permission_callback' => [ $this->permission_checker, 'can_manage_settings' ],
                ],
            ],
        );
    }

    /**
     * Retrieves the current license information.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_license_info( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $license_key    = get_option( self::LICENSE_KEY_OPTION, '' );
        $license_status = get_option( self::LICENSE_STATUS_OPTION, 'inactive' );

        $masked_key = '';
        if ( !empty( $license_key ) && strlen( $license_key ) > 8 )
        {
            $masked_key = substr( $license_key, 0, 4 ) . str_repeat( '*', strlen( $license_key ) - 8 ) . substr( $license_key, -4 );
        }
        elseif ( !empty( $license_key ) )
        {
            $masked_key = str_repeat( '*', strlen( $license_key ) );
        }

        $data = [
            'license_key_masked' => $masked_key,
            'status'             => $license_status,
            'expires_at'         => get_option( 'sentient_forms_license_expiry' ),
            // Add other relevant data like expiry date if available
        ];
        return $this->prepare_item_for_response( $data );
    }

    /**
     * Activates a license key.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_Error
     */
    public function activate_license( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $license_key = $request->get_param( 'license_key' );

        // --- Placeholder for actual license activation logic --- // gx todo - add actual license activation logic
        // This would typically involve:
        // 1. Sending the license key to your licensing server.
        // 2. Receiving a response (success/failure, status, expiry date).
        // Example: $activation_response = My_Plugin_License_API_Client::activate( $license_key );

        $is_successful_activation        = true; // Simulate successful activation // gx todo - implement actual activation logic
        $activation_response             = new stdClass();
        $activation_response->expires_at = date( 'Y-m-d H:i:s', strtotime( '+1 year' ) ); // Simulate expiry date

        $new_status = 'active';
        $message    = __( 'License activated successfully.', 'sentient-forms' );

        if ( $is_successful_activation )
        {
            update_option( self::LICENSE_KEY_OPTION, $license_key );
            update_option( self::LICENSE_STATUS_OPTION, $new_status );
            update_option( 'sentient_forms_license_expiry', $activation_response->expires_at );

            return $this->prepare_item_for_response(
                [
                    'success' => true,
                    'message' => $message,
                    'status'  => $new_status,
                ],
            );
        }
        else
        {
            $message = $activation_response->error ?? __( 'License activation failed. Please check your key and try again.', 'sentient-forms' );
            //            $message = __( 'License activation failed. Please check your key and try again.', 'sentient-forms' ); // Simulated error
            return $this->prepare_error_response( 'license_activation_failed', $message, 400 );
        }
        // --- End Placeholder ---
    }

    /**
     * Deactivates the current license.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_Error
     */
    public function deactivate_license( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $current_license_key = get_option( self::LICENSE_KEY_OPTION );

        if ( empty( $current_license_key ) )
        {
            return $this->prepare_error_response( 'no_license_to_deactivate', __( 'No active license to deactivate.', 'sentient-forms' ), 400 );
        }

        // --- Placeholder for actual license deactivation logic --- // gx todo - add actual license deactivation logic
        // This would typically involve:
        // 1. Sending a deactivation request to your licensing server with the current key.
        // Example: $deactivation_response = My_Plugin_License_API_Client::deactivate( $current_license_key );
        $is_successful_deactivation = true; // Simulate successful deactivation
        $message                    = __( 'License deactivated successfully.', 'sentient-forms' );

        if ( $is_successful_deactivation )
        {
            update_option( self::LICENSE_STATUS_OPTION, 'inactive' );
            // Optionally clear the key or keep it for reactivation:
            delete_option( self::LICENSE_KEY_OPTION );
            delete_option( 'sentient_forms_license_expiry' );

            return $this->prepare_item_for_response(
                [
                    'success' => true,
                    'message' => $message,
                    'status'  => 'inactive',
                ],
            );
        }
        else
        {
            $message = $deactivation_response->error ?? __( 'License deactivation failed.', 'sentient-forms' );
            //            $message = __( 'License deactivation failed.', 'sentient-forms' ); // Simulated error
            return $this->prepare_error_response( 'license_deactivation_failed', $message, 400 );
        }
        // --- End Placeholder ---
    }

    /**
     * Validates the license key format.
     *
     * @param mixed           $value
     * @param WP_REST_Request $request
     * @param string          $param
     *
     * @return true|WP_Error
     */
    public function validate_license_key_format( mixed $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( empty( $value ) || !is_string( $value ) )
        {
            return new WP_Error(
                'rest_invalid_param', sprintf( __( '%s must be a non-empty string.', 'sentient-forms' ), $param ), [ 'status' => 400 ],
            );
        }

        if ( !preg_match( self::LICENSE_KEY_REGEX_PATTERN, $value ) )
        {
            return new WP_Error( 'rest_invalid_format', sprintf( __( '%s has an invalid format.', 'sentient-forms' ), $param ), [ 'status' => 400 ] );
        }
        return true;
    }

    /**
     * Retrieves the schema for the license information.
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
            '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
            'title'       => $this->rest_base,
            'description' => __( 'Plugin license information.', 'sentient-forms' ),
            'type'        => 'object',
            'properties'  => [
                'license_key_masked' => [
                    'description' => __( 'The current license key, partially masked.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
                'status'             => [
                    'description' => __( 'Current status of the license (e.g., active, inactive, expired).', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
                'expires_at'         => [
                    'description' => __( 'License expiry date (ISO 8601 format).', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                    'format'      => 'date-time',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
            ],
        ];
        return $this->schema;
    }
}
