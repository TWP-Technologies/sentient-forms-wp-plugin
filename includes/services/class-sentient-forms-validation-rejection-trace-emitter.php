<?php
/**
 * Emits metadata-only visitor validation rejection traces.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Emits metadata-only validation rejection evidence through a response header.
 */
final class Sentient_Forms_Validation_Rejection_Trace_Emitter
{
    /** Header carrying URL-encoded validation rejection evidence. */
    public const HEADER_NAME = 'X-Sentient-Forms-Validation-Trace';

    /** @var callable */
    private $header_sink;

    /**
     * @param callable|null $header_sink Header writer used by the current Form Source transport.
     */
    public function __construct( ?callable $header_sink = null )
    {
        $this->header_sink = $header_sink ?? static function ( string $name, string $value, bool $replace ): void {
            if ( ! headers_sent() )
            {
                header( $name . ': ' . $value, $replace );
            }
        };
    }

    /**
     * Emit validated metadata-only rejection traces for a visitor validation run.
     */
    public function emit( Sentient_Forms_Validation_Run_Result $result ): void
    {
        $traces = $result->get_validation_rejection_traces();
        if ( [] === $traces )
        {
            return;
        }

        ksort( $traces, SORT_STRING );
        $rejections = [];
        foreach ( $traces as $mapping_id => $trace )
        {
            $mapping_id         = (string) $mapping_id;
            $request_trace_id   = is_array( $trace ) ? (string) ( $trace['request_trace_id'] ?? '' ) : '';
            $rejection_trace_id = is_array( $trace ) ? (string) ( $trace['rejection_trace_id'] ?? '' ) : '';
            if (
                '' === trim( $mapping_id )
                || '' === $request_trace_id
                || 'validation-rejection:' . $request_trace_id !== $rejection_trace_id
            )
            {
                continue;
            }

            $rejections[] = [
                'mapping_id'         => $mapping_id,
                'request_trace_id'   => $request_trace_id,
                'rejection_trace_id' => $rejection_trace_id,
            ];
        }

        if ( [] === $rejections )
        {
            return;
        }

        $encoded = rawurlencode( (string) wp_json_encode( [ 'rejections' => $rejections ] ) );
        call_user_func( $this->header_sink, self::HEADER_NAME, $encoded, true );
    }
}
