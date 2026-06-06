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
	private ?string $spa_app_module_url = null;
	private ?string $spa_start_module_url = null;
	private ?string $spa_bootstrap_script = null;

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

        // Ensure admin_url paths include /wp-admin/ for environments where it may be omitted (avoids /admin.php 404s).
		add_filter( 'admin_url', [ $this, 'ensure_admin_path_prefix' ], 9, 3 );

        // Enqueue admin-specific scripts and styles.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_filter( 'script_loader_tag', [ $this, 'force_module_type_for_spa' ], 10, 3 );

        // Add plugin action links (e.g., "Settings") on the plugins page.
        if ( defined( 'SENTIENT_FORMS_PLUGIN_FILE' ) )
        {
            add_filter( 'plugin_action_links_' . plugin_basename( SENTIENT_FORMS_PLUGIN_FILE ), [ $this, 'plugin_action_links' ] );
        }

        if ( function_exists( 'wp_add_privacy_policy_content' ) )
        {
            add_action( 'admin_init', [ $this, 'register_privacy_policy_content' ] );
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
        if ( empty( $this->plugin->get_proxy_api_key() ) && ! $this->has_ready_local_provider() )
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
            $this->get_menu_icon_svg_data_uri(),      // Icon URL or dashicon class
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

    private function get_menu_icon_svg_data_uri(): string
    {
        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000" aria-hidden="true" focusable="false"><g fill="black" transform="translate(71.7, 83.6) scale(0.9507)"><path d="M833.17,219.79h-225.3c-31.12,0-56.59,25.46-56.59,56.59,0,72.67,2.83,145.9-.14,218.49-2.05,50.07-19.46,87.08-69.49,101.82-39.09,11.51-62,10.89-111.58,11.91-41.31.85-107.18-4.63-149.28,1.39-28.55,4.08-64.99,16.04-104.03,35.29-6.56,3.24-12.91,6.84-18.96,10.88,118.7,0,237.4-.08,356.1-.01,46.55.03,93.11-.81,139.65.08,103.92,1.99,254.03-.4,290.4-123.15,3.03-10.23,4.48-20.64,5.15-31.13.43-2.81.66-5.68.66-8.6v-216.97c0-31.12-25.46-56.59-56.59-56.59ZM614.3,346.7c0-11.37,10.24-20.68,22.75-20.68h169.05c12.51,0,22.75,9.31,22.75,20.68v.3c0,11.37-10.24,20.68-22.75,20.68h-169.05c-12.51,0-22.75-9.31-22.75-20.68v-.3ZM873.97,428.89h.04c1.39-1.39.34-.34-.29.29-.01.01-.03.03-.04.04q-34.05,39.6-73.73,79.51s-5.92,6.38-6.35,5.89l-.02-.02c-4.81,4.42-9.58,8.55-14.29,12.18-12.53,9.67-21.52,4.4-30.71-5.94-6.28-7.06-12.36-14.3-18.4-21.57-8.04-9.67-16-19.41-23.86-29.23l-12.2-12.61h-58.59c-11.69,0-21.26-9.31-21.26-20.68v-.3c0-11.37,9.56-20.68,21.26-20.68h157.96c10.79,0,19.74,7.93,21.07,18.09.25-.25.49-.49.72-.73.04-.04.08-.09.12-.13.95-.96,1.77-1.79,2.43-2.45.02-.02.04-.04.06-.07.99-1,1.59-1.6,1.59-1.6l11.57-13.11c-.99.99,54.57-.48,54.16,0-4.14,4.82-7.87,9.15-11.27,13.11Z"/><path d="M389.38,448.47h-111.98c-18.51,0-33.66,15.15-33.66,33.66v98.27c0,18.51,15.15,33.66,33.66,33.66h111.98c18.51,0,33.66-15.15,33.66-33.66v-98.27c0-18.51-15.15-33.66-33.66-33.66ZM367.76,546.89h-68.74c-8.59,0-15.62-7.03-15.62-15.62s7.03-15.62,15.62-15.62h68.74c8.59,0,15.62,7.03,15.62,15.62s-7.03,15.62-15.62,15.62Z"/><circle cx="62.72" cy="604.19" r="51.01"/></g></svg>
SVG;

        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
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

        $entry = $this->assets->get_entry();
        if ( is_wp_error( $entry ) )
        {
            $this->asset_error = $entry;
            add_action( 'admin_notices', [ $this, 'render_asset_error_notice' ] );
            return;
        }

        $dev_notice = $this->assets->get_dev_notice();
        if ( $dev_notice && apply_filters( 'sentient_forms_admin_dev_notice_enabled', true ) ) {
            $this->dev_notice = $dev_notice;
            add_action( 'admin_notices', [ $this, 'render_dev_asset_notice' ] );
        }

        $script_handle = 'sentient-forms-admin-app';
		$start_entry   = $this->assets->get_entry( 'node_modules/@sveltejs/kit/src/runtime/client/entry.js' );
		if ( is_wp_error( $start_entry ) )
		{
			$this->asset_error = $start_entry;
			add_action( 'admin_notices', [ $this, 'render_asset_error_notice' ] );
			return;
		}
		$app_module_url    = $this->assets->get_asset_url( $entry['file'] ?? '' );
		$start_module_url  = $this->assets->get_asset_url( $start_entry['file'] ?? '' );

        wp_enqueue_style( 'wp-components' );
        wp_enqueue_script(
            'sentient-forms-admin-legacy',
            SENTIENT_FORMS_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            SENTIENT_FORMS_VERSION,
            true
        );
        wp_localize_script(
            'sentient-forms-admin-legacy',
            'sentientFormsAdmin',
            $this->build_legacy_admin_payload()
        );

        foreach ( $entry['css'] ?? [] as $index => $css_path ) {
            wp_enqueue_style(
                sprintf( '%s-css-%d', $script_handle, $index ),
                $this->assets->get_asset_url( $css_path ),
                [],
                SENTIENT_FORMS_VERSION
            );
        }

		$this->spa_app_module_url   = $app_module_url;
		$this->spa_start_module_url = $start_module_url;

		$config                 = $this->build_spa_bootstrap_payload();
		$asset_base             = rtrim( $config['assetBaseUrl'] ?? '', '/' );
		$svelte_runtime_key     = $this->assets->get_sveltekit_runtime_key();
		$bootstrap_js           = 'window.sentientFormsConfig = ' . wp_json_encode( $config ) . ';';
		$bootstrap_js          .= "\n" . sprintf(
			'window.%1$s = { base: new URL(".", location).pathname.slice(0, -1), assets: %2$s };',
			$svelte_runtime_key,
			wp_json_encode( $asset_base )
		);
		$bootstrap_js .= "\n" . 'window.sentientFormsAppReady = "bootstrapping";';
		$bootstrap_js .= "\n" . '(function(){ ["#adminmenu", "#wpadminbar", "#screen-meta-links", "#wpfooter"].forEach(function(selector){ var node = document.querySelector(selector); if (node) { node.setAttribute("data-sveltekit-reload", ""); } }); })();';
		$bootstrap_js .= "\n" . $this->build_hash_router_bootstrap_js();

		$is_dev = ! empty( $config['devMode'] );
		if ( $is_dev ) {
			$bootstrap_js .= "\n" . sprintf(
				'import("%s/@vite/client").catch((e) => console.warn("Vite client load failed", e));',
				rtrim( $asset_base, '/' )
			);
		}

		$runtime_import = $is_dev
			? rtrim( $asset_base, '/' ) . '/node_modules/@sveltejs/kit/src/runtime/client/entry.js'
			: $start_module_url;
		$app_import = $is_dev
			? rtrim( $asset_base, '/' ) . $this->get_dev_app_entry_path()
			: $app_module_url;

		$this->spa_bootstrap_script = $bootstrap_js;

		// Store dev/prod module URLs for enqueue output.
		$this->spa_start_module_url = $runtime_import;
		$this->spa_app_module_url   = $app_import;
	}

	private function get_dev_app_entry_path(): string
	{
		if ( defined( 'SENTIENT_FORMS_ADMIN_DEV_APP_ENTRY' ) && is_string( constant( 'SENTIENT_FORMS_ADMIN_DEV_APP_ENTRY' ) ) )
		{
			$configured = constant( 'SENTIENT_FORMS_ADMIN_DEV_APP_ENTRY' );
		}
		else
		{
			$configured = getenv( 'SENTIENT_FORMS_ADMIN_DEV_APP_ENTRY' );
		}

		if ( is_string( $configured ) && '' !== trim( $configured ) )
		{
			return '/' . ltrim( trim( $configured ), '/' );
		}

		return '/@fs/app/.svelte-kit/generated/client/app.js';
	}

	public function force_module_type_for_spa( string $tag, string $handle, string $src ): string
	{
		if ( 'sentient-forms-admin-app' !== $handle )
		{
			return $tag;
		}

		if ( str_contains( $tag, 'type="module"' ) || str_contains( $tag, "type='module'" ) )
		{
			return $tag;
		}

		return str_replace( '<' . 'script ', '<' . 'script type="module" ', $tag );
	}

	private function build_spa_bootstrap_payload(): array
	{
		$initial_route = $this->determine_initial_route();
		$admin_url     = admin_url( 'admin.php' );
		$admin_path    = wp_parse_url( $admin_url, PHP_URL_PATH ) ?: '/wp-admin/admin.php';
		$admin_base    = rtrim( preg_replace( '#/admin\.php$#', '', $admin_path ), '/' ) . '/';

		return [
			'apiBaseUrl'    => rest_url( 'sentient-forms/v1/' ),
			'restNonce'     => wp_create_nonce( 'wp_rest' ),
			'ajaxNonce'     => wp_create_nonce( 'sentient_forms_admin_nonce' ),
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'siteUrl'       => get_site_url(),
			'adminPhpPath'  => $admin_path,
			'adminBasePath' => $admin_base,
			'localSiteIdentifier' => Sentient_Forms_Plugin::instance()->get_local_site_identifier(),
			'pluginVersion' => SENTIENT_FORMS_VERSION,
			'assetBaseUrl'  => rtrim( $this->assets->get_asset_url( '', false ), '/' ),
			'devMode'       => $this->assets->is_dev_mode(),
			'devServerUrl'  => $this->assets->is_dev_mode() ? rtrim( $this->assets->get_asset_url( '', false ), '/' ) : null,
			'license'       => $this->build_license_bootstrap_payload(),
			'telemetry'     => $this->build_telemetry_bootstrap_payload(),
			'initialRoute'  => $initial_route,
			'formSources'   => $this->collect_form_sources(),
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

    private function build_legacy_admin_payload(): array
    {
        $ajax_nonce = wp_create_nonce( 'sentient_forms_admin_nonce' );

        return [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => $ajax_nonce,
            'ajaxNonce' => $ajax_nonce,
            'ajax_nonce' => $ajax_nonce,
            'forms'     => $this->collect_legacy_forms(),
            'i18n'      => [
                'apiKeyRequired'    => __( 'API key is required to test connection.', 'sentient-forms' ),
                'testingConnection' => __( 'Testing connection...', 'sentient-forms' ),
                'connectionFailed'  => __( 'Connection failed.', 'sentient-forms' ),
                'connectionError'   => __( 'An error occurred during the connection test.', 'sentient-forms' ),
                'confirmReset'      => __( 'Reset settings to defaults?', 'sentient-forms' ),
                'savingSettings'    => __( 'Saving settings...', 'sentient-forms' ),
                'settingsFailed'    => __( 'Failed to save settings.', 'sentient-forms' ),
                'settingsError'     => __( 'An error occurred while saving settings.', 'sentient-forms' ),
                'confirmDeactivate' => __( 'Deactivate this site license? This disconnects this WordPress site and does not cancel Stripe billing.', 'sentient-forms' ),
                'checking'          => __( 'Checking...', 'sentient-forms' ),
                'adminDataMissing'  => __( 'Sentient Forms admin data could not be loaded.', 'sentient-forms' ),
                'formDataMissing'   => __( 'Could not find form data for configuration.', 'sentient-forms' ),
            ],
        ];
    }

    private function collect_legacy_forms(): array
    {
        $registry = $this->plugin->get_form_adapter_registry();
        if ( ! $registry )
        {
            return [];
        }

        $forms = [];
        foreach ( $registry->get_adapters( true ) as $adapter )
        {
            foreach ( $adapter->get_forms() as $form )
            {
                if ( ! is_array( $form ) )
                {
                    continue;
                }

                $form_id = $form['id'] ?? '';
                if ( '' === (string) $form_id )
                {
                    continue;
                }

                $forms[] = [
                    'id'           => $form_id,
                    'title'        => (string) ( $form['title'] ?? $form['name'] ?? '' ),
                    'adapter'      => (string) ( $form['adapter'] ?? $adapter->get_id() ),
                    'adapter_name' => (string) ( $form['adapter_name'] ?? $adapter->get_name() ),
                    'settings'     => is_array( $form['settings'] ?? null )
                        ? $form['settings']
                        : [ 'enabled' => false, 'actions' => [] ],
                ];
            }
        }

        return $forms;
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

	private function build_telemetry_bootstrap_payload(): array
	{
		$settings = Sentient_Forms_Plugin::instance()->get_telemetry_settings();

		return [
			'optIn'           => ! empty( $settings['telemetry_opt_in'] ),
			'updatedAt'       => $settings['updated_at'] ?? null,
			'syncedAt'        => $settings['synced_at'] ?? null,
			'remoteUpdatedAt' => $settings['remote_updated_at'] ?? null,
			'lastError'       => $settings['last_error'] ?? null,
		];
	}

	private function build_hash_router_bootstrap_js(): string
	{
		return implode(
			"\n",
			[
				'(function () {',
				'	try {',
				'		var config = window.sentientFormsConfig || {};',
				"		var adminPhpPath = typeof config.adminPhpPath === 'string' && config.adminPhpPath.length ? config.adminPhpPath : '/wp-admin/admin.php';",
				"		var adminBasePath = typeof config.adminBasePath === 'string' && config.adminBasePath.length ? config.adminBasePath : '/wp-admin/';",
				'		var basePath = new URL(".", location).pathname;',
				'		if (!basePath.endsWith("/")) {',
				'			basePath = basePath + "/";',
				'		}',
				'		var searchParams = new URLSearchParams(window.location.search || "");',
				'		var pageParam = searchParams.get("page") || "";',
				'		if (pageParam && pageParam.indexOf("sentient-forms") === 0) {',
				"			var pathname = window.location.pathname || '';",
				'			var pathMatchesAdmin = pathname === adminPhpPath;',
				'			var pathMatchesBase = pathname === adminBasePath;',
				'			var pathMatchesIndex = pathname === adminBasePath + "index.php";',
				'			if (!pathMatchesAdmin && !pathMatchesBase && !pathMatchesIndex) {',
				'				var replacementUrl = adminPhpPath + (window.location.search || "") + (window.location.hash || "");',
				'				history.replaceState({}, document.title, replacementUrl);',
				'				pathname = adminPhpPath;',
				'			}',
				'		}',
				"		var existingHash = typeof window.location.hash === 'string' ? window.location.hash.trim() : '';",
				"		var hasExplicitHash = existingHash.length > 1 && existingHash !== '#/';",
				"		var route = hasExplicitHash ? existingHash : (typeof config.initialRoute === 'string' ? config.initialRoute : '/dashboard');",
				"		if (route.startsWith('#')) {",
				'			route = route.slice(1);',
				'		}',
				"		if (!route.startsWith('/')) {",
				"			route = '/' + route;",
				'		}',
				"		var targetHash = '#' + route;",
				'		if (!hasExplicitHash && window.location.hash !== targetHash) {',
				'			window.location.hash = targetHash;',
				'		}',
				'	} catch (error) {',
				"		console.error('Sentient Forms router bootstrap failed', error);",
				'	}',
				'})();',
			]
		);
	}

	/**
	 * Some local environments (or misconfigured proxies) can yield admin_url() values missing /wp-admin/,
	 * which 404 at Apache before WordPress executes. Guard against that by forcing the prefix when absent.
	 *
	 * @param string $url  The generated admin URL.
	 * @param string $path Requested path.
	 * @param int    $blog_id Site blog id (unused).
	 *
	 * @return string Corrected admin URL.
	 */
	public function ensure_admin_path_prefix( string $url, string $path = '', $blog_id = null ): string
	{
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['path'] ) ) {
			return $url;
		}

		$path_value = $parsed['path'];
		// If /wp-admin/ already present, leave untouched.
		if ( str_contains( $path_value, '/wp-admin/' ) ) {
			return $url;
		}

		// Only adjust typical admin endpoints; avoid altering other admin_url usages unexpectedly.
		$basename = basename( $path_value );
		$admin_targets = [ 'admin.php', 'index.php', 'plugins.php', 'options-general.php' ];
		if ( ! in_array( $basename, $admin_targets, true ) ) {
			return $url;
		}

		$corrected_path = '/wp-admin/' . ltrim( $path_value, '/' );
		$rebuilt = ( $parsed['scheme'] ?? 'http' ) . '://' . ( $parsed['host'] ?? 'localhost' );
		if ( isset( $parsed['port'] ) ) {
			$rebuilt .= ':' . $parsed['port'];
		}
		$rebuilt .= $corrected_path;
		if ( ! empty( $parsed['query'] ) ) {
			$rebuilt .= '?' . $parsed['query'];
		}
		if ( ! empty( $parsed['fragment'] ) ) {
			$rebuilt .= '#' . $parsed['fragment'];
		}

		return $rebuilt;
	}

	private function determine_initial_route(): string
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing based on the current plugin page.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : 'sentient-forms';

		return match ( $page ) {
			'sentient-forms-actions',
			'sentient-forms-form-config' => '/actions',
			'sentient-forms-license'     => '/licensing',
			default                      => '/dashboard',
		};
	}

	private function collect_form_sources(): array
	{
		$plugin   = Sentient_Forms_Plugin::instance();
		$registry = $plugin ? $plugin->get_form_adapter_registry() : null;

		if ( ! $registry ) {
			return [];
		}

		$sources = [];
		foreach ( $registry->get_all_adapters() as $adapter ) {
			$sources[] = [
				'slug'     => $adapter->get_id(),
				'label'    => $adapter->get_name(),
				'isActive' => $adapter->is_active(),
			];
		}

		return $sources;
	}

    public function render_asset_error_notice(): void
    {
        if ( null === $this->asset_error ) {
            return;
        }

        $message = $this->asset_error->get_error_message();

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(
                sprintf(
                    /* translators: %s: asset loading error message. */
                    __( 'Sentient Forms admin assets are unavailable. %s', 'sentient-forms' ),
                    $message
                )
            )
        );
    }

    public function render_dev_asset_notice(): void
    {
        if ( null === $this->dev_notice ) {
            return;
        }

        printf(
            '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
            esc_html(
                sprintf(
                    /* translators: %s: admin dev server error message. */
                    __( 'Sentient Forms dev server unavailable (%s). Falling back to built assets.', 'sentient-forms' ),
                    $this->dev_notice
                )
            )
        );
    }

    public function register_privacy_policy_content(): void
    {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) )
        {
            return;
        }

        $content  = '<p>' . esc_html__( 'Sentient Forms stores local AI action configuration, provider connection status, form mapping metadata, and execution logs in this WordPress database. Execution logs can include model outputs or error details created from submitted form data depending on the actions an administrator configures.', 'sentient-forms' ) . '</p>';
        $content .= '<p>' . esc_html__( 'When an administrator enables OpenRouter direct execution, selected form data and prompts are sent from this site to OpenRouter for processing. When an administrator enables Sentient Forms managed execution, selected form data and prompts are sent to the Sentient Forms managed service for paid pass-through execution, metering, and billing. AI-generated Site Context may send the site URL and public-site research prompt to the selected provider, and web-capable models may search or fetch public site pages. These external-service choices require administrator acceptance before calls are made.', 'sentient-forms' ) . '</p>';
        $content .= '<p>' . esc_html__( 'When an administrator enables the Realtime Clarification Assistant for a Gravity Forms form, selected in-progress visitor field values can be sent to the selected AI provider before final form submission so the visitor can receive suggestions. Site owners should disclose this behavior near the form or in their privacy policy before enabling realtime suggestions.', 'sentient-forms' ) . '</p>';
        $content .= '<p>' . esc_html__( 'Sentient Forms can send metadata-only operational telemetry to the Sentient Forms service only after an administrator opts in and the site has a connected Sentient Forms site identity. Telemetry can include plugin/runtime versions, provider path, action code, execution request ID, adapter, job status, attempt counts, and sanitized error or warning codes. Telemetry does not include form field contents, prompts, model outputs, raw error messages, visitor identifiers, saved provider secrets, or billing secrets, and consent can be revoked at any time.', 'sentient-forms' ) . '</p>';
        $content .= '<p>' . esc_html__( 'Site owners can use WordPress personal data export and erase tools for local Sentient Forms execution records that directly contain a verified email address. Local execution logs are subject to the retention period configured by the site administrator.', 'sentient-forms' ) . '</p>';

        wp_add_privacy_policy_content( 'Sentient Forms', wp_kses_post( $content ) );
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
            '<div class="wrap"><div id="sentient-forms-admin-app" data-view="%s">',
            esc_attr( $view )
        );
		if ( $this->spa_bootstrap_script && $this->spa_start_module_url && $this->spa_app_module_url ) {
			wp_print_inline_script_tag(
				sprintf(
					'%3$s
const mount = document.currentScript.parentElement;
if (!mount) {
	throw new Error("Sentient Forms mount element missing");
}
Promise.all([
	import(%1$s),
	import(%2$s)
]).then(([kit, app]) => {
	if (kit && typeof kit.start === "function") {
		return kit.start(app, mount);
	}
	return null;
}).then(function () {
	window.sentientFormsAppReady = "ready";
}).catch(function (error) {
	console.error("Sentient Forms SPA failed to start", error);
	window.sentientFormsAppReady = "failed";
});',
					wp_json_encode( $this->spa_start_module_url ),
					wp_json_encode( $this->spa_app_module_url ),
					$this->spa_bootstrap_script
				)
			);
			}

		echo '</div></div>';
    }


    private function has_ready_local_provider(): bool
    {
        if ( ! class_exists( 'Sentient_Forms_Provider_Credentials_Repository' ) )
        {
            return false;
        }

        global $wpdb;
        $repository = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        foreach ( $repository->list( [ 'limit' => 20 ] ) as $credential )
        {
            $provider = sanitize_key( (string) ( $credential['provider'] ?? '' ) );
            $status   = sanitize_key( (string) ( $credential['status'] ?? '' ) );
            if ( in_array( $provider, [ 'openrouter', 'sentient_managed' ], true ) && in_array( $status, [ 'valid', 'limited' ], true ) )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Admin notice for missing local provider setup.
     */
    public function admin_notice_missing_api_key(): void
    {
        // Only show on Sentient Forms pages or if explicitly needed globally
        $current_screen = get_current_screen();
        if ( $current_screen && str_contains( $current_screen->id, 'sentient-forms' ) ) {
            $providers_url = admin_url( 'admin.php?page=sentient-forms#/providers' );
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <?php
                    printf(
                        /* translators: %s: providers page URL. */
                        wp_kses_post( __( '<strong>Sentient Forms:</strong> Connect OpenRouter or Sentient Forms managed service on the <a href="%s">Providers</a> page before enabling AI actions.', 'sentient-forms' ) ),
                        esc_url( $providers_url )
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
                        /* translators: %s: license page URL. */
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
	        check_ajax_referer( 'sentient_forms_admin_nonce', 'nonce' );

        if ( !current_user_can( 'manage_options' ) )
        {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sentient-forms' ) ], 403 );
        }

	        $settings_data = isset( $_POST[ 'sentient_forms_settings' ] ) && is_array( $_POST[ 'sentient_forms_settings' ] )
	            ? map_deep( wp_unslash( $_POST[ 'sentient_forms_settings' ] ), 'sanitize_text_field' )
	            : [];

        // Sanitize settings data before saving
        $sanitized_settings = [];
        if (isset($settings_data['default_llm'])) {
            $sanitized_settings['default_llm'] = sanitize_text_field($settings_data['default_llm']);
        }
        if ( isset( $settings_data['enable_logging'] ) ) {
            $sanitized_settings['enable_logging'] = rest_sanitize_boolean( $settings_data['enable_logging'] );
        } else {
            $sanitized_settings['enable_logging'] = false;
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
	        check_ajax_referer( 'sentient_forms_admin_nonce', 'nonce' );

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
	        check_ajax_referer( 'sentient_forms_admin_nonce', 'nonce' );
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
	        check_ajax_referer( 'sentient_forms_admin_nonce', 'nonce' );
        if ( !current_user_can( 'manage_options' ) )
        {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sentient-forms' ) ], 403 );
            return;
        }

	        $form_id      = isset( $_POST[ 'form_id' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'form_id' ] ) ) : '';
	        $adapter_id   = isset( $_POST[ 'adapter_id' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'adapter_id' ] ) ) : '';
	        $settings_raw = isset( $_POST[ 'settings' ] ) && is_array( $_POST[ 'settings' ] ) ? map_deep( wp_unslash( $_POST[ 'settings' ] ), 'sanitize_text_field' ) : [];


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
	        check_ajax_referer( 'sentient_forms_admin_nonce', 'nonce' );
        if ( !current_user_can( 'manage_options' ) )
        {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sentient-forms' ) ], 403 );
            return;
        }

	        $action_id = isset( $_POST[ 'action_id' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'action_id' ] ) ) : '';
	        $action_settings_values_param = isset( $_POST[ 'action_settings' ] ) && is_array( $_POST[ 'action_settings' ] ) ? map_deep( wp_unslash( $_POST[ 'action_settings' ] ), 'sanitize_text_field' ) : [];

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
        check_ajax_referer( 'sentient_forms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sentient-forms' ) ], 403 );
        }

        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => __( 'API Key is required.', 'sentient-forms' ) ], 400 );
        }

        // Use the provided API key for this test, not necessarily the saved one.
        $api_client = new Sentient_Forms_Llm_Api_Client( $api_key );
        $response = $api_client->get_available_models();

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        } elseif ( is_array( $response ) ) {
            wp_send_json_success( [ 'message' => __( 'Connection successful.', 'sentient-forms' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Connection test failed. The API responded, but the data format was unexpected.', 'sentient-forms' ), 'response' => $response ] );
        }
    }

    /**
     * AJAX compatibility handler for the retired credit balance action.
     */
    public function ajax_get_credit_balance(): void
    {
        check_ajax_referer( 'sentient_forms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sentient-forms' ) ], 403 );
        }

        wp_send_json_error(
            [
                'code'    => 'sentient_forms_credit_balance_retired',
                'message' => __( 'The legacy Sentient credit balance route is retired in local-first mode. Use the Licensing billing-state view for managed plan allowance.', 'sentient-forms' ),
            ],
            410
        );
    }

}
