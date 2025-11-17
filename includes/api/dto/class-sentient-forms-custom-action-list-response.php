<?php
/**
 * DTO representing the list envelope returned by CPS custom-action list route.
 *
 * @package SentientForms
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Holds an array of custom actions plus quota metadata.
 */
class Sentient_Forms_Custom_Action_List_Response
{
    /**
     * @param array<int, Sentient_Forms_Custom_Action_Response> $actions
     */
    public function __construct(
        private array $actions,
        private Sentient_Forms_Custom_Action_Quota $quota
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function from_api_payload( array $payload ): self
    {
        if ( !isset( $payload['actions'] ) || !is_array( $payload['actions'] ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Custom action list payload missing actions array',
                Sentient_Forms_Error_Type::invalid_response
            );
        }

        $actions = [];
        foreach ( $payload['actions'] as $action_payload )
        {
            if ( !is_array( $action_payload ) )
            {
                Sentient_Forms_Error_Utils::throw_or_die(
                    'Custom action list payload contains invalid action entry',
                    Sentient_Forms_Error_Type::invalid_response
                );
            }
            $actions[] = Sentient_Forms_Custom_Action_Response::from_api_payload( $action_payload );
        }

        if ( !isset( $payload['quota'] ) || !is_array( $payload['quota'] ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Custom action list payload missing quota block',
                Sentient_Forms_Error_Type::invalid_response
            );
        }

        $quota = Sentient_Forms_Custom_Action_Quota::from_api_payload( $payload['quota'] );

        return new self( $actions, $quota );
    }

    /**
     * @return array{actions: array<int, array<string, mixed>>, quota: array<string, int>}
     */
    public function to_array(): array
    {
        return [
            'actions' => array_map(
                static fn( Sentient_Forms_Custom_Action_Response $action ) => $action->to_array(),
                $this->actions
            ),
            'quota'   => $this->quota->to_array(),
        ];
    }
}
