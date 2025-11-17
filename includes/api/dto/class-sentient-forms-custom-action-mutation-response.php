<?php
/**
 * DTO for CPS custom-action mutation envelopes.
 *
 * @package SentientForms
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Holds the action + quota returned by CPS after create/update/archive/reactivate.
 */
class Sentient_Forms_Custom_Action_Mutation_Response
{
    public function __construct(
        private Sentient_Forms_Custom_Action_Response $action,
        private Sentient_Forms_Custom_Action_Quota $quota
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function from_api_payload( array $payload ): self
    {
        if ( !isset( $payload['action'] ) || !is_array( $payload['action'] ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Custom action mutation payload missing action block',
                Sentient_Forms_Error_Type::invalid_response
            );
        }

        if ( !isset( $payload['quota'] ) || !is_array( $payload['quota'] ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Custom action mutation payload missing quota block',
                Sentient_Forms_Error_Type::invalid_response
            );
        }

        return new self(
            Sentient_Forms_Custom_Action_Response::from_api_payload( $payload['action'] ),
            Sentient_Forms_Custom_Action_Quota::from_api_payload( $payload['quota'] )
        );
    }

    /**
     * @return array{action: array<string, mixed>, quota: array<string, int>}
     */
    public function to_array(): array
    {
        return [
            'action' => $this->action->to_array(),
            'quota'  => $this->quota->to_array(),
        ];
    }
}
