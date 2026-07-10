<?php
/**
 * Action-by-Form-Source compatibility manifest contract.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

interface Sentient_Forms_Action_Source_Compatibility_Manifest_Interface
{
    /**
     * Enumerate every bundled Action and versioned first-party Form Source pair.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array;

    /**
     * Query one bundled Action and versioned first-party Form Source pair.
     *
     * @return array<string, mixed>|null
     */
    public function get( string $action_code, string $form_source ): ?array;

    /**
     * Produce the versioned, public-safe compatibility contract.
     *
     * @return array<string, mixed>
     */
    public function public_projection(): array;
}
