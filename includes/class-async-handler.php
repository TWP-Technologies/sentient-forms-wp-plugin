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
	private const MAX_ATTEMPTS = 3;
	private const BASE_BACKOFF_SECONDS = 60;
	private const ACTION_SCHEDULER_GROUP = 'sentient_forms_async';

	/**
	 * Plugin instance
	 */
	private Sentient_Forms_Plugin $plugin;

	private ?array $retry_config = null;

    /**
     * Constructor
     *
     * @param Sentient_Forms_Plugin $plugin Plugin instance.
     */
    public function __construct( Sentient_Forms_Plugin $plugin, bool $register_hooks = true )
    {
        $this->plugin = $plugin;

        if ( $register_hooks )
        {
            $this->init();
        }
    }

    public function process_evaluation( array $payload ): void
    {
        $context = $this->normalize_context(
            $payload['context'] ?? [],
            (string) ( $payload['context']['action_id'] ?? 'evaluation' ),
        );

        $evaluation_request_id = $context['evaluation_request_id']
            ?? $this->generate_evaluation_request_id(
                [
                    'adapter_id' => $context['adapter_id'] ?? $context['form_source'] ?? '',
                    'action_id'  => $context['action_id'] ?? '',
                    'entry_id'   => $context['entry_id'] ?? '',
                    'form_id'    => $context['form_id'] ?? '',
                    'payload'    => $context['evaluation_payload'] ?? $payload['context']['evaluation_payload'] ?? [],
                ]
            );

        $result  = isset( $context['evaluation_payload'] ) && is_array( $context['evaluation_payload'] )
            ? $context['evaluation_payload']
            : [];
        $adapter = $this->resolve_async_adapter( $context['form_source'] ?? null, $context );

        if ( !$adapter )
        {
            if ( $evaluation_request_id )
            {
                $this->get_request_store()->mark_status( $evaluation_request_id, 'failed', __( 'Adapter not available', 'sentient-forms' ), 'evaluation' );
            }
            return;
        }

        try
        {
            if ( $evaluation_request_id )
            {
                $this->get_request_store()->mark_status( $evaluation_request_id, 'running', null, 'evaluation' );
            }
            $this->get_metadata_store()->update_status( $context['job_id'] ?? null, 'running' );
            $adapter->finalize_async_evaluation( $context, $result );
            do_action( 'sentient_forms_async_success', $context, $result );
            $this->emit_async_event( 'evaluation_success', $context, $result );
            $this->get_metadata_store()->update_status(
                $context['job_id'] ?? null,
                'success',
                [ 'completed_at' => time() ]
            );
            if ( $evaluation_request_id )
            {
                $this->get_request_store()->mark_status( $evaluation_request_id, 'success', null, 'evaluation' );
            }
        } catch ( Throwable $throwable )
        {
            $this->handle_evaluation_failure(
                $context,
                $result,
                new WP_Error( 'sentient_forms_async_evaluation_failed', $throwable->getMessage() ),
            );
        }
        finally
        {
            $store = $this->get_request_store();

            if ( $evaluation_request_id )
            {
                // If status stayed queued, force it to failed to avoid ledger leaks.
                $row = $store->get( $evaluation_request_id, 'evaluation' );
                if ( $row && ( $row['status'] ?? '' ) === 'queued' )
                {
                    $store->mark_status(
                        $evaluation_request_id,
                        'failed',
                        __( 'Evaluation job did not complete', 'sentient-forms' ),
                        'evaluation'
                    );
                }
            }

            $this->sweep_stale_async_rows();
        }
    }

	private function handle_success( array $job, array $result ): void
	{
		$this->log_success( $job['action_id'], $result );
		
		// Merge settings into context so adapter can access them (mark_as_spam, spam_confidence_threshold, etc.)
		$context_with_settings = array_merge(
			$job['context'] ?? [],
			$job['settings'] ?? []
		);
		
		do_action( 'sentient_forms_async_success', $context_with_settings, $result );
		$this->notify_adapter_success( $context_with_settings, $result );
		$this->emit_async_event( 'success', $job['context'], $result );
		$this->maybe_schedule_evaluation_jobs( $job, $result );
		$this->get_metadata_store()->update_status(
			$job['context']['job_id'] ?? null,
			'success',
			[ 'completed_at' => time() ]
		);
		if ( ! empty( $job['execution_request_id'] ) )
		{
			$this->get_request_store()->mark_status( $job['execution_request_id'], 'success' );
		}
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
			$delay                 = $this->compute_backoff_delay( $attempt, $context );
            $this->get_metadata_store()->update_status(
                $context['job_id'] ?? null,
                'retry_scheduled',
                [
                    'last_error' => $error->get_error_message(),
                    'run_at'     => time() + $delay,
                ]
            );
            unset( $context['job_id'] );
			$this->schedule_action(
				$job['action_id'],
				$job['data'],
				$job['settings'],
				$context,
				time() + $delay,
			);
			if ( ! empty( $job['execution_request_id'] ) )
			{
				$this->get_request_store()->mark_status( $job['execution_request_id'], 'queued', $error->get_error_message() );
			}
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
		$this->get_metadata_store()->update_status(
			$context['job_id'] ?? null,
			'failed',
			[
				'last_error'   => $error->get_error_message(),
				'completed_at' => time(),
			]
		);
		if ( ! empty( $job['execution_request_id'] ) )
		{
			$this->get_request_store()->mark_status( $job['execution_request_id'], 'failed', $error->get_error_message() );
		}
    }

    private function handle_evaluation_failure( array $context, array $result, WP_Error $error ): void
    {
        $attempt = (int) $context['attempt'];
        $max     = (int) $context['max_attempts'];
        $evaluation_request_id = $context['evaluation_request_id'] ?? null;

		if ( $attempt < $max )
		{
			$context['attempt']    = $attempt + 1;
			$context['last_error'] = $error->get_error_message();
			$delay                 = $this->compute_backoff_delay( $attempt, $context );
            $this->get_metadata_store()->update_status(
                $context['job_id'] ?? null,
                'retry_scheduled',
                [
                    'last_error' => $error->get_error_message(),
                    'run_at'     => time() + $delay,
                ]
            );
            unset( $context['job_id'] );
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
            if ( $evaluation_request_id )
            {
                $this->get_request_store()->mark_status( $evaluation_request_id, 'queued', $error->get_error_message(), 'evaluation' );
            }
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
		$this->get_metadata_store()->update_status(
			$context['job_id'] ?? null,
			'failed',
			[
				'last_error'   => $error->get_error_message(),
				'completed_at' => time(),
			]
		);
        if ( $evaluation_request_id )
        {
            $this->get_request_store()->mark_status( $evaluation_request_id, 'failed', $error->get_error_message(), 'evaluation' );
        }
    }

	private function maybe_schedule_evaluation_jobs( array $job, array $result ): void
	{
		$candidates = [];
		if ( ! empty( $job['context']['evaluation_jobs'] ) && is_array( $job['context']['evaluation_jobs'] ) )
		{
			$candidates = array_merge( $candidates, $job['context']['evaluation_jobs'] );
		}

		if ( ! empty( $result['evaluation_jobs'] ) && is_array( $result['evaluation_jobs'] ) )
		{
			$candidates = array_merge( $candidates, $result['evaluation_jobs'] );
		}
		elseif ( ! empty( $result['evaluation_payload'] ) && is_array( $result['evaluation_payload'] ) )
		{
			$candidates[] = [ 'payload' => $result['evaluation_payload'] ];
		}

		$candidates = apply_filters( 'sentient_forms_async_evaluation_jobs', $candidates, $job, $result );

		if ( empty( $candidates ) )
		{
			return;
		}

		foreach ( $candidates as $candidate )
		{
			if ( ! is_array( $candidate ) )
			{
				continue;
			}

			$payload = isset( $candidate['payload'] ) && is_array( $candidate['payload'] ) ? $candidate['payload'] : [];
			if ( empty( $payload ) )
			{
				continue;
			}

			$normalized_job = [
				'adapter_id' => $candidate['adapter_id'] ?? $job['context']['adapter_id'] ?? $job['context']['form_source'] ?? null,
				'entry_id'   => $candidate['entry_id'] ?? $job['context']['entry_id'] ?? null,
				'form_id'    => $candidate['form_id'] ?? $job['context']['form_id'] ?? null,
				'action_id'  => $candidate['action_id'] ?? $job['context']['action_id'] ?? null,
				'payload'    => $payload,
				'context'    => array_merge( $job['context'], $candidate['context'] ?? [] ),
			];

			if ( isset( $candidate['run_at'] ) )
			{
				$normalized_job['run_at'] = (int) $candidate['run_at'];
			}
			elseif ( isset( $candidate['delay'] ) )
			{
				$normalized_job['delay'] = (int) $candidate['delay'];
			}

			if ( ! $normalized_job['adapter_id'] )
			{
				continue;
			}

			$this->plugin->dispatch_action_evaluation( $normalized_job );
		}
	}

    private function notify_adapter_success( array $context, array $result ): void
    {
        $form_source = $context['form_source'] ?? null;
        $adapter = $this->resolve_async_adapter( $form_source, $context );
        
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

	private function compute_backoff_delay( int $attempt, array $context = [] ): int
	{
		$config = $this->get_retry_config();
		$base   = isset( $context['backoff_base_delay'] ) ? max( 5, (int) $context['backoff_base_delay'] ) : $config['base_delay'];
		$max    = isset( $context['backoff_max_delay'] ) ? max( $base, (int) $context['backoff_max_delay'] ) : $config['max_delay'];
		$delay  = (int) ( $base * pow( 2, max( 0, $attempt - 1 ) ) );

		return min( $max, max( $base, $delay ) );
	}

    private function enqueue_job( string $hook, array $args, int $run_at ): array
    {
        $group = $this->get_scheduler_group();

        $record_enqueued = function ( $action_id ) use ( $hook, $args, $group, $run_at ) {
            /**
             * Internal hook to observe async job scheduling (used by tests).
             *
             * @param string     $hook      Hook name.
             * @param array      $args      Hook args.
             * @param string     $group     Action Scheduler group.
             * @param int|null   $action_id Scheduler action id if available.
             * @param int        $run_at    Timestamp the job is scheduled for.
             */
            do_action( 'sentient_forms_async_job_scheduled', $hook, $args, $group, $action_id, $run_at );
        };

        if ( function_exists( 'as_schedule_single_action' ) )
        {
            $action_id = as_schedule_single_action( $run_at, $hook, $args, $group );
            if ( is_wp_error( $action_id ) )
            {
                return [ 'scheduled' => false, 'action_id' => null ];
            }

            $record_enqueued( $action_id );
            return [ 'scheduled' => (bool) $action_id, 'action_id' => is_numeric( $action_id ) ? (int) $action_id : null ];
        }

        if ( function_exists( 'as_enqueue_async_action' ) )
        {
            $action_id = as_enqueue_async_action( $hook, $args, $group );
            $record_enqueued( $action_id );
            return [ 'scheduled' => (bool) $action_id, 'action_id' => is_numeric( $action_id ) ? (int) $action_id : null ];
        }

        $scheduled = (bool) wp_schedule_single_event( $run_at, $hook, $args );
        if ( $scheduled )
        {
            $record_enqueued( null );
        }

        return [ 'scheduled' => $scheduled, 'action_id' => null ];
    }

    private function get_scheduler_group(): string
    {
        /**
         * Filter the Action Scheduler group used for Sentient Forms async jobs.
         *
         * @param string $group Group slug.
         */
        return (string) apply_filters( 'sentient_forms_async_scheduler_group', self::ACTION_SCHEDULER_GROUP );
    }

	private function get_metadata_store(): Sentient_Forms_Async_Metadata_Store
	{
		return $this->plugin->get_async_metadata_store();
	}

	private function get_request_store(): Sentient_Forms_Async_Request_Store
	{
		return $this->plugin->get_async_request_store();
	}

	private function get_retry_config(): array
	{
		if ( null === $this->retry_config )
		{
			$settings = $this->plugin->get_async_settings_service()->get_settings();
			$base     = max( 5, (int) ( $settings['base_delay_seconds'] ?? self::BASE_BACKOFF_SECONDS ) );
			$max      = max( $base, (int) ( $settings['max_delay_seconds'] ?? HOUR_IN_SECONDS ) );
			$this->retry_config = [
				'max_attempts' => max( 1, (int) ( $settings['max_attempts'] ?? self::MAX_ATTEMPTS ) ),
				'base_delay'   => $base,
				'max_delay'    => $max,
			];
		}

		return $this->retry_config;
	}

	private function normalize_context( array $context, string $action_id = '' ): array
	{
		$config         = $this->get_retry_config();
		$attempt        = isset( $context['attempt'] ) ? max( 1, (int) $context['attempt'] ) : 1;
		$max_attempts   = isset( $context['max_attempts'] ) ? max( 1, (int) $context['max_attempts'] ) : $config['max_attempts'];
		$base_delay     = isset( $context['backoff_base_delay'] ) ? max( 5, (int) $context['backoff_base_delay'] ) : $config['base_delay'];
		$max_delay      = isset( $context['backoff_max_delay'] ) ? max( $base_delay, (int) $context['backoff_max_delay'] ) : $config['max_delay'];

		$defaults = [
			'attempt'              => $attempt,
			'max_attempts'         => $max_attempts,
			'job_type'             => $context['job_type'] ?? 'execution',
			'action_id'            => $context['action_id'] ?? $action_id,
			'job_id'               => $context['job_id'] ?? wp_generate_uuid4(),
			'backoff_base_delay'   => $base_delay,
			'backoff_max_delay'    => $max_delay,
		];

        if ( !isset( $context['form_source'] ) && isset( $context['adapter_id'] ) )
        {
            $defaults['form_source'] = $context['adapter_id'];
        }

		return array_merge( $defaults, $context );
	}

	private function generate_evaluation_request_id( array $job ): string
	{
		$adapter   = $job['adapter_id'] ?? $job['context']['adapter_id'] ?? $job['context']['form_source'] ?? '';
		$entry_id  = $job['entry_id'] ?? $job['context']['entry_id'] ?? '';
		$form_id   = $job['form_id'] ?? $job['context']['form_id'] ?? '';
		$action_id = $job['action_id'] ?? $job['context']['action_id'] ?? '';
		$payload   = '';
		if ( ! empty( $job['payload'] ) && is_array( $job['payload'] ) )
		{
			$payload = wp_hash( wp_json_encode( $job['payload'] ) );
		}

		return substr( hash( 'sha256', implode( '|', [ $adapter, $action_id, $entry_id, $form_id, $payload ] ) ), 0, 40 );
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
        add_action( 'sentient_forms_process_action', [ $this, 'process_action' ], 10, 5 );
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
            $group = $this->get_scheduler_group();
            $label = apply_filters( 'sentient_forms_async_scheduler_group_label', __( 'Sentient Forms Async', 'sentient-forms' ), $group );
            as_register_group( $group, $label );
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
        // For CPS-managed 'master' actions, we don't require a local PHP action class
        // The CPS handles execution, so we can proceed without a local action
        $is_master_action = ( $settings['action_type_indicator'] ?? '' ) === 'master';
        
        // Get the action instance (optional for master actions)
        $action = $this->plugin->get_action( $action_id );
        if ( !$action && !$is_master_action )
        {
            return false;
        }

        $context['job_id'] = wp_generate_uuid4();

        $payload = [
            'action_id'            => $action_id,
            'data'                 => $this->prepare_job_data( $data ),
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

        $run_at_ts = $run_at ?? time();
        $scheduled = $this->enqueue_job(
            'sentient_forms_process_action',
            $payload,
            $run_at_ts,
        );

        if ( $scheduled['scheduled'] )
        {
            $this->get_metadata_store()->record_job(
                $payload['context']['job_id'],
                'sentient_forms_process_action',
                $payload,
                $run_at_ts,
                $scheduled['action_id'],
                $this->get_scheduler_group(),
            );
        }

        return $scheduled['scheduled'];
    }

    private function prepare_job_data( array $data ): array
    {
        $form = [];
        if ( isset( $data['form'] ) && is_array( $data['form'] ) )
        {
            if ( isset( $data['form']['id'] ) && '' !== $data['form']['id'] )
            {
                $form['id'] = (string) $data['form']['id'];
            }

            if ( isset( $data['form']['title'] ) )
            {
                $form['title'] = sanitize_text_field( (string) $data['form']['title'] );
            }
        }

        $entry = [];
        if ( isset( $data['entry'] ) && is_array( $data['entry'] ) )
        {
            foreach ( $data['entry'] as $key => $value )
            {
                if ( is_scalar( $value ) )
                {
                    $entry[ (string) $key ] = sanitize_text_field( (string) $value );
                }
            }
        }

        $payload = [
            'form'        => $form,
            'entry'       => $entry,
            'hook'        => isset( $data['hook'] ) ? sanitize_text_field( (string) $data['hook'] ) : 'gform_after_submission',
            'form_source' => isset( $data['form_source'] ) ? sanitize_key( (string) $data['form_source'] ) : 'gravity_forms',
        ];

        if ( isset( $data['source_url'] ) )
        {
            $payload['source_url'] = esc_url_raw( (string) $data['source_url'] );
        }

        if ( isset( $data['validation_result'] ) && is_array( $data['validation_result'] ) )
        {
            $payload['validation_result'] = [
                'is_valid' => (bool) ( $data['validation_result']['is_valid'] ?? true ),
                'form'     => [
                    'failed_validation'  => ! empty( $data['validation_result']['form']['failed_validation'] ),
                    'validation_message' => isset( $data['validation_result']['form']['validation_message'] )
                        ? sanitize_text_field( (string) $data['validation_result']['form']['validation_message'] )
                        : '',
                ],
            ];
        }

        return $payload;
    }

    public function dispatch_evaluation( array $job ): bool
    {
        $payload_data = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : [];
        $payload_data = $this->enrich_evaluation_payload_ids( $payload_data, $job );
        if ( empty( $payload_data ) )
        {
            return false;
        }

        $adapter_id = $job['adapter_id']
            ?? $job['context']['adapter_id']
            ?? $job['context']['form_source']
            ?? null;

        $evaluation_request_id = $job['evaluation_request_id'] ?? $this->generate_evaluation_request_id( $job );
        $request_store         = $this->get_request_store();

        if ( $request_store->should_block( $evaluation_request_id, 'evaluation' ) )
        {
            // Treat duplicates as a no-op so health dashboards stay green, but keep the event visible.
            $request_store->mark_status( $evaluation_request_id, 'skipped', __( 'Duplicate evaluation request blocked', 'sentient-forms' ), 'evaluation' );
            $this->emit_async_event(
                'evaluation_duplicate_blocked',
                array_merge(
                    $job['context'] ?? [],
                    [
                        'evaluation_request_id' => $evaluation_request_id,
                        'adapter_id'            => $job['adapter_id'] ?? $job['context']['adapter_id'] ?? $job['context']['form_source'] ?? null,
                        'action_id'             => $job['action_id'] ?? $job['context']['action_id'] ?? null,
                    ]
                ),
                [
                    'reason' => 'duplicate_blocked',
                ]
            );
            return false;
        }

        $request_store->record(
            $evaluation_request_id,
            [
                'action_id'      => $job['action_id'] ?? $job['context']['action_id'] ?? '',
                'adapter'        => $adapter_id,
                'status'         => 'queued',
                'record_type'    => 'evaluation',
                'payload_digest' => $payload_data ? wp_hash( wp_json_encode( $payload_data ) ) : null,
            ]
        );

        $job_context = array_merge(
            [
                'adapter_id'         => $adapter_id,
                'entry_id'           => $job['entry_id'] ?? $job['context']['entry_id'] ?? null,
                'form_id'            => $job['form_id'] ?? $job['context']['form_id'] ?? null,
                'action_id'          => $job['action_id'] ?? $job['context']['action_id'] ?? null,
                'job_type'           => 'evaluation',
                'evaluation_payload' => $payload_data,
                'evaluation_request_id' => $evaluation_request_id,
            ],
            $job['context'] ?? []
        );

        $job_context['job_id'] = wp_generate_uuid4();

        $payload = [
            'context' => $this->normalize_context(
                $job_context,
                (string) ( $job_context['action_id'] ?? 'evaluation' )
            ),
        ];

        $run_at_ts = $job['run_at'] ?? ( isset( $job['delay'] ) ? time() + (int) $job['delay'] : time() );
        $scheduled = $this->enqueue_job(
            'sentient_forms_evaluate_action',
            $payload,
            $run_at_ts,
        );

        if ( $scheduled['scheduled'] )
        {
            $this->get_metadata_store()->record_job(
                $payload['context']['job_id'],
                'sentient_forms_evaluate_action',
                $payload,
                $run_at_ts,
                $scheduled['action_id'],
                $this->get_scheduler_group(),
            );
        }
        else
        {
            $this->get_request_store()->mark_status(
                $evaluation_request_id,
                'failed',
                __( 'Scheduling failed', 'sentient-forms' ),
                'evaluation'
            );
        }

        return $scheduled['scheduled'];
    }

    /**
     * Ensure evaluation payload carries identifiers needed by CPS telemetry/logs.
     */
    private function enrich_evaluation_payload_ids( array $payload, array $job ): array
    {
        $entry_id  = $job['entry_id'] ?? $job['context']['entry_id'] ?? null;
        $form_id   = $job['form_id'] ?? $job['context']['form_id'] ?? null;
        $action_id = $job['action_id'] ?? $job['context']['action_id'] ?? null;
        $action_label = $job['context']['action_name_label'] ?? null;
        $form_source  = $job['context']['form_source'] ?? $job['context']['adapter_id'] ?? null;

        if ( $entry_id && empty( $payload['entry_id'] ) )
        {
            $payload['entry_id'] = (string) $entry_id;
        }

        if ( $form_id && empty( $payload['form_id'] ) )
        {
            $payload['form_id'] = (string) $form_id;
        }

        if ( $action_id && empty( $payload['action_id'] ) )
        {
            $payload['action_id'] = (string) $action_id;
        }

        if ( $action_label && empty( $payload['action_name_label'] ) )
        {
            $payload['action_name_label'] = (string) $action_label;
        }

        if ( $form_source && empty( $payload['form_source'] ) )
        {
            $payload['form_source'] = (string) $form_source;
        }

        return $payload;
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
		$this->get_metadata_store()->update_status( $context['job_id'] ?? null, 'running' );
		if ( $execution_request_id )
		{
			$this->get_request_store()->mark_status( $execution_request_id, 'running' );
		}

        $job = [
            'action_id'            => $action_id,
            'data'                 => $data,
            'settings'             => $settings,
            'execution_request_id' => $execution_request_id,
            'context'              => $context,
        ];

        try
        {
            $action = $this->plugin->get_action( $action_id );
            $is_master_action = ( $settings['action_type_indicator'] ?? '' ) === 'master';
            
            if ( !$action && !$is_master_action )
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

            $entry_id = $data['entry']['id'] ?? $context['entry_id'] ?? null;
            $form_id  = $data['form']['id'] ?? $context['form_id'] ?? null;

            // For CPS master actions without a local handler, execute via Action Executor
            if ( !$action && $is_master_action )
            {
                try
                {
                    $executor = $this->plugin->get_action_executor();
                    $result = $executor->execute(
                    $settings['central_action_id'] ?? $action_id,
                    $data['form'] ?? [],
                    $data['entry'] ?? [],
                    $context
                );
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
            }
            else
            {
                // Local action exists - execute via local handler
                try
                {
                    $result = $action->execute(
                        $data,
                        $settings,
                        $entry_id ?? 0,
                        $form_id ?? 0
                    );
                } catch ( Throwable $throwable )
                {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG )
                    {
                        error_log( sprintf( '[sentient-forms][async] execute exception action=%s entry=%s form=%s error=%s', $action_id, $entry_id ?? 'n/a', $form_id ?? 'n/a', $throwable->getMessage() ) );
                    }
                    $this->handle_failure(
                        $job,
                        new WP_Error(
                            'sentient_forms_async_exception',
                            $throwable->getMessage(),
                        ),
                    );
                    return;
                }
            }

            if ( is_wp_error( $result ) )
            {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG )
                {
                    error_log( sprintf( '[sentient-forms][async] execute wp_error action=%s entry=%s form=%s code=%s message=%s', $action_id, $entry_id ?? 'n/a', $form_id ?? 'n/a', $result->get_error_code(), $result->get_error_message() ) );
                }
                $this->handle_failure( $job, $result );
                return;
            }

            $normalized_result = is_array( $result ) ? $result : [ 'success' => (bool) $result ];
            $this->handle_success( $job, $normalized_result );
        }
        finally
        {
            $this->sweep_stale_async_rows();
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
     * Mark lingering queued evaluation/telemetry rows so async-health stays accurate.
     */
    private function sweep_stale_async_rows(): void
    {
        $store = $this->get_request_store();

        // Sweep evaluation rows that never transitioned out of queued.
        foreach ( $store->list( [ 'record_type' => 'evaluation', 'status' => 'queued', 'limit' => 50 ] ) as $queued )
        {
            if ( empty( $queued['request_hash'] ) )
            {
                continue;
            }

            $store->mark_status(
                $queued['request_hash'],
                'failed',
                __( 'Evaluation cleanup sweep (stale queued)', 'sentient-forms' ),
                'evaluation'
            );
        }

        // Telemetry rows can stick in telemetry_queued if cron does not run; mark them sent.
        foreach ( $store->list( [ 'record_type' => 'telemetry', 'status' => 'telemetry_queued', 'limit' => 50 ] ) as $queued )
        {
            if ( empty( $queued['request_hash'] ) )
            {
                continue;
            }

            $store->mark_status(
                $queued['request_hash'],
                'telemetry_sent',
                null,
                'telemetry'
            );
        }
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
