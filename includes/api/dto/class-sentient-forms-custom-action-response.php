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
    /**
     * @param string $id
     * @param string $template_id
     * @param string $code
     * @param string $display_name
     * @param string|null $description
     * @param array<string, mixed> $prompt_overrides
     * @param string|null $model_hint
     * @param int|null $base_credit_cost
     * @param string $status
     * @param string|null $archived_at
     * @param string $created_at
     * @param string $updated_at
     * @param string $action_kind New: 'template_override' or 'custom_definition'
     * @param array<string, mixed>|null $definition New: Full action definition JSONB
     * @param int $definition_version New: Schema version for definition
     * @param array<string, mixed>|null $output_contract New: Expected structured output format
     * @param array<int, string> $supported_execution_modes New: Allowed execution modes
     */
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
        private string $updated_at,
        // New definition fields (CA-DEF-001)
        private string $action_kind,
        private ?array $definition,
        private int $definition_version,
        private ?array $output_contract,
        private array $supported_execution_modes
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
            'action_kind',
            'definition_version',
            'supported_execution_modes',
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
            (string) $payload['updated_at'],
            // New definition fields
            (string) $payload['action_kind'],
            isset( $payload['definition'] ) && is_array( $payload['definition'] ) ? $payload['definition'] : null,
            (int) $payload['definition_version'],
            isset( $payload['output_contract'] ) && is_array( $payload['output_contract'] ) ? $payload['output_contract'] : null,
            is_array( $payload['supported_execution_modes'] ) ? $payload['supported_execution_modes'] : []
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
            'id'                        => $this->id,
            'template_id'               => $this->template_id,
            'code'                      => $this->code,
            'display_name'              => $this->display_name,
            'description'               => $this->description,
            'prompt_overrides'          => $this->prompt_overrides,
            'model_hint'                => $this->model_hint,
            'base_credit_cost'          => $this->base_credit_cost,
            'status'                    => $this->status,
            'archived_at'               => $this->archived_at,
            'created_at'                => $this->created_at,
            'updated_at'                => $this->updated_at,
            // New definition fields (CA-DEF-001)
            'action_kind'               => $this->action_kind,
            'definition'                => $this->definition,
            'definition_version'        => $this->definition_version,
            'output_contract'           => $this->output_contract,
            'supported_execution_modes' => $this->supported_execution_modes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_prompt_overrides(): array
    {
        return $this->prompt_overrides;
    }

    /**
     * @return string 'template_override' or 'custom_definition'
     */
    public function get_action_kind(): string
    {
        return $this->action_kind;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_definition(): ?array
    {
        return $this->definition;
    }

    /**
     * @return int
     */
    public function get_definition_version(): int
    {
        return $this->definition_version;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_output_contract(): ?array
    {
        return $this->output_contract;
    }

    /**
     * @return array<int, string>
     */
    public function get_supported_execution_modes(): array
    {
        return $this->supported_execution_modes;
    }
}

