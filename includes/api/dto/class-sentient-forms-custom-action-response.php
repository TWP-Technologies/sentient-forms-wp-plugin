<?php
/**
 * DTO for CPS custom action payloads.
 *
 * Mirrors contracts/v1/actions/custom-action-response.schema.json so PHP callers
 * can rely on typed accessors instead of ad-hoc arrays.
 *
 * @package SentientForms
 */

if ( !defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly.
}

/**
 * Represents a CPS custom action record.
 */
class Sentient_Forms_Custom_Action_Response
{
    public function __construct(
        private string $id,
        private string $template_id,
        private string $code,
        private string $display_name,
        private ?string $description,
        private array $prompt_overrides,
        private ?string $model_hint,
        private ?int $base_credit_cost,
        private string $status,
        private ?string $archived_at,
        private string $created_at,
        private string $updated_at
    ) {
    }

    /**
     * Build the DTO from a CPS response payload.
     *
     * @param array<string, mixed> $payload Raw data returned by CPS.
     *
     * @return self
     */
    public static function from_api_payload( array $payload ): self
    {
        $required_keys = [
            'id',
            'template_id',
            'code',
            'display_name',
            'prompt_overrides',
            'status',
            'created_at',
            'updated_at',
        ];

        foreach ( $required_keys as $key )
        {
            if ( !array_key_exists( $key, $payload ) )
            {
                Sentient_Forms_Error_Utils::throw_or_die(
                    sprintf( 'Custom action payload missing required field: %s', $key ),
                    Sentient_Forms_Error_Type::invalid_response
                );
            }
        }

        return new self(
            (string) $payload['id'],
            (string) $payload['template_id'],
            (string) $payload['code'],
            (string) $payload['display_name'],
            isset( $payload['description'] ) ? (string) $payload['description'] : null,
            is_array( $payload['prompt_overrides'] ) ? $payload['prompt_overrides'] : [],
            isset( $payload['model_hint'] ) ? (string) $payload['model_hint'] : null,
            isset( $payload['base_credit_cost'] ) ? (int) $payload['base_credit_cost'] : null,
            (string) $payload['status'],
            isset( $payload['archived_at'] ) ? (string) $payload['archived_at'] : null,
            (string) $payload['created_at'],
            (string) $payload['updated_at']
        );
    }

    /**
     * Export the DTO as an array for REST responses/stores.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        return [
            'id'               => $this->id,
            'template_id'      => $this->template_id,
            'code'             => $this->code,
            'display_name'     => $this->display_name,
            'description'      => $this->description,
            'prompt_overrides' => $this->prompt_overrides,
            'model_hint'       => $this->model_hint,
            'base_credit_cost' => $this->base_credit_cost,
            'status'           => $this->status,
            'archived_at'      => $this->archived_at,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_prompt_overrides(): array
    {
        return $this->prompt_overrides;
    }
}
