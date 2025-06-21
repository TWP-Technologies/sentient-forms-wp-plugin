<?php
/**
 * Sentient Forms Admin Area.
 * Handles all administrative functionalities for the Sentient Forms plugin,
 * including settings pages, admin notices, AJAX handlers, and script/style enqueuing.
 *
 * @package    SentientForms
 * @subpackage Admin
 * @since      0.1.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly.
}

/**
 * Class Sentient_Forms_Admin
 * Manages the WordPress admin interface components for Sentient Forms.
 */
class Sentient_Forms_Admin
{

    /**
     * Reference to the main plugin instance.
     * This allows the admin class to access plugin-wide settings and registries.
     *
     * @var Sentient_Forms_Plugin
     * @access private
     */
    private Sentient_Forms_Plugin $plugin;

    /**
     * Constructor.
     * Stores a reference to the main plugin instance.
     *
     * @param Sentient_Forms_Plugin $plugin The main plugin instance.
     */
    public function __construct( Sentient_Forms_Plugin $plugin )
    {
        $this->plugin = $plugin;
    }

    /**
     * Initialize admin hooks and functionalities.
     * This method is called by the main plugin class to set up
     * admin menus, enqueue scripts, and register AJAX handlers.
     */
    public function init(): void
    {
        // Add WordPress admin menu pages.
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );

        // Enqueue admin-specific scripts and styles.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

        // Add plugin action links (e.g., "Settings") on the plugins page.
        if ( defined( 'SENTIENT_FORMS_PLUGIN_FILE' ) )
        {
            add_filter( 'plugin_action_links_' . plugin_basename( SENTIENT_FORMS_PLUGIN_FILE ), [ $this, 'plugin_action_links' ] );
        }

        // Register AJAX handlers for various admin operations.
        // These handle saving settings, fetching data for UI components, etc.
        add_action( 'wp_ajax_sentient_forms_save_settings', [ $this, 'ajax_save_settings' ] ); // General settings
        add_action( 'wp_ajax_sentient_forms_get_forms_for_provider', [ $this, 'ajax_get_forms_for_provider' ] );
        add_action( 'wp_ajax_sentient_forms_get_actions_for_form', [ $this, 'ajax_get_actions_for_form' ] );
        // Corrected AJAX action hook for saving form settings (was ajax_save_form_actions, now matches JS call)
        add_action( 'wp_ajax_sentient_forms_save_form_settings', [ $this, 'ajax_save_form_settings' ] );
        add_action( 'wp_ajax_sentient_forms_get_action_settings_html', [ $this, 'ajax_get_action_settings_html' ] );
        add_action( 'wp_ajax_sentient_forms_test_connection', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_sentient_forms_get_credit_balance', [ $this, 'ajax_get_credit_balance' ] );


        // Display admin notices, e.g., for missing API keys or license issues.
        if ( empty( $this->plugin->get_proxy_api_key() ) )
        {
            add_action( 'admin_notices', [ $this, 'admin_notice_missing_api_key' ] );
        }

        // Check if license is managed by this plugin (e.g. Pro version)
        // This check might need adjustment based on how license status is determined for Pro features.
        // For now, we assume get_license_status() is reliable.
        if ( method_exists($this->plugin, 'get_license_status') && $this->plugin->get_license_status() !== 'valid' ) {
            // Ensure this notice doesn't show if licensing isn't part of this plugin version.
            // A more robust check might involve checking if a Pro version constant is defined.
            if (defined('SENTIENT_FORMS_PRO_VERSION')) { // Example conditional check
                add_action( 'admin_notices', [ $this, 'admin_notice_invalid_license' ] );
            }
        }
    }

    /**
     * Add admin menu pages for Sentient Forms.
     * Creates the main menu item and sub-menu pages for plugin settings,
     * form configurations, etc.
     */
    public function admin_menu(): void
    {
        // Main menu page
        add_menu_page(
            __( 'Sentient Forms', 'sentient-forms' ), // Page title
            __( 'Sentient Forms', 'sentient-forms' ), // Menu title
            'manage_options',                         // Capability required
            'sentient-forms',                         // Menu slug
            [ $this, 'render_dashboard_tab' ],        // Callback function to display the page content
            'dashicons-brain',                        // Icon URL or dashicon class
            75,                                        // Position in menu order
        );

        // Submenu page for Dashboard (can be the same as main if desired, or a dedicated one)
        add_submenu_page(
            'sentient-forms',
            __( 'Dashboard', 'sentient-forms' ),
            __( 'Dashboard', 'sentient-forms' ),
            'manage_options',
            'sentient-forms', // Slug for the dashboard, same as parent
            [ $this, 'render_dashboard_tab' ],
        );

        // Submenu page for Forms Configuration
        add_submenu_page(
            'sentient-forms',                              // Parent slug
            __( 'Forms Configuration', 'sentient-forms' ), // Page title
            __( 'Forms', 'sentient-forms' ),               // Menu title (changed for brevity)
            'manage_options',                              // Capability
            'sentient-forms-form-config',                  // Menu slug for this submenu item
            [ $this, 'render_forms_tab' ],                 // Callback function
        );

        // Submenu page for Actions
        add_submenu_page(
            'sentient-forms',
            __( 'Actions', 'sentient-forms' ),
            __( 'Actions', 'sentient-forms' ),
            'manage_options',
            'sentient-forms-actions',
            [ $this, 'render_actions_tab' ]
        );


        // Submenu page for general Settings
        add_submenu_page(
            'sentient-forms',
            __( 'Settings', 'sentient-forms' ),
            __( 'Settings', 'sentient-forms' ),
            'manage_options',
            'sentient-forms-settings', // Slug for settings
            [ $this, 'render_settings_tab' ],
        );

        // Submenu page for License
        add_submenu_page(
            'sentient-forms',
            __( 'License', 'sentient-forms' ),
            __( 'License', 'sentient-forms' ),
            'manage_options',
            'sentient-forms-license', // Slug for license
            [ $this, 'render_license_tab' ],
        );
    }

