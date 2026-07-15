<?php

if ( ! class_exists( 'Sentient_Forms_Test_Mappings_Migration_Api_Client' ) )
{
    class Sentient_Forms_Test_Mappings_Migration_Api_Client extends Sentient_Forms_Api_Client
    {
        /**
         * @var array<string, array<string, mixed>>
         */
        public array $records = [];

        /**
         * @var array<int, array{path: string, options: array<string, mixed>}>
         */
        public array $get_calls = [];

        /**
         * @var array<int, array{path: string, payload: array<string, mixed>, options: array<string, mixed>}>
         */
        public array $post_calls = [];

        /**
         * @var array<int, array{path: string, payload: array<string, mixed>, options: array<string, mixed>}>
         */
        public array $put_calls = [];

        public string $list_shape = 'list';

        /**
         * @param array<int, array<string, mixed>> $records
         */
        public function __construct( array $records = [] )
        {
            foreach ( $records as $record )
            {
                if ( ! is_array( $record ) || empty( $record['id'] ) )
                {
                    continue;
                }

                $id                  = sanitize_text_field( (string) $record['id'] );
                $this->records[ $id ] = $record;
            }
        }

        public function get( string $path, array $options = [] ): WP_Error | array
        {
            $this->get_calls[] = [
                'path'    => $path,
                'options' => $options,
            ];

            $records = array_values( $this->records );
            if ( 'mappings_key' === $this->list_shape )
            {
                return [ 'mappings' => $records ];
            }
            if ( 'data_key' === $this->list_shape )
            {
                return [ 'data' => $records ];
            }

            return $records;
        }

        public function post( string $path, array $payload, array $options = [] ): WP_Error | array
        {
            $this->post_calls[] = [
                'path'    => $path,
                'payload' => $payload,
                'options' => $options,
            ];

            $id     = wp_generate_uuid4();
            $record = [
                'id'                   => $id,
                'site_id'              => $payload['site_id'] ?? '',
                'form_source'          => $payload['form_source'] ?? '',
                'form_id'              => $payload['form_id'] ?? 0,
                'action_template_id'   => $payload['action_template_id'] ?? null,
                'action_template_code' => $payload['action_template_code'] ?? null,
                'custom_action_id'     => $payload['custom_action_id'] ?? null,
                'display_name'         => $payload['display_name'] ?? '',
                'settings'             => $payload['settings'] ?? [],
                'is_template'          => false,
            ];

            $this->records[ $id ] = $record;

            return $record;
        }

        public function put( string $path, array $payload, array $options = [] ): WP_Error | array
        {
            $this->put_calls[] = [
                'path'    => $path,
                'payload' => $payload,
                'options' => $options,
            ];

            $mapping_id = sanitize_text_field( (string) basename( $path ) );
            if ( '' === $mapping_id || ! isset( $this->records[ $mapping_id ] ) )
            {
                return new WP_Error( 'missing_record', 'Record not found in stub client.' );
            }

            $record               = $this->records[ $mapping_id ];
            $record['display_name'] = $payload['display_name'] ?? ( $record['display_name'] ?? '' );
            $record['settings']     = $payload['settings'] ?? ( $record['settings'] ?? [] );
            $record['is_template']  = $payload['is_template'] ?? ( $record['is_template'] ?? false );
            $this->records[ $mapping_id ] = $record;

            return $record;
        }
    }
}

