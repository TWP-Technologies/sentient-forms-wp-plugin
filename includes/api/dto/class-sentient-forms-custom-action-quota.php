<?php
/**
 * DTO representing the quota block returned by CPS custom-action endpoints.
 *
 * @package SentientForms
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Encapsulates quota metadata for UI rendering and enforcement.
 */
class Sentient_Forms_Custom_Action_Quota
{
    public function __construct(
        private int $quota_max,
        private int $quota_used,
        private int $quota_remaining
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function from_api_payload( array $payload ): self
    {
        $required_keys = [ 'quota_max', 'quota_used', 'quota_remaining' ];
        foreach ( $required_keys as $key )
        {
            if ( !array_key_exists( $key, $payload ) )
            {
                Sentient_Forms_Error_Utils::throw_or_die(
                    sprintf( 'Custom action quota payload missing %s', $key ),
                    Sentient_Forms_Error_Type::invalid_response
                );
            }
        }

        return new self(
            (int) $payload['quota_max'],
            (int) $payload['quota_used'],
            (int) $payload['quota_remaining']
        );
    }

    /**
     * @return array<string, int>
     */
    public function to_array(): array
    {
        return [
            'quota_max'       => $this->quota_max,
            'quota_used'      => $this->quota_used,
            'quota_remaining' => $this->quota_remaining,
        ];
    }
}
