<?php
/**
 * Sentient Forms Adapter Registry.
 * Manages the registration and retrieval of form provider adapters.
 * This class acts as a central point for accessing different form adapters
 * like Gravity Forms, WPForms, etc.
 *
 * @package    SentientForms
 * @subpackage Adapters/Forms
 * @since      0.1.0
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Adapter_Registry
 * Responsible for discovering, storing, and providing access to instances
 * of available form adapter classes that implement Sentient_Forms_Adapter_Interface.
 */
class Sentient_Forms_Form_Adapter_Registry
{

    /**
     * Array of registered adapter instances.
     * The keys are the adapter IDs (from $adapter->get_id()).
     *
     * @var Sentient_Forms_Adapter_Interface[]
     */
    private array $adapters = [];

    /**
     * Reference to the main plugin instance.
     * This is passed to each adapter during registration.
     *
     * @var Sentient_Forms_Plugin
     */
    private Sentient_Forms_Plugin $plugin;

    /**
     * Constructor.
     * Initializes the adapter registry. Adapters can be registered
     * during construction or later via specific methods.
     */
    public function __construct( $plugin )
    {
        $this->plugin = $plugin;
        $this->discover_adapters();
    }

    /**
     * Registers a form adapter.
     * Adds a valid adapter instance to the registry.
     *
     * @param Sentient_Forms_Adapter_Interface $adapter The adapter instance to register.
     *
     * @return void
     */
    public function register_adapter( Sentient_Forms_Adapter_Interface $adapter ): void
    {
        $this->adapters[ $adapter->get_id() ] = $adapter;
    }

    /**
     * Unregisters a form adapter by ID.
     *
     * @param string $id The unique ID of the adapter to unregister.
     *
     * @return void
     */
    public function unregister_adapter( string $id ): void
    {
        unset( $this->adapters[ $id ] );
    }

    /**
     * Retrieves a specific adapter by its ID.
     *
     * @param string $id The unique ID of the adapter (e.g., 'gravity_forms').
     *
     * @return Sentient_Forms_Adapter_Interface|null The adapter instance, or null if not found.
     */
    public function get_adapter_by_id( string $id ): ?Sentient_Forms_Adapter_Interface
    {
        return $this->adapters[ $id ] ?? null;
    }

    /**
     * Retrieves all registered adapters, regardless of their active status.
     *
     * @return Sentient_Forms_Adapter_Interface[] An array of all registered adapter instances.
     */
    public function get_all_adapters(): array
    {
        return $this->adapters;
    }

    /**
     * Retrieve capability descriptors for every registered Form Source.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_capability_descriptors(): array
    {
        $descriptors = [];

        foreach ( $this->adapters as $adapter )
        {
            $descriptors[ $adapter->get_id() ] = $this->normalize_capability_descriptor( $adapter );
        }

        return $descriptors;
    }

    /**
     * Retrieve one registered Form Source capability descriptor.
     *
     * @return array<string, mixed>|null
     */
    public function get_capability_descriptor( string $id ): ?array
    {
        $adapter = $this->get_adapter_by_id( $id );

        return $adapter ? $this->normalize_capability_descriptor( $adapter ) : null;
    }

    /**
     * Retrieves all active adapters.
     * An adapter is considered active if its corresponding form plugin
     * (e.g., Gravity Forms plugin) is active on the site.
     *
     * @param null|bool $is_active Optional. If true, only active adapters are returned; false for inactive.
     *
     * @return Sentient_Forms_Adapter_Interface[] An array of active adapter instances.
     */
    public function get_adapters( null | bool $is_active = null ): array
    {
        if ( $is_active !== null )
        {
            return array_filter(
                $this->adapters,
                fn( $adapter ) => $adapter->is_active() === $is_active,
            );
        }

        return $this->adapters;
    }

    /**
     * @return void
     */
    private function discover_adapters(): void
    {
        $this->register_adapter( new Sentient_Forms_Gravity_Forms_Adapter( $this->plugin ) );
        $this->register_adapter( new Sentient_Forms_Contact_Form_7_Adapter( $this->plugin ) );
        $this->register_adapter( new Sentient_Forms_WPForms_Adapter( $this->plugin ) );
        $this->register_adapter( new Sentient_Forms_Elementor_Forms_Adapter( $this->plugin ) );

        /**
         * Action hook to allow other plugins/themes to register their own adapters.
         *
         * @param Sentient_Forms_Form_Adapter_Registry $this The instance of the adapter registry.
         */
        do_action( 'sentient_forms_register_adapters', $this );
    }

