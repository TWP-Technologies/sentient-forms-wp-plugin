<?php
/**
 * REST API Form Actions Controller class for the Sentient Forms plugin.
 * Handles routes related to managing action linkages for a specific form source and form ID.
 *
 * @package    SentientForms
 * @subpackage REST_API\\Controllers\\Forms
 * @since      0.1.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Form_Actions_Controller
 * Manages REST API endpoints for local action linkages of a form.
 */
class Sentient_Forms_Form_Actions_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;
    /**
     * Route base including form source and form ID placeholders.
     *
     * @var string
     */
    protected string $rest_base = '(?P<form_source_slug>[a-z0-9_]+)/forms/(?P<form_id>[A-Za-z0-9._:%-]+)/actions';

    /** @var Sentient_Forms_Admin_Permission */
    private Sentient_Forms_Admin_Permission $permission_checker;

    /** @var Sentient_Forms_Mappings_Sync|null Phase 7 CSM: CPS sync service */
    private ?Sentient_Forms_Mappings_Sync $mappings_sync = null;

    /** @var array<int, array<string, mixed>>|null Full CPS mapping list fetched once per controller request. */
    private ?array $cps_mappings_cache = null;

    private ?Sentient_Forms_Form_Mappings_Repository $local_form_mappings = null;

    private ?Sentient_Forms_Local_Custom_Actions_Repository $local_custom_actions = null;

    private ?Sentient_Forms_Action_Templates_Repository $local_action_templates = null;

    private ?Sentient_Forms_Provider_Credentials_Repository $local_provider_credentials = null;

    private ?Sentient_Forms_Provider_Path_Policy_Service $provider_path_policy = null;

    private ?Sentient_Forms_Execution_Events_Repository $local_execution_events = null;

    private ?Sentient_Forms_Submission_Ledger_Settings_Repository $submission_ledger_settings = null;

    private ?Sentient_Forms_Submission_Ledger_Repository $submission_ledger = null;

    const FORM_ACTIONS_OPTION_BASE = 'sentient_forms_actions_';

    private const ACTION_LOG_OPTION_KEY = 'sentient_forms_action_log';

    /** Allowed values for action_type_indicator. */
    private const ACTION_TYPE_INDICATORS = [ 'master', 'custom', 'local_first' ];

    /** Allowed canonical lifecycle IDs that can trigger Sentient Forms actions. */
    private const ALLOWED_TRIGGER_HOOKS = [
        'validation',
        'after_submission',
        'real_time',
    ];

    /** The only bundled action currently allowed to use the visitor-facing realtime hook. */
    private const REALTIME_ACTION_ID = 'clarification_assistant_v1';
    private const REALTIME_HIDDEN_FIELD_EXPOSURE_MODES = [
        'omit_hidden',
        'label_hidden',
        'label_hidden_value',
        'label_value',
    ];
    private const REALTIME_PAGE_CHECKPOINT_MODES = [
        'all_pages',
        'include_pages',
        'exclude_pages',
    ];

    /** Policy version exposed to admin workflow planner clients. */
    private const WORKFLOW_POLICY_VERSION = '2026-02-mixed-sync-async-v1';

    /** Maximum allowed nested depth for mapping condition groups. */
    private const MAX_CONDITION_DEPTH = 3;

    /** Maximum allowed node count for a mapping condition tree. */
    private const MAX_CONDITION_NODES = 50;

    /** Supported operators for conditional run rules (CB-FORMS-006). */
    private const CONDITION_OPERATORS = [
        'eq',
        'neq',
        'contains',
        'not_contains',
        'starts_with',
        'ends_with',
        'in',
        'not_in',
        'is_empty',
        'is_not_empty',
        'gt',
        'gte',
        'lt',
        'lte',
    ];

    /** Maximum number of draft mappings accepted by the request tracer. */
    private const MAX_TRACE_DRAFT_MAPPINGS = 200;

    /** Maximum number of entry values accepted by the request tracer. */
    private const MAX_TRACE_ENTRY_VALUES = 200;

    /** Maximum string length per trace entry value. */
    private const MAX_TRACE_VALUE_LENGTH = 4096;

    /** Provider-native form identifiers accepted by submission ledger routes. */
    private const SUBMISSION_LEDGER_FORM_ID_PATTERN = '[A-Za-z0-9._:%-]+';

    /** Maximum local execution-event rows grouped into one ledger submission response. */
    private const SUBMISSION_LEDGER_ACTION_RUN_LIMIT = 100;

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

        if ( !class_exists( 'Sentient_Forms_Form_Sources' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Required dependency Sentient_Forms_Form_Sources not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        $this->permission_checker = new Sentient_Forms_Admin_Permission();

        if (
            class_exists( 'Sentient_Forms_Mappings_Sync' ) &&
            apply_filters( 'sentient_forms_enable_legacy_cps_mapping_sync', false )
        ) {
            $this->mappings_sync = new Sentient_Forms_Mappings_Sync();
        }

        global $wpdb;
        if ( class_exists( 'Sentient_Forms_Form_Mappings_Repository' ) )
        {
            $this->local_form_mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        }

        if ( class_exists( 'Sentient_Forms_Local_Custom_Actions_Repository' ) )
        {
            $this->local_custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        }

        if ( class_exists( 'Sentient_Forms_Action_Templates_Repository' ) )
        {
            $this->local_action_templates = new Sentient_Forms_Action_Templates_Repository( $wpdb );
        }

        if ( class_exists( 'Sentient_Forms_Provider_Credentials_Repository' ) )
        {
            $this->local_provider_credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        }

        if ( class_exists( 'Sentient_Forms_Provider_Path_Policy_Service' ) )
        {
            $this->provider_path_policy = new Sentient_Forms_Provider_Path_Policy_Service(
                $this->local_provider_credentials
            );
        }

        if ( class_exists( 'Sentient_Forms_Execution_Events_Repository' ) )
        {
            $this->local_execution_events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        }

        if ( class_exists( 'Sentient_Forms_Submission_Ledger_Settings_Repository' ) )
        {
            $this->submission_ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        }

        if ( class_exists( 'Sentient_Forms_Submission_Ledger_Repository' ) )
        {
            $this->submission_ledger = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        }
    }

    /**
     * Build option key for a specific form.
     */
    private function get_actions_option_key( string $form_source_slug, mixed $form_id ): string
    {
        return self::FORM_ACTIONS_OPTION_BASE . sanitize_key( $form_source_slug ) . '_' . $this->normalize_form_id_option_suffix( $form_id );
    }

    /**
     * @return string[]
     */
    private function get_legacy_actions_option_keys( string $form_source_slug, mixed $form_id ): array
    {
        $source = sanitize_key( $form_source_slug );

        return array_map(
            static fn ( string $suffix ): string => self::FORM_ACTIONS_OPTION_BASE . $source . '_' . $suffix,
            Sentient_Forms_Provider_Form_Id_Keys::legacy_option_suffixes( $source, $form_id )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function get_actions_option( string $form_source_slug, mixed $form_id ): array
    {
        $actions = get_option( $this->get_actions_option_key( $form_source_slug, $form_id ), null );
        if ( null === $actions )
        {
            foreach ( $this->get_legacy_actions_option_keys( $form_source_slug, $form_id ) as $legacy_option_key )
            {
                $actions = get_option( $legacy_option_key, null );
                if ( null !== $actions )
                {
                    break;
                }
            }
        }

        return is_array( $actions ) ? $actions : [];
    }

    public function sanitize_form_id_param( mixed $value ): string
    {
        return $this->normalize_provider_form_id( $value );
    }

    private function get_request_form_id( WP_REST_Request $request ): string
    {
        return $this->normalize_provider_form_id( $request->get_param( 'form_id' ) );
    }

    private function normalize_provider_form_id( mixed $value ): string
    {
        return Sentient_Forms_Provider_Form_Id_Keys::normalize( $value );
    }

    private function normalize_form_id_option_suffix( mixed $form_id ): string
    {
        return Sentient_Forms_Provider_Form_Id_Keys::option_suffix( $form_id );
    }

    private function is_positive_integer_form_id( string $form_id ): bool
    {
        return ctype_digit( $form_id ) && absint( $form_id ) > 0;
    }

    private function response_form_id( string $form_source_slug, string $form_id ): int | string
    {
        if ( Sentient_Forms_Form_Sources::GRAVITY_FORMS === sanitize_key( $form_source_slug ) && $this->is_positive_integer_form_id( $form_id ) )
        {
            return absint( $form_id );
        }

        return $form_id;
    }

    /**
     * Resolve an option-backed mapping from either supported storage shape.
     *
     * The admin app still encounters both the original top-level action shape and
     * the newer wrapped `actions` shape. Treat them as aliases so writes do not
     * silently update only the copy the current endpoint happened to read first.
     *
     * @param array<string,mixed> $actions Raw per-form option payload.
     */
    private function get_option_backed_action_linkage( array $actions, string $id ): ?array
    {
        if ( isset( $actions[ $id ] ) && is_array( $actions[ $id ] ) )
        {
            return $actions[ $id ];
        }

        if (
            isset( $actions['actions'] ) &&
            is_array( $actions['actions'] ) &&
            isset( $actions['actions'][ $id ] ) &&
            is_array( $actions['actions'][ $id ] )
        )
        {
            return $actions['actions'][ $id ];
        }

        return null;
    }

    /**
     * Persist an option-backed mapping to every supported option shape present.
     *
     * @param array<string,mixed> $actions Raw per-form option payload.
     * @param array<string,mixed> $linkage Normalized mapping payload.
     *
     * @return array<string,mixed>
     */
    private function upsert_option_backed_action_linkage( array $actions, string $id, array $linkage ): array
    {
        $actions[ $id ] = $linkage;

        if ( isset( $actions['actions'] ) && is_array( $actions['actions'] ) )
        {
            $actions['actions'][ $id ] = $linkage;
        }

        return $actions;
    }

    private function build_local_first_mapping_id( int $id ): string
    {
        return 'local_first_' . absint( $id );
    }

    private function parse_local_first_mapping_id( mixed $mapping_id ): int
    {
        if ( ! is_scalar( $mapping_id ) )
        {
            return 0;
        }

        $mapping_id = sanitize_text_field( (string) $mapping_id );
        if ( ! str_starts_with( $mapping_id, 'local_first_' ) )
        {
            return 0;
        }

        return absint( substr( $mapping_id, strlen( 'local_first_' ) ) );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function list_local_first_actions_for_form( string $form_source_slug, string $form_id ): array
    {
        if ( ! $this->local_form_mappings )
        {
            return [];
        }

        return $this->local_first_action_linkages_from_rows(
            $this->local_form_mappings->list_for_form( $form_source_slug, (string) $form_id )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows Local form mapping rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function local_first_action_linkages_from_rows( array $rows ): array
    {
        $actions = [];
        foreach ( $rows as $row )
        {
            $linkage = $this->transform_local_first_mapping_to_linkage( $row );
            if ( null !== $linkage )
            {
                $actions[] = $linkage;
            }
        }

        return $actions;
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     *
     * @return array<int, array<string, mixed>>
     */
    private function merge_local_first_actions( array $actions, string $form_source_slug, string $form_id, ?array $local_mapping_rows = null ): array
    {
        $local_first_actions = null === $local_mapping_rows
            ? $this->list_local_first_actions_for_form( $form_source_slug, $form_id )
            : $this->local_first_action_linkages_from_rows( $local_mapping_rows );

        foreach ( $local_first_actions as $local_first_action )
        {
            $mapping_id = isset( $local_first_action['local_mapping_id'] ) && is_scalar( $local_first_action['local_mapping_id'] )
                ? sanitize_text_field( (string) $local_first_action['local_mapping_id'] )
                : '';

            if ( '' === $mapping_id )
            {
                continue;
            }

            $actions[ $mapping_id ] = $local_first_action;
        }

        return array_values( $actions );
    }

    /**
     * Convert a custom-table mapping into the admin form-action shape.
     *
     * @param array<string, mixed> $row Local form mapping row.
     *
     * @return array<string, mixed>|null
     */
    private function transform_local_first_mapping_to_linkage( array $row ): ?array
    {
        $id = absint( $row['id'] ?? 0 );
        if ( $id <= 0 )
        {
            return null;
        }

        $hook = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $row['hook'] ?? '' );
        if ( null === $hook )
        {
            return null;
        }

        if ( 'custom_action' !== sanitize_key( (string) ( $row['action_kind'] ?? '' ) ) )
        {
            return null;
        }

        $custom_action = $this->local_custom_actions
            ? $this->local_custom_actions->get( absint( $row['action_id'] ?? 0 ) )
            : null;
        $identity      = $this->resolve_local_first_action_identity( $custom_action );

        $row_execution_mode = sanitize_key( (string) ( $row['execution_mode'] ?? '' ) );
        $execution_mode     = match ( true ) {
            'real_time' === $hook || 'real_time' === $row_execution_mode => 'real_time',
            'sync' === $row_execution_mode                              => 'validation',
            default                                                      => 'after_submission',
        };

        $form_source = isset( $row['form_source'] ) && is_scalar( $row['form_source'] )
            ? sanitize_key( (string) $row['form_source'] )
            : '';
        $effect_mapping = is_array( $row['effect_mapping_json'] ?? null )
            ? $this->filter_effect_mapping_for_form_source_capabilities( $form_source, $row['effect_mapping_json'] )
            : null;
        $settings       = is_array( $row['settings_json'] ?? null ) ? $row['settings_json'] : [];
        $settings       = array_replace_recursive(
            $settings,
            [
                'local_form_mapping_id' => $id,
                'execution_mode'        => $execution_mode,
                'input_mapping'         => is_array( $row['input_bindings_json'] ?? null )
                    ? $row['input_bindings_json']
                    : [],
                'effect_mapping_json'   => $effect_mapping,
                'linked_action_status'  => $identity['linked_action_status'],
                'repair_state'          => $identity['repair_state'],
                'trigger_sources'       => [
                    $hook => [ 'type' => 'hook_root' ],
                ],
            ]
        );

        if ( is_array( $effect_mapping ) )
        {
            $settings = $this->hydrate_spam_settings_from_effect_mapping( $settings, $effect_mapping );
        }

        if ( isset( $row['conditions_json'] ) && is_array( $row['conditions_json'] ) )
        {
            $settings['conditions'] = $row['conditions_json'];
        }

        $stored_trigger_sources = isset( $row['settings_json']['trigger_sources'] ) && is_array( $row['settings_json']['trigger_sources'] )
            ? $this->sanitize_trigger_sources( $row['settings_json']['trigger_sources'] )
            : [];
        if ( [] !== $stored_trigger_sources )
        {
            $settings['trigger_sources'] = $stored_trigger_sources;
        }

        return [
            'local_mapping_id'           => $this->build_local_first_mapping_id( $id ),
            'local_form_mapping_id'      => $id,
            'central_action_id'          => $identity['action_code'],
            'action_type_indicator'      => 'local_first',
            'action_kind'                => 'custom_action',
            'action_name_label'          => $identity['action_label'],
            'is_action_enabled_for_form' => ! empty( $row['enabled'] ),
            'trigger_hooks'              => [ $hook ],
            'execution_priority'         => $id,
            'execution_mode'             => $execution_mode,
            'settings'                   => $settings,
            'linked_action_status'       => $identity['linked_action_status'],
            'repair_state'               => $identity['repair_state'],
            'source'                     => 'local_first',
        ];
    }

    /**
     * Validate a candidate custom-table mapping against the form's dependency graph.
     *
     * @param string               $form_source_slug Form source slug.
     * @param string               $form_id          Form id.
     * @param array<string, mixed> $candidate_row    Candidate mapping row payload.
     * @param int|null             $existing_id      Existing local mapping id when updating.
     *
     * @return true|WP_Error
     */
    private function validate_local_first_mapping_dependencies_for_row(
        string $form_source_slug,
        string $form_id,
        array $candidate_row,
        ?int $existing_id = null
    ): true | WP_Error
    {
        return $this->validate_local_first_mapping_dependencies_for_rows(
            $form_source_slug,
            $form_id,
            [
                [
                    'payload'     => $candidate_row,
                    'existing_id' => $existing_id,
                ],
            ]
        );
    }

    /**
     * Validate pending custom-table mapping rows against the complete local graph.
     *
     * @param string $form_source_slug Form source slug.
     * @param string $form_id          Form id.
     * @param array<int, array{payload: array<string, mixed>, existing_id?: int|null}> $candidate_rows Pending rows.
     *
     * @return true|WP_Error
     */
    private function validate_local_first_mapping_dependencies_for_rows(
        string $form_source_slug,
        string $form_id,
        array $candidate_rows
    ): true | WP_Error
    {
        if ( ! $this->local_form_mappings )
        {
            return true;
        }

        $rows    = $this->local_form_mappings->list_for_form( $form_source_slug, $form_id );
        $actions = $this->option_backed_dependency_actions_for_form( $form_source_slug, $form_id );

        $excluded_ids = [];
        foreach ( $candidate_rows as $candidate )
        {
            $existing_id = absint( $candidate['existing_id'] ?? 0 );
            if ( $existing_id > 0 )
            {
                $excluded_ids[] = $existing_id;
            }
        }

        foreach ( $rows as $row )
        {
            $row_id = absint( $row['id'] ?? 0 );
            if ( $row_id > 0 && in_array( $row_id, $excluded_ids, true ) )
            {
                continue;
            }

            $linkage = $this->transform_local_first_mapping_to_linkage( $row );
            if ( null !== $linkage )
            {
                $actions[ (string) $linkage['local_mapping_id'] ] = $linkage;
            }
        }

        $next_id = $this->next_local_first_validation_mapping_id( $rows );
        foreach ( $candidate_rows as $candidate )
        {
            $candidate_row = isset( $candidate['payload'] ) && is_array( $candidate['payload'] )
                ? $candidate['payload']
                : [];
            $existing_id   = absint( $candidate['existing_id'] ?? 0 );

            $candidate_row['id'] = $existing_id > 0 ? $existing_id : $next_id++;

            $candidate_linkage = $this->transform_local_first_mapping_to_linkage( $candidate_row );
            if ( null === $candidate_linkage )
            {
                return $this->prepare_error_response(
                    'rest_local_first_mapping_invalid',
                    __( 'Local form mapping could not be validated.', 'sentient-forms' ),
                    400
                );
            }

            $actions[ (string) $candidate_linkage['local_mapping_id'] ] = $candidate_linkage;
        }

        return $this->dependency_validation_response( $this->validate_mapping_dependencies( $actions ) );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function option_backed_dependency_actions_for_form( string $form_source_slug, string $form_id ): array
    {
        $stored = $this->get_actions_option( $form_source_slug, $form_id );

        return $this->normalize_local_action_mappings(
            $this->extract_action_linkages_from_option( $stored )
        );
    }

    private function dependency_validation_response( true | WP_Error $result ): true | WP_Error
    {
        if ( ! is_wp_error( $result ) )
        {
            return true;
        }

        $data = $result->get_error_data();
        if ( is_array( $data ) && isset( $data['status'] ) )
        {
            return $result;
        }

        return $this->prepare_error_response(
            $result->get_error_code(),
            $result->get_error_message(),
            400,
            is_array( $data ) ? $data : []
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows Existing custom-table mapping rows.
     */
    private function next_local_first_validation_mapping_id( array $rows ): int
    {
        $max_id = 0;
        foreach ( $rows as $row )
        {
            $max_id = max( $max_id, absint( $row['id'] ?? 0 ) );
        }

        return $max_id + 1;
    }

    /**
     * @param array<string, mixed>|null $custom_action
     *
     * @return array{action_code: string, action_label: string, linked_action_status: string, repair_state: string}
     */
    private function resolve_local_first_action_identity( ?array $custom_action ): array
    {
        $linked_action_status = 'missing';
        $repair_state         = 'needs_repair';
        $action_code          = 'sentient_forms_local_custom_action';
        $action_label         = __( 'Local OpenRouter action', 'sentient-forms' );

        if ( is_array( $custom_action ) )
        {
            $linked_action_status = isset( $custom_action['status'] ) && is_scalar( $custom_action['status'] )
                ? sanitize_key( (string) $custom_action['status'] )
                : 'unknown';
            $repair_state         = 'active' === $linked_action_status ? 'ok' : 'needs_repair';
            $action_code          = isset( $custom_action['code'] ) && is_scalar( $custom_action['code'] )
                ? sanitize_key( (string) $custom_action['code'] )
                : $action_code;
            $action_label         = isset( $custom_action['display_name'] ) && is_scalar( $custom_action['display_name'] )
                ? sanitize_text_field( (string) $custom_action['display_name'] )
                : $action_label;
        }

        $template = $this->resolve_template_for_local_custom_action( $custom_action );
        if ( is_array( $template ) )
        {
            $template_code = isset( $template['code'] ) && is_scalar( $template['code'] )
                ? sanitize_key( (string) $template['code'] )
                : '';
            if ( '' !== $template_code && Sentient_Forms_Bundled_Action_Templates::has( $template_code ) )
            {
                $action_code = $template_code;
                $action_label = isset( $template['display_name'] ) && is_scalar( $template['display_name'] )
                    ? sanitize_text_field( (string) $template['display_name'] )
                    : $action_label;
            }
        }
        elseif ( is_array( $custom_action ) )
        {
            $custom_code   = isset( $custom_action['code'] ) && is_scalar( $custom_action['code'] )
                ? sanitize_key( (string) $custom_action['code'] )
                : '';
            $template_code = Sentient_Forms_Bundled_Action_Templates::extract_template_code_from_custom_action_code( $custom_code );
            $definition    = '' !== $template_code ? Sentient_Forms_Bundled_Action_Templates::get( $template_code ) : null;
            if ( is_array( $definition ) )
            {
                $action_code  = $template_code;
                $action_label = sanitize_text_field( (string) ( $definition['display_name'] ?? $action_label ) );
            }
        }

        return [
            'action_code'          => $action_code,
            'action_label'         => $action_label,
            'linked_action_status' => $linked_action_status,
            'repair_state'         => $repair_state,
        ];
    }

    /**
     * @param array<string, mixed>|null $custom_action
     *
     * @return array<string, mixed>|null
     */
    private function resolve_template_for_local_custom_action( ?array $custom_action ): ?array
    {
        if ( ! is_array( $custom_action ) || ! $this->local_action_templates )
        {
            return null;
        }

        $template_id = absint( $custom_action['template_id'] ?? 0 );
        if ( $template_id <= 0 )
        {
            return null;
        }

        return $this->local_action_templates->get( $template_id );
    }

    private function get_local_first_mapping_row( mixed $mapping_id, string $form_source_slug, string $form_id ): ?array
    {
        if ( ! $this->local_form_mappings )
        {
            return null;
        }

        $id = $this->parse_local_first_mapping_id( $mapping_id );
        if ( $id <= 0 )
        {
            return null;
        }

        $row = $this->local_form_mappings->get( $id );
        if ( ! $row )
        {
            return null;
        }

        if (
            sanitize_key( (string) ( $row['form_source'] ?? '' ) ) !== sanitize_key( $form_source_slug ) ||
            sanitize_text_field( (string) ( $row['form_id'] ?? '' ) ) !== sanitize_text_field( $form_id )
        )
        {
            return null;
        }

        return $row;
    }

    private function get_local_first_mapping_row_for_request( WP_REST_Request $request ): ?array
    {
        return $this->get_local_first_mapping_row(
            $request->get_param( 'local_mapping_id' ),
            sanitize_key( (string) $request->get_param( 'form_source_slug' ) ),
            $this->get_request_form_id( $request )
        );
    }

    /**
     * Register REST routes.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/(?P<form_source_slug>[a-z0-9_]+)/forms/overview',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_forms_overview' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source' ],
                    'args'                => $this->get_source_args(),
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_form_actions' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_collection_args(),
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'add_form_action' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/status',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_form_execution_status' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_collection_args(),
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/bootstrap',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_form_actions_bootstrap' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_collection_args(),
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/(?P<form_source_slug>[a-z0-9_]+)/forms/(?P<form_id>' . self::SUBMISSION_LEDGER_FORM_ID_PATTERN . ')/ledger-settings',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_submission_ledger_settings' ],
                    'permission_callback' => [ $this, 'permissions_check_for_submission_ledger_form' ],
                    'args'                => $this->get_submission_ledger_args(),
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_submission_ledger_settings' ],
                    'permission_callback' => [ $this, 'permissions_check_for_submission_ledger_form' ],
                    'args'                => array_merge(
                        $this->get_submission_ledger_args(),
                        [
                            'enabled' => [
                                'description'       => __( 'Whether Sentient Forms should store logical submitted field snapshots for this form.', 'sentient-forms' ),
                                'type'              => 'boolean',
                                'required'          => true,
                                'sanitize_callback' => 'rest_sanitize_boolean',
                            ],
                        ],
                    ),
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/(?P<form_source_slug>[a-z0-9_]+)/forms/(?P<form_id>' . self::SUBMISSION_LEDGER_FORM_ID_PATTERN . ')/submissions',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_submission_ledger_records' ],
                    'permission_callback' => [ $this, 'permissions_check_for_submission_ledger_form' ],
                    'args'                => array_merge(
                        $this->get_submission_ledger_args(),
                        [
                            'per_page' => [
                                'description'       => __( 'Maximum number of submission ledger records to return.', 'sentient-forms' ),
                                'type'              => 'integer',
                                'required'          => false,
                                'default'           => 50,
                                'sanitize_callback' => 'absint',
                            ],
                            'offset'   => [
                                'description'       => __( 'Number of submission ledger records to skip.', 'sentient-forms' ),
                                'type'              => 'integer',
                                'required'          => false,
                                'default'           => 0,
                                'sanitize_callback' => 'absint',
                            ],
                            'q'        => [
                                'description'       => __( 'Search submission UUID, native entry ID, logical fields, or provider metadata.', 'sentient-forms' ),
                                'type'              => 'string',
                                'required'          => false,
                                'sanitize_callback' => 'sanitize_text_field',
                            ],
                            'native_entry' => [
                                'description'       => __( 'Filter by provider-native entry ID.', 'sentient-forms' ),
                                'type'              => 'string',
                                'required'          => false,
                                'sanitize_callback' => 'sanitize_text_field',
                            ],
                            'captured_from' => [
                                'description'       => __( 'Return records captured on or after this timestamp.', 'sentient-forms' ),
                                'type'              => 'string',
                                'required'          => false,
                                'sanitize_callback' => 'sanitize_text_field',
                            ],
                            'captured_to' => [
                                'description'       => __( 'Return records captured on or before this timestamp.', 'sentient-forms' ),
                                'type'              => 'string',
                                'required'          => false,
                                'sanitize_callback' => 'sanitize_text_field',
                            ],
                            'has_files' => [
                                'description'       => __( 'Filter records by whether file references were captured.', 'sentient-forms' ),
                                'type'              => 'boolean',
                                'required'          => false,
                                'sanitize_callback' => 'rest_sanitize_boolean',
                            ],
                            'sort'     => [
                                'description'       => __( 'Submission ledger sort mode.', 'sentient-forms' ),
                                'type'              => 'string',
                                'required'          => false,
                                'default'           => 'captured_desc',
                                'sanitize_callback' => 'sanitize_key',
                            ],
                        ],
                    ),
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/(?P<form_source_slug>[a-z0-9_]+)/forms/(?P<form_id>' . self::SUBMISSION_LEDGER_FORM_ID_PATTERN . ')/submissions/(?P<submission_uuid>[a-fA-F0-9-]{36})',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_submission_ledger_record' ],
                    'permission_callback' => [ $this, 'permissions_check_for_submission_ledger_form' ],
                    'args'                => array_merge(
                        $this->get_submission_ledger_args(),
                        [
                            'submission_uuid' => [
                                'description'       => __( 'Submission ledger UUID.', 'sentient-forms' ),
                                'type'              => 'string',
                                'required'          => true,
                                'sanitize_callback' => 'sanitize_text_field',
                            ],
                        ],
                    ),
                ],
            ],
        );

        // CB-FORMS-001: Per-form master disable toggle
        // IMPORTANT: Must be registered BEFORE /{local_mapping_id} to avoid route conflict
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/disable',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_form_disabled' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_collection_args(),
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'toggle_form_disabled' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => array_merge(
                        $this->get_collection_args(),
                        [
                            'sf_disabled' => [
                                'description'       => __( 'Whether Sentient Forms is disabled for this form.', 'sentient-forms' ),
                                'type'              => 'boolean',
                                'required'          => true,
                                'sanitize_callback' => 'rest_sanitize_boolean',
                            ],
                        ],
                    ),
                ],
            ],
        );

        // CA-MAP-001: Form field discovery endpoint for FieldSelector component
        // IMPORTANT: Must be registered BEFORE /{local_mapping_id} to avoid route conflict
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/fields',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_form_fields' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_collection_args(),
                ],
            ],
        );

        // Workflow planning endpoint (graph execution preview + policy diagnostics).
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/workflow-plan',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_workflow_plan' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => array_merge(
                        $this->get_collection_args(),
                        [
                            'hook_scope' => [
                                'description'       => __( 'Hook scope for plan output (all or specific hook).', 'sentient-forms' ),
                                'type'              => 'string',
                                'required'          => false,
                                'default'           => 'all',
                                'sanitize_callback' => 'sanitize_text_field',
                            ],
                        ],
                    ),
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/request-trace',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'get_request_trace' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => array_merge(
                        $this->get_collection_args(),
                        [
                            'hook_scope'    => [
                                'description'       => __( 'Hook scope for the request trace (all or specific hook).', 'sentient-forms' ),
                                'type'              => 'string',
                                'required'          => false,
                                'default'           => 'all',
                                'sanitize_callback' => 'sanitize_text_field',
                            ],
                            'entry_values'  => [
                                'description'       => __( 'Manual scalar field values keyed by field id.', 'sentient-forms' ),
                                'type'              => 'object',
                                'required'          => false,
                            ],
                            'entry_id'      => [
                                'description'       => __( 'Optional Gravity Forms entry id used for trace input import.', 'sentient-forms' ),
                                'type'              => 'integer',
                                'required'          => false,
                                'validate_callback' => [ $this, 'validate_entry_id_param' ],
                            ],
                            'field_scope'   => [
                                'description'       => __( 'Entry import scope for trace input filtering.', 'sentient-forms' ),
                                'type'              => 'string',
                                'required'          => false,
                                'default'           => 'mapped_and_rule',
                                'sanitize_callback' => 'sanitize_text_field',
                            ],
                            'include_drafts' => [
                                'description'       => __( 'Whether unsaved draft mappings should be used during tracing.', 'sentient-forms' ),
                                'type'              => 'boolean',
                                'required'          => false,
                                'default'           => true,
                                'sanitize_callback' => 'rest_sanitize_boolean',
                            ],
                            'draft_mappings' => [
                                'description'       => __( 'Optional draft mapping payload used for non-persistent simulation.', 'sentient-forms' ),
                                'type'              => 'array',
                                'required'          => false,
                            ],
                        ],
                    ),
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<local_mapping_id>[a-zA-Z0-9_]+)/duplicate',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'duplicate_form_action_item' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => array_merge(
                        $this->get_item_args(),
                        [
                            'parent' => [
                                'description' => __( 'Parent insertion selection for duplicated mapping.', 'sentient-forms' ),
                                'type'        => 'object',
                                'required'    => true,
                            ],
                        ],
                    ),
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<local_mapping_id>[a-zA-Z0-9_]+)',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_form_action_item' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_item_args(),
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_form_action_item' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'delete_form_action_item' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_item_args(),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/entries/(?P<entry_id>\\d+)/status',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_entry_execution_status' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_entry_status_args(),
                ],
            ],
        );
    }

    /** Collection args */
    protected function get_collection_args(): array
    {
        return array_merge(
            $this->get_source_args(),
            [
            'form_id'          => [
                'validate_callback' => [ $this, 'validate_form_id_param' ],
                'sanitize_callback' => [ $this, 'sanitize_form_id_param' ],
                'required'          => true,
                'type'              => 'string',
                'description'       => __( 'The provider-native form identifier.', 'sentient-forms' ),
            ],
            ],
        );
    }

    /** Submission ledger routes accept provider-native opaque form identifiers. */
    protected function get_submission_ledger_args(): array
    {
        return array_merge(
            $this->get_source_args(),
            [
                'form_id' => [
                    'validate_callback' => [ $this, 'validate_submission_ledger_form_id_param' ],
                    'sanitize_callback' => [ $this, 'sanitize_form_id_param' ],
                    'required'          => true,
                    'type'              => 'string',
                    'description'       => __( 'The provider-native form identifier.', 'sentient-forms' ),
                ],
            ],
        );
    }

    /** Source-only args for source-level optimized overview endpoints. */
    protected function get_source_args(): array
    {
        return [
            'form_source_slug' => [
                'validate_callback' => [ $this, 'validate_form_source_slug_param' ],
                'sanitize_callback' => [ 'Sentient_Forms_Form_Sources', 'rest_sanitize_form_source_slug' ],
                'required'          => true,
                'type'              => 'string',
                'description'       => __( 'The slug identifying the form plugin source.', 'sentient-forms' ),
            ],
        ];
    }

    /** Item args */
    protected function get_item_args(): array
    {
        return array_merge(
            $this->get_collection_args(),
            [
                'local_mapping_id' => [
                    'validate_callback' => [ $this, 'validate_local_mapping_id_param' ],
                    'required'          => true,
                    'type'              => 'string',
                    'description'       => __( 'Local mapping ID for the action linkage.', 'sentient-forms' ),
                ],
            ],
        );
    }

    /**
     * Arguments for entry status endpoint.
     */
    protected function get_entry_status_args(): array
    {
        return array_merge(
            $this->get_collection_args(),
            [
                'entry_id' => [
                    'validate_callback' => [ $this, 'validate_entry_id_param' ],
                    'required'          => true,
                    'type'              => 'integer',
                    'description'       => __( 'Provider-native entry ID to inspect.', 'sentient-forms' ),
                ],
            ],
        );
    }

    /** Permission check for form source and ID. */
    public function permissions_check_for_form_source_and_id( WP_REST_Request $request ): WP_Error | bool
    {
        $permission = $this->permission_callback_with_nonce( $request );
        if ( true !== $permission )
        {
            return $permission;
        }

        $source = $request->get_param( 'form_source_slug' );
        if ( !Sentient_Forms_Form_Sources::is_supported_source( $source ) )
        {
            return $this->prepare_error_response( 'rest_invalid_form_source', __( 'Invalid form source provided.', 'sentient-forms' ), 400 );
        }

        $form_id    = $this->get_request_form_id( $request );
        $validation = $this->validate_form_id_param( $form_id, $request, 'form_id' );
        if ( is_wp_error( $validation ) )
        {
            return $validation;
        }

        $availability = $this->validate_elementor_forms_source_available( (string) $source );
        if ( is_wp_error( $availability ) )
        {
            return $availability;
        }

        $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $adapter  = $registry ? $registry->get_adapter_by_id( $source ) : null;
        if ( $registry && $adapter )
        {
            if ( method_exists( $adapter, 'form_exists' ) && !$adapter->form_exists( $form_id ) )
            {
                return $this->prepare_error_response(
                    'rest_form_not_found',
                    __( 'Form not found for the given source and ID.', 'sentient-forms' ),
                    404,
                );
            }
            elseif ( $this->is_positive_integer_form_id( $form_id ) && method_exists( $adapter, 'get_form_object' ) && null === $adapter->get_form_object( absint( $form_id ) ) )
            {
                return $this->prepare_error_response(
                    'rest_form_not_found',
                    __( 'Form not found for the given source and ID.', 'sentient-forms' ),
                    404,
                );
            }
        }

        return true;
    }

    /** Permission check for provider-neutral submission ledger routes. */
    public function permissions_check_for_submission_ledger_form( WP_REST_Request $request ): WP_Error | bool
    {
        $permission = $this->permission_callback_with_nonce( $request );
        if ( true !== $permission )
        {
            return $permission;
        }

        $source = Sentient_Forms_Form_Sources::rest_sanitize_form_source_slug(
            $request->get_param( 'form_source_slug' ),
            $request,
            'form_source_slug'
        );
        if ( ! Sentient_Forms_Form_Sources::is_supported_source( $source ) )
        {
            return $this->prepare_error_response( 'rest_invalid_form_source', __( 'Invalid form source provided.', 'sentient-forms' ), 400 );
        }

        $form_id    = $this->get_request_form_id( $request );
        $validation = $this->validate_submission_ledger_form_id_param( $form_id, $request, 'form_id' );
        if ( is_wp_error( $validation ) )
        {
            return $validation;
        }

        if ( Sentient_Forms_Form_Sources::GRAVITY_FORMS === $source )
        {
            if ( ! ctype_digit( $form_id ) || absint( $form_id ) <= 0 )
            {
                return $this->prepare_error_response( 'rest_invalid_form_id', __( 'Invalid form ID provided.', 'sentient-forms' ), 400 );
            }
        }

        $availability = $this->validate_elementor_forms_source_available( $source );
        if ( is_wp_error( $availability ) )
        {
            return $availability;
        }

        $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $adapter  = $registry ? $registry->get_adapter_by_id( $source ) : null;
        if ( $adapter && method_exists( $adapter, 'form_exists' ) )
        {
            $form_exists = $adapter->form_exists( $form_id );
            if ( is_wp_error( $form_exists ) )
            {
                return $form_exists;
            }

            if ( ! $form_exists )
            {
                return $this->prepare_error_response(
                    'rest_form_not_found',
                    __( 'Form not found for the given source and ID.', 'sentient-forms' ),
                    404,
                );
            }

            return true;
        }

        if ( $adapter && ctype_digit( $form_id ) && method_exists( $adapter, 'get_form_object' ) && null === $adapter->get_form_object( absint( $form_id ) ) )
        {
            return $this->prepare_error_response(
                'rest_form_not_found',
                __( 'Form not found for the given source and ID.', 'sentient-forms' ),
                404,
            );
        }

        return true;
    }

    /** Permission check for form source-only optimized endpoints. */
    public function permissions_check_for_form_source( WP_REST_Request $request ): WP_Error | bool
    {
        $permission = $this->permission_callback_with_nonce( $request );
        if ( true !== $permission )
        {
            return $permission;
        }

        $source = $request->get_param( 'form_source_slug' );
        if ( ! Sentient_Forms_Form_Sources::is_supported_source( $source ) )
        {
            return $this->prepare_error_response( 'rest_invalid_form_source', __( 'Invalid form source provided.', 'sentient-forms' ), 400 );
        }

        return true;
    }

    /** Validate form_source_slug syntax without disclosing supported adapters before auth. */
    public function validate_form_source_slug_param( string $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( '' === $value || ! preg_match( '/^[a-z0-9_]+$/', $value ) )
        {
            return new WP_Error(
                'rest_invalid_form_source',
                __( 'Invalid form source format.', 'sentient-forms' ),
                [ 'status' => 400, 'param' => $param ]
            );
        }

        return true;
    }

    /** Whether REST arg validation can safely run object-existence checks. */
    private function can_validate_form_action_objects( WP_REST_Request $request ): bool
    {
        return true === $this->permission_callback_with_nonce( $request );
    }

    /** Validate provider-native form IDs used by submission ledger routes. */
    public function validate_submission_ledger_form_id_param( mixed $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( ! is_scalar( $value ) )
        {
            return new WP_Error( 'rest_invalid_param', __( 'Form ID must be a string identifier.', 'sentient-forms' ), [ 'status' => 400, 'param' => $param ] );
        }

        $form_id = $this->normalize_provider_form_id( $value );
        if ( '' === $form_id || strlen( $form_id ) > 100 || ! preg_match( '/^' . self::SUBMISSION_LEDGER_FORM_ID_PATTERN . '$/', $form_id ) )
        {
            return new WP_Error( 'rest_invalid_param', __( 'Form ID must be a valid provider-native identifier.', 'sentient-forms' ), [ 'status' => 400, 'param' => $param ] );
        }

        return true;
    }

    /** Validate form_id param. */
    public function validate_form_id_param( mixed $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        $form_id = $this->normalize_provider_form_id( $value );
        if (
            '' === $form_id ||
            strlen( $form_id ) > 100 ||
            ! preg_match( '/^' . self::SUBMISSION_LEDGER_FORM_ID_PATTERN . '$/', $form_id )
        )
        {
            return new WP_Error( 'rest_invalid_param', __( 'Form ID must be a valid provider-native identifier.', 'sentient-forms' ), [ 'status' => 400, 'param' => $param ] );
        }

        $source = Sentient_Forms_Form_Sources::rest_sanitize_form_source_slug(
            $request->get_param( 'form_source_slug' ),
            $request,
            'form_source_slug'
        );
        if ( Sentient_Forms_Form_Sources::GRAVITY_FORMS === $source && ! $this->is_positive_integer_form_id( $form_id ) )
        {
            return new WP_Error( 'rest_invalid_param', __( 'Form ID must be a positive integer.', 'sentient-forms' ), [ 'status' => 400, 'param' => $param ] );
        }

        if ( null !== $this->elementor_forms_source_unavailable_error( $source ) )
        {
            return true;
        }

        if ( ! $this->can_validate_form_action_objects( $request ) )
        {
            return true;
        }

        if ( class_exists( 'Sentient_Forms_Plugin' ) )
        {
            $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
            if ( $registry )
            {
                $adapter = $registry->get_adapter_by_id( $request->get_param( 'form_source_slug' ) );
                if ( $adapter )
                {
                    if ( method_exists( $adapter, 'form_exists' ) && !$adapter->form_exists( $form_id ) )
                    {
                        return new WP_Error(
                            'rest_form_not_found', __( 'Form not found for the given source and ID.', 'sentient-forms' ), [ 'status' => 404 ],
                        );
                    }
                    elseif ( $this->is_positive_integer_form_id( $form_id ) && method_exists( $adapter, 'get_form_object' ) && null === $adapter->get_form_object( absint( $form_id ) ) )
                    {
                        return new WP_Error(
                            'rest_form_not_found', __( 'Form not found for the given source and ID.', 'sentient-forms' ), [ 'status' => 404 ],
                        );
                    }
                }
            }
        }

        return true;
    }

    private function validate_elementor_forms_source_available( string $form_source_slug ): true | WP_Error
    {
        $error = $this->elementor_forms_source_unavailable_error( $form_source_slug );
        return null === $error ? true : $error;
    }

    private function elementor_forms_source_unavailable_error( string $form_source_slug ): ?WP_Error
    {
        if ( Sentient_Forms_Form_Sources::ELEMENTOR_FORMS !== sanitize_key( $form_source_slug ) )
        {
            return null;
        }

        $descriptor = $this->get_form_source_descriptor( Sentient_Forms_Form_Sources::ELEMENTOR_FORMS );
        if ( ! is_array( $descriptor ) )
        {
            return null;
        }

        $availability = isset( $descriptor['availability'] ) && is_scalar( $descriptor['availability'] )
            ? sanitize_key( (string) $descriptor['availability'] )
            : '';
        $is_active    = array_key_exists( 'is_active', $descriptor ) ? (bool) $descriptor['is_active'] : true;
        if ( $is_active && 'available' === $availability )
        {
            return null;
        }

        $message = isset( $descriptor['availability_message'] ) && is_scalar( $descriptor['availability_message'] )
            ? sanitize_text_field( (string) $descriptor['availability_message'] )
            : '';
        if ( '' === $message )
        {
            $message = __( 'Elementor Forms support is unavailable until Elementor Pro Forms APIs are available.', 'sentient-forms' );
        }

        return $this->prepare_error_response(
            'rest_form_source_unavailable',
            $message,
            400
        );
    }

    /** Validate local_mapping_id path parameter. */
    public function validate_local_mapping_id_param( $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( empty( $value ) || !is_string( $value ) || !preg_match( '/^[a-zA-Z0-9_]+$/', $value ) )
        {
            return new WP_Error( 'rest_invalid_param', __( 'Invalid mapping ID.', 'sentient-forms' ), [ 'status' => 400 ] );
        }

        if ( ! $this->can_validate_form_action_objects( $request ) )
        {
            return true;
        }

        $actions = $this->get_actions_option( $request->get_param( 'form_source_slug' ), $this->get_request_form_id( $request ) );

        if (
            in_array( $request->get_method(), [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) &&
            null === $this->get_option_backed_action_linkage( $actions, $value )
        )
        {
            $local_first_row = $this->get_local_first_mapping_row(
                $value,
                sanitize_key( (string) $request->get_param( 'form_source_slug' ) ),
                $this->get_request_form_id( $request )
            );
            if ( ! $local_first_row )
            {
                return new WP_Error( 'rest_action_not_found', __( 'Action linkage not found.', 'sentient-forms' ), [ 'status' => 404 ] );
            }
        }

        return true;
    }

    /** Validate entry_id path parameter. */
    public function validate_entry_id_param( int $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( $value <= 0 )
        {
            return new WP_Error( 'rest_invalid_entry', __( 'Entry ID must be a positive integer.', 'sentient-forms' ), [ 'status' => 400 ] );
        }

        if ( ! $this->can_validate_form_action_objects( $request ) )
        {
            return true;
        }

        $form_source_slug = Sentient_Forms_Form_Sources::rest_sanitize_form_source_slug(
            $request->get_param( 'form_source_slug' ) ?: Sentient_Forms_Form_Sources::GRAVITY_FORMS,
            $request,
            'form_source_slug'
        );
        if ( Sentient_Forms_Form_Sources::GRAVITY_FORMS !== $form_source_slug )
        {
            return true;
        }

        if ( ! class_exists( 'GFAPI' ) )
        {
            return new WP_Error( 'rest_gf_missing', __( 'Gravity Forms is required for this endpoint.', 'sentient-forms' ), [ 'status' => 500 ] );
        }

        $entry = GFAPI::get_entry( $value );
        if ( is_wp_error( $entry ) )
        {
            return new WP_Error( 'rest_entry_not_found', __( 'Entry not found.', 'sentient-forms' ), [ 'status' => 404 ] );
        }

        $form_id = $this->get_request_form_id( $request );
        if ( $this->is_positive_integer_form_id( $form_id ) && isset( $entry['form_id'] ) && (int) $entry['form_id'] !== absint( $form_id ) )
        {
            return new WP_Error( 'rest_entry_form_mismatch', __( 'Entry does not belong to the requested form.', 'sentient-forms' ), [ 'status' => 400 ] );
        }

        return true;
    }

    /**
     * Retrieve all action linkages for a form.
     *
     * Phase 7 CSM: Merges local WP linkages with CPS mappings.
     * CPS mappings are transformed to match local linkage format.
     */
    public function get_form_actions( WP_REST_Request $request ): WP_REST_Response
    {
        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id = $this->get_request_form_id( $request );

        return $this->prepare_item_for_response(
            $this->build_form_actions_payload( $form_source_slug, $form_id )
        );
    }

    /**
     * Retrieve forms plus action/status summary data in a single REST request.
     */
    public function get_forms_overview( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source_slug = Sentient_Forms_Form_Sources::rest_sanitize_form_source_slug(
            $request->get_param( 'form_source_slug' ),
            $request,
            'form_source_slug'
        );

        $forms = $this->list_forms_for_source( $form_source_slug );
        if ( is_wp_error( $forms ) )
        {
            return $forms;
        }

        $valid_forms = [];
        $form_ids    = [];
        foreach ( $forms as $form )
        {
            if ( ! is_array( $form ) )
            {
                continue;
            }

            $form_id = $this->normalize_provider_form_id( $form['id'] ?? '' );
            if ( '' === $form_id )
            {
                continue;
            }

            $valid_forms[] = [
                'form'    => $form,
                'form_id' => $form_id,
            ];
            $form_ids[]    = $form_id;
        }

        $this->prime_form_action_option_caches( $form_source_slug, $form_ids );
        $local_mapping_rows_by_form = $this->list_local_mapping_rows_for_forms( $form_source_slug, $form_ids );
        $latest_events_by_form      = $this->list_latest_execution_events_for_forms( $form_source_slug, $form_ids );
        $action_log_entries_by_form = $this->list_action_log_entries_for_forms( $form_source_slug, $form_ids );
        $cps_actions_by_form        = $this->list_cps_actions_for_forms( $form_source_slug, $form_ids );

        $overview_forms = [];
        foreach ( $valid_forms as $valid_form )
        {
            $form    = $valid_form['form'];
            $form_id = $valid_form['form_id'];
            $form_key = (string) $form_id;
            $local_mapping_rows = $local_mapping_rows_by_form[ $form_key ] ?? [];
            $cps_actions        = $cps_actions_by_form[ $form_key ] ?? [];

            $actions         = $this->build_form_actions_payload(
                $form_source_slug,
                $form_id,
                null,
                $local_mapping_rows,
                $cps_actions
            );
            $enabled_actions = array_values(
                array_filter(
                    $actions,
                    static function ( array $action ): bool {
                        return ! empty( $action['is_action_enabled_for_form'] );
                    }
                )
            );

            $overview_forms[] = array_merge(
                $form,
                [
                    'actions'              => $actions,
                    'action_count'         => count( $actions ),
                    'enabled_action_count' => count( $enabled_actions ),
                    'execution_status'     => $this->build_form_execution_status(
                        $form_source_slug,
                        $form_id,
                        $local_mapping_rows,
                        $latest_events_by_form,
                        $action_log_entries_by_form
                    ),
                ]
            );
        }

        return $this->prepare_item_for_response(
            [
                'form_source'            => $form_source_slug,
                'form_source_descriptor' => $this->get_form_source_descriptor( $form_source_slug ),
                'forms'                  => $overview_forms,
                'generated_at'            => gmdate( 'c' ),
            ]
        );
    }

    /**
     * Retrieve per-form action bootstrap data in one request for the form editor.
     */
    public function get_form_actions_bootstrap( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source_slug = Sentient_Forms_Form_Sources::rest_sanitize_form_source_slug(
            $request->get_param( 'form_source_slug' ),
            $request,
            'form_source_slug'
        );
        $form_id = $this->get_request_form_id( $request );
        $actions = $this->build_form_actions_payload( $form_source_slug, $form_id );
        $definitions = $this->get_bootstrap_action_definitions();
        $custom_actions = $this->get_bootstrap_custom_actions();

        return $this->prepare_item_for_response(
            [
                'form_source'      => $form_source_slug,
                'form_id'          => $this->response_form_id( $form_source_slug, $form_id ),
                'form'             => $this->get_bootstrap_form_summary( $form_source_slug, $form_id ),
                'form_source_descriptor' => $this->get_form_source_descriptor( $form_source_slug ),
                'ledger_settings'  => $this->get_bootstrap_submission_ledger_settings( $form_source_slug, $form_id ),
                'actions'          => $actions,
                'execution_status' => $this->build_form_execution_status( $form_source_slug, $form_id ),
                'disabled_state'   => $this->build_form_disabled_state( $form_source_slug, $form_id ),
                'capabilities'     => $this->get_bootstrap_capabilities(),
                'definitions'      => $definitions,
                'custom_actions'   => $custom_actions,
                'provider_credentials' => $this->get_bootstrap_provider_credentials(),
                'form_action_configs'  => $this->get_bootstrap_form_action_configs( $form_source_slug, $form_id ),
                'form_fields'          => $this->get_bootstrap_form_fields( $form_source_slug, $form_id ),
                'action_defaults'      => $this->get_bootstrap_action_defaults( $definitions, $custom_actions ),
                'provider_path_policy' => $this->get_bootstrap_provider_path_policy(),
                'workflow_plan'        => $this->get_bootstrap_workflow_plan( $form_source_slug, $form_id, $actions ),
                'generated_at'     => gmdate( 'c' ),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function get_bootstrap_submission_ledger_settings( string $form_source_slug, string $form_id ): array
    {
        if ( ! $this->submission_ledger_settings )
        {
            return [
                'form_source'     => $form_source_slug,
                'form_id'         => (string) $form_id,
                'enabled'         => false,
                'settings_source' => 'unavailable',
                'record_count'    => 0,
            ];
        }

        return $this->format_submission_ledger_settings(
            $this->submission_ledger_settings->get_or_default( $form_source_slug, (string) $form_id )
        );
    }

    public function get_submission_ledger_settings( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        if ( ! $this->submission_ledger_settings )
        {
            return $this->prepare_error_response(
                'sentient_forms_submission_ledger_unavailable',
                __( 'Submission ledger settings are not available.', 'sentient-forms' ),
                500
            );
        }

        $form_source_slug = Sentient_Forms_Form_Sources::rest_sanitize_form_source_slug(
            $request->get_param( 'form_source_slug' ),
            $request,
            'form_source_slug'
        );
        $form_id = $this->get_request_form_id( $request );

        return $this->prepare_item_for_response(
            $this->format_submission_ledger_settings(
                $this->submission_ledger_settings->get_or_default( $form_source_slug, $form_id )
            )
        );
    }

    public function update_submission_ledger_settings( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        if ( ! $this->submission_ledger_settings )
        {
            return $this->prepare_error_response(
                'sentient_forms_submission_ledger_unavailable',
                __( 'Submission ledger settings are not available.', 'sentient-forms' ),
                500
            );
        }

        $form_source_slug = Sentient_Forms_Form_Sources::rest_sanitize_form_source_slug(
            $request->get_param( 'form_source_slug' ),
            $request,
            'form_source_slug'
        );
        $form_id = $this->get_request_form_id( $request );
        $updated = $this->submission_ledger_settings->set_enabled(
            $form_source_slug,
            $form_id,
            rest_sanitize_boolean( $request->get_param( 'enabled' ) ),
            get_current_user_id()
        );

        if ( is_wp_error( $updated ) )
        {
            return $updated;
        }

        return $this->prepare_item_for_response( $this->format_submission_ledger_settings( $updated ) );
    }

    public function get_submission_ledger_records( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        if ( ! $this->submission_ledger )
        {
            return $this->prepare_error_response(
                'sentient_forms_submission_ledger_unavailable',
                __( 'Submission ledger records are not available.', 'sentient-forms' ),
                500
            );
        }

        $form_source_slug = Sentient_Forms_Form_Sources::rest_sanitize_form_source_slug(
            $request->get_param( 'form_source_slug' ),
            $request,
            'form_source_slug'
        );
        $form_id     = $this->get_request_form_id( $request );
        $per_page    = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ?: 50 ) ) );
        $offset      = max( 0, absint( $request->get_param( 'offset' ) ?: 0 ) );
        $enabled     = $this->require_submission_ledger_enabled( $form_source_slug, $form_id );
        if ( is_wp_error( $enabled ) )
        {
            return $enabled;
        }

        $filters     = [
            'q'             => sanitize_text_field( (string) ( $request->get_param( 'q' ) ?? '' ) ),
            'native_entry'  => sanitize_text_field( (string) ( $request->get_param( 'native_entry' ) ?? '' ) ),
            'captured_from' => $this->normalize_submission_ledger_captured_bound( $request->get_param( 'captured_from' ) ),
            'captured_to'   => $this->normalize_submission_ledger_captured_bound( $request->get_param( 'captured_to' ) ),
            'has_files'     => null !== $request->get_param( 'has_files' )
                ? rest_sanitize_boolean( $request->get_param( 'has_files' ) )
                : null,
            'sort'          => sanitize_key( (string) ( $request->get_param( 'sort' ) ?: 'captured_desc' ) ),
        ];
        $submissions = array_map(
            [ $this, 'format_submission_ledger_record' ],
            $this->submission_ledger->list_for_form( $form_source_slug, $form_id, $per_page, $offset, $filters )
        );
        $total       = $this->submission_ledger->count_for_form( $form_source_slug, $form_id, $filters );

        return $this->prepare_item_for_response(
            [
                'form_source' => $form_source_slug,
                'form_id'     => $form_id,
                'submissions' => $submissions,
                'count'       => count( $submissions ),
                'total'       => $total,
                'per_page'    => $per_page,
                'offset'      => $offset,
            ]
        );
    }

    public function get_submission_ledger_record( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        if ( ! $this->submission_ledger )
        {
            return $this->prepare_error_response(
                'sentient_forms_submission_ledger_unavailable',
                __( 'Submission ledger records are not available.', 'sentient-forms' ),
                500
            );
        }

        $form_source_slug = Sentient_Forms_Form_Sources::rest_sanitize_form_source_slug(
            $request->get_param( 'form_source_slug' ),
            $request,
            'form_source_slug'
        );
        $form_id         = $this->get_request_form_id( $request );
        $submission_uuid = sanitize_text_field( (string) $request->get_param( 'submission_uuid' ) );
        $enabled         = $this->require_submission_ledger_enabled( $form_source_slug, $form_id );
        if ( is_wp_error( $enabled ) )
        {
            return $enabled;
        }

        $record          = $this->submission_ledger->get_by_submission_uuid( $submission_uuid );

        if (
            null === $record
            || sanitize_key( (string) ( $record['form_source'] ?? '' ) ) !== $form_source_slug
            || sanitize_text_field( (string) ( $record['form_id'] ?? '' ) ) !== $form_id
        )
        {
            return $this->prepare_error_response(
                'sentient_forms_submission_ledger_record_not_found',
                __( 'Submission ledger record not found for this form.', 'sentient-forms' ),
                404
            );
        }

        return $this->prepare_item_for_response( $this->format_submission_ledger_record( $record ) );
    }

    private function require_submission_ledger_enabled( string $form_source_slug, string $form_id ): WP_Error | bool
    {
        if ( ! $this->submission_ledger_settings )
        {
            return $this->prepare_error_response(
                'sentient_forms_submission_ledger_unavailable',
                __( 'Submission ledger settings are not available.', 'sentient-forms' ),
                500
            );
        }

        $settings = $this->submission_ledger_settings->get_or_default( $form_source_slug, $form_id );
        if ( empty( $settings['enabled'] ) )
        {
            return $this->prepare_error_response(
                'sentient_forms_submission_ledger_disabled',
                __( 'Enable the Sentient Forms Submission Ledger before reviewing stored submissions.', 'sentient-forms' ),
                403
            );
        }

        return true;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function format_submission_ledger_settings( array $settings ): array
    {
        $form_source = sanitize_key( (string) ( $settings['form_source'] ?? '' ) );
        $form_id     = sanitize_text_field( (string) ( $settings['form_id'] ?? '' ) );

        return [
            'form_source'              => $form_source,
            'form_id'                  => $form_id,
            'enabled'                  => ! empty( $settings['enabled'] ),
            'enabled_at'               => $settings['enabled_at'] ?? null,
            'enabled_by_user_id'       => $settings['enabled_by_user_id'] ?? null,
            'disabled_at'              => $settings['disabled_at'] ?? null,
            'disabled_by_user_id'      => $settings['disabled_by_user_id'] ?? null,
            'settings_source'          => 'sentient_submission_ledger_settings',
            'record_count'             => $this->submission_ledger
                ? $this->submission_ledger->count_for_form( $form_source, $form_id )
                : 0,
            'ledger_records_endpoint'  => sprintf(
                '/%s/%s/forms/%s/submissions',
                $this->namespace,
                $form_source,
                rawurlencode( $form_id )
            ),
        ];
    }

    private function normalize_submission_ledger_captured_bound( mixed $value ): string
    {
        if ( null === $value || ! is_scalar( $value ) )
        {
            return '';
        }

        $raw = sanitize_text_field( (string) $value );
        if ( '' === $raw )
        {
            return '';
        }

        $normalized = str_replace( 'T', ' ', $raw );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized ) )
        {
            return $normalized . ':00';
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $normalized ) )
        {
            return $normalized;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function format_submission_ledger_record( array $row ): array
    {
        $submission_uuid = sanitize_text_field( (string) ( $row['submission_uuid'] ?? '' ) );
        $form_source     = sanitize_key( (string) ( $row['form_source'] ?? '' ) );
        $form_id         = sanitize_text_field( (string) ( $row['form_id'] ?? '' ) );
        $native_entry    = Sentient_Forms_Form_Sources::native_entry_capability_for_form_source( $form_source );
        $native_entry_id = isset( $row['native_entry_id'] ) ? sanitize_text_field( (string) $row['native_entry_id'] ) : null;
        $native_entry_url = isset( $row['native_entry_url'] ) ? esc_url_raw( (string) $row['native_entry_url'] ) : null;
        $native_entry_id = '' === $native_entry_id ? null : $native_entry_id;
        $native_entry_url = '' === $native_entry_url ? null : $native_entry_url;

        if ( is_array( $native_entry ) )
        {
            if ( empty( $native_entry['id'] ) )
            {
                $native_entry_id = null;
            }

            if ( empty( $native_entry['link'] ) )
            {
                $native_entry_url = null;
            }
        }

        return [
            'id'                 => absint( $row['id'] ?? 0 ),
            'submission_uuid'    => $submission_uuid,
            'form_source'        => $form_source,
            'form_id'            => $form_id,
            'native_entry_id'    => $native_entry_id,
            'native_entry_url'   => $native_entry_url,
            'source_submitted_at' => $row['source_submitted_at'] ?? null,
            'captured_at'        => $row['captured_at'] ?? null,
            'logical_fields'     => is_array( $row['logical_fields_json'] ?? null ) ? $row['logical_fields_json'] : [],
            'provider_metadata'  => is_array( $row['provider_metadata_json'] ?? null ) ? $row['provider_metadata_json'] : null,
            'file_refs'          => is_array( $row['file_refs_json'] ?? null ) ? $row['file_refs_json'] : [],
            'redaction_summary'  => is_array( $row['redaction_summary_json'] ?? null ) ? $row['redaction_summary_json'] : [],
            'action_runs'        => $this->format_submission_ledger_action_runs( $submission_uuid, $form_source, $form_id ),
            'expires_at'         => $row['expires_at'] ?? null,
            'detail_endpoint'    => sprintf(
                '/%s/%s/forms/%s/submissions/%s',
                $this->namespace,
                $form_source,
                rawurlencode( $form_id ),
                rawurlencode( $submission_uuid )
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function format_submission_ledger_action_runs( string $submission_uuid, string $form_source, string $form_id ): array
    {
        if ( '' === $submission_uuid )
        {
            return [];
        }

        $runs = [];
        $seen_request_ids = [];

        if ( null !== $this->local_execution_events )
        {
            foreach ( $this->local_execution_events->list_for_submission_uuid( $submission_uuid, self::SUBMISSION_LEDGER_ACTION_RUN_LIMIT ) as $event )
            {
                if (
                    sanitize_key( (string) ( $event['form_source'] ?? '' ) ) !== $form_source
                    || sanitize_text_field( (string) ( $event['form_id'] ?? '' ) ) !== $form_id
                )
                {
                    continue;
                }

                $execution_request_id = sanitize_text_field( (string) ( $event['execution_request_id'] ?? '' ) );
                if ( '' !== $execution_request_id )
                {
                    $seen_request_ids[ $execution_request_id ] = true;
                }

                $status = sanitize_key( (string) ( $event['status'] ?? 'unknown' ) );
                $runs[] = [
                    'execution_request_id' => $execution_request_id,
                    'mapping_id'           => isset( $event['mapping_id'] ) ? absint( $event['mapping_id'] ) : null,
                    'status'               => $this->normalize_submission_ledger_action_run_status( $status ),
                    'provider'             => isset( $event['provider'] ) ? sanitize_key( (string) $event['provider'] ) : null,
                    'model'                => isset( $event['model'] ) ? sanitize_text_field( (string) $event['model'] ) : null,
                    'last_result'          => is_array( $event['result_json'] ?? null ) ? $event['result_json'] : null,
                    'last_error_code'      => isset( $event['error_code'] ) ? sanitize_key( (string) $event['error_code'] ) : null,
                    'last_error_message'   => isset( $event['error_message'] ) ? sanitize_textarea_field( (string) $event['error_message'] ) : null,
                    'created_at'           => $event['created_at'] ?? null,
                    'updated_at'           => $event['updated_at'] ?? null,
                ];
            }
        }

        return array_merge(
            $runs,
            $this->format_submission_ledger_action_log_runs( $submission_uuid, $form_source, $form_id, $seen_request_ids )
        );
    }

    /**
     * @param array<string, bool> $seen_request_ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function format_submission_ledger_action_log_runs( string $submission_uuid, string $form_source, string $form_id, array $seen_request_ids ): array
    {
        $entries = get_option( self::ACTION_LOG_OPTION_KEY, [] );
        if ( ! is_array( $entries ) )
        {
            return [];
        }

        $runs = [];
        foreach ( $entries as $entry )
        {
            if ( ! is_array( $entry ) )
            {
                continue;
            }

            if ( sanitize_key( (string) ( $entry['form_source'] ?? '' ) ) !== $form_source )
            {
                continue;
            }

            if ( ! $this->action_log_form_id_matches( $entry['form_id'] ?? null, $form_source, $form_id ) )
            {
                continue;
            }

            if ( $this->action_log_submission_uuid( $entry ) !== $submission_uuid )
            {
                continue;
            }

            $execution_request_id = $this->action_log_execution_request_id( $entry );
            if ( '' !== $execution_request_id && isset( $seen_request_ids[ $execution_request_id ] ) )
            {
                continue;
            }

            $runs[] = [
                'execution_request_id' => $execution_request_id,
                'mapping_id'           => $this->action_log_mapping_id( $entry ),
                'status'               => $this->normalize_submission_ledger_action_run_status(
                    sanitize_key( (string) ( $entry['status'] ?? 'unknown' ) )
                ),
                'provider'             => isset( $entry['provider'] ) && is_scalar( $entry['provider'] ) ? sanitize_key( (string) $entry['provider'] ) : null,
                'model'                => isset( $entry['model'] ) && is_scalar( $entry['model'] ) ? sanitize_text_field( (string) $entry['model'] ) : null,
                'last_result'          => $this->action_log_last_result( $entry ),
                'last_error_code'      => isset( $entry['error_code'] ) && is_scalar( $entry['error_code'] ) ? sanitize_key( (string) $entry['error_code'] ) : null,
                'last_error_message'   => isset( $entry['error_message'] ) && is_scalar( $entry['error_message'] ) ? sanitize_textarea_field( (string) $entry['error_message'] ) : null,
                'created_at'           => isset( $entry['created_at'] ) && is_scalar( $entry['created_at'] ) ? sanitize_text_field( (string) $entry['created_at'] ) : null,
                'updated_at'           => isset( $entry['updated_at'] ) && is_scalar( $entry['updated_at'] )
                    ? sanitize_text_field( (string) $entry['updated_at'] )
                    : ( isset( $entry['created_at'] ) && is_scalar( $entry['created_at'] ) ? sanitize_text_field( (string) $entry['created_at'] ) : null ),
            ];
        }

        return $runs;
    }

    private function normalize_submission_ledger_action_run_status( string $status ): string
    {
        return match ( $status ) {
            'succeeded', 'success' => 'success',
            'failed', 'error'      => 'error',
            default                => $status,
        };
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function action_log_submission_uuid( array $entry ): string
    {
        if ( ! isset( $entry['submission_uuid'] ) || ! is_scalar( $entry['submission_uuid'] ) )
        {
            return '';
        }

        return sanitize_text_field( (string) $entry['submission_uuid'] );
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function action_log_execution_request_id( array $entry ): string
    {
        if ( isset( $entry['execution_request_id'] ) && is_scalar( $entry['execution_request_id'] ) )
        {
            return sanitize_text_field( (string) $entry['execution_request_id'] );
        }

        $details = $entry['details'] ?? null;
        if ( is_array( $details ) && isset( $details['meta'] ) && is_array( $details['meta'] ) )
        {
            $execution_request_id = $details['meta']['execution_request_id'] ?? null;
            if ( is_scalar( $execution_request_id ) )
            {
                return sanitize_text_field( (string) $execution_request_id );
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function action_log_mapping_id( array $entry ): ?int
    {
        $mapping_id = $entry['mapping_id'] ?? null;
        if ( ! is_scalar( $mapping_id ) )
        {
            return null;
        }

        $mapping_id = sanitize_text_field( (string) $mapping_id );
        if ( ctype_digit( $mapping_id ) )
        {
            return absint( $mapping_id );
        }

        if ( str_starts_with( $mapping_id, 'local_first_' ) )
        {
            $local_mapping_id = substr( $mapping_id, strlen( 'local_first_' ) );
            return ctype_digit( $local_mapping_id ) ? absint( $local_mapping_id ) : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>|null
     */
    private function action_log_last_result( array $entry ): ?array
    {
        if ( is_array( $entry['details'] ?? null ) )
        {
            return $entry['details'];
        }

        if ( isset( $entry['result_summary'] ) && is_scalar( $entry['result_summary'] ) )
        {
            $summary = sanitize_text_field( (string) $entry['result_summary'] );
            return '' !== $summary ? [ 'summary' => $summary ] : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get_form_source_descriptor( string $form_source_slug ): ?array
    {
        $plugin = Sentient_Forms_Plugin::instance();
        if ( ! method_exists( $plugin, 'get_form_adapter_registry' ) )
        {
            return null;
        }

        $registry = $plugin->get_form_adapter_registry();
        if ( ! $registry || ! method_exists( $registry, 'get_capability_descriptor' ) )
        {
            return null;
        }

        return $registry->get_capability_descriptor( $form_source_slug );
    }

    /**
     * Create a lightweight internal request for composing bootstrap payloads.
     *
     * @param array<string, mixed> $params Request params.
     */
    private function create_bootstrap_request( array $params = [] ): WP_REST_Request
    {
        $request = new WP_REST_Request( WP_REST_Server::READABLE, '/' . $this->namespace . '/bootstrap-internal' );
        foreach ( $params as $key => $value )
        {
            $request->set_param( $key, $value );
        }

        return $request;
    }

    /**
     * Extract response data from an internal controller call.
     */
    private function bootstrap_response_data( mixed $response, mixed $fallback ): mixed
    {
        if ( is_wp_error( $response ) )
        {
            return $fallback;
        }

        if ( $response instanceof WP_REST_Response )
        {
            return $response->get_data();
        }

        return $fallback;
    }

    /**
     * @param array<int, int> $form_ids Form IDs whose action options are needed.
     */
    private function prime_form_action_option_caches( string $form_source_slug, array $form_ids ): void
    {
        if ( ! function_exists( 'wp_prime_option_caches' ) )
        {
            return;
        }

        $option_keys = [];
        foreach ( $form_ids as $form_id )
        {
            $form_id = $this->normalize_provider_form_id( $form_id );
            if ( '' !== $form_id )
            {
                $option_keys[] = $this->get_actions_option_key( $form_source_slug, $form_id );
                $option_keys   = array_merge( $option_keys, $this->get_legacy_actions_option_keys( $form_source_slug, $form_id ) );
            }
        }

        $option_keys = array_values( array_unique( $option_keys ) );
        if ( ! empty( $option_keys ) )
        {
            wp_prime_option_caches( $option_keys );
        }
    }

    /**
     * @param array<int, int> $form_ids Form IDs to load from local mapping storage.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function list_local_mapping_rows_for_forms( string $form_source_slug, array $form_ids ): array
    {
        if ( ! $this->local_form_mappings )
        {
            return [];
        }

        return $this->local_form_mappings->list_for_forms( $form_source_slug, $form_ids );
    }

    /**
     * @param array<int, int> $form_ids Form IDs to load from local execution storage.
     *
     * @return array<string, array<string, mixed>>
     */
    private function list_latest_execution_events_for_forms( string $form_source_slug, array $form_ids ): array
    {
        if ( ! $this->local_execution_events )
        {
            return [];
        }

        return $this->local_execution_events->get_latest_for_forms( $form_source_slug, $form_ids );
    }

    /**
     * @param array<int, int> $form_ids Form IDs to load from the legacy action log.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function list_action_log_entries_for_forms( string $form_source_slug, array $form_ids ): array
    {
        $entries = get_option( self::ACTION_LOG_OPTION_KEY, [] );
        if ( ! is_array( $entries ) )
        {
            return [];
        }

        $form_id_lookup = [];
        foreach ( $form_ids as $form_id )
        {
            $form_id = $this->normalize_provider_form_id( $form_id );
            if ( '' !== $form_id )
            {
                $form_id_lookup[ (string) $form_id ] = true;
            }
        }

        if ( empty( $form_id_lookup ) )
        {
            return [];
        }

        $grouped = [];
        foreach ( $entries as $entry )
        {
            if ( ! is_array( $entry ) || (string) ( $entry['form_source'] ?? '' ) !== $form_source_slug )
            {
                continue;
            }

            $form_key = $this->normalize_provider_form_id( $entry['form_id'] ?? '' );
            if ( ! isset( $form_id_lookup[ $form_key ] ) )
            {
                continue;
            }

            if ( ! isset( $grouped[ $form_key ] ) )
            {
                $grouped[ $form_key ] = [];
            }
            $grouped[ $form_key ][] = $entry;
        }

        return $grouped;
    }

    /**
     * @param array<int, array<string, mixed>> $actions Current merged action payload.
     *
     * @return array<string, mixed>
     */
    private function get_bootstrap_workflow_plan( string $form_source_slug, string $form_id, array $actions ): array
    {
        if ( ! $this->is_positive_integer_form_id( $form_id ) )
        {
            return $this->build_local_workflow_plan_payload( $actions, 'all', 'provider_native_form_id' );
        }

        $data = $this->bootstrap_response_data(
            $this->get_workflow_plan(
                $this->create_bootstrap_request(
                    [
                        'form_source_slug' => $form_source_slug,
                        'form_id'          => $form_id,
                        'hook_scope'       => 'all',
                    ]
                )
            ),
            []
        );

        return is_array( $data )
            ? $data
            : $this->build_local_workflow_plan_payload( $actions, 'all', 'form_bootstrap_fallback' );
    }

    private function get_bootstrap_form_summary( string $form_source_slug, string $form_id ): ?array
    {
        $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $adapter  = $registry ? $registry->get_adapter_by_id( $form_source_slug ) : null;
        if ( $adapter && $this->is_positive_integer_form_id( $form_id ) && method_exists( $adapter, 'get_form_object' ) )
        {
            $form = $adapter->get_form_object( absint( $form_id ) );
            if ( null !== $form )
            {
                $summary = $this->normalize_bootstrap_form_summary( $form_source_slug, $form_id, $form, $adapter );
                if ( null !== $summary )
                {
                    return $summary;
                }
            }
        }

        $forms = $this->list_forms_for_source( $form_source_slug );
        if ( is_wp_error( $forms ) )
        {
            return null;
        }

        foreach ( $forms as $form )
        {
            if ( ! is_array( $form ) )
            {
                continue;
            }

            if ( $this->normalize_provider_form_id( $form['id'] ?? '' ) === $form_id )
            {
                return $form;
            }
        }

        return null;
    }

    private function normalize_bootstrap_form_summary(
        string $form_source_slug,
        string $form_id,
        object | array $form,
        object $adapter
    ): ?array
    {
        $form_data   = is_array( $form ) ? $form : get_object_vars( $form );
        $resolved_id = $this->normalize_provider_form_id( $form_data['id'] ?? ( $form_data['ID'] ?? $form_id ) );
        if ( $resolved_id !== $form_id )
        {
            return null;
        }

        $title = $form_data['title'] ?? $form_data['name'] ?? ( $form_data['post_title'] ?? '' );
        $title = is_scalar( $title ) && '' !== (string) $title
            ? (string) $title
            : sprintf(
                /* translators: %s: Form ID. */
                __( 'Form %s', 'sentient-forms' ),
                $form_id
            );

        $summary = [
            'id'                 => $form_id,
            'title'              => $title,
            'adapter'            => method_exists( $adapter, 'get_id' ) ? $adapter->get_id() : $form_source_slug,
            'adapter_name'       => method_exists( $adapter, 'get_name' ) ? $adapter->get_name() : $form_source_slug,
            'provider_is_active' => ! isset( $form_data['is_active'] ) || ! empty( $form_data['is_active'] ),
        ];

        if ( 'gravity_forms' === $form_source_slug && $this->is_positive_integer_form_id( $form_id ) )
        {
            $summary['provider_edit_url'] = admin_url(
                sprintf(
                    'admin.php?page=gf_edit_forms&id=%d',
                    absint( $form_id )
                )
            );
        }
        elseif ( method_exists( $adapter, 'get_provider_edit_url' ) )
        {
            $provider_edit_url = $adapter->get_provider_edit_url( $form_id );
            if ( is_scalar( $provider_edit_url ) && '' !== (string) $provider_edit_url )
            {
                $summary['provider_edit_url'] = esc_url_raw( (string) $provider_edit_url );
            }
        }

        if ( method_exists( $adapter, 'get_form_settings' ) )
        {
            $summary['settings'] = $adapter->get_form_settings( $form_id );
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function get_bootstrap_capabilities(): array
    {
        if ( ! class_exists( 'Sentient_Forms_Meta_Controller' ) )
        {
            return [
                'supports_custom_actions' => true,
                'supports_status'         => true,
                'supports_credits'        => false,
                'cps_version'             => null,
            ];
        }

        $controller = new Sentient_Forms_Meta_Controller();
        $data = $this->bootstrap_response_data(
            $controller->get_capabilities( $this->create_bootstrap_request() ),
            []
        );

        return is_array( $data ) ? $data : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function get_bootstrap_action_definitions(): array
    {
        if ( ! class_exists( 'Sentient_Forms_Action_Definitions_Controller' ) )
        {
            return [];
        }

        $controller = new Sentient_Forms_Action_Definitions_Controller();
        $data = $this->bootstrap_response_data(
            $controller->get_action_definitions( $this->create_bootstrap_request() ),
            []
        );

        return is_array( $data ) ? array_values( array_filter( $data, 'is_array' ) ) : [];
    }

    /**
     * @return array{actions: array<int, array<string, mixed>>, quota: array<string, int>|null}
     */
    private function get_bootstrap_custom_actions(): array
    {
        if ( ! class_exists( 'Sentient_Forms_Custom_Actions_Controller' ) )
        {
            return [
                'actions' => [],
                'quota'   => null,
            ];
        }

        $controller = new Sentient_Forms_Custom_Actions_Controller();
        $data = $this->bootstrap_response_data(
            $controller->list_custom_actions(
                $this->create_bootstrap_request(
                    [
                        'status' => 'active',
                    ]
                )
            ),
            []
        );

        return [
            'actions' => isset( $data['actions'] ) && is_array( $data['actions'] ) ? array_values( $data['actions'] ) : [],
            'quota'   => isset( $data['quota'] ) && is_array( $data['quota'] ) ? $data['quota'] : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function get_bootstrap_provider_credentials(): array
    {
        if ( ! class_exists( 'Sentient_Forms_Local_Providers_Controller' ) )
        {
            return [];
        }

        $controller = new Sentient_Forms_Local_Providers_Controller();
        $data = $this->bootstrap_response_data(
            $controller->list_credentials( $this->create_bootstrap_request() ),
            []
        );

        return is_array( $data ) ? array_values( array_filter( $data, 'is_array' ) ) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function get_bootstrap_provider_path_policy(): array
    {
        if ( ! $this->provider_path_policy || ! class_exists( 'Sentient_Forms_Bundled_Action_Templates' ) )
        {
            return [
                'default_provider' => null,
                'providers'        => [
                    'sentient_managed' => [
                        'ready'               => false,
                        'credential_id'       => null,
                        'blocked_reason_code' => 'policy_unavailable',
                    ],
                    'openrouter' => [
                        'ready'               => false,
                        'credential_id'       => null,
                        'blocked_reason_code' => 'policy_unavailable',
                    ],
                ],
                'actions'          => [],
            ];
        }

        return $this->provider_path_policy->build_bootstrap_policy(
            Sentient_Forms_Bundled_Action_Templates::definitions()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function get_bootstrap_form_action_configs( string $form_source_slug, string $form_id ): array
    {
        if ( ! class_exists( 'Sentient_Forms_Form_Action_Config_Controller' ) )
        {
            return [];
        }

        $controller = new Sentient_Forms_Form_Action_Config_Controller();
        $data = $this->bootstrap_response_data(
            $controller->get_form_configs(
                $this->create_bootstrap_request(
                    [
                        'form_source' => $form_source_slug,
                        'form_id'     => $form_id,
                    ]
                )
            ),
            []
        );

        return isset( $data['configs'] ) && is_array( $data['configs'] ) ? $data['configs'] : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function get_bootstrap_form_fields( string $form_source_slug, string $form_id ): array
    {
        $data = $this->bootstrap_response_data(
            $this->get_form_fields(
                $this->create_bootstrap_request(
                    [
                        'form_source_slug' => $form_source_slug,
                        'form_id'          => $form_id,
                    ]
                )
            ),
            []
        );

        if ( isset( $data['success'], $data['data'] ) && true === $data['success'] && is_array( $data['data'] ) )
        {
            return array_values( array_filter( $data['data'], 'is_array' ) );
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @param array{actions?: array<int, array<string, mixed>>} $custom_actions
     * @return array<string, mixed>
     */
    private function get_bootstrap_action_defaults( array $definitions, array $custom_actions ): array
    {
        if ( ! class_exists( 'Sentient_Forms_Form_Action_Config_Controller' ) )
        {
            return [];
        }

        $action_ids = [];
        foreach ( $definitions as $definition )
        {
            $action_id = isset( $definition['id'] ) && is_scalar( $definition['id'] )
                ? sanitize_key( (string) $definition['id'] )
                : '';
            if ( '' !== $action_id )
            {
                $action_ids[] = $action_id;
            }
        }

        foreach ( (array) ( $custom_actions['actions'] ?? [] ) as $action )
        {
            if ( ! is_array( $action ) )
            {
                continue;
            }
            $action_code = isset( $action['code'] ) && is_scalar( $action['code'] )
                ? sanitize_key( (string) $action['code'] )
                : '';
            if ( '' !== $action_code )
            {
                $action_ids[] = $action_code;
            }
        }

        $action_ids = array_values( array_unique( $action_ids ) );
        if ( empty( $action_ids ) )
        {
            return [];
        }

        $controller = new Sentient_Forms_Form_Action_Config_Controller();
        $defaults = [];
        $batch_limit = Sentient_Forms_Form_Action_Config_Controller::ACTION_DEFAULTS_BATCH_LIMIT;
        foreach ( array_chunk( $action_ids, $batch_limit ) as $action_id_batch )
        {
            $data = $this->bootstrap_response_data(
                $controller->get_action_defaults_batch(
                    $this->create_bootstrap_request(
                        [
                            'ids' => implode( ',', $action_id_batch ),
                        ]
                    )
                ),
                []
            );

            if ( isset( $data['defaults'] ) && is_array( $data['defaults'] ) )
            {
                $defaults = array_merge( $defaults, $data['defaults'] );
            }
        }

        return $defaults;
    }

    /**
     * Build normalized action linkages for a form without creating nested REST requests.
     *
     * @return array<int, array<string, mixed>>
     */
    private function build_form_actions_payload(
        string $form_source_slug,
        string $form_id,
        ?array $stored_actions = null,
        ?array $local_mapping_rows = null,
        ?array $cps_actions = null
    ): array
    {
        // Get local WP linkages
        $local_actions = null === $stored_actions
            ? $this->get_actions_option( $form_source_slug, $form_id )
            : $stored_actions;
        if ( ! is_array( $local_actions ) ) {
            $local_actions = [];
        }

        $local_actions = $this->extract_action_linkages_from_option( $local_actions );
        $local_actions = $this->merge_local_first_actions( $local_actions, $form_source_slug, $form_id, $local_mapping_rows );

        // Phase 7 CSM: Optionally merge CPS mappings
        $cps_actions = null === $cps_actions
            ? $this->fetch_cps_mappings_for_form( $form_source_slug, $form_id )
            : $cps_actions;
        $merged = $this->merge_local_and_cps_actions( $local_actions, $cps_actions );

        return $merged;
    }

    private function list_forms_for_source( string $form_source_slug ): WP_Error | array
    {
        $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $adapter  = $registry ? $registry->get_adapter_by_id( $form_source_slug ) : null;
        if ( ! $adapter )
        {
            return $this->prepare_error_response( 'rest_invalid_form_source', __( 'Invalid form source.', 'sentient-forms' ), 404 );
        }

        if ( ! method_exists( $adapter, 'get_forms' ) )
        {
            return $this->prepare_error_response( 'rest_not_implemented', __( 'Adapter does not support listing forms.', 'sentient-forms' ), 501 );
        }

        $forms = $adapter->get_forms();
        return is_array( $forms ) ? $forms : [];
    }

    /**
     * Return workflow planning data for dependency graph execution previews.
     *
     * Attempts CPS authority first; falls back to a local deterministic planner when CPS is
     * unavailable so the UI can remain readable.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function get_workflow_plan( WP_REST_Request $request ): WP_REST_Response
    {
        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id          = $this->get_request_form_id( $request );
        $hook_scope       = $this->sanitize_lifecycle_scope( $request->get_param( 'hook_scope' ) );

        $local_actions = $this->get_actions_option( $form_source_slug, $form_id );
        $local_actions = $this->extract_action_linkages_from_option( $local_actions );
        $local_actions = $this->merge_local_first_actions( $local_actions, $form_source_slug, $form_id );

        $cps_error        = null;
        $cps_sync_error   = null;
        $authority_reason = 'cps_unavailable';
        if ( $this->mappings_sync && $this->is_positive_integer_form_id( $form_id ) )
        {
            $numeric_form_id = absint( $form_id );
            $cps_plan        = $this->mappings_sync->plan_workflow( $form_source_slug, $numeric_form_id, $hook_scope );
            if ( ! is_wp_error( $cps_plan ) && is_array( $cps_plan ) )
            {
                $normalized_plan = $this->normalize_workflow_plan_payload( $cps_plan, $hook_scope );
                if ( $this->workflow_plan_covers_local_actions( $normalized_plan, $local_actions ) )
                {
                    return $this->prepare_item_for_response( $normalized_plan );
                }

                $authority_reason = 'cps_mismatch';
            }

            if ( is_wp_error( $cps_plan ) )
            {
                $cps_error = $cps_plan->get_error_code();
                if ( is_string( $cps_error ) && '' !== $cps_error )
                {
                    $lower_error = strtolower( $cps_error );
                    if ( false !== strpos( $lower_error, 'mismatch' ) )
                    {
                        $authority_reason = 'cps_mismatch';
                    }
                }
            }

            if ( 'cps_mismatch' === $authority_reason && ! empty( $local_actions ) )
            {
                $sync_result = $this->mappings_sync->sync_form_mappings_for_form(
                    $form_source_slug,
                    $numeric_form_id,
                    $local_actions,
                    true
                );

                if ( is_wp_error( $sync_result ) )
                {
                    $cps_sync_error = $sync_result->get_error_code();
                }
                elseif ( $this->mapping_sync_result_is_clean( $sync_result ) )
                {
                    $retry_plan = $this->mappings_sync->plan_workflow( $form_source_slug, $numeric_form_id, $hook_scope );
                    if ( ! is_wp_error( $retry_plan ) && is_array( $retry_plan ) )
                    {
                        $normalized_plan = $this->normalize_workflow_plan_payload( $retry_plan, $hook_scope );
                        if ( $this->workflow_plan_covers_local_actions( $normalized_plan, $local_actions ) )
                        {
                            return $this->prepare_item_for_response( $normalized_plan );
                        }
                    }
                    elseif ( is_wp_error( $retry_plan ) )
                    {
                        $cps_error = $retry_plan->get_error_code();
                    }
                }
                else
                {
                    $cps_sync_error = 'cps_sync_incomplete';
                }
            }
        }

        $cps_actions = $this->fetch_cps_mappings_for_form( $form_source_slug, $form_id );
        $merged      = $this->merge_local_and_cps_actions( $local_actions, $cps_actions );
        $fallback = $this->build_local_workflow_plan_payload( $merged, $hook_scope, $authority_reason );
        $fallback['cps_unreachable'] = 'cps_mismatch' !== $authority_reason;
        if ( is_string( $cps_error ) && '' !== $cps_error )
        {
            $fallback['cps_error_code'] = $cps_error;
        }
        if ( is_string( $cps_sync_error ) && '' !== $cps_sync_error )
        {
            $fallback['cps_sync_error_code'] = $cps_sync_error;
        }

        return $this->prepare_item_for_response( $fallback );
    }

    /**
     * Return request-trace simulation details for the current mapping graph.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_request_trace( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id          = $this->get_request_form_id( $request );
        $hook_scope       = $this->sanitize_lifecycle_scope( $request->get_param( 'hook_scope' ) );
        $field_scope      = $this->sanitize_trace_field_scope( (string) ( $request->get_param( 'field_scope' ) ?? 'mapped_and_rule' ) );
        $manual_values    = $this->sanitize_trace_entry_values( $request->get_param( 'entry_values' ) );
        $entry_id         = (int) ( $request->get_param( 'entry_id' ) ?? 0 );
        $include_drafts   = rest_sanitize_boolean( $request->get_param( 'include_drafts' ) );
        $draft_mappings   = $include_drafts
            ? $this->sanitize_trace_draft_mappings( $request->get_param( 'draft_mappings' ) )
            : [];

        $actions = $this->normalize_trace_actions_for_form( $form_source_slug, $form_id );
        if ( $include_drafts && ! empty( $draft_mappings ) )
        {
            foreach ( $draft_mappings as $draft_mapping )
            {
                if ( ! is_array( $draft_mapping ) )
                {
                    continue;
                }

                $mapping_id = isset( $draft_mapping['local_mapping_id'] ) && is_scalar( $draft_mapping['local_mapping_id'] )
                    ? sanitize_text_field( (string) $draft_mapping['local_mapping_id'] )
                    : '';
                if ( '' === $mapping_id )
                {
                    continue;
                }

                $actions[ $mapping_id ] = $draft_mapping;
            }
        }

        [ $entry_values, $input_meta ] = $this->resolve_trace_entry_values(
            $actions,
            $manual_values,
            $entry_id,
            $field_scope,
        );
        if ( is_wp_error( $entry_values ) )
        {
            return $entry_values;
        }

        $plugin = Sentient_Forms_Plugin::instance();
        $tracer = new Sentient_Forms_Request_Tracer(
            $plugin->get_mapping_dependency_planner(),
            $plugin->get_condition_evaluator(),
        );

        $trace           = $tracer->trace( $actions, $entry_values, $hook_scope );
        $trace['input']  = $this->build_trace_input_payload(
            $entry_id,
            $field_scope,
            $manual_values,
            $entry_values,
            $input_meta,
            $include_drafts,
            ! empty( $draft_mappings ),
        );

        return $this->prepare_item_for_response( $trace );
    }

    /**
     * @param mixed $field_scope Raw field scope value.
     */
    private function sanitize_trace_field_scope( string $field_scope ): string
    {
        $normalized = sanitize_key( $field_scope );
        return 'mapped_and_rule' === $normalized ? 'mapped_and_rule' : 'mapped_and_rule';
    }

    /**
     * @param mixed $raw_values Manual entry values payload.
     *
     * @return array<string, string>
     */
    private function sanitize_trace_entry_values( $raw_values ): array
    {
        if ( ! is_array( $raw_values ) )
        {
            return [];
        }

        $sanitized = [];
        foreach ( $raw_values as $field_id => $value )
        {
            if ( count( $sanitized ) >= self::MAX_TRACE_ENTRY_VALUES )
            {
                break;
            }

            if ( ! is_scalar( $field_id ) || '' === trim( (string) $field_id ) )
            {
                continue;
            }
            if ( ! is_scalar( $value ) && null !== $value )
            {
                continue;
            }

            $normalized_field_id = sanitize_text_field( (string) $field_id );
            if ( '' === $normalized_field_id )
            {
                continue;
            }

            if ( null === $value )
            {
                continue;
            }

            $normalized_value = sanitize_text_field( (string) $value );
            if ( strlen( $normalized_value ) > self::MAX_TRACE_VALUE_LENGTH )
            {
                $normalized_value = substr( $normalized_value, 0, self::MAX_TRACE_VALUE_LENGTH );
            }

            $sanitized[ $normalized_field_id ] = $normalized_value;
        }

        return $sanitized;
    }

    /**
     * @param mixed $raw_mappings Draft mappings payload.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sanitize_trace_draft_mappings( $raw_mappings ): array
    {
        if ( ! is_array( $raw_mappings ) )
        {
            return [];
        }

        $sanitized = [];
        foreach ( $raw_mappings as $candidate )
        {
            if ( count( $sanitized ) >= self::MAX_TRACE_DRAFT_MAPPINGS )
            {
                break;
            }
            if ( ! is_array( $candidate ) )
            {
                continue;
            }

            $mapping = $this->sanitize_trace_draft_mapping( $candidate );
            if ( empty( $mapping ) )
            {
                continue;
            }

            $sanitized[] = $mapping;
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $mapping Raw mapping payload.
     *
     * @return array<string, mixed>
     */
    private function sanitize_trace_draft_mapping( array $mapping ): array
    {
        $local_mapping_id = isset( $mapping['local_mapping_id'] ) && is_scalar( $mapping['local_mapping_id'] )
            ? sanitize_text_field( (string) $mapping['local_mapping_id'] )
            : '';
        $central_action_id = isset( $mapping['central_action_id'] ) && is_scalar( $mapping['central_action_id'] )
            ? sanitize_text_field( (string) $mapping['central_action_id'] )
            : '';
        if ( '' === $local_mapping_id || '' === $central_action_id )
        {
            return [];
        }

        $action_type_indicator = isset( $mapping['action_type_indicator'] ) && is_scalar( $mapping['action_type_indicator'] )
            ? sanitize_key( (string) $mapping['action_type_indicator'] )
            : 'master';
        if ( ! in_array( $action_type_indicator, self::ACTION_TYPE_INDICATORS, true ) )
        {
            $action_type_indicator = 'master';
        }

        $trigger_hooks = isset( $mapping['trigger_hooks'] ) && is_array( $mapping['trigger_hooks'] )
            ? $this->sanitize_trigger_hooks( $mapping['trigger_hooks'] )
            : [];

        $settings = [];
        if ( isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) )
        {
            $settings = $this->sanitize_settings( $mapping['settings'] );
            if ( isset( $mapping['settings']['trigger_sources'] ) && is_array( $mapping['settings']['trigger_sources'] ) )
            {
                $settings['trigger_sources'] = $this->sanitize_trace_trigger_sources( $mapping['settings']['trigger_sources'] );
            }
        }

        $result = [
            'local_mapping_id'           => $local_mapping_id,
            'central_action_id'          => $central_action_id,
            'action_type_indicator'      => $action_type_indicator,
            'trigger_hooks'              => $trigger_hooks,
            'is_action_enabled_for_form' => isset( $mapping['is_action_enabled_for_form'] )
                ? rest_sanitize_boolean( $mapping['is_action_enabled_for_form'] )
                : true,
            'settings'                   => $settings,
        ];

        if ( isset( $mapping['action_name_label'] ) && is_scalar( $mapping['action_name_label'] ) )
        {
            $result['action_name_label'] = sanitize_text_field( (string) $mapping['action_name_label'] );
        }

        if ( isset( $mapping['execution_mode'] ) && is_scalar( $mapping['execution_mode'] ) )
        {
            $execution_mode = sanitize_key( (string) $mapping['execution_mode'] );
            if ( in_array( $execution_mode, [ 'validation', 'after_submission', 'real_time' ], true ) )
            {
                $result['execution_mode'] = $execution_mode;
            }
        }

        return $result;
    }

    /**
     * Sanitize per-hook trigger source definitions for tracing (supports unbound).
     *
     * @param array<string, mixed> $trigger_sources Raw trigger sources keyed by hook id.
     *
     * @return array<string, array{type: string, mapping_id?: string}>
     */
    private function sanitize_trace_trigger_sources( array $trigger_sources ): array
    {
        $sanitized = [];
        foreach ( $trigger_sources as $hook => $source )
        {
            if ( ! is_scalar( $hook ) || ! is_array( $source ) )
            {
                continue;
            }

            $hook_key = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $hook );
            if ( null === $hook_key )
            {
                continue;
            }

            $type = isset( $source['type'] ) && is_scalar( $source['type'] )
                ? sanitize_key( (string) $source['type'] )
                : '';
            if ( 'hook_root' === $type || 'unbound' === $type )
            {
                $sanitized[ $hook_key ] = [ 'type' => $type ];
                continue;
            }

            if ( 'mapping' !== $type )
            {
                continue;
            }

            $mapping_id = '';
            if ( isset( $source['mapping_id'] ) && is_scalar( $source['mapping_id'] ) )
            {
                $mapping_id = sanitize_text_field( (string) $source['mapping_id'] );
            }
            elseif ( isset( $source['source_mapping_id'] ) && is_scalar( $source['source_mapping_id'] ) )
            {
                $mapping_id = sanitize_text_field( (string) $source['source_mapping_id'] );
            }

            if ( '' === $mapping_id )
            {
                continue;
            }

            $sanitized[ $hook_key ] = [
                'type'       => 'mapping',
                'mapping_id' => $mapping_id,
            ];
        }

        return $sanitized;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function normalize_trace_actions_for_form( string $form_source_slug, string $form_id ): array
    {
        $local_actions = $this->get_actions_option( $form_source_slug, $form_id );
        $local_actions = $this->extract_action_linkages_from_option( $local_actions );
        $local_actions = $this->merge_local_first_actions( $local_actions, $form_source_slug, $form_id );

        $cps_actions = $this->fetch_cps_mappings_for_form( $form_source_slug, $form_id );
        $merged      = $this->merge_local_and_cps_actions( $local_actions, $cps_actions );

        return $this->normalize_local_action_mappings( $merged );
    }

    /**
     * @param array<string, array<string, mixed>> $actions
     * @param array<string, string>               $manual_values
     *
     * @return array{0: array<string, string>|WP_Error, 1: array<string, mixed>}
     */
    private function resolve_trace_entry_values(
        array $actions,
        array $manual_values,
        int $entry_id,
        string $field_scope
    ): array
    {
        $meta = [
            'entry_id'           => $entry_id > 0 ? $entry_id : null,
            'imported_field_ids' => [],
            'overridden_field_ids' => [],
            'warnings'           => [],
        ];

        if ( $entry_id <= 0 )
        {
            return [ $manual_values, $meta ];
        }

        if ( ! class_exists( 'GFAPI' ) )
        {
            return [
                $this->prepare_error_response( 'rest_gf_missing', __( 'Gravity Forms is required for this endpoint.', 'sentient-forms' ), 500 ),
                $meta,
            ];
        }

        $entry = GFAPI::get_entry( $entry_id );
        if ( is_wp_error( $entry ) )
        {
            return [
                $this->prepare_error_response( 'rest_entry_not_found', __( 'Entry not found.', 'sentient-forms' ), 404 ),
                $meta,
            ];
        }

        $imported_values = $this->extract_scalar_entry_values( $entry );
        if ( 'mapped_and_rule' === $field_scope )
        {
            $allowed_field_ids = $this->collect_trace_referenced_field_ids( $actions, $imported_values );
            if ( ! empty( $allowed_field_ids ) )
            {
                $imported_values = array_intersect_key( $imported_values, array_flip( $allowed_field_ids ) );
            }
            else
            {
                $imported_values = [];
            }
        }

        $meta['imported_field_ids']  = array_values( array_map( 'strval', array_keys( $imported_values ) ) );
        $meta['overridden_field_ids'] = array_values(
            array_map(
                'strval',
                array_intersect(
                    array_keys( $manual_values ),
                    array_keys( $imported_values )
                )
            )
        );

        if ( empty( $imported_values ) )
        {
            $meta['warnings'][] = __( 'No entry fields matched the selected trace field scope.', 'sentient-forms' );
        }

        $combined = array_merge( $imported_values, $manual_values );

        return [ $combined, $meta ];
    }

    /**
     * @param array<string, string> $import_values
     * @param array<string, string> $effective_values
     * @param array<string, mixed>  $input_meta
     *
     * @return array<string, mixed>
     */
    private function build_trace_input_payload(
        int $entry_id,
        string $field_scope,
        array $manual_values,
        array $effective_values,
        array $input_meta,
        bool $include_drafts,
        bool $draft_applied
    ): array
    {
        $source = 'empty';
        if ( $entry_id > 0 && ! empty( $manual_values ) )
        {
            $source = 'entry_import_with_manual_overrides';
        }
        elseif ( $entry_id > 0 )
        {
            $source = 'entry_import';
        }
        elseif ( ! empty( $manual_values ) )
        {
            $source = 'manual';
        }

        return [
            'source'               => $source,
            'entry_id'             => $entry_id > 0 ? $entry_id : null,
            'field_scope'          => $field_scope,
            'values'               => $effective_values,
            'manual_field_ids'     => array_values( array_map( 'strval', array_keys( $manual_values ) ) ),
            'imported_field_ids'   => is_array( $input_meta['imported_field_ids'] ?? null )
                ? array_values( $input_meta['imported_field_ids'] )
                : [],
            'overridden_field_ids' => is_array( $input_meta['overridden_field_ids'] ?? null )
                ? array_values( $input_meta['overridden_field_ids'] )
                : [],
            'warnings'             => is_array( $input_meta['warnings'] ?? null )
                ? array_values( array_filter( $input_meta['warnings'], 'is_string' ) )
                : [],
            'include_drafts'       => $include_drafts,
            'draft_applied'        => $draft_applied,
        ];
    }

    /**
     * @param array<string, mixed>  $entry
     *
     * @return array<string, string>
     */
    private function extract_scalar_entry_values( array $entry ): array
    {
        $values = [];
        foreach ( $entry as $key => $value )
        {
            if ( ! is_scalar( $key ) || ! is_scalar( $value ) )
            {
                continue;
            }

            $field_id = sanitize_text_field( (string) $key );
            if ( '' === $field_id )
            {
                continue;
            }

            $field_value = sanitize_text_field( (string) $value );
            if ( strlen( $field_value ) > self::MAX_TRACE_VALUE_LENGTH )
            {
                $field_value = substr( $field_value, 0, self::MAX_TRACE_VALUE_LENGTH );
            }

            $values[ $field_id ] = $field_value;
        }

        return $values;
    }

    /**
     * @param array<string, array<string, mixed>> $actions
     * @param array<string, string>               $entry_values
     *
     * @return array<int, string>
     */
    private function collect_trace_referenced_field_ids( array $actions, array $entry_values ): array
    {
        $field_ids = [];
        foreach ( $actions as $mapping )
        {
            if ( ! is_array( $mapping ) )
            {
                continue;
            }

            $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
                ? $mapping['settings']
                : [];

            $input_mapping = $this->normalize_trace_input_mapping( $settings['input_mapping'] ?? null );
            if ( is_array( $input_mapping ) )
            {
                $mode      = $input_mapping['mode'] ?? 'selected';
                $field_ids_list = isset( $input_mapping['field_ids'] ) && is_array( $input_mapping['field_ids'] )
                    ? array_values( array_map( 'strval', $input_mapping['field_ids'] ) )
                    : [];

                if ( 'all' === $mode )
                {
                    $field_ids = array_merge( $field_ids, array_keys( $entry_values ) );
                }
                elseif ( 'exclude' === $mode )
                {
                    $remaining = array_diff( array_keys( $entry_values ), $field_ids_list );
                    $field_ids = array_merge( $field_ids, $remaining );
                }
                else
                {
                    $field_ids = array_merge( $field_ids, $field_ids_list );
                }
            }

            if (
                isset( $settings['conditions'] )
                && is_array( $settings['conditions'] )
                && ! empty( $settings['conditions']['enabled'] )
                && isset( $settings['conditions']['root'] )
                && is_array( $settings['conditions']['root'] )
            )
            {
                $this->collect_trace_condition_field_ids_from_node( $settings['conditions']['root'], $field_ids );
            }
        }

        $field_ids = array_filter(
            array_map(
                static fn( $field_id ): string => sanitize_text_field( (string) $field_id ),
                $field_ids
            ),
            static fn( string $field_id ): bool => '' !== $field_id
        );

        return array_values( array_unique( $field_ids ) );
    }

    /**
     * @param mixed               $input_mapping Raw input mapping.
     *
     * @return array<string, mixed>|null
     */
    private function normalize_trace_input_mapping( $input_mapping ): ?array
    {
        if ( ! is_array( $input_mapping ) )
        {
            return null;
        }

        $mode = isset( $input_mapping['mode'] ) && is_scalar( $input_mapping['mode'] )
            ? sanitize_key( (string) $input_mapping['mode'] )
            : 'selected';
        if ( ! in_array( $mode, [ 'all', 'selected', 'exclude' ], true ) )
        {
            $mode = 'selected';
        }

        $field_ids = [];
        if ( isset( $input_mapping['field_ids'] ) && is_array( $input_mapping['field_ids'] ) )
        {
            foreach ( $input_mapping['field_ids'] as $field_id )
            {
                if ( ! is_scalar( $field_id ) )
                {
                    continue;
                }

                $normalized = sanitize_text_field( (string) $field_id );
                if ( '' === $normalized )
                {
                    continue;
                }
                $field_ids[] = $normalized;
            }
        }

        return [
            'mode'      => $mode,
            'field_ids' => array_values( array_unique( $field_ids ) ),
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string>   $field_ids
     */
    private function collect_trace_condition_field_ids_from_node( array $node, array &$field_ids ): void
    {
        $type = isset( $node['type'] ) && is_scalar( $node['type'] )
            ? sanitize_key( (string) $node['type'] )
            : '';
        if ( 'rule' === $type )
        {
            $field_id = isset( $node['field_id'] ) && is_scalar( $node['field_id'] )
                ? sanitize_text_field( (string) $node['field_id'] )
                : '';
            if ( '' !== $field_id )
            {
                $field_ids[] = $field_id;
            }
            return;
        }

        $rules = isset( $node['rules'] ) && is_array( $node['rules'] ) ? $node['rules'] : [];
        foreach ( $rules as $child )
        {
            if ( ! is_array( $child ) )
            {
                continue;
            }

            $this->collect_trace_condition_field_ids_from_node( $child, $field_ids );
        }
    }

    /**
     * CB-FORMS-001: Get the per-form disabled state.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function get_form_disabled( WP_REST_Request $request ): WP_REST_Response
    {
        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id          = $this->get_request_form_id( $request );

        return $this->prepare_item_for_response(
            $this->build_form_disabled_state( $form_source_slug, $form_id )
        );
    }

    /**
     * Build the per-form disabled state without nested REST dispatch.
     *
     * @return array<string, bool>
     */
    private function build_form_disabled_state( string $form_source_slug, string $form_id ): array
    {
        $options = $this->get_actions_option( $form_source_slug, $form_id );

        $sf_disabled = ! empty( $options['sf_disabled'] );
        $execution_disable = $this->get_execution_disable_flags( $form_source_slug );
        $effective_disabled = $sf_disabled || $execution_disable['global_disabled'] || $execution_disable['provider_disabled'];

        return [
            'sf_disabled'       => $sf_disabled,
            'global_disabled'   => $execution_disable['global_disabled'],
            'provider_disabled' => $execution_disable['provider_disabled'],
            'effective_disabled'=> $effective_disabled,
        ];
    }

    /**
     * CB-FORMS-001: Toggle per-form master disable flag.
     *
     * Sets the sf_disabled flag in the form's option array.
     * When enabled, the GF adapter skips all action processing for this form.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function toggle_form_disabled( WP_REST_Request $request ): WP_REST_Response
    {
        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id          = $this->get_request_form_id( $request );
        $sf_disabled      = (bool) $request->get_param( 'sf_disabled' );

        $option_key = $this->get_actions_option_key( $form_source_slug, $form_id );
        $options    = $this->get_actions_option( $form_source_slug, $form_id );

        $options['sf_disabled'] = $sf_disabled;
        update_option( $option_key, $options, false );

        $execution_disable = $this->get_execution_disable_flags( $form_source_slug );
        $effective_disabled = $sf_disabled || $execution_disable['global_disabled'] || $execution_disable['provider_disabled'];

        return $this->prepare_item_for_response( [
            'sf_disabled'       => $sf_disabled,
            'global_disabled'   => $execution_disable['global_disabled'],
            'provider_disabled' => $execution_disable['provider_disabled'],
            'effective_disabled'=> $effective_disabled,
            'message'           => $sf_disabled
                ? __( 'Sentient Forms disabled for this form.', 'sentient-forms' )
                : __( 'Sentient Forms enabled for this form.', 'sentient-forms' ),
        ] );
    }

    /**
     * Read execution disable settings from plugin-level settings.
     *
     * @param string $form_source_slug Form provider slug.
     * @return array{global_disabled: bool, provider_disabled: bool}
     */
    private function get_execution_disable_flags( string $form_source_slug ): array
    {
        $settings = get_option( 'sentient_forms_plugin_settings', [] );
        if ( ! is_array( $settings ) )
        {
            $settings = [];
        }

        $provider_map = $settings['execution_provider_disabled'] ?? [];
        if ( ! is_array( $provider_map ) )
        {
            $provider_map = [];
        }

        $provider_key = sanitize_key( $form_source_slug );

        return [
            'global_disabled'   => ! empty( $settings['execution_global_disabled'] ),
            'provider_disabled' => ! empty( $provider_map[ $provider_key ] ),
        ];
    }

    /**
     * Phase 7 CSM: Fetch CPS mappings for a specific form.
     *
     * @param string $form_source_slug Form source (e.g., 'gravity_forms').
     * @param int|string $form_id      Form ID.
     * @return array Transformed CPS mappings as local linkage format.
     */
    private function fetch_cps_mappings_for_form( string $form_source_slug, int|string $form_id ): array
    {
        if ( ! $this->mappings_sync ) {
            return [];
        }

        $normalized_form_id = $this->normalize_cps_mapping_form_id( $form_source_slug, $form_id );
        if ( '' === $normalized_form_id )
        {
            return [];
        }

        $site_id = $this->resolve_cps_site_id_for_mappings();
        if ( empty( $site_id ) ) {
            return [];
        }

        try {
            $all_mappings  = $this->fetch_cps_mappings_once();
            $form_mappings = array_filter(
                $all_mappings,
                function ( array $m ) use ( $site_id, $form_source_slug, $normalized_form_id ) {
                    return
                        ( $m['site_id'] ?? '' ) === $site_id &&
                        ( $m['form_source'] ?? '' ) === $form_source_slug &&
                        $this->normalize_cps_mapping_form_id( $form_source_slug, $m['form_id'] ?? '' ) === $normalized_form_id &&
                        empty( $m['is_template'] ); // Exclude templates
                }
            );

            return array_map( [ $this, 'transform_cps_mapping_to_linkage' ], array_values( $form_mappings ) );
        } catch ( \Throwable $e ) {
            // Silently fail - CPS unreachable, use local only
            return [];
        }
    }

    /**
     * Build transformed CPS actions once for a forms overview response.
     *
     * @param array<int, int|string> $form_ids Form IDs to include.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function list_cps_actions_for_forms( string $form_source_slug, array $form_ids ): array
    {
        if ( ! $this->mappings_sync )
        {
            return [];
        }

        $requested_form_ids = [];
        foreach ( $form_ids as $form_id )
        {
            $normalized_id = $this->normalize_cps_mapping_form_id( $form_source_slug, $form_id );
            if ( '' !== $normalized_id )
            {
                $requested_form_ids[ $normalized_id ] = true;
            }
        }

        if ( empty( $requested_form_ids ) )
        {
            return [];
        }

        $site_id = $this->resolve_cps_site_id_for_mappings();
        if ( '' === $site_id )
        {
            return [];
        }

        $actions_by_form = [];
        foreach ( $this->fetch_cps_mappings_once() as $mapping )
        {
            $form_key = $this->normalize_cps_mapping_form_id( $form_source_slug, $mapping['form_id'] ?? '' );
            if (
                '' === $form_key
                || ! isset( $requested_form_ids[ $form_key ] )
                || ( $mapping['site_id'] ?? '' ) !== $site_id
                || ( $mapping['form_source'] ?? '' ) !== $form_source_slug
                || ! empty( $mapping['is_template'] )
            )
            {
                continue;
            }

            if ( ! isset( $actions_by_form[ $form_key ] ) )
            {
                $actions_by_form[ $form_key ] = [];
            }

            $actions_by_form[ $form_key ][] = $this->transform_cps_mapping_to_linkage( $mapping );
        }

        return $actions_by_form;
    }

    private function normalize_cps_mapping_form_id( string $form_source_slug, mixed $form_id ): string
    {
        $form_id = $this->normalize_provider_form_id( $form_id );
        if ( '' === $form_id )
        {
            return '';
        }

        if ( Sentient_Forms_Form_Sources::GRAVITY_FORMS === sanitize_key( $form_source_slug ) )
        {
            return $this->is_positive_integer_form_id( $form_id ) ? (string) absint( $form_id ) : '';
        }

        return Sentient_Forms_Provider_Form_Id_Keys::is_valid( $form_id ) ? $form_id : '';
    }

    /**
     * Fetch the full CPS mapping list once for this controller request.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetch_cps_mappings_once(): array
    {
        if ( null !== $this->cps_mappings_cache )
        {
            return $this->cps_mappings_cache;
        }

        if ( ! $this->mappings_sync )
        {
            $this->cps_mappings_cache = [];
            return $this->cps_mappings_cache;
        }

        try
        {
            $mappings = $this->mappings_sync->fetch_mappings();
        }
        catch ( \Throwable $e )
        {
            $this->cps_mappings_cache = [];
            return $this->cps_mappings_cache;
        }

        $this->cps_mappings_cache = array_values(
            array_filter(
                is_array( $mappings ) ? $mappings : [],
                static function ( $mapping ): bool {
                    return is_array( $mapping );
                }
            )
        );

        return $this->cps_mappings_cache;
    }

    /**
     * Determine whether automatic CPS mapping reconciliation completed without errors.
     */
    private function mapping_sync_result_is_clean( array $sync_result ): bool
    {
        $counts = isset( $sync_result['counts'] ) && is_array( $sync_result['counts'] )
            ? $sync_result['counts']
            : [];

        return 0 === (int) ( $counts['error'] ?? 0 );
    }

    /**
     * Best-effort CPS reconciliation after local CRUD mutations.
     *
     * Local persistence remains the fallback if CPS is down, but a healthy CPS
     * should not be left stale until the admin planner is opened again.
     */
    private function normalize_syncable_cps_form_id( string $form_source_slug, mixed $form_id ): int|string|null
    {
        $normalized = $this->normalize_cps_mapping_form_id( $form_source_slug, $form_id );
        if ( '' === $normalized )
        {
            return null;
        }

        return Sentient_Forms_Form_Sources::GRAVITY_FORMS === sanitize_key( $form_source_slug )
            ? absint( $normalized )
            : $normalized;
    }

    private function sync_form_mappings_after_local_change( string $form_source_slug, int|string $form_id, array $actions ): void
    {
        if ( ! $this->mappings_sync )
        {
            return;
        }

        $sync_form_id = $this->normalize_syncable_cps_form_id( $form_source_slug, $form_id );
        if ( null === $sync_form_id )
        {
            return;
        }

        $this->mappings_sync->sync_form_mappings_for_form(
            $form_source_slug,
            $sync_form_id,
            $this->extract_action_linkages_from_option( $actions ),
            true
        );
        $this->cps_mappings_cache = null;
    }

    /**
     * Resolve the site UUID used by CPS form mapping records.
     */
    private function resolve_cps_site_id_for_mappings(): string
    {
        if ( $this->mappings_sync )
        {
            $site_id = $this->mappings_sync->get_site_id();
            if ( '' !== $site_id )
            {
                return $site_id;
            }
        }

        $license_data = [];
        if ( class_exists( 'Sentient_Forms_Plugin' ) )
        {
            $license_data = Sentient_Forms_Plugin::instance()->get_license_data();
        }

        $license_site_id = isset( $license_data['site_id'] ) && is_scalar( $license_data['site_id'] )
            ? sanitize_text_field( (string) $license_data['site_id'] )
            : '';
        if ( '' !== $license_site_id )
        {
            return $license_site_id;
        }

        return sanitize_text_field( (string) get_option( 'sentient_forms_site_id', '' ) );
    }

    /**
     * Phase 7 CSM: Transform a CPS mapping to local linkage format.
     *
     * @param array $mapping CPS mapping.
     * @return array Local linkage format.
     */
    private function transform_cps_mapping_to_linkage( array $mapping ): array
    {
        $settings = $mapping['settings'] ?? [];
        $local_mapping_id = isset( $settings['local_mapping_id'] ) && is_scalar( $settings['local_mapping_id'] )
            ? sanitize_text_field( (string) $settings['local_mapping_id'] )
            : 'cps_' . ( $mapping['id'] ?? uniqid() );

        return [
            'local_mapping_id'           => $local_mapping_id,
            'cps_mapping_id'             => $mapping['id'] ?? null, // Track CPS origin
            'central_action_id'          => $mapping['action_template_code'] ?? $mapping['action_template_id'] ?? $mapping['custom_action_id'] ?? '',
            'action_type_indicator'      => ! empty( $mapping['custom_action_id'] ) ? 'custom' : 'master',
            'trigger_hooks'              => $settings['trigger_hooks'] ?? [],
            'is_action_enabled_for_form' => $settings['is_action_enabled_for_form'] ?? true,
            'execution_priority'         => $settings['execution_priority'] ?? 10,
            'action_name_label'          => $mapping['display_name'] ?? 'CPS Mapping',
            'settings'                   => $settings,
            'source'                     => 'cps', // Mark as CPS-sourced
        ];
    }

    /**
     * Phase 7 CSM: Merge local and CPS actions, avoiding duplicates.
     *
     * @param array $local_actions Local WP linkages.
     * @param array $cps_actions   Transformed CPS mappings.
     * @return array Merged list.
     */
    private function merge_local_and_cps_actions( array $local_actions, array $cps_actions ): array
    {
        // Normalize local actions to arrays only (legacy settings may include scalar keys like "enabled").
        $normalized_local_actions = [];
        foreach ( $local_actions as $action ) {
            if ( ! is_array( $action ) ) {
                continue;
            }
            $action['source']       = $action['source'] ?? 'local';
            $normalized_local_actions[] = $action;
        }

        $local_actions = $normalized_local_actions;

        // Add CPS actions that don't have a local equivalent
        $local_central_ids = array_values(
            array_filter(
                array_map(
                    static function ( $action ) {
                        return is_array( $action ) ? (string) ( $action['central_action_id'] ?? '' ) : '';
                    },
                    $local_actions
                ),
                static function ( $id ) {
                    return '' !== $id;
                }
            )
        );

        foreach ( $cps_actions as $cps_action ) {
            if ( ! is_array( $cps_action ) ) {
                continue;
            }

            $cps_central_id = (string) ( $cps_action['central_action_id'] ?? '' );

            // Skip if local already has this central action
            if ( '' !== $cps_central_id && in_array( $cps_central_id, $local_central_ids, true ) ) {
                continue;
            }

            $local_actions[] = $cps_action;
            if ( '' !== $cps_central_id ) {
                $local_central_ids[] = $cps_central_id;
            }
        }

        return $local_actions;
    }

    /**
     * CA-MAP-001: Retrieve form fields for FieldSelector component.
     *
     * Returns field metadata transformed to FormFieldInfo format.
     * Filters out non-input fields (HTML, page breaks, sections).
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response Field list or error.
     */
    public function get_form_fields( WP_REST_Request $request ): WP_REST_Response
    {
        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id          = $this->get_request_form_id( $request );

        // Get the adapter for this form source
        $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $adapter  = $registry ? $registry->get_adapter_by_id( $form_source_slug ) : null;
        if ( ! $adapter ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => 'Form source adapter not found.' ],
                404
            );
        }

        // Get raw form fields from adapter
        $raw_fields = $adapter->get_form_fields( $form_id );
        if ( empty( $raw_fields ) ) {
            return new WP_REST_Response(
                [ 'success' => true, 'data' => [] ],
                200
            );
        }

        // Transform to FieldSelector format and filter non-input fields
        $excluded_types = [ 'html', 'page', 'section', 'captcha' ];
        $fields         = [];

        foreach ( $raw_fields as $field ) {
            $field_type = strtolower( $this->extract_form_field_property( $field, 'type' ) );
            if ( in_array( $field_type, $excluded_types, true ) ) {
                continue;
            }

            $field_payload = [
                'id'         => $this->extract_form_field_property( $field, 'id' ),
                'label'      => $this->extract_form_field_property( $field, 'label' ),
                'type'       => $field_type,
                'adminLabel' => $this->extract_form_field_property( $field, 'adminLabel' ) ?: null,
                'page_index' => max( 1, (int) $this->extract_form_field_property( $field, 'pageNumber' ) ),
            ];

            foreach ( [ 'visibility', 'storage_eligible', 'file_reference_eligible', 'required' ] as $optional_key )
            {
                if ( is_array( $field ) && array_key_exists( $optional_key, $field ) )
                {
                    $field_payload[ $optional_key ] = $field[ $optional_key ];
                    continue;
                }

                if ( is_object( $field ) && isset( $field->{$optional_key} ) )
                {
                    $field_payload[ $optional_key ] = $field->{$optional_key};
                }
            }

            $fields[] = $field_payload;
        }

        return new WP_REST_Response(
            [ 'success' => true, 'data' => $fields ],
            200
        );
    }

    /**
     * Add a new action linkage to a form.
     */
    public function add_form_action( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        if ( $this->should_create_bundled_local_first_action( $request ) )
        {
            return $this->create_bundled_local_first_action( $request );
        }

        if ( $this->should_create_existing_local_custom_action_mapping( $request ) )
        {
            return $this->create_existing_local_custom_action_mapping( $request );
        }

        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id          = $this->get_request_form_id( $request );
        $option_key       = $this->get_actions_option_key( $form_source_slug, $form_id );
        $actions          = $this->get_actions_option( $form_source_slug, $form_id );

        $new_id = uniqid( 'map_', false );
        while ( isset( $actions[ $new_id ] ) )
        {
            $new_id = uniqid( 'map_', false );
        }

        $trigger_hooks = $this->sanitize_trigger_hooks( (array) $request->get_param( 'trigger_hooks' ) );
        $lifecycle_validation = $this->validate_form_source_trigger_hooks(
            sanitize_key( (string) $form_source_slug ),
            $trigger_hooks
        );
        if ( is_wp_error( $lifecycle_validation ) )
        {
            return $lifecycle_validation;
        }

        if ( $request->has_param( 'settings' ) )
        {
            $settings_validation = $this->validate_settings_write_payload( $request->get_param( 'settings' ) );
            if ( is_wp_error( $settings_validation ) )
            {
                return $settings_validation;
            }
        }

        $settings      = $request->has_param( 'settings' )
            ? $this->sanitize_settings( $request->get_param( 'settings' ) )
            : [];
        $realtime_policy = $this->validate_realtime_trigger_policy(
            $trigger_hooks,
            $request->get_param( 'central_action_id' ),
            $settings
        );
        if ( is_wp_error( $realtime_policy ) )
        {
            return $realtime_policy;
        }

        $action = [
            'local_mapping_id'           => $new_id,
            'central_action_id'          => $request->get_param( 'central_action_id' ),
            'action_type_indicator'      => $request->get_param( 'action_type_indicator' ),
            'trigger_hooks'              => $trigger_hooks,
            'is_action_enabled_for_form' => $request->get_param( 'is_action_enabled_for_form' ) ?? true,
            'execution_priority'         => $request->get_param( 'execution_priority' ) ?? 10,
        ];

        if ( $request->has_param( 'action_name_label' ) )
        {
            $action[ 'action_name_label' ] = $request->get_param( 'action_name_label' );
        }

        if ( [] !== $settings )
        {
            $action[ 'settings' ] = $settings;
        }

        $actions_to_validate            = $actions;
        $actions_to_validate[ $new_id ] = $action;

        $dependency_validation = $this->validate_mapping_dependencies( $actions_to_validate );
        if ( is_wp_error( $dependency_validation ) )
        {
            return $this->prepare_error_response(
                $dependency_validation->get_error_code(),
                $dependency_validation->get_error_message(),
                400
            );
        }

        $actions[ $new_id ] = $action;
        update_option( $option_key, $actions, false );
        $this->sync_form_mappings_after_local_change(
            $form_source_slug,
            $form_id,
            $actions
        );

        return $this->prepare_item_for_response( $action, 201 );
    }

    private function should_create_bundled_local_first_action( WP_REST_Request $request ): bool
    {
        if ( ! $this->local_form_mappings || ! $this->local_custom_actions || ! $this->local_action_templates )
        {
            return false;
        }

        $action_type_indicator = sanitize_key( (string) $request->get_param( 'action_type_indicator' ) );
        if ( 'master' !== $action_type_indicator )
        {
            return false;
        }

        $central_action_id = sanitize_key( (string) $request->get_param( 'central_action_id' ) );
        return Sentient_Forms_Bundled_Action_Templates::has( $central_action_id );
    }

    private function should_create_existing_local_custom_action_mapping( WP_REST_Request $request ): bool
    {
        if ( ! $this->local_form_mappings || ! $this->local_custom_actions )
        {
            return false;
        }

        $action_type_indicator = sanitize_key( (string) $request->get_param( 'action_type_indicator' ) );
        if ( 'custom' !== $action_type_indicator )
        {
            return false;
        }

        $custom_action_code = sanitize_key( (string) $request->get_param( 'central_action_id' ) );
        return '' !== $custom_action_code && is_array( $this->local_custom_actions->get_by_code( $custom_action_code ) );
    }

    private function create_existing_local_custom_action_mapping( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $custom_action_code = sanitize_key( (string) $request->get_param( 'central_action_id' ) );
        $custom_action      = $this->local_custom_actions ? $this->local_custom_actions->get_by_code( $custom_action_code ) : null;
        if ( ! is_array( $custom_action ) )
        {
            return $this->prepare_error_response(
                'rest_local_custom_action_not_found',
                __( 'Local custom action could not be found.', 'sentient-forms' ),
                404
            );
        }

        if ( 'active' !== sanitize_key( (string) ( $custom_action['status'] ?? '' ) ) )
        {
            return $this->prepare_error_response(
                'rest_local_custom_action_inactive',
                __( 'Local custom action must be active before it can be mapped.', 'sentient-forms' ),
                409
            );
        }

        if ( $request->has_param( 'settings' ) )
        {
            $settings_validation = $this->validate_settings_write_payload( $request->get_param( 'settings' ) );
            if ( is_wp_error( $settings_validation ) )
            {
                return $settings_validation;
            }
        }

        $form_source = sanitize_key( (string) $request->get_param( 'form_source_slug' ) );
        $form_id     = $this->get_request_form_id( $request );
        $settings      = $request->has_param( 'settings' ) ? $this->sanitize_settings( $request->get_param( 'settings' ) ) : [];
        $trigger_hooks = $this->sanitize_trigger_hooks( (array) $request->get_param( 'trigger_hooks' ) );
        if ( [] === $trigger_hooks )
        {
            $trigger_hooks = [ Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION ];
        }

        $lifecycle_validation = $this->validate_form_source_trigger_hooks( $form_source, $trigger_hooks );
        if ( is_wp_error( $lifecycle_validation ) )
        {
            return $lifecycle_validation;
        }

        $storage_validation = $this->validate_realtime_storage_target( $form_source, $form_id, $settings );
        if ( is_wp_error( $storage_validation ) )
        {
            return $storage_validation;
        }

        $realtime_policy = $this->validate_realtime_trigger_policy( $trigger_hooks, $custom_action_code, $settings );
        if ( is_wp_error( $realtime_policy ) )
        {
            return $realtime_policy;
        }

        $definition = is_array( $custom_action['definition_json'] ?? null ) ? $custom_action['definition_json'] : [];
        $enabled    = $request->has_param( 'is_action_enabled_for_form' )
            ? rest_sanitize_boolean( $request->get_param( 'is_action_enabled_for_form' ) )
            : true;
        $first_row         = null;
        $action_id         = absint( $custom_action['id'] ?? 0 );
        $pending_mappings  = [];

        foreach ( $trigger_hooks as $hook )
        {
            $effect_mapping = $this->filter_effect_mapping_for_form_source_capabilities(
                $form_source,
                $this->build_local_first_effect_mapping( $definition, $settings )
            );
            $payload = [
                'form_source'         => $form_source,
                'form_id'             => $form_id,
                'hook'                => $hook,
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'conditions_json'     => isset( $settings['conditions'] ) && is_array( $settings['conditions'] )
                    ? $settings['conditions']
                    : null,
                'input_bindings_json' => isset( $settings['input_mapping'] ) && is_array( $settings['input_mapping'] )
                    ? $settings['input_mapping']
                    : [],
                'execution_mode'      => $this->resolve_local_first_execution_mode_for_hook( $hook, $settings, $definition ),
                'effect_mapping_json' => $effect_mapping,
                'settings_json'       => $this->build_local_first_runtime_settings( $settings ),
                'enabled'             => $enabled,
            ];

            $existing = $this->find_existing_local_first_mapping( $form_source, $form_id, $hook, $action_id );
            $pending_mappings[] = [
                'payload'     => $payload,
                'existing'    => is_array( $existing ) ? $existing : null,
                'existing_id' => is_array( $existing ) ? absint( $existing['id'] ?? 0 ) : null,
            ];
        }

        $dependency_validation = $this->validate_local_first_mapping_dependencies_for_rows(
            $form_source,
            $form_id,
            $pending_mappings
        );
        if ( is_wp_error( $dependency_validation ) )
        {
            return $dependency_validation;
        }

        foreach ( $pending_mappings as $pending_mapping )
        {
            $payload  = $pending_mapping['payload'];
            $existing = $pending_mapping['existing'];

            $row      = is_array( $existing )
                ? $this->local_form_mappings->update( absint( $existing['id'] ?? 0 ), $payload )
                : $this->local_form_mappings->create( $payload );

            if ( is_wp_error( $row ) )
            {
                return $row;
            }

            if ( ! is_array( $row ) )
            {
                $row = $this->local_form_mappings->get( (int) $row );
            }

            if ( ! is_array( $row ) )
            {
                return $this->prepare_error_response(
                    'rest_local_first_mapping_not_found',
                    __( 'Local form mapping could not be created.', 'sentient-forms' ),
                    500
                );
            }

            if ( null === $first_row )
            {
                $first_row = $row;
            }
        }

        if ( ! is_array( $first_row ) )
        {
            return $this->prepare_error_response(
                'rest_local_first_mapping_not_created',
                __( 'Local custom action could not be linked to the form.', 'sentient-forms' ),
                500
            );
        }

        $linkage = $this->transform_local_first_mapping_to_linkage( $first_row );
        if ( null === $linkage )
        {
            return $this->prepare_error_response(
                'rest_local_first_mapping_invalid',
                __( 'Local custom action mapping could not be normalized.', 'sentient-forms' ),
                500
            );
        }

        return $this->prepare_item_for_response( $linkage, 201 );
    }

    private function create_bundled_local_first_action( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $template_code = sanitize_key( (string) $request->get_param( 'central_action_id' ) );
        $definition    = Sentient_Forms_Bundled_Action_Templates::get( $template_code );
        if ( ! is_array( $definition ) )
        {
            return $this->prepare_error_response(
                'rest_invalid_bundled_action',
                __( 'Bundled action template could not be resolved.', 'sentient-forms' ),
                400
            );
        }

        if ( $request->has_param( 'settings' ) )
        {
            $settings_validation = $this->validate_settings_write_payload( $request->get_param( 'settings' ) );
            if ( is_wp_error( $settings_validation ) )
            {
                return $settings_validation;
            }
        }

        $form_source  = sanitize_key( (string) $request->get_param( 'form_source_slug' ) );
        $form_id      = $this->get_request_form_id( $request );
        $settings        = $request->has_param( 'settings' ) ? $this->sanitize_settings( $request->get_param( 'settings' ) ) : [];
        $storage_validation = $this->validate_realtime_storage_target( $form_source, $form_id, $settings );
        if ( is_wp_error( $storage_validation ) )
        {
            return $storage_validation;
        }

        $definition_hooks = Sentient_Forms_Form_Source_Lifecycles::normalize_many(
            is_array( $definition['hooks'] ?? null ) ? $definition['hooks'] : []
        );
        $requested_hooks  = $this->sanitize_trigger_hooks( (array) $request->get_param( 'trigger_hooks' ) );
        $trigger_hooks    = $requested_hooks;

        if ( [] !== $requested_hooks && [] !== $definition_hooks )
        {
            $trigger_hooks = array_values( array_intersect( $requested_hooks, $definition_hooks ) );
            if ( [] === $trigger_hooks )
            {
                return $this->prepare_error_response(
                    'rest_invalid_bundled_action_hooks',
                    __( 'Selected trigger hooks are not supported by this bundled action template.', 'sentient-forms' ),
                    400
                );
            }
        }

        if ( [] === $trigger_hooks )
        {
            $trigger_hooks = $definition_hooks;
        }

        if ( [] === $trigger_hooks )
        {
            return $this->prepare_error_response(
                'rest_invalid_bundled_action_hooks',
                __( 'Bundled action template does not expose any supported trigger hooks.', 'sentient-forms' ),
                400
            );
        }

        $lifecycle_validation = $this->validate_form_source_trigger_hooks( $form_source, $trigger_hooks );
        if ( is_wp_error( $lifecycle_validation ) )
        {
            return $lifecycle_validation;
        }

        $realtime_policy = $this->validate_realtime_trigger_policy( $trigger_hooks, $template_code, $settings );
        if ( is_wp_error( $realtime_policy ) )
        {
            return $realtime_policy;
        }

        $template_row = $this->ensure_bundled_action_template_row( $template_code, $definition );
        if ( is_wp_error( $template_row ) )
        {
            return $template_row;
        }

        $custom_action = $this->ensure_bundled_local_custom_action( $template_row, $definition );
        if ( is_wp_error( $custom_action ) )
        {
            return $custom_action;
        }

        $enabled    = $request->has_param( 'is_action_enabled_for_form' )
            ? rest_sanitize_boolean( $request->get_param( 'is_action_enabled_for_form' ) )
            : true;
        $first_row        = null;
        $action_id        = absint( $custom_action['id'] ?? 0 );
        $pending_mappings = [];

        foreach ( $trigger_hooks as $hook )
        {
            $effect_mapping = $this->filter_effect_mapping_for_form_source_capabilities(
                $form_source,
                $this->build_local_first_effect_mapping( $definition, $settings )
            );
            $payload = [
                'form_source'         => $form_source,
                'form_id'             => $form_id,
                'hook'                => $hook,
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'conditions_json'     => isset( $settings['conditions'] ) && is_array( $settings['conditions'] )
                    ? $settings['conditions']
                    : null,
                'input_bindings_json' => isset( $settings['input_mapping'] ) && is_array( $settings['input_mapping'] )
                    ? $settings['input_mapping']
                    : [],
                'execution_mode'      => $this->resolve_local_first_execution_mode_for_hook( $hook, $settings, $definition ),
                'effect_mapping_json' => $effect_mapping,
                'settings_json'       => $this->build_local_first_runtime_settings( $settings ),
                'enabled'             => $enabled,
            ];

            $existing = $this->find_existing_local_first_mapping( $form_source, $form_id, $hook, $action_id );
            $pending_mappings[] = [
                'payload'     => $payload,
                'existing'    => is_array( $existing ) ? $existing : null,
                'existing_id' => is_array( $existing ) ? absint( $existing['id'] ?? 0 ) : null,
            ];
        }

        $dependency_validation = $this->validate_local_first_mapping_dependencies_for_rows(
            $form_source,
            $form_id,
            $pending_mappings
        );
        if ( is_wp_error( $dependency_validation ) )
        {
            return $dependency_validation;
        }

        foreach ( $pending_mappings as $pending_mapping )
        {
            $payload  = $pending_mapping['payload'];
            $existing = $pending_mapping['existing'];

            $row      = is_array( $existing )
                ? $this->local_form_mappings->update( absint( $existing['id'] ?? 0 ), $payload )
                : $this->local_form_mappings->create( $payload );

            if ( is_wp_error( $row ) )
            {
                return $row;
            }

            if ( ! is_array( $row ) )
            {
                $row = $this->local_form_mappings->get( (int) $row );
            }

            if ( ! is_array( $row ) )
            {
                return $this->prepare_error_response(
                    'rest_local_first_mapping_not_found',
                    __( 'Local form mapping could not be created.', 'sentient-forms' ),
                    500
                );
            }

            if ( null === $first_row )
            {
                $first_row = $row;
            }
        }

        if ( ! is_array( $first_row ) )
        {
            return $this->prepare_error_response(
                'rest_local_first_mapping_not_created',
                __( 'Bundled action could not be linked to the form.', 'sentient-forms' ),
                500
            );
        }

        $linkage = $this->transform_local_first_mapping_to_linkage( $first_row );
        if ( null === $linkage )
        {
            return $this->prepare_error_response(
                'rest_local_first_mapping_invalid',
                __( 'Bundled action mapping could not be normalized.', 'sentient-forms' ),
                500
            );
        }

        return $this->prepare_item_for_response( $linkage, 201 );
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>|WP_Error
     */
    private function ensure_bundled_action_template_row( string $template_code, array $definition ): array | WP_Error
    {
        if ( ! $this->local_action_templates )
        {
            return $this->prepare_error_response(
                'rest_local_templates_unavailable',
                __( 'Local action templates are unavailable.', 'sentient-forms' ),
                503
            );
        }

        $template_row = $this->local_action_templates->get_by_code( $template_code );
        if ( is_array( $template_row ) )
        {
            return $template_row;
        }

        $template_id = $this->local_action_templates->upsert_by_code(
            [
                'source'                   => $definition['source'] ?? 'bundled',
                'code'                     => $template_code,
                'display_name'             => $definition['display_name'] ?? $template_code,
                'description'              => $definition['description'] ?? null,
                'prompt_template'          => $definition['prompt_template'] ?? '',
                'default_model'            => $definition['default_model'] ?? 'openrouter/auto',
                'structured_output_schema' => $definition['structured_output_schema'] ?? null,
                'override_schema'          => $definition['override_schema'] ?? null,
                'version'                  => $definition['version'] ?? '1',
                'is_active'                => ! empty( $definition['is_active'] ),
            ]
        );
        if ( is_wp_error( $template_id ) )
        {
            return $template_id;
        }

        $template_row = $this->local_action_templates->get( (int) $template_id );
        if ( ! is_array( $template_row ) )
        {
            return $this->prepare_error_response(
                'rest_local_template_not_found',
                __( 'Bundled action template could not be loaded after creation.', 'sentient-forms' ),
                500
            );
        }

        return $template_row;
    }

    /**
     * @param array<string, mixed> $template_row
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>|WP_Error
     */
    private function ensure_bundled_local_custom_action( array $template_row, array $definition ): array | WP_Error
    {
        if ( ! $this->local_custom_actions )
        {
            return $this->prepare_error_response(
                'rest_local_custom_actions_unavailable',
                __( 'Local custom actions are unavailable.', 'sentient-forms' ),
                503
            );
        }

        $template_code = isset( $template_row['code'] ) && is_scalar( $template_row['code'] )
            ? sanitize_key( (string) $template_row['code'] )
            : '';
        if ( '' === $template_code )
        {
            return $this->prepare_error_response(
                'rest_local_template_missing_code',
                __( 'Bundled action template is missing its code.', 'sentient-forms' ),
                500
            );
        }

        $managed_code = Sentient_Forms_Bundled_Action_Templates::build_managed_custom_action_code( $template_code );
        $selection    = $this->build_default_model_selection_for_bundled_action( $definition );
        if ( is_wp_error( $selection ) )
        {
            return $selection;
        }

        $row_id       = $this->local_custom_actions->upsert_by_code(
            [
                'template_id'          => absint( $template_row['id'] ?? 0 ),
                'code'                 => $managed_code,
                'display_name'         => $definition['display_name'] ?? $template_code,
                'definition_json'      => $this->build_bundled_local_custom_action_definition( $definition ),
                'model_selection_json' => $selection,
                'status'               => 'active',
            ]
        );
        if ( is_wp_error( $row_id ) )
        {
            return $row_id;
        }

        $row = $this->local_custom_actions->get( (int) $row_id );
        if ( ! is_array( $row ) )
        {
            return $this->prepare_error_response(
                'rest_local_custom_action_not_found',
                __( 'Bundled local action could not be loaded after creation.', 'sentient-forms' ),
                500
            );
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function build_bundled_local_custom_action_definition( array $definition ): array
    {
        $action_definition = is_array( $definition['definition_json'] ?? null ) ? $definition['definition_json'] : [];
        if ( isset( $definition['code'] ) && is_scalar( $definition['code'] ) )
        {
            $action_definition['code'] = sanitize_key( (string) $definition['code'] );
        }

        if ( isset( $definition['description'] ) && is_scalar( $definition['description'] ) )
        {
            $action_definition['description'] = sanitize_textarea_field( (string) $definition['description'] );
        }

        return $action_definition;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>|WP_Error
     */
    private function build_default_model_selection_for_bundled_action( array $definition ): array | WP_Error
    {
        if ( ! $this->provider_path_policy )
        {
            return $this->prepare_error_response(
                'rest_provider_path_policy_unavailable',
                __( 'Provider path policy is unavailable.', 'sentient-forms' ),
                503
            );
        }

        return $this->provider_path_policy->build_bundled_action_model_selection( $definition );
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>|null
     */
    private function build_local_first_runtime_settings( array $settings ): ?array
    {
        $column_backed_keys = [
            'conditions',
            'effect_mapping_json',
            'execution_mode',
            'input_mapping',
            'is_action_enabled_for_form',
            'linked_action_status',
            'local_form_mapping_id',
            'repair_state',
            'spam_indicators_display',
            'spam_result_display_mode',
            'suppress_notifications_on_spam',
            'suppress_webhooks_on_spam',
            'skip_downstream_on_spam',
            'trigger_hooks',
        ];

        $runtime_settings = [];
        foreach ( $settings as $key => $value )
        {
            $key = sanitize_key( (string) $key );
            if ( '' === $key || in_array( $key, $column_backed_keys, true ) )
            {
                continue;
            }

            $runtime_settings[ $key ] = $value;
        }

        return [] === $runtime_settings ? null : $runtime_settings;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function build_local_first_effect_mapping( array $definition, array $settings ): array
    {
        $effect_mapping = is_array( $definition['effect_mapping_json'] ?? null ) ? $definition['effect_mapping_json'] : [];
        if ( isset( $settings['effect_mapping_json'] ) && is_array( $settings['effect_mapping_json'] ) )
        {
            $effect_mapping = array_replace_recursive( $effect_mapping, $settings['effect_mapping_json'] );
        }

        $template_code = sanitize_key( (string) ( $definition['code'] ?? '' ) );
        if ( 'spam_detection_v1' === $template_code )
        {
            if ( ! isset( $effect_mapping['spam'] ) || ! is_array( $effect_mapping['spam'] ) )
            {
                $effect_mapping['spam'] = [];
            }

            if ( array_key_exists( 'suppress_notifications_on_spam', $settings ) )
            {
                $effect_mapping['spam']['suppress_notifications_on_spam'] = rest_sanitize_boolean( $settings['suppress_notifications_on_spam'] );
            }

            if ( array_key_exists( 'suppress_webhooks_on_spam', $settings ) )
            {
                $effect_mapping['spam']['suppress_webhooks_on_spam'] = rest_sanitize_boolean( $settings['suppress_webhooks_on_spam'] );
            }

            if ( array_key_exists( 'skip_downstream_on_spam', $settings ) )
            {
                $effect_mapping['spam']['skip_downstream_on_spam'] = rest_sanitize_boolean( $settings['skip_downstream_on_spam'] );
            }

            if ( array_key_exists( 'spam_confidence_threshold', $settings ) && is_numeric( $settings['spam_confidence_threshold'] ) )
            {
                $effect_mapping['spam']['min_confidence'] = max( 0, min( 1, (float) $settings['spam_confidence_threshold'] ) );
            }

            $note = is_array( $effect_mapping['spam']['note'] ?? null ) ? $effect_mapping['spam']['note'] : [];
            if ( array_key_exists( 'spam_result_display_mode', $settings ) )
            {
                $note['result_display_mode'] = $this->normalize_spam_result_display_mode( $settings['spam_result_display_mode'] );
            }
            if ( array_key_exists( 'spam_indicators_display', $settings ) )
            {
                $note['indicators_display'] = $this->normalize_spam_indicators_display( $settings['spam_indicators_display'] );
            }
            if ( [] !== $note )
            {
                $effect_mapping['spam']['note'] = $note;
            }
        }

        return $effect_mapping;
    }

    /**
     * Remove provider-native effects a form source descriptor does not support.
     *
     * @param array<string, mixed> $effect_mapping
     *
     * @return array<string, mixed>
     */
    private function filter_effect_mapping_for_form_source_capabilities( string $form_source_slug, array $effect_mapping ): array
    {
        if ( [] === $effect_mapping )
        {
            return [];
        }

        $descriptor = $this->get_form_source_descriptor( $form_source_slug );
        if ( ! is_array( $descriptor ) )
        {
            return $effect_mapping;
        }

        $native_entry = isset( $descriptor['native_entry'] ) && is_array( $descriptor['native_entry'] )
            ? $descriptor['native_entry']
            : [];
        if ( empty( $native_entry['write'] ) )
        {
            unset(
                $effect_mapping['store_result'],
                $effect_mapping['store_result_meta'],
                $effect_mapping['meta']
            );
        }

        $native_enrichment = isset( $descriptor['native_enrichment'] ) && is_array( $descriptor['native_enrichment'] )
            ? $descriptor['native_enrichment']
            : [];
        if ( empty( $native_enrichment['notes'] ) )
        {
            unset( $effect_mapping['entry_note'] );

            if ( isset( $effect_mapping['spam'] ) && is_array( $effect_mapping['spam'] ) )
            {
                unset( $effect_mapping['spam']['note'] );
            }
        }

        if ( empty( $native_enrichment['spam'] ) || empty( $native_enrichment['status'] ) )
        {
            $skip_downstream_on_spam = null;
            if (
                isset( $effect_mapping['spam'] )
                && is_array( $effect_mapping['spam'] )
                && array_key_exists( 'skip_downstream_on_spam', $effect_mapping['spam'] )
            )
            {
                $skip_downstream_on_spam = rest_sanitize_boolean( $effect_mapping['spam']['skip_downstream_on_spam'] );
            }

            unset(
                $effect_mapping['spam'],
                $effect_mapping['mark_as_spam']
            );

            if ( null !== $skip_downstream_on_spam )
            {
                $effect_mapping['spam'] = [
                    'skip_downstream_on_spam' => $skip_downstream_on_spam,
                ];
            }
        }
        elseif ( isset( $effect_mapping['spam'] ) && is_array( $effect_mapping['spam'] ) )
        {
            if ( empty( $native_enrichment['notification_controls'] ) )
            {
                unset( $effect_mapping['spam']['suppress_notifications_on_spam'] );
            }

            if ( empty( $native_enrichment['webhook_controls'] ) )
            {
                unset( $effect_mapping['spam']['suppress_webhooks_on_spam'] );
            }
        }

        if ( empty( $native_enrichment['notification_controls'] ) )
        {
            unset( $effect_mapping['suppress_notifications_on_spam'] );
        }

        if ( empty( $native_enrichment['webhook_controls'] ) )
        {
            unset( $effect_mapping['suppress_webhooks_on_spam'] );
        }

        return $effect_mapping;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $effect_mapping
     *
     * @return array<string, mixed>
     */
    private function hydrate_spam_settings_from_effect_mapping( array $settings, array $effect_mapping ): array
    {
        $spam = is_array( $effect_mapping['spam'] ?? null ) ? $effect_mapping['spam'] : [];
        if ( [] === $spam )
        {
            return $settings;
        }

        if ( array_key_exists( 'suppress_notifications_on_spam', $spam ) )
        {
            $settings['suppress_notifications_on_spam'] = rest_sanitize_boolean( $spam['suppress_notifications_on_spam'] );
        }

        if ( array_key_exists( 'suppress_webhooks_on_spam', $spam ) )
        {
            $settings['suppress_webhooks_on_spam'] = rest_sanitize_boolean( $spam['suppress_webhooks_on_spam'] );
        }

        if ( array_key_exists( 'skip_downstream_on_spam', $spam ) )
        {
            $settings['skip_downstream_on_spam'] = rest_sanitize_boolean( $spam['skip_downstream_on_spam'] );
        }

        if ( array_key_exists( 'min_confidence', $spam ) && is_numeric( $spam['min_confidence'] ) )
        {
            $settings['spam_confidence_threshold'] = max( 0, min( 1, (float) $spam['min_confidence'] ) );
        }

        $note = is_array( $spam['note'] ?? null ) ? $spam['note'] : [];
        if ( array_key_exists( 'result_display_mode', $note ) )
        {
            $settings['spam_result_display_mode'] = $this->normalize_spam_result_display_mode( $note['result_display_mode'] );
        }

        if ( array_key_exists( 'indicators_display', $note ) )
        {
            $settings['spam_indicators_display'] = $this->normalize_spam_indicators_display( $note['indicators_display'] );
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function settings_include_spam_effect_fields( array $settings ): bool
    {
        foreach ( [ 'suppress_notifications_on_spam', 'suppress_webhooks_on_spam', 'skip_downstream_on_spam', 'spam_confidence_threshold', 'spam_result_display_mode', 'spam_indicators_display' ] as $key )
        {
            if ( array_key_exists( $key, $settings ) )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $effect_mapping
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function merge_spam_settings_into_effect_mapping( array $effect_mapping, array $settings ): array
    {
        if ( ! isset( $effect_mapping['spam'] ) || ! is_array( $effect_mapping['spam'] ) )
        {
            $effect_mapping['spam'] = [];
        }

        if ( array_key_exists( 'suppress_notifications_on_spam', $settings ) )
        {
            $effect_mapping['spam']['suppress_notifications_on_spam'] = rest_sanitize_boolean( $settings['suppress_notifications_on_spam'] );
        }

        if ( array_key_exists( 'suppress_webhooks_on_spam', $settings ) )
        {
            $effect_mapping['spam']['suppress_webhooks_on_spam'] = rest_sanitize_boolean( $settings['suppress_webhooks_on_spam'] );
        }

        if ( array_key_exists( 'skip_downstream_on_spam', $settings ) )
        {
            $effect_mapping['spam']['skip_downstream_on_spam'] = rest_sanitize_boolean( $settings['skip_downstream_on_spam'] );
        }

        if ( array_key_exists( 'spam_confidence_threshold', $settings ) && is_numeric( $settings['spam_confidence_threshold'] ) )
        {
            $effect_mapping['spam']['min_confidence'] = max( 0, min( 1, (float) $settings['spam_confidence_threshold'] ) );
        }

        $note = is_array( $effect_mapping['spam']['note'] ?? null ) ? $effect_mapping['spam']['note'] : [];
        if ( array_key_exists( 'spam_result_display_mode', $settings ) )
        {
            $note['result_display_mode'] = $this->normalize_spam_result_display_mode( $settings['spam_result_display_mode'] );
        }
        if ( array_key_exists( 'spam_indicators_display', $settings ) )
        {
            $note['indicators_display'] = $this->normalize_spam_indicators_display( $settings['spam_indicators_display'] );
        }
        if ( [] !== $note )
        {
            $effect_mapping['spam']['note'] = $note;
        }

        return $effect_mapping;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $definition
     */
    private function resolve_local_first_execution_mode_for_hook( string $hook, array $settings, array $definition ): string
    {
        $hook = sanitize_key( $hook );
        if ( 'real_time' === $hook )
        {
            return 'real_time';
        }

        if ( Sentient_Forms_Form_Source_Lifecycles::VALIDATION === $hook )
        {
            return 'sync';
        }

        $configured = isset( $settings['execution_mode'] ) && is_scalar( $settings['execution_mode'] )
            ? sanitize_key( (string) $settings['execution_mode'] )
            : sanitize_key( (string) ( $definition['default_execution_mode'] ?? 'async' ) );

        return 'validation' === $configured || 'sync' === $configured ? 'sync' : 'async';
    }

    private function find_existing_local_first_mapping( string $form_source, string $form_id, string $hook, int $action_id ): ?array
    {
        if ( ! $this->local_form_mappings )
        {
            return null;
        }

        foreach ( $this->local_form_mappings->list_for_form( $form_source, $form_id ) as $candidate )
        {
            if ( 'custom_action' !== sanitize_key( (string) ( $candidate['action_kind'] ?? '' ) ) )
            {
                continue;
            }

            if ( absint( $candidate['action_id'] ?? 0 ) !== $action_id )
            {
                continue;
            }

            if ( sanitize_key( (string) ( $candidate['hook'] ?? '' ) ) !== sanitize_key( $hook ) )
            {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    private function normalize_spam_result_display_mode( mixed $value ): string
    {
        $value = sanitize_key( (string) $value );

        return match ( $value ) {
            'none',
            'spam_only',
            'all_results' => $value,
            default      => 'all_results',
        };
    }

    private function normalize_spam_indicators_display( mixed $value ): string
    {
        return 'detailed' === sanitize_key( (string) $value ) ? 'detailed' : 'simple';
    }

    /**
     * Retrieve a specific action linkage.
     */
    public function get_form_action_item( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $actions = $this->get_actions_option( $request->get_param( 'form_source_slug' ), $this->get_request_form_id( $request ) );
        $id      = $request->get_param( 'local_mapping_id' );
        if ( isset( $actions[ $id ] ) )
        {
            return $this->prepare_item_for_response( $actions[ $id ] );
        }

        $local_first_row = $this->get_local_first_mapping_row_for_request( $request );
        if ( $local_first_row )
        {
            $linkage = $this->transform_local_first_mapping_to_linkage( $local_first_row );
            if ( null !== $linkage )
            {
                return $this->prepare_item_for_response( $linkage );
            }
        }

        return $this->prepare_error_response( 'rest_action_not_found', __( 'Action linkage not found.', 'sentient-forms' ), 404 );
    }

    /**
     * Update an existing action linkage.
     */
    public function update_form_action_item( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id          = $this->get_request_form_id( $request );
        $option_key       = $this->get_actions_option_key( $form_source_slug, $form_id );
        $actions          = $this->get_actions_option( $form_source_slug, $form_id );
        $id               = $request->get_param( 'local_mapping_id' );
        $option_linkage   = $this->get_option_backed_action_linkage( $actions, (string) $id );
        if ( null === $option_linkage )
        {
            $local_first_row = $this->get_local_first_mapping_row_for_request( $request );
            if ( $local_first_row && $this->local_form_mappings )
            {
                $update = [];

                if ( $request->has_param( 'trigger_hooks' ) )
                {
                    $trigger_hooks = $this->sanitize_trigger_hooks( (array) $request->get_param( 'trigger_hooks' ) );
                    if ( count( $trigger_hooks ) !== 1 )
                    {
                        return $this->prepare_error_response(
                            'rest_invalid_local_first_trigger_hooks',
                            __( 'Local-first custom table mappings currently support exactly one trigger hook per mapping.', 'sentient-forms' ),
                            400
                        );
                    }

                    $update['hook'] = $trigger_hooks[0];
                }

                if ( $request->has_param( 'is_action_enabled_for_form' ) )
                {
                    $enabled = rest_sanitize_boolean( $request->get_param( 'is_action_enabled_for_form' ) );
                    if ( $enabled )
                    {
                        $repair = $this->repair_local_first_action_before_enable( $local_first_row );
                        if ( is_wp_error( $repair ) )
                        {
                            return $repair;
                        }
                    }

                    $update['enabled'] = $enabled;
                }

                if ( $request->has_param( 'settings' ) )
                {
                    $settings_validation = $this->validate_settings_write_payload( $request->get_param( 'settings' ) );
                    if ( is_wp_error( $settings_validation ) )
                    {
                        return $settings_validation;
                    }

                    $settings = $this->sanitize_settings( $request->get_param( 'settings' ) );
                    $storage_validation = $this->validate_realtime_storage_target(
                        sanitize_key( (string) $form_source_slug ),
                        $form_id,
                        $settings
                    );
                    if ( is_wp_error( $storage_validation ) )
                    {
                        return $storage_validation;
                    }

                    if ( isset( $settings['execution_mode'] ) && is_scalar( $settings['execution_mode'] ) )
                    {
                        $execution_mode = sanitize_key( (string) $settings['execution_mode'] );
                        if ( 'validation' === $execution_mode )
                        {
                            $update['execution_mode'] = 'sync';
                        }
                        elseif ( 'after_submission' === $execution_mode )
                        {
                            $update['execution_mode'] = 'async';
                        }
                        elseif ( 'real_time' === $execution_mode )
                        {
                            $update['execution_mode'] = 'real_time';
                        }
                    }

                    if ( array_key_exists( 'conditions', $settings ) && is_array( $settings['conditions'] ) )
                    {
                        $update['conditions_json'] = $settings['conditions'];
                    }

                    if ( array_key_exists( 'input_mapping', $settings ) && is_array( $settings['input_mapping'] ) )
                    {
                        $update['input_bindings_json'] = $settings['input_mapping'];
                    }

                    $has_effect_mapping_update = array_key_exists( 'effect_mapping_json', $settings ) && is_array( $settings['effect_mapping_json'] );
                    if ( $has_effect_mapping_update || $this->settings_include_spam_effect_fields( $settings ) )
                    {
                        $effect_mapping = $has_effect_mapping_update
                            ? $settings['effect_mapping_json']
                            : ( is_array( $local_first_row['effect_mapping_json'] ?? null ) ? $local_first_row['effect_mapping_json'] : [] );

                        if ( $this->settings_include_spam_effect_fields( $settings ) )
                        {
                            $effect_mapping = $this->merge_spam_settings_into_effect_mapping( $effect_mapping, $settings );
                        }

                        $update['effect_mapping_json'] = $this->filter_effect_mapping_for_form_source_capabilities(
                            sanitize_key( (string) $form_source_slug ),
                            $effect_mapping
                        );
                    }

                    $update['settings_json'] = $this->build_local_first_runtime_settings( $settings );
                }

                $policy_linkage = $this->transform_local_first_mapping_to_linkage( $local_first_row );
                $policy_action_id = is_array( $policy_linkage ) ? ( $policy_linkage['central_action_id'] ?? '' ) : '';
                $policy_trigger_hooks = $request->has_param( 'trigger_hooks' )
                    ? ( $trigger_hooks ?? [] )
                    : [ sanitize_key( (string) ( $local_first_row['hook'] ?? '' ) ) ];
                $policy_settings = $request->has_param( 'settings' )
                    ? ( $settings ?? [] )
                    : ( is_array( $local_first_row['settings_json'] ?? null ) ? $local_first_row['settings_json'] : [] );
                $lifecycle_validation = $this->validate_form_source_trigger_hooks(
                    sanitize_key( (string) $form_source_slug ),
                    $policy_trigger_hooks
                );
                if ( is_wp_error( $lifecycle_validation ) )
                {
                    return $lifecycle_validation;
                }

                $realtime_policy = $this->validate_realtime_trigger_policy(
                    $policy_trigger_hooks,
                    $policy_action_id,
                    $policy_settings
                );
                if ( is_wp_error( $realtime_policy ) )
                {
                    return $realtime_policy;
                }

                $dependency_validation = $this->validate_local_first_mapping_dependencies_for_row(
                    sanitize_key( (string) $request->get_param( 'form_source_slug' ) ),
                    $form_id,
                    array_merge( $local_first_row, $update ),
                    absint( $local_first_row['id'] ?? 0 )
                );
                if ( is_wp_error( $dependency_validation ) )
                {
                    return $dependency_validation;
                }

                $updated = $this->local_form_mappings->update(
                    absint( $local_first_row['id'] ?? 0 ),
                    $update
                );

                if ( is_wp_error( $updated ) )
                {
                    return $updated;
                }

                $linkage = $this->transform_local_first_mapping_to_linkage( $updated );
                if ( null !== $linkage )
                {
                    return $this->prepare_item_for_response( $linkage );
                }
            }

            return $this->prepare_error_response( 'rest_action_not_found', __( 'Action linkage not found to update.', 'sentient-forms' ), 404 );
        }

        $linkage = $option_linkage;

        if ( $request->has_param( 'central_action_id' ) )
        {
            $linkage[ 'central_action_id' ] = $request->get_param( 'central_action_id' );
        }
        if ( $request->has_param( 'action_type_indicator' ) )
        {
            $linkage[ 'action_type_indicator' ] = $request->get_param( 'action_type_indicator' );
        }
        if ( $request->has_param( 'trigger_hooks' ) )
        {
            $linkage[ 'trigger_hooks' ] = $this->sanitize_trigger_hooks( (array) $request->get_param( 'trigger_hooks' ) );
        }
        if ( $request->has_param( 'is_action_enabled_for_form' ) )
        {
            $linkage[ 'is_action_enabled_for_form' ] = (bool)$request->get_param( 'is_action_enabled_for_form' );
        }
        if ( $request->has_param( 'execution_priority' ) )
        {
            $linkage[ 'execution_priority' ] = (int)$request->get_param( 'execution_priority' );
        }
        if ( $request->has_param( 'action_name_label' ) )
        {
            $linkage[ 'action_name_label' ] = $request->get_param( 'action_name_label' );
        }
        if ( $request->has_param( 'settings' ) )
        {
            $settings_validation = $this->validate_settings_write_payload( $request->get_param( 'settings' ) );
            if ( is_wp_error( $settings_validation ) )
            {
                return $settings_validation;
            }

            $settings = $this->sanitize_settings( $request->get_param( 'settings' ) );
            $storage_validation = $this->validate_realtime_storage_target(
                sanitize_key( (string) $form_source_slug ),
                $form_id,
                $settings
            );
            if ( is_wp_error( $storage_validation ) )
            {
                return $storage_validation;
            }

            $linkage[ 'settings' ] = $settings;
        }

        $lifecycle_validation = $this->validate_form_source_trigger_hooks(
            sanitize_key( (string) $form_source_slug ),
            isset( $linkage['trigger_hooks'] ) && is_array( $linkage['trigger_hooks'] ) ? $linkage['trigger_hooks'] : []
        );
        if ( is_wp_error( $lifecycle_validation ) )
        {
            return $lifecycle_validation;
        }

        $realtime_policy = $this->validate_realtime_trigger_policy(
            isset( $linkage['trigger_hooks'] ) && is_array( $linkage['trigger_hooks'] ) ? $linkage['trigger_hooks'] : [],
            $linkage['central_action_id'] ?? '',
            isset( $linkage['settings'] ) && is_array( $linkage['settings'] ) ? $linkage['settings'] : []
        );
        if ( is_wp_error( $realtime_policy ) )
        {
            return $realtime_policy;
        }

        $actions_to_validate       = $actions;
        $actions_to_validate[ $id ] = $linkage;

        $dependency_validation = $this->validate_mapping_dependencies( $actions_to_validate );
        if ( is_wp_error( $dependency_validation ) )
        {
            return $this->prepare_error_response(
                $dependency_validation->get_error_code(),
                $dependency_validation->get_error_message(),
                400
            );
        }

        $actions = $this->upsert_option_backed_action_linkage( $actions, (string) $id, $linkage );
        update_option( $option_key, $actions, false );
        $this->sync_form_mappings_after_local_change(
            $form_source_slug,
            $form_id,
            $actions
        );

        return $this->prepare_item_for_response( $linkage );
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function repair_local_first_action_before_enable( array $mapping ): true | WP_Error
    {
        if ( ! $this->local_custom_actions )
        {
            return $this->prepare_error_response(
                'rest_local_custom_actions_unavailable',
                __( 'Local custom actions are unavailable.', 'sentient-forms' ),
                503
            );
        }

        $action_id = absint( $mapping['action_id'] ?? 0 );
        if ( $action_id <= 0 )
        {
            return $this->prepare_error_response(
                'rest_local_first_mapping_needs_repair',
                __( 'This local action mapping is missing its linked action and cannot be enabled until it is repaired.', 'sentient-forms' ),
                409
            );
        }

        $custom_action = $this->local_custom_actions->get( $action_id );
        if ( ! is_array( $custom_action ) )
        {
            return $this->prepare_error_response(
                'rest_local_first_mapping_needs_repair',
                __( 'This local action mapping points to an action that no longer exists and cannot be enabled until it is repaired.', 'sentient-forms' ),
                409
            );
        }

        $status = isset( $custom_action['status'] ) && is_scalar( $custom_action['status'] )
            ? sanitize_key( (string) $custom_action['status'] )
            : '';

        if ( 'active' === $status )
        {
            return true;
        }

        $updated = $this->local_custom_actions->update_status( $action_id, 'active' );
        if ( ! $updated )
        {
            return $this->prepare_error_response(
                'rest_local_first_action_reactivate_failed',
                __( 'This local action could not be reactivated, so the mapping was not enabled.', 'sentient-forms' ),
                500
            );
        }

        return true;
    }

    /**
     * Duplicate an existing action mapping and insert it under a selected parent.
     *
     * The duplicate copies the original mapping configuration (except local_mapping_id), then
     * rewires parent pre-existing children for the selected hook to run through the duplicate.
     * Incompatible rewires are skipped and reported in the response.
     */
    public function duplicate_form_action_item( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id          = $this->get_request_form_id( $request );
        $option_key       = $this->get_actions_option_key( $form_source_slug, $form_id );
        $actions          = $this->get_actions_option( $form_source_slug, $form_id );

        $source_id = sanitize_text_field( (string) $request->get_param( 'local_mapping_id' ) );
        if ( '' === $source_id || ! isset( $actions[ $source_id ] ) || ! is_array( $actions[ $source_id ] ) )
        {
            return $this->prepare_error_response(
                'rest_action_not_found',
                __( 'Action linkage not found to duplicate.', 'sentient-forms' ),
                404
            );
        }

        $parent = $this->sanitize_duplicate_parent_request( $request->get_param( 'parent' ) );
        if ( is_wp_error( $parent ) )
        {
            return $this->prepare_error_response(
                $parent->get_error_code(),
                $parent->get_error_message(),
                400
            );
        }

        $source_mapping      = $actions[ $source_id ];
        $source_trigger_hooks = $this->sanitize_trigger_hooks( (array) ( $source_mapping['trigger_hooks'] ?? [] ) );
        $target_hook         = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $parent['hook'] ?? null ) ?? '';
        if ( ! in_array( $target_hook, $source_trigger_hooks, true ) )
        {
            return $this->prepare_error_response(
                'rest_invalid_duplicate_parent_hook',
                sprintf(
                    /* translators: %s: hook id */
                    __( 'Duplicate insertion hook %s is not configured on the source mapping.', 'sentient-forms' ),
                    sanitize_text_field( $target_hook )
                ),
                400
            );
        }

        if ( 'mapping' === $parent['type'] )
        {
            $parent_mapping_id = sanitize_text_field( (string) ( $parent['mapping_id'] ?? '' ) );
            if ( '' === $parent_mapping_id || ! isset( $actions[ $parent_mapping_id ] ) || ! is_array( $actions[ $parent_mapping_id ] ) )
            {
                return $this->prepare_error_response(
                    'rest_invalid_duplicate_parent',
                    __( 'Selected parent mapping does not exist.', 'sentient-forms' ),
                    400
                );
            }

            $parent_mapping_hooks = $this->sanitize_trigger_hooks( (array) ( $actions[ $parent_mapping_id ]['trigger_hooks'] ?? [] ) );
            if ( ! $this->dependency_satisfies_hook( $target_hook, $parent_mapping_hooks ) )
            {
                return $this->prepare_error_response(
                    'rest_invalid_duplicate_parent_hooks',
                    __( 'Selected parent mapping does not satisfy the selected hook.', 'sentient-forms' ),
                    400
                );
            }

            if ( Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION === $target_hook )
            {
                $parent_is_async = $this->is_mapping_async( $actions[ $parent_mapping_id ] );
                $source_is_async = $this->is_mapping_async( $source_mapping );
                if ( $parent_is_async && ! $source_is_async )
                {
                    return $this->prepare_error_response(
                        'rest_invalid_duplicate_parent_execution_mode',
                        __( 'Selected parent mapping runs in Background during after-submission, but the source mapping does not.', 'sentient-forms' ),
                        400
                    );
                }
            }
        }

        $new_id = uniqid( 'map_', false );
        while ( isset( $actions[ $new_id ] ) )
        {
            $new_id = uniqid( 'map_', false );
        }

        $duplicate = $source_mapping;
        $duplicate['local_mapping_id'] = $new_id;
        $duplicate['trigger_hooks'] = $source_trigger_hooks;
        if ( ! array_key_exists( 'is_action_enabled_for_form', $duplicate ) )
        {
            $duplicate['is_action_enabled_for_form'] = true;
        }
        if ( ! isset( $duplicate['settings'] ) || ! is_array( $duplicate['settings'] ) )
        {
            $duplicate['settings'] = [];
        }

        $parent_source = 'mapping' === $parent['type']
            ? [
                'type'       => 'mapping',
                'mapping_id' => sanitize_text_field( (string) ( $parent['mapping_id'] ?? '' ) ),
            ]
            : [
                'type' => 'hook_root',
            ];
        $duplicate = $this->set_mapping_trigger_source_for_hook( $duplicate, $target_hook, $parent_source );
        if ( is_wp_error( $duplicate ) )
        {
            return $this->prepare_error_response(
                $duplicate->get_error_code(),
                $duplicate->get_error_message(),
                400
            );
        }

        $candidate = $actions;
        $candidate[ $new_id ] = $duplicate;
        $duplicate_validation = $this->validate_mapping_dependencies( $candidate );
        if ( is_wp_error( $duplicate_validation ) )
        {
            return $this->prepare_error_response(
                $duplicate_validation->get_error_code(),
                $duplicate_validation->get_error_message(),
                400
            );
        }

        $planner = Sentient_Forms_Plugin::instance()->get_mapping_dependency_planner();
        $children_to_move = $this->find_parent_children_for_hook( $actions, $parent, $target_hook, $planner );
        $moved_children = [];
        $skipped_children = [];
        $working = $candidate;

        foreach ( $children_to_move as $child_id )
        {
            if ( ! isset( $working[ $child_id ] ) || ! is_array( $working[ $child_id ] ) )
            {
                continue;
            }

            $next_child = $this->set_mapping_trigger_source_for_hook(
                $working[ $child_id ],
                $target_hook,
                [
                    'type'       => 'mapping',
                    'mapping_id' => $new_id,
                ]
            );
            if ( is_wp_error( $next_child ) )
            {
                $skipped_children[] = [
                    'child_id' => $child_id,
                    'hook'     => $target_hook,
                    'code'     => 'policy_violation',
                    'message'  => $next_child->get_error_message(),
                ];
                continue;
            }

            $child_candidate = $working;
            $child_candidate[ $child_id ] = $next_child;
            $child_validation = $this->validate_mapping_dependencies( $child_candidate );
            if ( is_wp_error( $child_validation ) )
            {
                $skipped_children[] = [
                    'child_id' => $child_id,
                    'hook'     => $target_hook,
                    'code'     => $this->map_dependency_validation_error_to_skip_code( $child_validation->get_error_code() ),
                    'message'  => $child_validation->get_error_message(),
                ];
                continue;
            }

            $working[ $child_id ] = $next_child;
            $moved_children[] = $child_id;
        }

        $final_validation = $this->validate_mapping_dependencies( $working );
        if ( is_wp_error( $final_validation ) )
        {
            return $this->prepare_error_response(
                $final_validation->get_error_code(),
                $final_validation->get_error_message(),
                400
            );
        }

        update_option( $option_key, $working, false );
        $this->sync_form_mappings_after_local_change(
            $form_source_slug,
            $form_id,
            $working
        );

        $warnings = [];
        if ( ! empty( $skipped_children ) )
        {
            $warnings[] = __( 'Some parent children could not be rewired due to dependency policy constraints.', 'sentient-forms' );
        }

        return $this->prepare_item_for_response(
            [
                'duplicate' => $working[ $new_id ],
                'insertion' => [
                    'parent'           => $parent,
                    'moved_children'   => array_values( $moved_children ),
                    'skipped_children' => array_values( $skipped_children ),
                    'warnings'         => $warnings,
                ],
            ],
            201
        );
    }

    /**
     * Remove references to a deleted mapping from option-backed mappings.
     *
     * @param array<string, mixed> $actions Stored per-form option payload.
     * @param string               $id      Deleted mapping id.
     *
     * @return array<string, mixed>
     */
    private function remove_dependency_references_from_actions( array $actions, string $id ): array
    {
        $planner = Sentient_Forms_Plugin::instance()->get_mapping_dependency_planner();

        foreach ( $actions as &$mapping )
        {
            if ( ! is_array( $mapping ) )
            {
                continue;
            }

            if ( ! isset( $mapping['settings'] ) || ! is_array( $mapping['settings'] ) )
            {
                continue;
            }

            $trigger_hooks = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
            $trigger_sources = $this->build_mapping_trigger_sources_for_hooks( $mapping, $trigger_hooks, $planner );
            $rewired_to_unbound = false;

            foreach ( $trigger_sources as $hook => $source )
            {
                if (
                    ! is_array( $source ) ||
                    ! isset( $source['type'], $source['mapping_id'] ) ||
                    'mapping' !== $source['type'] ||
                    $id !== $source['mapping_id']
                )
                {
                    continue;
                }

                $trigger_sources[ $hook ] = [ 'type' => 'unbound' ];
                $rewired_to_unbound = true;
            }

            if ( $rewired_to_unbound )
            {
                $mapping['settings']['trigger_sources'] = $this->serialize_trigger_sources_for_storage( $trigger_sources );
                $dependency_ids = $this->derive_dependency_ids_from_trigger_sources( $trigger_sources );
                if ( empty( $dependency_ids ) )
                {
                    unset( $mapping['settings']['dependency_ids'] );
                }
                else
                {
                    $mapping['settings']['dependency_ids'] = $dependency_ids;
                }
                continue;
            }

            if ( ! isset( $mapping['settings']['dependency_ids'] ) || ! is_array( $mapping['settings']['dependency_ids'] ) )
            {
                continue;
            }

            $dependency_ids = $this->sanitize_dependency_ids( $mapping['settings']['dependency_ids'] );
            $mapping['settings']['dependency_ids'] = array_values(
                array_filter(
                    $dependency_ids,
                    static fn( string $dependency_id ): bool => $dependency_id !== $id
                )
            );
        }
        unset( $mapping );

        return $actions;
    }

    private function remove_dependency_references_from_local_first_mappings( string $form_source_slug, string $form_id, string $id ): true | WP_Error
    {
        if ( ! $this->local_form_mappings )
        {
            return true;
        }

        $planner = Sentient_Forms_Plugin::instance()->get_mapping_dependency_planner();
        foreach ( $this->local_form_mappings->list_for_form( $form_source_slug, $form_id ) as $row )
        {
            $row_id = absint( $row['id'] ?? 0 );
            if ( $row_id <= 0 )
            {
                continue;
            }

            $settings = is_array( $row['settings_json'] ?? null ) ? $row['settings_json'] : [];
            $trigger_hooks = $this->sanitize_trigger_hooks( [ (string) ( $row['hook'] ?? '' ) ] );
            if ( [] === $trigger_hooks )
            {
                continue;
            }

            $mapping = [
                'local_mapping_id' => $this->build_local_first_mapping_id( $row_id ),
                'central_action_id' => 'sentient_forms_local_custom_action',
                'trigger_hooks'    => $trigger_hooks,
                'settings'         => $settings,
            ];

            $trigger_sources = $this->build_mapping_trigger_sources_for_hooks( $mapping, $trigger_hooks, $planner );
            $rewired_to_unbound = false;

            foreach ( $trigger_sources as $hook => $source )
            {
                if (
                    ! is_array( $source ) ||
                    ! isset( $source['type'], $source['mapping_id'] ) ||
                    'mapping' !== $source['type'] ||
                    $id !== $source['mapping_id']
                )
                {
                    continue;
                }

                $trigger_sources[ $hook ] = [ 'type' => 'unbound' ];
                $rewired_to_unbound = true;
            }

            $changed = $rewired_to_unbound;
            if ( $rewired_to_unbound )
            {
                $settings['trigger_sources'] = $this->serialize_trigger_sources_for_storage( $trigger_sources );
                $dependency_ids = $this->derive_dependency_ids_from_trigger_sources( $trigger_sources );
                if ( empty( $dependency_ids ) )
                {
                    unset( $settings['dependency_ids'] );
                }
                else
                {
                    $settings['dependency_ids'] = $dependency_ids;
                }
            }
            elseif ( isset( $settings['dependency_ids'] ) && is_array( $settings['dependency_ids'] ) )
            {
                $dependency_ids = $this->sanitize_dependency_ids( $settings['dependency_ids'] );
                $updated_dependency_ids = array_values(
                    array_filter(
                        $dependency_ids,
                        static fn( string $dependency_id ): bool => $dependency_id !== $id
                    )
                );

                if ( $updated_dependency_ids !== $dependency_ids )
                {
                    $changed = true;
                    if ( empty( $updated_dependency_ids ) )
                    {
                        unset( $settings['dependency_ids'] );
                    }
                    else
                    {
                        $settings['dependency_ids'] = $updated_dependency_ids;
                    }
                }
            }

            if ( ! $changed )
            {
                continue;
            }

            $updated = $this->local_form_mappings->update( $row_id, [ 'settings_json' => $settings ] );
            if ( is_wp_error( $updated ) )
            {
                return $this->prepare_error_response(
                    'rest_dependency_rewire_failed',
                    __( 'Dependent local-first mappings could not be rewired after deleting a mapping.', 'sentient-forms' ),
                    500
                );
            }
        }

        return true;
    }

    /** Delete an action linkage. */
    public function delete_form_action_item( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id          = $this->get_request_form_id( $request );
        $option_key       = $this->get_actions_option_key( $form_source_slug, $form_id );
        $actions          = $this->get_actions_option( $form_source_slug, $form_id );
        $id               = $request->get_param( 'local_mapping_id' );
        if ( !isset( $actions[ $id ] ) )
        {
            $local_first_row = $this->get_local_first_mapping_row_for_request( $request );
            if ( $local_first_row && $this->local_form_mappings )
            {
                $previous = $this->transform_local_first_mapping_to_linkage( $local_first_row );
                $deleted  = $this->local_form_mappings->delete( absint( $local_first_row['id'] ?? 0 ) );
                if ( ! $deleted )
                {
                    return $this->prepare_error_response( 'rest_action_delete_failed', __( 'Action linkage could not be deleted.', 'sentient-forms' ), 500 );
                }

                $actions = $this->remove_dependency_references_from_actions( $actions, sanitize_text_field( (string) $id ) );
                update_option( $option_key, $actions, false );

                $local_rewire = $this->remove_dependency_references_from_local_first_mappings(
                    sanitize_key( (string) $request->get_param( 'form_source_slug' ) ),
                    $form_id,
                    sanitize_text_field( (string) $id )
                );
                if ( is_wp_error( $local_rewire ) )
                {
                    return $local_rewire;
                }

                return $this->prepare_item_for_response( [ 'deleted' => true, 'previous' => $previous ] );
            }

            return $this->prepare_error_response( 'rest_action_not_found', __( 'Action linkage not found to delete.', 'sentient-forms' ), 404 );
        }

        $deleted = $actions[ $id ];
        unset( $actions[ $id ] );
        $actions = $this->remove_dependency_references_from_actions( $actions, sanitize_text_field( (string) $id ) );
        $local_rewire = $this->remove_dependency_references_from_local_first_mappings(
            sanitize_key( (string) $request->get_param( 'form_source_slug' ) ),
            $form_id,
            sanitize_text_field( (string) $id )
        );
        if ( is_wp_error( $local_rewire ) )
        {
            return $local_rewire;
        }

        update_option( $option_key, $actions, false );
        $this->sync_form_mappings_after_local_change(
            $form_source_slug,
            $form_id,
            $actions
        );

        return $this->prepare_item_for_response( [ 'deleted' => true, 'previous' => $deleted ] );
    }


    /** Retrieve aggregated execution status for a form. */
    public function get_form_execution_status( WP_REST_Request $request ): WP_REST_Response
    {
        $form_source_slug = Sentient_Forms_Form_Sources::rest_sanitize_form_source_slug(
            $request->get_param( 'form_source_slug' ),
            $request,
            'form_source_slug'
        );

        $form_id = $this->get_request_form_id( $request );
        return $this->prepare_item_for_response(
            $this->build_form_execution_status( $form_source_slug, $form_id )
        );
    }

    /**
     * Build the aggregated execution status for a form without nested REST dispatch.
     *
     * @return array<string, mixed>
     */
    private function build_form_execution_status(
        string $form_source_slug,
        string $form_id,
        ?array $local_mapping_rows = null,
        ?array $latest_events_by_form = null,
        ?array $action_log_entries_by_form = null
    ): array
    {
        $form_key = (string) $form_id;
        $integrity_status = $this->get_local_mapping_integrity_status( $form_source_slug, $form_id, $local_mapping_rows );
        return $integrity_status
            ?? ( null === $latest_events_by_form
                ? ( '' !== $form_key ? $this->get_form_execution_status_from_local_execution_event( $form_source_slug, $form_key, true ) : null )
                : $this->format_form_execution_status_from_local_execution_event( $latest_events_by_form[ $form_key ] ?? null, $form_source_slug, true ) )
            ?? ( '' !== $form_key
                ? $this->get_form_execution_status_from_action_log(
                    $form_source_slug,
                    $form_key,
                    true,
                    null === $action_log_entries_by_form ? null : ( $action_log_entries_by_form[ $form_key ] ?? [] )
                )
                : null )
            ?? [
                'status'          => 'unknown',
                'message'         => null,
                'entry_id'        => null,
                'last_error_code' => null,
                'last_result'     => null,
                'updated_at'      => null,
            ];
    }

    private function get_form_execution_status_from_local_execution_event( string $form_source_slug, int|string $form_id, bool $ignore_stale_inactive_errors = false ): ?array
    {
        if ( null === $this->local_execution_events )
        {
            return null;
        }

        return $this->format_form_execution_status_from_local_execution_event(
            $this->local_execution_events->get_latest_for_form( $form_source_slug, $form_id ),
            $form_source_slug,
            $ignore_stale_inactive_errors
        );
    }

    private function format_form_execution_status_from_local_execution_event( ?array $event, string $form_source_slug, bool $ignore_stale_inactive_errors = false ): ?array
    {
        if ( ! is_array( $event ) )
        {
            return null;
        }

        $raw_status = sanitize_key( (string) ( $event['status'] ?? 'unknown' ) );
        $status     = match ( $raw_status ) {
            'succeeded', 'success' => 'success',
            'failed', 'error'      => 'error',
            default                => 'unknown',
        };

        if ( $ignore_stale_inactive_errors && $this->should_ignore_stale_local_action_inactive_event( $event ) )
        {
            return null;
        }

        return [
            'status'          => $status,
            'message'         => $this->format_local_execution_event_message( $event, $raw_status, $status ),
            'entry_id'        => $this->format_execution_status_entry_id( $event['entry_id'] ?? null, $form_source_slug ),
            'last_error_code' => isset( $event['error_code'] ) && is_scalar( $event['error_code'] )
                ? sanitize_key( (string) $event['error_code'] )
                : null,
            'last_result'     => is_array( $event['result_json'] ?? null ) ? $event['result_json'] : null,
            'updated_at'      => isset( $event['updated_at'] ) && is_scalar( $event['updated_at'] )
                ? sanitize_text_field( (string) $event['updated_at'] )
                : ( isset( $event['created_at'] ) && is_scalar( $event['created_at'] )
                    ? sanitize_text_field( (string) $event['created_at'] )
                    : null ),
        ];
    }

    private function format_execution_status_entry_id( mixed $entry_id, string $form_source_slug ): ?int
    {
        if ( null === $entry_id || '' === $entry_id || ! is_scalar( $entry_id ) )
        {
            return null;
        }

        $entry_id = absint( $entry_id );
        if ( $entry_id <= 0 )
        {
            return null;
        }

        $native_entry = Sentient_Forms_Form_Sources::native_entry_capability_for_form_source( $form_source_slug );
        if ( is_array( $native_entry ) && array_key_exists( 'id', $native_entry ) && ! $native_entry['id'] )
        {
            return null;
        }

        return $entry_id;
    }

    private function format_local_execution_event_message( array $event, string $raw_status, string $status ): ?string
    {
        if ( 'error' === $status )
        {
            return isset( $event['error_message'] ) && is_scalar( $event['error_message'] )
                ? sanitize_textarea_field( (string) $event['error_message'] )
                : __( 'Local action execution failed.', 'sentient-forms' );
        }

        if ( 'success' === $status )
        {
            return __( 'Local action completed.', 'sentient-forms' );
        }

        return match ( $raw_status ) {
            'queued'  => __( 'Local action is queued.', 'sentient-forms' ),
            'running' => __( 'Local action is running.', 'sentient-forms' ),
            'pending' => __( 'Local action is pending.', 'sentient-forms' ),
            'skipped' => __( 'Local action was skipped.', 'sentient-forms' ),
            'blocked' => __( 'Local action was blocked.', 'sentient-forms' ),
            default   => null,
        };
    }

    private function get_form_execution_status_from_action_log( string $form_source_slug, string $form_id, bool $ignore_stale_inactive_errors = false, ?array $entries = null ): ?array
    {
        $entries = $entries ?? get_option( self::ACTION_LOG_OPTION_KEY, [] );
        if ( ! is_array( $entries ) )
        {
            return null;
        }

        $form_id = $this->normalize_provider_form_id( $form_id );
        if ( '' === $form_id )
        {
            return null;
        }

        foreach ( $entries as $entry )
        {
            if ( ! is_array( $entry ) )
            {
                continue;
            }

            if ( (string) ( $entry['form_source'] ?? '' ) !== $form_source_slug )
            {
                continue;
            }

            if ( ! $this->action_log_form_id_matches( $entry['form_id'] ?? null, $form_source_slug, $form_id ) )
            {
                continue;
            }

            if ( $this->is_retired_legacy_proxy_auth_log_entry( $entry ) )
            {
                continue;
            }

            if ( $ignore_stale_inactive_errors && $this->should_ignore_stale_local_action_inactive_log_entry( $entry ) )
            {
                continue;
            }

            $status = sanitize_key( (string) ( $entry['status'] ?? 'unknown' ) );
            if ( ! in_array( $status, [ 'success', 'error' ], true ) )
            {
                $status = 'error';
            }

            if (
                'success' === $status &&
                ! $this->can_action_log_entry_drive_success_status( $entry, $form_source_slug, $form_id )
            ) {
                continue;
            }

            $message = null;
            if ( 'error' === $status )
            {
                $message = isset( $entry['error_message'] ) && is_scalar( $entry['error_message'] )
                    ? sanitize_text_field( (string) $entry['error_message'] )
                    : null;
            }

            if ( null === $message && isset( $entry['result_summary'] ) && is_scalar( $entry['result_summary'] ) )
            {
                $message = sanitize_text_field( (string) $entry['result_summary'] );
            }

            return [
                'status'          => $status,
                'message'         => $message,
                'entry_id'        => $this->format_execution_status_entry_id( $entry['entry_id'] ?? null, $form_source_slug ),
                'last_error_code' => isset( $entry['error_code'] ) && is_scalar( $entry['error_code'] )
                    ? sanitize_key( (string) $entry['error_code'] )
                    : null,
                'last_result'     => $entry['details'] ?? $entry['result_summary'] ?? null,
                'updated_at'      => isset( $entry['created_at'] ) && is_scalar( $entry['created_at'] )
                    ? sanitize_text_field( (string) $entry['created_at'] )
                    : null,
            ];
        }

        return null;
    }

    private function get_entry_execution_status_from_action_log( string $form_source_slug, string $form_id, int $entry_id ): ?array
    {
        $entries = get_option( self::ACTION_LOG_OPTION_KEY, [] );
        if ( ! is_array( $entries ) )
        {
            return null;
        }

        $form_id = $this->normalize_provider_form_id( $form_id );
        if ( '' === $form_id )
        {
            return null;
        }

        foreach ( $entries as $entry )
        {
            if ( ! is_array( $entry ) )
            {
                continue;
            }

            if ( absint( $entry['entry_id'] ?? 0 ) !== $entry_id )
            {
                continue;
            }

            if ( sanitize_key( (string) ( $entry['form_source'] ?? '' ) ) !== sanitize_key( $form_source_slug ) )
            {
                continue;
            }

            if ( ! $this->action_log_form_id_matches( $entry['form_id'] ?? null, $form_source_slug, $form_id ) )
            {
                continue;
            }

            $status = $this->get_form_execution_status_from_action_log( $form_source_slug, $form_id, false, [ $entry ] );
            if ( null !== $status )
            {
                return $status;
            }
        }

        return null;
    }

    private function action_log_form_id_matches( mixed $candidate, string $form_source_slug, string $form_id ): bool
    {
        $candidate_form_id = $this->normalize_provider_form_id( $candidate );
        if ( '' === $candidate_form_id )
        {
            return false;
        }

        if ( Sentient_Forms_Form_Sources::GRAVITY_FORMS === sanitize_key( $form_source_slug ) )
        {
            return $this->is_positive_integer_form_id( $form_id )
                && absint( $candidate_form_id ) === absint( $form_id );
        }

        return $candidate_form_id === $form_id;
    }

    private function get_local_mapping_integrity_status( string $form_source_slug, string $form_id, ?array $local_mapping_rows = null ): ?array
    {
        if ( null === $local_mapping_rows && ! $this->local_form_mappings )
        {
            return null;
        }

        $mappings = null === $local_mapping_rows
            ? $this->local_form_mappings->list_for_form( $form_source_slug, (string) $form_id )
            : $local_mapping_rows;

        foreach ( $mappings as $mapping )
        {
            if ( empty( $mapping['enabled'] ) || 'custom_action' !== sanitize_key( (string) ( $mapping['action_kind'] ?? '' ) ) )
            {
                continue;
            }

            $custom_action = $this->local_custom_actions
                ? $this->local_custom_actions->get( absint( $mapping['action_id'] ?? 0 ) )
                : null;
            $identity = $this->resolve_local_first_action_identity( $custom_action );

            if ( 'ok' === $identity['repair_state'] )
            {
                continue;
            }

            $hook = isset( $mapping['hook'] ) && is_scalar( $mapping['hook'] )
                ? sanitize_key( (string) $mapping['hook'] )
                : 'unknown';

            return [
                'status'          => 'error',
                'message'         => sprintf(
                    /* translators: 1: action label, 2: hook */
                    __( 'Local action "%1$s" needs repair before it can run on %2$s.', 'sentient-forms' ),
                    $identity['action_label'],
                    $hook
                ),
                'entry_id'        => null,
                'last_error_code' => 'sentient_forms_local_mapping_needs_repair',
                'last_result'     => null,
                'updated_at'      => isset( $mapping['updated_at'] ) && is_scalar( $mapping['updated_at'] )
                    ? sanitize_text_field( (string) $mapping['updated_at'] )
                    : null,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function should_ignore_stale_local_action_inactive_event( array $event ): bool
    {
        $error_code = isset( $event['error_code'] ) && is_scalar( $event['error_code'] )
            ? sanitize_key( (string) $event['error_code'] )
            : '';
        $message    = isset( $event['error_message'] ) && is_scalar( $event['error_message'] )
            ? trim( (string) $event['error_message'] )
            : '';

        return 'sentient_forms_local_action_inactive' === $error_code
            || 'Local action is not active.' === $message;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function should_ignore_stale_local_action_inactive_log_entry( array $entry ): bool
    {
        $status        = sanitize_key( (string) ( $entry['status'] ?? '' ) );
        $error_code    = isset( $entry['error_code'] ) && is_scalar( $entry['error_code'] )
            ? sanitize_key( (string) $entry['error_code'] )
            : '';
        $error_message = isset( $entry['error_message'] ) && is_scalar( $entry['error_message'] )
            ? trim( (string) $entry['error_message'] )
            : '';

        return 'error' === $status
            && (
                'sentient_forms_local_action_inactive' === $error_code
                || 'Local action is not active.' === $error_message
            );
    }

    private function can_action_log_entry_drive_success_status( array $entry, string $form_source_slug, string $form_id ): bool
    {
        if ( ! $this->is_local_first_action_log_entry( $entry ) )
        {
            return true;
        }

        if ( null === $this->local_execution_events )
        {
            return false;
        }

        $execution_request_id = isset( $entry['execution_request_id'] ) && is_scalar( $entry['execution_request_id'] )
            ? sanitize_text_field( (string) $entry['execution_request_id'] )
            : '';

        if ( '' === $execution_request_id )
        {
            return false;
        }

        $event = $this->local_execution_events->get_by_request_id( $execution_request_id );
        if ( ! is_array( $event ) )
        {
            return false;
        }

        if ( sanitize_key( (string) ( $event['form_source'] ?? '' ) ) !== $form_source_slug )
        {
            return false;
        }

        if ( ! $this->action_log_form_id_matches( $event['form_id'] ?? null, $form_source_slug, $form_id ) )
        {
            return false;
        }

        return in_array(
            sanitize_key( (string) ( $event['status'] ?? '' ) ),
            [ 'success', 'succeeded' ],
            true
        );
    }

    private function is_local_first_action_log_entry( array $entry ): bool
    {
        $mapping_id = isset( $entry['mapping_id'] ) && is_scalar( $entry['mapping_id'] )
            ? sanitize_text_field( (string) $entry['mapping_id'] )
            : '';
        if ( '' !== $mapping_id && str_starts_with( $mapping_id, 'local_first_' ) )
        {
            return true;
        }

        $action_code = sanitize_key( (string) ( $entry['action_code'] ?? '' ) );
        return 'sentient_forms_local_custom_action' === $action_code || str_starts_with( $action_code, 'local_first_' );
    }

    private function is_retired_legacy_proxy_auth_log_entry( array $entry ): bool
    {
        $status        = sanitize_key( (string) ( $entry['status'] ?? '' ) );
        $error_code    = isset( $entry['error_code'] ) && is_scalar( $entry['error_code'] )
            ? sanitize_key( (string) $entry['error_code'] )
            : '';
        $error_message = isset( $entry['error_message'] ) && is_scalar( $entry['error_message'] )
            ? trim( (string) $entry['error_message'] )
            : '';

        return 'error' === $status
            && '401' === $error_code
            && 'Missing Authentication header' === $error_message;
    }

    /**
     * Retrieve the latest execution status for a given entry.
     */
    public function get_entry_execution_status( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source_slug = Sentient_Forms_Form_Sources::rest_sanitize_form_source_slug(
            $request->get_param( 'form_source_slug' ) ?: Sentient_Forms_Form_Sources::GRAVITY_FORMS,
            $request,
            'form_source_slug'
        );

        if ( Sentient_Forms_Form_Sources::GRAVITY_FORMS !== $form_source_slug )
        {
            return $this->get_provider_entry_execution_status( $request, $form_source_slug );
        }

        if ( ! class_exists( 'GFAPI' ) )
        {
            return $this->prepare_error_response( 'rest_gf_missing', __( 'Gravity Forms is required for this endpoint.', 'sentient-forms' ), 500 );
        }

        $entry_id = (int) $request->get_param( 'entry_id' );
        $entry    = GFAPI::get_entry( $entry_id );

        if ( is_wp_error( $entry ) )
        {
            return $this->prepare_error_response( 'rest_entry_not_found', __( 'Entry not found.', 'sentient-forms' ), 404 );
        }

        $last_response = function_exists( 'gform_get_meta' ) ? gform_get_meta( $entry_id, 'sentient_forms_last_response' ) : null;
        $last_error    = function_exists( 'gform_get_meta' ) ? gform_get_meta( $entry_id, 'sentient_forms_last_error' ) : null;
        $processed_at  = function_exists( 'gform_get_meta' ) ? gform_get_meta( $entry_id, 'sentient_forms_last_processed_at' ) : null;

        $decoded_last_response = $this->maybe_decode_json_meta( $last_response );

        $payload = [
            'entry_id'       => $entry_id,
            'form_id'        => (int) ( $entry['form_id'] ?? 0 ),
            'last_response'  => $decoded_last_response,
            'last_error'     => is_string( $last_error ) && $last_error !== '' ? $last_error : null,
            'processed_at'   => is_string( $processed_at ) && $processed_at !== '' ? $processed_at : null,
            'status'         => is_string( $last_error ) && $last_error !== '' ? 'error' : ( $last_response ? 'success' : 'unknown' ),
            'metering_summary' => $this->build_metering_summary( $decoded_last_response ),
        ];

        return $this->prepare_item_for_response( $payload );
    }

    private function get_provider_entry_execution_status( WP_REST_Request $request, string $form_source_slug ): WP_Error | WP_REST_Response
    {
        $form_id  = absint( $request->get_param( 'form_id' ) );
        $entry_id = absint( $request->get_param( 'entry_id' ) );
        if ( $form_id <= 0 || $entry_id <= 0 )
        {
            return $this->prepare_error_response( 'rest_entry_not_found', __( 'Entry not found.', 'sentient-forms' ), 404 );
        }

        $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $adapter  = $registry ? $registry->get_adapter_by_id( $form_source_slug ) : null;
        if ( ! $adapter || ! method_exists( $adapter, 'get_entry_data' ) )
        {
            return $this->prepare_error_response( 'rest_entry_not_found', __( 'Entry not found.', 'sentient-forms' ), 404 );
        }

        $entry = $adapter->get_entry_data( $entry_id, $form_id );
        if ( ! is_array( $entry ) )
        {
            return $this->prepare_error_response( 'rest_entry_not_found', __( 'Entry not found.', 'sentient-forms' ), 404 );
        }

        $submission_uuid = isset( $entry['submission_uuid'] ) && is_scalar( $entry['submission_uuid'] )
            ? sanitize_text_field( (string) $entry['submission_uuid'] )
            : null;
        $event           = null !== $this->local_execution_events
            ? $this->local_execution_events->get_latest_for_entry( $form_source_slug, $form_id, $entry_id, $submission_uuid )
            : null;
        $action_log_status = ! is_array( $event )
            ? $this->get_entry_execution_status_from_action_log( $form_source_slug, $form_id, $entry_id )
            : null;

        if ( is_array( $action_log_status ) )
        {
            $raw_status    = isset( $action_log_status['status'] ) && is_scalar( $action_log_status['status'] )
                ? sanitize_key( (string) $action_log_status['status'] )
                : 'unknown';
            $status        = match ( $raw_status ) {
                'succeeded', 'success' => 'success',
                'failed', 'error'      => 'error',
                default                => 'unknown',
            };
            $last_response = is_array( $action_log_status['last_result'] ?? null ) ? $action_log_status['last_result'] : null;
            $last_error    = 'error' === $status && isset( $action_log_status['message'] ) && is_scalar( $action_log_status['message'] ) && '' !== (string) $action_log_status['message']
                ? sanitize_textarea_field( (string) $action_log_status['message'] )
                : null;
            $processed_at  = isset( $action_log_status['updated_at'] ) && is_scalar( $action_log_status['updated_at'] )
                ? sanitize_text_field( (string) $action_log_status['updated_at'] )
                : null;
        }
        else
        {
            $raw_status = is_array( $event ) && isset( $event['status'] ) && is_scalar( $event['status'] )
                ? sanitize_key( (string) $event['status'] )
                : 'unknown';
            $status     = match ( $raw_status ) {
                'succeeded', 'success' => 'success',
                'failed', 'error'      => 'error',
                default                => 'unknown',
            };

            $last_response = is_array( $event['result_json'] ?? null ) ? $event['result_json'] : null;
            $last_error    = is_array( $event ) && isset( $event['error_message'] ) && is_scalar( $event['error_message'] ) && '' !== (string) $event['error_message']
                ? sanitize_textarea_field( (string) $event['error_message'] )
                : null;
            $processed_at  = is_array( $event ) && isset( $event['updated_at'] ) && is_scalar( $event['updated_at'] )
                ? sanitize_text_field( (string) $event['updated_at'] )
                : ( is_array( $event ) && isset( $event['created_at'] ) && is_scalar( $event['created_at'] )
                    ? sanitize_text_field( (string) $event['created_at'] )
                    : null );
        }

        $payload = [
            'entry_id'          => absint( $entry['id'] ?? $entry_id ),
            'form_id'           => $form_id,
            'form_source'       => $form_source_slug,
            'submission_uuid'   => $submission_uuid,
            'native_entry_url'  => isset( $entry['native_entry_url'] ) && is_scalar( $entry['native_entry_url'] )
                ? esc_url_raw( (string) $entry['native_entry_url'] )
                : null,
            'last_response'     => $last_response,
            'last_error'        => $last_error,
            'processed_at'      => $processed_at,
            'status'            => $status,
            'metering_summary'  => $this->build_metering_summary( $last_response ),
        ];

        return $this->prepare_item_for_response( $payload );
    }

    /**
     * Endpoint args for item schema.
     */

    private function get_form_status_option_key( string $form_source_slug, int $form_id ): string
    {
        return sprintf(
            'sentient_forms_form_status_%s_%d',
            sanitize_key( $form_source_slug ),
            $form_id
        );
    }

    public function get_endpoint_args_for_item_schema( $method = null ): array
    {
        $args = $this->get_collection_args();

        if ( WP_REST_Server::CREATABLE === $method || WP_REST_Server::EDITABLE === $method )
        {
            $args[ 'central_action_id' ]          = [
                'description'       => __( 'ID of the action on the central server.', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => WP_REST_Server::CREATABLE === $method,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => 'rest_validate_request_arg',
            ];
            $args[ 'action_type_indicator' ]      = [
                'description'       => __( 'Indicator of action type.', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => WP_REST_Server::CREATABLE === $method,
                'sanitize_callback' => 'sanitize_key',
                'enum'              => self::ACTION_TYPE_INDICATORS,
            ];
            $args[ 'trigger_hooks' ]              = [
                'description'       => __( 'Hooks that trigger this action.', 'sentient-forms' ),
                'type'              => 'array',
                'required'          => WP_REST_Server::CREATABLE === $method,
                'items'             => [
                    'type' => 'string',
                    'enum' => Sentient_Forms_Form_Source_Lifecycles::accepted_input_ids(),
                ],
                'validate_callback' => [ $this, 'validate_trigger_hooks_param' ],
            ];
            $args[ 'is_action_enabled_for_form' ] = [
                'description'       => __( 'Whether the action is enabled for the form.', 'sentient-forms' ),
                'type'              => 'boolean',
                'required'          => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default'           => true,
            ];
            $args[ 'execution_priority' ]         = [
                'description'       => __( 'Execution priority for the action.', 'sentient-forms' ),
                'type'              => 'integer',
                'required'          => false,
                'sanitize_callback' => 'absint',
                'default'           => 10,
            ];
            $args[ 'action_name_label' ]          = [
                'description'       => __( 'Optional display label for the action.', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ];
            $args[ 'settings' ]                   = [
                'description'       => __( 'Action-specific configuration settings.', 'sentient-forms' ),
                'type'              => 'object',
                'required'          => false,
            ];
        }

        if ( WP_REST_Server::EDITABLE === $method )
        {
            $args[ 'local_mapping_id' ] = [
                'validate_callback' => [ $this, 'validate_local_mapping_id_param' ],
                'required'          => true,
                'type'              => 'string',
            ];
        }

        return $args;
    }

    /**
     * Schema for an action linkage item.
     */
    public function get_item_schema(): ?array
    {
        if ( $this->schema )
        {
            return $this->schema;
        }

        $this->schema = [
            '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
            'title'       => 'sentient_form_action_linkage',
            'description' => __( 'A linkage between a form and a central action.', 'sentient-forms' ),
            'type'        => 'object',
            'properties'  => [
                'local_mapping_id'           => [
                    'description' => __( 'Unique ID for this linkage.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
                'central_action_id'          => [
                    'description' => __( 'Action identifier on the central server.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'action_type_indicator'      => [
                    'description' => __( 'Indicator for the type of central action.', 'sentient-forms' ),
                    'type'        => 'string',
                    'enum'        => self::ACTION_TYPE_INDICATORS,
                    'context'     => [ 'view', 'edit' ],
                ],
                'trigger_hooks'              => [
                    'description' => __( 'Hooks that trigger the action.', 'sentient-forms' ),
                    'type'        => 'array',
                    'default'     => [],
                    'items'       => [
                        'type' => 'string',
                        'enum' => self::ALLOWED_TRIGGER_HOOKS,
                    ],
                    'context'     => [ 'view', 'edit' ],
                ],
                'is_action_enabled_for_form' => [
                    'description' => __( 'Whether this action is enabled for the form.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'context'     => [ 'view', 'edit' ],
                    'default'     => true,
                ],
                'execution_priority'         => [
                    'description' => __( 'Priority in which the action executes.', 'sentient-forms' ),
                    'type'        => 'integer',
                    'context'     => [ 'view', 'edit' ],
                    'default'     => 10,
                ],
                'action_name_label'          => [
                    'description' => __( 'Human readable name of the action.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'settings'                   => [
                    'description' => __( 'Action-specific configuration settings.', 'sentient-forms' ),
                    'type'        => 'object',
                    'context'     => [ 'view', 'edit' ],
                    'default'     => [],
                ],
            ],
        ];

        return $this->schema;
    }

    /** Validate trigger_hooks parameter. */
    public function validate_trigger_hooks_param( $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( !is_array( $value ) )
        {
            return new WP_Error( 'rest_invalid_param', __( 'Trigger hooks must be an array.', 'sentient-forms' ), [ 'status' => 400 ] );
        }

        foreach ( $value as $hook )
        {
            if ( !is_string( $hook ) )
            {
                return new WP_Error( 'rest_invalid_param', __( 'Each trigger hook must be a string.', 'sentient-forms' ), [ 'status' => 400 ] );
            }

            if ( ! Sentient_Forms_Form_Source_Lifecycles::is_accepted_input( $hook ) )
            {
                return new WP_Error(
                    'rest_invalid_hook',
                    sprintf(
                        /* translators: %s: invalid hook name */
                        __( 'Hook %s is not supported. Allowed lifecycle IDs: validation, after_submission, real_time.', 'sentient-forms' ),
                        esc_html( $hook )
                    ),
                    [ 'status' => 400 ],
                );
            }
        }

        return true;
    }

    /**
     * Normalize trigger hooks to allowed sanitized values.
     */
    private function sanitize_trigger_hooks( array $hooks ): array
    {
        if ( empty( $hooks ) )
        {
            return [];
        }

        return Sentient_Forms_Form_Source_Lifecycles::normalize_many( $hooks );
    }

    private function sanitize_lifecycle_scope( mixed $scope ): string
    {
        $scope_key = is_scalar( $scope ) ? sanitize_key( (string) $scope ) : 'all';
        if ( 'all' === $scope_key )
        {
            return 'all';
        }

        return Sentient_Forms_Form_Source_Lifecycles::normalize_id( $scope_key ) ?? 'all';
    }

    private function action_allows_realtime_hook( mixed $central_action_id ): bool
    {
        return self::REALTIME_ACTION_ID === sanitize_key( (string) $central_action_id );
    }

    private function validate_realtime_trigger_policy( array $trigger_hooks, mixed $central_action_id, array $settings = [] ): true | WP_Error
    {
        $has_realtime = in_array( 'real_time', $this->sanitize_trigger_hooks( $trigger_hooks ), true );
        if ( isset( $settings['execution_mode'] ) && is_scalar( $settings['execution_mode'] ) )
        {
            $has_realtime = $has_realtime || 'real_time' === sanitize_key( (string) $settings['execution_mode'] );
        }

        if ( ! $has_realtime || $this->action_allows_realtime_hook( $central_action_id ) )
        {
            return true;
        }

        return $this->prepare_error_response(
            'rest_invalid_realtime_action',
            __( 'Realtime triggers are only supported by the Realtime Clarification Assistant action.', 'sentient-forms' ),
            400
        );
    }

    private function validate_form_source_trigger_hooks( string $form_source_slug, array $trigger_hooks ): true | WP_Error
    {
        $trigger_hooks = $this->sanitize_trigger_hooks( $trigger_hooks );
        if ( [] === $trigger_hooks )
        {
            return true;
        }

        $descriptor = $this->get_form_source_descriptor( $form_source_slug );
        if ( ! is_array( $descriptor ) || ! isset( $descriptor['lifecycles'] ) || ! is_array( $descriptor['lifecycles'] ) )
        {
            return true;
        }

        $source_label = isset( $descriptor['label'] ) && is_scalar( $descriptor['label'] )
            ? sanitize_text_field( (string) $descriptor['label'] )
            : $form_source_slug;

        foreach ( $trigger_hooks as $hook )
        {
            $hook = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $hook );
            if ( null === $hook )
            {
                continue;
            }

            if ( ! array_key_exists( $hook, $descriptor['lifecycles'] ) )
            {
                continue;
            }

            $lifecycle = $descriptor['lifecycles'][ $hook ];
            if ( is_array( $lifecycle ) && ! empty( $lifecycle['supported'] ) )
            {
                continue;
            }

            $reason = is_array( $lifecycle ) && isset( $lifecycle['unsupported_reason'] ) && is_scalar( $lifecycle['unsupported_reason'] )
                ? sanitize_text_field( (string) $lifecycle['unsupported_reason'] )
                : '';
            $message = sprintf(
                /* translators: 1: lifecycle id, 2: form source label. */
                __( 'The %1$s lifecycle is not supported for %2$s.', 'sentient-forms' ),
                $hook,
                $source_label
            );
            if ( '' !== $reason )
            {
                $message .= ' ' . $reason;
            }

            return $this->prepare_error_response(
                'rest_unsupported_form_source_lifecycle',
                $message,
                400
            );
        }

        return true;
    }

    /**
     * Safely decode JSON-encoded Gravity Forms meta values.
     */
    private function maybe_decode_json_meta( mixed $value ): mixed
    {
        if ( ! is_string( $value ) || '' === trim( $value ) )
        {
            return null;
        }

        $decoded = json_decode( $value, true );
        if ( JSON_ERROR_NONE === json_last_error() )
        {
            return $decoded;
        }

        return $value;
    }

    /**
     * Project metering/correlation details from CPS response meta for admin UI rendering.
     */
    private function build_metering_summary( mixed $last_response ): ?array
    {
        if ( ! is_array( $last_response ) )
        {
            return null;
        }

        $meta = $last_response['meta'] ?? null;
        if ( ! is_array( $meta ) )
        {
            return null;
        }

        $execution_request_id = isset( $meta['execution_request_id'] ) && is_scalar( $meta['execution_request_id'] )
            ? sanitize_text_field( (string) $meta['execution_request_id'] )
            : null;
        $correlation_id = isset( $meta['correlation_id'] ) && is_scalar( $meta['correlation_id'] )
            ? sanitize_text_field( (string) $meta['correlation_id'] )
            : $execution_request_id;
        $credits_debited = isset( $meta['credits_debited'] ) && is_numeric( $meta['credits_debited'] )
            ? max( 0, (int) $meta['credits_debited'] )
            : null;
        $pricing_policy_version = isset( $meta['pricing']['pricing_policy_version'] ) && is_scalar( $meta['pricing']['pricing_policy_version'] )
            ? sanitize_text_field( (string) $meta['pricing']['pricing_policy_version'] )
            : null;
        $workflow = $this->extract_workflow_metering_summary( $meta['workflow_execution'] ?? null );

        if ( null === $correlation_id && null === $execution_request_id && null === $credits_debited && null === $pricing_policy_version && null === $workflow )
        {
            return null;
        }

        return array_filter(
            [
                'correlation_id' => $correlation_id,
                'execution_request_id' => $execution_request_id,
                'credits_debited' => $credits_debited,
                'pricing_policy_version' => $pricing_policy_version,
                'workflow' => $workflow,
            ],
            static function ( $value ): bool {
                return null !== $value;
            }
        );
    }

    /**
     * Extract workflow metering breakdown from workflow_execution response metadata.
     */
    private function extract_workflow_metering_summary( mixed $workflow ): ?array
    {
        if ( ! is_array( $workflow ) )
        {
            return null;
        }

        $status = isset( $workflow['status'] ) && is_scalar( $workflow['status'] )
            ? sanitize_key( (string) $workflow['status'] )
            : 'unknown';
        $credits_total = isset( $workflow['credits_total'] ) && is_numeric( $workflow['credits_total'] )
            ? max( 0, (int) $workflow['credits_total'] )
            : 0;
        $credits_by_node = [];
        if ( isset( $workflow['credits_by_node'] ) && is_array( $workflow['credits_by_node'] ) )
        {
            foreach ( $workflow['credits_by_node'] as $node_id => $credits )
            {
                if ( ! is_scalar( $node_id ) || ! is_numeric( $credits ) )
                {
                    continue;
                }
                $normalized_node_id = sanitize_text_field( (string) $node_id );
                if ( '' === $normalized_node_id )
                {
                    continue;
                }
                $credits_by_node[ $normalized_node_id ] = max( 0, (int) $credits );
            }
        }

        $failed_nodes = [];
        if ( isset( $workflow['nodes'] ) && is_array( $workflow['nodes'] ) )
        {
            foreach ( $workflow['nodes'] as $node )
            {
                if ( ! is_array( $node ) )
                {
                    continue;
                }

                $node_status = isset( $node['status'] ) && is_scalar( $node['status'] )
                    ? sanitize_key( (string) $node['status'] )
                    : '';
                if ( 'failed' !== $node_status )
                {
                    continue;
                }

                $node_id = isset( $node['node_id'] ) && is_scalar( $node['node_id'] )
                    ? sanitize_text_field( (string) $node['node_id'] )
                    : '';
                if ( '' !== $node_id )
                {
                    $failed_nodes[] = $node_id;
                }
            }
        }

        return [
            'status' => $status,
            'credits_total' => $credits_total,
            'credits_by_node' => $credits_by_node,
            'failed_nodes' => array_values( array_unique( $failed_nodes ) ),
        ];
    }

    /**
     * Normalize CPS workflow planner payload shape for UI compatibility.
     *
     * @param array  $payload    Raw CPS planner payload.
     * @param string $hook_scope Requested hook scope.
     *
     * @return array
     */
    private function normalize_workflow_plan_payload( array $payload, string $hook_scope ): array
    {
        return [
            'authority'         => 'cps',
            'authority_reason'  => isset( $payload['authority_reason'] ) && is_scalar( $payload['authority_reason'] )
                ? sanitize_key( (string) $payload['authority_reason'] )
                : null,
            'cps_unreachable'   => false,
            'policy_version'    => is_string( $payload['policy_version'] ?? null )
                ? $payload['policy_version']
                : self::WORKFLOW_POLICY_VERSION,
            'hook_scope'        => $hook_scope,
            'available_hooks'   => isset( $payload['available_hooks'] ) && is_array( $payload['available_hooks'] )
                ? array_values( $payload['available_hooks'] )
                : [],
            'nodes'             => isset( $payload['nodes'] ) && is_array( $payload['nodes'] )
                ? array_values( $payload['nodes'] )
                : [],
            'edges'             => isset( $payload['edges'] ) && is_array( $payload['edges'] )
                ? array_values( $payload['edges'] )
                : [],
            'hooks'             => isset( $payload['hooks'] ) && is_array( $payload['hooks'] )
                ? array_values( $payload['hooks'] )
                : [],
            'policy_violations' => isset( $payload['policy_violations'] ) && is_array( $payload['policy_violations'] )
                ? array_values( $payload['policy_violations'] )
                : [],
        ];
    }

    /**
     * Confirm a CPS-authored workflow plan still represents local WordPress linkages.
     *
     * CPS can return an empty successful plan when it has not caught up with local-only
     * mappings. In that case the local planner is more truthful for the admin graph.
     *
     * @param array $plan          Normalized CPS workflow plan payload.
     * @param array $local_actions Local WordPress linkage payload.
     *
     * @return bool
     */
    private function workflow_plan_covers_local_actions( array $plan, array $local_actions ): bool
    {
        $normalized_actions = $this->normalize_local_action_mappings( $local_actions );
        if ( empty( $normalized_actions ) )
        {
            return true;
        }

        $plan_node_ids = [];
        foreach ( (array) ( $plan['nodes'] ?? [] ) as $node )
        {
            if ( ! is_array( $node ) )
            {
                continue;
            }

            foreach ( [ 'mapping_id', 'local_mapping_id', 'node_id' ] as $id_key )
            {
                if ( isset( $node[ $id_key ] ) && is_scalar( $node[ $id_key ] ) )
                {
                    $node_id = sanitize_text_field( (string) $node[ $id_key ] );
                    if ( '' !== $node_id )
                    {
                        $plan_node_ids[ $node_id ] = true;
                    }
                }
            }
        }

        foreach ( array_keys( $normalized_actions ) as $mapping_id )
        {
            if ( ! isset( $plan_node_ids[ $mapping_id ] ) )
            {
                return false;
            }
        }

        return true;
    }

    /**
     * Build the local workflow plan.
     *
     * @param array  $actions    Merged linkage payload.
     * @param string $hook_scope        Requested hook scope.
     * @param string $authority_reason  Reason recorded for local planning.
     *
     * @return array
     */
    private function build_local_workflow_plan_payload( array $actions, string $hook_scope, string $authority_reason = 'cps_unavailable' ): array
    {
        $normalized = $this->normalize_local_action_mappings( $actions );
        $planner    = Sentient_Forms_Plugin::instance()->get_mapping_dependency_planner();

        $nodes = [];
        $edges = [];
        $edge_lookup = [];
        $available_hooks = [];

        foreach ( $normalized as $mapping_id => $mapping )
        {
            $trigger_hooks   = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
            $trigger_sources = $planner->extract_trigger_sources( $mapping, $trigger_hooks );
            $dependency_ids  = $planner->extract_dependency_ids( $mapping );
            $is_enabled     = ! empty( $mapping['is_action_enabled_for_form'] );
            $is_async       = $this->is_mapping_async( $mapping );

            $available_hooks = array_values( array_unique( array_merge( $available_hooks, $trigger_hooks ) ) );

            $nodes[] = [
                'mapping_id'        => $mapping_id,
                'label'             => sanitize_text_field( (string) ( $mapping['action_name_label'] ?? $mapping['central_action_id'] ?? $mapping_id ) ),
                'central_action_id' => sanitize_text_field( (string) ( $mapping['central_action_id'] ?? '' ) ),
                'trigger_hooks'     => $trigger_hooks,
                'trigger_sources'   => $trigger_sources,
                'dependency_ids'    => $dependency_ids,
                'is_enabled'        => $is_enabled,
                'is_async'          => $is_async,
            ];

            foreach ( $trigger_hooks as $hook )
            {
                $hook_dependency_ids = $planner->extract_dependency_ids_for_hook( $mapping, $hook );
                if ( empty( $hook_dependency_ids ) )
                {
                    $trigger_source = is_array( $trigger_sources[ $hook ] ?? null ) ? $trigger_sources[ $hook ] : [];
                    if ( 'unbound' === sanitize_key( (string) ( $trigger_source['type'] ?? '' ) ) )
                    {
                        continue;
                    }

                    $edge_id = sprintf( 'hook_root:%s->%s:%s', $hook, $mapping_id, $hook );
                    if ( isset( $edge_lookup[ $edge_id ] ) )
                    {
                        continue;
                    }

                    $edges[] = [
                        'from' => sprintf( '__hook_root__:%s', $hook ),
                        'to'   => $mapping_id,
                        'kind' => 'hook_root',
                        'hook' => $hook,
                    ];
                    $edge_lookup[ $edge_id ] = true;
                    continue;
                }

                foreach ( $hook_dependency_ids as $dependency_id )
                {
                    $edge_id = sprintf( 'dependency:%s->%s:%s', $dependency_id, $mapping_id, $hook );
                    if ( isset( $edge_lookup[ $edge_id ] ) )
                    {
                        continue;
                    }

                    $edges[] = [
                        'from' => $dependency_id,
                        'to'   => $mapping_id,
                        'kind' => 'dependency',
                        'hook' => $hook,
                    ];
                    $edge_lookup[ $edge_id ] = true;
                }
            }
        }

        sort( $available_hooks );
        $hooks_to_plan = 'all' === $hook_scope
            ? $available_hooks
            : ( in_array( $hook_scope, $available_hooks, true ) ? [ $hook_scope ] : [] );

        $hook_payloads = [];
        foreach ( $hooks_to_plan as $hook )
        {
            $plan = $planner->build_execution_plan( $normalized, $hook );
            $order = [];
            $blocked = [];
            $blocked_lookup = [];

            foreach ( (array) ( $plan['order'] ?? [] ) as $mapping_id )
            {
                if ( ! isset( $plan['nodes'][ $mapping_id ] ) || ! is_array( $plan['nodes'][ $mapping_id ] ) )
                {
                    continue;
                }

                $node = $plan['nodes'][ $mapping_id ];
                if ( empty( $node['hook_enabled'] ) )
                {
                    continue;
                }

                $order[] = $mapping_id;
                $dependency_ids = is_array( $node['dependency_ids'] ?? null ) ? $node['dependency_ids'] : [];

                if ( in_array( $mapping_id, (array) ( $plan['cycle_ids'] ?? [] ), true ) )
                {
                    $blocked_lookup[ $mapping_id ] = 'cycle';
                    $blocked[] = [
                        'mapping_id' => $mapping_id,
                        'reason'     => 'cycle',
                    ];
                    continue;
                }

                if ( empty( $node['enabled'] ) )
                {
                    $blocked_lookup[ $mapping_id ] = 'disabled';
                    $blocked[] = [
                        'mapping_id' => $mapping_id,
                        'reason'     => 'disabled',
                    ];
                    continue;
                }

                $missing_dependencies = [];
                foreach ( $dependency_ids as $dependency_id )
                {
                    if ( ! isset( $plan['nodes'][ $dependency_id ] ) || ! is_array( $plan['nodes'][ $dependency_id ] ) )
                    {
                        $missing_dependencies[] = $dependency_id;
                        continue;
                    }

                    if ( empty( $plan['nodes'][ $dependency_id ]['hook_enabled'] ) )
                    {
                        $dependency_hooks = isset( $plan['nodes'][ $dependency_id ]['trigger_hooks'] ) && is_array( $plan['nodes'][ $dependency_id ]['trigger_hooks'] )
                            ? $plan['nodes'][ $dependency_id ]['trigger_hooks']
                            : [];
                        if ( ! $this->dependency_satisfies_hook( $hook, $dependency_hooks ) )
                        {
                            $missing_dependencies[] = $dependency_id;
                        }
                    }
                }

                if ( ! empty( $missing_dependencies ) )
                {
                    $blocked_lookup[ $mapping_id ] = 'missing_dependency';
                    $blocked[] = [
                        'mapping_id' => $mapping_id,
                        'reason'     => 'missing_dependency',
                        'details'    => implode( ', ', array_map( 'sanitize_text_field', $missing_dependencies ) ),
                    ];
                    continue;
                }

                if ( Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION === $hook )
                {
                    $invalid_dependency = null;
                    foreach ( $dependency_ids as $dependency_id )
                    {
                        if ( ! isset( $plan['nodes'][ $dependency_id ] ) || ! is_array( $plan['nodes'][ $dependency_id ] ) )
                        {
                            continue;
                        }

                        $dependency_hooks = isset( $plan['nodes'][ $dependency_id ]['trigger_hooks'] ) && is_array( $plan['nodes'][ $dependency_id ]['trigger_hooks'] )
                            ? $plan['nodes'][ $dependency_id ]['trigger_hooks']
                            : [];
                        if ( ! $this->dependency_satisfies_hook( $hook, $dependency_hooks ) )
                        {
                            continue;
                        }

                        $dependency_is_async = $this->is_mapping_async( (array) ( $plan['nodes'][ $dependency_id ]['mapping'] ?? [] ) );
                        $mapping_is_async    = $this->is_mapping_async( (array) ( $node['mapping'] ?? [] ) );
                        if ( $dependency_is_async && ! $mapping_is_async )
                        {
                            $invalid_dependency = $dependency_id;
                            break;
                        }
                    }

                    if ( null !== $invalid_dependency )
                    {
                        $blocked_lookup[ $mapping_id ] = 'policy_violation';
                        $blocked[] = [
                            'mapping_id' => $mapping_id,
                            'reason'     => 'policy_violation',
                            'details'    => sanitize_text_field( (string) $invalid_dependency ) . ':execution_mode_mismatch',
                        ];
                        continue;
                    }
                }
            }

            $runnable = [];
            foreach ( $order as $mapping_id )
            {
                if ( isset( $blocked_lookup[ $mapping_id ] ) )
                {
                    continue;
                }

                $node = $plan['nodes'][ $mapping_id ] ?? null;
                $dependency_ids = is_array( $node['dependency_ids'] ?? null ) ? $node['dependency_ids'] : [];
                $upstream_blocking_dependency = null;
                foreach ( $dependency_ids as $dependency_id )
                {
                    if ( isset( $blocked_lookup[ $dependency_id ] ) )
                    {
                        $upstream_blocking_dependency = $dependency_id;
                        break;
                    }
                }

                if ( null !== $upstream_blocking_dependency )
                {
                    $blocked_lookup[ $mapping_id ] = 'upstream_blocked';
                    $blocked[] = [
                        'mapping_id' => $mapping_id,
                        'reason'     => 'upstream_blocked',
                        'details'    => sanitize_text_field( $upstream_blocking_dependency ),
                    ];
                    continue;
                }

                $runnable[] = $mapping_id;
            }

            $waves = $this->build_local_workflow_waves( $runnable, $plan['nodes'] ?? [] );
            $hook_payloads[] = [
                'hook'      => $hook,
                'order'     => $order,
                'waves'     => $waves,
                'runnable'  => $runnable,
                'blocked'   => $blocked,
                'cycle_ids' => array_values( array_filter(
                    (array) ( $plan['cycle_ids'] ?? [] ),
                    static fn( $mapping_id ) => in_array( $mapping_id, $order, true )
                ) ),
            ];
        }

        return [
            'authority'         => 'local',
            'authority_reason'  => sanitize_key( $authority_reason ),
            'cps_unreachable'   => 'cps_mismatch' !== $authority_reason,
            'policy_version'    => self::WORKFLOW_POLICY_VERSION,
            'hook_scope'        => $hook_scope,
            'available_hooks'   => $available_hooks,
            'nodes'             => $nodes,
            'edges'             => $edges,
            'hooks'             => $hook_payloads,
            'policy_violations' => $this->collect_dependency_policy_violations( $normalized ),
        ];
    }

    /**
     * Build wave groups for topological order previews.
     *
     * @param array<int, string>                  $runnable_ids Runnable mapping ids in topological order.
     * @param array<string, array<string, mixed>> $nodes        Planner nodes keyed by mapping id.
     *
     * @return array<int, array{level: int, mapping_ids: array<int, string>}>
     */
    private function build_local_workflow_waves( array $runnable_ids, array $nodes ): array
    {
        $runnable_lookup = array_fill_keys( $runnable_ids, true );
        $levels          = [];
        foreach ( $runnable_ids as $mapping_id )
        {
            $dependency_ids = isset( $nodes[ $mapping_id ]['dependency_ids'] ) && is_array( $nodes[ $mapping_id ]['dependency_ids'] )
                ? $nodes[ $mapping_id ]['dependency_ids']
                : [];
            $dependency_levels = [];
            foreach ( $dependency_ids as $dependency_id )
            {
                if ( ! isset( $runnable_lookup[ $dependency_id ] ) )
                {
                    continue;
                }

                $dependency_levels[] = (int) ( $levels[ $dependency_id ] ?? 0 );
            }

            $levels[ $mapping_id ] = empty( $dependency_levels ) ? 0 : ( max( $dependency_levels ) + 1 );
        }

        $waves = [];
        foreach ( $runnable_ids as $mapping_id )
        {
            $level = (int) ( $levels[ $mapping_id ] ?? 0 );
            if ( ! isset( $waves[ $level ] ) )
            {
                $waves[ $level ] = [];
            }
            $waves[ $level ][] = $mapping_id;
        }

        ksort( $waves );
        $result = [];
        foreach ( $waves as $level => $mapping_ids )
        {
            $result[] = [
                'level'       => (int) $level,
                'mapping_ids' => array_values( $mapping_ids ),
            ];
        }

        return $result;
    }

    /**
     * Return non-fatal dependency policy diagnostics used by the planner UI.
     *
     * @param array<string, array<string, mixed>> $normalized Action mappings keyed by local mapping id.
     *
     * @return array<int, array{mapping_id: string, dependency_id: string, code: string, message: string}>
     */
    private function collect_dependency_policy_violations( array $normalized ): array
    {
        $violations       = [];
        $violation_lookup = [];
        $planner          = Sentient_Forms_Plugin::instance()->get_mapping_dependency_planner();

        foreach ( $normalized as $mapping_id => $mapping )
        {
            $trigger_hooks = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
            if ( empty( $trigger_hooks ) )
            {
                continue;
            }

            $mapping_is_async = $this->is_mapping_async( $mapping );

            foreach ( $trigger_hooks as $hook )
            {
                $dependency_ids = $planner->extract_dependency_ids_for_hook( $mapping, $hook );
                if ( empty( $dependency_ids ) )
                {
                    continue;
                }

                foreach ( $dependency_ids as $dependency_id )
                {
                    if ( ! isset( $normalized[ $dependency_id ] ) )
                    {
                        $key = sprintf( 'missing_dependency:%s:%s:%s', $mapping_id, $dependency_id, $hook );
                        if ( isset( $violation_lookup[ $key ] ) )
                        {
                            continue;
                        }

                        $violations[] = [
                            'mapping_id'    => $mapping_id,
                            'dependency_id' => $dependency_id,
                            'code'          => 'missing_dependency',
                            'message'       => sprintf(
                                /* translators: 1: mapping id, 2: dependency mapping id, 3: hook id. */
                                __( 'Mapping %1$s depends on unknown mapping %2$s in hook %3$s.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id ),
                                sanitize_text_field( $dependency_id ),
                                sanitize_text_field( $hook )
                            ),
                        ];
                        $violation_lookup[ $key ] = true;
                        continue;
                    }

                    $dependency_hooks = $this->sanitize_trigger_hooks( (array) ( $normalized[ $dependency_id ]['trigger_hooks'] ?? [] ) );
                    if ( ! $this->dependency_satisfies_hook( $hook, $dependency_hooks ) )
                    {
                        $key = sprintf( 'hook_mismatch:%s:%s:%s', $mapping_id, $dependency_id, $hook );
                        if ( isset( $violation_lookup[ $key ] ) )
                        {
                            continue;
                        }

                        $violations[] = [
                            'mapping_id'    => $mapping_id,
                            'dependency_id' => $dependency_id,
                            'code'          => 'hook_mismatch',
                            'message'       => sprintf(
                                /* translators: 1: mapping id, 2: dependency mapping id, 3: hook id. */
                                __( 'Mapping %1$s depends on %2$s in hook %3$s, but %2$s does not run on that hook.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id ),
                                sanitize_text_field( $dependency_id ),
                                sanitize_text_field( $hook )
                            ),
                        ];
                        $violation_lookup[ $key ] = true;
                        continue;
                    }

                    if ( Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION !== $hook )
                    {
                        continue;
                    }

                    $dependency_is_async = $this->is_mapping_async( $normalized[ $dependency_id ] );
                    if ( $dependency_is_async && ! $mapping_is_async )
                    {
                        $key = sprintf( 'execution_mode_mismatch:%s:%s:%s', $mapping_id, $dependency_id, $hook );
                        if ( isset( $violation_lookup[ $key ] ) )
                        {
                            continue;
                        }

                        $violations[] = [
                            'mapping_id'    => $mapping_id,
                            'dependency_id' => $dependency_id,
                            'code'          => 'execution_mode_mismatch',
                            'message'       => sprintf(
                                /* translators: 1: mapping id, 2: dependency mapping id. */
                                __( 'Mapping %1$s depends on Background mapping %2$s during after-submission, so %1$s must also run in Background.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id ),
                                sanitize_text_field( $dependency_id )
                            ),
                        ];
                        $violation_lookup[ $key ] = true;
                    }
                }
            }
        }

        return array_values( $violations );
    }

    /**
     * Realtime virtual Q&A persists generated JSON into a form field. Keep that
     * target limited to fields intended for generated/internal text so normal
     * visitor answers are not overwritten.
     *
     * @param array<string, mixed> $settings
     */
    private function validate_realtime_storage_target( string $form_source, string $form_id, array $settings ): true | WP_Error
    {
        $realtime_settings = is_array( $settings['realtime_settings'] ?? null )
            ? $settings['realtime_settings']
            : [];
        $target_field_id = isset( $realtime_settings['storage_target_field_id'] ) && is_scalar( $realtime_settings['storage_target_field_id'] )
            ? trim( sanitize_text_field( (string) $realtime_settings['storage_target_field_id'] ) )
            : '';

        if ( '' === $target_field_id )
        {
            return true;
        }

        $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $adapter  = $registry ? $registry->get_adapter_by_id( $form_source ) : null;
        if ( ! $adapter )
        {
            return $this->prepare_error_response(
                'rest_invalid_realtime_storage_target',
                __( 'Realtime storage target could not be validated because the form adapter is unavailable.', 'sentient-forms' ),
                400
            );
        }

        $fields = $adapter->get_form_fields( $form_id );
        foreach ( $fields as $field )
        {
            $field_id = $this->extract_form_field_property( $field, 'id' );
            if ( $target_field_id !== $field_id )
            {
                continue;
            }

            $field_type = strtolower( $this->extract_form_field_property( $field, 'type' ) );
            if ( in_array( $field_type, [ 'hidden', 'textarea' ], true ) )
            {
                return true;
            }

            return $this->prepare_error_response(
                'rest_invalid_realtime_storage_target',
                __( 'Realtime virtual Q&A storage must target a dedicated hidden field or textarea.', 'sentient-forms' ),
                400
            );
        }

        return $this->prepare_error_response(
            'rest_invalid_realtime_storage_target',
            __( 'Realtime virtual Q&A storage field was not found on this form.', 'sentient-forms' ),
            400
        );
    }

    private function extract_form_field_property( mixed $field, string $property ): string
    {
        if ( is_array( $field ) && isset( $field[ $property ] ) && is_scalar( $field[ $property ] ) )
        {
            return sanitize_text_field( (string) $field[ $property ] );
        }

        if ( is_object( $field ) && isset( $field->{$property} ) && is_scalar( $field->{$property} ) )
        {
            return sanitize_text_field( (string) $field->{$property} );
        }

        return '';
    }

    /**
     * Validates mapping settings payloads for strict config writes.
     *
     * @param mixed $settings Raw settings payload.
     * @return WP_Error|null
     */
    private function validate_settings_write_payload( mixed $settings ): ?WP_Error
    {
        if ( null === $settings )
        {
            return null;
        }

        if ( ! is_array( $settings ) )
        {
            return $this->invalid_settings_write_error(
                'settings',
                __( 'Mapping settings must be an object.', 'sentient-forms' )
            );
        }

        foreach ( [ 'spam_positive_examples', 'spam_negative_examples' ] as $field )
        {
            if ( ! array_key_exists( $field, $settings ) )
            {
                continue;
            }

            $value = $settings[ $field ];
            if ( null === $value || ( is_array( $value ) && [] === $value ) )
            {
                continue;
            }

            $validation = $this->validate_spam_guidance_examples_for_write( $field, $value );
            if ( is_wp_error( $validation ) )
            {
                return $validation;
            }
        }

        foreach ( [ 'suppress_notifications_on_spam', 'suppress_webhooks_on_spam', 'skip_downstream_on_spam' ] as $field )
        {
            if ( ! array_key_exists( $field, $settings ) )
            {
                continue;
            }

            $value = $settings[ $field ];
            if ( null !== $value && ! is_bool( $value ) )
            {
                return $this->invalid_settings_write_error(
                    $field,
                    __( 'Spam policy controls must be JSON booleans.', 'sentient-forms' )
                );
            }
        }

        if ( array_key_exists( 'spam_confidence_threshold', $settings ) )
        {
            $value = $settings['spam_confidence_threshold'];
            if ( null !== $value && ( ! is_int( $value ) && ! is_float( $value ) || $value < 0 || $value > 1 ) )
            {
                return $this->invalid_settings_write_error(
                    'spam_confidence_threshold',
                    __( 'Spam confidence threshold must be a JSON number between 0 and 1.', 'sentient-forms' )
                );
            }
        }

        if ( array_key_exists( 'action_customization', $settings ) )
        {
            $value = $settings['action_customization'];
            if ( null !== $value )
            {
                if ( ! is_string( $value ) )
                {
                    return $this->invalid_settings_write_error(
                        'action_customization',
                        __( 'Action customization must be a string.', 'sentient-forms' )
                    );
                }

                if ( mb_strlen( trim( $value ) ) > 2000 )
                {
                    return $this->invalid_settings_write_error(
                        'action_customization',
                        __( 'Action customization must be 2000 characters or fewer.', 'sentient-forms' )
                    );
                }
            }
        }

        if ( array_key_exists( 'realtime_settings', $settings ) )
        {
            $validation = $this->validate_realtime_settings_for_write( $settings['realtime_settings'] );
            if ( is_wp_error( $validation ) )
            {
                return $validation;
            }
        }

        return null;
    }

    private function validate_realtime_settings_for_write( mixed $value ): ?WP_Error
    {
        if ( null === $value )
        {
            return null;
        }

        if ( ! is_array( $value ) )
        {
            return $this->invalid_settings_write_error(
                'realtime_settings',
                __( 'Realtime settings must be an object.', 'sentient-forms' )
            );
        }

        foreach ( [ 'auto_refresh_enabled', 'field_checkpoints_enabled', 'page_checkpoints_enabled', 'manual_refresh_enabled', 'pre_submit_run_enabled' ] as $field )
        {
            if ( array_key_exists( $field, $value ) && ! is_bool( $value[ $field ] ) )
            {
                return $this->invalid_settings_write_error(
                    'realtime_settings.' . $field,
                    __( 'Realtime toggle controls must be JSON booleans.', 'sentient-forms' )
                );
            }
        }

        foreach ( [ 'debounce_ms', 'cooldown_ms', 'page_checkpoint_timeout_ms', 'pre_submit_timeout_ms' ] as $field )
        {
            if ( array_key_exists( $field, $value ) && ! is_int( $value[ $field ] ) && ! is_float( $value[ $field ] ) )
            {
                return $this->invalid_settings_write_error(
                    'realtime_settings.' . $field,
                    __( 'Realtime timing controls must be JSON numbers.', 'sentient-forms' )
                );
            }
        }

        foreach ( [ 'checkpoint_field_ids', 'page_checkpoint_pages' ] as $field )
        {
            if ( array_key_exists( $field, $value ) && ! is_array( $value[ $field ] ) )
            {
                return $this->invalid_settings_write_error(
                    'realtime_settings.' . $field,
                    __( 'Realtime checkpoint controls must be JSON arrays.', 'sentient-forms' )
                );
            }
        }

        return null;
    }

    /**
     * @param string $field Field being validated.
     * @param mixed  $value Raw field value.
     * @return WP_Error|null
     */
    private function validate_spam_guidance_examples_for_write( string $field, mixed $value ): ?WP_Error
    {
        if ( ! is_array( $value ) )
        {
            return $this->invalid_settings_write_error(
                $field,
                __( 'Spam guidance examples must be an array of objects.', 'sentient-forms' )
            );
        }

        if ( count( $value ) > 10 )
        {
            return $this->invalid_settings_write_error(
                $field,
                __( 'Spam guidance examples are limited to 10 examples per list.', 'sentient-forms' )
            );
        }

        foreach ( $value as $example )
        {
            if ( ! is_array( $example ) )
            {
                return $this->invalid_settings_write_error(
                    $field,
                    __( 'Each spam guidance example must be an object.', 'sentient-forms' )
                );
            }

            $extra_keys = array_diff( array_keys( $example ), [ 'text', 'rationale' ] );
            if ( [] !== $extra_keys )
            {
                return $this->invalid_settings_write_error(
                    $field,
                    __( 'Spam guidance examples may only include text and rationale.', 'sentient-forms' )
                );
            }

            foreach ( [ 'text', 'rationale' ] as $example_field )
            {
                if ( ! array_key_exists( $example_field, $example ) || ! is_string( $example[ $example_field ] ) )
                {
                    return $this->invalid_settings_write_error(
                        $field,
                        __( 'Each spam guidance example must include string text and rationale fields.', 'sentient-forms' )
                    );
                }

                $trimmed = trim( $example[ $example_field ] );
                if ( '' === $trimmed || mb_strlen( $trimmed ) > 800 )
                {
                    return $this->invalid_settings_write_error(
                        $field,
                        __( 'Spam guidance example text and rationale must be between 1 and 800 characters.', 'sentient-forms' )
                    );
                }
            }
        }

        return null;
    }

    private function invalid_settings_write_error( string $field, string $message ): WP_Error
    {
        return $this->prepare_error_response(
            'rest_invalid_action_config',
            $message,
            400,
            [ 'field' => $field ],
        );
    }

    /**
     * Sanitize settings array recursively.
     */
    private function sanitize_settings( $settings ): array
    {
        if ( ! is_array( $settings ) )
        {
            return [];
        }

        $sanitized = [];
        foreach ( $settings as $key => $value )
        {
            $key = sanitize_key( $key );

            // CB-EXEC-003/004: Dedicated sanitisation for batch_settings.
            if ( 'batch_settings' === $key && is_array( $value ) )
            {
                $sanitized[ $key ] = $this->sanitize_batch_settings( $value );
                continue;
            }

            if ( 'conditions' === $key && is_array( $value ) )
            {
                $sanitized[ $key ] = $this->sanitize_conditions( $value );
                continue;
            }

            if ( 'dependency_ids' === $key && is_array( $value ) )
            {
                $sanitized[ $key ] = $this->sanitize_dependency_ids( $value );
                continue;
            }

            if ( 'trigger_sources' === $key && is_array( $value ) )
            {
                $sanitized[ $key ] = $this->sanitize_trigger_sources( $value );
                continue;
            }

            if ( 'attachment_mapping' === $key && is_array( $value ) )
            {
                $sanitized[ $key ] = $this->sanitize_attachment_mapping( $value );
                continue;
            }

            if ( 'skip_on_upstream_spam' === $key )
            {
                $sanitized[ $key ] = rest_sanitize_boolean( $value );
                continue;
            }

            if ( in_array( $key, [ 'suppress_notifications_on_spam', 'suppress_webhooks_on_spam', 'skip_downstream_on_spam' ], true ) )
            {
                $sanitized[ $key ] = rest_sanitize_boolean( $value );
                continue;
            }

            if ( in_array( $key, [ 'spam_positive_examples', 'spam_negative_examples' ], true ) )
            {
                $sanitized[ $key ] = $this->sanitize_spam_guidance_examples( $value );
                continue;
            }

            if ( 'action_customization' === $key )
            {
                $sanitized[ $key ] = $this->sanitize_action_customization( $value );
                continue;
            }

            if ( 'realtime_settings' === $key )
            {
                $sanitized[ $key ] = $this->sanitize_realtime_settings_for_mapping( $value );
                continue;
            }

            if ( is_array( $value ) )
            {
                $sanitized[ $key ] = $this->sanitize_settings( $value );
            }
            elseif ( is_bool( $value ) || is_numeric( $value ) )
            {
                // Allow booleans and numbers (int/float) to pass through
                $sanitized[ $key ] = $value;
            }
            else
            {
                $sanitized[ $key ] = sanitize_text_field( (string)$value );
            }
        }

        return $sanitized;
    }

    /**
     * @return array<string,mixed>
     */
    private function sanitize_realtime_settings_for_mapping( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $legacy_refresh_mode = isset( $value['refresh_mode'] ) && is_scalar( $value['refresh_mode'] )
            ? sanitize_key( (string) $value['refresh_mode'] )
            : 'auto';
        if ( ! in_array( $legacy_refresh_mode, [ 'auto', 'checkpoint', 'manual' ], true ) )
        {
            $legacy_refresh_mode = 'auto';
        }

        $auto_refresh_enabled = array_key_exists( 'auto_refresh_enabled', $value )
            ? rest_sanitize_boolean( $value['auto_refresh_enabled'] )
            : 'auto' === $legacy_refresh_mode;
        $field_checkpoints_enabled = array_key_exists( 'field_checkpoints_enabled', $value )
            ? rest_sanitize_boolean( $value['field_checkpoints_enabled'] )
            : 'checkpoint' === $legacy_refresh_mode;
        $page_checkpoints_enabled = array_key_exists( 'page_checkpoints_enabled', $value )
            ? rest_sanitize_boolean( $value['page_checkpoints_enabled'] )
            : false;
        $refresh_mode = $auto_refresh_enabled
            ? 'auto'
            : ( $field_checkpoints_enabled || $page_checkpoints_enabled ? 'checkpoint' : 'manual' );

        $hidden_field_exposure_mode = isset( $value['hidden_field_exposure_mode'] ) && is_scalar( $value['hidden_field_exposure_mode'] )
            ? sanitize_key( (string) $value['hidden_field_exposure_mode'] )
            : 'label_hidden';
        if ( ! in_array( $hidden_field_exposure_mode, self::REALTIME_HIDDEN_FIELD_EXPOSURE_MODES, true ) )
        {
            $hidden_field_exposure_mode = 'label_hidden';
        }

        $initial_panel_state = isset( $value['initial_panel_state'] ) && is_scalar( $value['initial_panel_state'] )
            ? sanitize_key( (string) $value['initial_panel_state'] )
            : 'minimized';
        if ( ! in_array( $initial_panel_state, [ 'open', 'minimized', 'hidden_until_interaction' ], true ) )
        {
            $initial_panel_state = 'minimized';
        }

        $page_checkpoint_mode = isset( $value['page_checkpoint_mode'] ) && is_scalar( $value['page_checkpoint_mode'] )
            ? sanitize_key( (string) $value['page_checkpoint_mode'] )
            : 'all_pages';
        if ( ! in_array( $page_checkpoint_mode, self::REALTIME_PAGE_CHECKPOINT_MODES, true ) )
        {
            $page_checkpoint_mode = 'all_pages';
        }

        return [
            'auto_refresh_enabled'       => $auto_refresh_enabled,
            'field_checkpoints_enabled'  => $field_checkpoints_enabled,
            'checkpoint_field_ids'       => $this->sanitize_realtime_string_list( $value['checkpoint_field_ids'] ?? [] ),
            'page_checkpoints_enabled'   => $page_checkpoints_enabled,
            'page_checkpoint_mode'       => $page_checkpoint_mode,
            'page_checkpoint_pages'      => $this->sanitize_realtime_positive_int_list( $value['page_checkpoint_pages'] ?? [] ),
            'page_checkpoint_timeout_ms' => $this->normalize_realtime_millis( $value['page_checkpoint_timeout_ms'] ?? 2500, 500, 10000, 2500 ),
            'storage_target_field_id'    => isset( $value['storage_target_field_id'] ) && is_scalar( $value['storage_target_field_id'] )
                ? sanitize_text_field( (string) $value['storage_target_field_id'] )
                : '',
            'debounce_ms'                => $this->normalize_realtime_millis( $value['debounce_ms'] ?? 900, 250, 5000, 900 ),
            'cooldown_ms'                => $this->normalize_realtime_millis( $value['cooldown_ms'] ?? 8000, 0, 60000, 8000 ),
            'manual_refresh_enabled'     => array_key_exists( 'manual_refresh_enabled', $value )
                ? rest_sanitize_boolean( $value['manual_refresh_enabled'] )
                : true,
            'blocking_mode'              => isset( $value['blocking_mode'] )
                && 'require_answers' === sanitize_key( (string) $value['blocking_mode'] )
                ? 'require_answers'
                : 'advisory',
            'refresh_mode'               => $refresh_mode,
            'initial_panel_state'        => $initial_panel_state,
            'hidden_field_exposure_mode' => $hidden_field_exposure_mode,
            'pre_submit_run_enabled'     => array_key_exists( 'pre_submit_run_enabled', $value )
                ? rest_sanitize_boolean( $value['pre_submit_run_enabled'] )
                : false,
            'pre_submit_timeout_ms'      => $this->normalize_realtime_millis( $value['pre_submit_timeout_ms'] ?? 2500, 500, 10000, 2500 ),
        ];
    }

    private function normalize_realtime_millis( mixed $value, int $min, int $max, int $fallback ): int
    {
        $normalized = is_numeric( $value ) ? (int) $value : $fallback;

        return max( $min, min( $max, $normalized ) );
    }

    /**
     * @return array<int,string>
     */
    private function sanitize_realtime_string_list( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $items = [];
        foreach ( $value as $item )
        {
            if ( ! is_scalar( $item ) )
            {
                continue;
            }

            $normalized = trim( sanitize_text_field( (string) $item ) );
            if ( '' !== $normalized )
            {
                $items[] = $normalized;
            }
        }

        return array_values( array_unique( $items ) );
    }

    /**
     * @return array<int,int>
     */
    private function sanitize_realtime_positive_int_list( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $items = [];
        foreach ( $value as $item )
        {
            if ( ! is_scalar( $item ) )
            {
                continue;
            }

            $number = absint( $item );
            if ( $number > 0 )
            {
                $items[] = min( 200, $number );
            }
        }

        $items = array_values( array_unique( $items ) );
        sort( $items );

        return $items;
    }

    /**
     * @param mixed $value
     * @return array<int, array{text: string, rationale: string}>
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

            $text      = isset( $example['text'] ) && is_scalar( $example['text'] )
                ? trim( sanitize_textarea_field( (string) $example['text'] ) )
                : '';
            $rationale = isset( $example['rationale'] ) && is_scalar( $example['rationale'] )
                ? trim( sanitize_textarea_field( (string) $example['rationale'] ) )
                : '';

            if ( '' === $text || '' === $rationale )
            {
                continue;
            }

            $sanitized[] = [
                'text'      => mb_substr( $text, 0, 800 ),
                'rationale' => mb_substr( $rationale, 0, 800 ),
            ];

            if ( count( $sanitized ) >= 10 )
            {
                break;
            }
        }

        return $sanitized;
    }

    private function sanitize_action_customization( mixed $value ): string
    {
        if ( ! is_scalar( $value ) )
        {
            return '';
        }

        return mb_substr( trim( sanitize_textarea_field( (string) $value ) ), 0, 2000 );
    }

    /**
     * CB-EXEC-003/004: Sanitise and clamp batch execution settings.
     *
     * @param array $raw Raw batch_settings from the client.
     * @return array {enabled: bool, delay_seconds: int, max_wait_seconds: int}
     */
    private function sanitize_batch_settings( array $raw ): array
    {
        return [
            'enabled'       => ! empty( $raw['enabled'] ),
            'delay_seconds' => max( 10, min( 3600, (int) ( $raw['delay_seconds'] ?? 60 ) ) ),
            'max_wait_seconds' => max( 43200, min( 604800, (int) ( $raw['max_wait_seconds'] ?? DAY_IN_SECONDS ) ) ),
        ];
    }

    /**
     * Sanitize attachment mapping settings used by file_ref serialization.
     *
     * @param array $raw Raw attachment mapping payload.
     * @return array{mode:string,gf_upload_field_ids:array<int,string>,media_ids:array<int,int>,max_files:int}
     */
    private function sanitize_attachment_mapping( array $raw ): array
    {
        if ( class_exists( 'Sentient_Forms_Attachment_File_Ref_Builder' ) )
        {
            return Sentient_Forms_Attachment_File_Ref_Builder::sanitize_attachment_mapping( $raw );
        }

        $mode = isset( $raw['mode'] ) && is_scalar( $raw['mode'] )
            ? sanitize_key( (string) $raw['mode'] )
            : 'none';
        if ( ! in_array( $mode, [ 'none', 'gf_upload', 'media_library', 'mixed' ], true ) )
        {
            $mode = 'none';
        }

        $gf_upload_field_ids = [];
        if ( isset( $raw['gf_upload_field_ids'] ) && is_array( $raw['gf_upload_field_ids'] ) )
        {
            foreach ( $raw['gf_upload_field_ids'] as $field_id )
            {
                if ( ! is_scalar( $field_id ) )
                {
                    continue;
                }

                $normalized = sanitize_text_field( (string) $field_id );
                if ( '' !== $normalized )
                {
                    $gf_upload_field_ids[] = $normalized;
                }
            }
        }

        $media_ids = [];
        if ( isset( $raw['media_ids'] ) && is_array( $raw['media_ids'] ) )
        {
            foreach ( $raw['media_ids'] as $media_id )
            {
                $normalized = (int) $media_id;
                if ( $normalized > 0 )
                {
                    $media_ids[] = $normalized;
                }
            }
        }

        $max_files = isset( $raw['max_files'] ) ? (int) $raw['max_files'] : 5;
        $max_files = max( 1, min( 20, $max_files ) );

        return [
            'mode'                => $mode,
            'gf_upload_field_ids' => array_values( array_unique( $gf_upload_field_ids ) ),
            'media_ids'           => array_values( array_unique( $media_ids ) ),
            'max_files'           => $max_files,
        ];
    }

    /**
     * Sanitize dependency IDs for mapping DAG relationships.
     *
     * @param array $dependency_ids Raw dependency identifiers.
     *
     * @return array<int, string>
     */
    private function sanitize_dependency_ids( array $dependency_ids ): array
    {
        $result = [];
        foreach ( $dependency_ids as $dependency_id )
        {
            if ( ! is_scalar( $dependency_id ) )
            {
                continue;
            }

            $normalized = sanitize_text_field( (string) $dependency_id );
            if ( '' === $normalized )
            {
                continue;
            }

            $result[] = $normalized;
        }

        return array_values( array_unique( $result ) );
    }

    /**
     * Sanitize per-hook trigger source definitions.
     *
     * @param array $trigger_sources Raw trigger sources keyed by hook id.
     *
     * @return array<string, array{type: string, mapping_id?: string}>
     */
    private function sanitize_trigger_sources( array $trigger_sources ): array
    {
        $sanitized = [];
        foreach ( $trigger_sources as $hook => $source )
        {
            if ( ! is_scalar( $hook ) || ! is_array( $source ) )
            {
                continue;
            }

            $hook_key = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $hook );
            if ( null === $hook_key )
            {
                continue;
            }

            $type = isset( $source['type'] ) && is_scalar( $source['type'] )
                ? sanitize_key( (string) $source['type'] )
                : '';
            if ( 'mapping' !== $type && 'hook_root' !== $type && 'unbound' !== $type )
            {
                continue;
            }

            if ( 'hook_root' === $type || 'unbound' === $type )
            {
                $sanitized[ $hook_key ] = [
                    'type' => $type,
                ];
                continue;
            }

            $mapping_id = '';
            if ( isset( $source['mapping_id'] ) && is_scalar( $source['mapping_id'] ) )
            {
                $mapping_id = sanitize_text_field( (string) $source['mapping_id'] );
            }
            elseif ( isset( $source['source_mapping_id'] ) && is_scalar( $source['source_mapping_id'] ) )
            {
                $mapping_id = sanitize_text_field( (string) $source['source_mapping_id'] );
            }

            if ( '' === $mapping_id )
            {
                continue;
            }

            $sanitized[ $hook_key ] = [
                'type'       => 'mapping',
                'mapping_id' => $mapping_id,
            ];
        }

        return $sanitized;
    }

    /**
     * Sanitize duplicate-parent request payload.
     *
     * @param mixed $parent Raw parent payload.
     *
     * @return array{type: string, hook: string, mapping_id?: string}|WP_Error
     */
    private function sanitize_duplicate_parent_request( $parent ): array | WP_Error
    {
        if ( ! is_array( $parent ) )
        {
            return new WP_Error(
                'rest_invalid_duplicate_parent',
                __( 'Duplicate parent payload must be an object.', 'sentient-forms' )
            );
        }

        $type = isset( $parent['type'] ) && is_scalar( $parent['type'] )
            ? sanitize_key( (string) $parent['type'] )
            : '';
        if ( 'mapping' !== $type && 'hook_root' !== $type )
        {
            return new WP_Error(
                'rest_invalid_duplicate_parent',
                __( 'Parent type must be "mapping" or "hook_root".', 'sentient-forms' )
            );
        }

        $hook = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $parent['hook'] ?? null );
        if ( null === $hook )
        {
            return new WP_Error(
                'rest_invalid_duplicate_parent_hook',
                __( 'Parent hook is missing or not supported.', 'sentient-forms' )
            );
        }

        $normalized = [
            'type' => $type,
            'hook' => $hook,
        ];

        if ( 'mapping' === $type )
        {
            $mapping_id = isset( $parent['mapping_id'] ) && is_scalar( $parent['mapping_id'] )
                ? sanitize_text_field( (string) $parent['mapping_id'] )
                : '';
            if ( '' === $mapping_id )
            {
                return new WP_Error(
                    'rest_invalid_duplicate_parent_mapping',
                    __( 'Parent mapping_id is required when parent type is mapping.', 'sentient-forms' )
                );
            }
            $normalized['mapping_id'] = $mapping_id;
        }

        return $normalized;
    }

    /**
     * Normalize trigger source graph for all hooks on a mapping.
     *
     * Uses explicit trigger_sources when present, otherwise derives a deterministic
     * fallback from legacy dependency_ids.
     *
     * @param array<string, mixed>                       $mapping       Mapping payload.
     * @param array<int, string>                         $trigger_hooks Mapping trigger hooks.
     * @param Sentient_Forms_Mapping_Dependency_Planner  $planner       Planner service.
     *
     * @return array<string, array{type: string, mapping_id?: string}>
     */
    private function build_mapping_trigger_sources_for_hooks(
        array $mapping,
        array $trigger_hooks,
        Sentient_Forms_Mapping_Dependency_Planner $planner
    ): array
    {
        $trigger_hooks = $this->sanitize_trigger_hooks( $trigger_hooks );
        if ( empty( $trigger_hooks ) )
        {
            return [];
        }

        $explicit = $planner->extract_trigger_sources( $mapping, $trigger_hooks );
        $has_explicit = ! empty( $explicit );
        $normalized = [];

        if ( $has_explicit )
        {
            foreach ( $trigger_hooks as $hook )
            {
                $source = $explicit[ $hook ] ?? [ 'type' => 'hook_root' ];
                $type = isset( $source['type'] ) && is_scalar( $source['type'] )
                    ? sanitize_key( (string) $source['type'] )
                    : 'hook_root';
                if ( 'mapping' !== $type && 'unbound' !== $type )
                {
                    $normalized[ $hook ] = [ 'type' => 'hook_root' ];
                    continue;
                }

                if ( 'unbound' === $type )
                {
                    $normalized[ $hook ] = [ 'type' => 'unbound' ];
                    continue;
                }

                $mapping_id = isset( $source['mapping_id'] ) && is_scalar( $source['mapping_id'] )
                    ? sanitize_text_field( (string) $source['mapping_id'] )
                    : '';
                if ( '' === $mapping_id )
                {
                    $normalized[ $hook ] = [ 'type' => 'hook_root' ];
                    continue;
                }

                $normalized[ $hook ] = [
                    'type'       => 'mapping',
                    'mapping_id' => $mapping_id,
                ];
            }

            return $normalized;
        }

        $legacy_dependency_ids = $this->sanitize_dependency_ids( $planner->extract_dependency_ids( $mapping ) );
        if ( 1 === count( $legacy_dependency_ids ) )
        {
            foreach ( $trigger_hooks as $hook )
            {
                $normalized[ $hook ] = [
                    'type'       => 'mapping',
                    'mapping_id' => $legacy_dependency_ids[0],
                ];
            }
            return $normalized;
        }

        foreach ( $trigger_hooks as $hook )
        {
            $normalized[ $hook ] = [ 'type' => 'hook_root' ];
        }

        return $normalized;
    }

    /**
     * Apply hook-scoped trigger source to a mapping and recompute dependency_ids.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     * @param string               $hook    Target hook.
     * @param array<string, mixed> $source  Source payload with type/mapping_id.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function set_mapping_trigger_source_for_hook( array $mapping, string $hook, array $source ): array | WP_Error
    {
        $hook = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $hook );
        if ( null === $hook )
        {
            return new WP_Error(
                'rest_invalid_duplicate_parent_hook',
                __( 'Cannot apply trigger source to an unsupported hook.', 'sentient-forms' )
            );
        }

        $trigger_hooks = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
        if ( ! in_array( $hook, $trigger_hooks, true ) )
        {
            return new WP_Error(
                'rest_invalid_duplicate_parent_hook',
                __( 'Cannot apply trigger source to a hook that the mapping does not use.', 'sentient-forms' )
            );
        }

        $source_type = isset( $source['type'] ) && is_scalar( $source['type'] )
            ? sanitize_key( (string) $source['type'] )
            : '';
        if ( 'mapping' !== $source_type && 'hook_root' !== $source_type )
        {
            return new WP_Error(
                'rest_invalid_duplicate_parent',
                __( 'Invalid trigger source type.', 'sentient-forms' )
            );
        }

        $planner = Sentient_Forms_Plugin::instance()->get_mapping_dependency_planner();
        $trigger_sources = $this->build_mapping_trigger_sources_for_hooks( $mapping, $trigger_hooks, $planner );

        if ( 'hook_root' === $source_type )
        {
            $trigger_sources[ $hook ] = [ 'type' => 'hook_root' ];
        }
        else
        {
            $mapping_id = isset( $source['mapping_id'] ) && is_scalar( $source['mapping_id'] )
                ? sanitize_text_field( (string) $source['mapping_id'] )
                : '';
            if ( '' === $mapping_id )
            {
                return new WP_Error(
                    'rest_invalid_duplicate_parent_mapping',
                    __( 'Mapping trigger source requires a mapping_id.', 'sentient-forms' )
                );
            }

            $trigger_sources[ $hook ] = [
                'type'       => 'mapping',
                'mapping_id' => $mapping_id,
            ];
        }

        $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
            ? $mapping['settings']
            : [];
        $settings['trigger_sources'] = $this->serialize_trigger_sources_for_storage( $trigger_sources );

        $dependency_ids = $this->derive_dependency_ids_from_trigger_sources( $trigger_sources );
        if ( empty( $dependency_ids ) )
        {
            unset( $settings['dependency_ids'] );
        }
        else
        {
            $settings['dependency_ids'] = $dependency_ids;
        }

        $mapping['trigger_hooks'] = $trigger_hooks;
        $mapping['settings'] = $settings;

        return $mapping;
    }

    /**
     * Derive unique dependency_ids from trigger source graph.
     *
     * @param array<string, array{type: string, mapping_id?: string}> $trigger_sources Trigger source graph.
     *
     * @return array<int, string>
     */
    private function derive_dependency_ids_from_trigger_sources( array $trigger_sources ): array
    {
        $dependencies = [];
        foreach ( $trigger_sources as $source )
        {
            if ( ! is_array( $source ) )
            {
                continue;
            }

            $type = isset( $source['type'] ) && is_scalar( $source['type'] )
                ? sanitize_key( (string) $source['type'] )
                : '';
            if ( 'mapping' !== $type )
            {
                continue;
            }

            $mapping_id = isset( $source['mapping_id'] ) && is_scalar( $source['mapping_id'] )
                ? sanitize_text_field( (string) $source['mapping_id'] )
                : '';
            if ( '' === $mapping_id )
            {
                continue;
            }

            $dependencies[] = $mapping_id;
        }

        return array_values( array_unique( $dependencies ) );
    }

    /**
     * Serialize trigger source graph for storage in settings.trigger_sources.
     *
     * @param array<string, array{type: string, mapping_id?: string}> $trigger_sources Trigger source graph.
     *
     * @return array<string, array{type: string, mapping_id?: string}>
     */
    private function serialize_trigger_sources_for_storage( array $trigger_sources ): array
    {
        $serialized = [];
        foreach ( $trigger_sources as $hook => $source )
        {
            $hook_key = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $hook );
            if ( null === $hook_key )
            {
                continue;
            }

            if ( ! is_array( $source ) )
            {
                continue;
            }

            $type = isset( $source['type'] ) && is_scalar( $source['type'] )
                ? sanitize_key( (string) $source['type'] )
                : '';
            if ( 'mapping' !== $type && 'unbound' !== $type )
            {
                $serialized[ $hook_key ] = [ 'type' => 'hook_root' ];
                continue;
            }

            if ( 'unbound' === $type )
            {
                $serialized[ $hook_key ] = [ 'type' => 'unbound' ];
                continue;
            }

            $mapping_id = isset( $source['mapping_id'] ) && is_scalar( $source['mapping_id'] )
                ? sanitize_text_field( (string) $source['mapping_id'] )
                : '';
            if ( '' === $mapping_id )
            {
                $serialized[ $hook_key ] = [ 'type' => 'hook_root' ];
                continue;
            }

            $serialized[ $hook_key ] = [
                'type'       => 'mapping',
                'mapping_id' => $mapping_id,
            ];
        }

        return $serialized;
    }

    /**
     * Find parent children (before duplicate insertion) that are eligible to rewire for hook.
     *
     * @param array<string, mixed>                      $actions Raw action map.
     * @param array{type: string, hook: string, mapping_id?: string} $parent Parent selection payload.
     * @param string                                    $hook Hook scope.
     * @param Sentient_Forms_Mapping_Dependency_Planner $planner Planner service.
     *
     * @return array<int, string>
     */
    private function find_parent_children_for_hook(
        array $actions,
        array $parent,
        string $hook,
        Sentient_Forms_Mapping_Dependency_Planner $planner
    ): array
    {
        $hook = sanitize_key( $hook );
        if ( '' === $hook )
        {
            return [];
        }

        $children = [];
        $normalized = $this->normalize_local_action_mappings( $actions );
        foreach ( $normalized as $mapping_id => $mapping )
        {
            $trigger_hooks = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
            if ( ! in_array( $hook, $trigger_hooks, true ) )
            {
                continue;
            }

            $sources = $this->build_mapping_trigger_sources_for_hooks( $mapping, $trigger_hooks, $planner );
            $hook_source = $sources[ $hook ] ?? [ 'type' => 'hook_root' ];
            $hook_source_type = isset( $hook_source['type'] ) && is_scalar( $hook_source['type'] )
                ? sanitize_key( (string) $hook_source['type'] )
                : 'hook_root';
            $hook_source_mapping = isset( $hook_source['mapping_id'] ) && is_scalar( $hook_source['mapping_id'] )
                ? sanitize_text_field( (string) $hook_source['mapping_id'] )
                : '';

            if ( 'mapping' === $parent['type'] )
            {
                $parent_mapping_id = sanitize_text_field( (string) ( $parent['mapping_id'] ?? '' ) );
                if ( 'mapping' === $hook_source_type && $hook_source_mapping === $parent_mapping_id )
                {
                    $children[] = $mapping_id;
                }
                continue;
            }

            if ( 'hook_root' === $hook_source_type )
            {
                $children[] = $mapping_id;
            }
        }

        sort( $children );
        return $children;
    }

    /**
     * Map dependency-validation WP_Error codes into duplicate rewire skip codes.
     */
    private function map_dependency_validation_error_to_skip_code( string $wp_error_code ): string
    {
        $normalized = sanitize_key( $wp_error_code );
        return match ( $normalized )
        {
            'rest_invalid_dependency_execution_mode' => 'execution_mode_mismatch',
            'rest_invalid_dependency_hooks' => 'hook_mismatch',
            'rest_invalid_dependency_cycle' => 'cycle',
            'rest_invalid_dependency_missing' => 'missing_dependency',
            default => 'policy_violation',
        };
    }

    /**
     * Validate dependency graph integrity for current form mappings.
     *
     * @param array $actions Raw stored action map keyed by local mapping id.
     *
     * @return true|WP_Error
     */
    private function validate_mapping_dependencies( array $actions ): true | WP_Error
    {
        $normalized = $this->normalize_local_action_mappings( $actions );
        if ( empty( $normalized ) )
        {
            return true;
        }

        $planner = Sentient_Forms_Plugin::instance()->get_mapping_dependency_planner();

        $cycle_ids = [];
        foreach ( self::ALLOWED_TRIGGER_HOOKS as $hook )
        {
            $plan = $planner->build_execution_plan( $normalized, $hook );
            foreach ( (array) ( $plan['cycle_ids'] ?? [] ) as $cycle_id )
            {
                $cycle_ids[] = $cycle_id;
            }
        }
        $cycle_ids = array_values( array_unique( array_map( 'sanitize_text_field', $cycle_ids ) ) );
        if ( ! empty( $cycle_ids ) )
        {
            return new WP_Error(
                'rest_invalid_dependency_cycle',
                sprintf(
                    /* translators: %s: comma-separated mapping ids */
                    __( 'Dependency graph contains a cycle: %s', 'sentient-forms' ),
                    implode( ', ', $cycle_ids )
                )
            );
        }

        foreach ( $normalized as $mapping_id => $mapping )
        {
            $trigger_hooks = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
            if ( empty( $trigger_hooks ) )
            {
                continue;
            }

            $mapping_is_async = $this->is_mapping_async( $mapping );
            $skip_on_upstream_spam_validation = $this->validate_skip_on_upstream_spam_dependency( $mapping_id, $mapping, $normalized, $planner );
            if ( is_wp_error( $skip_on_upstream_spam_validation ) )
            {
                return $skip_on_upstream_spam_validation;
            }

            foreach ( $trigger_hooks as $hook )
            {
                $dependency_ids = $planner->extract_dependency_ids_for_hook( $mapping, $hook );
                if ( empty( $dependency_ids ) )
                {
                    continue;
                }

                foreach ( $dependency_ids as $dependency_id )
                {
                    if ( $dependency_id === $mapping_id )
                    {
                        return new WP_Error(
                            'rest_invalid_dependency_self',
                            sprintf(
                                /* translators: %s: mapping id */
                                __( 'Mapping %s cannot depend on itself.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id )
                            )
                        );
                    }

                    if ( ! isset( $normalized[ $dependency_id ] ) )
                    {
                        return new WP_Error(
                            'rest_invalid_dependency_missing',
                            sprintf(
                                /* translators: 1: mapping id, 2: dependency id */
                                __( 'Mapping %1$s depends on unknown mapping %2$s.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id ),
                                sanitize_text_field( $dependency_id )
                            )
                        );
                    }

                    $dependency_hooks = $this->sanitize_trigger_hooks( (array) ( $normalized[ $dependency_id ]['trigger_hooks'] ?? [] ) );
                    if ( ! $this->dependency_satisfies_hook( $hook, $dependency_hooks ) )
                    {
                        return new WP_Error(
                            'rest_invalid_dependency_hooks',
                            sprintf(
                                /* translators: 1: mapping id, 2: dependency id, 3: hook name */
                                __( 'Mapping %1$s depends on %2$s in hook %3$s, but %2$s does not run on that hook.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id ),
                                sanitize_text_field( $dependency_id ),
                                sanitize_text_field( $hook )
                            )
                        );
                    }

                    if ( Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION !== $hook )
                    {
                        continue;
                    }

                    $dependency_is_async = $this->is_mapping_async( $normalized[ $dependency_id ] );
                    if ( $dependency_is_async && ! $mapping_is_async )
                    {
                        return new WP_Error(
                            'rest_invalid_dependency_execution_mode',
                            sprintf(
                                /* translators: 1: mapping id, 2: dependency id */
                                __( 'Mapping %1$s depends on Background mapping %2$s during after-submission, so %1$s must also run in Background.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id ),
                                sanitize_text_field( $dependency_id )
                            )
                        );
                    }
                }
            }
        }

        return true;
    }

    /**
     * Validate the narrow skip_on_upstream_spam mapping option.
     *
     * This option is only valid when at least one active trigger hook resolves
     * to exactly one upstream spam_detection_v1 mapping.
     *
     * @param string                                   $mapping_id Mapping id.
     * @param array<string, mixed>                     $mapping    Mapping payload.
     * @param array<string, array<string, mixed>>      $normalized All normalized mappings.
     * @param Sentient_Forms_Mapping_Dependency_Planner $planner   Dependency planner.
     *
     * @return true|WP_Error
     */
    private function validate_skip_on_upstream_spam_dependency(
        string $mapping_id,
        array $mapping,
        array $normalized,
        Sentient_Forms_Mapping_Dependency_Planner $planner
    ): true | WP_Error
    {
        if ( ! $this->is_skip_on_upstream_spam_enabled( $mapping ) )
        {
            return true;
        }

        $trigger_hooks = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
        foreach ( $trigger_hooks as $hook )
        {
            $dependency_ids = $planner->extract_dependency_ids_for_hook( $mapping, $hook );
            if ( 1 !== count( $dependency_ids ) )
            {
                continue;
            }

            $dependency_id = sanitize_text_field( (string) $dependency_ids[0] );
            if ( '' === $dependency_id || ! isset( $normalized[ $dependency_id ] ) )
            {
                continue;
            }

            $dependency_hooks = $this->sanitize_trigger_hooks( (array) ( $normalized[ $dependency_id ]['trigger_hooks'] ?? [] ) );
            if ( ! $this->dependency_satisfies_hook( $hook, $dependency_hooks ) )
            {
                continue;
            }

            $dependency_action_id = isset( $normalized[ $dependency_id ]['central_action_id'] ) && is_scalar( $normalized[ $dependency_id ]['central_action_id'] )
                ? sanitize_key( (string) $normalized[ $dependency_id ]['central_action_id'] )
                : '';

            if ( 'spam_detection_v1' === $dependency_action_id )
            {
                return true;
            }
        }

        return new WP_Error(
            'rest_invalid_skip_on_upstream_spam',
            sprintf(
                /* translators: %s: mapping id */
                __( 'Mapping %s can only enable skip_on_upstream_spam when at least one active trigger depends on exactly one upstream spam_detection_v1 mapping.', 'sentient-forms' ),
                sanitize_text_field( $mapping_id )
            )
        );
    }

    /**
     * Determine whether the skip_on_upstream_spam option is enabled for a mapping.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     *
     * @return bool
     */
    private function is_skip_on_upstream_spam_enabled( array $mapping ): bool
    {
        if ( ! isset( $mapping['settings'] ) || ! is_array( $mapping['settings'] ) )
        {
            return false;
        }

        if ( ! array_key_exists( 'skip_on_upstream_spam', $mapping['settings'] ) )
        {
            return false;
        }

        return rest_sanitize_boolean( $mapping['settings']['skip_on_upstream_spam'] );
    }

    /**
     * Determine whether a mapping executes asynchronously for after-submission flow.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     *
     * @return bool
     */
    private function is_mapping_async( array $mapping ): bool
    {
        $trigger_hooks        = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
        $has_validation_hook  = in_array( Sentient_Forms_Form_Source_Lifecycles::VALIDATION, $trigger_hooks, true );
        $has_after_hook       = in_array( Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION, $trigger_hooks, true );

        if ( $has_validation_hook && ! $has_after_hook )
        {
            return false;
        }

        if ( isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) && array_key_exists( 'async', $mapping['settings'] ) )
        {
            return rest_sanitize_boolean( $mapping['settings']['async'] );
        }

        if ( isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) && isset( $mapping['settings']['execution_mode'] ) && is_scalar( $mapping['settings']['execution_mode'] ) )
        {
            return 'after_submission' === sanitize_key( (string) $mapping['settings']['execution_mode'] );
        }

        if ( isset( $mapping['execution_mode'] ) && is_scalar( $mapping['execution_mode'] ) )
        {
            return 'after_submission' === sanitize_key( (string) $mapping['execution_mode'] );
        }

        if ( $has_after_hook )
        {
            return true;
        }

        $indicator = isset( $mapping['action_type_indicator'] ) && is_scalar( $mapping['action_type_indicator'] )
            ? sanitize_key( (string) $mapping['action_type_indicator'] )
            : '';

        if ( 'master' === $indicator )
        {
            return true;
        }

        return false;
    }

    /**
     * Determine whether dependency hooks satisfy a required runtime hook.
     *
     * Validation dependencies can satisfy after-submission dependants because sync
     * validation execution completes before async after-submission actions begin.
     *
     * @param string            $required_hook    Runtime hook being evaluated.
     * @param array<int, mixed> $dependency_hooks Dependency trigger hooks.
     *
     * @return bool
     */
    private function dependency_satisfies_hook( string $required_hook, array $dependency_hooks ): bool
    {
        $required_hook = sanitize_key( $required_hook );
        $normalized_dependency_hooks = $this->sanitize_trigger_hooks( $dependency_hooks );

        if ( in_array( $required_hook, $normalized_dependency_hooks, true ) )
        {
            return true;
        }

        return Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION === $required_hook
            && in_array( Sentient_Forms_Form_Source_Lifecycles::VALIDATION, $normalized_dependency_hooks, true );
    }

    /**
     * List required hooks a dependency does not satisfy for a dependant mapping.
     *
     * @param array<int, string> $trigger_hooks    Dependant mapping hooks.
     * @param array<int, string> $dependency_hooks Dependency mapping hooks.
     *
     * @return array<int, string>
     */
    private function collect_missing_required_hooks( array $trigger_hooks, array $dependency_hooks ): array
    {
        $missing = [];
        foreach ( $trigger_hooks as $required_hook )
        {
            if ( ! $this->dependency_satisfies_hook( $required_hook, $dependency_hooks ) )
            {
                $missing[] = $required_hook;
            }
        }

        return array_values( array_unique( $missing ) );
    }

    /**
     * Extract real action linkages from the stored per-form option.
     *
     * Older UI flows persisted metadata and a nested `actions` wrapper beside the
     * top-level mapping keys. CPS sync and graph planning must only see mapping rows.
     *
     * @param array $stored_actions Raw option payload.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extract_action_linkages_from_option( array $stored_actions ): array
    {
        $candidates = [];

        if ( isset( $stored_actions['actions'] ) && is_array( $stored_actions['actions'] ) )
        {
            foreach ( $stored_actions['actions'] as $mapping_key => $mapping )
            {
                $candidates[ $mapping_key ] = $mapping;
            }
        }

        foreach ( $stored_actions as $mapping_key => $mapping )
        {
            if ( in_array( (string) $mapping_key, [ 'actions', 'enabled', 'sf_disabled' ], true ) )
            {
                continue;
            }

            $candidates[ $mapping_key ] = $mapping;
        }

        return array_values( $this->normalize_local_action_mappings( $candidates ) );
    }

    /**
     * Normalize local mapping payloads while filtering non-action metadata keys.
     *
     * @param array $actions Raw actions payload.
     *
     * @return array<string, array<string, mixed>>
     */
    private function normalize_local_action_mappings( array $actions ): array
    {
        $normalized = [];

        foreach ( $actions as $mapping_key => $mapping )
        {
            if ( ! is_array( $mapping ) || ! isset( $mapping['central_action_id'] ) )
            {
                continue;
            }

            $mapping_id = isset( $mapping['local_mapping_id'] ) && is_scalar( $mapping['local_mapping_id'] )
                ? sanitize_text_field( (string) $mapping['local_mapping_id'] )
                : sanitize_text_field( (string) $mapping_key );

            if ( '' === $mapping_id )
            {
                continue;
            }

            $mapping['local_mapping_id'] = $mapping_id;
            $normalized[ $mapping_id ]   = $mapping;
        }

        return $normalized;
    }

    /**
     * CB-FORMS-006: Sanitize conditional run settings.
     *
     * @param array $raw Raw condition config.
     *
     * @return array
     */
    private function sanitize_conditions( array $raw ): array
    {
        $node_count = 0;
        $root       = null;

        if ( isset( $raw['root'] ) && is_array( $raw['root'] ) )
        {
            $root = $this->sanitize_condition_node( $raw['root'], 1, $node_count );
        }

        if ( ! is_array( $root ) )
        {
            $root = [
                'type'  => 'group',
                'logic' => 'all',
                'rules' => [],
            ];
        }

        return [
            'enabled' => ! empty( $raw['enabled'] ),
            'root'    => $root,
        ];
    }

    /**
     * Sanitize a condition node recursively.
     *
     * @param array $node       Raw condition node.
     * @param int   $depth      Current recursion depth.
     * @param int   $node_count Running node counter.
     *
     * @return array|null
     */
    private function sanitize_condition_node( array $node, int $depth, int &$node_count ): ?array
    {
        if ( $depth > self::MAX_CONDITION_DEPTH )
        {
            return null;
        }

        $node_count++;
        if ( $node_count > self::MAX_CONDITION_NODES )
        {
            return null;
        }

        $type = sanitize_key( (string) ( $node['type'] ?? '' ) );
        if ( 'group' === $type )
        {
            $logic = sanitize_key( (string) ( $node['logic'] ?? 'all' ) );
            $logic = in_array( $logic, [ 'all', 'any' ], true ) ? $logic : 'all';
            $rules = [];

            if ( isset( $node['rules'] ) && is_array( $node['rules'] ) )
            {
                foreach ( $node['rules'] as $child )
                {
                    if ( ! is_array( $child ) )
                    {
                        continue;
                    }

                    $child_type      = sanitize_key( (string) ( $child['type'] ?? '' ) );
                    $child_depth     = 'group' === $child_type ? $depth + 1 : $depth;
                    $sanitized_child = $this->sanitize_condition_node( $child, $child_depth, $node_count );
                    if ( null !== $sanitized_child )
                    {
                        $rules[] = $sanitized_child;
                    }
                }
            }

            return [
                'type'  => 'group',
                'logic' => $logic,
                'rules' => array_values( $rules ),
            ];
        }

        if ( 'rule' !== $type )
        {
            return null;
        }

        $field_id = isset( $node['field_id'] ) ? sanitize_text_field( (string) $node['field_id'] ) : '';
        if ( '' === $field_id )
        {
            return null;
        }

        $operator = sanitize_key( (string) ( $node['operator'] ?? '' ) );
        if ( ! in_array( $operator, self::CONDITION_OPERATORS, true ) )
        {
            return null;
        }

        $sanitized = [
            'type'     => 'rule',
            'field_id' => $field_id,
            'operator' => $operator,
        ];

        if ( $this->condition_operator_requires_value( $operator ) )
        {
            if ( ! array_key_exists( 'value', $node ) )
            {
                return null;
            }

            $value = $this->sanitize_condition_value( $operator, $node['value'] );
            if ( null === $value )
            {
                return null;
            }

            $sanitized['value'] = $value;
        }

        return $sanitized;
    }

    /**
     * Determine whether a condition operator requires a value.
     *
     * @param string $operator Operator key.
     *
     * @return bool
     */
    private function condition_operator_requires_value( string $operator ): bool
    {
        return ! in_array( $operator, [ 'is_empty', 'is_not_empty' ], true );
    }

    /**
     * Sanitize condition rule value by operator.
     *
     * @param string $operator Operator key.
     * @param mixed  $raw      Raw value.
     *
     * @return mixed|null
     */
    private function sanitize_condition_value( string $operator, $raw )
    {
        if ( in_array( $operator, [ 'in', 'not_in' ], true ) )
        {
            if ( ! is_array( $raw ) )
            {
                return null;
            }

            $items = [];
            foreach ( $raw as $value )
            {
                if ( ! is_scalar( $value ) )
                {
                    continue;
                }

                $text = sanitize_text_field( (string) $value );
                if ( '' !== $text )
                {
                    $items[] = $text;
                }
            }

            if ( empty( $items ) )
            {
                return null;
            }

            return array_values( array_unique( $items ) );
        }

        if ( in_array( $operator, [ 'gt', 'gte', 'lt', 'lte' ], true ) )
        {
            if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) )
            {
                return null;
            }

            return (float) $raw;
        }

        if ( ! is_scalar( $raw ) )
        {
            return null;
        }

        return sanitize_text_field( (string) $raw );
    }
}
