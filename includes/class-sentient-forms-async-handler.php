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

/**
 * Class Sentient_Forms_Async_Handler
 * Handles asynchronous processing of actions
 */
class Sentient_Forms_Async_Handler
{

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

    /**
     * Initialize the async handler
     *
     * @return void
     */
    public function init(): void
    {
        // Register the action hook for processing actions
        add_action( 'sentient_forms_process_action', [ $this, 'process_action' ], 10, 3 );

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
    public function schedule_action( string $action_id, array $data, array $settings ): bool
    {
        // Get the action instance
        $action = $this->plugin->get_action( $action_id );
        if ( !$action )
        {
            return false;
        }

        // Schedule the action
        if ( function_exists( 'as_schedule_single_action' ) )
        {
            // Use Action Scheduler if available
            return as_schedule_single_action(
                time(),
                'sentient_forms_process_action',
                [
                    'action_id' => $action_id,
                    'data'      => $data,
                    'settings'  => $settings,
                ],
                'sentient_forms',
            );
        }
        else
        {
            // Fallback to WP-Cron
            return wp_schedule_single_event(
                time(),
                'sentient_forms_process_action',
                [
                    'action_id' => $action_id,
                    'data'      => $data,
                    'settings'  => $settings,
                ],
            );
        }
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
    public function process_action( string $action_id, array $data, array $settings ): void
    {
        // Get the action instance
        $action = $this->plugin->get_action( $action_id );
        if ( !$action )
        {
            $this->log_error( sprintf( __( 'Action %s not found.', 'sentient-forms' ), $action_id ) );
            return;
        }

        // Execute the action
        try
        {
            $result = $action->execute( $data, $settings );
            $this->log_success( $action_id, $result );
        } catch ( Exception $e )
        {
            $this->log_error( sprintf( __( 'Error processing action %s: %s', 'sentient-forms' ), $action_id, $e->getMessage() ) );
        }
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