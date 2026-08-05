<?php

/**
 * Executes canonical validation assignments through the real Form Source hooks.
 *
 * @package Sentient_Forms
 */

final class Sentient_Forms_Test_Exact_Artifact_Execution_Boundary extends Sentient_Forms_Local_Action_Execution_Service
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** @param array<string, mixed> $result */
    public function __construct( private array $result )
    {
    }

    /** @return array<string, mixed> */
    public function execute_mapping( int $mapping_id, array $form, array $entry, array $context = [] ): array | WP_Error
    {
        $this->calls[] = compact( 'mapping_id', 'form', 'entry', 'context' );

        return $this->result;
    }
}

final class Sentient_Forms_Test_Exact_Artifact_CF7_Validation_Result
{
    /** @var array<int, array{tag: object, message: string}> */
    public array $invalidations = [];

    public function invalidate( object $tag, string $message ): void
    {
        $this->invalidations[] = compact( 'tag', 'message' );
    }
}

final class Sentient_Forms_Test_Exact_Artifact_Elementor_Validation_Handler
{
    /** @var array<string|int, string> */
    public array $errors = [];

    /** @var array<string, array<int, string>> */
    public array $messages = [ 'error' => [] ];

    public function add_error( string | int $field_id, string $message = '' ): self
    {
        $this->errors[ $field_id ] = $message;

        return $this;
    }

    public function add_error_message( string $message ): self
    {
        $this->messages['error'][] = $message;

        return $this;
    }
}

final class Sentient_Forms_Test_Exact_Artifact_Validation_Observer
{
    public static ?Sentient_Forms_Validation_Run_Result $result = null;

    public static function record( Sentient_Forms_Validation_Run_Result $result ): void
    {
        self::$result = $result;
    }

    public static function blocked(): bool
    {
        if ( ! self::$result instanceof Sentient_Forms_Validation_Run_Result )
        {
            throw new RuntimeException( 'The native validation adapter did not expose its real workflow result.' );
        }
        if ( null !== self::$result->get_form_error() || [] !== self::$result->get_field_errors() )
        {
            return true;
        }

        return false;
    }

    public static function spam_state_applied(): bool
    {
        if ( ! self::$result instanceof Sentient_Forms_Validation_Run_Result )
        {
            throw new RuntimeException( 'The native validation adapter did not expose its real workflow result.' );
        }

        return [] !== array_intersect( [ 'spam', 'likely_spam' ], self::$result->get_spam_classifications() );
    }
}

class Sentient_Forms_Test_Exact_Artifact_Gravity_Adapter extends Sentient_Forms_Gravity_Forms_Adapter
{
    public function apply_validation_result( mixed $native_validation, Sentient_Forms_Validation_Run_Result $result ): mixed
    {
        Sentient_Forms_Test_Exact_Artifact_Validation_Observer::record( $result );

        return parent::apply_validation_result( $native_validation, $result );
    }
}

class Sentient_Forms_Test_Exact_Artifact_CF7_Adapter extends Sentient_Forms_Contact_Form_7_Adapter
{
    public function apply_validation_result( mixed $native_validation, Sentient_Forms_Validation_Run_Result $result ): mixed
    {
        Sentient_Forms_Test_Exact_Artifact_Validation_Observer::record( $result );

        return parent::apply_validation_result( $native_validation, $result );
    }
}

class Sentient_Forms_Test_Exact_Artifact_WPForms_Adapter extends Sentient_Forms_WPForms_Adapter
{
    public function apply_validation_result( mixed $native_validation, Sentient_Forms_Validation_Run_Result $result ): mixed
    {
        Sentient_Forms_Test_Exact_Artifact_Validation_Observer::record( $result );

        return parent::apply_validation_result( $native_validation, $result );
    }
}

