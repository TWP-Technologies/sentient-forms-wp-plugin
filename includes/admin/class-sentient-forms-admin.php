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

    private Sentient_Forms_Admin_Assets $assets;

    /** @var WP_Error|null */
    private $asset_error = null;
    private ?string $dev_notice = null;

    /**
     * Constructor.
     * Stores a reference to the main plugin instance.
     *
     * @param Sentient_Forms_Plugin $plugin The main plugin instance.
     */
    public function __construct( Sentient_Forms_Plugin $plugin )
    {
        $this->plugin = $plugin;
        $this->assets = new Sentient_Forms_Admin_Assets();
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

        if ( ! defined( 'SENTIENT_FORMS_VERSION' ) ) {
            return;
        }

        try {
            $entry = $this->assets->get_entry();
        } catch ( WP_Error $error ) {
            $this->asset_error = $error;
            add_action( 'admin_notices', [ $this, 'render_asset_error_notice' ] );
            return;
        }

        $dev_notice = $this->assets->get_dev_notice();
        if ( $dev_notice && apply_filters( 'sentient_forms_admin_dev_notice_enabled', true ) ) {
            $this->dev_notice = $dev_notice;
            add_action( 'admin_notices', [ $this, 'render_dev_asset_notice' ] );
        }

        $script_handle = 'sentient-forms-admin-app';
        $script_url    = $this->assets->get_asset_url( $entry['file'] ?? '' );

        wp_enqueue_style( 'wp-components' );

        foreach ( $entry['css'] ?? [] as $index => $css_path ) {
            wp_enqueue_style(
                sprintf( '%s-css-%d', $script_handle, $index ),
                $this->assets->get_asset_url( $css_path ),
                [],
                SENTIENT_FORMS_VERSION
            );
        }

        wp_enqueue_script(
            $script_handle,
            $script_url,
            [],
            SENTIENT_FORMS_VERSION,
            true
        );

        wp_script_add_data( $script_handle, 'type', 'module' );

        $config = $this->build_spa_bootstrap_payload();
        wp_add_inline_script( $script_handle, 'window.sentientFormsConfig = ' . wp_json_encode( $config ) . ';', 'before' );
    }

    private function build_spa_bootstrap_payload(): array
    {
        return [
            'apiBaseUrl'    => rest_url( 'sentient-forms/v1/' ),
            'restNonce'     => wp_create_nonce( 'wp_rest' ),
            'ajaxNonce'     => wp_create_nonce( 'sentient_forms_admin_nonce' ),
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'siteUrl'       => get_site_url(),
            'localSiteIdentifier' => Sentient_Forms_Plugin::instance()->get_local_site_identifier(),
            'pluginVersion' => SENTIENT_FORMS_VERSION,
            'assetBaseUrl'  => rtrim( $this->assets->get_asset_url( '' ), '/' ),
            'devMode'       => $this->assets->is_dev_mode(),
            'devServerUrl'  => $this->assets->is_dev_mode() ? rtrim( $this->assets->get_asset_url( '' ), '/' ) : null,
            'license'       => $this->build_license_bootstrap_payload(),
            'currentUser'   => [
                'id'        => get_current_user_id(),
                'canManage' => current_user_can( 'manage_options' ),
            ],
            'i18n'          => [
                'errorOccurred'     => __( 'An error occurred. Please try again.', 'sentient-forms' ),
                'unsavedChanges'    => __( 'You have unsaved changes. Are you sure you want to leave?', 'sentient-forms' ),
                'savingSettings'    => __( 'Saving settings...', 'sentient-forms' ),
                'settingsFailed'    => __( 'Failed to save settings.', 'sentient-forms' ),
                'settingsError'     => __( 'An error occurred while saving settings.', 'sentient-forms' ),
                'apiKeyRequired'    => __( 'API key is required to test connection.', 'sentient-forms' ),
                'testingConnection' => __( 'Testing connection...', 'sentient-forms' ),
                'connectionFailed'  => __( 'Connection failed.', 'sentient-forms' ),
                'connectionError'   => __( 'An error occurred during the connection test.', 'sentient-forms' ),
            ],
        ];
    }

    private function build_license_bootstrap_payload(): array
    {
        $license_data = Sentient_Forms_Plugin::instance()->get_license_data();
        $license_key  = $license_data['license_key'] ?? '';

        $masked_key = '';
        if ( ! empty( $license_key ) )
        {
            $masked_key = strlen( $license_key ) > 8
                ? substr( $license_key, 0, 4 ) . str_repeat( '*', strlen( $license_key ) - 8 ) . substr( $license_key, -4 )
                : str_repeat( '*', strlen( $license_key ) );
        }

        return [
            'status'            => $license_data['license_status'] ?? 'inactive',
            'licenseKeyMasked'  => $masked_key,
            'proxyKeyPresent'   => ! empty( $license_data['proxy_api_key'] ),
            'tier'              => $license_data['tier'] ?: null,
            'expiresAt'         => $license_data['expiry_date'] ?: null,
            'lastSynced'        => $license_data['last_synced'] ?: null,
            'licenseId'         => $license_data['license_id'] ?: null,
            'siteId'            => $license_data['site_id'] ?: null,
        ];
    }

    public function render_asset_error_notice(): void
    {
        if ( null === $this->asset_error ) {
            return;
        }

        $message = $this->asset_error->get_error_message();

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html( sprintf( __( 'Sentient Forms admin assets are unavailable. %s', 'sentient-forms' ), $message ) )
        );
    }

    public function render_dev_asset_notice(): void
    {
        if ( null === $this->dev_notice ) {
            return;
        }

        printf(
            '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
            esc_html( sprintf( __( 'Sentient Forms dev server unavailable (%s). Falling back to built assets.', 'sentient-forms' ), $this->dev_notice ) )
        );
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
        $this->render_app_container( 'forms' );
    }

    /**
     * Render the Dashboard tab.
     */
    public function render_dashboard_tab(): void
    {
        $this->render_app_container( 'dashboard' );
    }

    /**
     * Render the Actions tab.
     */
    public function render_actions_tab(): void {
        $this->render_app_container( 'actions' );
    }


    /**
     * Render the Settings tab.
     */
    public function render_settings_tab(): void
    {
        $this->render_app_container( 'settings' );
    }

    /**
     * Render the License tab.
     */
    public function render_license_tab(): void
    {
        $this->render_app_container( 'license' );
    }

    /**
     * Output the SPA mount point for the requested view.
     */
    private function render_app_container( string $view ): void
    {
        printf(
            '<div class="wrap"><div id="sentient-forms-admin-app" data-view="%s"></div></div>',
            esc_attr( $view )
        );
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