    /**
     * Enqueue admin scripts and styles.
     * Loads CSS and JavaScript files needed for the plugin's admin pages.
     *
     * @param string $hook_suffix The current admin page hook. Used to conditionally load assets.
     */
    public function enqueue_scripts( string $hook_suffix ): void
    {
        // Only load assets on Sentient Forms admin pages.
        if ( !str_contains( $hook_suffix, 'sentient-forms' ) )
        {
            return;
        }

        // Enqueue admin CSS (ensure SENTIENT_FORMS_URL and SENTIENT_FORMS_VERSION are defined).
        if ( defined( 'SENTIENT_FORMS_PLUGIN_URL' ) && defined( 'SENTIENT_FORMS_VERSION' ) )
        {
            wp_enqueue_style(
                'sentient-forms-admin-css',
                SENTIENT_FORMS_PLUGIN_URL . 'assets/css/admin.css',
                [ 'wp-admin', 'wp-components' ], // Added wp-components for potential future use with React/Gutenberg style components
                SENTIENT_FORMS_VERSION,
            );

            // Enqueue admin JavaScript.
            wp_enqueue_script(
                'sentient-forms-admin-js',
                SENTIENT_FORMS_PLUGIN_URL . 'assets/js/admin.js',
                [ 'jquery', 'jquery-ui-sortable', 'wp-util', 'jquery-ui-tooltip' ], // Added jquery-ui-tooltip based on error message
                SENTIENT_FORMS_VERSION,
                true, // Load in footer
            );

            // Prepare data for JavaScript localization
            $js_data_for_admin = [
                'apiBaseUrl' => rest_url( 'sentient-forms/v1/' ),
                'rest_nonce' => wp_create_nonce( 'wp_rest' ),
                'ajax_nonce' => wp_create_nonce( 'sentient_forms_admin_nonce' ),
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'i18n'       => [
                    'errorOccurred'  => __( 'An error occurred. Please try again.', 'sentient-forms' ),
                    'unsavedChanges' => __( 'You have unsaved changes. Are you sure you want to leave?', 'sentient-forms' ),
                    'savingSettings' => __( 'Saving settings...', 'sentient-forms'),
                    'settingsFailed' => __( 'Failed to save settings: ', 'sentient-forms'),
                    'settingsError' => __( 'An error occurred while saving settings.', 'sentient-forms'),
                    'apiKeyRequired' => __('API Key is required to test connection.', 'sentient-forms'),
                    'testingConnection' => __('Testing connection...', 'sentient-forms'),
                    'connectionFailed' => __('Connection failed: ', 'sentient-forms'),
                    'connectionError' => __('An error occurred during the connection test.', 'sentient-forms'),
                    'confirmReset' => __('Are you sure you want to reset all settings to their defaults? This cannot be undone.', 'sentient-forms'),
                    'confirmDeactivate' => __('Are you sure you want to deactivate your license?', 'sentient-forms'),
                    'checking' => __('Checking...', 'sentient-forms'),
                    'loadingBalance' => __('Loading balance...', 'sentient-forms'),
                    'balanceError' => __('Could not retrieve credit balance.', 'sentient-forms'),
                ],
            ];

            // Localize the main admin script with general data.
            // Changed 'sentientFormsAdminData' to 'sentientFormsAdmin' to match JS usage.
            wp_localize_script( 'sentient-forms-admin-js', 'sentientFormsAdmin', $js_data_for_admin );


            // Localize data specifically for the Forms Configuration page
            // The hook suffix for submenu pages is typically 'toplevel_page_{main_menu_slug}' for the main page,
            // and '{parent_slug}_page_{submenu_slug}' for actual submenus.
            // For "Forms Configuration" under "sentient-forms", it should be 'sentient-forms_page_sentient-forms-form-config'.
            if ( 'sentient-forms_page_sentient-forms-form-config' === $hook_suffix ) {
                $form_adapter_registry = $this->plugin->get_form_adapter_registry();
                $forms_data_for_js = [];

                if ($form_adapter_registry) {
                    $form_providers = $form_adapter_registry->get_adapters(true); // Get only active adapters
                    if (!empty($form_providers)) {
                        $all_forms_from_providers = array_map(
                            static fn(Sentient_Forms_Adapter_Interface $provider) => $provider->get_forms(),
                            $form_providers
                        );
                        // Filter out any empty results from get_forms() if a provider has no forms
                        $all_forms_from_providers = array_filter($all_forms_from_providers);

                        if (!empty($all_forms_from_providers)) {
                            // Flatten the array of arrays if get_forms() returns arrays of forms for each provider
                            $forms_data_for_js = array_reduce(
                                $all_forms_from_providers,
                                static fn($carry, $item) => array_merge($carry, is_array($item) ? $item : []),
                                []
                            );
                        }
                    }
                }

                // Ensure the sentientFormsFormsData object contains the 'forms' array as expected by admin.js
                // The admin.js script (line 132) expects sentientFormsAdmin.forms,
                // but the error was sentientFormsFormsData is not defined (line 320 of forms.php inline script).
                // The inline script in forms.php expects `sentientFormsFormsData` to be the array of forms.
                wp_localize_script(
                    'sentient-forms-admin-js',
                    'sentientFormsFormsData', // This is for the inline script in forms.php
                    $forms_data_for_js
                );

                // Additionally, if admin.js (external file) also needs this list under sentientFormsAdmin.forms:
                // We need to add it to the $js_data_for_admin array before it's localized.
                // However, looking at admin.js, it seems to expect `sentientFormsAdmin.forms`
                // Let's ensure `sentientFormsAdmin` (formerly `sentientFormsAdminData`) includes this.
                // The click handler in admin.js (line 129) uses `sentientFormsAdmin.forms`.
                // The inline script in forms.php (line 320) uses `sentientFormsFormsData`. Both need to be correct.

                // To make `sentientFormsAdmin.forms` available in admin.js:
                $js_data_for_admin_with_forms = $js_data_for_admin; // Start with the general data
                $js_data_for_admin_with_forms['forms'] = $forms_data_for_js; // Add the forms list

                // Re-localize with the added forms data if this specific page is loaded.
                // This will overwrite the previous localization of `sentientFormsAdmin` for this page only,
                // adding the 'forms' key.
                wp_localize_script( 'sentient-forms-admin-js', 'sentientFormsAdmin', $js_data_for_admin_with_forms );

            }
        }
    }


