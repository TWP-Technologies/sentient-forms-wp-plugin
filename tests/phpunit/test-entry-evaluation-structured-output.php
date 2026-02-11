<?php
/**
 * Tests for CA-EXEC-001: Entry Evaluation structured output handling.
 *
 * Verifies that process_response() prefers structured_output from CPS
 * over regex-parsing llm_output when structured_output_valid is true.
 *
 * @package Sentient_Forms
 */

class Tests_Entry_Evaluation_Structured_Output extends WP_UnitTestCase {

	private Sentient_Forms_Plugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		$this->plugin = Sentient_Forms_Plugin::instance();
	}

	/**
	 * T-PHP-030: process_response() uses structured_output directly when valid.
	 */
	public function test_process_response_prefers_structured_output_when_valid(): void {
		$action = new Sentient_Forms_Entry_Evaluation_Action( $this->plugin );

		// Build a response with both llm_output AND structured_output.
		// The structured_output has different content to prove it is preferred.
		$response = [
			'result_data' => [
				'llm_output'             => '{"summary":"from llm_output","sentiment":"negative"}',
				'structured_output'      => [
					'summary'            => 'Structured summary text',
					'key_points'         => [ 'Point A', 'Point B' ],
					'sentiment'          => 'positive',
					'suggested_tags'     => [ 'tag1', 'tag2' ],
					'suggested_response' => 'Thank you for contacting us.',
				],
				'structured_output_valid' => true,
				'output_schema_version'   => 3,
			],
		];

		$payload  = [
			'form'  => [ 'id' => 1 ],
			'entry' => [ 'id' => 0 ], // No real entry to avoid GF API calls
		];
		$settings = [ 'add_note' => false, 'notify_admin' => false ];

		// Use reflection to call protected process_response().
		$method = new ReflectionMethod( $action, 'process_response' );
		$method->setAccessible( true );
		$result = $method->invoke( $action, $response, $payload, $settings );

		// Assert structured_output values were used, not llm_output.
		$this->assertSame( 'Structured summary text', $result['summary'] );
		$this->assertSame( 'positive', $result['sentiment'] );
		$this->assertSame( [ 'Point A', 'Point B' ], $result['key_points'] );
		$this->assertSame( [ 'tag1', 'tag2' ], $result['suggested_tags'] );
		$this->assertSame( 'Thank you for contacting us.', $result['suggested_response'] );
		$this->assertSame( 3, $result['output_schema_version'] );
		$this->assertNull( $result['error'] );
	}

	/**
	 * T-PHP-031: process_response() falls back to llm_output when structured_output absent.
	 */
	public function test_process_response_falls_back_to_llm_output_when_structured_output_absent(): void {
		$action = new Sentient_Forms_Entry_Evaluation_Action( $this->plugin );

		$response = [
			'result_data' => [
				'llm_output' => '{"summary":"Legacy summary","sentiment":"neutral","key_points":["Only point"]}',
			],
		];

		$payload  = [
			'form'  => [ 'id' => 1 ],
			'entry' => [ 'id' => 0 ],
		];
		$settings = [ 'add_note' => false, 'notify_admin' => false ];

		$method = new ReflectionMethod( $action, 'process_response' );
		$method->setAccessible( true );
		$result = $method->invoke( $action, $response, $payload, $settings );

		$this->assertSame( 'Legacy summary', $result['summary'] );
		$this->assertSame( 'neutral', $result['sentiment'] );
		$this->assertSame( [ 'Only point' ], $result['key_points'] );
		$this->assertNull( $result['error'] );
		$this->assertArrayNotHasKey( 'output_schema_version', $result );
	}

	/**
	 * T-PHP-032: process_response() falls back when structured_output_valid is false.
	 */
	public function test_process_response_falls_back_when_structured_output_invalid(): void {
		$action = new Sentient_Forms_Entry_Evaluation_Action( $this->plugin );

		$response = [
			'result_data' => [
				'llm_output'              => '{"summary":"Fallback summary","sentiment":"positive"}',
				'structured_output'       => null,
				'structured_output_valid' => false,
				'output_schema_version'   => 2,
			],
		];

		$payload  = [
			'form'  => [ 'id' => 1 ],
			'entry' => [ 'id' => 0 ],
		];
		$settings = [ 'add_note' => false, 'notify_admin' => false ];

		$method = new ReflectionMethod( $action, 'process_response' );
		$method->setAccessible( true );
		$result = $method->invoke( $action, $response, $payload, $settings );

		// Should fall back to llm_output parsing.
		$this->assertSame( 'Fallback summary', $result['summary'] );
		$this->assertSame( 'positive', $result['sentiment'] );
		$this->assertNull( $result['error'] );
	}
}
