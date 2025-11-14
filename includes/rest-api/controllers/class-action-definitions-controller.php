<?php
/**
 * REST API Action Definitions Controller for the Sentient Forms plugin.
 * Provides metadata about registered actions.
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
 * Class Sentient_Forms_Action_Definitions_Controller
 * Returns information about available Sentient Forms actions.
 */
class Sentient_Forms_Action_Definitions_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;
    /**
     * Base route for action definitions.
     *
     * @var string
     */
    protected string $rest_base = 'actions/definitions';

    /**
     * Permission checker instance.
     *
     * @var Sentient_Forms_Admin_Permission
     */
    private Sentient_Forms_Admin_Permission $permission_checker;

    /**
     * Action registry instance.
     *
     * @var Sentient_Forms_Action_Registry
     */
    private Sentient_Forms_Action_Registry $action_registry;

    public function __construct()
    {
        parent::__construct();

        if ( !class_exists( 'Sentient_Forms_Admin_Permission' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Required dependency Sentient_Forms_Admin_Permission not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        if ( !class_exists( 'Sentient_Forms_Plugin' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Required dependency Sentient_Forms_Plugin not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        $plugin                   = Sentient_Forms_Plugin::instance();
        $this->action_registry    = $plugin->get_action_registry();
        $this->permission_checker = new Sentient_Forms_Admin_Permission();
    }

    /**
     * Registers REST API routes.
     *
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_action_definitions' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ],
        );
    }

    /**
     * Retrieves the definitions of all actions registered in the action registry.
     *
     * @param WP_REST_Request $request The REST request instance containing any parameters.
     *
     * @return WP_Error|WP_REST_Response Returns a WP_Error if the action registry is unavailable,
     *                                    or a WP_REST_Response containing the action definitions.
     */
    public function get_action_definitions( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
		$definitions = [];
		foreach ( $this->action_registry->get_all_actions() as $id => $action )
		{
			$definitions[] = [
				'id'             => $id,
				'label'          => $action->get_name(),
				'description'    => $action->get_description(),
				'settingsFields' => $action->get_settings_fields(),
				'icon'           => method_exists( $action, 'get_icon' ) ? $action->get_icon() : '',
				'hooks'          => method_exists( $action, 'get_hooks' ) ? $action->get_hooks() : [],
				'compatibility'  => method_exists( $action, 'get_compatibility' ) ? $action->get_compatibility() : [],
				'source'         => 'local',
				'baseCreditCost' => null,
				'modelHint'      => method_exists( $action, 'get_model_hint' ) ? $action->get_model_hint() : null,
			];
		}

        return $this->prepare_item_for_response( $definitions );
    }

    /**
     * Schema for action definitions.
     */
    public function get_item_schema(): ?array
    {
        if ( $this->schema )
        {
            return $this->schema;
        }

        $this->schema = [
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'sentient_action_definition',
            'type'       => 'object',
            'properties' => [
                'id'             => [
                    'description' => __( 'Unique action identifier.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'label'          => [
                    'description' => __( 'Human readable name of the action.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'description'    => [
                    'description' => __( 'Description of the action.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'settingsFields' => [
                    'description'          => __( 'Settings fields available for this action.', 'sentient-forms' ),
                    'type'                 => 'object',
                    'context'              => [ 'view', 'edit' ],
                    'additionalProperties' => true,
                ],
                'icon'           => [
                    'description' => __( 'Dashicon slug representing the action.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'hooks'          => [
                    'description' => __( 'Form hooks this action integrates with.', 'sentient-forms' ),
                    'type'        => 'array',
                    'items'       => [ 'type' => 'string' ],
                    'context'     => [ 'view', 'edit' ],
                ],
                'compatibility'  => [
                    'description'          => __( 'Information about form builder compatibility.', 'sentient-forms' ),
                    'type'                 => 'object',
                    'context'              => [ 'view', 'edit' ],
                    'additionalProperties' => true,
                ],
            ],
        ];

        return $this->schema;
    }
}