    /**
     * Add plugin action links to the plugins page.
     * Adds a "Settings" link next to the "Activate/Deactivate" links.
     *
     * @param array $links Existing plugin action links.
     *
     * @return array Modified plugin action links.
     */
    public function plugin_action_links( array $links ): array
    {
        $admin_url     = esc_url( admin_url( 'admin.php?page=sentient-forms-settings' ) );
        $settings_text = esc_html__( 'Settings', 'sentient-forms' );
        $settings_link = "<a href='$admin_url' class='sentient-forms-settings-link'>$settings_text</a>";

        array_unshift( $links, $settings_link ); // Add to the beginning of the links array.
        return $links;
    }

    /**
     * Render the Forms configuration tab.
     * This method is responsible for displaying the UI where users can
     * select a form provider (e.g., Gravity Forms), choose a specific form,
     * and configure Sentient Forms actions for it.
     */
    public function render_forms_tab(): void
    {
        // Data for the view is prepared here or directly in the view file.
        // For example, getting available form adapters and actions.
        $form_adapter_registry = $this->plugin->get_form_adapter_registry();
        $action_registry = $this->plugin->get_action_registry();

        $active_form_adapters = $form_adapter_registry ? $form_adapter_registry->get_adapters(true) : [];
        $all_actions = $action_registry ? $action_registry->get_all_actions() : [];


        // The actual HTML/UI for this tab is typically in a separate view file.
        $view_file = SENTIENT_FORMS_PLUGIN_DIR . 'includes/admin/views/forms.php';

        if ( file_exists( $view_file ) )
        {
            // Make variables available to the view file.
            // This is one way; alternatively, pass them as arguments if the view is a function.
            $data_for_view = [
                'plugin' => $this->plugin, // Pass the main plugin instance
                'active_form_adapters' => $active_form_adapters,
                'all_actions' => $all_actions,
                // The $forms variable will be generated within forms.php itself now
            ];
            extract($data_for_view); // Make array keys into variables

            include $view_file;
        }
        else
        {
            echo sprintf(
                '<div class="wrap"><div id="message" class="error"><p>%s%s</p></div></div>',
                esc_html__( 'Forms configuration view file is missing. Expected at: ', 'sentient-forms' ),
                esc_html( $view_file ),
            );
        }
    }

    /**
     * Render the Dashboard tab.
     */
    public function render_dashboard_tab(): void
    {
        $view_file = SENTIENT_FORMS_PLUGIN_DIR . 'includes/admin/views/dashboard.php';
        if ( file_exists( $view_file ) )
        {
            // Prepare data for the dashboard view
            $credit_balance = get_option('sentient_forms_credit_balance', 0); // Example
            $license_status = $this->plugin->get_license_status();
            $license_data = get_option('sentient_forms_license_data', []); // Example

            $form_adapter_registry = $this->plugin->get_form_adapter_registry();
            $form_count = 0;
            if ($form_adapter_registry) {
                $adapters = $form_adapter_registry->get_adapters(true);
                foreach ($adapters as $adapter) {
                    $form_count += count($adapter->get_forms());
                }
            }

            $action_registry = $this->plugin->get_action_registry();
            $actions = $action_registry ? $action_registry->get_all_actions() : [];


            // Pass data to the view
            extract(compact('credit_balance', 'license_status', 'license_data', 'form_count', 'actions'));

            include $view_file;
        }
        else
        {
            echo '<div class="wrap"><h1>' .
                 esc_html__( 'Sentient Forms Dashboard', 'sentient-forms' ) .
                 '</h1><p>' .
                 esc_html__( 'Welcome to Sentient Forms!', 'sentient-forms' ) .
                 '</p></div>';
        }
    }

