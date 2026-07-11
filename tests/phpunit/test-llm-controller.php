<?php

final class Tests_Llm_Controller extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        delete_transient( Sentient_Forms_Llm_Controller::MODELS_TRANSIENT_KEY );
        delete_transient( Sentient_Forms_Llm_Controller::API_ERROR_TRANSIENT_KEY );
        remove_all_filters( 'pre_http_request' );
        parent::tearDown();
    }

    public function test_model_catalog_reads_openrouter_envelope_without_managed_credential(): void
    {
        delete_transient( Sentient_Forms_Llm_Controller::MODELS_TRANSIENT_KEY );
        $calls = [];
        add_filter(
            'pre_http_request',
            static function ( mixed $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = compact( 'args', 'url' );
                return [
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'headers'  => [],
                    'cookies'  => [],
                    'body'     => wp_json_encode(
                        [
                            'data' => [
                                [
                                    'id'           => 'example/model',
                                    'name'         => 'Example Model',
                                    'description'  => 'Fixture',
                                    'provider'     => 'example',
                                    'status'       => 'active',
                                    'cost_tier'    => 'paid',
                                    'capabilities' => [ 'chat' ],
                                ],
                            ],
                        ]
                    ),
                ];
            },
            10,
            3
        );

        $response = ( new Sentient_Forms_Llm_Controller() )->get_models( new WP_REST_Request( 'GET', '/sentient-forms/v1/llms/models' ) );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 'example/model', $response->get_data()[0]['id'] ?? null );
        $this->assertCount( 1, $calls );
        $this->assertStringContainsString( 'openrouter.ai/api/v1/models', $calls[0]['url'] );
        $this->assertArrayNotHasKey( 'Authorization', $calls[0]['args']['headers'] ?? [] );
    }
}
