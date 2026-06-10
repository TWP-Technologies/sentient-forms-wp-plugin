<?php
/**
 * REST API controller for async health summaries.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Async_Health_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;

    protected string $rest_base = 'async-health';

    public function __construct()
    {
        parent::__construct();
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_health' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'purge_jobs' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'status' => [
                            'type'              => 'string',
                            'default'           => 'queued,failed',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'older_than' => [
                            'type'              => 'integer',
                            'default'           => 10080, // 1 week in minutes
                            'sanitize_callback' => 'absint',
                        ],
                        'clear_all' => [
                            'type'              => 'boolean',
                            'default'           => false,
                        ],
                    ],
                ],
            ]
        );
    }

    public function get_health( WP_REST_Request $request ): WP_REST_Response
    {
        $payload = Sentient_Forms_Plugin::instance()->get_async_health_service()->evaluate();
        return $this->prepare_item_for_response( $payload );
    }

    /**
     * Purge async jobs based on status and age filters.
     */
    public function purge_jobs( WP_REST_Request $request ): WP_REST_Response
    {
        $store = new Sentient_Forms_Async_Metadata_Store();

        // If clear_all is true, clear everything
        if ( $request->get_param( 'clear_all' ) )
        {
            $store->clear();
            return $this->prepare_item_for_response( [
                'removed' => -1, // -1 indicates all cleared
                'message' => 'All background job metadata cleared.',
            ] );
        }

        // Parse status filter
        $status_arg = $request->get_param( 'status' );
        $statuses   = array_filter( array_map( 'trim', explode( ',', $status_arg ) ) );

        // Parse older_than filter (minutes)
        $older_than_minutes = max( 0, (int) $request->get_param( 'older_than' ) );
        $threshold          = $older_than_minutes > 0 ? time() - ( $older_than_minutes * 60 ) : null;

        $removed = $store->purge(
            function ( array $job ) use ( $statuses, $threshold ): bool {
                // Filter by status
                if ( ! empty( $statuses ) && ! in_array( $job['status'] ?? '', $statuses, true ) )
                {
                    return false;
                }

                // Filter by age
                if ( null === $threshold )
                {
                    return true;
                }

                $reference = $job['updated_at'] ?? $job['completed_at'] ?? $job['run_at'] ?? $job['scheduled_at'] ?? 0;
                if ( 0 === $reference )
                {
                    return false;
                }

                return $reference <= $threshold;
            }
        );

        return $this->prepare_item_for_response( [
            'removed' => $removed,
            'message' => sprintf( 'Purged %d job(s).', $removed ),
        ] );
    }
}