    /**
     * Render the Actions tab.
     */
    public function render_actions_tab(): void {
        $view_file = SENTIENT_FORMS_PLUGIN_DIR . 'includes/admin/views/actions.php';
        if ( file_exists( $view_file ) ) {
            $action_registry = $this->plugin->get_action_registry();
            $actions         = $action_registry ? $action_registry->get_all_actions() : [];

            extract( compact( 'actions' ) ); // Make $actions available to the view
            include $view_file;
        } else {
            echo '<div class="wrap"><h1>' .
                 esc_html__( 'Sentient Forms Actions', 'sentient-forms' ) .
                 '</h1><p>' .
                 esc_html__( 'Actions view file is missing.', 'sentient-forms' ) .
                 '</p></div>';
        }
    }


    /**
     * Render the Settings tab.
     */
    public function render_settings_tab(): void
    {
        $view_file = SENTIENT_FORMS_PLUGIN_DIR . 'includes/admin/views/settings.php';
        if ( file_exists( $view_file ) )
        {
            // Pass options to the view
            $options = $this->plugin->get_options(); // This gets all 'sentient_forms_settings'
            $llm_registry = $this->plugin->get_llm_model_registry();
            $all_llm_models = $llm_registry ? $llm_registry->get_models([Sentient_Forms_Llm_Status::ACTIVE, Sentient_Forms_Llm_Status::PREVIEW]) : [];

            // Extract specific values needed by the view, with defaults
            $api_key = $options['api_key'] ?? '';
            $default_llm_id = $options['default_llm'] ?? '';
            if (empty($default_llm_id) && $llm_registry) {
                $default_free_model = $llm_registry->get_model_by_id(SENTIENT_FORMS_DEFAULT_FREE_LLM_ID);
                if ($default_free_model) {
                    $default_llm_id = $default_free_model->get_id();
                } elseif (!empty($all_llm_models)) {
                    $first_model = reset($all_llm_models);
                    $default_llm_id = $first_model->get_id();
                }
            }
            $license_key = $options['license_key'] ?? ''; // Assuming license key is stored within the same option array
            $license_status = get_option('sentient_forms_license_status', 'inactive'); // License status might be a separate option

            extract(compact('options', 'all_llm_models', 'api_key', 'default_llm_id', 'license_key', 'license_status', 'llm_registry'));
            include $view_file;
        }
        else
        {
            echo '<div class="wrap"><h1>' .
                 esc_html__( 'Sentient Forms Settings', 'sentient-forms' ) .
                 '</h1><p>' .
                 esc_html__( 'Configure your settings.', 'sentient-forms' ) .
                 '</p></div>';
        }
    }

    /**
     * Render the License tab.
     */
    public function render_license_tab(): void
    {
        $view_file = SENTIENT_FORMS_PLUGIN_DIR . 'includes/admin/views/license.php';
        if ( file_exists( $view_file ) )
        {
            // Pass license info to the view
            $options = $this->plugin->get_options();
            $license_key    = $options['license_key'] ?? '';
            $license_status = get_option( 'sentient_forms_license_status', 'inactive' );
            $license_data   = get_option( 'sentient_forms_license_data', [] );


            extract(compact('license_key', 'license_status', 'license_data'));
            include $view_file;
        }
        else
        {
            echo '<div class="wrap"><h1>' .
                 esc_html__( 'Sentient Forms License', 'sentient-forms' ) .
                 '</h1><p>' .
                 esc_html__( 'Manage your license.', 'sentient-forms' ) .
                 '</p></div>';
        }
    }

