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
    private const REMOTE_PATH = '/execution-audit';

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

        $remote_entries = $this->get_remote_log_entries( $request );
        if ( ! is_wp_error( $remote_entries ) )
        {
            return $this->prepare_item_for_response( $remote_entries );
        }

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
            'execution_request_id' => $request->get_param( 'execution_request_id' ) ?: null,
            'mapping_id'     => $request->get_param( 'mapping_id' ) ?: null,
            'resolved_model_id' => $request->get_param( 'resolved_model_id' ) ?: null,
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
            'details'        => self::sanitize_log_json_value( $data['details'] ?? [] ),
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

        $saved = update_option( self::OPTION_KEY, $entries, false );
        self::mirror_execution_to_cps( $entry );

        return $saved;
    }

    /**
     * Attach a saved form entry id to prior validation-hook audit rows.
     *
     * Gravity Forms validation runs before the entry exists. Once gform_entry_post_save fires,
     * the plugin can safely re-mirror matching execution_request_id rows to CPS; the CPS audit
     * endpoint upserts on execution_request_id and fills entry_id without duplicating rows.
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
            return 0;
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

        if ( empty( $updated_entries ) )
        {
            return 0;
        }

        update_option( self::OPTION_KEY, $entries, false );

        foreach ( $updated_entries as $updated_entry )
        {
            self::mirror_execution_to_cps( $updated_entry );
        }

        return count( $updated_entries );
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

    private function get_remote_log_entries( WP_REST_Request $request ): array | WP_Error
    {
        $plugin  = Sentient_Forms_Plugin::instance();
        $api_key = $plugin->get_proxy_api_key();

        if ( empty( $api_key ) )
        {
            return new WP_Error( 'missing_api_key', __( 'Proxy API key is not configured.', 'sentient-forms' ) );
        }

        $params = array_filter(
            [
                'page'        => $request->has_param( 'page' )
                    ? max( 1, (int) $request->get_param( 'page' ) )
                    : 1,
                'per_page'    => $request->has_param( 'per_page' )
                    ? min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) )
                    : 20,
                'form_id'     => $request->get_param( 'form_id' ),
                'action_code' => $request->get_param( 'action_code' ),
                'status'      => $request->get_param( 'status' ),
                'date_from'   => $request->get_param( 'date_from' ),
                'date_to'     => $request->get_param( 'date_to' ),
            ],
            static function ( $value ): bool {
                return null !== $value && '' !== $value;
            }
        );

        $path = self::REMOTE_PATH;
        if ( ! empty( $params ) )
        {
            $path .= '?' . http_build_query( $params );
        }

        return $plugin->get_cps_api_client()->get(
            $path,
            [
                'bearer_token' => $api_key,
            ]
        );
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

    private static function mirror_execution_to_cps( array $entry ): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $api_key = $plugin->get_proxy_api_key();
        if ( empty( $api_key ) )
        {
            return;
        }

        $payload = [
            'execution_request_id'  => $entry['execution_request_id'],
            'form_source'           => $entry['form_source'],
            'form_id'               => (int) $entry['form_id'],
            'entry_id'              => $entry['entry_id'],
            'mapping_id'            => $entry['mapping_id'],
            'action_code'           => $entry['action_code'],
            'action_label'          => $entry['action_label'],
            'status'                => $entry['status'],
            'result_summary'        => $entry['result_summary'],
            'classification'        => $entry['classification'],
            'credits_used'          => (int) ( $entry['credits_used'] ?? 0 ),
            'structured_output_valid' => ! empty( $entry['structured_output_valid'] ),
            'error_code'            => $entry['error_code'],
            'error_message'         => $entry['error_message'],
            'resolved_model_id'     => $entry['resolved_model_id'],
            'pricing'               => is_array( $entry['pricing'] ?? null ) ? $entry['pricing'] : [],
            'details'               => is_array( $entry['details'] ?? null ) ? $entry['details'] : [],
            'started_at'            => $entry['created_at'],
            'completed_at'          => $entry['completed_at'],
        ];

        $response = $plugin->get_cps_api_client()->post(
            self::REMOTE_PATH,
            $payload,
            [
                'bearer_token' => $api_key,
            ]
        );

        if ( is_wp_error( $response ) && defined( 'WP_DEBUG' ) && WP_DEBUG )
        {
            sentient_forms_debug_log(
                '[sentient-forms] failed to mirror execution audit to CPS.',
                [
                    'error_code'    => $response->get_error_code(),
                    'error_message' => $response->get_error_message(),
                ]
            );
        }
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
                    'description' => __( 'Whether the CPS response included valid structured output.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'readonly'    => true,
                ],
            ],
        ];

        return $this->schema;
    }
}
