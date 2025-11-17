<?php
/**
 * Async handler for processing actions in the background
 *
 * @package Sentient_Forms
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

use Throwable;
use WP_Error;

/**
 * Class Sentient_Forms_Async_Handler
 * Handles asynchronous processing of actions
 */
class Sentient_Forms_Async_Handler
{
    private const MAX_ATTEMPTS = 3;
    private const BASE_BACKOFF_SECONDS = 60;

    /**
     * Plugin instance
     */
    private Sentient_Forms_Plugin $plugin;

    /**
     * Constructor
     *
     * @param Sentient_Forms_Plugin $plugin Plugin instance.
     */
    public function __construct( Sentient_Forms_Plugin $plugin )
    {
        $this->plugin = $plugin;
        $this->init();
    }

    public function process_evaluation( array $payload ): void
    {
        $context = $this->normalize_context(
            $payload['context'] ?? [],
            (string) ( $payload['context']['action_id'] ?? 'evaluation' ),
        );

        $result  = isset( $context['evaluation_payload'] ) && is_array( $context['evaluation_payload'] )
            ? $context['evaluation_payload']
            : [];
        $adapter = $this->resolve_async_adapter( $context['form_source'] ?? null, $context );

        if ( !$adapter )
        {
            return;
        }

        try
        {
            $adapter->finalize_async_evaluation( $context, $result );
            do_action( 'sentient_forms_async_success', $context, $result );
            $this->emit_async_event( 'evaluation_success', $context, $result );
        } catch ( Throwable $throwable )
        {
            $this->handle_evaluation_failure(
                $context,
                $result,
                new WP_Error( 'sentient_forms_async_evaluation_failed', $throwable->getMessage() ),
            );
        }
    }

    private function handle_success( array $job, array $result ): void
    {
        $this->log_success( $job['action_id'], $result );
        do_action( 'sentient_forms_async_success', $job['context'], $result );
        $this->notify_adapter_success( $job['context'], $result );
        $this->emit_async_event( 'success', $job['context'], $result );
    }

    private function handle_failure( array $job, WP_Error $error ): void
    {
        $context = $job['context'];
        $attempt = (int) $context['attempt'];
        $max     = (int) $context['max_attempts'];

        if ( $attempt < $max )
        {
            $context['attempt']    = $attempt + 1;
            $context['last_error'] = $error->get_error_message();
            $delay                 = $this->compute_backoff_delay( $attempt );
            $this->schedule_action(
                $job['action_id'],
                $job['data'],
                $job['settings'],
                $context,
                time() + $delay,
            );
            $this->emit_async_event(
                'retry_scheduled',
                $context,
                [
                    'error' => $error->get_error_message(),
                    'run_at' => time() + $delay,
                ],
            );
            return;
        }

        $this->log_error( $error->get_error_message() );
        do_action( 'sentient_forms_async_failure', $context, $error );
        $this->notify_adapter_error( $context, $error );
        $this->emit_async_event(
            'failed',
            $context,
            [ 'error' => $error->get_error_message() ],
        );
    }

    private function handle_evaluation_failure( array $context, array $result, WP_Error $error ): void
    {
        $attempt = (int) $context['attempt'];
        $max     = (int) $context['max_attempts'];

        if ( $attempt < $max )
        {
            $context['attempt']    = $attempt + 1;
            $context['last_error'] = $error->get_error_message();
            $delay                 = $this->compute_backoff_delay( $attempt );
            $this->dispatch_evaluation(
                [
                    'adapter_id' => $context['adapter_id'] ?? $context['form_source'] ?? null,
                    'entry_id'   => $context['entry_id'] ?? null,
                    'form_id'    => $context['form_id'] ?? null,
                    'action_id'  => $context['action_id'] ?? null,
                    'payload'    => $result,
                    'context'    => $context,
                    'run_at'     => time() + $delay,
                ],
            );
            $this->emit_async_event(
                'evaluation_retry_scheduled',
                $context,
                [
                    'error' => $error->get_error_message(),
                    'run_at' => time() + $delay,
                ],
            );
            return;
        }

        $this->log_error( $error->get_error_message() );
        do_action( 'sentient_forms_async_failure', $context, $error );
        $this->notify_adapter_error( $context, $error );
        $this->emit_async_event(
            'evaluation_failed',
            $context,
            [ 'error' => $error->get_error_message() ],
        );
    }

