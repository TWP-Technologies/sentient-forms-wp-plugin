<?php

/**
 * Public contract tests for the plugin-owned Action cutover.
 */
final class LegacyCpsRuntimeDetachmentTest extends WP_UnitTestCase
{
    /**
     * @var array<int, string>
     */
    private const RETIRED_CLASSES = [
        'Sentient_Forms_Action_Executor',
        'Sentient_Forms_Action_Registry',
        'Sentient_Forms_Custom_Actions_Service',
        'Sentient_Forms_Execution_Status_Controller',
        'Sentient_Forms_Licensing_Api_Client',
        'Sentient_Forms_Llm_Api_Client',
        'Sentient_Forms_Llm_Controller',
        'Sentient_Forms_Managed_Execution_Callback_Controller',
        'Sentient_Forms_Mappings_Controller',
        'Sentient_Forms_Mappings_Migrate_CLI_Command',
        'Sentient_Forms_Mappings_Migration_Service',
        'Sentient_Forms_Mappings_Sync',
    ];

    public function test_legacy_cps_runtime_classes_are_not_available(): void
    {
        foreach ( self::RETIRED_CLASSES as $class_name )
        {
            $this->assertFalse( class_exists( $class_name ), "Legacy CPS runtime class {$class_name} must remain retired." );
        }
    }

    public function test_plugin_exposes_only_local_action_runtime_entrypoints(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();

        foreach ( [ 'get_action', 'get_action_executor', 'get_action_registry', 'get_api_client', 'get_cps_api_client' ] as $method_name )
        {
            $this->assertFalse( method_exists( $plugin, $method_name ), "Legacy CPS runtime method {$method_name} must remain retired." );
        }

        $this->assertTrue( method_exists( $plugin, 'execute_local_action_mapping' ) );
        $this->assertTrue( method_exists( $plugin, 'get_local_action_execution_service' ) );
    }

    public function test_stale_action_mapping_error_requires_replacement_instead_of_resave(): void
    {
        $result = Sentient_Forms_Plugin::instance()->execute_local_action_mapping( [], [], [] );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'sentient_forms_local_mapping_required', $result->get_error_code() );
        $this->assertSame(
            'This Action mapping predates local Action authority and must be replaced with a plugin-owned local Action mapping before it can run.',
            $result->get_error_message()
        );
    }

    public function test_async_handler_exposes_no_legacy_cps_execution_entrypoints(): void
    {
        foreach ( [
            'schedule_action',
            'process_action',
            'complete_remote_cps_async_success',
            'complete_remote_cps_async_failure',
        ] as $method_name )
        {
            $this->assertFalse(
                method_exists( Sentient_Forms_Async_Handler::class, $method_name ),
                "Legacy CPS async method {$method_name} must remain retired."
            );
        }
    }

    public function test_default_cps_base_url_targets_v2(): void
    {
        $this->assertSame( 'https://api.sentientforms.com/v2', SENTIENT_FORMS_DEFAULT_CPS_BASE_URL );
    }

    public function test_local_and_ci_cps_defaults_target_v2(): void
    {
        $plugin_root = dirname( __DIR__, 2 );
        $fixtures    = [
            'tests/bootstrap.php'          => 'http://cps-api:8080/v2',
            '.github/workflows/full-qa.yml' => 'http://localhost:10081/v2',
            'scripts/run-full-qa.sh'       => '/v2/health',
        ];

        foreach ( $fixtures as $relative_path => $expected_v2_value )
        {
            $contents = file_get_contents( $plugin_root . '/' . $relative_path );
            $this->assertIsString( $contents, "Unable to read {$relative_path}." );
            $this->assertStringContainsString( $expected_v2_value, $contents, "{$relative_path} must target CPS v2." );
        }
    }

    public function test_legacy_cps_ownership_routes_are_not_registered(): void
    {
        do_action( 'rest_api_init', rest_get_server() );
        $routes = rest_get_server()->get_routes();

        foreach ( [
            '/sentient-forms/v1/execution-callback',
            '/sentient-forms/v1/execution-status',
            '/sentient-forms/v1/llms/models',
            '/sentient-forms/v1/mappings',
            '/sentient-forms/v1/mappings/templates',
        ] as $route )
        {
            $this->assertArrayNotHasKey( $route, $routes, "Legacy CPS ownership route {$route} must remain retired." );
        }

        $this->assertArrayHasKey( '/sentient-forms/v1/actions/definitions', $routes );
        $this->assertArrayHasKey( '/sentient-forms/v1/local/custom-actions', $routes );
    }
}
