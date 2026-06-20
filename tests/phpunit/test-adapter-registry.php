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
}
