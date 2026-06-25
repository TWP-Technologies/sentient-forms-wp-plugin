<?php

if ( ! class_exists( 'Sentient_Forms_Test_Form_Source_Adapter' ) )
{
    class Sentient_Forms_Test_Form_Source_Adapter implements Sentient_Forms_Adapter_Interface, Sentient_Forms_Async_Capable_Adapter_Interface
    {
        public function __construct(
            private string $id,
            private string $name,
            private bool $active,
            private array $descriptor
        ) {
        }

        public function get_id(): string
        {
            return $this->id;
        }

        public function get_name(): string
        {
            return $this->name;
        }

        public function is_active(): bool
        {
            return $this->active;
        }

        public function get_forms(): array
        {
            return [];
        }

        public function get_form_fields( $form_id ): array
        {
            return [];
        }

        public function get_entry_data( $entry_id, $form_id = null )
        {
            return null;
        }

        public function update_entry_meta( $entry_id, string $meta_key, $meta_value ): bool
        {
            return false;
        }

        public function mark_entry_as_spam( mixed $entry_id ): bool
        {
            return false;
        }

        public function reject_submission( mixed $entry_id, string $message ): bool
        {
            return false;
        }

        public function add_entry_note( mixed $entry_id, string $note_author, string $note_content ): bool
        {
            return false;
        }

        public function get_action_hook_for_event( string $event_name ): ?string
        {
            return null;
        }

        public function get_form_object( int $form_id ): object | array | null
        {
            return null;
        }

        public function get_capability_descriptor(): array
        {
            return $this->descriptor;
        }

        public function finalize_async_success( array $context, array $result ): void
        {
        }

        public function finalize_async_error( array $context, WP_Error $error ): void
        {
        }

        public function finalize_async_evaluation( array $context, array $result ): void
        {
        }
    }
}

class AdapterRegistryTest extends WP_UnitTestCase
{
    public function test_registered_adapters_are_async_capable(): void
    {
        $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $this->assertNotNull( $registry, 'Adapter registry should be initialised.' );

        foreach ( $registry->get_all_adapters() as $adapter )
        {
            $this->assertInstanceOf( Sentient_Forms_Adapter_Interface::class, $adapter );
            $this->assertInstanceOf(
                Sentient_Forms_Async_Capable_Adapter_Interface::class,
                $adapter,
                sprintf( '%s must implement async interface', $adapter::class )
            );
        }
    }

    public function test_registered_adapters_expose_capability_descriptors(): void
    {
        $registry = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
        $registry->register_adapter(
            new Sentient_Forms_Test_Form_Source_Adapter(
                'fake_source',
                'Fake Source',
                false,
                [
                    'availability' => 'inactive',
                    'availability_message' => 'Fake Source is installed but inactive.',
                    'forms_discovery' => [
                        'supported' => false,
                        'reason'    => 'Activate Fake Source to list forms.',
                    ],
                    'field_manifest' => [
                        'supported' => false,
                        'reason'    => 'Activate Fake Source to inspect fields.',
                    ],
                    'lifecycles' => [
                        'after_submission' => [
                            'supported'          => true,
                            'label'              => 'After submission',
                            'native_hook'        => 'fake_after_submission',
                            'execution_mode'     => 'async',
                            'requires_ledger'    => true,
                            'unsupported_reason' => null,
                        ],
                    ],
                    'native_entry' => [
                        'id'    => false,
                        'link'  => false,
                        'read'  => false,
                        'write' => false,
                    ],
                    'native_enrichment' => [
                        'notes'                  => false,
                        'status'                 => false,
                        'spam'                   => false,
                        'notification_controls'  => false,
                        'webhook_controls'       => false,
                    ],
                    'ledger' => [
                        'required_for_parity' => true,
                        'enabled'             => false,
                        'settings_source'     => 'sentient_submission_ledger_settings',
                        'unavailable_reason'  => 'Enable the Sentient Forms Submission Ledger to review Fake Source submissions.',
                    ],
                    'requirements' => [
                        'plugin' => 'fake-source/fake-source.php',
                    ],
                ]
            )
        );

        $descriptors = $registry->get_capability_descriptors();

        $this->assertArrayHasKey( 'fake_source', $descriptors );
        $this->assertSame( 'fake_source', $descriptors['fake_source']['slug'] );
        $this->assertSame( 'Fake Source', $descriptors['fake_source']['label'] );
        $this->assertTrue( $descriptors['fake_source']['is_registered'] );
        $this->assertFalse( $descriptors['fake_source']['is_active'] );
        $this->assertSame( 'inactive', $descriptors['fake_source']['availability'] );
        $this->assertTrue( $descriptors['fake_source']['lifecycles']['after_submission']['requires_ledger'] );
        $this->assertTrue( $descriptors['fake_source']['ledger']['required_for_parity'] );
    }

