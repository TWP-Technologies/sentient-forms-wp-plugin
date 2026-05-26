<?php
/**
 * REST API Action Log Controller class for the Sentient Forms plugin.
 * Handles routes for viewing action execution history.
 *
 * Implements FR-006 (Action Log Page via REST) and FR-007 (Action Log REST API).
 *
 * @package    SentientForms
 * @subpackage REST_API\Controllers
 * @since      0.2.0
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Action_Log_Controller
 * Manages REST API endpoints for action execution logs.
 */
class Sentient_Forms_Action_Log_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;

    protected string $rest_base = 'actions/log';

    private const OPTION_KEY = 'sentient_forms_action_log';
    private const MAX_LOG_ENTRIES = 500; // Retention limit

    private Sentient_Forms_Admin_Permission $permission_checker;
    private array $local_mapping_cache = [];
    private array $local_custom_action_cache = [];
    private array $local_action_template_cache = [];
    private array $form_context_cache = [];

    public function __construct()
    {
        parent::__construct();

        if ( class_exists( 'Sentient_Forms_Admin_Permission' ) )
        {
            $this->permission_checker = new Sentient_Forms_Admin_Permission();
        }
    }

    public function register_routes(): void
    {
        // GET /actions/log - List action logs with optional filters
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_log_entries' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_collection_params(),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ],
        );

        // GET /actions/log/{id}/entry-preview - Lazy-load a safe form entry preview for one log row
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<log_id>[A-Za-z0-9_-]+)/entry-preview',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_entry_preview' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'log_id' => [
                            'description'       => __( 'Action log row identifier.', 'sentient-forms' ),
                            'type'              => 'string',
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ],
        );

        // POST /actions/log - Record a new log entry (internal use)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'create_log_entry' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'form_source' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_key',
                        ],
                        'form_id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'entry_id' => [
                            'required'          => false,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'action_code' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'action_label' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'status' => [
                            'required'          => true,
                            'type'              => 'string',
                            'enum'              => [ 'pending', 'success', 'blocked', 'error' ],
                        ],
                        'result_summary' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_textarea_field',
                        ],
                        'classification' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'credits_used' => [
                            'required'          => false,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                            'default'           => 0,
                        ],
                        'error_code' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'error_message' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_textarea_field',
                        ],
                        'execution_request_id' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'mapping_id' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'resolved_model_id' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Get paginated log entries with optional filters.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_log_entries( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $page = $request->has_param( 'page' )
            ? max( 1, (int) $request->get_param( 'page' ) )
            : 1;
        $per_page = $request->has_param( 'per_page' )
            ? min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) )
            : 20;

        $form_id  = $request->get_param( 'form_id' );
        $action_code = $request->get_param( 'action_code' );
        $status   = $request->get_param( 'status' );
        $date_from = $request->get_param( 'date_from' );
        $date_to  = $request->get_param( 'date_to' );

        $filters = [
            'form_id'     => $form_id,
            'action_code' => $action_code,
            'status'      => $status,
            'date_from'   => $date_from,
            'date_to'     => $date_to,
        ];

        if ( $action_code )
        {
            $page_data = $this->get_filtered_entries_page_from_all_entries( $filters, $page, $per_page );
        }
        else
        {
            $page_data = $this->get_filtered_entries_page( $filters, $page, $per_page );
        }

        return $this->prepare_item_for_response( [
            'entries'     => $this->enrich_log_entries( $page_data['entries'] ),
            'total'       => $page_data['total'],
            'total_pages' => $page_data['total_pages'],
            'page'        => $page,
            'per_page'    => $per_page,
        ] );
    }

    /**
     * Create a new log entry.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function create_log_entry( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $entry = [
            'id'             => wp_generate_uuid4(),
            'form_source'    => $request->get_param( 'form_source' ),
            'form_id'        => (int) $request->get_param( 'form_id' ),
            'entry_id'       => (int) $request->get_param( 'entry_id' ) ?: null,
            'action_code'    => $request->get_param( 'action_code' ),
            'action_label'   => $request->get_param( 'action_label' ),
            'status'         => $request->get_param( 'status' ),
            'result_summary' => $request->get_param( 'result_summary' ) ?: null,
            'classification' => $request->get_param( 'classification' ) ?: null,
            'credits_used'   => (int) $request->get_param( 'credits_used' ),
            'error_code'     => $request->get_param( 'error_code' ) ?: null,
            'error_message'  => $request->get_param( 'error_message' ) ?: null,
            'execution_request_id' => $request->get_param( 'execution_request_id' ) ?: null,
            'mapping_id'     => $request->get_param( 'mapping_id' ) ?: null,
            'resolved_model_id' => $request->get_param( 'resolved_model_id' ) ?: null,
            'usage_cost'     => self::build_legacy_usage_cost_summary(
                [
                    'credits_used' => (int) $request->get_param( 'credits_used' ),
                ]
            ),
            'created_at'     => gmdate( 'c' ),
            'completed_at'   => $request->get_param( 'status' ) !== 'pending' ? gmdate( 'c' ) : null,
        ];

        $saved = $this->save_entry( $entry );

        if ( ! $saved )
        {
            return $this->prepare_error_response(
                'log_save_failed',
                __( 'Failed to save log entry.', 'sentient-forms' ),
                500
            );
        }

        return $this->prepare_item_for_response( $entry, 201 );
    }

    public function get_entry_preview( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $log_id = sanitize_text_field( (string) $request->get_param( 'log_id' ) );
        $entry  = $this->find_log_entry_by_id( $log_id );

        if ( ! $entry )
        {
            return $this->prepare_error_response(
                'sentient_forms_action_log_entry_not_found',
                __( 'Action log entry not found.', 'sentient-forms' ),
                404
            );
        }

        $form_source = $this->normalize_form_source( (string) ( $entry['form_source'] ?? '' ) );
        if ( ! in_array( $form_source, [ 'gravity_forms', 'gravity-forms' ], true ) )
        {
            return $this->prepare_error_response(
                'sentient_forms_action_log_preview_unsupported_provider',
                __( 'Entry preview is currently available for Gravity Forms entries only.', 'sentient-forms' ),
                400
            );
        }

        if ( ! class_exists( 'GFAPI' ) || ! is_callable( [ 'GFAPI', 'get_form' ] ) || ! is_callable( [ 'GFAPI', 'get_entry' ] ) )
        {
            return $this->prepare_error_response(
                'sentient_forms_gfapi_unavailable',
                __( 'Gravity Forms entry preview is unavailable.', 'sentient-forms' ),
                503
            );
        }

        $form_id  = absint( $entry['form_id'] ?? 0 );
        $entry_id = absint( $entry['entry_id'] ?? 0 );
        if ( $form_id <= 0 || $entry_id <= 0 )
        {
            return $this->prepare_error_response(
                'sentient_forms_action_log_preview_unavailable',
                __( 'This action log row does not have a saved form entry to preview yet.', 'sentient-forms' ),
                400
            );
        }

        $form = GFAPI::get_form( $form_id );
        if ( ! is_array( $form ) )
        {
            return $this->prepare_error_response(
                'sentient_forms_action_log_form_not_found',
                __( 'The form for this action log row could not be found.', 'sentient-forms' ),
                404
            );
        }

        $gf_entry = GFAPI::get_entry( $entry_id );
        if ( is_wp_error( $gf_entry ) || ! is_array( $gf_entry ) )
        {
            return $this->prepare_error_response(
                'sentient_forms_action_log_entry_preview_not_found',
                __( 'The form entry for this action log row could not be found.', 'sentient-forms' ),
                404
            );
        }

        if ( absint( $gf_entry['form_id'] ?? 0 ) !== $form_id )
        {
            return $this->prepare_error_response(
                'sentient_forms_action_log_entry_form_mismatch',
                __( 'The form entry no longer belongs to the expected form.', 'sentient-forms' ),
                409
            );
        }

        $context = $this->build_form_context( $entry );

        return $this->prepare_item_for_response(
            [
                'log_id'       => $entry['id'],
                'form_source'  => $form_source,
                'provider_label' => $context['provider_label'],
                'form_id'      => $form_id,
                'form_name'    => $context['form_name'],
                'entry_id'     => $entry_id,
                'date_created' => isset( $gf_entry['date_created'] ) ? sanitize_text_field( (string) $gf_entry['date_created'] ) : null,
                'status'       => isset( $gf_entry['status'] ) ? sanitize_key( (string) $gf_entry['status'] ) : null,
                'fields'       => $this->summarize_gravity_forms_entry_fields( $form, $gf_entry ),
                'links'        => $context['links'],
            ]
        );
    }

    /**
     * Record a log entry programmatically (for internal use by adapters/executors).
     *
     * @param array $data Log entry data.
     * @return bool Whether the entry was saved.
     */
    public static function log_execution( array $data ): bool
    {
        $execution_request_id = self::resolve_execution_request_id( $data );
        $entry = [
            'id'             => wp_generate_uuid4(),
            'form_source'    => sanitize_key( $data['form_source'] ?? 'unknown' ),
            'form_id'        => absint( $data['form_id'] ?? 0 ),
            'entry_id'       => isset( $data['entry_id'] ) ? absint( $data['entry_id'] ) : null,
            'action_code'    => sanitize_text_field( $data['action_code'] ?? '' ),
            'action_label'   => sanitize_text_field( $data['action_label'] ?? '' ),
            'status'         => in_array( $data['status'] ?? '', [ 'pending', 'success', 'blocked', 'error' ], true )
                                    ? $data['status'] : 'error',
            'result_summary' => isset( $data['result_summary'] )
                                    ? wp_trim_words( sanitize_textarea_field( $data['result_summary'] ), 50, '...' )
                                    : null,
            'classification' => isset( $data['classification'] )
                                    ? sanitize_text_field( $data['classification'] )
                                    : null,
            'credits_used'   => absint( $data['credits_used'] ?? 0 ),
            'error_code'     => isset( $data['error_code'] )
                                    ? sanitize_text_field( $data['error_code'] )
                                    : null,
            'error_message'  => isset( $data['error_message'] )
                                    ? sanitize_textarea_field( $data['error_message'] )
                                    : null,
            'execution_request_id' => $execution_request_id,
            'mapping_id'     => isset( $data['mapping_id'] )
                                    ? sanitize_text_field( (string) $data['mapping_id'] )
                                    : null,
            'resolved_model_id' => isset( $data['resolved_model_id'] )
                                    ? sanitize_text_field( (string) $data['resolved_model_id'] )
                                    : null,
            'pricing'        => self::sanitize_log_json_value( $data['pricing'] ?? [] ),
            'usage_cost'     => self::sanitize_log_json_value( $data['usage_cost'] ?? self::build_legacy_usage_cost_summary( $data ) ),
            'details'        => self::sanitize_log_json_value( $data['details'] ?? [] ),
            'structured_output_valid' => ! empty( $data['structured_output_valid'] ),
            'created_at'     => gmdate( 'c' ),
            'completed_at'   => ( $data['status'] ?? '' ) !== 'pending' ? gmdate( 'c' ) : null,
        ];

        if ( class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' ) && Sentient_Forms_Managed_Usage_Sanitizer::is_managed_payload( $entry ) )
        {
            $entry = Sentient_Forms_Managed_Usage_Sanitizer::sanitize_for_managed_context( $entry );
        }

        $entries = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $entries ) )
        {
            $entries = [];
        }

        // Prepend new entry (newest first)
        array_unshift( $entries, $entry );

        // Enforce retention limit
        if ( count( $entries ) > self::MAX_LOG_ENTRIES )
        {
            $entries = array_slice( $entries, 0, self::MAX_LOG_ENTRIES );
        }

        $saved = update_option( self::OPTION_KEY, $entries, false );

        return $saved;
    }

    /**
     * Attach a saved form entry id to prior validation-hook audit rows.
     *
     * Gravity Forms validation runs before the entry exists. Once gform_entry_post_save fires,
     * the plugin can safely backfill matching local execution_request_id rows without relying on
     * the retired CPS audit mirror.
     *
     * @param array<int, string> $execution_request_ids Execution request ids from the validation hook.
     * @param int                $entry_id              Saved form entry id.
     * @param string|null        $form_source           Optional form source guard.
     * @param int|null           $form_id               Optional form id guard.
     * @return int Number of local audit rows updated.
     */
    public static function backfill_entry_id_for_execution_requests(
        array $execution_request_ids,
        int $entry_id,
        ?string $form_source = null,
        ?int $form_id = null
    ): int
    {
        $entry_id = absint( $entry_id );
        if ( $entry_id <= 0 || empty( $execution_request_ids ) )
        {
            return 0;
        }

        $request_ids = [];
        foreach ( $execution_request_ids as $execution_request_id )
        {
            if ( ! is_scalar( $execution_request_id ) )
            {
                continue;
            }

            $execution_request_id = sanitize_text_field( (string) $execution_request_id );
            if ( '' !== $execution_request_id )
            {
                $request_ids[ $execution_request_id ] = true;
            }
        }

        if ( empty( $request_ids ) )
        {
            return 0;
        }

        $expected_form_source = null !== $form_source ? sanitize_key( $form_source ) : null;
        $expected_form_id     = null !== $form_id ? absint( $form_id ) : null;
        $entries              = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $entries ) )
        {
            $entries = [];
        }

        $updated_entries = [];
        foreach ( $entries as &$entry )
        {
            if ( ! is_array( $entry ) )
            {
                continue;
            }

            $execution_request_id = isset( $entry['execution_request_id'] ) && is_scalar( $entry['execution_request_id'] )
                ? sanitize_text_field( (string) $entry['execution_request_id'] )
                : '';

            if ( '' === $execution_request_id || ! isset( $request_ids[ $execution_request_id ] ) )
            {
                continue;
            }

            if ( null !== $expected_form_source && ( $entry['form_source'] ?? '' ) !== $expected_form_source )
            {
                continue;
            }

            if ( null !== $expected_form_id && absint( $entry['form_id'] ?? 0 ) !== $expected_form_id )
            {
                continue;
            }

            $current_entry_id = absint( $entry['entry_id'] ?? 0 );
            if ( $current_entry_id > 0 )
            {
                continue;
            }

            $entry['entry_id'] = $entry_id;
            $updated_entries[] = $entry;
        }
        unset( $entry );

        if ( ! empty( $updated_entries ) )
        {
            update_option( self::OPTION_KEY, $entries, false );
        }

        $updated_count = count( $updated_entries );
        $updated_count += self::backfill_local_execution_events(
            array_keys( $request_ids ),
            $entry_id,
            $expected_form_source,
            $expected_form_id
        );

        return $updated_count;
    }

    /**
     * Get all log entries from storage.
     *
     * @return array
     */
    private function get_all_entries(): array
    {
        $legacy_entries = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $legacy_entries ) )
        {
            $legacy_entries = [];
        }

        $local_execution_events = $this->get_local_execution_event_rows();

        return array_merge(
            array_values(
                array_filter(
                    array_map(
                        [ $this, 'format_local_execution_event_for_log' ],
                        $local_execution_events
                    )
                )
            ),
            $this->filter_legacy_entries_for_runtime_truth( $legacy_entries, $local_execution_events )
        );
    }

    private function get_filtered_entries_page_from_all_entries( array $filters, int $page, int $per_page ): array
    {
        $filtered = array_filter(
            $this->get_all_entries(),
            fn ( array $entry ): bool => $this->log_entry_matches_filters( $entry, $filters )
        );

        usort( $filtered, fn ( $a, $b ) => strcmp( $b['created_at'] ?? '', $a['created_at'] ?? '' ) );

        $total = count( $filtered );
        $offset = ( $page - 1 ) * $per_page;

        return [
            'entries'     => array_values( array_slice( $filtered, $offset, $per_page ) ),
            'total'       => $total,
            'total_pages' => (int) ceil( $total / $per_page ),
        ];
    }

    private function get_filtered_entries_page( array $filters, int $page, int $per_page ): array
    {
        $offset = ( $page - 1 ) * $per_page;

        $legacy_entries = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $legacy_entries ) )
        {
            $legacy_entries = [];
        }

        $repository = $this->get_execution_events_repository();
        $local_rows = [];
        $local_total = 0;
        $legacy_truth_rows = [];
        if ( $repository )
        {
            $local_query_limit = min( self::MAX_LOG_ENTRIES, $offset + $per_page );
            $local_rows        = $repository->list_for_action_log( $filters, $local_query_limit, 0 );
            $local_total       = $repository->count_for_action_log( $filters );

            if ( ! empty( $legacy_entries ) )
            {
                $legacy_truth_rows = $repository->list_recent_for_action_log( self::MAX_LOG_ENTRIES );
            }
        }

        $local_entries = array_values(
            array_filter(
                array_map( [ $this, 'format_local_execution_event_for_log' ], $local_rows )
            )
        );

        $legacy_filtered = array_values(
            array_filter(
                $this->filter_legacy_entries_for_runtime_truth( $legacy_entries, $legacy_truth_rows ),
                fn ( array $entry ): bool => $this->log_entry_matches_filters( $entry, $filters )
            )
        );

        $candidates = array_merge( $local_entries, $legacy_filtered );
        usort( $candidates, fn ( $a, $b ) => strcmp( $b['created_at'] ?? '', $a['created_at'] ?? '' ) );

        $total = $local_total + count( $legacy_filtered );

        return [
            'entries'     => array_values( array_slice( $candidates, $offset, $per_page ) ),
            'total'       => $total,
            'total_pages' => (int) ceil( $total / $per_page ),
        ];
    }

    private function log_entry_matches_filters( array $entry, array $filters ): bool
    {
        $form_id = absint( $filters['form_id'] ?? 0 );
        if ( $form_id > 0 && absint( $entry['form_id'] ?? 0 ) !== $form_id )
        {
            return false;
        }

        $action_code = isset( $filters['action_code'] ) ? sanitize_text_field( (string) $filters['action_code'] ) : '';
        if ( '' !== $action_code && ( $entry['action_code'] ?? '' ) !== $action_code )
        {
            return false;
        }

        $status = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';
        if ( '' !== $status && ( $entry['status'] ?? '' ) !== $status )
        {
            return false;
        }

        $date_from = isset( $filters['date_from'] ) ? sanitize_text_field( (string) $filters['date_from'] ) : '';
        if ( '' !== $date_from && ( $entry['created_at'] ?? '' ) < $date_from )
        {
            return false;
        }

        $date_to = isset( $filters['date_to'] ) ? sanitize_text_field( (string) $filters['date_to'] ) : '';
        if ( '' !== $date_to && ( $entry['created_at'] ?? '' ) > $date_to )
        {
            return false;
        }

        return true;
    }

    private function get_execution_events_repository(): ?Sentient_Forms_Execution_Events_Repository
    {
        if ( ! class_exists( 'Sentient_Forms_Execution_Events_Repository' ) )
        {
            return null;
        }

        global $wpdb;
        return new Sentient_Forms_Execution_Events_Repository( $wpdb );
    }

    /**
     * Save a new entry to storage.
     *
     * @param array $entry The entry to save.
     * @return bool
     */
    private function save_entry( array $entry ): bool
    {
        return self::log_execution( $entry );
    }

    private function get_local_execution_event_rows(): array
    {
        $repository = $this->get_execution_events_repository();
        if ( ! $repository )
        {
            return [];
        }

        return $repository->list_recent_for_action_log( self::MAX_LOG_ENTRIES );
    }

    private function enrich_log_entries( array $entries ): array
    {
        foreach ( $entries as $index => $entry )
        {
            if ( ! is_array( $entry ) )
            {
                continue;
            }

            $entries[ $index ]['form_context'] = $this->build_form_context( $entry );
        }

        return $entries;
    }

    private function find_log_entry_by_id( string $log_id ): ?array
    {
        $log_id = sanitize_text_field( $log_id );
        if ( '' === $log_id )
        {
            return null;
        }

        if ( str_starts_with( $log_id, 'local-event-' ) )
        {
            $event_id = absint( substr( $log_id, strlen( 'local-event-' ) ) );
            $repository = $this->get_execution_events_repository();
            if ( $repository && $event_id > 0 )
            {
                $event = $repository->get_by_id( $event_id );
                return $event ? $this->format_local_execution_event_for_log( $event ) : null;
            }
        }

        $entries = get_option( self::OPTION_KEY, [] );
        foreach ( is_array( $entries ) ? $entries : [] as $entry )
        {
            if ( ! is_array( $entry ) )
            {
                continue;
            }

            if ( isset( $entry['id'] ) && is_scalar( $entry['id'] ) && sanitize_text_field( (string) $entry['id'] ) === $log_id )
            {
                return $entry;
            }
        }

        return null;
    }

    private function build_form_context( array $entry ): array
    {
        $form_source = $this->normalize_form_source( (string) ( $entry['form_source'] ?? '' ) );
        $form_id     = absint( $entry['form_id'] ?? 0 );
        $entry_id    = $this->normalize_log_entry_id( $entry['entry_id'] ?? null );
        $cache_key   = $form_source . ':' . $form_id;

        if ( ! isset( $this->form_context_cache[ $cache_key ] ) )
        {
            $this->form_context_cache[ $cache_key ] = $this->build_base_form_context( $form_source, $form_id );
        }

        $context = $this->form_context_cache[ $cache_key ];
        $links   = is_array( $context['links'] ?? null ) ? $context['links'] : [];

        if ( $entry_id && 'gravity_forms' === $form_source )
        {
            $links['entry_admin_url'] = $this->gravity_forms_entry_admin_url( $form_id, $entry_id );
        }

        $context['entry_id'] = $entry_id;
        $context['links']    = $links;
        $context['entry_preview_available'] = 'gravity_forms' === $form_source
            && $form_id > 0
            && null !== $entry_id
            && empty( $context['form_missing'] )
            && class_exists( 'GFAPI' )
            && is_callable( [ 'GFAPI', 'get_form' ] )
            && is_callable( [ 'GFAPI', 'get_entry' ] );

        return $context;
    }

    private function build_base_form_context( string $form_source, int $form_id ): array
    {
        $provider_label = $this->provider_label( $form_source );
        $form_name      = $form_id > 0 ? sprintf(
            /* translators: %d: Form ID. */
            __( 'Form #%d', 'sentient-forms' ),
            $form_id
        ) : __( 'Unknown form', 'sentient-forms' );
        $form_missing = false;
        $links = [
            'provider_admin_url' => null,
            'form_admin_url'     => null,
            'entries_admin_url'  => null,
            'entry_admin_url'    => null,
        ];

        if ( 'gravity_forms' === $form_source )
        {
            $links['provider_admin_url'] = admin_url( 'admin.php?page=gf_edit_forms' );
            if ( $form_id > 0 )
            {
                $links['form_admin_url']    = admin_url( sprintf( 'admin.php?page=gf_edit_forms&id=%d', $form_id ) );
                $links['entries_admin_url'] = admin_url( sprintf( 'admin.php?page=gf_entries&id=%d', $form_id ) );
            }

            $form = null;
            if ( $form_id > 0 && class_exists( 'GFAPI' ) && is_callable( [ 'GFAPI', 'get_form' ] ) )
            {
                $form = GFAPI::get_form( $form_id );
            }

            if ( is_array( $form ) )
            {
                $title = isset( $form['title'] ) && is_scalar( $form['title'] ) ? trim( (string) $form['title'] ) : '';
                if ( '' !== $title )
                {
                    $form_name = sanitize_text_field( $title );
                }
            }
            elseif ( $form_id > 0 && class_exists( 'GFAPI' ) )
            {
                $form_missing = true;
            }
        }

        return [
            'provider_slug'            => $form_source,
            'provider_label'           => $provider_label,
            'form_id'                  => $form_id,
            'form_name'                => $form_name,
            'entry_id'                 => null,
            'links'                    => $links,
            'entry_preview_available'  => false,
            'form_missing'             => $form_missing,
        ];
    }

    private function normalize_form_source( string $form_source ): string
    {
        return match ( sanitize_key( $form_source ) ) {
            'gravity-forms' => 'gravity_forms',
            ''              => 'unknown',
            default         => sanitize_key( $form_source ),
        };
    }

    private function provider_label( string $form_source ): string
    {
        return match ( $this->normalize_form_source( $form_source ) ) {
            'gravity_forms' => __( 'Gravity Forms', 'sentient-forms' ),
            'unknown'       => __( 'Unknown provider', 'sentient-forms' ),
            default         => ucwords( str_replace( [ '_', '-' ], ' ', sanitize_key( $form_source ) ) ),
        };
    }

    private function gravity_forms_entry_admin_url( int $form_id, int $entry_id ): ?string
    {
        if ( $form_id <= 0 || $entry_id <= 0 )
        {
            return null;
        }

        return admin_url( sprintf( 'admin.php?page=gf_entries&view=entry&id=%d&lid=%d', $form_id, $entry_id ) );
    }

    private function summarize_gravity_forms_entry_fields( array $form, array $entry ): array
    {
        $summary = [];
        foreach ( is_array( $form['fields'] ?? null ) ? $form['fields'] : [] as $field )
        {
            if ( $this->should_skip_preview_field( $field ) )
            {
                continue;
            }

            $field_id = $this->field_property( $field, 'id' );
            if ( '' === $field_id )
            {
                continue;
            }

            $label = $this->field_property( $field, 'label' );
            if ( '' === $label )
            {
                $label = $field_id;
            }

            $value = $this->entry_field_preview_value( $field, $entry, $field_id );
            if ( '' === $value )
            {
                continue;
            }

            $summary[] = [
                'field_id' => sanitize_text_field( $field_id ),
                'label'    => sanitize_text_field( $label ),
                'value'    => $this->truncate_preview_value( $value ),
            ];

            if ( count( $summary ) >= 12 )
            {
                return $summary;
            }
        }

        if ( [] === $summary )
        {
            $summary = $this->fallback_entry_field_preview( $entry );
        }

        return array_slice( $summary, 0, 12 );
    }

    private function should_skip_preview_field( mixed $field ): bool
    {
        $type       = sanitize_key( $this->field_property( $field, 'type' ) );
        $visibility = sanitize_key( $this->field_property( $field, 'visibility' ) );

        return in_array( $visibility, [ 'hidden', 'administrative' ], true )
            || in_array( $type, [ 'hidden', 'html', 'section', 'page', 'fileupload', 'signature', 'captcha' ], true );
    }

    private function field_property( mixed $field, string $property ): string
    {
        if ( is_object( $field ) && isset( $field->{$property} ) && is_scalar( $field->{$property} ) )
        {
            return trim( (string) $field->{$property} );
        }

        if ( is_array( $field ) && isset( $field[ $property ] ) && is_scalar( $field[ $property ] ) )
        {
            return trim( (string) $field[ $property ] );
        }

        return '';
    }

    private function entry_field_preview_value( mixed $field, array $entry, string $field_id ): string
    {
        $value = $entry[ $field_id ] ?? '';
        if ( is_scalar( $value ) && '' !== trim( (string) $value ) )
        {
            return sanitize_textarea_field( (string) $value );
        }

        $inputs = is_object( $field ) && isset( $field->inputs ) ? $field->inputs : ( is_array( $field ) ? ( $field['inputs'] ?? [] ) : [] );
        if ( ! is_array( $inputs ) )
        {
            return '';
        }

        $parts = [];
        foreach ( $inputs as $input )
        {
            $input_id = is_array( $input ) && isset( $input['id'] ) ? (string) $input['id'] : ( is_object( $input ) && isset( $input->id ) ? (string) $input->id : '' );
            if ( '' === $input_id || ! isset( $entry[ $input_id ] ) || ! is_scalar( $entry[ $input_id ] ) )
            {
                continue;
            }

            $input_value = trim( (string) $entry[ $input_id ] );
            if ( '' === $input_value )
            {
                continue;
            }

            $input_label = is_array( $input ) && isset( $input['label'] ) ? (string) $input['label'] : ( is_object( $input ) && isset( $input->label ) ? (string) $input->label : '' );
            $parts[] = '' !== trim( $input_label )
                ? sanitize_text_field( $input_label ) . ': ' . sanitize_textarea_field( $input_value )
                : sanitize_textarea_field( $input_value );
        }

        return implode( "\n", $parts );
    }

    private function fallback_entry_field_preview( array $entry ): array
    {
        $summary = [];
        $blocked = [
            'id',
            'form_id',
            'post_id',
            'date_created',
            'date_updated',
            'is_starred',
            'is_read',
            'ip',
            'source_url',
            'user_agent',
            'currency',
            'payment_status',
            'payment_date',
            'payment_amount',
            'payment_method',
            'transaction_id',
            'transaction_type',
            'is_fulfilled',
            'created_by',
            'status',
        ];

        foreach ( $entry as $key => $value )
        {
            $key = (string) $key;
            if ( count( $summary ) >= 8 || in_array( $key, $blocked, true ) || ! is_scalar( $value ) || '' === trim( (string) $value ) )
            {
                continue;
            }

            if ( ! preg_match( '/^\d+(?:\.\d+)?$/', $key ) )
            {
                continue;
            }

            $summary[] = [
                'field_id' => sanitize_text_field( $key ),
                'label'    => sanitize_text_field( $key ),
                'value'    => $this->truncate_preview_value( sanitize_textarea_field( (string) $value ) ),
            ];
        }

        return $summary;
    }

    private function truncate_preview_value( string $value ): string
    {
        $value = sanitize_textarea_field( $value );
        if ( function_exists( 'mb_substr' ) )
        {
            return mb_substr( $value, 0, 300 );
        }

        return substr( $value, 0, 300 );
    }

    private function filter_legacy_entries_for_runtime_truth( array $legacy_entries, array $local_execution_events ): array
    {
        if ( empty( $legacy_entries ) )
        {
            return [];
        }

        $events_by_request_id = [];
        foreach ( $local_execution_events as $event )
        {
            if ( ! is_array( $event ) )
            {
                continue;
            }

            $execution_request_id = isset( $event['execution_request_id'] ) && is_scalar( $event['execution_request_id'] )
                ? sanitize_text_field( (string) $event['execution_request_id'] )
                : '';
            if ( '' === $execution_request_id )
            {
                continue;
            }

            $events_by_request_id[ $execution_request_id ] = $event;
        }

        $filtered = [];
        foreach ( $legacy_entries as $entry )
        {
            if ( ! is_array( $entry ) )
            {
                continue;
            }

            if ( $this->is_untrusted_local_first_success_legacy_entry( $entry, $events_by_request_id ) )
            {
                continue;
            }

            $filtered[] = $entry;
        }

        return $filtered;
    }

    private function is_untrusted_local_first_success_legacy_entry( array $entry, array $events_by_request_id ): bool
    {
        if ( 'success' !== sanitize_key( (string) ( $entry['status'] ?? '' ) ) )
        {
            return false;
        }

        if ( ! $this->is_local_first_legacy_entry( $entry ) )
        {
            return false;
        }

        $execution_request_id = isset( $entry['execution_request_id'] ) && is_scalar( $entry['execution_request_id'] )
            ? sanitize_text_field( (string) $entry['execution_request_id'] )
            : '';
        if ( '' === $execution_request_id )
        {
            return true;
        }

        $event = $events_by_request_id[ $execution_request_id ] ?? null;
        if ( ! is_array( $event ) )
        {
            return true;
        }

        return 'success' !== $this->normalize_local_execution_status( (string) ( $event['status'] ?? '' ) );
    }

    private function is_local_first_legacy_entry( array $entry ): bool
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

    private function format_local_execution_event_for_log( array $event ): array
    {
        $result_json = is_array( $event['result_json'] ?? null ) ? $event['result_json'] : [];
        $result_data = $this->extract_local_result_data( $result_json );
        $status      = $this->normalize_local_execution_status( (string) ( $event['status'] ?? '' ) );
        $action      = $this->resolve_local_execution_action( $event );
        $cost        = is_array( $event['cost_json'] ?? null ) ? $event['cost_json'] : [];
        $provider    = sanitize_key( (string) ( $event['provider'] ?? 'openrouter' ) );
        if ( 'sentient_managed' === $provider && class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' ) )
        {
            $result_json = Sentient_Forms_Managed_Usage_Sanitizer::sanitize_for_managed_context( $result_json );
            $cost        = Sentient_Forms_Managed_Usage_Sanitizer::sanitize_for_managed_context( $cost );
        }
        $metering    = is_array( $result_json['metering'] ?? null ) ? $result_json['metering'] : [];

        $pricing = [
            'pricing_policy_version'     => 'local-direct-provider-v1',
            'estimate_source'            => 'local_direct_provider',
            'base_floor_credits'         => 0,
            'normalized_actual_credits'  => 0,
            'debited_credits'            => 0,
        ];

        if ( 'sentient_managed' === $provider )
        {
            $debited_credits = isset( $metering['debited_credits'] ) ? absint( $metering['debited_credits'] ) : 0;
            $pricing = [
                'pricing_policy_version'     => isset( $metering['pricing_policy_version'] ) && is_scalar( $metering['pricing_policy_version'] )
                    ? sanitize_text_field( (string) $metering['pricing_policy_version'] )
                    : 'sentient-managed-v1',
                'estimate_source'            => 'sentient_forms_managed_metering',
                'base_floor_credits'         => 0,
                'normalized_actual_credits'  => $debited_credits,
                'debited_credits'            => $debited_credits,
            ];
        }

        if ( ! empty( $cost ) && 'sentient_managed' !== $provider )
        {
            $pricing['provider_cost'] = $cost;
        }

        $details = [
            'source'             => 'local_execution_events',
            'provider'           => $provider,
            'token_usage'        => is_array( $event['token_usage_json'] ?? null ) ? $event['token_usage_json'] : [],
            'stored_result'      => $result_json,
            'evaluation_payload' => [
                'result_data' => $result_data,
            ],
        ];
        if ( 'sentient_managed' !== $provider )
        {
            $details['cost'] = $cost;
        }

        return [
            'id'                      => 'local-event-' . absint( $event['id'] ?? 0 ),
            'form_source'             => sanitize_key( (string) ( $event['form_source'] ?? 'unknown' ) ),
            'form_id'                 => absint( $event['form_id'] ?? 0 ),
            'entry_id'                => $this->normalize_log_entry_id( $event['entry_id'] ?? null ),
            'action_code'             => $action['code'],
            'action_label'            => $action['label'],
            'status'                  => $status,
            'result_summary'          => $this->extract_local_result_summary( $result_json, $result_data ),
            'classification'          => $this->extract_local_result_classification( $result_json, $result_data ),
            'credits_used'            => 0,
            'error_code'              => isset( $event['error_code'] ) ? sanitize_text_field( (string) $event['error_code'] ) : null,
            'error_message'           => isset( $event['error_message'] ) ? sanitize_textarea_field( (string) $event['error_message'] ) : null,
            'execution_request_id'    => isset( $event['execution_request_id'] ) ? sanitize_text_field( (string) $event['execution_request_id'] ) : null,
            'mapping_id'              => isset( $event['mapping_id'] ) ? 'local_first_' . absint( $event['mapping_id'] ) : null,
            'resolved_model_id'       => isset( $event['model'] ) ? sanitize_text_field( (string) $event['model'] ) : null,
            'pricing'                 => $pricing,
            'usage_cost'              => $this->build_local_usage_cost_summary( $provider, $event, $result_json, $cost, $pricing ),
            'details'                 => $details,
            'structured_output_valid' => 'success' === $status && ! empty( $result_data ),
            'created_at'              => sanitize_text_field( (string) ( $event['created_at'] ?? '' ) ),
            'completed_at'            => $this->is_terminal_log_status( $status )
                ? sanitize_text_field( (string) ( $event['updated_at'] ?? $event['created_at'] ?? '' ) )
                : null,
        ];
    }

    private function normalize_log_entry_id( mixed $entry_id ): ?int
    {
        if ( null === $entry_id || '' === $entry_id )
        {
            return null;
        }

        $normalized = absint( $entry_id );
        return $normalized > 0 ? $normalized : null;
    }

    private function normalize_local_execution_status( string $status ): string
    {
        return match ( sanitize_key( $status ) ) {
            'succeeded', 'success' => 'success',
            'failed', 'error'      => 'error',
            'blocked', 'skipped'   => 'blocked',
            default                => 'pending',
        };
    }

    private function is_terminal_log_status( string $status ): bool
    {
        return in_array( $status, [ 'success', 'blocked', 'error' ], true );
    }

    private function resolve_local_execution_action( array $event ): array
    {
        $mapping_id = absint( $event['mapping_id'] ?? 0 );
        $provider   = sanitize_key( (string) ( $event['provider'] ?? '' ) );
        $is_managed = 'sentient_managed' === $provider;
        if ( ! $is_managed && class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' ) )
        {
            $is_managed = Sentient_Forms_Managed_Usage_Sanitizer::is_managed_provider( $provider );
        }

        $fallback   = [
            'code'  => $mapping_id > 0
                ? 'local_first_' . $mapping_id
                : ( $is_managed ? 'sentient_forms_managed_action' : 'local_openrouter_action' ),
            'label' => $mapping_id > 0
                /* translators: %d: Local form mapping database ID. */
                ? sprintf( __( 'Local mapping #%d', 'sentient-forms' ), $mapping_id )
                : (
                    $is_managed
                    ? __( 'Sentient Forms managed action', 'sentient-forms' )
                    : __( 'Local OpenRouter action', 'sentient-forms' )
                ),
        ];

        if ( $mapping_id <= 0 )
        {
            return $fallback;
        }

        $mapping = $this->get_local_mapping( $mapping_id );
        if ( ! $mapping )
        {
            return $fallback;
        }

        $action_id   = absint( $mapping['action_id'] ?? 0 );
        $action_kind = sanitize_key( (string) ( $mapping['action_kind'] ?? '' ) );
        if ( 'custom_action' === $action_kind )
        {
            $action = $this->get_local_custom_action( $action_id );
            if ( $action )
            {
                return [
                    'code'  => sanitize_key( (string) ( $action['code'] ?? $fallback['code'] ) ),
                    'label' => sanitize_text_field( (string) ( $action['display_name'] ?? $fallback['label'] ) ),
                ];
            }
        }

        if ( 'template' === $action_kind )
        {
            $template = $this->get_local_action_template( $action_id );
            if ( $template )
            {
                return [
                    'code'  => sanitize_key( (string) ( $template['code'] ?? $fallback['code'] ) ),
                    'label' => sanitize_text_field( (string) ( $template['display_name'] ?? $fallback['label'] ) ),
                ];
            }
        }

        return $fallback;
    }

    private function get_local_mapping( int $mapping_id ): ?array
    {
        if ( array_key_exists( $mapping_id, $this->local_mapping_cache ) )
        {
            return $this->local_mapping_cache[ $mapping_id ];
        }

        if ( ! class_exists( 'Sentient_Forms_Form_Mappings_Repository' ) )
        {
            $this->local_mapping_cache[ $mapping_id ] = null;
            return null;
        }

        global $wpdb;
        $repository = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $this->local_mapping_cache[ $mapping_id ] = $repository->get( $mapping_id );
        return $this->local_mapping_cache[ $mapping_id ];
    }

    private function get_local_custom_action( int $action_id ): ?array
    {
        if ( array_key_exists( $action_id, $this->local_custom_action_cache ) )
        {
            return $this->local_custom_action_cache[ $action_id ];
        }

        if ( $action_id <= 0 || ! class_exists( 'Sentient_Forms_Local_Custom_Actions_Repository' ) )
        {
            $this->local_custom_action_cache[ $action_id ] = null;
            return null;
        }

        global $wpdb;
        $repository = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $this->local_custom_action_cache[ $action_id ] = $repository->get( $action_id );
        return $this->local_custom_action_cache[ $action_id ];
    }

    private function get_local_action_template( int $template_id ): ?array
    {
        if ( array_key_exists( $template_id, $this->local_action_template_cache ) )
        {
            return $this->local_action_template_cache[ $template_id ];
        }

        if ( $template_id <= 0 || ! class_exists( 'Sentient_Forms_Action_Templates_Repository' ) )
        {
            $this->local_action_template_cache[ $template_id ] = null;
            return null;
        }

        global $wpdb;
        $repository = new Sentient_Forms_Action_Templates_Repository( $wpdb );
        $this->local_action_template_cache[ $template_id ] = $repository->get( $template_id );
        return $this->local_action_template_cache[ $template_id ];
    }

    private function extract_local_result_data( array $result_json ): array
    {
        foreach ( [ 'structured', 'result_data', 'structured_output' ] as $key )
        {
            if ( is_array( $result_json[ $key ] ?? null ) )
            {
                return $result_json[ $key ];
            }
        }

        $result_data = [];
        foreach ( [ 'classification', 'summary', 'reasoning', 'justification', 'confidence', 'indicators' ] as $key )
        {
            if ( array_key_exists( $key, $result_json ) )
            {
                $result_data[ $key ] = $result_json[ $key ];
            }
        }

        return $result_data;
    }

    private function extract_local_result_summary( array $result_json, array $result_data ): ?string
    {
        foreach (
            [
                $result_data['summary'] ?? null,
                $result_data['justification'] ?? null,
                $result_data['reasoning'] ?? null,
                $result_json['summary'] ?? null,
                $result_json['result_summary'] ?? null,
                $result_json['content'] ?? null,
            ] as $candidate
        )
        {
            if ( is_scalar( $candidate ) && '' !== trim( (string) $candidate ) )
            {
                return wp_trim_words( sanitize_textarea_field( (string) $candidate ), 50, '...' );
            }
        }

        return null;
    }

    private function extract_local_result_classification( array $result_json, array $result_data ): ?string
    {
        foreach ( [ $result_data['classification'] ?? null, $result_json['classification'] ?? null ] as $candidate )
        {
            if ( is_scalar( $candidate ) && '' !== trim( (string) $candidate ) )
            {
                return sanitize_text_field( (string) $candidate );
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $result_json
     * @param array<string, mixed> $cost
     * @param array<string, mixed> $pricing
     * @return array<string, mixed>
     */
    private function build_local_usage_cost_summary( string $provider, array $event, array $result_json, array $cost, array $pricing ): array
    {
        if ( 'sentient_managed' === $provider )
        {
            $credits = absint( $pricing['debited_credits'] ?? 0 );
            return [
                'route'       => 'sentient_forms_managed',
                'label'       => sprintf(
                    /* translators: %d: Sentient Forms credit count. */
                    _n( 'SF %d credit', 'SF %d credits', $credits, 'sentient-forms' ),
                    $credits
                ),
                'kind'        => 'sentient_credits',
                'credits'     => $credits,
                'known'       => true,
            ];
        }

        $amount = $this->extract_amount_usd( $cost );
        if ( null !== $amount )
        {
            return [
                'route'      => 'openrouter_direct',
                'label'      => 0.0 === $amount ? 'OR Free' : 'OR ' . $this->format_usd( $amount ),
                'kind'       => 0.0 === $amount ? 'openrouter_free' : 'openrouter_currency',
                'amount_usd' => $amount,
                'known'      => true,
            ];
        }

        $model = is_scalar( $event['model'] ?? null ) ? (string) $event['model'] : '';
        if ( str_ends_with( $model, ':free' ) || ! empty( $cost['free'] ) )
        {
            return [
                'route'      => 'openrouter_direct',
                'label'      => 'OR Free',
                'kind'       => 'openrouter_free',
                'amount_usd' => 0.0,
                'known'      => true,
            ];
        }

        return [
            'route' => 'openrouter_direct',
            'label' => 'Unknown',
            'kind'  => 'unknown',
            'known' => false,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function build_legacy_usage_cost_summary( array $data ): array
    {
        $pricing = is_array( $data['pricing'] ?? null ) ? $data['pricing'] : [];
        $credits = absint( $pricing['debited_credits'] ?? $data['credits_used'] ?? 0 );
        if ( $credits > 0 )
        {
            return [
                'route'   => 'sentient_forms_managed',
                'label'   => sprintf(
                    /* translators: %d: Sentient Forms credit count. */
                    _n( 'SF %d credit', 'SF %d credits', $credits, 'sentient-forms' ),
                    $credits
                ),
                'kind'    => 'sentient_credits',
                'credits' => $credits,
                'known'   => true,
            ];
        }

        return [
            'route' => 'unknown',
            'label' => 'Unknown',
            'kind'  => 'unknown',
            'known' => false,
        ];
    }

    /**
     * @param array<string, mixed> $cost
     */
    private function extract_amount_usd( array $cost ): ?float
    {
        if ( isset( $cost['amount_usd'] ) && is_numeric( $cost['amount_usd'] ) )
        {
            $amount = (float) $cost['amount_usd'];
            return $amount >= 0 ? $amount : null;
        }

        if ( isset( $cost['cost'] ) && is_numeric( $cost['cost'] ) )
        {
            $amount = (float) $cost['cost'];
            return $amount >= 0 ? $amount : null;
        }

        return null;
    }

    private function format_usd( float $amount ): string
    {
        if ( $amount >= 1 )
        {
            return '$' . number_format( $amount, 2 );
        }

        if ( $amount >= 0.01 )
        {
            return '$' . number_format( $amount, 4 );
        }

        return '$' . rtrim( rtrim( number_format( $amount, 6 ), '0' ), '.' );
    }

    private static function backfill_local_execution_events(
        array $execution_request_ids,
        int $entry_id,
        ?string $expected_form_source,
        ?int $expected_form_id
    ): int
    {
        if ( ! class_exists( 'Sentient_Forms_Execution_Events_Repository' ) )
        {
            return 0;
        }

        global $wpdb;

        $repository = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $updated    = 0;
        foreach ( $execution_request_ids as $execution_request_id )
        {
            $event = $repository->get_by_request_id( $execution_request_id );
            if ( ! $event )
            {
                continue;
            }

            if ( null !== $expected_form_source && ( $event['form_source'] ?? '' ) !== $expected_form_source )
            {
                continue;
            }

            if ( null !== $expected_form_id && absint( $event['form_id'] ?? 0 ) !== $expected_form_id )
            {
                continue;
            }

            if ( absint( $event['entry_id'] ?? 0 ) > 0 )
            {
                continue;
            }

            $event['entry_id'] = (string) $entry_id;
            $recorded          = $repository->record( $event );
            if ( ! is_wp_error( $recorded ) )
            {
                $updated++;
            }
        }

        return $updated;
    }

    private static function sanitize_log_json_value( $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        return json_decode( wp_json_encode( $value ), true ) ?: [];
    }

    private static function resolve_execution_request_id( array $data ): string
    {
        if ( ! empty( $data['execution_request_id'] ) && is_scalar( $data['execution_request_id'] ) )
        {
            return sanitize_text_field( (string) $data['execution_request_id'] );
        }

        $seed = [
            'form_source' => sanitize_key( $data['form_source'] ?? 'unknown' ),
            'form_id'     => absint( $data['form_id'] ?? 0 ),
            'entry_id'    => isset( $data['entry_id'] ) ? absint( $data['entry_id'] ) : null,
            'mapping_id'  => isset( $data['mapping_id'] ) ? sanitize_text_field( (string) $data['mapping_id'] ) : null,
            'action_code' => sanitize_text_field( $data['action_code'] ?? '' ),
        ];

        return 'wp-' . substr( hash( 'sha256', wp_json_encode( $seed ) ), 0, 48 );
    }

    public function get_collection_params(): array
    {
        return array_merge(
            parent::get_collection_params(),
            [
                'form_id' => [
                    'description'       => __( 'Filter by form ID.', 'sentient-forms' ),
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'action_code' => [
                    'description'       => __( 'Filter by action code.', 'sentient-forms' ),
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'status' => [
                    'description' => __( 'Filter by status.', 'sentient-forms' ),
                    'type'        => 'string',
                    'enum'        => [ 'pending', 'success', 'blocked', 'error' ],
                ],
                'date_from' => [
                    'description'       => __( 'Filter entries created after this ISO date.', 'sentient-forms' ),
                    'type'              => 'string',
                    'format'            => 'date-time',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'date_to' => [
                    'description'       => __( 'Filter entries created before this ISO date.', 'sentient-forms' ),
                    'type'              => 'string',
                    'format'            => 'date-time',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ]
        );
    }

    public function get_item_schema(): ?array
    {
        if ( $this->schema )
        {
            return $this->schema;
        }

        $this->schema = [
            '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
            'title'       => 'action_log_entry',
            'description' => __( 'A record of an AI action execution.', 'sentient-forms' ),
            'type'        => 'object',
            'properties'  => [
                'id' => [
                    'description' => __( 'Unique log entry identifier.', 'sentient-forms' ),
                    'type'        => 'string',
                    'readonly'    => true,
                ],
                'form_source' => [
                    'description' => __( 'Form provider (e.g., gravity_forms).', 'sentient-forms' ),
                    'type'        => 'string',
                ],
                'form_id' => [
                    'description' => __( 'Form ID.', 'sentient-forms' ),
                    'type'        => 'integer',
                ],
                'entry_id' => [
                    'description' => __( 'Entry ID (null for validation-phase).', 'sentient-forms' ),
                    'type'        => [ 'integer', 'null' ],
                ],
                'action_code' => [
                    'description' => __( 'Action template code.', 'sentient-forms' ),
                    'type'        => 'string',
                ],
                'action_label' => [
                    'description' => __( 'Human-readable action label.', 'sentient-forms' ),
                    'type'        => 'string',
                ],
                'status' => [
                    'description' => __( 'Execution status.', 'sentient-forms' ),
                    'type'        => 'string',
                    'enum'        => [ 'pending', 'success', 'blocked', 'error' ],
                ],
                'result_summary' => [
                    'description' => __( 'Truncated LLM output.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                ],
                'classification' => [
                    'description' => __( 'Classification result (spam/ham/etc).', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                ],
                'credits_used' => [
                    'description' => __( 'Sentient Forms managed credits debited; zero for direct local provider runs.', 'sentient-forms' ),
                    'type'        => 'integer',
                ],
                'error_code' => [
                    'description' => __( 'Error code if failed.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                ],
                'error_message' => [
                    'description' => __( 'Error message if failed.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                ],
                'execution_request_id' => [
                    'description' => __( 'Stable execution request identifier.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                ],
                'mapping_id' => [
                    'description' => __( 'Form mapping identifier, when available.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                ],
                'resolved_model_id' => [
                    'description' => __( 'Resolved model used for the execution, when available.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                ],
                'pricing' => [
                    'description' => __( 'Pricing summary metadata for the execution.', 'sentient-forms' ),
                    'type'        => 'object',
                    'readonly'    => true,
                ],
                'usage_cost' => [
                    'description' => __( 'Human-readable billing route and provider cost summary.', 'sentient-forms' ),
                    'type'        => 'object',
                    'readonly'    => true,
                ],
                'details' => [
                    'description' => __( 'Extended operational details for the execution.', 'sentient-forms' ),
                    'type'        => 'object',
                    'readonly'    => true,
                ],
                'created_at' => [
                    'description' => __( 'When the action was queued.', 'sentient-forms' ),
                    'type'        => 'string',
                    'format'      => 'date-time',
                    'readonly'    => true,
                ],
                'completed_at' => [
                    'description' => __( 'When the action completed.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                    'format'      => 'date-time',
                    'readonly'    => true,
                ],
                'structured_output_valid' => [
                    'description' => __( 'Whether execution produced valid structured output.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'readonly'    => true,
                ],
                'form_context' => [
                    'description' => __( 'Human-friendly form provider, form, entry, and admin jump-link context.', 'sentient-forms' ),
                    'type'        => 'object',
                    'readonly'    => true,
                ],
            ],
        ];

        return $this->schema;
    }
}