class Tests_Mappings_Migration_Service extends WP_UnitTestCase
{
    /**
     * @var array<int, string>
     */
    private array $option_keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $action_option_names = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT option_name FROM %i WHERE option_name LIKE %s',
                $wpdb->options,
                $wpdb->esc_like( 'sentient_forms_actions_' ) . '%'
            )
        );
        foreach ( is_array( $action_option_names ) ? $action_option_names : [] as $action_option_name )
        {
            if ( is_string( $action_option_name ) )
            {
                delete_option( $action_option_name );
            }
        }

        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'proxy_api_key' => 'proxy-migrate-test',
                'site_id'       => '11111111-2222-4333-8444-555555555555',
            ]
        );

        update_option( 'sentient_forms_site_id', '11111111-2222-4333-8444-555555555555' );
    }

    protected function tearDown(): void
    {
        foreach ( $this->option_keys as $option_key )
        {
            delete_option( $option_key );
        }

        delete_option( 'sentient_forms_site_id' );

        parent::tearDown();
    }

    public function test_migrate_dry_run_plans_create_and_update_without_remote_mutation(): void
    {
        $option_key = $this->store_form_actions(
            'gravity_forms',
            101,
            [
                'map_existing' => $this->build_master_mapping( 'map_existing', 'entry_summary_v1' ),
                'map_new'      => $this->build_master_mapping( 'map_new', 'spam_detection_v1' ),
            ]
        );

        $client  = new Sentient_Forms_Test_Mappings_Migration_Api_Client(
            [
                $this->build_remote_record(
                    '33333333-2222-4333-8444-111111111111',
                    '11111111-2222-4333-8444-555555555555',
                    'gravity_forms',
                    101,
                    'map_existing'
                ),
            ]
        );
        $service = new Sentient_Forms_Mappings_Migration_Service(
            $client,
            'proxy-migrate-test',
            '11111111-2222-4333-8444-555555555555'
        );

        $result = $service->migrate(
            [
                'apply'            => false,
                'include_disabled' => true,
                'form_source'      => 'gravity_forms',
                'form_id'          => 101,
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 1, count( $result['forms'] ) );
        $this->assertSame( 1, $result['totals']['create'] ?? -1 );
        $this->assertSame( 1, $result['totals']['update'] ?? -1 );
        $this->assertSame( 0, $result['totals']['error'] ?? -1 );
        $this->assertSame( 1, count( $client->get_calls ) );
        $this->assertSame( 0, count( $client->post_calls ) );
        $this->assertSame( 0, count( $client->put_calls ) );
        $this->assertSame( $option_key, $result['forms'][0]['option_key'] ?? '' );
    }

    public function test_migrate_apply_creates_and_updates_by_local_mapping_id(): void
    {
        $this->store_form_actions(
            'gravity_forms',
            102,
            [
                'map_existing' => $this->build_master_mapping( 'map_existing', 'entry_summary_v1' ),
                'map_new'      => $this->build_master_mapping( 'map_new', 'spam_detection_v1' ),
            ]
        );

        $client  = new Sentient_Forms_Test_Mappings_Migration_Api_Client(
            [
                $this->build_remote_record(
                    '77777777-2222-4333-8444-111111111111',
                    '11111111-2222-4333-8444-555555555555',
                    'gravity_forms',
                    102,
                    'map_existing'
                ),
            ]
        );
        $service = new Sentient_Forms_Mappings_Migration_Service(
            $client,
            'proxy-migrate-test',
            '11111111-2222-4333-8444-555555555555'
        );

        $result = $service->migrate(
            [
                'apply'            => true,
                'include_disabled' => true,
                'form_source'      => 'gravity_forms',
                'form_id'          => 102,
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 1, $result['totals']['create'] ?? -1 );
        $this->assertSame( 1, $result['totals']['update'] ?? -1 );
        $this->assertSame( 0, $result['totals']['error'] ?? -1 );
        $this->assertSame( 1, count( $client->post_calls ) );
        $this->assertSame( 1, count( $client->put_calls ) );
        $this->assertSame( '/mappings', $client->post_calls[0]['path'] ?? '' );
        $this->assertSame(
            'spam_detection_v1',
            $client->post_calls[0]['payload']['action_template_code'] ?? null
        );
        $this->assertSame(
            'map_new',
            $client->post_calls[0]['payload']['settings']['local_mapping_id'] ?? null
        );
        $this->assertStringContainsString(
            '/mappings/77777777-2222-4333-8444-111111111111',
            $client->put_calls[0]['path'] ?? ''
        );
    }

    public function test_migrate_apply_preserves_elementor_provider_native_form_id(): void
    {
        $option_key           = 'sentient_forms_actions_elementor_pro_forms_' . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( '91:formabc' );
        $this->option_keys[] = $option_key;
        update_option(
            $option_key,
            [
                'map_existing' => [
                    'local_mapping_id'           => 'map_existing',
                    'central_action_id'          => 'entry_summary_v1',
                    'action_type_indicator'      => 'master',
                    'trigger_hooks'              => [ 'elementor_pro_forms_new_record' ],
                    'is_action_enabled_for_form' => true,
                    'execution_priority'         => 10,
                    'action_name_label'          => 'Entry Summary',
                    'settings'                   => [],
                ],
                'map_new'      => [
                    'local_mapping_id'           => 'map_new',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'trigger_hooks'              => [ 'elementor_pro_forms_new_record', 'vendor_custom_hook' ],
                    'is_action_enabled_for_form' => true,
                    'execution_priority'         => 10,
                    'action_name_label'          => 'Spam Detection',
                    'settings'                   => [],
                ],
            ],
            false
        );

        $client  = new Sentient_Forms_Test_Mappings_Migration_Api_Client(
            [
                [
                    'id'                   => 'eeeeeeee-2222-4333-8444-111111111111',
                    'site_id'              => '11111111-2222-4333-8444-555555555555',
                    'form_source'          => 'elementor_pro_forms',
                    'form_id'              => '91:formabc',
                    'action_template_id'   => null,
                    'action_template_code' => 'entry_summary_v1',
                    'custom_action_id'     => null,
                    'display_name'         => 'Entry Summary',
                    'settings'             => [
                        'local_mapping_id'           => 'map_existing',
                        'trigger_hooks'              => [ 'elementor_pro_forms_new_record' ],
                        'is_action_enabled_for_form' => true,
                    ],
                    'is_template'          => false,
                ],
            ]
        );
        $service = new Sentient_Forms_Mappings_Migration_Service(
            $client,
            'proxy-migrate-test',
            '11111111-2222-4333-8444-555555555555'
        );

        $result = $service->migrate(
            [
                'apply'            => true,
                'include_disabled' => true,
                'form_source'      => 'elementor_pro_forms',
                'form_id'          => '91:formabc',
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( '91:formabc', $result['forms'][0]['form_id'] ?? null );
        $this->assertSame( $option_key, $result['forms'][0]['option_key'] ?? '' );
        $this->assertSame( 1, $result['totals']['create'] ?? -1 );
        $this->assertSame( 1, $result['totals']['update'] ?? -1 );
        $this->assertSame( 1, count( $client->post_calls ) );
        $this->assertSame( 1, count( $client->put_calls ) );
        $this->assertSame( '91:formabc', $client->post_calls[0]['payload']['form_id'] ?? null );
        $this->assertSame(
            [ 'after_submission', 'vendor_custom_hook' ],
            $client->post_calls[0]['payload']['settings']['trigger_hooks'] ?? null
        );
        $this->assertSame(
            [ 'after_submission' ],
            $client->put_calls[0]['payload']['settings']['trigger_hooks'] ?? null
        );
    }

    public function test_scoped_elementor_migration_reads_legacy_provider_native_option_key(): void
    {
        $option_key           = 'sentient_forms_actions_elementor_pro_forms_91_formabc';
        $this->option_keys[] = $option_key;
        update_option(
            $option_key,
            [
                'map_existing' => [
                    'local_mapping_id'           => 'map_existing',
                    'central_action_id'          => 'entry_summary_v1',
                    'action_type_indicator'      => 'master',
                    'trigger_hooks'              => [ 'elementor_pro_forms_new_record' ],
                    'is_action_enabled_for_form' => true,
                    'execution_priority'         => 10,
                    'action_name_label'          => 'Entry Summary',
                    'settings'                   => [],
                ],
            ],
            false
        );

        $client  = new Sentient_Forms_Test_Mappings_Migration_Api_Client(
            [
                [
                    'id'                   => 'ffffffff-2222-4333-8444-111111111111',
                    'site_id'              => '11111111-2222-4333-8444-555555555555',
                    'form_source'          => 'elementor_pro_forms',
                    'form_id'              => '91:formabc',
                    'action_template_id'   => null,
                    'action_template_code' => 'entry_summary_v1',
                    'custom_action_id'     => null,
                    'display_name'         => 'Entry Summary',
                    'settings'             => [
                        'local_mapping_id'           => 'map_existing',
                        'trigger_hooks'              => [ 'elementor_pro_forms_new_record' ],
                        'is_action_enabled_for_form' => true,
                    ],
                    'is_template'          => false,
                ],
            ]
        );
        $service = new Sentient_Forms_Mappings_Migration_Service(
            $client,
            'proxy-migrate-test',
            '11111111-2222-4333-8444-555555555555'
        );

        $result = $service->migrate(
            [
                'apply'            => true,
                'include_disabled' => true,
                'form_source'      => 'elementor_pro_forms',
                'form_id'          => '91:formabc',
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( '91:formabc', $result['forms'][0]['form_id'] ?? null );
        $this->assertSame( $option_key, $result['forms'][0]['option_key'] ?? '' );
        $this->assertSame( 0, $result['totals']['create'] ?? -1 );
        $this->assertSame( 1, $result['totals']['update'] ?? -1 );
        $this->assertSame( 0, count( $client->post_calls ) );
        $this->assertSame( 1, count( $client->put_calls ) );
        $this->assertStringContainsString(
            '/mappings/ffffffff-2222-4333-8444-111111111111',
            $client->put_calls[0]['path'] ?? ''
        );
    }

    public function test_migrate_skips_custom_mapping_without_uuid_action_id(): void
    {
        $this->store_form_actions(
            'gravity_forms',
            103,
            [
                'map_custom' => [
                    'local_mapping_id'           => 'map_custom',
                    'central_action_id'          => 'pw_custom_non_uuid',
                    'action_type_indicator'      => 'custom',
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'is_action_enabled_for_form' => true,
                    'settings'                   => [],
                ],
            ]
        );

        $client  = new Sentient_Forms_Test_Mappings_Migration_Api_Client();
        $service = new Sentient_Forms_Mappings_Migration_Service(
            $client,
            'proxy-migrate-test',
            '11111111-2222-4333-8444-555555555555'
        );

        $result = $service->migrate(
            [
                'apply'            => true,
                'include_disabled' => true,
                'form_source'      => 'gravity_forms',
                'form_id'          => 103,
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 1, $result['totals']['skip'] ?? -1 );
        $this->assertSame( 0, $result['totals']['create'] ?? -1 );
        $this->assertSame( 0, count( $client->post_calls ) );
        $this->assertSame( 0, count( $client->put_calls ) );
        $this->assertSame(
            'unsupported_custom_action_id',
            $result['forms'][0]['operations'][0]['reason'] ?? ''
        );
    }

    public function test_migrate_supports_mappings_key_response_shape(): void
    {
        $this->store_form_actions(
            'gravity_forms',
            104,
            [
                'map_existing' => $this->build_master_mapping( 'map_existing', 'entry_summary_v1' ),
            ]
        );

        $client             = new Sentient_Forms_Test_Mappings_Migration_Api_Client(
            [
                $this->build_remote_record(
                    '88888888-2222-4333-8444-111111111111',
                    '11111111-2222-4333-8444-555555555555',
                    'gravity_forms',
                    104,
                    'map_existing'
                ),
            ]
        );
        $client->list_shape = 'mappings_key';
        $service            = new Sentient_Forms_Mappings_Migration_Service(
            $client,
            'proxy-migrate-test',
            '11111111-2222-4333-8444-555555555555'
        );

        $result = $service->migrate(
            [
                'apply'            => false,
                'include_disabled' => true,
                'form_source'      => 'gravity_forms',
                'form_id'          => 104,
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 1, $result['totals']['update'] ?? -1 );
        $this->assertSame( 0, $result['totals']['create'] ?? -1 );
    }

    public function test_migrate_skips_disabled_mappings_unless_include_disabled_enabled(): void
    {
        $this->store_form_actions(
            'gravity_forms',
            105,
            [
                'map_disabled' => $this->build_master_mapping( 'map_disabled', 'entry_summary_v1', false ),
            ]
        );

        $client  = new Sentient_Forms_Test_Mappings_Migration_Api_Client();
        $service = new Sentient_Forms_Mappings_Migration_Service(
            $client,
            'proxy-migrate-test',
            '11111111-2222-4333-8444-555555555555'
        );

        $result = $service->migrate(
            [
                'apply'            => false,
                'include_disabled' => false,
                'form_source'      => 'gravity_forms',
                'form_id'          => 105,
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 1, $result['totals']['skip'] ?? -1 );
        $this->assertSame(
            'disabled_mapping',
            $result['forms'][0]['operations'][0]['reason'] ?? ''
        );
    }

    public function test_migrate_all_scope_discovers_multiple_form_options(): void
    {
        $this->store_form_actions(
            'gravity_forms',
            106,
            [ 'map_a' => $this->build_master_mapping( 'map_a', 'entry_summary_v1' ) ]
        );
        $this->store_form_actions(
            'gravity_forms',
            107,
            [ 'map_b' => $this->build_master_mapping( 'map_b', 'spam_detection_v1' ) ]
        );

        $client  = new Sentient_Forms_Test_Mappings_Migration_Api_Client();
        $service = new Sentient_Forms_Mappings_Migration_Service(
            $client,
            'proxy-migrate-test',
            '11111111-2222-4333-8444-555555555555'
        );

        $result = $service->migrate(
            [
                'apply'            => false,
                'include_disabled' => true,
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 2, count( $result['forms'] ) );
        $this->assertSame( 2, $result['totals']['create'] ?? -1 );
        $this->assertSame( 1, count( $client->get_calls ) );
    }

    public function test_migrate_all_scope_discovers_elementor_provider_native_option_key(): void
    {
        $option_key           = 'sentient_forms_actions_elementor_pro_forms_91_form-alpha_2026';
        $this->option_keys[] = $option_key;
        update_option(
            $option_key,
            [
                'map_elementor' => [
                    'local_mapping_id'           => 'map_elementor',
                    'central_action_id'          => 'entry_summary_v1',
                    'action_type_indicator'      => 'master',
                    'trigger_hooks'              => [ 'elementor_pro_forms_new_record' ],
                    'is_action_enabled_for_form' => true,
                    'execution_priority'         => 10,
                    'action_name_label'          => 'Entry Summary',
                    'settings'                   => [],
                ],
            ],
            false
        );

        $client  = new Sentient_Forms_Test_Mappings_Migration_Api_Client();
        $service = new Sentient_Forms_Mappings_Migration_Service(
            $client,
            'proxy-migrate-test',
            '11111111-2222-4333-8444-555555555555'
        );

        $result = $service->migrate(
            [
                'apply'            => false,
                'include_disabled' => true,
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 1, count( $result['forms'] ) );
        $this->assertSame( 'elementor_pro_forms', $result['forms'][0]['form_source'] ?? null );
        $this->assertSame( '91:form-alpha_2026', $result['forms'][0]['form_id'] ?? null );
        $this->assertSame( $option_key, $result['forms'][0]['option_key'] ?? null );
        $this->assertSame( 1, $result['totals']['create'] ?? -1 );
    }

    public function test_migrate_all_scope_decodes_canonical_provider_native_option_keys(): void
    {
        $form_ids = [
            '91:formabc',
            '91_formabc',
            '91.formabc',
        ];

        foreach ( $form_ids as $form_id )
        {
            $option_key           = 'sentient_forms_actions_elementor_pro_forms_' . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( $form_id );
            $this->option_keys[] = $option_key;
            update_option(
                $option_key,
                [
                    'map_' . md5( $form_id ) => $this->build_master_mapping( 'map_' . md5( $form_id ), 'entry_summary_v1' ),
                ],
                false
            );
        }

        $client  = new Sentient_Forms_Test_Mappings_Migration_Api_Client();
        $service = new Sentient_Forms_Mappings_Migration_Service(
            $client,
            'proxy-migrate-test',
            '11111111-2222-4333-8444-555555555555'
        );

        $result = $service->migrate(
            [
                'apply'            => false,
                'include_disabled' => true,
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 3, count( $result['forms'] ) );
        $this->assertEqualsCanonicalizing(
            $form_ids,
            array_map(
                static fn ( array $form ): string => (string) ( $form['form_id'] ?? '' ),
                $result['forms']
            )
        );
        $this->assertSame( 3, $result['totals']['create'] ?? -1 );
    }

    public function test_migrate_apply_supports_legacy_actions_wrapper_and_hooks_alias(): void
    {
        $option_key = sprintf( 'sentient_forms_actions_%s_%d', 'gravity_forms', 110 );
        $this->option_keys[] = $option_key;
        update_option(
            $option_key,
            [
                'enabled' => true,
                'actions' => [
                    'legacy_spam' => [
                        'central_action_id'     => 'spam_detection_v1',
                        'action_name_label'     => 'Legacy Spam',
                        'hooks'                 => [ 'gform_validation', 'gform_after_submission' ],
                        'enabled'               => 1,
                        'execution_priority'    => 9,
                    ],
                ],
            ],
            false
        );

        $client  = new Sentient_Forms_Test_Mappings_Migration_Api_Client();
        $service = new Sentient_Forms_Mappings_Migration_Service(
            $client,
            'proxy-migrate-test',
            '11111111-2222-4333-8444-555555555555'
        );

        $result = $service->migrate(
            [
                'apply'            => true,
                'include_disabled' => true,
                'form_source'      => 'gravity_forms',
                'form_id'          => 110,
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 1, $result['totals']['create'] ?? -1 );
        $this->assertSame( 0, $result['totals']['skip'] ?? -1 );
        $this->assertSame( 1, count( $client->post_calls ) );
        $this->assertSame(
            'legacy_spam',
            $client->post_calls[0]['payload']['settings']['local_mapping_id'] ?? ''
        );
        $this->assertSame(
            [ 'validation', 'after_submission' ],
            $client->post_calls[0]['payload']['settings']['trigger_hooks'] ?? []
        );
        $this->assertSame(
            true,
            $client->post_calls[0]['payload']['settings']['is_action_enabled_for_form'] ?? null
        );
    }

    public function test_migrate_apply_infers_site_id_from_remote_when_local_site_id_is_legacy(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'proxy_api_key' => 'proxy-migrate-test',
                'site_id'       => 'local-site',
            ]
        );
        update_option( 'sentient_forms_site_id', 'local-site' );

        $this->store_form_actions(
            'gravity_forms',
            108,
            [
                'map_new' => $this->build_master_mapping( 'map_new', 'spam_detection_v1' ),
            ]
        );

        $remote_site_id = '99999999-2222-4333-8444-aaaaaaaaaaaa';
        $client         = new Sentient_Forms_Test_Mappings_Migration_Api_Client(
            [
                $this->build_remote_record(
                    'aaaaaaaa-2222-4333-8444-111111111111',
                    $remote_site_id,
                    'gravity_forms',
                    77,
                    'map_existing_other_form'
                ),
            ]
        );
        $service        = new Sentient_Forms_Mappings_Migration_Service(
            $client,
            'proxy-migrate-test',
            'local-site'
        );

        $result = $service->migrate(
            [
                'apply'            => true,
                'include_disabled' => true,
                'form_source'      => 'gravity_forms',
                'form_id'          => 108,
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 1, $result['totals']['create'] ?? -1 );
        $this->assertSame( 0, $result['totals']['error'] ?? -1 );
        $this->assertSame( $remote_site_id, $result['site_id'] ?? '' );
        $this->assertSame( 1, count( $client->post_calls ) );
        $this->assertSame( $remote_site_id, $client->post_calls[0]['payload']['site_id'] ?? '' );
    }

    public function test_migrate_returns_error_when_site_id_missing_and_cannot_be_inferred(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'proxy_api_key' => 'proxy-migrate-test',
                'site_id'       => 'local-site',
            ]
        );
        update_option( 'sentient_forms_site_id', 'local-site' );

        $this->store_form_actions(
            'gravity_forms',
            109,
            [
                'map_new' => $this->build_master_mapping( 'map_new', 'spam_detection_v1' ),
            ]
        );

        $client  = new Sentient_Forms_Test_Mappings_Migration_Api_Client();
        $service = new Sentient_Forms_Mappings_Migration_Service(
            $client,
            'proxy-migrate-test',
            'local-site'
        );

        $result = $service->migrate(
            [
                'apply'            => true,
                'include_disabled' => true,
                'form_source'      => 'gravity_forms',
                'form_id'          => 109,
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'missing_site_id', $result->get_error_code() );
    }

    /**
     * @param array<string, mixed> $actions
     */
    private function store_form_actions( string $form_source, int $form_id, array $actions ): string
    {
        $option_key           = sprintf( 'sentient_forms_actions_%s_%d', sanitize_key( $form_source ), absint( $form_id ) );
        $this->option_keys[] = $option_key;
        update_option( $option_key, $actions, false );

        return $option_key;
    }

    /**
     * @return array<string, mixed>
     */
    private function build_master_mapping( string $local_mapping_id, string $central_action_id, bool $enabled = true ): array
    {
        return [
            'local_mapping_id'           => $local_mapping_id,
            'central_action_id'          => $central_action_id,
            'action_type_indicator'      => 'master',
            'trigger_hooks'              => [ 'gform_validation' ],
            'is_action_enabled_for_form' => $enabled,
            'execution_priority'         => 10,
            'action_name_label'          => ucwords( str_replace( '_', ' ', $central_action_id ) ),
            'settings'                   => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function build_remote_record(
        string $id,
        string $site_id,
        string $form_source,
        int $form_id,
        string $local_mapping_id
    ): array
    {
        return [
            'id'                   => $id,
            'site_id'              => $site_id,
            'form_source'          => $form_source,
            'form_id'              => $form_id,
            'action_template_id'   => null,
            'action_template_code' => 'entry_summary_v1',
            'custom_action_id'     => null,
            'display_name'         => 'Entry Summary',
            'settings'             => [
                'local_mapping_id'           => $local_mapping_id,
                'trigger_hooks'              => [ 'gform_validation' ],
                'is_action_enabled_for_form' => true,
            ],
            'is_template'          => false,
        ];
    }
}
