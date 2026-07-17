<?php
/**
 * Source-neutral validation run result.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Validation_Run_Result
{
    /**
     * @param array<string, string>               $mapping_outcomes
     * @param array<string, array<string, mixed>> $resolved_mappings
     * @param array<string, mixed>                $execution_results
     * @param array<string, array{code: string, message: string}> $errors
     * @param array<int, array{field_id: string, message: string}> $field_errors
     * @param array<string, string>               $spam_classifications
     * @param array<string, string>               $execution_request_ids
     * @param array<string, array<string, mixed>> $spam_payloads
     */
    public function __construct(
        private array $mapping_outcomes = [],
        private array $resolved_mappings = [],
        private array $execution_results = [],
        private array $errors = [],
        private ?string $form_error = null,
        private array $field_errors = [],
        private array $spam_classifications = [],
        private array $execution_request_ids = [],
        private array $spam_payloads = []
    )
    {
    }

    /**
     * @return array<string, string>
     */
    public function get_mapping_outcomes(): array
    {
        return $this->mapping_outcomes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_resolved_mappings(): array
    {
        return $this->resolved_mappings;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_execution_results(): array
    {
        return $this->execution_results;
    }

    public function get_execution_result( string $mapping_id ): mixed
    {
        return $this->execution_results[ $mapping_id ] ?? null;
    }

    /**
     * @return array<string, array{code: string, message: string}>
     */
    public function get_errors(): array
    {
        return $this->errors;
    }

    public function get_form_error(): ?string
    {
        return $this->form_error;
    }

    /**
     * @return array<int, array{field_id: string, message: string}>
     */
    public function get_field_errors(): array
    {
        return $this->field_errors;
    }

    /**
     * @return array<string, string>
     */
    public function get_spam_classifications(): array
    {
        return $this->spam_classifications;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_spam_payloads(): array
    {
        return $this->spam_payloads;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_spam_payload( string $mapping_id ): ?array
    {
        return $this->spam_payloads[ $mapping_id ] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function get_execution_request_ids(): array
    {
        return $this->execution_request_ids;
    }
}