class Sentient_Forms_Test_Exact_Artifact_Elementor_Adapter extends Sentient_Forms_Elementor_Forms_Adapter
{
    public function apply_validation_result( mixed $native_validation, Sentient_Forms_Validation_Run_Result $result ): mixed
    {
        Sentient_Forms_Test_Exact_Artifact_Validation_Observer::record( $result );

        return parent::apply_validation_result( $native_validation, $result );
    }
}

final class Sentient_Forms_Test_Exact_Artifact_Validation_Scenario
{
    private const SOURCES = [ 'gravity_forms', 'contact_form_7', 'wpforms', 'elementor_pro_forms' ];

    private const ACTIONS = [ 'spam_detection_v1', 'content_validation_v1' ];

    private const ASSIGNMENTS = [ 'accept', 'reject' ];

    /**
     * Execute one canonical validation assignment.
     *
     * @return array{
     *     form_source: string,
     *     action_code: string,
     *     assignment: string,
     *     observed_effect: string,
     *     rejected: bool,
     *     effect_applied: bool,
     *     spam_state_applied: bool,
     *     native_rejected: bool,
     *     request_id: ?string,
     *     trace_id: ?string,
     *     native_hook: string,
     *     native_hooks: array<int, string>,
     *     provider_calls: int
     * }
     */
    public static function run( string $form_source, string $action_code, string $assignment ): array
    {
        require_once __DIR__ . '/class-sentient-forms-test-exact-artifact-gravity-runtime.php';
        self::assert_supported( $form_source, $action_code, $assignment );
        Sentient_Forms_Installer::maybe_upgrade();

        $identity_post_id = wp_insert_post(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Exact-artifact validation form identity',
            ]
        );
        if ( ! is_int( $identity_post_id ) || $identity_post_id <= 0 )
        {
            throw new RuntimeException( 'Unable to allocate the validation form identity.' );
        }
        $form_id = (string) $identity_post_id;
        Sentient_Forms_Test_Exact_Artifact_Validation_Observer::$result = null;
        $boundary = new Sentient_Forms_Test_Exact_Artifact_Execution_Boundary(
            self::provider_result( $action_code, $assignment )
        );
        $runner = new Sentient_Forms_Form_Source_Workflow_Runner(
            Sentient_Forms_Plugin::instance(),
            null,
            null,
            $boundary
        );
        $mapping_id = self::create_mapping( $form_source, $form_id, $action_code );
        $hooks = self::hook_names( $form_source );
        $hook_snapshot = self::isolate_hooks( $hooks );
        $post_snapshot = $_POST;

        try
        {
            $observation = match ( $form_source )
            {
                'gravity_forms'       => self::run_gravity_forms( $form_id, $runner ),
                'contact_form_7'      => self::run_contact_form_7( $form_id, $action_code, $runner ),
                'wpforms'             => self::run_wpforms( $form_id, $runner ),
                'elementor_pro_forms' => self::run_elementor( $form_id, $runner ),
            };
        }
        finally
        {
            $_POST = $post_snapshot;
            self::restore_hooks( $hook_snapshot );
            self::delete_mapping( $mapping_id );
            wp_delete_post( $identity_post_id, true );
        }

        $request_id = $boundary->calls[0]['context']['execution_request_id'] ?? null;
        $native_rejected = (bool) ( $observation['rejected'] ?? false );
        $runtime_blocked = Sentient_Forms_Test_Exact_Artifact_Validation_Observer::blocked();
        $spam_state_applied = Sentient_Forms_Test_Exact_Artifact_Validation_Observer::spam_state_applied();
        $rejected = $native_rejected || $runtime_blocked;
        $effect_applied = $rejected || $spam_state_applied;
        if ( $runtime_blocked && ! $native_rejected )
        {
            $observation['observed_effect'] .= '_runtime_blocked';
        }
        elseif ( $spam_state_applied && ! $native_rejected )
        {
            $observation['observed_effect'] = 'gravity_forms' === $form_source
                ? 'gravity_forms_native_spam_state_applied'
                : $observation['observed_effect'];
        }
        $trace_id = $rejected && is_string( $request_id ) && '' !== $request_id
            ? 'validation-rejection:' . $request_id
            : null;

