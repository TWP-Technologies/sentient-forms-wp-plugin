<?php

class Tests_Custom_Actions_Service extends WP_UnitTestCase
{
    private Sentient_Forms_Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin = Sentient_Forms_Plugin::instance();
        $this->plugin->set_license_data(
            [
                'proxy_api_key' => 'proxy-test-key',
            ]
        );
    }

    protected function tearDown(): void
    {
        $this->plugin->clear_license_data();
        parent::tearDown();
    }

    public function test_archive_uses_archive_post_route(): void
    {
        $client = new class extends Sentient_Forms_Api_Client {
            public array $calls = [];

            public function __construct() {}

            public function post( string $path, array $payload, array $options = [] ): WP_Error | array
            {
                $this->calls[] = [
                    'method'  => 'post',
                    'path'    => $path,
                    'payload' => $payload,
                    'options' => $options,
                ];

                return [
                    'action' => [
                        'id'                       => 'custom-action-1',
                        'template_id'              => wp_generate_uuid4(),
                        'code'                     => 'custom-action-code',
                        'display_name'             => 'Archived action',
                        'description'              => null,
                        'prompt_overrides'         => [],
                        'model_hint'               => null,
                        'base_credit_cost'         => null,
                        'status'                   => 'archived',
                        'archived_at'              => '2026-03-12T00:00:00Z',
                        'created_at'               => '2026-03-12T00:00:00Z',
                        'updated_at'               => '2026-03-12T00:00:00Z',
                        'action_kind'              => 'template_override',
                        'definition'               => null,
                        'definition_version'       => 1,
                        'output_contract'          => null,
                        'supported_execution_modes' => [ 'after_submission' ],
                    ],
                    'quota'  => [
                        'quota_max'       => 240,
                        'quota_used'      => 0,
                        'quota_remaining' => 240,
                    ],
                ];
            }

            public function delete( string $path, array $payload = [], array $options = [] ): WP_Error | array
            {
                $this->calls[] = [
                    'method'  => 'delete',
                    'path'    => $path,
                    'payload' => $payload,
                    'options' => $options,
                ];

                return new WP_Error( 'unexpected_delete', 'Archive should not use DELETE.' );
            }
        };

        $service = new Sentient_Forms_Custom_Actions_Service( $this->plugin, $client );
        $result  = $service->archive( 'custom-action-1', 'codex-test' );

        $this->assertInstanceOf( Sentient_Forms_Custom_Action_Mutation_Response::class, $result );
        $this->assertCount( 1, $client->calls );
        $this->assertSame( 'post', $client->calls[0]['method'] );
        $this->assertSame( '/custom-actions/custom-action-1/archive', $client->calls[0]['path'] );
        $this->assertSame( 'codex-test', $client->calls[0]['payload']['actor_hint'] ?? null );
        $this->assertSame( 'proxy-test-key', $client->calls[0]['options']['bearer_token'] ?? null );
    }
}
