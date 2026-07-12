<?php

final class Sentient_Forms_Validation_Rejection_Trace_Emitter_Test extends WP_UnitTestCase
{
    public function test_result_exposes_mapping_keyed_rejection_trace_metadata(): void
    {
        $result = new Sentient_Forms_Validation_Run_Result(
            execution_request_ids: [ 'mapping-b' => 'request-b' ],
            validation_rejection_traces: [
                'mapping-b' => [
                    'request_trace_id'   => 'request-b',
                    'rejection_trace_id' => 'validation-rejection:11111111-1111-4111-8111-111111111111',
                ],
            ]
        );

        $this->assertSame(
            [
                'mapping-b' => [
                    'request_trace_id'   => 'request-b',
                    'rejection_trace_id' => 'validation-rejection:11111111-1111-4111-8111-111111111111',
                ],
            ],
            $result->get_validation_rejection_traces()
        );
    }

    public function test_emitter_writes_one_deterministic_url_encoded_metadata_only_header(): void
    {
        $headers = [];
        $emitter = new Sentient_Forms_Validation_Rejection_Trace_Emitter(
            static function ( string $name, string $value, bool $replace ) use ( &$headers ): void {
                $headers[] = [ $name, $value, $replace ];
            }
        );
        $result = new Sentient_Forms_Validation_Run_Result(
            validation_rejection_traces: [
                'mapping-z' => [
                    'request_trace_id'   => 'request-z',
                    'rejection_trace_id' => 'validation-rejection:request-z',
                ],
                'mapping-a' => [
                    'request_trace_id'   => 'request-a',
                    'rejection_trace_id' => 'validation-rejection:request-a',
                ],
            ]
        );

        $emitter->emit( $result );

        $this->assertCount( 1, $headers );
        $this->assertSame( 'X-Sentient-Forms-Validation-Trace', $headers[0][0] );
        $this->assertTrue( $headers[0][2], 'The transport must replace a prior trace header instead of appending invalid comma-joined JSON.' );
        $this->assertSame(
            [
                'rejections' => [
                    [
                        'mapping_id'        => 'mapping-a',
                        'request_trace_id'  => 'request-a',
                        'rejection_trace_id' => 'validation-rejection:request-a',
                    ],
                    [
                        'mapping_id'        => 'mapping-z',
                        'request_trace_id'  => 'request-z',
                        'rejection_trace_id' => 'validation-rejection:request-z',
                    ],
                ],
            ],
            json_decode( rawurldecode( $headers[0][1] ), true )
        );
        $this->assertStringNotContainsString( 'payload', rawurldecode( $headers[0][1] ) );
        $this->assertStringNotContainsString( 'message', rawurldecode( $headers[0][1] ) );
        $this->assertStringNotContainsString( 'error', rawurldecode( $headers[0][1] ) );
    }

    public function test_emitter_omits_header_when_result_has_no_rejections(): void
    {
        $headers = [];
        $emitter = new Sentient_Forms_Validation_Rejection_Trace_Emitter(
            static function ( string $name, string $value ) use ( &$headers ): void {
                $headers[] = [ $name, $value ];
            }
        );

        $emitter->emit( new Sentient_Forms_Validation_Run_Result() );

        $this->assertSame( [], $headers );
    }

    public function test_emitter_omits_header_when_all_trace_entries_are_malformed(): void
    {
        $headers = [];
        $emitter = new Sentient_Forms_Validation_Rejection_Trace_Emitter(
            static function ( string $name, string $value ) use ( &$headers ): void {
                $headers[] = [ $name, $value ];
            }
        );
        $result = new Sentient_Forms_Validation_Run_Result(
            validation_rejection_traces: [
                'mapping-empty' => [
                    'request_trace_id'   => '',
                    'rejection_trace_id' => '',
                ],
                'mapping-mismatch' => [
                    'request_trace_id'   => 'request-real',
                    'rejection_trace_id' => 'validation-rejection:request-other',
                ],
                '   ' => [
                    'request_trace_id'   => 'request-whitespace',
                    'rejection_trace_id' => 'validation-rejection:request-whitespace',
                ],
            ]
        );

        $emitter->emit( $result );

        $this->assertSame( [], $headers );
    }
}
