<?php
/**
 * Sentient Forms Action Registry.
 * Manages the registration and retrieval of available actions.
 *
 * @package SentientForms
 * @since   0.1.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly.
}

/**
 * Sentient_Forms_Action_Registry Class.
 * Responsible for discovering, storing, and providing access to all registered action instances.
 *
 * @since 0.1.0
 */
class Sentient_Forms_Action_Registry
{
    /**
     * Holds the instantiated action objects.
     * Keyed by action ID.
     *
     * @var array<string, Sentient_Forms_Action_Interface>
     */
    private array $actions = [];

    /**
     * Reference to the main plugin instance, passed to actions.
     *
     * @var Sentient_Forms_Plugin
     */
    private Sentient_Forms_Plugin $plugin;

    /**
     * Constructor.
     *
     * @param Sentient_Forms_Plugin $plugin The main plugin instance.
     */
    public function __construct( Sentient_Forms_Plugin $plugin )
    {
        $this->plugin = $plugin;
        $this->discover_actions();
    }

    /**
     * Initializes and registers default actions.
     */
    private function discover_actions(): void
    {
        $this->register_action( new Sentient_Forms_Spam_Analysis_Action( $this->plugin ) );
        $this->register_action( new Sentient_Forms_Entry_Evaluation_Action( $this->plugin ) );

        /**
         * Action hook to allow other plugins/themes to register their own actions.
         *
         * @param Sentient_Forms_Action_Registry $this The instance of the action registry.
         */
        do_action( 'sentient_forms_register_actions', $this );
    }

    /**
     * Registers an action class.
     * The action is instantiated when first requested or when get_all_actions is called.
     *
     * @param Sentient_Forms_Action_Interface $action The action class to register.
     *
     * @return void
     */
    public function register_action( Sentient_Forms_Action_Interface $action ): void
    {
        $this->actions[ $action->get_id() ] = $action;
    }

    /**
     * Retrieves an instantiated action by its ID.
     *
     * @param string $action_id The ID of the action to retrieve.
     *
     * @return Sentient_Forms_Action_Interface|null The action instance or null if not found.
     */
    public function get_action( string $action_id ): ?Sentient_Forms_Action_Interface
    {
        if ( isset( $this->actions[ $action_id ] ) )
        {
            return $this->actions[ $action_id ];
        }

        return null;
    }

    /**
     * Retrieves all registered and instantiated actions.
     *
     * @return array<string, Sentient_Forms_Action_Interface> An array of action instances, keyed by their ID.
     */
    public function get_all_actions(): array
    {
        return $this->actions;
    }
}