    /**
     * Normalize adapter descriptors so future adapters receive one stable shape.
     *
     * @return array<string, mixed>
     */
    private function normalize_capability_descriptor( Sentient_Forms_Adapter_Interface $adapter ): array
    {
        $provided = method_exists( $adapter, 'get_capability_descriptor' )
            ? $adapter->get_capability_descriptor()
            : [];

        if ( ! is_array( $provided ) )
        {
            $provided = [];
        }

        $is_active    = $adapter->is_active();
        $availability = sanitize_key( (string) ( $provided['availability'] ?? ( $is_active ? 'available' : 'inactive' ) ) );

        return [
            'slug'                 => sanitize_key( (string) ( $provided['slug'] ?? $adapter->get_id() ) ),
            'label'                => sanitize_text_field( (string) ( $provided['label'] ?? $adapter->get_name() ) ),
            'is_registered'        => true,
            'is_active'            => $is_active,
            'availability'         => $availability,
            'availability_message' => sanitize_text_field( (string) ( $provided['availability_message'] ?? '' ) ),
            'forms_discovery'      => $this->normalize_support_descriptor( $provided['forms_discovery'] ?? [], $is_active ),
            'field_manifest'       => $this->normalize_support_descriptor( $provided['field_manifest'] ?? [], $is_active ),
            'lifecycles'           => $this->normalize_lifecycle_descriptors( $provided['lifecycles'] ?? [] ),
            'native_entry'         => $this->normalize_boolean_capabilities(
                $provided['native_entry'] ?? [],
                [ 'id', 'link', 'read', 'write' ]
            ),
            'native_enrichment'    => $this->normalize_boolean_capabilities(
                $provided['native_enrichment'] ?? [],
                [ 'notes', 'status', 'spam', 'notification_controls', 'webhook_controls' ]
            ),
            'ledger'               => array_merge(
                [
                    'required_for_parity' => false,
                    'enabled'             => false,
                    'settings_source'     => 'sentient_submission_ledger_settings',
                    'unavailable_reason'  => null,
                ],
                is_array( $provided['ledger'] ?? null ) ? $provided['ledger'] : []
            ),
            'requirements'         => is_array( $provided['requirements'] ?? null ) ? $provided['requirements'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize_support_descriptor( mixed $value, bool $default_supported ): array
    {
        $value = is_array( $value ) ? $value : [];

        return [
            'supported' => array_key_exists( 'supported', $value ) ? (bool) $value['supported'] : $default_supported,
            'reason'    => isset( $value['reason'] ) ? sanitize_text_field( (string) $value['reason'] ) : null,
        ];
    }

    /**
     * @param array<string, mixed> $lifecycles
     *
     * @return array<string, array<string, mixed>>
     */
    private function normalize_lifecycle_descriptors( mixed $lifecycles ): array
    {
        if ( ! is_array( $lifecycles ) )
        {
            return [];
        }

        $normalized = [];
        foreach ( $lifecycles as $id => $descriptor )
        {
            if ( ! is_array( $descriptor ) )
            {
                continue;
            }

            $lifecycle_id = sanitize_key( (string) $id );
            if ( '' === $lifecycle_id )
            {
                continue;
            }

            $normalized[ $lifecycle_id ] = [
                'supported'          => (bool) ( $descriptor['supported'] ?? false ),
                'label'              => sanitize_text_field( (string) ( $descriptor['label'] ?? ucwords( str_replace( '_', ' ', $lifecycle_id ) ) ) ),
                'native_hook'        => isset( $descriptor['native_hook'] ) ? sanitize_text_field( (string) $descriptor['native_hook'] ) : null,
                'execution_mode'     => sanitize_key( (string) ( $descriptor['execution_mode'] ?? 'sync' ) ),
                'requires_ledger'    => (bool) ( $descriptor['requires_ledger'] ?? false ),
                'unsupported_reason' => isset( $descriptor['unsupported_reason'] ) ? sanitize_text_field( (string) $descriptor['unsupported_reason'] ) : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array<string, bool>
     */
    private function normalize_boolean_capabilities( mixed $value, array $keys ): array
    {
        $value      = is_array( $value ) ? $value : [];
        $normalized = [];

        foreach ( $keys as $key )
        {
            $normalized[ $key ] = (bool) ( $value[ $key ] ?? false );
        }

        return $normalized;
    }
}
