<?php
/**
 * REST API Mappings Controller for the Sentient Forms plugin.
 * Proxies form mapping operations to/from CPS.
 *
 * @package    SentientForms
 * @subpackage REST_API\Controllers
 * @since      0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Sentient_Forms_Mappings_Controller
 *
 * Handles REST API endpoints for form mappings (CSM Phase 7).
 * Enables "Import from Library" and "Save as Template" features.
 */
class Sentient_Forms_Mappings_Controller extends Abstract_Sentient_Forms_Base_Controller {
    use Trait_Sentient_Forms_Permission_Utils;

    /**
     * Base route for mappings.
     *
     * @var string
     */
    protected string $rest_base = 'mappings';

    /**
     * Permission checker instance.
     *
     * @var Sentient_Forms_Admin_Permission
     */
    private Sentient_Forms_Admin_Permission $permission_checker;

    /**
     * Mappings sync service.
     *
     * @var Sentient_Forms_Mappings_Sync
     */
    private Sentient_Forms_Mappings_Sync $mappings_sync;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct();

        if ( ! class_exists( 'Sentient_Forms_Admin_Permission' ) ) {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Required dependency Sentient_Forms_Admin_Permission not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        if ( ! class_exists( 'Sentient_Forms_Mappings_Sync' ) ) {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Required dependency Sentient_Forms_Mappings_Sync not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        $this->permission_checker = new Sentient_Forms_Admin_Permission();
        $this->mappings_sync      = new Sentient_Forms_Mappings_Sync();
    }

    /**
     * Registers REST API routes.
     *
     * @return void
     */
    public function register_routes(): void {
        // GET /mappings/templates - List available templates.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/templates',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_templates' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
            ],
        );

        // POST /mappings - Create a new mapping (Save as Template).
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'create_mapping' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_create_mapping_args(),
                ],
            ],
        );

        // POST /mappings/{id}/clone - Clone a template to a form.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9-]+)/clone',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'clone_template' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_clone_template_args(),
                ],
            ],
        );
    }

    /**
     * Get available templates from CPS.
     *
     * @param WP_REST_Request $request The REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_templates( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $templates = $this->mappings_sync->fetch_templates();

        return rest_ensure_response( [
            'success' => true,
            'data'    => $templates,
        ] );
    }

    /**
     * Create a new mapping in CPS.
     *
     * @param WP_REST_Request $request The REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function create_mapping( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $mapping_data = [
            'site_id'              => $request->get_param( 'site_id' ),
            'form_source'          => $request->get_param( 'form_source' ),
            'form_id'              => $request->get_param( 'form_id' ),
            'action_template_id'   => $request->get_param( 'action_template_id' ),
            'action_template_code' => $request->get_param( 'action_template_code' ),
            'custom_action_id'     => $request->get_param( 'custom_action_id' ),
            'display_name'         => $request->get_param( 'display_name' ),
            'settings'             => $request->get_param( 'settings' ) ?? [],
            'is_template'          => $request->get_param( 'is_template' ) ?? false,
        ];

        // Remove null values
        $mapping_data = array_filter( $mapping_data, fn( $v ) => $v !== null );

        $result = $this->mappings_sync->create_mapping( $mapping_data );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => $result,
        ] );
    }

    /**
     * Clone a template to a specific form.
     *
     * @param WP_REST_Request $request The REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function clone_template( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $template_id = $request->get_param( 'id' );
        $clone_data  = [
            'form_source' => $request->get_param( 'form_source' ),
            'form_id'     => $request->get_param( 'form_id' ),
        ];

        $result = $this->mappings_sync->clone_template( $template_id, $clone_data );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => $result,
        ] );
    }

    /**
     * Get arguments for create mapping endpoint.
     *
     * @return array
     */
    private function get_create_mapping_args(): array {
        return [
            'site_id'            => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'form_source'        => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'form_id'            => [
                'required' => false,
                'type'     => 'integer',
            ],
            'action_template_id'   => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'action_template_code' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => 'String code for master templates (e.g., spam_detection_v1)',
            ],
            'custom_action_id'     => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'display_name'       => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'settings'           => [
                'required' => false,
                'type'     => 'object',
            ],
            'is_template'        => [
                'required' => false,
                'type'     => 'boolean',
                'default'  => false,
            ],
        ];
    }

    /**
     * Get arguments for clone template endpoint.
     *
     * @return array
     */
    private function get_clone_template_args(): array {
        return [
            'form_source' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'form_id'     => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }
}