    public function test_unregister_adapter_removes_registered_adapter(): void
    {
        $registry = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
        $registry->register_adapter(
            new Sentient_Forms_Test_Form_Source_Adapter(
                'temporary_source',
                'Temporary Source',
                true,
                []
            )
        );

        $this->assertInstanceOf( Sentient_Forms_Adapter_Interface::class, $registry->get_adapter_by_id( 'temporary_source' ) );

        $registry->unregister_adapter( 'temporary_source' );

        $this->assertNull( $registry->get_adapter_by_id( 'temporary_source' ) );
        $this->assertArrayNotHasKey( 'temporary_source', $registry->get_all_adapters() );
    }

    public function test_gravity_forms_descriptor_uses_canonical_lifecycle_ids_with_native_hooks(): void
    {
        $registry   = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
        $descriptor = $registry->get_capability_descriptor( 'gravity_forms' );

        $this->assertIsArray( $descriptor );
        $this->assertSame( 'gravity_forms', $descriptor['slug'] );
        $this->assertSame( 'Gravity Forms', $descriptor['label'] );
        $this->assertArrayHasKey( 'validation', $descriptor['lifecycles'] );
        $this->assertArrayHasKey( 'after_submission', $descriptor['lifecycles'] );
        $this->assertArrayHasKey( 'real_time', $descriptor['lifecycles'] );
        $this->assertSame( 'gform_validation', $descriptor['lifecycles']['validation']['native_hook'] );
        $this->assertSame( 'gform_after_submission', $descriptor['lifecycles']['after_submission']['native_hook'] );
        $this->assertSame( 'real_time', $descriptor['lifecycles']['real_time']['native_hook'] );
        $this->assertFalse( $descriptor['ledger']['required_for_parity'] );
        $this->assertTrue( $descriptor['native_entry']['id'] );
        $this->assertTrue( $descriptor['native_enrichment']['notes'] );
        $this->assertTrue( $descriptor['native_enrichment']['spam'] );
    }

    public function test_contact_form_7_absent_descriptor_requires_ledger_and_hides_gravity_only_capabilities(): void
    {
        $registry   = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
        $descriptor = $registry->get_capability_descriptor( 'contact_form_7' );

        $this->assertIsArray( $descriptor );
        $this->assertSame( 'contact_form_7', $descriptor['slug'] );
        $this->assertSame( 'Contact Form 7', $descriptor['label'] );
        $this->assertFalse( $descriptor['is_active'] );
        $this->assertSame( 'not_installed', $descriptor['availability'] );
        $this->assertFalse( $descriptor['forms_discovery']['supported'] );
        $this->assertFalse( $descriptor['field_manifest']['supported'] );
        $this->assertTrue( $descriptor['lifecycles']['after_submission']['supported'] );
        $this->assertSame( 'wpcf7_mail_sent', $descriptor['lifecycles']['after_submission']['native_hook'] );
        $this->assertTrue( $descriptor['lifecycles']['after_submission']['requires_ledger'] );
        $this->assertFalse( $descriptor['lifecycles']['validation']['supported'] );
        $this->assertFalse( $descriptor['lifecycles']['real_time']['supported'] );
        $this->assertFalse( $descriptor['native_entry']['id'] );
        $this->assertFalse( $descriptor['native_entry']['link'] );
        $this->assertFalse( $descriptor['native_enrichment']['notes'] );
        $this->assertFalse( $descriptor['native_enrichment']['spam'] );
        $this->assertTrue( $descriptor['ledger']['required_for_parity'] );
        $this->assertFalse( $descriptor['ledger']['enabled'] );
    }

    public function test_contact_form_7_is_supported_form_source_slug(): void
    {
        $this->assertTrue( Sentient_Forms_Form_Sources::is_supported_source( 'contact_form_7' ) );
    }

    public function test_wpforms_is_supported_form_source_slug(): void
    {
        $this->assertTrue( Sentient_Forms_Form_Sources::is_supported_source( 'wpforms' ) );
    }

