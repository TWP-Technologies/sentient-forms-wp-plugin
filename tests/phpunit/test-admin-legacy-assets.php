<?php
/**
 * Tests for legacy admin asset payloads.
 *
 * @package Sentient_Forms
 */

if ( ! class_exists( 'Sentient_Forms_Test_Legacy_Admin_Adapter' ) )
{
    final class Sentient_Forms_Test_Legacy_Admin_Adapter implements Sentient_Forms_Adapter_Interface
    {
        public function __construct(
            private readonly string $id,
            private readonly string $name,
            private readonly bool $active,
            private readonly array $forms,
        )
        {
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
            return $this->forms;
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
            return true;
        }

        public function mark_entry_as_spam( mixed $entry_id ): bool
        {
            return true;
        }

        public function reject_submission( mixed $entry_id, string $message ): bool
        {
            return true;
        }

        public function add_entry_note( mixed $entry_id, string $note_author, string $note_content ): bool
        {
            return true;
        }

        public function get_action_hook_for_event( string $event_name ): ?string
        {
            return null;
        }

        public function get_form_object( int $form_id ): object | array | null
        {
            return null;
        }
    }
}

class Tests_Admin_Legacy_Assets extends WP_UnitTestCase
{
    private Sentient_Forms_Form_Adapter_Registry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $this->registry->register_adapter(
            new Sentient_Forms_Test_Legacy_Admin_Adapter(
                'legacy_test_forms',
                'Legacy Test Forms',
                true,
                [
                    [
                        'id'       => 42,
                        'title'    => 'Legacy Contact',
                        'settings' => [
                            'enabled' => true,
                            'actions' => [
                                'spam_detection_v1' => [
                                    'enabled' => true,
                                    'hooks'   => [ 'gform_after_submission' ],
                                ],
                            ],
                        ],
                    ],
                ],
            )
        );
        $this->registry->register_adapter(
            new Sentient_Forms_Test_Legacy_Admin_Adapter(
                'legacy_inactive_forms',
                'Legacy Inactive Forms',
                false,
                [
                    [
                        'id'    => 99,
                        'title' => 'Inactive Form',
                    ],
                ],
            )
        );
    }

    protected function tearDown(): void
    {
        $this->remove_registered_adapter( 'legacy_test_forms' );
        $this->remove_registered_adapter( 'legacy_inactive_forms' );
        remove_all_filters( 'sentient_forms_elementor_is_active' );
        remove_all_filters( 'sentient_forms_elementor_pro_forms_api_available' );

        parent::tearDown();
    }

    public function test_legacy_admin_payload_includes_active_adapter_forms(): void
    {
        $admin  = new Sentient_Forms_Admin( Sentient_Forms_Plugin::instance() );
        $method = new ReflectionMethod( $admin, 'build_legacy_admin_payload' );
        $method->setAccessible( true );

        $payload = $method->invoke( $admin );
        $forms   = array_values(
            array_filter(
                $payload['forms'] ?? [],
                static fn( array $form ): bool => 'legacy_test_forms' === ( $form['adapter'] ?? '' )
            )
        );

        $this->assertCount( 1, $forms );
        $this->assertSame( 42, $forms[0]['id'] );
        $this->assertSame( 'Legacy Contact', $forms[0]['title'] );
        $this->assertSame( 'Legacy Test Forms', $forms[0]['adapter_name'] );
        $this->assertTrue( $forms[0]['settings']['enabled'] ?? false );
        $this->assertSame(
            [ 'gform_after_submission' ],
            $forms[0]['settings']['actions']['spam_detection_v1']['hooks'] ?? []
        );
    }

    public function test_legacy_admin_payload_excludes_inactive_adapter_forms(): void
    {
        $admin  = new Sentient_Forms_Admin( Sentient_Forms_Plugin::instance() );
        $method = new ReflectionMethod( $admin, 'build_legacy_admin_payload' );
        $method->setAccessible( true );

        $payload = $method->invoke( $admin );
        $forms   = array_values(
            array_filter(
                $payload['forms'] ?? [],
                static fn( array $form ): bool => 'legacy_inactive_forms' === ( $form['adapter'] ?? '' )
            )
        );

        $this->assertSame( [], $forms );
    }

    public function test_spa_bootstrap_payload_includes_elementor_requires_pro_state(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_false' );

        $admin  = new Sentient_Forms_Admin( Sentient_Forms_Plugin::instance() );
        $method = new ReflectionMethod( $admin, 'build_spa_bootstrap_payload' );
        $method->setAccessible( true );

        $payload   = $method->invoke( $admin );
        $elementor = null;
        foreach ( $payload['formSources'] ?? [] as $source )
        {
            if ( 'elementor_forms' === ( $source['slug'] ?? '' ) )
            {
                $elementor = $source;
                break;
            }
        }

        $this->assertIsArray( $elementor );
        $this->assertSame( 'Elementor Forms', $elementor['label'] );
        $this->assertFalse( $elementor['isActive'] );
        $this->assertSame( 'requires_pro', $elementor['availability'] ?? null );
        $this->assertStringContainsString( 'Elementor Pro Forms', $elementor['availabilityMessage'] ?? '' );
        $this->assertTrue( $elementor['requiresPro'] ?? false );
        $this->assertSame( 'requires_pro', $elementor['descriptor']['availability'] ?? null );
        $this->assertTrue( $elementor['descriptor']['requirements']['is_elementor_active'] ?? false );
        $this->assertFalse( $elementor['descriptor']['requirements']['is_pro_forms_api_available'] ?? true );
    }

    private function remove_registered_adapter( string $adapter_id ): void
    {
        $reflection = new ReflectionClass( $this->registry );
        $property   = $reflection->getProperty( 'adapters' );
        $property->setAccessible( true );

        $adapters = $property->getValue( $this->registry );
        unset( $adapters[ $adapter_id ] );
        $property->setValue( $this->registry, $adapters );
    }
}