    private function notify_adapter_success( array $context, array $result ): void
    {
        $adapter = $this->resolve_async_adapter( $context['form_source'] ?? null, $context );
        if ( $adapter )
        {
            $adapter->finalize_async_success( $context, $result );
        }
    }

    private function notify_adapter_error( array $context, WP_Error $error ): void
    {
        $adapter = $this->resolve_async_adapter( $context['form_source'] ?? null, $context );
        if ( $adapter )
        {
            $adapter->finalize_async_error( $context, $error );
        }
    }

    private function compute_backoff_delay( int $attempt ): int
    {
        $delay = (int) ( self::BASE_BACKOFF_SECONDS * pow( 2, max( 0, $attempt - 1 ) ) );
        return min( HOUR_IN_SECONDS, max( self::BASE_BACKOFF_SECONDS, $delay ) );
    }

    private function enqueue_job( string $hook, array $args, int $run_at ): bool
    {
        if ( function_exists( 'as_schedule_single_action' ) )
        {
            return (bool) as_schedule_single_action( $run_at, $hook, $args, 'sentient_forms' );
        }

        if ( function_exists( 'as_enqueue_async_action' ) )
        {
            as_enqueue_async_action( $hook, $args, 'sentient_forms' );
            return true;
        }

        return (bool) wp_schedule_single_event( $run_at, $hook, $args );
    }

    private function normalize_context( array $context, string $action_id = '' ): array
    {
        $attempt = isset( $context['attempt'] ) ? max( 1, (int) $context['attempt'] ) : 1;
        $max     = isset( $context['max_attempts'] ) ? max( 1, (int) $context['max_attempts'] ) : self::MAX_ATTEMPTS;

        $defaults = [
            'attempt'      => $attempt,
            'max_attempts' => $max,
            'job_type'     => $context['job_type'] ?? 'execution',
            'action_id'    => $context['action_id'] ?? $action_id,
        ];

        if ( !isset( $context['form_source'] ) && isset( $context['adapter_id'] ) )
        {
            $defaults['form_source'] = $context['adapter_id'];
        }

        return array_merge( $defaults, $context );
    }

    private function resolve_async_adapter( ?string $form_source, array $context = [] ): ?Sentient_Forms_Async_Capable_Adapter_Interface
    {
        $adapter_id = $form_source ?? ( $context['adapter_id'] ?? null );
        if ( empty( $adapter_id ) )
        {
            return null;
        }

        $registry = $this->plugin->get_form_adapter_registry();
        $adapter  = $registry ? $registry->get_adapter_by_id( $adapter_id ) : null;

        return $adapter instanceof Sentient_Forms_Async_Capable_Adapter_Interface ? $adapter : null;
    }

    /**
     * Initialize the async handler
     *
     * @return void
     */
    public function init(): void
    {
        // Register the action hook for processing actions
        add_action( 'sentient_forms_process_action', [ $this, 'process_action' ], 10, 4 );
        add_action( 'sentient_forms_evaluate_action', [ $this, 'process_evaluation' ], 10, 1 );

        // Register the action hook for Action Scheduler
        if ( function_exists( 'as_schedule_single_action' ) )
        {
            add_action( 'init', [ $this, 'register_action_scheduler_group' ] );
        }
    }

    /**
     * Register Action Scheduler group
     *
     * @return void
     */
    public function register_action_scheduler_group(): void
    {
        if ( function_exists( 'as_register_group' ) )
        {
            as_register_group( 'sentient_forms', __( 'Sentient Forms', 'sentient-forms' ) );
        }
    }

