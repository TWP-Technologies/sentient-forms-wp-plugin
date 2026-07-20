<?php
/**
 * Process-local proof that a durable execution request was claimed.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Local_Execution_Claim
{
    private function __construct(
        private string $execution_request_id,
        private string $record_type
    )
    {
    }

    /**
     * Create an internal capability from the authoritative request-store claim.
     *
     * @param array<string, mixed> $claim Claim result returned by the request store.
     */
    public static function from_request_store_claim(
        array $claim,
        string $execution_request_id,
        string $record_type
    ): self | WP_Error
    {
        $record = isset( $claim['record'] ) && is_array( $claim['record'] ) ? $claim['record'] : null;
        if (
            'claimed' !== sanitize_key( (string) ( $claim['state'] ?? '' ) )
            || null === $record
            || ! hash_equals( $execution_request_id, (string) ( $record['request_hash'] ?? '' ) )
            || $record_type !== sanitize_key( (string) ( $record['record_type'] ?? '' ) )
            || 'running' !== sanitize_key( (string) ( $record['status'] ?? '' ) )
        )
        {
            return new WP_Error(
                'sentient_forms_local_execution_claim_invalid',
                __( 'The durable execution request claim could not be verified.', 'sentient-forms' )
            );
        }

        return new self( $execution_request_id, $record_type );
    }

    public function attests( string $execution_request_id, string $record_type ): bool
    {
        return hash_equals( $this->execution_request_id, $execution_request_id )
            && $this->record_type === $record_type;
    }
}
