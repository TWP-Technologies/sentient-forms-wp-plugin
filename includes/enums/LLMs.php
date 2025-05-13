<?php

/**
 * LLM Model Metadata Constants
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Enum for LLM Providers.
 */
enum Sentient_Forms_Llm_Provider: string
{
    case GOOGLE    = 'google';
    case OPENAI    = 'openai';
    case ANTHROPIC = 'anthropic';

    public function get_name(): string
    {
        return match ( $this )
        {
            self::GOOGLE    => __( 'Google', 'sentient-forms' ),
            self::OPENAI    => __( 'OpenAI', 'sentient-forms' ),
            self::ANTHROPIC => __( 'Anthropic', 'sentient-forms' ),
        };
    }
}

/**
 * Enum for LLM Capabilities.
 */
enum Sentient_Forms_Llm_Capability: string
{
    case AUDIO_INPUT      = 'audio_input';
    case FUNCTION_CALLING = 'function_calling';
    case IMAGE_INPUT      = 'image_input';
    case PDF_INPUT        = 'pdf_input';
    case REASONING        = 'reasoning';
    case WEB_SEARCH       = 'web_search';

    public function get_name(): string
    {
        return match ( $this )
        {
            self::AUDIO_INPUT      => __( 'Audio Input', 'sentient-forms' ),
            self::FUNCTION_CALLING => __( 'Function Calling', 'sentient-forms' ),
            self::IMAGE_INPUT      => __( 'Image Input', 'sentient-forms' ),
            self::PDF_INPUT        => __( 'PDF Input', 'sentient-forms' ),
            self::REASONING        => __( 'Advanced Reasoning', 'sentient-forms' ),
            self::WEB_SEARCH       => __( 'Web Search', 'sentient-forms' ),
        };
    }
}

/**
 * Enum for LLM Status.
 */
enum Sentient_Forms_Llm_Status: string
{
    case ACTIVE       = 'active';
    case BETA         = 'beta';
    case PREVIEW      = 'preview';
    case DEPRECATED   = 'deprecated';
    case EXPERIMENTAL = 'experimental';

    public function get_name(): string
    {
        return match ( $this )
        {
            self::ACTIVE       => __( 'Active', 'sentient-forms' ),
            self::BETA         => __( 'Beta', 'sentient-forms' ),
            self::PREVIEW      => __( 'Preview', 'sentient-forms' ),
            self::DEPRECATED   => __( 'Deprecated', 'sentient-forms' ),
            self::EXPERIMENTAL => __( 'Experimental', 'sentient-forms' ),
        };
    }
}

/**
 * Enum for LLM Cost Tiers.
 */
enum Sentient_Forms_Llm_Cost_Tier: string
{
    case LOWEST  = 'lowest';
    case LOW     = 'low';
    case MEDIUM  = 'medium';
    case HIGH    = 'high';
    case PREMIUM = 'premium';

    public function get_name(): string
    {
        return match ( $this )
        {
            self::LOWEST  => __( 'Lowest Cost', 'sentient-forms' ),
            self::LOW     => __( 'Low Cost', 'sentient-forms' ),
            self::MEDIUM  => __( 'Medium Cost', 'sentient-forms' ),
            self::HIGH    => __( 'High Cost', 'sentient-forms' ),
            self::PREMIUM => __( 'Premium Cost', 'sentient-forms' ),
        };
    }
}