    /**
     * Schedule an action to be processed asynchronously
     *
     * @param string $action_id The action ID.
     * @param array  $data      The data to process.
     * @param array  $settings  The action settings.
     *
     * @return bool Whether the action was scheduled.
     */
    public function schedule_action( string $action_id, array $data, array $settings, array $context = [], ?int $run_at = null ): bool
    {
        // Get the action instance
        $action = $this->plugin->get_action( $action_id );
        if ( !$action )
        {
            return false;
        }

        $payload = [
            'action_id'            => $action_id,
            'data'                 => $data,
            'settings'             => $settings,
            'execution_request_id' => $context['execution_request_id'] ?? null,
            'context'              => $this->normalize_context(
                array_merge(
                    [
                        'form_source' => $context['form_source'] ?? null,
                        'job_type'    => $context['job_type'] ?? 'execution',
                    ],
                    $context,
                ),
                $action_id,
            ),
        ];

        return $this->enqueue_job(
            'sentient_forms_process_action',
            $payload,
            $run_at ?? time(),
        );
    }

    public function dispatch_evaluation( array $job ): bool
    {
        $payload = [
            'context' => $this->normalize_context(
                array_merge(
                    [
                        'adapter_id'        => $job['adapter_id'] ?? null,
                        'entry_id'          => $job['entry_id'] ?? null,
                        'form_id'           => $job['form_id'] ?? null,
                        'action_id'         => $job['action_id'] ?? null,
                        'job_type'          => 'evaluation',
                        'evaluation_payload'=> $job['payload'] ?? [],
                    ],
                    $job['context'] ?? [],
                ),
                (string) ( $job['action_id'] ?? 'evaluation' ),
            ),
        ];

        return $this->enqueue_job(
            'sentient_forms_evaluate_action',
            $payload,
            $job['run_at'] ?? time(),
        );
    }

    /**
     * Process an action
     *
     * @param string $action_id The action ID.
     * @param array  $data      The data to process.
     * @param array  $settings  The action settings.
     *
     * @return void
     */
    public function process_action( string $action_id, array $data, array $settings, $execution_request_id = null, array $context = [] ): void
    {
        $context = $this->normalize_context( $context, $action_id );

        $job = [
            'action_id'            => $action_id,
            'data'                 => $data,
            'settings'             => $settings,
            'execution_request_id' => $execution_request_id,
            'context'              => $context,
        ];

        $action = $this->plugin->get_action( $action_id );
        if ( !$action )
        {
            $this->handle_failure(
                $job,
                new WP_Error(
                    'sentient_forms_missing_action',
                    sprintf( __( 'Action %s not found.', 'sentient-forms' ), $action_id ),
                ),
            );
            return;
        }

        try
        {
            $result = $action->execute( $data, $settings );
        } catch ( Throwable $throwable )
        {
            $this->handle_failure(
                $job,
                new WP_Error(
                    'sentient_forms_async_exception',
                    $throwable->getMessage(),
                ),
            );
            return;
        }

        if ( is_wp_error( $result ) )
        {
            $this->handle_failure( $job, $result );
            return;
        }

        $normalized_result = is_array( $result ) ? $result : [ 'success' => (bool) $result ];
        $this->handle_success( $job, $normalized_result );
    }

    /**
     * Log a successful action execution
     *
     * @param string $action_id The action ID.
     * @param array  $result    The action result.
     *
     * @return void
     */
    private function log_success( string $action_id, array $result ): void
    {
        // Get plugin options
        $options    = $this->plugin->get_options();
        $debug_mode = $options[ 'global_settings' ][ 'debug_mode' ] ?? false;

        // Log the result if debug mode is enabled
        if ( $debug_mode )
        {
            error_log(
                sprintf(
                    __( 'Sentient Forms: Action %s processed successfully. Result: %s', 'sentient-forms' ),
                    $action_id,
                    wp_json_encode( $result ),
                ),
            );
        }

        // Store the result in the database
        $this->store_result( $action_id, $result );
    }

