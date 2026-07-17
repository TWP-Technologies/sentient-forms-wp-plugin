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
     * @throws LogicException When a canonical Form Source adapter contract is invalid.
     */
    public function all(): array;

    /**
     * Query one bundled Action and versioned first-party Form Source pair.
     *
     * @param string $action_code Bundled Action code.
     * @param string $form_source Canonical Form Source identifier.
     *
     * @return array<string, mixed>|null
     * @throws LogicException When a canonical Form Source adapter contract is invalid.
     */
    public function get( string $action_code, string $form_source ): ?array;

    /**
     * Produce the versioned, public-safe compatibility contract.
     *
     * @return array<string, mixed>
     * @throws LogicException When a canonical adapter or semantic source-hash input is invalid.
     */
    public function public_projection(): array;
}
