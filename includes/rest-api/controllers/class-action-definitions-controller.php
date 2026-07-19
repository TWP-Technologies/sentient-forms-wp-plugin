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
class Sentient_Forms_Action_Definitions_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;
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
        $definitions = $this->get_local_template_definitions();
        if ( empty( $definitions ) )
        {
            return new WP_Error(
                'sentient_forms_bundled_action_catalog_unavailable',
                __( 'The bundled Sentient Forms Action Catalog is unavailable.', 'sentient-forms' ),
                [ 'status' => 503 ]
            );
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
                'templateId'     => [
                    'description' => __( 'Action template identifier.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                    'context'     => [ 'view', 'edit' ],
                ],
                'promptTemplate' => [
                    'description' => __( 'Prompt template used by action templates.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                    'context'     => [ 'view', 'edit' ],
                ],
                'structuredOutputSchema' => [
                    'description'          => __( 'Structured output schema for the action template.', 'sentient-forms' ),
                    'type'                 => [ 'object', 'null' ],
                    'context'              => [ 'view', 'edit' ],
                    'additionalProperties' => true,
                ],
            ],
        ];

        return $this->schema;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function get_local_template_definitions(): array
    {
        if ( ! class_exists( 'Sentient_Forms_Action_Templates_Repository' ) )
        {
            return [];
        }

        global $wpdb;
        $repository = new Sentient_Forms_Action_Templates_Repository( $wpdb );
        $templates = $repository->list_by_source( 'bundled' );
        $codes     = Sentient_Forms_Bundled_Action_Templates::codes();

        if ( count( $templates ) !== count( $codes ) )
        {
            return [];
        }

        $templates_by_code = [];
        foreach ( $templates as $template )
        {
            $code = isset( $template['code'] ) && is_scalar( $template['code'] )
                ? sanitize_key( (string) $template['code'] )
                : '';
            $id = absint( $template['id'] ?? 0 );
            if (
                '' === $code
                || $id <= 0
                || 'bundled' !== sanitize_key( (string) ( $template['source'] ?? '' ) )
                || ! in_array( $code, $codes, true )
                || isset( $templates_by_code[ $code ] )
            )
            {
                return [];
            }

            $templates_by_code[ $code ] = $template;
        }

        $definitions = [];
        foreach ( $codes as $code )
        {
            $template = $templates_by_code[ $code ] ?? null;
            $catalog  = Sentient_Forms_Bundled_Action_Templates::get( $code );
            if ( ! is_array( $template ) || ! is_array( $catalog ) )
            {
                return [];
            }

            $definitions[] = [
                'id'                     => $code,
                'templateId'             => (string) absint( $template['id'] ),
                'label'                  => sanitize_text_field( (string) ( $catalog['display_name'] ?? $code ) ),
                'description'            => isset( $catalog['description'] ) && is_scalar( $catalog['description'] ) ? sanitize_textarea_field( (string) $catalog['description'] ) : '',
                'settingsFields'         => [],
                'icon'                   => '',
                'hooks'                  => $this->resolve_catalog_hooks( $catalog ),
                'compatibility'          => [],
                'source'                 => 'bundled',
                'baseCreditCost'         => null,
                'modelHint'              => isset( $catalog['default_model'] ) && is_scalar( $catalog['default_model'] ) ? sanitize_text_field( (string) $catalog['default_model'] ) : null,
                'overrideSchema'         => is_array( $catalog['override_schema'] ?? null ) ? $catalog['override_schema'] : [],
                'promptTemplate'         => isset( $catalog['prompt_template'] ) && is_scalar( $catalog['prompt_template'] ) ? (string) $catalog['prompt_template'] : null,
                'structuredOutputSchema' => is_array( $catalog['structured_output_schema'] ?? null ) ? $catalog['structured_output_schema'] : null,
            ];
        }

        return $definitions;
    }

    /**
     * Resolve supported hooks for a template summary.
     *
     * Imported legacy bundles may omit hook metadata. Keep inference here so
     * older template payloads do not degrade into ambiguous defaults.
     *
     * @param array<string, mixed> $template Template summary payload.
     *
     * @return array<int, string>
     */
    private function resolve_catalog_hooks( array $template ): array
    {
        $hooks = [];
        if ( isset( $template['hooks'] ) && is_array( $template['hooks'] ) )
        {
            foreach ( $template['hooks'] as $hook )
            {
                if ( is_scalar( $hook ) )
                {
                    $hook_key = sanitize_key( (string) $hook );
                    if ( '' !== $hook_key )
                    {
                        $hooks[] = $hook_key;
                    }
                    continue;
                }

                if ( is_array( $hook ) && isset( $hook['id'] ) && is_scalar( $hook['id'] ) )
                {
                    $hook_key = sanitize_key( (string) $hook['id'] );
                    if ( '' !== $hook_key )
                    {
                        $hooks[] = $hook_key;
                    }
                }
            }
        }

        $hooks = array_map(
            static fn( string $hook ): string => match ( $hook ) {
                'validation'       => 'gform_validation',
                'after_submission' => 'gform_after_submission',
                default            => $hook,
            },
            $hooks
        );

        return array_values( array_unique( $hooks ) );
    }
}
