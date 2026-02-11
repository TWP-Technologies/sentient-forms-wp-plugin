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
                            'enum'              => [ 'pending', 'success', 'error' ],
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
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
        $form_id  = $request->get_param( 'form_id' );
        $action_code = $request->get_param( 'action_code' );
        $status   = $request->get_param( 'status' );
        $date_from = $request->get_param( 'date_from' );
        $date_to  = $request->get_param( 'date_to' );

        $all_entries = $this->get_all_entries();

        // Apply filters
        $filtered = array_filter( $all_entries, function ( $entry ) use ( $form_id, $action_code, $status, $date_from, $date_to ) {
            if ( $form_id && ( $entry['form_id'] ?? 0 ) !== (int) $form_id )
            {
                return false;
            }
            if ( $action_code && ( $entry['action_code'] ?? '' ) !== $action_code )
            {
                return false;
            }
            if ( $status && ( $entry['status'] ?? '' ) !== $status )
            {
                return false;
            }
            if ( $date_from && ( $entry['created_at'] ?? '' ) < $date_from )
            {
                return false;
            }
            if ( $date_to && ( $entry['created_at'] ?? '' ) > $date_to )
            {
                return false;
            }
            return true;
        } );

        $total = count( $filtered );
        $total_pages = (int) ceil( $total / $per_page );
        $offset = ( $page - 1 ) * $per_page;

        // Sort by created_at descending (newest first)
        usort( $filtered, fn ( $a, $b ) => strcmp( $b['created_at'] ?? '', $a['created_at'] ?? '' ) );

        $paginated = array_slice( $filtered, $offset, $per_page );

        return $this->prepare_item_for_response( [
            'entries'     => array_values( $paginated ),
            'total'       => $total,
            'total_pages' => $total_pages,
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

    /**
     * Record a log entry programmatically (for internal use by adapters/executors).
     *
     * @param array $data Log entry data.
     * @return bool Whether the entry was saved.
     */
    public static function log_execution( array $data ): bool
    {
        $entry = [
            'id'             => wp_generate_uuid4(),
            'form_source'    => sanitize_key( $data['form_source'] ?? 'unknown' ),
            'form_id'        => absint( $data['form_id'] ?? 0 ),
            'entry_id'       => isset( $data['entry_id'] ) ? absint( $data['entry_id'] ) : null,
            'action_code'    => sanitize_text_field( $data['action_code'] ?? '' ),
            'action_label'   => sanitize_text_field( $data['action_label'] ?? '' ),
            'status'         => in_array( $data['status'] ?? '', [ 'pending', 'success', 'error' ], true )
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
            'structured_output_valid' => ! empty( $data['structured_output_valid'] ),
            'created_at'     => gmdate( 'c' ),
            'completed_at'   => ( $data['status'] ?? '' ) !== 'pending' ? gmdate( 'c' ) : null,
        ];

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

        return update_option( self::OPTION_KEY, $entries, false );
    }

    /**
     * Get all log entries from storage.
     *
     * @return array
     */
    private function get_all_entries(): array
    {
        $entries = get_option( self::OPTION_KEY, [] );
        return is_array( $entries ) ? $entries : [];
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
                    'enum'        => [ 'pending', 'success', 'error' ],
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
                    'format'      => 'uuid',
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
                    'enum'        => [ 'pending', 'success', 'error' ],
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
                    'description' => __( 'Credits consumed.', 'sentient-forms' ),
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
                    'description' => __( 'Whether the CPS response included valid structured output.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'readonly'    => true,
                ],
            ],
        ];

        return $this->schema;
    }
}
