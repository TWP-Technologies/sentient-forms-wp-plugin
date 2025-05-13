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

        /**
         * Action hook to allow other plugins/themes to register their own adapters.
         *
         * @param Sentient_Forms_Form_Adapter_Registry $this The instance of the adapter registry.
         */
        do_action( 'sentient_forms_register_adapters', $this );
    }
}