<?php

class Tests_Custom_Actions_Controller extends WP_UnitTestCase
{
    private Sentient_Forms_Custom_Actions_Controller $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new Sentient_Forms_Custom_Actions_Controller();
    }

    public function test_create_args_do_not_expose_base_credit_cost(): void
    {
        $args = $this->invoke_private( 'get_create_args' );

        $this->assertIsArray( $args );
        $this->assertArrayNotHasKey( 'base_credit_cost', $args );
    }

    public function test_update_args_do_not_expose_base_credit_cost(): void
    {
        $args = $this->invoke_private( 'get_update_args' );

        $this->assertIsArray( $args );
        $this->assertArrayNotHasKey( 'base_credit_cost', $args );
    }

    public function test_build_create_payload_ignores_base_credit_cost_input(): void
    {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/custom-actions' );
        $request->set_param( 'template_id', wp_generate_uuid4() );
        $request->set_param( 'code', 'pricing-locked-action' );
        $request->set_param( 'display_name', 'Pricing Locked Action' );
        $request->set_param( 'description', 'Should ignore any cost override input.' );
        $request->set_param( 'prompt_overrides', [ 'tone' => 'strict' ] );
        $request->set_param( 'model_hint', 'gemini-2.5-pro' );
        $request->set_param( 'base_credit_cost', 999 );

        $payload = $this->invoke_private( 'build_create_payload', [ $request ] );

        $this->assertIsArray( $payload );
        $this->assertArrayNotHasKey( 'base_credit_cost', $payload );
        $this->assertSame( 'pricing-locked-action', $payload['code'] ?? null );
        $this->assertSame( 'Pricing Locked Action', $payload['display_name'] ?? null );
    }

    public function test_build_update_payload_ignores_base_credit_cost_input(): void
    {
        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/custom-actions/test-id' );
        $request->set_param( 'display_name', 'Renamed Action' );
        $request->set_param( 'description', 'Updated description.' );
        $request->set_param( 'prompt_overrides', [ 'strictness' => 'high' ] );
        $request->set_param( 'model_hint', 'gemini-2.5-flash' );
        $request->set_param( 'base_credit_cost', 5 );

        $payload = $this->invoke_private( 'build_update_payload', [ $request ] );

        $this->assertIsArray( $payload );
        $this->assertArrayNotHasKey( 'base_credit_cost', $payload );
        $this->assertSame( 'Renamed Action', $payload['display_name'] ?? null );
        $this->assertSame( 'gemini-2.5-flash', $payload['model_hint'] ?? null );
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invoke_private( string $method, array $args = [] ): mixed
    {
        $reflection = new ReflectionMethod( $this->controller, $method );
        $reflection->setAccessible( true );

        return $reflection->invokeArgs( $this->controller, $args );
    }
}