    public function test_wpforms_active_discovers_published_forms_from_wpforms_posts(): void
    {
        $active_filter = static fn(): bool => true;
        add_filter( 'sentient_forms_wpforms_is_active', $active_filter );

        $published_form_id = self::factory()->post->create(
            [
                'post_type'    => 'wpforms',
                'post_status'  => 'publish',
                'post_title'   => 'Partner Intake',
                'post_content' => wp_json_encode(
                    [
                        'settings' => [
                            'form_title' => 'Partner Intake',
                        ],
                        'fields'   => [],
                    ]
                ),
            ]
        );

        self::factory()->post->create(
            [
                'post_type'    => 'wpforms',
                'post_status'  => 'draft',
                'post_title'   => 'Draft Intake',
                'post_content' => wp_json_encode(
                    [
                        'settings' => [
                            'form_title' => 'Draft Intake',
                        ],
                        'fields'   => [],
                    ]
                ),
            ]
        );

        try
        {
            $registry = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
            $adapter  = $registry->get_adapter_by_id( 'wpforms' );

            $this->assertInstanceOf( Sentient_Forms_WPForms_Adapter::class, $adapter );

            $forms = $adapter->get_forms();

            $this->assertCount( 1, $forms );
            $this->assertSame( $published_form_id, $forms[0]['id'] );
            $this->assertSame( 'Partner Intake', $forms[0]['title'] );
            $this->assertSame( 'wpforms', $forms[0]['adapter'] );
            $this->assertSame( 'WPForms', $forms[0]['adapter_name'] );
            $this->assertTrue( $forms[0]['provider_is_active'] );
            $this->assertSame(
                admin_url( 'admin.php?page=wpforms-builder&view=fields&form_id=' . $published_form_id ),
                $forms[0]['provider_edit_url']
            );
            $this->assertNull( $forms[0]['settings'] );
        }
        finally
        {
            remove_filter( 'sentient_forms_wpforms_is_active', $active_filter );
        }
    }

    public function test_wpforms_absent_descriptor_requires_ledger_and_hides_gravity_only_capabilities(): void
    {
        $registry   = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
        $descriptor = $registry->get_capability_descriptor( 'wpforms' );

        $this->assertIsArray( $descriptor );
        $this->assertSame( 'wpforms', $descriptor['slug'] );
        $this->assertSame( 'WPForms', $descriptor['label'] );
        $this->assertFalse( $descriptor['is_active'] );
        $this->assertSame( 'not_installed', $descriptor['availability'] );
        $this->assertFalse( $descriptor['forms_discovery']['supported'] );
        $this->assertFalse( $descriptor['field_manifest']['supported'] );
        $this->assertTrue( $descriptor['lifecycles']['after_submission']['supported'] );
        $this->assertSame( 'wpforms_process_complete', $descriptor['lifecycles']['after_submission']['native_hook'] );
        $this->assertTrue( $descriptor['lifecycles']['after_submission']['requires_ledger'] );
        $this->assertFalse( $descriptor['lifecycles']['validation']['supported'] );
        $this->assertFalse( $descriptor['lifecycles']['real_time']['supported'] );
        $this->assertFalse( $descriptor['native_entry']['id'] );
        $this->assertFalse( $descriptor['native_entry']['link'] );
        $this->assertFalse( $descriptor['native_enrichment']['notes'] );
        $this->assertFalse( $descriptor['native_enrichment']['spam'] );
        $this->assertTrue( $descriptor['ledger']['required_for_parity'] );
        $this->assertFalse( $descriptor['ledger']['enabled'] );
    }

    public function test_wpforms_active_descriptor_reports_native_entry_links_only_for_verified_paid_storage(): void
    {
        $active_filter = static fn(): bool => true;
        $native_filter = static fn(): bool => true;

        add_filter( 'sentient_forms_wpforms_is_active', $active_filter );
        add_filter( 'sentient_forms_wpforms_native_entry_storage_available', $native_filter );

        try
        {
            $registry   = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
            $descriptor = $registry->get_capability_descriptor( 'wpforms' );

            $this->assertIsArray( $descriptor );
            $this->assertSame( 'wpforms', $descriptor['slug'] );
            $this->assertTrue( $descriptor['is_active'] );
            $this->assertSame( 'available', $descriptor['availability'] );
            $this->assertTrue( $descriptor['forms_discovery']['supported'] );
            $this->assertTrue( $descriptor['field_manifest']['supported'] );
            $this->assertTrue( $descriptor['lifecycles']['after_submission']['supported'] );
            $this->assertTrue( $descriptor['lifecycles']['after_submission']['requires_ledger'] );
            $this->assertFalse( $descriptor['lifecycles']['validation']['supported'] );
            $this->assertFalse( $descriptor['lifecycles']['real_time']['supported'] );
            $this->assertTrue( $descriptor['native_entry']['id'] );
            $this->assertTrue( $descriptor['native_entry']['link'] );
            $this->assertFalse( $descriptor['native_entry']['read'] );
            $this->assertFalse( $descriptor['native_entry']['write'] );
            $this->assertFalse( $descriptor['native_enrichment']['notes'] );
            $this->assertFalse( $descriptor['native_enrichment']['spam'] );
            $this->assertTrue( $descriptor['ledger']['required_for_parity'] );
        }
        finally
        {
            remove_filter( 'sentient_forms_wpforms_is_active', $active_filter );
            remove_filter( 'sentient_forms_wpforms_native_entry_storage_available', $native_filter );
        }
    }
}