    /**
     * Admin notice for missing API key.
     */
    public function admin_notice_missing_api_key(): void
    {
        // Only show on Sentient Forms pages or if explicitly needed globally
        $current_screen = get_current_screen();
        if ( $current_screen && str_contains( $current_screen->id, 'sentient-forms' ) ) {
            $settings_url = admin_url( 'admin.php?page=sentient-forms-settings' );
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <?php
                    printf(
                        wp_kses_post( __( '<strong>Sentient Forms:</strong> Your Sentient Forms API Key is not set. Please <a href="%s">configure your API key</a> to enable LLM functionalities.', 'sentient-forms' ) ),
                        esc_url( $settings_url )
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Admin notice for invalid license.
     */
    public function admin_notice_invalid_license(): void
    {
        $current_screen = get_current_screen();
        // Only show on Sentient Forms pages
        if ( $current_screen && str_contains( $current_screen->id, 'sentient-forms' ) ) {
            $license_url = admin_url( 'admin.php?page=sentient-forms-license' );
            ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <?php
                    printf(
                        wp_kses_post( __( '<strong>Sentient Forms:</strong> Your license is not active. Please <a href="%s">activate your license</a> to ensure access to all features and updates.', 'sentient-forms' ) ),
                        esc_url( $license_url )
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }

    // --- AJAX Handlers ---

    /**
     * AJAX handler to save general plugin settings.
     * Note: WordPress handles saving options via options.php if forms are set up correctly.
     * This custom AJAX handler is for settings pages not using the Settings API directly for saving.
     * The provided settings.php seems to use options.php, so this might be for other settings contexts.
     * If settings.php is indeed using `settings_fields()` and `submit_button()`, WordPress handles the saving.
     * This AJAX handler is kept if it's used by another part of the admin UI or if settings.php is changed.
     */
    public function ajax_save_settings(): void
    {
        $options       = get_option( 'sentient_forms_settings', [] );
        $enforce_nonce = isset( $options['enforce_nonce_verification'] ) ? rest_sanitize_boolean( $options['enforce_nonce_verification'] ) : true;
        if ( $enforce_nonce ) {
            check_ajax_referer( 'sentient_forms_admin_nonce', 'nonce' );
        }

        if ( !current_user_can( 'manage_options' ) )
        {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sentient-forms' ) ], 403 );
        }

        $settings_data = isset( $_POST[ 'sentient_forms_settings' ] ) && is_array( $_POST[ 'sentient_forms_settings' ] )
            ? $_POST[ 'sentient_forms_settings' ]
            : [];

        // Sanitize settings data before saving
        $sanitized_settings = [];
        if (isset($settings_data['api_key'])) {
            $sanitized_settings['api_key'] = sanitize_text_field($settings_data['api_key']);
        }
        if (isset($settings_data['default_llm'])) {
            $sanitized_settings['default_llm'] = sanitize_text_field($settings_data['default_llm']);
        }
        if (isset($settings_data['license_key'])) {
            $sanitized_settings['license_key'] = sanitize_text_field($settings_data['license_key']);
        }
        if (isset($settings_data['enforce_nonce_verification'])) {
            $sanitized_settings['enforce_nonce_verification'] = rest_sanitize_boolean($settings_data['enforce_nonce_verification']);
        } else {
            $sanitized_settings['enforce_nonce_verification'] = false;
        }

        update_option( 'sentient_forms_settings', $sanitized_settings );

        // Potentially trigger license activation/deactivation if license key changed
        if (isset($sanitized_settings['license_key']) && class_exists('Sentient_Forms_Pro_Updater')) {
            // This logic would typically be in the Pro_Updater class, triggered by an option update.
            // For simplicity, a direct call or action could be placed here if appropriate.
        }

        wp_send_json_success( [ 'message' => __( 'Settings saved successfully.', 'sentient-forms' ) ] );
    }

    /**
     * AJAX handler to get forms for a selected form provider (adapter).
     */
    public function ajax_get_forms_for_provider(): void
    {
        $options       = get_option( 'sentient_forms_settings', [] );
        $enforce_nonce = isset( $options['enforce_nonce_verification'] ) ? rest_sanitize_boolean( $options['enforce_nonce_verification'] ) : true;
        if ( $enforce_nonce ) {
            check_ajax_referer( 'sentient_forms_admin_nonce', 'nonce' );
        }

        if ( !current_user_can( 'manage_options' ) )
        {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sentient-forms' ) ], 403 );
        }

        $adapter_id = isset( $_POST[ 'adapter_id' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'adapter_id' ] ) ) : null;
        if ( empty( $adapter_id ) )
        {
            wp_send_json_error( [ 'message' => __( 'Form provider ID is missing.', 'sentient-forms' ) ], 400 );
        }

        $adapter_registry = $this->plugin->get_form_adapter_registry();
        if ( !$adapter_registry )
        {
            wp_send_json_error( [ 'message' => __( 'Adapter registry not available.', 'sentient-forms' ) ], 500 );
        }
        $adapter = $adapter_registry->get_adapter_by_id( $adapter_id ); // Corrected method name

        if ( !$adapter || !$adapter->is_active() )
        {
            wp_send_json_error( [ 'message' => __( 'Invalid or inactive form provider.', 'sentient-forms' ) ], 404 );
        }

        $forms = $adapter->get_forms();
        wp_send_json_success( [ 'forms' => $forms ] );
    }

    /**
     * AJAX handler to get saved actions for a specific form.
     */
    public function ajax_get_actions_for_form(): void
    {
        $options       = get_option( 'sentient_forms_settings', [] );
        $enforce_nonce = isset( $options['enforce_nonce_verification'] ) ? rest_sanitize_boolean( $options['enforce_nonce_verification'] ) : true;
        if ( $enforce_nonce ) {
            check_ajax_referer( 'sentient_forms_admin_nonce', 'nonce' );
        }
        if ( !current_user_can( 'manage_options' ) )
        {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sentient-forms' ) ], 403 );
            return;
        }

        $adapter_id = isset( $_POST[ 'adapter_id' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'adapter_id' ] ) ) : null;
        $form_id    = isset( $_POST[ 'form_id' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'form_id' ] ) ) : null;

        if ( empty( $adapter_id ) || empty( $form_id ) )
        {
            wp_send_json_error( [ 'message' => __( 'Adapter ID or Form ID is missing.', 'sentient-forms' ) ], 400 );
        }

        // Logic to get form-specific settings, which include action configurations
        $adapter = $this->plugin->get_form_adapter_registry()->get_adapter_by_id($adapter_id);
        if (!$adapter) {
            wp_send_json_error( [ 'message' => __( 'Invalid adapter.', 'sentient-forms' ) ], 400 );
        }

        // Assuming adapters have a method like get_form_settings which returns all settings including actions
        $form_settings = [];
        if (method_exists($adapter, 'get_form_settings')) {
            $form_settings = $adapter->get_form_settings( $form_id );
        } else {
            // Fallback or generic option name if adapter doesn't provide a specific method
            $option_key = 'sentient_forms_' . $adapter_id . '_' . $form_id . '_settings';
            $form_settings = get_option($option_key, ['enabled' => false, 'actions' => []]);
        }


        wp_send_json_success( [
                                  'enabled' => $form_settings['enabled'] ?? false,
                                  'actions' => $form_settings['actions'] ?? []
                              ] );
    }

    /**
     * AJAX handler to save action configurations for a specific form.
     * This was previously named ajax_save_form_actions, renamed to match JS.
     */
    public function ajax_save_form_settings(): void // Renamed from ajax_save_form_actions
    {
        $options       = get_option( 'sentient_forms_settings', [] );
        $enforce_nonce = isset( $options['enforce_nonce_verification'] ) ? rest_sanitize_boolean( $options['enforce_nonce_verification'] ) : true;
        if ( $enforce_nonce ) {
            check_ajax_referer( 'sentient_forms_admin_nonce', 'nonce' );
        }
        if ( !current_user_can( 'manage_options' ) )
        {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sentient-forms' ) ], 403 );
            return;
        }

        $form_id_raw     = isset( $_POST[ 'form_id' ] ) ? wp_unslash( $_POST[ 'form_id' ] ) : null;
        $adapter_id_raw  = isset( $_POST[ 'adapter_id' ] ) ? wp_unslash( $_POST[ 'adapter_id' ] ) : null;
        $settings_raw    = isset( $_POST[ 'settings' ] ) && is_array( $_POST[ 'settings' ] ) ? wp_unslash( $_POST[ 'settings' ] ) : [];

        $form_id    = sanitize_text_field( $form_id_raw );
        $adapter_id = sanitize_text_field( $adapter_id_raw );


        if ( empty( $adapter_id ) || empty( $form_id ) )
        {
            wp_send_json_error( [ 'message' => __( 'Adapter ID or Form ID is missing.', 'sentient-forms' ) ], 400 );
        }

        $adapter = $this->plugin->get_form_adapter_registry()->get_adapter_by_id($adapter_id);
        if (!$adapter) {
            wp_send_json_error( [ 'message' => __( 'Invalid adapter specified.', 'sentient-forms' ) ], 400 );
        }

        // Sanitize the 'enabled' status for the form
        $sanitized_form_settings = [
            'enabled' => isset($settings_raw['enabled']) ? rest_sanitize_boolean($settings_raw['enabled']) : false,
            'actions' => []
        ];

        // Sanitize actions
        $action_registry = $this->plugin->get_action_registry();
        if (!$action_registry) {
            wp_send_json_error( [ 'message' => __( 'Action registry not available.', 'sentient-forms' ) ], 500 );
        }

        if (isset($settings_raw['actions']) && is_array($settings_raw['actions'])) {
            foreach ($settings_raw['actions'] as $action_id_key => $action_data_raw) {
                $action_id = sanitize_text_field($action_id_key);
                $action_instance = $action_registry->get_action($action_id);

                if (!$action_instance) {
                    // Log or skip invalid action
                    continue;
                }

                $current_action_settings = [];
                $current_action_settings['enabled'] = isset($action_data_raw['enabled']) ? rest_sanitize_boolean($action_data_raw['enabled']) : false;

                // Sanitize individual settings for this action
                $defined_fields = $action_instance->get_settings_fields();
                foreach ($defined_fields as $field_key => $field_args) {
                    if (isset($action_data_raw[$field_key])) {
                        // Basic sanitization based on type, can be expanded
                        if ($field_args['type'] === 'checkbox') {
                            $current_action_settings[$field_key] = rest_sanitize_boolean($action_data_raw[$field_key]);
                        } elseif ($field_args['type'] === 'textarea') {
                            $current_action_settings[$field_key] = sanitize_textarea_field($action_data_raw[$field_key]);
                        } elseif ($field_args['type'] === 'number') {
                            $current_action_settings[$field_key] = floatval($action_data_raw[$field_key]);
                        } else { // text, select, etc.
                            $current_action_settings[$field_key] = sanitize_text_field($action_data_raw[$field_key]);
                        }
                    } else {
                        // If not present, set default or false for checkboxes
                        $current_action_settings[$field_key] = $field_args['type'] === 'checkbox' ? false : ($field_args['default'] ?? null);
                    }
                }
                // Sanitize hooks
                if (isset($action_data_raw['hooks']) && is_array($action_data_raw['hooks'])) {
                    $current_action_settings['hooks'] = array_map('sanitize_text_field', $action_data_raw['hooks']);
                } else {
                    $current_action_settings['hooks'] = [];
                }


                $sanitized_form_settings['actions'][$action_id] = $current_action_settings;
            }
        }

        // Save the sanitized settings using the adapter's method or a generic option
        $success = false;
        if (method_exists($adapter, 'update_form_settings')) {
            $success = $adapter->update_form_settings($form_id, $sanitized_form_settings);
        } else {
            $option_key = 'sentient_forms_' . $adapter_id . '_' . $form_id . '_settings';
            $success = update_option($option_key, $sanitized_form_settings);
        }


        if ($success) {
            wp_send_json_success(
                [
                    'message' => __( 'Form settings saved successfully.', 'sentient-forms' ),
                    'settings' => $sanitized_form_settings, // Send back the saved settings
                ]
            );
        } else {
            wp_send_json_error( [ 'message' => __( 'Failed to save form settings.', 'sentient-forms' ) ], 500 );
        }
    }


    /**
     * AJAX handler to get HTML for a specific action's settings fields.
     */
    public function ajax_get_action_settings_html(): void
    {
        $options       = get_option( 'sentient_forms_settings', [] );
        $enforce_nonce = isset( $options['enforce_nonce_verification'] ) ? rest_sanitize_boolean( $options['enforce_nonce_verification'] ) : true;
        if ( $enforce_nonce ) {
            check_ajax_referer( 'sentient_forms_admin_nonce', 'nonce' );
        }
        if ( !current_user_can( 'manage_options' ) )
        {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sentient-forms' ) ], 403 );
            return;
        }

        $action_id_param              = isset( $_POST[ 'action_id' ] ) ? wp_unslash( $_POST[ 'action_id' ] ) : null;
        $action_settings_values_param = isset( $_POST[ 'action_settings' ] ) && is_array( $_POST[ 'action_settings' ] ) ? wp_unslash( $_POST[ 'action_settings' ] ) : [];

        $action_id = sanitize_text_field($action_id_param);

        // Sanitize action_settings_values more carefully if needed, though they are mostly for pre-filling.
        // For this HTML generation, direct output of these values into form fields needs escaping.
        $action_settings_values = array_map_recursive('sanitize_text_field', $action_settings_values_param);


        if ( empty( $action_id ) )
        {
            wp_send_json_error( [ 'message' => __( 'Action ID is missing.', 'sentient-forms' ) ], 400 );
        }

        $action_registry = $this->plugin->get_action_registry();
        if ( !$action_registry )
        {
            wp_send_json_error( [ 'message' => __( 'Action registry not available.', 'sentient-forms' ) ], 500 );
        }
        $action_instance = $action_registry->get_action( $action_id );

        if ( !$action_instance )
        {
            wp_send_json_error( [ 'message' => __( 'Invalid Action ID.', 'sentient-forms' ) ], 404 );
        }

        $settings_fields = $action_instance->get_settings_fields();

        $llm_registry   = $this->plugin->get_llm_model_registry();
        $available_llms = [];
        if ($llm_registry) {
            $required_capabilities = method_exists($action_instance, 'get_required_llm_capabilities') ? $action_instance->get_required_llm_capabilities() : [];
            $available_llms = $llm_registry->get_models(
                statuses: [Sentient_Forms_Llm_Status::ACTIVE, Sentient_Forms_Llm_Status::PREVIEW],
                capabilities: $required_capabilities
            );
        }


        ob_start();
        if ( empty( $settings_fields ) )
        {
            echo '<p>' . esc_html__( 'This action has no configurable settings.', 'sentient-forms' ) . '</p>';
        }
        else
        {
            echo '<table class="form-table">';
            // Helper function to render different field types
            // This is a simplified version; a more robust solution might use a dedicated field rendering class/functions.
            foreach ( $settings_fields as $field_key => $field_args ) {
                // Skip 'enabled' field if it's managed globally for the action in the modal
                if ($field_key === 'enabled' && isset($field_args['managed_globally']) && $field_args['managed_globally']) {
                    continue;
                }

                $current_value = $action_settings_values[ $field_key ] ?? $field_args[ 'default' ] ?? '';
                $field_id_attr = 'sf_action_setting_' . esc_attr( $action_id ) . '_' . esc_attr( $field_key );
                $field_name_attr = 'settings[actions][' . esc_attr( $action_id ) . '][' . esc_attr( $field_key ) . ']';

                echo '<tr>';
                echo '<th scope="row"><label for="' . esc_attr( $field_id_attr ) . '">' . esc_html( $field_args['label'] ) . '</label></th>';
                echo '<td>';

                switch ( $field_args['type'] ) {
                    case 'llm_model_select':
                        echo '<select id="' . esc_attr( $field_id_attr ) . '" name="' . esc_attr( $field_name_attr ) . '">';
                        echo '<option value="">' . esc_html__( 'Use Global Default LLM', 'sentient-forms' ) . '</option>';
                        if (!empty($available_llms)) {
                            foreach ( $available_llms as $llm_model ) {
                                echo sprintf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr( $llm_model->get_id() ),
                                    selected( $current_value, $llm_model->get_id(), false ),
                                    esc_html( $llm_model->get_name() . ' (' . $llm_model->get_cost_tier()->get_name() . ')' )
                                );
                            }
                        } else {
                            echo '<option value="" disabled>' . esc_html__( 'No compatible LLMs found for this action.', 'sentient-forms' ) . '</option>';
                        }
                        echo '</select>';
                        break;
                    case 'textarea':
                        echo '<textarea id="' . esc_attr( $field_id_attr ) . '" name="' . esc_attr( $field_name_attr ) . '" class="large-text" rows="5">' . esc_textarea( $current_value ) . '</textarea>';
                        break;
                    case 'checkbox':
                        echo '<label><input type="checkbox" id="' . esc_attr( $field_id_attr ) . '" name="' . esc_attr( $field_name_attr ) . '" value="1" ' . checked( rest_sanitize_boolean($current_value), true, false ) . '>';
                        // Checkbox descriptions are often placed directly after the input
                        if ( !empty( $field_args['description_inline'] ) ) {
                            echo ' ' . esc_html( $field_args['description_inline'] ) . '</label>';
                        } else {
                            echo '</label>';
                        }
                        break;
                    case 'number':
                        echo '<input type="number" id="' . esc_attr( $field_id_attr ) . '" name="' . esc_attr( $field_name_attr ) . '" value="' . esc_attr( $current_value ) . '" class="small-text"';
                        if (isset($field_args['min'])) echo ' min="'.esc_attr($field_args['min']).'"';
                        if (isset($field_args['max'])) echo ' max="'.esc_attr($field_args['max']).'"';
                        if (isset($field_args['step'])) echo ' step="'.esc_attr($field_args['step']).'"';
                        echo '>';
                        break;
                    case 'select':
                        echo '<select id="' . esc_attr( $field_id_attr ) . '" name="' . esc_attr( $field_name_attr ) . '">';
                        foreach (($field_args['options'] ?? []) as $opt_val => $opt_label) {
                            echo sprintf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr($opt_val),
                                selected($current_value, $opt_val, false),
                                esc_html($opt_label)
                            );
                        }
                        echo '</select>';
                        break;
                    default: // text
                        echo '<input type="text" id="' . esc_attr( $field_id_attr ) . '" name="' . esc_attr( $field_name_attr ) . '" value="' . esc_attr( $current_value ) . '" class="regular-text"/>';
                        break;
                }

                if ( !empty( $field_args['description'] ) && $field_args['type'] !== 'checkbox' ) { // Checkbox description handled by description_inline
                    echo '<p class="description">' . wp_kses_post( $field_args['description'] ) . '</p>';
                }
                if ( !empty( $field_args['description'] ) && $field_args['type'] === 'checkbox' && empty( $field_args['description_inline'] ) ) {
                    echo '<p class="description">' . wp_kses_post( $field_args['description'] ) . '</p>';
                }

                echo '</td></tr>';
            }
            echo '</table>';
        }
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    /**
     * AJAX handler for testing API connection.
     */
    public function ajax_test_connection(): void {
        $options       = get_option( 'sentient_forms_settings', [] );
        $enforce_nonce = isset( $options['enforce_nonce_verification'] ) ? rest_sanitize_boolean( $options['enforce_nonce_verification'] ) : true;
        if ( $enforce_nonce ) {
            check_ajax_referer( 'sentient_forms_admin_nonce', 'nonce' );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sentient-forms' ) ], 403 );
        }

        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => __( 'API Key is required.', 'sentient-forms' ) ], 400 );
        }

        // Use the provided API key for this test, not necessarily the saved one.
        $api_client = new Sentient_Forms_Llm_Api_Client( $api_key );
        // A simple way to test is to try fetching available models or credit balance.
        // Let's use get_credit_balance as it's a GET request and usually lightweight.
        $response = $api_client->get_credit_balance();

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        } elseif ( isset( $response['balance'] ) ) {
            wp_send_json_success( [ 'message' => __( 'Connection successful! Credit balance retrieved.', 'sentient-forms' ), 'balance' => $response['balance'] ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Connection test failed. The API responded, but the data format was unexpected.', 'sentient-forms' ), 'response' => $response ] );
        }
    }

    /**
     * AJAX handler for fetching credit balance.
     */
    public function ajax_get_credit_balance(): void {
        $options       = get_option( 'sentient_forms_settings', [] );
        $enforce_nonce = isset( $options['enforce_nonce_verification'] ) ? rest_sanitize_boolean( $options['enforce_nonce_verification'] ) : true;
        if ( $enforce_nonce ) {
            check_ajax_referer( 'sentient_forms_admin_nonce', 'nonce' );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sentient-forms' ) ], 403 );
        }

        $api_key = $this->plugin->get_proxy_api_key();
        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => __( 'API Key is not configured.', 'sentient-forms' ) ], 400 );
        }

        $api_client = new Sentient_Forms_Llm_Api_Client( $api_key );
        $response = $api_client->get_credit_balance();

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        } elseif ( isset( $response['balance'] ) ) {
            // Optionally update the stored credit balance
            update_option('sentient_forms_credit_balance', $response['balance']);
            wp_send_json_success( [ 'balance' => $response['balance'] ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Failed to retrieve credit balance. Unexpected API response.', 'sentient-forms' ), 'response' => $response ] );
        }
    }

}
