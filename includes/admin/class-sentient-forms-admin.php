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
			'optIn'     => ! empty( $settings['telemetry_opt_in'] ),
			'updatedAt' => $settings['updated_at'] ?? null,
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
			$descriptor = method_exists( $registry, 'get_capability_descriptor' )
				? $registry->get_capability_descriptor( $adapter->get_id() )
				: null;
			$descriptor = is_array( $descriptor ) ? $descriptor : [];
			$requirements = is_array( $descriptor['requirements'] ?? null ) ? $descriptor['requirements'] : [];

			$sources[] = [
				'slug'                => (string) ( $descriptor['slug'] ?? $adapter->get_id() ),
				'label'               => (string) ( $descriptor['label'] ?? $adapter->get_name() ),
				'isActive'            => (bool) ( $descriptor['is_active'] ?? $adapter->is_active() ),
				'availability'        => (string) ( $descriptor['availability'] ?? ( $adapter->is_active() ? 'available' : 'inactive' ) ),
				'availabilityMessage' => (string) ( $descriptor['availability_message'] ?? '' ),
				'requiresPro'         => ! empty( $requirements['requires_pro'] ),
				'descriptor'          => $descriptor,
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
        $content .= '<p>' . esc_html__( 'The optional diagnostic-consent setting enables metadata-only local events. The separate on-site logging setting must also be enabled for the plugin to write those events to its masked log. Allowed metadata includes plugin/runtime versions, provider path, action code, execution request ID, adapter, job status, attempt counts, and sanitized error or warning codes. Diagnostic events exclude form field contents, prompts, model outputs, raw error messages, visitor identifiers, saved provider secrets, and billing secrets. Debug mode cannot bypass consent, no telemetry leaves this site in this release, and either setting can be turned off at any time.', 'sentient-forms' ) . '</p>';
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

}
