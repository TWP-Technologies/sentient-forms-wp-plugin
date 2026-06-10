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
        $cps_definitions = $this->maybe_fetch_cps_templates();
        if ( ! is_wp_error( $cps_definitions ) && ! empty( $cps_definitions ) )
        {
            return $this->prepare_item_for_response( $cps_definitions );
        }

        $definitions = $this->get_local_template_definitions();
        if ( ! empty( $definitions ) )
        {
            return $this->prepare_item_for_response( $definitions );
        }

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
                'source'         => 'bundled',
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
        $definitions = [];

        $templates = $repository->list_by_source( 'bundled' );
        if ( [] === $templates )
        {
            $templates = $repository->list_active();
        }

        foreach ( $templates as $template )
        {
            $code = isset( $template['code'] ) && is_scalar( $template['code'] )
                ? sanitize_key( (string) $template['code'] )
                : '';
            if ( '' === $code )
            {
                continue;
            }

            $definitions[] = [
                'id'                     => $code,
                'templateId'             => isset( $template['id'] ) ? (string) (int) $template['id'] : null,
                'label'                  => isset( $template['display_name'] ) ? sanitize_text_field( (string) $template['display_name'] ) : $code,
                'description'            => isset( $template['description'] ) && is_scalar( $template['description'] ) ? sanitize_textarea_field( (string) $template['description'] ) : '',
                'settingsFields'         => [],
                'icon'                   => '',
                'hooks'                  => $this->resolve_template_hooks( $template ),
                'compatibility'          => [],
                'source'                 => $this->normalize_local_template_source( $template['source'] ?? null ),
                'baseCreditCost'         => null,
                'modelHint'              => isset( $template['default_model'] ) && is_scalar( $template['default_model'] ) ? sanitize_text_field( (string) $template['default_model'] ) : null,
                'overrideSchema'         => is_array( $template['override_schema'] ?? null ) ? $template['override_schema'] : [],
                'promptTemplate'         => isset( $template['prompt_template'] ) && is_scalar( $template['prompt_template'] ) ? (string) $template['prompt_template'] : null,
                'structuredOutputSchema' => is_array( $template['structured_output_schema'] ?? null ) ? $template['structured_output_schema'] : null,
            ];
        }

        return $definitions;
    }

    /**
     * Attempt to fetch action templates from CPS if a proxy key is available.
     *
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private function maybe_fetch_cps_templates(): array | WP_Error
    {
        if ( ! apply_filters( 'sentient_forms_enable_legacy_cps_action_templates', false ) )
        {
            return [];
        }

        $plugin    = Sentient_Forms_Plugin::instance();
        $proxy_key = $plugin->get_proxy_api_key();
        if ( empty( $proxy_key ) )
        {
            return [];
        }

        $client = $plugin->get_cps_api_client();
        if ( ! $client )
        {
            return [];
        }

        $response = $client->get(
            '/actions/templates',
            [ 'bearer_token' => $proxy_key ]
        );

        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        $templates = $response['templates'] ?? ( $response['data']['templates'] ?? [] );
        if ( ! is_array( $templates ) )
        {
            return [];
        }

        $mapped = [];
        foreach ( $templates as $template )
        {
            $mapped[] = [
                'id'             => $template['code'] ?? '',
                'templateId'     => $template['id'] ?? null,
                'label'          => $template['display_name'] ?? ( $template['code'] ?? '' ),
                'description'    => $template['description'] ?? '',
                'settingsFields' => [],
                'icon'           => '',
                'hooks'          => $this->resolve_template_hooks( $template ),
                'compatibility'  => [],
                'source'         => 'cps',
                'baseCreditCost' => $template['base_credit_cost'] ?? null,
                'modelHint'      => $template['model_hint'] ?? null,
                'overrideSchema' => $template['override_schema'] ?? [],
            ];
        }

        return $mapped;
    }

    /**
     * Normalize template sources for the REST contract.
     *
     * Imported templates need to stay distinguishable so the UI can avoid
     * presenting them as built-in actions. All other site-owned rows collapse
     * to bundled because the UI no longer exposes source-of-authority chips.
     *
     * @param mixed $source Stored template source identifier.
     *
     * @return string
     */
    private function normalize_local_template_source( mixed $source ): string
    {
        $source_key = is_scalar( $source ) ? sanitize_key( (string) $source ) : '';

        if ( 'imported' === $source_key )
        {
            return 'imported';
        }

        return 'bundled';
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
    private function resolve_template_hooks( array $template ): array
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

        $hooks = array_values( array_unique( $hooks ) );
        if ( ! empty( $hooks ) )
        {
            return $hooks;
        }

        $code = isset( $template['code'] ) && is_scalar( $template['code'] )
            ? sanitize_key( (string) $template['code'] )
            : '';

        return match ( $code ) {
            'spam_detection_v1'    => [ 'gform_validation', 'gform_after_submission' ],
            'content_validation_v1'=> [ 'gform_validation' ],
            'entry_summary_v1'     => [ 'gform_after_submission' ],
            'clarification_assistant_v1' => [ 'real_time' ],
            default                => [],
        };
    }
}
