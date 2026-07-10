<?php
/**
 * Source-neutral accepted-submission run result.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Accepted_Submission_Run_Result
{
    /**
     * @param array<string, string>               $mapping_outcomes
     * @param array<string, array<string, mixed>> $resolved_mappings
     * @param array<string, mixed>                $execution_results
     */
    public function __construct(
        private ?string $submission_uuid,
        private array $mapping_outcomes = [],
        private array $resolved_mappings = [],
        private array $execution_results = []
    )
    {
    }

    public function get_submission_uuid(): ?string
    {
        return $this->submission_uuid;
    }

    /**
     * @return array<string, string>
     */
    public function get_mapping_outcomes(): array
    {
        return $this->mapping_outcomes;
    }

    /**
     * @return array<int, string>
     */
    public function get_queued_mapping_ids(): array
    {
        return array_keys(
            array_filter(
                $this->mapping_outcomes,
                static fn ( string $outcome ): bool => in_array( $outcome, [ 'queued', 'replayed' ], true )
            )
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_resolved_mapping( string $mapping_id ): ?array
    {
        $mapping = $this->resolved_mappings[ $mapping_id ] ?? null;

        return is_array( $mapping ) ? $mapping : null;
    }

    public function get_execution_result( string $mapping_id ): mixed
    {
        return $this->execution_results[ $mapping_id ] ?? null;
    }
}
