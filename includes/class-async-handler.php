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
	private const LOCAL_MAPPING_HOOK = 'sentient_forms_process_local_mapping';
    private const LOCAL_DIAGNOSTIC_SCHEMA = 'sentient_forms_local_async_diagnostic.v1';
    private const LOCAL_DIAGNOSTIC_METADATA_KEYS = [
        'action_id',
        'action_code',
        'execution_request_id',
        'adapter',
        'adapter_id',
        'form_source',
        'provider_path',
        'provider',
        'job_type',
        'attempt',
        'max_attempts',
        'status',
        'error_code',
        'warning_code',
        'reason',
        'duration_ms',
        'queue_wait_ms',
        'run_at',
    ];

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
        $evaluation_context = $this->extract_evaluation_context( $payload );
        $context            = $this->normalize_context(
            $evaluation_context,
            (string) ( $evaluation_context['action_id'] ?? 'evaluation' ),
        );
        $result             = isset( $context['evaluation_payload'] ) && is_array( $context['evaluation_payload'] )
            ? $context['evaluation_payload']
            : [];
        $evaluation_request_id = $context['evaluation_request_id']
            ?? $this->generate_evaluation_request_id(
                [
                    'adapter_id' => $context['adapter_id'] ?? $context['form_source'] ?? '',
                    'action_id'  => $context['action_id'] ?? '',
                    'entry_id'   => $context['entry_id'] ?? '',
                    'form_id'    => $context['form_id'] ?? '',
                    'payload'    => $result,
                ]
            );
        $adapter = $this->resolve_async_adapter( $context['form_source'] ?? null, $context );

        if ( !$adapter )
        {
            $this->get_metadata_store()->update_status(
                $context['job_id'] ?? null,
                'failed',
                [
                    'last_error'   => __( 'Adapter not available', 'sentient-forms' ),
                    'completed_at' => time(),
                ]
            );
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

	private function should_record_provider_execution_event( array $job ): bool
	{
		$context = isset( $job['context'] ) && is_array( $job['context'] ) ? $job['context'] : [];
		$data    = isset( $job['data'] ) && is_array( $job['data'] ) ? $job['data'] : [];

		$form_source = isset( $context['form_source'] ) && is_scalar( $context['form_source'] )
			? sanitize_key( (string) $context['form_source'] )
			: sanitize_key( (string) ( $data['form_source'] ?? '' ) );
		if ( Sentient_Forms_Form_Sources::ELEMENTOR_PRO_FORMS !== $form_source )
		{
			return false;
		}

		$job_type = isset( $context['job_type'] ) && is_scalar( $context['job_type'] )
			? sanitize_key( (string) $context['job_type'] )
			: 'execution';
		if ( in_array( $job_type, [ 'local_mapping', 'evaluation' ], true ) )
		{
			return false;
		}

		$execution_request_id = isset( $job['execution_request_id'] ) && is_scalar( $job['execution_request_id'] )
			? sanitize_text_field( (string) $job['execution_request_id'] )
			: sanitize_text_field( (string) ( $context['execution_request_id'] ?? '' ) );
		if ( '' === $execution_request_id )
		{
			return false;
		}

		$form_id = isset( $context['form_id'] ) && is_scalar( $context['form_id'] )
			? sanitize_text_field( (string) $context['form_id'] )
			: '';
		if ( '' === $form_id && isset( $data['form'] ) && is_array( $data['form'] ) && isset( $data['form']['id'] ) && is_scalar( $data['form']['id'] ) )
		{
			$form_id = sanitize_text_field( (string) $data['form']['id'] );
		}

		return '' !== $form_id;
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
                    'error_code' => sanitize_key( (string) $error->get_error_code() ),
                    'run_at'     => time() + $delay,
                ],
            );
            return;
        }

        do_action( 'sentient_forms_async_failure', $context, $error );
        $this->notify_adapter_error( $context, $error );
        $this->emit_async_event(
            'evaluation_failed',
            $context,
            [ 'error_code' => sanitize_key( (string) $error->get_error_code() ) ],
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

	private function extract_evaluation_context( array $payload ): array
	{
		if ( isset( $payload['context'] ) && is_array( $payload['context'] ) )
		{
			return $payload['context'];
		}

		if ( isset( $payload['job_id'] ) || isset( $payload['action_id'] ) || isset( $payload['evaluation_payload'] ) )
		{
			return $payload;
		}

		return [];
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

    private function normalize_provider_form_id( mixed $form_id ): string
    {
        return Sentient_Forms_Provider_Form_Id_Keys::normalize( $form_id );
    }

    /**
     * Determine whether an action id refers to spam detection.
     *
     * @param string $action_id Action id.
     *
     * @return bool
     */
    private function is_spam_action_id( string $action_id ): bool
    {
        return in_array( sanitize_key( $action_id ), [ 'spam_detection_v1', 'spam_analysis' ], true );
    }

    /**
     * Initialize the async handler
     *
     * @return void
     */
    public function init(): void
    {
        add_action( 'sentient_forms_evaluate_action', [ $this, 'process_evaluation' ], 10, 1 );
        add_action( self::LOCAL_MAPPING_HOOK, [ $this, 'process_local_mapping' ], 10, 1 );

        if ( $this->plugin->get_logger()->is_enabled() )
        {
            add_action( 'sentient_forms_async_event', [ $this, 'write_local_diagnostic_event' ], 10, 1 );
        }

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
            $label = apply_filters( 'sentient_forms_async_scheduler_group_label', __( 'Sentient Forms Background', 'sentient-forms' ), $group );
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
    /**
     * Schedule a local-first form mapping without storing raw form payloads in the queue.
     *
     * @param int                  $local_mapping_id Local custom-table mapping id.
     * @param array<string, mixed> $form             Runtime form snapshot used only for idempotency.
     * @param array<string, mixed> $entry            Runtime entry snapshot used only for idempotency.
     * @param array<string, mixed> $context          Runtime context.
     * @param int|null             $run_at           Optional Unix timestamp.
     *
     * @return bool Whether the local mapping job was scheduled.
     */
    public function schedule_local_mapping( int $local_mapping_id, array $form, array $entry, array $context = [], ?int $run_at = null ): bool
    {
        $local_mapping_id = absint( $local_mapping_id );
        if ( $local_mapping_id <= 0 )
        {
            return false;
        }

        $form_source     = sanitize_key( (string) ( $context['form_source'] ?? $context['adapter_id'] ?? 'gravity_forms' ) );
        $form_id         = sanitize_text_field( (string) ( $context['form_id'] ?? $form['id'] ?? '' ) );
        $entry_id        = sanitize_text_field( (string) ( $context['entry_id'] ?? $entry['id'] ?? '' ) );
        $submission_uuid = $this->resolve_submission_uuid(
            [
                'submission_uuid' => $context['submission_uuid'] ?? $entry['submission_uuid'] ?? null,
            ],
            $context
        );
        $entry_lookup_id = '' !== $entry_id ? $entry_id : (string) $submission_uuid;

        if ( '' === $form_source || '' === $form_id || '' === $entry_lookup_id )
        {
            return false;
        }

        $execution_request_id = isset( $context['execution_request_id'] ) && is_scalar( $context['execution_request_id'] )
            ? sanitize_text_field( (string) $context['execution_request_id'] )
            : '';
        if ( '' === $execution_request_id )
        {
            $execution_request_id = $this->generate_local_mapping_request_id( $local_mapping_id, $form_source, $form_id, $entry_lookup_id, $context );
        }

        $job_context = $this->normalize_context(
            array_merge(
                $context,
                [
                    'action_id'             => $context['action_id'] ?? sprintf( 'local_first_%d', $local_mapping_id ),
                    'central_action_id'     => $context['central_action_id'] ?? 'sentient_forms_local_custom_action',
                    'form_source'           => $form_source,
                    'form_id'               => $form_id,
                    'entry_id'              => '' !== $entry_id ? $entry_id : null,
                    'submission_uuid'       => $submission_uuid,
                    'execution_request_id'  => $execution_request_id,
                    'job_type'              => 'local_mapping',
                    'local_form_mapping_id' => $local_mapping_id,
                ],
            ),
            'sentient_forms_local_mapping',
        );

        $attempt = (int) ( $job_context['attempt'] ?? 1 );
        $rescheduling_existing_request = ! empty( $job_context['rescheduling_existing_request'] );
        if ( $attempt <= 1 && ! $rescheduling_existing_request && $this->get_request_store()->should_block( $execution_request_id ) )
        {
            return false;
        }

        $payload = [
            'local_mapping_id'      => $local_mapping_id,
            'form_source'           => $form_source,
            'form_id'               => $form_id,
            'entry_id'              => '' !== $entry_id ? $entry_id : null,
            'submission_uuid'       => $submission_uuid,
            'execution_request_id'  => $execution_request_id,
            'context'               => $job_context,
        ];
        $payload_digest = $this->local_mapping_payload_digest( $payload );

        $this->get_request_store()->record(
            $execution_request_id,
            [
                'action_id'      => 'local_mapping_' . $local_mapping_id,
                'adapter'        => $form_source,
                'status'         => 'queued',
                'payload_digest' => $payload_digest,
            ]
        );

        $this->record_local_execution_event( $payload, 'queued' );

        $run_at_ts = $run_at ?? time();
        $scheduled = $this->enqueue_job(
            self::LOCAL_MAPPING_HOOK,
            [ $payload ],
            $run_at_ts,
        );

        if ( $scheduled['scheduled'] )
        {
            $this->get_metadata_store()->record_job(
                $payload['context']['job_id'],
                self::LOCAL_MAPPING_HOOK,
                $payload,
                $run_at_ts,
                $scheduled['action_id'],
                $this->get_scheduler_group(),
            );
            return true;
        }

        $this->get_request_store()->mark_status(
            $execution_request_id,
            'failed',
            __( 'Local mapping scheduling failed.', 'sentient-forms' )
        );
        $this->record_local_execution_event(
            $payload,
            'failed',
            null,
            new WP_Error( 'sentient_forms_local_mapping_schedule_failed', __( 'Local mapping scheduling failed.', 'sentient-forms' ) )
        );

        return false;
    }

    /**
     * Process a queued local-first mapping.
     *
     * @param array<string, mixed> $payload Local mapping job payload.
     *
     * @return void
     */
    public function process_local_mapping( array $payload ): void
    {
        if ( isset( $payload[0] ) && is_array( $payload[0] ) && ! isset( $payload['local_mapping_id'] ) )
        {
            $payload = $payload[0];
        }

        $context = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : [];
        $context = $this->normalize_context( $context, 'sentient_forms_local_mapping' );
        $payload['context'] = $context;

        $execution_request_id = isset( $payload['execution_request_id'] ) && is_scalar( $payload['execution_request_id'] )
            ? sanitize_text_field( (string) $payload['execution_request_id'] )
            : sanitize_text_field( (string) ( $context['execution_request_id'] ?? '' ) );

        $job_settings = isset( $context['settings'] ) && is_array( $context['settings'] )
            ? $context['settings']
            : [];

        $job = [
            'action_id'            => 'sentient_forms_local_mapping',
            'data'                 => [
                'entry' => [ 'id' => $payload['entry_id'] ?? $context['entry_id'] ?? null ],
            ],
            'settings'             => $job_settings,
            'execution_request_id' => $execution_request_id,
            'context'              => $context,
        ];

        $dependency_gate = $this->evaluate_dependency_gate( $job );
        if ( 'skip' === $dependency_gate['state'] )
        {
            $reason = $dependency_gate['reason'] ?? __( 'Dependency failed or skipped', 'sentient-forms' );
            $this->handle_dependency_skip( $job, $reason, $dependency_gate['reason_code'] ?? null );
            $this->record_local_execution_event( $payload, 'skipped', null, new WP_Error( 'sentient_forms_local_mapping_dependency_skipped', $reason ) );
            $this->sweep_stale_async_rows();
            return;
        }

        if ( 'wait' === $dependency_gate['state'] )
        {
            $this->requeue_local_mapping_waiting_on_dependencies(
                $payload,
                (int) ( $dependency_gate['delay_seconds'] ?? 10 ),
                $dependency_gate['reason'] ?? __( 'Waiting for dependency completion', 'sentient-forms' ),
            );
            $this->sweep_stale_async_rows();
            return;
        }

        $this->get_metadata_store()->update_status( $context['job_id'] ?? null, 'running' );
        if ( '' !== $execution_request_id )
        {
            $this->get_request_store()->mark_status( $execution_request_id, 'running' );
        }
        $this->record_local_execution_event( $payload, 'running' );

        try
        {
            $resolved = $this->resolve_local_mapping_form_entry( $payload );
            if ( is_wp_error( $resolved ) )
            {
                $this->handle_local_mapping_failure( $payload, $resolved );
                return;
            }

            $local_mapping_id = absint( $payload['local_mapping_id'] ?? 0 );
            $result = ( new Sentient_Forms_Local_Action_Execution_Service() )->execute_mapping(
                $local_mapping_id,
                $resolved['form'],
                $resolved['entry'],
                $context,
            );

            if ( is_wp_error( $result ) )
            {
                $this->handle_local_mapping_failure( $payload, $result );
                return;
            }

            $this->handle_local_mapping_success( $payload, $result );
        }
        catch ( Throwable $throwable )
        {
            $this->handle_local_mapping_failure(
                $payload,
                new WP_Error( 'sentient_forms_local_mapping_exception', $throwable->getMessage() )
            );
        }
        finally
        {
            $this->sweep_stale_async_rows();
        }
    }

    /**
     * Resolve form and entry records for a queued local mapping.
     *
     * @param array<string, mixed> $payload Local mapping job payload.
     *
     * @return array{form: array<string, mixed>, entry: array<string, mixed>}|WP_Error
     */
    private function resolve_local_mapping_form_entry( array $payload ): array | WP_Error
    {
        $context         = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : [];
        $form_source     = sanitize_key( (string) ( $payload['form_source'] ?? $context['form_source'] ?? $context['adapter_id'] ?? '' ) );
        $form_id         = sanitize_text_field( (string) ( $payload['form_id'] ?? $context['form_id'] ?? '' ) );
        $entry_id        = sanitize_text_field( (string) ( $payload['entry_id'] ?? $context['entry_id'] ?? '' ) );
        $submission_uuid = $this->resolve_submission_uuid( $payload, $context );
        $entry_lookup_id = '' !== $entry_id ? $entry_id : (string) $submission_uuid;

        if ( '' === $form_source || '' === $form_id || '' === $entry_lookup_id )
        {
            return new WP_Error(
                'sentient_forms_local_mapping_missing_identifiers',
                __( 'Local mapping job is missing form or entry identifiers.', 'sentient-forms' )
            );
        }

        $registry = $this->plugin->get_form_adapter_registry();
        $adapter  = $registry ? $registry->get_adapter_by_id( $form_source ) : null;
        if ( ! $adapter )
        {
            return new WP_Error(
                'sentient_forms_local_mapping_adapter_unavailable',
                __( 'The form adapter for this local mapping is unavailable.', 'sentient-forms' )
            );
        }

        $form = null;
        if ( method_exists( $adapter, 'get_form_data' ) )
        {
            $form = $adapter->get_form_data( $form_id );
        }

        if ( ! $form && method_exists( $adapter, 'get_form_object' ) )
        {
            $form = $adapter->get_form_object( absint( $form_id ) );
        }

        if ( ! is_array( $form ) && ! is_object( $form ) )
        {
            return new WP_Error(
                'sentient_forms_local_mapping_form_not_found',
                __( 'The form for this local mapping could not be found.', 'sentient-forms' )
            );
        }

        $entry = method_exists( $adapter, 'get_entry_data' )
            ? $adapter->get_entry_data( $entry_lookup_id, $form_id )
            : null;
        if ( is_wp_error( $entry ) )
        {
            return $entry;
        }

        if ( ! is_array( $entry ) && ! is_object( $entry ) )
        {
            return new WP_Error(
                'sentient_forms_local_mapping_entry_not_found',
                __( 'The entry for this local mapping could not be found.', 'sentient-forms' )
            );
        }

        return [
            'form'  => (array) $form,
            'entry' => (array) $entry,
        ];
    }

    /**
     * Mark a queued local mapping as successful.
     *
     * @param array<string, mixed> $payload Local mapping job payload.
     * @param array<string, mixed> $result  Local execution result.
     *
     * @return void
     */
    private function handle_local_mapping_success( array $payload, array $result ): void
    {
        $context = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : [];
        $execution_request_id = sanitize_text_field(
            (string) ( $payload['execution_request_id'] ?? $context['execution_request_id'] ?? '' )
        );

        do_action( 'sentient_forms_async_success', $context, $result );
        $this->emit_async_event( 'local_mapping_success', $context, $result );

        $this->get_metadata_store()->update_status(
            $context['job_id'] ?? null,
            'success',
            [ 'completed_at' => time() ]
        );

        if ( '' !== $execution_request_id )
        {
            $this->get_request_store()->mark_status( $execution_request_id, 'success' );
        }
    }

    /**
     * Handle a failed local mapping, retrying only transient provider failures.
     *
     * @param array<string, mixed> $payload Local mapping job payload.
     * @param WP_Error             $error   Failure details.
     *
     * @return void
     */
    private function handle_local_mapping_failure( array $payload, WP_Error $error ): void
    {
        $context = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : [];
        $context = $this->normalize_context( $context, 'sentient_forms_local_mapping' );
        $attempt = (int) ( $context['attempt'] ?? 1 );
        $max     = (int) ( $context['max_attempts'] ?? self::MAX_ATTEMPTS );
        $execution_request_id = sanitize_text_field(
            (string) ( $payload['execution_request_id'] ?? $context['execution_request_id'] ?? '' )
        );

        if ( $attempt < $max && $this->is_local_mapping_retryable_error( $error ) )
        {
            $context['attempt']    = $attempt + 1;
            $context['last_error'] = $error->get_error_message();
            $delay                 = $this->compute_backoff_delay( $attempt, $context );
            $run_at                = time() + $delay;

            $this->get_metadata_store()->update_status(
                $context['job_id'] ?? null,
                'retry_scheduled',
                [
                    'last_error' => $error->get_error_message(),
                    'run_at'     => $run_at,
                ]
            );

            unset( $context['job_id'] );
            $scheduled = $this->schedule_local_mapping(
                absint( $payload['local_mapping_id'] ?? 0 ),
                [ 'id' => $payload['form_id'] ?? $context['form_id'] ?? '' ],
                [ 'id' => $payload['entry_id'] ?? $context['entry_id'] ?? '' ],
                $context,
                $run_at,
            );

            if ( $scheduled )
            {
                if ( '' !== $execution_request_id )
                {
                    $this->get_request_store()->mark_status( $execution_request_id, 'queued', $error->get_error_message() );
                }
                $this->emit_async_event(
                    'local_mapping_retry_scheduled',
                    $context,
                    [
                        'error_code' => sanitize_key( (string) $error->get_error_code() ),
                        'run_at'     => $run_at,
                    ]
                );
                return;
            }
        }

        $this->record_local_execution_event( $payload, 'failed', null, $error );
        $this->get_metadata_store()->update_status(
            $context['job_id'] ?? null,
            'failed',
            [
                'last_error'   => $error->get_error_message(),
                'completed_at' => time(),
            ]
        );

        if ( '' !== $execution_request_id )
        {
            $this->get_request_store()->mark_status( $execution_request_id, 'failed', $error->get_error_message() );
        }

        do_action( 'sentient_forms_async_failure', $context, $error );
        $this->notify_adapter_error( $context, $error );
        $this->emit_async_event(
            'local_mapping_failed',
            $context,
            [ 'error_code' => sanitize_key( (string) $error->get_error_code() ) ],
        );
    }

    private function requeue_local_mapping_waiting_on_dependencies( array $payload, int $delay_seconds, string $reason ): void
    {
        $context = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : [];
        if ( ! isset( $context['dependency_wait_started_at'] ) )
        {
            $context['dependency_wait_started_at'] = time();
        }

        $run_at = time() + max( 5, $delay_seconds );
        $this->get_metadata_store()->update_status(
            $context['job_id'] ?? null,
            'retry_scheduled',
            [
                'last_error' => $reason,
                'run_at'     => $run_at,
            ]
        );

        $execution_request_id = sanitize_text_field(
            (string) ( $payload['execution_request_id'] ?? $context['execution_request_id'] ?? '' )
        );
        if ( '' !== $execution_request_id )
        {
            $this->get_request_store()->mark_status( $execution_request_id, 'queued', $reason );
        }

        unset( $context['job_id'] );
        $context['rescheduling_existing_request'] = true;
        $scheduled = $this->schedule_local_mapping(
            absint( $payload['local_mapping_id'] ?? 0 ),
            [ 'id' => $payload['form_id'] ?? $context['form_id'] ?? '' ],
            [ 'id' => $payload['entry_id'] ?? $context['entry_id'] ?? '' ],
            $context,
            $run_at,
        );

        if ( ! $scheduled )
        {
            $this->handle_local_mapping_failure(
                $payload,
                new WP_Error( 'sentient_forms_local_mapping_dependency_wait_reschedule_failed', $reason )
            );
            return;
        }

        $this->emit_async_event(
            'local_mapping_dependency_wait',
            $context,
            [
                'reason' => $reason,
                'run_at' => $run_at,
            ],
        );
    }

    private function is_local_mapping_retryable_error( WP_Error $error ): bool
    {
        $data    = $error->get_error_data();
        $status  = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
        $code    = sanitize_key( (string) $error->get_error_code() );
        $message = strtolower( trim( $error->get_error_message() ) );

        if ( $this->is_local_mapping_permanent_provider_error( $status, $code, $message ) )
        {
            return false;
        }

        if ( 429 === $status || $status >= 500 )
        {
            return true;
        }

        return in_array(
            $code,
            [
                'http_request_failed',
                'openrouter_http_error',
                'sentient_forms_local_mapping_exception',
            ],
            true
        );
    }

    private function is_local_mapping_permanent_provider_error( int $status, string $code, string $message ): bool
    {
        if ( in_array( $status, [ 400, 401, 403, 404, 422 ], true ) )
        {
            return true;
        }

        if (
            in_array(
                $code,
                [
                    'openrouter_missing_api_key',
                    'sentient_forms_invalid_secret_constant',
                    'sentient_forms_empty_secret_constant',
                    'sentient_forms_secret_constant_not_found',
                ],
                true
            )
        )
        {
            return true;
        }

        foreach (
            [
                'missing authentication header',
                'openrouter api key is required',
                'invalid api key',
                'unauthorized',
                'forbidden',
                'authentication failed',
                'provider credential constant',
            ] as $fragment
        )
        {
            if ( str_contains( $message, $fragment ) )
            {
                return true;
            }
        }

        return false;
    }

    private function generate_local_mapping_request_id( int $local_mapping_id, string $form_source, string $form_id, string $entry_id, array $context ): string
    {
        return substr(
            hash(
                'sha256',
                wp_json_encode(
                    [
                        'local_mapping_id' => $local_mapping_id,
                        'form_source'      => $form_source,
                        'form_id'          => $form_id,
                        'entry_id'         => $entry_id,
                        'hook'             => $context['hook'] ?? 'gform_after_submission',
                    ]
                )
            ),
            0,
            40
        );
    }

    private function local_mapping_payload_digest( array $payload ): string
    {
        return hash(
            'sha256',
            wp_json_encode(
                [
                    'local_mapping_id' => absint( $payload['local_mapping_id'] ?? 0 ),
                    'form_source'      => sanitize_key( (string) ( $payload['form_source'] ?? '' ) ),
                    'form_id'          => sanitize_text_field( (string) ( $payload['form_id'] ?? '' ) ),
                    'entry_id'         => sanitize_text_field( (string) ( $payload['entry_id'] ?? '' ) ),
                    'submission_uuid'  => $this->resolve_submission_uuid(
                        $payload,
                        isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : []
                    ),
                ]
            )
        );
    }

    private function record_local_execution_event( array $payload, string $status, ?array $result = null, ?WP_Error $error = null ): void
    {
        $context = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : [];
        $execution_request_id = sanitize_text_field(
            (string) ( $payload['execution_request_id'] ?? $context['execution_request_id'] ?? '' )
        );
        if ( '' === $execution_request_id )
        {
            return;
        }

        $provider_identity = Sentient_Forms_Execution_Identity::resolve_provider_identity(
            $payload,
            $context,
            $result,
            $execution_request_id,
        );

        $event = [
            'execution_request_id' => $execution_request_id,
            'mapping_id'           => absint( $payload['local_mapping_id'] ?? $context['local_form_mapping_id'] ?? 0 ),
            'mapping_key'          => $this->resolve_event_mapping_key( $payload, $context ),
            'action_code'          => $this->resolve_event_action_code( $payload, $context ),
            'action_label'         => $this->resolve_event_action_label( $payload, $context ),
            'form_source'          => $payload['form_source'] ?? $context['form_source'] ?? 'gravity_forms',
            'form_id'              => $payload['form_id'] ?? $context['form_id'] ?? null,
            'entry_id'             => $payload['entry_id'] ?? $context['entry_id'] ?? null,
            'submission_uuid'      => $this->resolve_submission_uuid( $payload, $context ),
            'provider'             => $provider_identity['provider'],
            'model'                => $provider_identity['model'],
            'status'               => $status,
            'payload_digest'       => $this->local_mapping_payload_digest( $payload ),
        ];

        if ( is_array( $result ) )
        {
            $stored_result             = $result['result'] ?? $result;
            if ( is_array( $stored_result ) )
            {
                $stored_result = Sentient_Forms_Local_Data_Governance::sanitize_execution_result_for_storage( $stored_result );
            }
            $event['result_json']      = $stored_result;
            $event['token_usage_json'] = is_array( $result['result']['usage'] ?? null ) ? $result['result']['usage'] : null;
        }

        if ( $error )
        {
            $event['error_code']    = $error->get_error_code();
            $event['error_message'] = $error->get_error_message();
        }

        $this->get_execution_events_repository()->record( $event );
    }

    private function first_sanitized_key( array $candidates ): string
    {
        foreach ( $candidates as $candidate )
        {
            if ( ! is_scalar( $candidate ) )
            {
                continue;
            }

            $value = sanitize_key( (string) $candidate );
            if ( '' !== $value )
            {
                return $value;
            }
        }

        return '';
    }

    private function first_sanitized_text( array $candidates ): string
    {
        foreach ( $candidates as $candidate )
        {
            if ( ! is_scalar( $candidate ) )
            {
                continue;
            }

            $value = sanitize_text_field( (string) $candidate );
            if ( '' !== $value )
            {
                return $value;
            }
        }

        return '';
    }

    private function resolve_event_mapping_key( array $payload, array $context ): ?string
    {
        foreach ( [ $payload['local_mapping_id'] ?? null, $context['local_mapping_id'] ?? null, $context['mapping_id'] ?? null ] as $candidate )
        {
            if ( ! is_scalar( $candidate ) )
            {
                continue;
            }

            $mapping_key = sanitize_text_field( (string) $candidate );
            if ( '' === $mapping_key || ( ctype_digit( $mapping_key ) && absint( $mapping_key ) > 0 ) )
            {
                continue;
            }

            return $mapping_key;
        }

        return null;
    }

    private function resolve_event_action_code( array $payload, array $context ): ?string
    {
        $action_code = $this->first_sanitized_key(
            [
                $payload['central_action_id'] ?? null,
                $context['central_action_id'] ?? null,
                $payload['action_id'] ?? null,
                $context['action_id'] ?? null,
            ]
        );

        return '' !== $action_code ? $action_code : null;
    }

    private function resolve_event_action_label( array $payload, array $context ): ?string
    {
        $action_label = $this->first_sanitized_text(
            [
                $payload['action_name_label'] ?? null,
                $context['action_name_label'] ?? null,
            ]
        );

        return '' !== $action_label ? $action_label : null;
    }

    private function resolve_submission_uuid( array $payload, array $context ): ?string
    {
        foreach (
            [
                $payload['submission_uuid'] ?? null,
                $payload['entry']['submission_uuid'] ?? null,
                $payload['data']['entry']['submission_uuid'] ?? null,
                $context['submission_uuid'] ?? null,
            ] as $candidate
        )
        {
            if ( ! is_scalar( $candidate ) )
            {
                continue;
            }

            $submission_uuid = strtolower( sanitize_text_field( (string) $candidate ) );
            if ( 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $submission_uuid ) )
            {
                return $submission_uuid;
            }
        }

        return null;
    }

    private function get_execution_events_repository(): Sentient_Forms_Execution_Events_Repository
    {
        global $wpdb;

        return new Sentient_Forms_Execution_Events_Repository( $wpdb );
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
            $job['context'] ?? [],
            $job['settings'] ?? [],
            [
                'adapter_id'         => $adapter_id,
                'entry_id'           => $job['entry_id'] ?? $job['context']['entry_id'] ?? null,
                'form_id'            => $job['form_id'] ?? $job['context']['form_id'] ?? null,
                'action_id'          => $job['action_id'] ?? $job['context']['action_id'] ?? null,
                'job_type'           => 'evaluation',
                'evaluation_payload' => $payload_data,
                'evaluation_request_id' => $evaluation_request_id,
            ]
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
     * Ensure evaluation payload carries identifiers needed by local execution logs.
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
     * Determine whether this job can execute based on dependency outcomes.
     *
     * @param array<string, mixed> $job Job payload.
     *
     * @return array{state: string, reason?: string, reason_code?: string, delay_seconds?: int}
     */
    private function evaluate_dependency_gate( array $job ): array
    {
        $context = isset( $job['context'] ) && is_array( $job['context'] ) ? $job['context'] : [];
        $dependency_ids = isset( $context['dependency_mapping_ids'] ) && is_array( $context['dependency_mapping_ids'] )
            ? array_values(
                array_filter(
                    array_map(
                        static fn ( $dependency_id ): string => sanitize_text_field( (string) $dependency_id ),
                        $context['dependency_mapping_ids']
                    )
                )
            )
            : [];

        if ( empty( $dependency_ids ) )
        {
            return [ 'state' => 'pass' ];
        }

        $initial_outcomes = isset( $context['dependency_initial_outcomes'] ) && is_array( $context['dependency_initial_outcomes'] )
            ? $context['dependency_initial_outcomes']
            : [];
        $dependency_request_ids = isset( $context['dependency_execution_request_ids'] ) && is_array( $context['dependency_execution_request_ids'] )
            ? $context['dependency_execution_request_ids']
            : [];

        $pending_dependencies = [];

        foreach ( $dependency_ids as $dependency_id )
        {
            $initial = isset( $initial_outcomes[ $dependency_id ] ) ? sanitize_key( (string) $initial_outcomes[ $dependency_id ] ) : '';
            if ( in_array( $initial, [ 'failed', 'skipped' ], true ) )
            {
                return [
                    'state'  => 'skip',
                    'reason' => sprintf(
                        /* translators: 1: dependency id, 2: dependency outcome */
                        __( 'Dependency %1$s is %2$s.', 'sentient-forms' ),
                        $dependency_id,
                        $initial
                    ),
                ];
            }

            if ( in_array( $initial, [ 'succeeded', 'success' ], true ) )
            {
                continue;
            }

            $dependency_request_id = isset( $dependency_request_ids[ $dependency_id ] )
                ? sanitize_text_field( (string) $dependency_request_ids[ $dependency_id ] )
                : '';

            if ( '' === $dependency_request_id )
            {
                $pending_dependencies[] = $dependency_id;
                continue;
            }

            if (
                isset( $job['execution_request_id'] )
                && (string) $job['execution_request_id'] !== ''
                && (string) $job['execution_request_id'] === $dependency_request_id
            )
            {
                continue;
            }

            $record = $this->get_request_store()->get( $dependency_request_id, 'job' );
            if ( ! $record )
            {
                $pending_dependencies[] = $dependency_id;
                continue;
            }

            $status = sanitize_key( (string) ( $record['status'] ?? 'queued' ) );
            if ( in_array( $status, [ 'failed', 'skipped' ], true ) )
            {
                return [
                    'state'  => 'skip',
                    'reason' => sprintf(
                        /* translators: 1: dependency id, 2: dependency status */
                        __( 'Dependency %1$s is %2$s.', 'sentient-forms' ),
                        $dependency_id,
                        $status
                    ),
                ];
            }

            if ( 'success' !== $status )
            {
                $pending_dependencies[] = $dependency_id;
            }
        }

        if ( empty( $pending_dependencies ) )
        {
            $spam_gate = $this->evaluate_upstream_spam_skip( $job, $dependency_ids );
            if ( is_array( $spam_gate ) )
            {
                return $spam_gate;
            }

            return [ 'state' => 'pass' ];
        }

        $wait_started_at = isset( $context['dependency_wait_started_at'] )
            ? (int) $context['dependency_wait_started_at']
            : time();
        $wait_max_seconds = max( 30, (int) ( $context['dependency_wait_max_seconds'] ?? 600 ) );

        if ( ( time() - $wait_started_at ) >= $wait_max_seconds )
        {
            return [
                'state'  => 'skip',
                'reason' => sprintf(
                    /* translators: %s: comma-separated dependency ids */
                    __( 'Timed out waiting for dependencies: %s.', 'sentient-forms' ),
                    implode( ', ', $pending_dependencies )
                ),
            ];
        }

        return [
            'state'         => 'wait',
            'delay_seconds' => max( 5, (int) ( $context['dependency_wait_poll_seconds'] ?? 10 ) ),
            'reason'        => sprintf(
                /* translators: %s: comma-separated dependency ids */
                __( 'Waiting for dependencies: %s.', 'sentient-forms' ),
                implode( ', ', $pending_dependencies )
            ),
        ];
    }

    /**
     * Mark a job as skipped due to dependency outcome.
     *
     * @param array<string, mixed> $job         Job payload.
     * @param string               $reason      Skip reason.
     * @param string|null          $reason_code Skip reason code.
     *
     * @return void
     */
    private function handle_dependency_skip( array $job, string $reason, ?string $reason_code = null ): void
    {
        $context = isset( $job['context'] ) && is_array( $job['context'] ) ? $job['context'] : [];
        $already_recorded = false;

        if ( ! empty( $job['execution_request_id'] ) )
        {
            $existing = $this->get_request_store()->get( (string) $job['execution_request_id'], 'job' );
            $already_recorded = $existing
                && 'skipped' === ( $existing['status'] ?? null )
                && $reason === ( $existing['last_error'] ?? null );
        }

        $this->get_metadata_store()->update_status(
            $context['job_id'] ?? null,
            'skipped',
            [
                'last_error'   => $reason,
                'completed_at' => time(),
            ]
        );

        if ( ! empty( $job['execution_request_id'] ) )
        {
            $this->get_request_store()->mark_status( (string) $job['execution_request_id'], 'skipped', $reason );
        }

        if ( $this->should_record_provider_execution_event( $job ) )
        {
            $this->record_local_execution_event(
                $job,
                'skipped',
                null,
                new WP_Error( 'sentient_forms_local_mapping_dependency_skipped', $reason )
            );
        }

        if ( 'upstream_spam' === $reason_code && ! $already_recorded )
        {
            $this->maybe_add_dependency_skip_note( $job, $reason );
        }

        $this->emit_async_event(
            'skipped',
            $context,
            [ 'reason' => $reason ]
        );
    }

    /**
     * Evaluate whether a dependent mapping should be skipped because its upstream
     * spam check classified the entry as spam.
     *
     * @param array<string, mixed> $job            Job payload.
     * @param array<int, string>   $dependency_ids Dependency ids for this job.
     *
     * @return array<string, string>|null
     */
    private function evaluate_upstream_spam_skip( array $job, array $dependency_ids ): ?array
    {
        if ( 1 !== count( $dependency_ids ) )
        {
            return null;
        }

        $dependency_id = is_scalar( $dependency_ids[0] ?? null )
            ? sanitize_text_field( (string) $dependency_ids[0] )
            : '';
        if ( '' === $dependency_id )
        {
            return null;
        }

        if ( ! $this->should_skip_on_upstream_spam( $job )
            && ! $this->upstream_mapping_skips_downstream_on_spam( $job, $dependency_id ) )
        {
            return null;
        }

        $classification = $this->get_upstream_spam_classification( $job, $dependency_id );
        if ( null === $classification || ! in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
        {
            return null;
        }

        return [
            'state'       => 'skip',
            'reason_code' => 'upstream_spam',
            'reason'      => sprintf(
                /* translators: %s: spam classification */
                __( 'Upstream spam check classified this entry as %s.', 'sentient-forms' ),
                str_replace( '_', ' ', $classification )
            ),
        ];
    }

    /**
     * Determine whether a job opted into skip_on_upstream_spam.
     *
     * @param array<string, mixed> $job Job payload.
     *
     * @return bool
     */
    private function should_skip_on_upstream_spam( array $job ): bool
    {
        $settings = isset( $job['settings'] ) && is_array( $job['settings'] ) ? $job['settings'] : [];

        if ( isset( $settings['settings'] ) && is_array( $settings['settings'] ) && array_key_exists( 'skip_on_upstream_spam', $settings['settings'] ) )
        {
            return rest_sanitize_boolean( $settings['settings']['skip_on_upstream_spam'] );
        }

        if ( array_key_exists( 'skip_on_upstream_spam', $settings ) )
        {
            return rest_sanitize_boolean( $settings['skip_on_upstream_spam'] );
        }

        return false;
    }

    /**
     * Determine whether the upstream spam mapping opts into skipping downstream work.
     *
     * @param array<string, mixed> $job           Job payload.
     * @param string               $dependency_id Upstream mapping id.
     *
     * @return bool
     */
    private function upstream_mapping_skips_downstream_on_spam( array $job, string $dependency_id ): bool
    {
        $context     = isset( $job['context'] ) && is_array( $job['context'] ) ? $job['context'] : [];
        $form_source = sanitize_key( (string) ( $context['form_source'] ?? $context['adapter_id'] ?? 'gravity_forms' ) );
        $form_id     = $this->normalize_provider_form_id( $context['form_id'] ?? '' );

        if ( '' === $form_source || '' === $form_id )
        {
            return false;
        }

        $dependency_mapping = $this->resolve_local_first_dependency_mapping( $form_source, $form_id, $dependency_id );

        if ( ! is_array( $dependency_mapping ) )
        {
            return false;
        }

        $dependency_action_id = isset( $dependency_mapping['central_action_id'] ) && is_scalar( $dependency_mapping['central_action_id'] )
            ? sanitize_key( (string) $dependency_mapping['central_action_id'] )
            : '';
        if ( ! $this->is_spam_action_id( $dependency_action_id ) )
        {
            return false;
        }

        $settings = isset( $dependency_mapping['settings'] ) && is_array( $dependency_mapping['settings'] )
            ? $dependency_mapping['settings']
            : [];

        if ( array_key_exists( 'skip_downstream_on_spam', $settings ) )
        {
            return rest_sanitize_boolean( $settings['skip_downstream_on_spam'] );
        }

        return true;
    }

    /**
     * Resolve an upstream local-first dependency row for spam skip policy checks.
     *
     * @param string $form_source   Current form source.
     * @param string $form_id       Current provider-native form id.
     * @param string $dependency_id Upstream mapping id.
     *
     * @return array<string, mixed>|null
     */
    private function resolve_local_first_dependency_mapping( string $form_source, string $form_id, string $dependency_id ): ?array
    {
        if ( ! str_starts_with( $dependency_id, 'local_first_' ) || ! class_exists( 'Sentient_Forms_Form_Mappings_Repository' ) )
        {
            return null;
        }

        $mapping_id = absint( substr( $dependency_id, strlen( 'local_first_' ) ) );
        if ( $mapping_id <= 0 )
        {
            return null;
        }

        global $wpdb;
        $repository = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $row        = $repository->get( $mapping_id );
        if ( ! is_array( $row ) || empty( $row['enabled'] ) )
        {
            return null;
        }

        $row_form_source = sanitize_key( (string) ( $row['form_source'] ?? '' ) );
        $row_form_id     = $this->normalize_provider_form_id( $row['form_id'] ?? '' );
        if ( $row_form_source !== $form_source || $row_form_id !== $this->normalize_provider_form_id( $form_id ) )
        {
            return null;
        }

        $action_code = $this->resolve_local_first_dependency_action_code( $row );
        if ( '' === $action_code )
        {
            return null;
        }

        $settings = isset( $row['settings_json'] ) && is_array( $row['settings_json'] )
            ? $row['settings_json']
            : [];

        $effect_mapping = isset( $row['effect_mapping_json'] ) && is_array( $row['effect_mapping_json'] )
            ? $row['effect_mapping_json']
            : [];
        if (
            ! array_key_exists( 'skip_downstream_on_spam', $settings )
            && isset( $effect_mapping['spam'] )
            && is_array( $effect_mapping['spam'] )
            && array_key_exists( 'skip_downstream_on_spam', $effect_mapping['spam'] )
        )
        {
            $settings['skip_downstream_on_spam'] = rest_sanitize_boolean( $effect_mapping['spam']['skip_downstream_on_spam'] );
        }

        return [
            'central_action_id' => $action_code,
            'settings'          => $settings,
        ];
    }

    /**
     * Resolve the action code represented by a local-first mapping row.
     *
     * @param array<string, mixed> $mapping Local-first mapping row.
     *
     * @return string
     */
    private function resolve_local_first_dependency_action_code( array $mapping ): string
    {
        if ( 'custom_action' !== sanitize_key( (string) ( $mapping['action_kind'] ?? '' ) ) || ! class_exists( 'Sentient_Forms_Local_Custom_Actions_Repository' ) )
        {
            return '';
        }

        $action_id = absint( $mapping['action_id'] ?? 0 );
        if ( $action_id <= 0 )
        {
            return '';
        }

        global $wpdb;
        $repository = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $action     = $repository->get( $action_id );
        if ( ! is_array( $action ) )
        {
            return '';
        }

        $action_code = isset( $action['code'] ) && is_scalar( $action['code'] )
            ? sanitize_key( (string) $action['code'] )
            : '';
        if ( '' === $action_code )
        {
            return '';
        }

        $template_code = class_exists( 'Sentient_Forms_Bundled_Action_Templates' )
            ? Sentient_Forms_Bundled_Action_Templates::extract_template_code_from_custom_action_code( $action_code )
            : '';

        return '' !== $template_code ? $template_code : $action_code;
    }

    /**
     * Resolve the stored upstream spam classification for a dependent job.
     *
     * @param array<string, mixed> $job Job payload.
     *
     * @return string|null
     */
    private function get_upstream_spam_classification( array $job, ?string $dependency_id = null ): ?string
    {
        $context      = isset( $job['context'] ) && is_array( $job['context'] ) ? $job['context'] : [];
        $event_result = $this->get_upstream_spam_classification_from_event( $context, $dependency_id );
        if ( null !== $event_result )
        {
            return $event_result;
        }

        $entry_id = isset( $job['data']['entry']['id'] ) ? (int) $job['data']['entry']['id'] : (int) ( $context['entry_id'] ?? 0 );

        if ( $entry_id <= 0 )
        {
            return null;
        }

        $classification = null;
        $adapter = $this->resolve_async_adapter( $context['form_source'] ?? null, $context );
        if ( $adapter && method_exists( $adapter, 'get_entry_meta' ) )
        {
            $classification = $adapter->get_entry_meta( $entry_id, 'spam_classification' );
        }

        $adapter_id = sanitize_key( (string) ( $context['form_source'] ?? $context['adapter_id'] ?? '' ) );
        if ( null === $classification && 'gravity_forms' === $adapter_id && function_exists( 'gform_get_meta' ) )
        {
            $classification = gform_get_meta( $entry_id, 'sentient_forms_spam_classification' );
        }

        if ( ! is_scalar( $classification ) )
        {
            return null;
        }

        $normalized = sanitize_key( (string) $classification );
        return '' === $normalized ? null : $normalized;
    }

    private function extract_spam_classification_from_result( array $result ): ?string
    {
        $candidates = [
            $result['structured']['classification'] ?? null,
            $result['result']['structured']['classification'] ?? null,
            $result['result_data']['structured_output']['classification'] ?? null,
            $result['evaluation_payload']['result_data']['structured_output']['classification'] ?? null,
            $result['result_data']['classification'] ?? null,
            $result['evaluation_payload']['result_data']['classification'] ?? null,
            $result['classification'] ?? null,
            $result['result']['classification'] ?? null,
        ];

        foreach ( $candidates as $candidate )
        {
            if ( is_scalar( $candidate ) )
            {
                $classification = $this->normalize_spam_classification_value( $candidate );
                if ( '' !== $classification )
                {
                    return $classification;
                }
            }
        }

        $is_spam_candidates = [
            $result['structured']['is_spam'] ?? null,
            $result['result']['structured']['is_spam'] ?? null,
            $result['result_data']['structured_output']['is_spam'] ?? null,
            $result['evaluation_payload']['result_data']['structured_output']['is_spam'] ?? null,
            $result['result_data']['is_spam'] ?? null,
            $result['evaluation_payload']['result_data']['is_spam'] ?? null,
            $result['is_spam'] ?? null,
            $result['result']['is_spam'] ?? null,
        ];

        foreach ( $is_spam_candidates as $is_spam )
        {
            if ( true === $is_spam )
            {
                return 'spam';
            }

            if ( is_scalar( $is_spam ) && in_array( strtolower( trim( (string) $is_spam ) ), [ '1', 'true', 'yes', 'on' ], true ) )
            {
                return 'spam';
            }
        }

        return null;
    }

    private function normalize_spam_classification_value( mixed $candidate ): string
    {
        if ( ! is_scalar( $candidate ) )
        {
            return '';
        }

        $normalized = preg_replace( '/[\s-]+/', '_', strtolower( trim( (string) $candidate ) ) );

        return sanitize_key( (string) ( $normalized ?? '' ) );
    }

    /**
     * Resolve an upstream spam classification from the persisted execution event.
     *
     * @param array<string, mixed> $context       Current job context.
     * @param string|null          $dependency_id Upstream mapping id.
     *
     * @return string|null
     */
    private function get_upstream_spam_classification_from_event( array $context, ?string $dependency_id ): ?string
    {
        $dependency_id = is_scalar( $dependency_id ) ? sanitize_text_field( (string) $dependency_id ) : '';
        if ( '' === $dependency_id )
        {
            return null;
        }

        $dependency_request_ids = isset( $context['dependency_execution_request_ids'] ) && is_array( $context['dependency_execution_request_ids'] )
            ? $context['dependency_execution_request_ids']
            : [];
        $execution_request_id = isset( $dependency_request_ids[ $dependency_id ] ) && is_scalar( $dependency_request_ids[ $dependency_id ] )
            ? sanitize_text_field( (string) $dependency_request_ids[ $dependency_id ] )
            : '';
        if ( '' === $execution_request_id )
        {
            return null;
        }

        $event = $this->get_execution_events_repository()->get_by_request_id( $execution_request_id );
        if ( ! is_array( $event ) || ! $this->execution_event_matches_context( $event, $context ) )
        {
            return null;
        }

        $result = isset( $event['result_json'] ) && is_array( $event['result_json'] )
            ? $event['result_json']
            : [];
        if ( [] === $result )
        {
            return null;
        }

        return $this->extract_spam_classification_from_result( $result );
    }

    /**
     * Prevent a stale event with the same request id from influencing another form/submission.
     *
     * @param array<string, mixed> $event   Persisted execution event.
     * @param array<string, mixed> $context Current job context.
     *
     * @return bool
     */
    private function execution_event_matches_context( array $event, array $context ): bool
    {
        foreach ( [ 'form_source', 'form_id', 'submission_uuid' ] as $field )
        {
            $expected = isset( $context[ $field ] ) && is_scalar( $context[ $field ] )
                ? sanitize_text_field( (string) $context[ $field ] )
                : '';
            $actual = isset( $event[ $field ] ) && is_scalar( $event[ $field ] )
                ? sanitize_text_field( (string) $event[ $field ] )
                : '';

            if ( '' !== $expected && '' !== $actual && $expected !== $actual )
            {
                return false;
            }
        }

        return true;
    }

    /**
     * Add a concise note when a dependent action is skipped due to upstream spam.
     *
     * @param array<string, mixed> $job    Job payload.
     * @param string               $reason Skip reason.
     *
     * @return void
     */
    private function maybe_add_dependency_skip_note( array $job, string $reason ): void
    {
        $context  = isset( $job['context'] ) && is_array( $job['context'] ) ? $job['context'] : [];
        $entry_id = isset( $job['data']['entry']['id'] ) ? (int) $job['data']['entry']['id'] : (int) ( $context['entry_id'] ?? 0 );

        if ( $entry_id <= 0 )
        {
            return;
        }

        $adapter = $this->resolve_async_adapter( $context['form_source'] ?? null, $context );
        if ( ! $adapter || ! method_exists( $adapter, 'add_entry_note' ) )
        {
            return;
        }

        $settings = isset( $job['settings'] ) && is_array( $job['settings'] ) ? $job['settings'] : [];
        $action_name = $context['action_name_label'] ?? $settings['action_name_label'] ?? $settings['central_action_id'] ?? $job['action_id'] ?? __( 'this action', 'sentient-forms' );
        $action_name = sanitize_text_field( (string) $action_name );
        if ( '' === $action_name )
        {
            $action_name = __( 'this action', 'sentient-forms' );
        }

        $normalized_reason = trim( $reason );
        $normalized_reason = rtrim( $normalized_reason, ". \t\n\r\0\x0B" );
        if ( '' !== $normalized_reason )
        {
            $normalized_reason = strtolower( substr( $normalized_reason, 0, 1 ) ) . substr( $normalized_reason, 1 );
        }

        $note = sprintf(
            /* translators: 1: action name, 2: skip reason */
            __( 'Skipped %1$s because %2$s.', 'sentient-forms' ),
            $action_name,
            $normalized_reason
        );

        $adapter->add_entry_note( $entry_id, 'Sentient Forms AI', $note );
    }

    /**
     * Emit a consent-aware async event for local site logging.
     *
     * @param string $event   Event name (success, failed, retry_scheduled, etc.).
     * @param array  $context Job context metadata.
     * @param array  $payload Result/error payload.
     */
    private function emit_async_event( string $event, array $context = [], array $payload = [] ): void
    {
        $telemetry    = $this->plugin->get_telemetry_settings();
        $telemetry_on = ! empty( $telemetry['telemetry_opt_in'] );

        if ( ! $telemetry_on )
        {
            return;
        }

        $metadata = $this->local_diagnostic_metadata( $context, $payload );

        /**
         * Fires whenever the async handler emits a metadata-only local diagnostic event.
         *
         * @param array<string, mixed> $event_payload Allowlisted local event data (`event`, `metadata`, `timestamp`).
         */
        do_action(
            'sentient_forms_async_event',
            [
                'event'     => $event,
                'metadata'  => $metadata,
                'timestamp' => time(),
            ]
        );
    }

    /**
     * Persist an already-consented local diagnostic event through the masked logger.
     *
     * The WordPress hook is public, so this listener independently rebuilds the
     * allowlisted envelope instead of trusting another callback's payload.
     *
     * @param mixed $event_payload Candidate event payload.
     */
    public function write_local_diagnostic_event( mixed $event_payload ): void
    {
        $telemetry = $this->plugin->get_telemetry_settings();
        if ( empty( $telemetry['telemetry_opt_in'] ) )
        {
            return;
        }

        $logger = $this->plugin->get_logger();
        if ( ! $logger->is_enabled() || ! is_array( $event_payload ) )
        {
            return;
        }

        $event = isset( $event_payload['event'] ) && is_scalar( $event_payload['event'] )
            ? sanitize_key( (string) $event_payload['event'] )
            : '';
        if ( '' === $event )
        {
            return;
        }

        $candidate_metadata = isset( $event_payload['metadata'] ) && is_array( $event_payload['metadata'] )
            ? $event_payload['metadata']
            : [];
        $logger->info(
            'Sentient Forms async diagnostic event.',
            [
                'event'    => $event,
                'metadata' => $this->local_diagnostic_metadata( [], $candidate_metadata ),
            ]
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $payload
     *
     * @return array<string, string|int|float|bool|null>
     */
    private function local_diagnostic_metadata( array $context, array $payload ): array
    {
        $candidate = array_merge( $context, $payload );
        if ( empty( $candidate['provider_path'] ) && ! empty( $candidate['provider'] ) )
        {
            $candidate['provider_path'] = $candidate['provider'];
        }

        $metadata = [
            'schema_version' => self::LOCAL_DIAGNOSTIC_SCHEMA,
            'plugin_version' => defined( 'SENTIENT_FORMS_VERSION' ) ? SENTIENT_FORMS_VERSION : 'unknown',
        ];
        $stable_code_keys = [
            'action_id',
            'action_code',
            'adapter',
            'adapter_id',
            'form_source',
            'provider_path',
            'provider',
            'job_type',
            'status',
            'error_code',
            'warning_code',
            'reason',
        ];
        foreach ( self::LOCAL_DIAGNOSTIC_METADATA_KEYS as $key )
        {
            if ( ! array_key_exists( $key, $candidate ) )
            {
                continue;
            }

            $value = $candidate[ $key ];
            if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) )
            {
                $metadata[ $key ] = $value;
                continue;
            }
            if ( ! is_scalar( $value ) )
            {
                continue;
            }

            $value = substr( sanitize_text_field( (string) $value ), 0, 191 );
            if ( in_array( $key, $stable_code_keys, true )
                && ! preg_match( '/^[a-z][a-z0-9_-]{1,63}$/D', $value ) )
            {
                continue;
            }
            if ( '' !== $value )
            {
                $metadata[ $key ] = $value;
            }
        }

        unset( $metadata['provider'] );

        return $metadata;
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

        // Remote telemetry is retired; preserve legacy queue rows as cancelled audit history.
        foreach ( $store->list( [ 'record_type' => 'telemetry', 'status' => 'telemetry_queued', 'limit' => 50 ] ) as $queued )
        {
            if ( empty( $queued['request_hash'] ) )
            {
                continue;
            }

            $store->mark_status(
                $queued['request_hash'],
                'telemetry_retired',
                __( 'Remote telemetry transport is retired; queued event cancelled locally.', 'sentient-forms' ),
                'telemetry'
            );
        }
    }

}
