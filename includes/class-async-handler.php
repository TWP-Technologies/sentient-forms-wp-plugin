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
	private const FORM_ACTION_CONFIG_OPTION_PREFIX = 'sentient_forms_form_config_';
	private const ACTION_DEFAULTS_OPTION_PREFIX = 'sentient_forms_action_defaults_';

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

    /**
     * Resolve hierarchical settings for CPS-managed actions.
     *
     * Implements the waterfall resolution pattern:
     * 1. Mapping-level settings (highest priority)
     * 2. Form-level settings from wp_options
     * 3. Action-level defaults from wp_options
     *
     * @param array $settings Mapping-level settings.
     * @param array $context  Job context with form_source and form_id.
     *
     * @return array Resolved settings with inherited values merged in.
     */
    private function resolve_hierarchical_settings( array $settings, array $context ): array
    {
        $action_id = isset( $settings['central_action_id'] ) && is_scalar( $settings['central_action_id'] )
            ? sanitize_key( (string) $settings['central_action_id'] )
            : sanitize_key( (string) ( $context['action_id'] ?? '' ) );
        if ( '' === $action_id )
        {
            return $settings;
        }

        $form_source = sanitize_key( (string) ( $context['form_source'] ?? $context['adapter_id'] ?? 'gravity_forms' ) );
        $form_id     = absint( $context['form_id'] ?? 0 );
        $resolved    = $settings;

        $action_defaults = $this->get_action_defaults_config( $action_id );
        $form_config     = $form_id > 0
            ? $this->get_form_action_config( $form_source, $form_id, $action_id )
            : [];

        foreach ( [ 'model_selection', 'include_site_context', 'action_customization' ] as $field )
        {
            $resolved = $this->merge_inherited_field( $resolved, $field, $form_config, $action_defaults );
        }

        if ( $this->is_spam_action_id( $action_id ) )
        {
            foreach ( [ 'spam_positive_examples', 'spam_negative_examples' ] as $field )
            {
                $resolved = $this->merge_inherited_field( $resolved, $field, $form_config, $action_defaults );
            }

            foreach ( [ 'suppress_notifications_on_spam', 'suppress_webhooks_on_spam', 'skip_downstream_on_spam' ] as $field )
            {
                $resolved = $this->merge_inherited_boolean_field( $resolved, $field, $form_config, $action_defaults );
            }
        }

        return $resolved;
    }

    /**
     * Load and normalize global action defaults for a specific action.
     *
     * @param string $action_id Action id.
     *
     * @return array<string, mixed>
     */
    private function get_action_defaults_config( string $action_id ): array
    {
        $config = get_option( self::ACTION_DEFAULTS_OPTION_PREFIX . sanitize_key( $action_id ), [] );

        return $this->normalize_action_config_payload( $config );
    }

    /**
     * Load and normalize form-level action config for a specific action.
     *
     * @param string $form_source Form source id.
     * @param int    $form_id     Form id.
     * @param string $action_id   Action id.
     *
     * @return array<string, mixed>
     */
    private function get_form_action_config( string $form_source, int $form_id, string $action_id ): array
    {
        $configs = get_option(
            self::FORM_ACTION_CONFIG_OPTION_PREFIX . sanitize_key( $form_source ) . '_' . $form_id,
            []
        );

        if ( ! is_array( $configs ) )
        {
            return [];
        }

        return $this->normalize_action_config_payload( $configs[ $action_id ] ?? [] );
    }

    /**
     * Normalize persisted action config payloads for runtime use.
     *
     * @param mixed $config Raw config value.
     *
     * @return array<string, mixed>
     */
    private function normalize_action_config_payload( $config ): array
    {
        if ( ! is_array( $config ) )
        {
            return [];
        }

        if ( empty( $config['model_selection'] ) && ! empty( $config['model_override'] ) && is_string( $config['model_override'] ) )
        {
            $config['model_selection'] = [
                'primary'   => sanitize_text_field( $config['model_override'] ),
                'backup'    => null,
                'is_preset' => str_starts_with( (string) $config['model_override'], 'sf_' ),
            ];
        }

        foreach ( [ 'suppress_notifications_on_spam', 'suppress_webhooks_on_spam', 'skip_downstream_on_spam' ] as $field )
        {
            if ( array_key_exists( $field, $config ) )
            {
                $config[ $field ] = rest_sanitize_boolean( $config[ $field ] );
            }
        }

        return $config;
    }

    /**
     * Merge a generic inheritable field into resolved settings when mapping scope does not define it.
     *
     * @param array<string, mixed> $resolved        Current resolved settings.
     * @param string               $field           Field name.
     * @param array<string, mixed> $form_config     Form-level config.
     * @param array<string, mixed> $action_defaults Action-level defaults.
     *
     * @return array<string, mixed>
     */
    private function merge_inherited_field( array $resolved, string $field, array $form_config, array $action_defaults ): array
    {
        if ( $this->has_inherited_value( $resolved, $field ) )
        {
            return $resolved;
        }

        if ( $this->has_inherited_value( $form_config, $field ) )
        {
            $resolved[ $field ] = $form_config[ $field ];
            return $resolved;
        }

        if ( $this->has_inherited_value( $action_defaults, $field ) )
        {
            $resolved[ $field ] = $action_defaults[ $field ];
        }

        return $resolved;
    }

    /**
     * Merge an inheritable boolean field into resolved settings when mapping scope does not define it.
     *
     * @param array<string, mixed> $resolved        Current resolved settings.
     * @param string               $field           Field name.
     * @param array<string, mixed> $form_config     Form-level config.
     * @param array<string, mixed> $action_defaults Action-level defaults.
     *
     * @return array<string, mixed>
     */
    private function merge_inherited_boolean_field( array $resolved, string $field, array $form_config, array $action_defaults ): array
    {
        if ( array_key_exists( $field, $resolved ) )
        {
            $resolved[ $field ] = rest_sanitize_boolean( $resolved[ $field ] );
            return $resolved;
        }

        if ( array_key_exists( $field, $form_config ) )
        {
            $resolved[ $field ] = rest_sanitize_boolean( $form_config[ $field ] );
            return $resolved;
        }

        if ( array_key_exists( $field, $action_defaults ) )
        {
            $resolved[ $field ] = rest_sanitize_boolean( $action_defaults[ $field ] );
        }

        return $resolved;
    }

    /**
     * Determine whether a field contains a meaningful inherited value.
     *
     * @param array<string, mixed> $settings Settings array.
     * @param string               $field    Field name.
     *
     * @return bool
     */
    private function has_inherited_value( array $settings, string $field ): bool
    {
        if ( ! array_key_exists( $field, $settings ) )
        {
            return false;
        }

        $value = $settings[ $field ];

        if ( is_array( $value ) )
        {
            return ! empty( $value );
        }

        if ( is_string( $value ) )
        {
            return '' !== trim( $value );
        }

        return null !== $value;
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
        // Register the action hook for processing actions
        add_action( 'sentient_forms_process_action', [ $this, 'process_action' ], 10, 5 );
        add_action( 'sentient_forms_evaluate_action', [ $this, 'process_evaluation' ], 10, 1 );
        add_action( self::LOCAL_MAPPING_HOOK, [ $this, 'process_local_mapping' ], 10, 1 );

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
    public function schedule_action( string $action_id, array $data, array $settings, array $context = [], ?int $run_at = null ): bool
    {
        // For CPS-managed actions, we don't require a local PHP action class.
        // CPS-backed master and custom actions execute remotely, so scheduling can proceed.
        $action_type_indicator = (string) ( $settings['action_type_indicator'] ?? '' );
        $is_cps_managed_action = in_array( $action_type_indicator, [ 'master', 'custom' ], true );
        
        // Get the action instance (optional for CPS-managed actions)
        $action = $this->plugin->get_action( $action_id );
        if ( !$action && !$is_cps_managed_action )
        {
            return false;
        }

        $context['job_id'] = wp_generate_uuid4();

        // Enforce runtime shape so stale local state cannot smuggle pricing hints.
        $settings = $this->normalize_runtime_settings( $settings );

        // CB-EXEC-003/004: Batch delay scheduling (pricing remains CPS-authoritative).
        $batch_settings = $settings['batch_settings'] ?? null;
        $batch_enabled  = ! empty( $batch_settings['enabled'] )
            && ( ( $data['hook'] ?? ( $context['hook'] ?? '' ) ) === 'gform_after_submission' );

        if ( $batch_enabled )
        {
            $delay    = max( 10, (int) ( $batch_settings['delay_seconds'] ?? 60 ) );
            $run_at   = $run_at ?? ( time() + $delay );

            $context['batch_context'] = [
                'batch_id' => wp_generate_uuid4(),
                'delay'    => $delay,
            ];
        }

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

        $form_source = sanitize_key( (string) ( $context['form_source'] ?? $context['adapter_id'] ?? 'gravity_forms' ) );
        $form_id     = sanitize_text_field( (string) ( $context['form_id'] ?? $form['id'] ?? '' ) );
        $entry_id    = sanitize_text_field( (string) ( $context['entry_id'] ?? $entry['id'] ?? '' ) );

        if ( '' === $form_source || '' === $form_id || '' === $entry_id )
        {
            return false;
        }

        $execution_request_id = isset( $context['execution_request_id'] ) && is_scalar( $context['execution_request_id'] )
            ? sanitize_text_field( (string) $context['execution_request_id'] )
            : '';
        if ( '' === $execution_request_id )
        {
            $execution_request_id = $this->generate_local_mapping_request_id( $local_mapping_id, $form_source, $form_id, $entry_id, $context );
        }

        $job_context = $this->normalize_context(
            array_merge(
                $context,
                [
                    'action_id'             => $context['action_id'] ?? sprintf( 'local_first_%d', $local_mapping_id ),
                    'central_action_id'     => $context['central_action_id'] ?? 'sentient_forms_local_custom_action',
                    'form_source'           => $form_source,
                    'form_id'               => $form_id,
                    'entry_id'              => $entry_id,
                    'execution_request_id'  => $execution_request_id,
                    'job_type'              => 'local_mapping',
                    'local_form_mapping_id' => $local_mapping_id,
                ],
            ),
            'sentient_forms_local_mapping',
        );

        $attempt = (int) ( $job_context['attempt'] ?? 1 );
        if ( $attempt <= 1 && $this->get_request_store()->should_block( $execution_request_id ) )
        {
            return false;
        }

        $payload = [
            'local_mapping_id'      => $local_mapping_id,
            'form_source'           => $form_source,
            'form_id'               => $form_id,
            'entry_id'              => $entry_id,
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

        $job = [
            'action_id'            => 'sentient_forms_local_mapping',
            'data'                 => [
                'entry' => [ 'id' => $payload['entry_id'] ?? $context['entry_id'] ?? null ],
            ],
            'settings'             => [],
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
        $context     = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : [];
        $form_source = sanitize_key( (string) ( $payload['form_source'] ?? $context['form_source'] ?? $context['adapter_id'] ?? '' ) );
        $form_id     = sanitize_text_field( (string) ( $payload['form_id'] ?? $context['form_id'] ?? '' ) );
        $entry_id    = sanitize_text_field( (string) ( $payload['entry_id'] ?? $context['entry_id'] ?? '' ) );

        if ( '' === $form_source || '' === $form_id || '' === $entry_id )
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
            ? $adapter->get_entry_data( $entry_id, $form_id )
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
                        'error'  => $error->get_error_message(),
                        'run_at' => $run_at,
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
            [ 'error' => $error->get_error_message() ],
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

        $event = [
            'execution_request_id' => $execution_request_id,
            'mapping_id'           => absint( $payload['local_mapping_id'] ?? $context['local_form_mapping_id'] ?? 0 ),
            'form_source'          => $payload['form_source'] ?? $context['form_source'] ?? 'gravity_forms',
            'form_id'              => $payload['form_id'] ?? $context['form_id'] ?? null,
            'entry_id'             => $payload['entry_id'] ?? $context['entry_id'] ?? null,
            'provider'             => $result['provider'] ?? $context['provider'] ?? 'openrouter',
            'model'                => $result['model'] ?? $context['model'] ?? null,
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

    private function get_execution_events_repository(): Sentient_Forms_Execution_Events_Repository
    {
        global $wpdb;

        return new Sentient_Forms_Execution_Events_Repository( $wpdb );
    }

    /**
     * Normalize mutable runtime settings before dispatch.
     *
     * @param array $settings Raw linkage settings.
     * @return array
     */
    private function normalize_runtime_settings( array $settings ): array
    {
        if ( !isset( $settings['batch_settings'] ) || !is_array( $settings['batch_settings'] ) )
        {
            return $settings;
        }

        $settings['batch_settings'] = [
            'enabled'       => ! empty( $settings['batch_settings']['enabled'] ),
            'delay_seconds' => max( 10, min( 3600, (int) ( $settings['batch_settings']['delay_seconds'] ?? 60 ) ) ),
            'max_wait_seconds' => max( 43200, min( 604800, (int) ( $settings['batch_settings']['max_wait_seconds'] ?? DAY_IN_SECONDS ) ) ),
        ];

        return $settings;
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
            array_merge(
                $job['context'] ?? [],
                $job['settings'] ?? []
            )
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

        $job = [
            'action_id'            => $action_id,
            'data'                 => $data,
            'settings'             => $settings,
            'execution_request_id' => $execution_request_id,
            'context'              => $context,
        ];

        $dependency_gate = $this->evaluate_dependency_gate( $job );
        if ( 'skip' === $dependency_gate['state'] )
        {
            $this->handle_dependency_skip(
                $job,
                $dependency_gate['reason'] ?? __( 'Dependency failed or skipped', 'sentient-forms' ),
                $dependency_gate['reason_code'] ?? null
            );
            $this->sweep_stale_async_rows();
            return;
        }

        if ( 'wait' === $dependency_gate['state'] )
        {
            $this->requeue_action_waiting_on_dependencies(
                $job,
                (int) ( $dependency_gate['delay_seconds'] ?? 10 ),
                $dependency_gate['reason'] ?? __( 'Waiting for dependency completion', 'sentient-forms' ),
            );
            $this->sweep_stale_async_rows();
            return;
        }

		$this->get_metadata_store()->update_status( $context['job_id'] ?? null, 'running' );
		if ( $execution_request_id )
		{
			$this->get_request_store()->mark_status( $execution_request_id, 'running' );
		}

        try
        {
            $action = $this->plugin->get_action( $action_id );
            $action_type_indicator = (string) ( $settings['action_type_indicator'] ?? '' );
            $is_cps_managed_action = in_array( $action_type_indicator, [ 'master', 'custom' ], true );
            
            if ( !$action && !$is_cps_managed_action )
            {
                $this->handle_failure(
                    $job,
                    new WP_Error(
                        'sentient_forms_missing_action',
                        sprintf(
                            /* translators: %s: action id. */
                            __( 'Action %s not found.', 'sentient-forms' ),
                            $action_id
                        ),
                    ),
                );
                return;
            }

            $entry_id = $data['entry']['id'] ?? $context['entry_id'] ?? null;
            $form_id  = $data['form']['id'] ?? $context['form_id'] ?? null;

            // For CPS-managed actions without a local handler, execute via Action Executor.
            if ( !$action && $is_cps_managed_action )
            {
                // Apply hierarchical settings resolution (form-level merging)
                $resolved_settings = $this->resolve_hierarchical_settings( $settings, $context );

                try
                {
                    $executor = $this->plugin->get_action_executor();
                    $result = $executor->execute(
                        $resolved_settings['central_action_id'] ?? $action_id,
                        $data['form'] ?? [],
                        $data['entry'] ?? [],
                        array_merge( $context, [ 'settings' => $resolved_settings ] )
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
                    sentient_forms_debug_log(
                        '[sentient-forms][async] execute exception.',
                        [
                            'action_id' => $action_id,
                            'entry_id'  => $entry_id ?? 'n/a',
                            'form_id'   => $form_id ?? 'n/a',
                            'error'     => $throwable->getMessage(),
                        ]
                    );
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
                sentient_forms_debug_log(
                    '[sentient-forms][async] execute wp_error.',
                    [
                        'action_id'     => $action_id,
                        'entry_id'      => $entry_id ?? 'n/a',
                        'form_id'       => $form_id ?? 'n/a',
                        'error_code'    => $result->get_error_code(),
                        'error_message' => $result->get_error_message(),
                    ]
                );
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

        $classification = $this->get_upstream_spam_classification( $job );
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
        $context    = isset( $job['context'] ) && is_array( $job['context'] ) ? $job['context'] : [];
        $form_source = sanitize_key( (string) ( $context['form_source'] ?? $context['adapter_id'] ?? 'gravity_forms' ) );
        $form_id     = absint( $context['form_id'] ?? 0 );

        if ( '' === $form_source || $form_id <= 0 )
        {
            return false;
        }

        $form_settings = get_option( sprintf( 'sentient_forms_actions_%s_%d', $form_source, $form_id ), [] );
        if ( ! is_array( $form_settings ) || ! isset( $form_settings[ $dependency_id ] ) || ! is_array( $form_settings[ $dependency_id ] ) )
        {
            return false;
        }

        $dependency_mapping = $this->resolve_hierarchical_settings(
            $form_settings[ $dependency_id ],
            [
                'form_source' => $form_source,
                'form_id'     => $form_id,
                'action_id'   => $form_settings[ $dependency_id ]['central_action_id'] ?? '',
            ]
        );
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
     * Resolve the stored upstream spam classification for a dependent job.
     *
     * @param array<string, mixed> $job Job payload.
     *
     * @return string|null
     */
    private function get_upstream_spam_classification( array $job ): ?string
    {
        $context  = isset( $job['context'] ) && is_array( $job['context'] ) ? $job['context'] : [];
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
     * Requeue a job while it waits for dependencies to complete.
     *
     * @param array<string, mixed> $job          Job payload.
     * @param int                  $delay_seconds Delay before retry.
     * @param string               $reason        Wait reason.
     *
     * @return void
     */
    private function requeue_action_waiting_on_dependencies( array $job, int $delay_seconds, string $reason ): void
    {
        $context = isset( $job['context'] ) && is_array( $job['context'] ) ? $job['context'] : [];
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

        if ( ! empty( $job['execution_request_id'] ) )
        {
            $this->get_request_store()->mark_status( (string) $job['execution_request_id'], 'queued', $reason );
        }

        $reschedule_context = $context;
        unset( $reschedule_context['job_id'] );

        $scheduled = $this->schedule_action(
            (string) $job['action_id'],
            is_array( $job['data'] ?? null ) ? $job['data'] : [],
            is_array( $job['settings'] ?? null ) ? $job['settings'] : [],
            $reschedule_context,
            $run_at,
        );

        if ( ! $scheduled )
        {
            $this->handle_failure(
                $job,
                new WP_Error( 'sentient_forms_dependency_wait_reschedule_failed', $reason ),
            );
            return;
        }

        $this->emit_async_event(
            'dependency_wait',
            $context,
            [
                'reason' => $reason,
                'run_at' => $run_at,
            ],
        );
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
            sentient_forms_debug_log(
                'Sentient Forms action processed successfully.',
                [
                    'action_id' => $action_id,
                    'result'    => $result,
                ]
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
            sentient_forms_debug_log(
                'Sentient Forms action error.',
                [
                    'message' => $message,
                ]
            );
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
        $result  = Sentient_Forms_Local_Data_Governance::sanitize_execution_payload_for_storage( $result );

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