        if ( 1 !== count( $boundary->calls ) )
        {
            throw new RuntimeException( 'The validation provider boundary must execute exactly once.' );
        }
        if ( ! is_string( $request_id ) || '' === $request_id )
        {
            throw new RuntimeException( 'The validation assignment did not produce its real request ID.' );
        }
        if ( 'reject' === $assignment && ( ! $effect_applied || ! is_string( $request_id ) || '' === $request_id ) )
        {
            throw new RuntimeException(
                sprintf(
                    'Negative validation evidence is incomplete for %s/%s: effect=%s request=%s.',
                    $form_source,
                    $action_code,
                    $effect_applied ? 'true' : 'false',
                    is_string( $request_id ) ? $request_id : 'null'
                )
            );
        }
        if ( 'accept' === $assignment && ( $effect_applied || null !== $trace_id ) )
        {
            throw new RuntimeException( 'The accepted validation assignment unexpectedly applied a negative effect.' );
        }

        return [
            'form_source'     => $form_source,
            'action_code'     => $action_code,
            'assignment'      => $assignment,
            'observed_effect' => (string) $observation['observed_effect'],
            'rejected'        => $rejected,
            'effect_applied'  => $effect_applied,
            'spam_state_applied' => $spam_state_applied,
            'native_rejected' => $native_rejected,
            'request_id'      => is_string( $request_id ) && '' !== $request_id ? $request_id : null,
            'trace_id'        => is_string( $trace_id ) && '' !== $trace_id ? $trace_id : null,
            'native_hook'     => (string) $observation['native_hook'],
            'native_hooks'    => isset( $observation['native_hooks'] ) && is_array( $observation['native_hooks'] )
                ? array_values( $observation['native_hooks'] )
                : [ (string) $observation['native_hook'] ],
            'provider_calls'  => count( $boundary->calls ),
        ];
    }

    private static function assert_supported( string $form_source, string $action_code, string $assignment ): void
    {
        if ( ! in_array( $form_source, self::SOURCES, true )
            || ! in_array( $action_code, self::ACTIONS, true )
            || ! in_array( $assignment, self::ASSIGNMENTS, true ) )
        {
            throw new InvalidArgumentException( 'Unsupported exact-artifact validation scenario.' );
        }
    }

    /** @return array<string, mixed> */
    private static function provider_result( string $action_code, string $assignment ): array
    {
        if ( 'spam_detection_v1' === $action_code )
        {
            return [
                'result_data' => [
                    'structured_output_valid' => true,
                    'structured_output'       => [
                        'classification' => 'reject' === $assignment ? 'spam' : 'ham',
                        'confidence'     => 0.99,
                        'justification'  => 'Exact-artifact external-boundary fixture.',
                        'indicators'     => 'reject' === $assignment
                            ? [ [ 'type' => 'other', 'evidence' => 'Exact-artifact rejection fixture.', 'weight' => 'high' ] ]
                            : [],
                    ],
                ],
                'validation'  => [
                    'is_valid' => 'accept' === $assignment,
                    'message'  => 'reject' === $assignment ? 'Submission classified as spam.' : '',
                    'fields'   => [],
                ],
            ];
        }

        return [
            'result_data' => [
                'structured_output_valid' => true,
                'structured_output'       => [
                    'is_valid' => 'accept' === $assignment,
                    'message'  => 'reject' === $assignment ? 'Please review your submission.' : '',
                    'fields'   => 'reject' === $assignment
                        ? [ [ 'field_id' => 'message', 'is_valid' => false, 'message' => 'Please provide useful details.' ] ]
                        : [],
                ],
            ],
        ];
    }

    private static function create_mapping( string $form_source, string $form_id, string $action_code ): int
    {
        global $wpdb;

        $catalog = Sentient_Forms_Bundled_Action_Templates::get( $action_code );
        if ( ! is_array( $catalog ) )
        {
            throw new RuntimeException( 'Canonical bundled validation action is unavailable.' );
        }
        $template_id = ( new Sentient_Forms_Action_Templates_Repository( $wpdb ) )->upsert_by_code(
            [
                'source'                   => 'bundled',
                'code'                     => $action_code,
                'display_name'             => $catalog['display_name'],
                'description'              => $catalog['description'] ?? null,
                'prompt_template'          => $catalog['prompt_template'],
                'default_model'             => $catalog['default_model'] ?? null,
                'structured_output_schema' => $catalog['structured_output_schema'] ?? null,
                'override_schema'          => $catalog['override_schema'] ?? null,
                'version'                  => $catalog['version'] ?? '1',
                'is_active'                => true,
            ]
        );
        $action_id = ( new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb ) )->upsert_by_code(
            [
                'code'                 => Sentient_Forms_Bundled_Action_Templates::build_managed_custom_action_code( $action_code ),
                'display_name'         => $catalog['display_name'],
                'template_id'          => $template_id,
                'definition_json'      => [ 'template_code' => $action_code ],
                'model_selection_json' => [ 'provider' => 'openrouter', 'model' => 'openrouter/auto' ],
                'status'               => 'active',
            ]
        );
        $mapping_id = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->create(
            [
                'form_source'         => $form_source,
                'form_id'             => $form_id,
                'hook'                => 'validation',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [],
                'execution_mode'      => 'sync',
                'settings_json'       => [ 'async' => false ],
                'effect_mapping_json' => $catalog['effect_mapping_json'] ?? [],
                'enabled'             => true,
            ]
        );
        if ( ! is_int( $template_id ) || ! is_int( $action_id ) || ! is_int( $mapping_id ) )
        {
            throw new RuntimeException( 'Unable to create the canonical validation mapping.' );
        }

        return $mapping_id;
    }

    private static function delete_mapping( int $mapping_id ): void
    {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'sentient_form_mappings', [ 'id' => $mapping_id ], [ '%d' ] );
    }

    /** @return array<int, string> */
    private static function hook_names( string $form_source ): array
    {
        return match ( $form_source )
        {
            'gravity_forms'       => [ 'gform_validation' ],
            'contact_form_7'      => [ 'wpcf7_validate', 'wpcf7_spam', 'sentient_forms_contact_form_7_is_active', 'sentient_forms_contact_form_7_current_submission' ],
            'wpforms'             => [ 'wpforms_process', 'sentient_forms_wpforms_is_active', 'sentient_forms_wpforms_object' ],
            'elementor_pro_forms' => [ 'elementor_pro/forms/validation', 'sentient_forms_elementor_is_active', 'sentient_forms_elementor_pro_forms_api_available', 'sentient_forms_elementor_posts_with_data' ],
        };
    }

    /** @param array<int, string> $hooks @return array<string, mixed> */
    private static function isolate_hooks( array $hooks ): array
    {
        global $wp_filter;
        $snapshot = [];
        foreach ( $hooks as $hook )
        {
            $snapshot[ $hook ] = $wp_filter[ $hook ] ?? null;
            unset( $wp_filter[ $hook ] );
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    private static function restore_hooks( array $snapshot ): void
    {
        global $wp_filter;
        foreach ( $snapshot as $hook => $callbacks )
        {
            if ( null === $callbacks )
            {
                unset( $wp_filter[ $hook ] );
            }
            else
            {
                $wp_filter[ $hook ] = $callbacks;
            }
        }
    }

    /** @return array{rejected: bool, observed_effect: string, native_hook: string} */
    private static function run_gravity_forms(
        string $form_id,
        Sentient_Forms_Form_Source_Workflow_Runner $runner
    ): array
    {
        $adapter = new Sentient_Forms_Test_Exact_Artifact_Gravity_Adapter( Sentient_Forms_Plugin::instance(), $runner );
        $adapter->register_hooks();
        $_POST['input_2'] = 'Exact-artifact validation fixture.';
        $field = (object) [ 'id' => 2, 'failed_validation' => false, 'validation_message' => '' ];
        $result = apply_filters(
            'gform_validation',
            [ 'is_valid' => true, 'form' => [ 'id' => (int) $form_id, 'fields' => [ $field ] ] ],
            [ 'source' => 'exact-artifact' ]
        );
        $rejected = ! (bool) ( $result['is_valid'] ?? true );

        return [
            'rejected'        => $rejected,
            'observed_effect' => $rejected ? 'gravity_forms_validation_rejected' : 'gravity_forms_validation_accepted',
            'native_hook'     => 'gform_validation',
        ];
    }

    /** @return array{rejected: bool, observed_effect: string, native_hook: string, native_hooks: array<int, string>} */
    private static function run_contact_form_7(
        string $form_id,
        string $action_code,
        Sentient_Forms_Form_Source_Workflow_Runner $runner
    ): array
    {
        $tag = new class {
            public string $type = 'textarea*';
            public string $basetype = 'textarea';
            public string $name = 'message';

            public function is_required(): bool
            {
                return true;
            }
        };
        $form = new class( (int) $form_id, $tag ) {
            public function __construct( private int $id, private object $tag )
            {
            }

            public function id(): int
            {
                return $this->id;
            }

            public function title(): string
            {
                return 'Exact Artifact CF7';
            }

            public function scan_form_tags(): array
            {
                return [ $this->tag ];
            }
        };
        $submission = new class( $form ) {
            public function __construct( private object $form )
            {
            }

            public function get_contact_form(): object
            {
                return $this->form;
            }

            public function get_posted_data(): array
            {
                return [ 'message' => 'Exact-artifact validation fixture.', '_wpcf7' => (string) $this->form->id() ];
            }
        };
        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter( 'sentient_forms_contact_form_7_current_submission', static fn() => $submission );
        $adapter = new Sentient_Forms_Test_Exact_Artifact_CF7_Adapter( Sentient_Forms_Plugin::instance(), $runner );
        $adapter->init();
        $result = apply_filters( 'wpcf7_validate', new Sentient_Forms_Test_Exact_Artifact_CF7_Validation_Result(), [ $tag ] );
        $spam = 'spam_detection_v1' === $action_code ? (bool) apply_filters( 'wpcf7_spam', false, $submission ) : false;
        $rejected = [] !== $result->invalidations;

        return [
            'rejected'        => $rejected,
            'observed_effect' => $spam ? 'contact_form_7_spam_flagged' : ( $rejected ? 'contact_form_7_field_invalidated' : 'contact_form_7_validation_accepted' ),
            'native_hook'     => 'spam_detection_v1' === $action_code ? 'wpcf7_spam' : 'wpcf7_validate',
            'native_hooks'    => 'spam_detection_v1' === $action_code
                ? [ 'wpcf7_validate', 'wpcf7_spam' ]
                : [ 'wpcf7_validate' ],
        ];
    }

    /** @return array{rejected: bool, observed_effect: string, native_hook: string} */
    private static function run_wpforms(
        string $form_id,
        Sentient_Forms_Form_Source_Workflow_Runner $runner
    ): array
    {
        $process = (object) [ 'errors' => [] ];
        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );
        add_filter(
            'sentient_forms_wpforms_object',
            static fn( mixed $object, string $name ): mixed => 'process' === $name ? $process : $object,
            10,
            2
        );
        $adapter = new Sentient_Forms_Test_Exact_Artifact_WPForms_Adapter( Sentient_Forms_Plugin::instance(), $runner );
        $adapter->init();
        $fields = [
            2 => [ 'id' => 2, 'name' => 'Message', 'type' => 'textarea', 'value' => 'Exact-artifact validation fixture.' ],
        ];
        $form_data = [
            'id'       => (int) $form_id,
            'settings' => [ 'form_title' => 'Exact Artifact WPForms' ],
            'fields'   => [ 2 => [ 'id' => 2, 'label' => 'Message', 'type' => 'textarea' ] ],
        ];
        do_action( 'wpforms_process', $fields, [], $form_data );
        $rejected = ! empty( $process->errors[ (int) $form_id ] ?? [] );

        return [
            'rejected'        => $rejected,
            'observed_effect' => $rejected ? 'wpforms_process_error_added' : 'wpforms_validation_accepted',
            'native_hook'     => 'wpforms_process',
        ];
    }

    /** @return array{rejected: bool, observed_effect: string, native_hook: string} */
    private static function run_elementor(
        string $form_id,
        Sentient_Forms_Form_Source_Workflow_Runner $runner
    ): array
    {
        [ $page_id, $widget_id ] = explode( ':', $form_id . ':formabc', 2 );
        $page_id = wp_insert_post(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Exact Artifact Elementor',
            ]
        );
        $widget_id = 'formabc';
        $resolved_form_id = $page_id . ':' . $widget_id;
        self::replace_mapping_form_id( $form_id, $resolved_form_id );
        update_post_meta(
            $page_id,
            '_elementor_data',
            wp_slash(
                wp_json_encode(
                    [
                        [
                            'id'       => 'container1',
                            'elType'   => 'container',
                            'settings' => [],
                            'elements' => [
                                [
                                    'id'         => $widget_id,
                                    'elType'     => 'widget',
                                    'widgetType' => 'form',
                                    'settings'   => [
                                        'form_name'   => 'Exact Artifact Elementor',
                                        'form_fields' => [
                                            [ 'custom_id' => 'message', 'field_label' => 'Message', 'field_type' => 'textarea', 'required' => 'true' ],
                                        ],
                                    ],
                                    'elements'   => [],
                                ],
                            ],
                        ],
                    ]
                )
            )
        );
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        add_filter( 'sentient_forms_elementor_posts_with_data', static fn() => [ $page_id ] );
        $record = new class( $page_id, $widget_id ) {
            public function __construct( private int $page_id, private string $widget_id )
            {
            }

            public function get( string $key ): mixed
            {
                return 'fields' === $key
                    ? [ 'native-message' => [ 'id' => 'message', 'title' => 'Message', 'type' => 'textarea', 'value' => 'Exact-artifact validation fixture.' ] ]
                    : null;
            }

            public function get_form_settings( ?string $key = null ): mixed
            {
                $settings = [ 'id' => $this->widget_id, 'post_id' => $this->page_id, 'form_name' => 'Exact Artifact Elementor' ];

                return null === $key ? $settings : ( $settings[ $key ] ?? null );
            }
        };
        $handler = new Sentient_Forms_Test_Exact_Artifact_Elementor_Validation_Handler();
        $adapter = new Sentient_Forms_Test_Exact_Artifact_Elementor_Adapter( Sentient_Forms_Plugin::instance() );
        $runner_property = new ReflectionProperty( Sentient_Forms_Elementor_Forms_Adapter::class, 'workflow_runner' );
        $runner_property->setValue( $adapter, $runner );
        $adapter->init();
        do_action( 'elementor_pro/forms/validation', $record, $handler );
        $rejected = [] !== $handler->errors || [] !== $handler->messages['error'];
        wp_delete_post( $page_id, true );

        return [
            'rejected'        => $rejected,
            'observed_effect' => $rejected ? 'elementor_ajax_error_added' : 'elementor_validation_accepted',
            'native_hook'     => 'elementor_pro/forms/validation',
        ];
    }

    private static function replace_mapping_form_id( string $old_form_id, string $new_form_id ): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'sentient_form_mappings',
            [ 'form_id' => $new_form_id ],
            [ 'form_source' => 'elementor_pro_forms', 'form_id' => $old_form_id ],
            [ '%s' ],
            [ '%s', '%s' ]
        );
    }

}
