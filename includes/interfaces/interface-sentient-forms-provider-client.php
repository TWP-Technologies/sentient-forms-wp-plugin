<?php
/**
 * Provider client contract for local-first execution.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

interface Sentient_Forms_Provider_Client_Interface
{
    public function validate_key( string $api_key ): array | WP_Error;

    public function chat_completion( string $api_key, array $payload, array $options = [] ): array | WP_Error;
}
