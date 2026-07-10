<?php
/**
 * Reusable Action facet definitions.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Action_Facet_Catalog
{
    /**
     * @param array<string, array<string, mixed>>|null $definitions Optional isolated catalog for tests or composition roots.
     */
    public function __construct( private ?array $definitions = null )
    {
        $this->definitions = $this->definitions ?? self::default_definitions();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * @return array<int, string>
     */
    public function codes(): array
    {
        return array_keys( $this->definitions );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get( string $code ): ?array
    {
        return $this->definitions[ sanitize_key( $code ) ] ?? null;
    }

    public function has( string $code ): bool
    {
        return null !== $this->get( $code );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function default_definitions(): array
    {
        return [
            'spam_guidance_rationale_generation' => [
                'code'                              => 'spam_guidance_rationale_generation',
                'feature_access'                    => 'active_subscription',
                'execution_requirement'             => 'provider_flexible',
                'required_form_source_capabilities' => [],
                'required_managed_capabilities'     => [],
                'lifecycle_restrictions'            => [],
                'metering_class'                    => 'standard',
            ],
        ];
    }
}