    /**
     * Log an error
     *
     * @param string $message The error message.
     *
     * @return void
     */
    private function log_error( string $message ): void
    {
        // Get plugin options
        $options    = $this->plugin->get_options();
        $debug_mode = $options[ 'global_settings' ][ 'debug_mode' ] ?? false;

        // Log the error if debug mode is enabled
        if ( $debug_mode )
        {
            error_log( sprintf( __( 'Sentient Forms Error: %s', 'sentient-forms' ), $message ) );
        }
    }

    /**
     * Emit a consent-aware async event for external logging.
     *
     * @param string $event   Event name (success, failed, retry_scheduled, etc.).
     * @param array  $context Job context metadata.
     * @param array  $payload Result/error payload.
     */
    private function emit_async_event( string $event, array $context = [], array $payload = [] ): void
    {
        $options      = $this->plugin->get_options();
        $debug_mode   = ! empty( $options['global_settings']['debug_mode'] );
        $telemetry    = $this->plugin->get_telemetry_settings();
        $telemetry_on = ! empty( $telemetry['telemetry_opt_in'] );

        if ( !$telemetry_on && !$debug_mode )
        {
            return;
        }

        /**
         * Fires whenever the async handler emits a structured telemetry/logging event.
         *
         * @param array<string, mixed> $event_payload Event data (`event`, `context`, `payload`, `timestamp`).
         */
        do_action(
            'sentient_forms_async_event',
            [
                'event'     => $event,
                'context'   => $context,
                'payload'   => $payload,
                'timestamp' => time(),
            ]
        );
    }

    /**
     * Store the action result in the database
     *
     * @param string $action_id The action ID.
     * @param array  $result    The action result.
     *
     * @return void
     */
    private function store_result( string $action_id, array $result ): void
    {
        // Get plugin options
        $options = $this->plugin->get_options();

        // Initialize the results array if it doesn't exist
        if ( !isset( $options[ 'action_results' ] ) )
        {
            $options[ 'action_results' ] = [];
        }

        // Add the result to the array
        $options[ 'action_results' ][ $action_id ][] = [
            'timestamp' => time(),
            'result'    => $result,
        ];

        // Limit the number of stored results to 20 per action
        if ( count( $options[ 'action_results' ][ $action_id ] ) > 20 )
        {
            $options[ 'action_results' ][ $action_id ] = array_slice( $options[ 'action_results' ][ $action_id ], -20 );
        }

        // Update the options
        $this->plugin->update_options( $options );
    }

    /**
     * Get the median cost for an action
     *
     * @param string $action_id The action ID.
     *
     * @return float|int|null The median cost or null if not enough data.
     */
    public function get_median_cost( string $action_id ): float | int | null
    {
        // Get plugin options
        $options = $this->plugin->get_options();

        // Check if we have results for this action
        if ( !isset( $options[ 'action_results' ][ $action_id ] ) || count( $options[ 'action_results' ][ $action_id ] ) < 10 )
        {
            return null;
        }

        // Extract costs from results
        $costs = [];
        foreach ( $options[ 'action_results' ][ $action_id ] as $result_data )
        {
            if ( isset( $result_data[ 'result' ][ 'cost' ] ) )
            {
                $costs[] = $result_data[ 'result' ][ 'cost' ];
            }
        }

        // Check if we have enough costs
        if ( count( $costs ) < 10 )
        {
            return null;
        }

        // Sort costs
        sort( $costs );

        // Calculate median
        $count  = count( $costs );
        $middle = floor( $count / 2 );

        if ( $count % 2 === 0 )
        {
            // Even number of costs, average the middle two
            return ( $costs[ $middle - 1 ] + $costs[ $middle ] ) / 2;
        }
        else
        {
            // Odd number of costs, return the middle one
            return $costs[ $middle ];
        }
    }
}
