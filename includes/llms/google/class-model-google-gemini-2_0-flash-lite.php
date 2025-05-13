<?php

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Google_Gemini_2_0_Flash_Lite_Llm extends Sentient_Forms_Abstract_Llm_Google
{
    public function __construct()
    {
        parent::__construct(
            'gemini-2.0-flash-lite',
            __( 'Google Gemini Flash Lite 2.0', 'sentient-forms' ),
            __( 'Lightweight and fast, suitable for simple, high-volume tasks.', 'sentient-forms' ),
            [
                Sentient_Forms_Llm_Capability::AUDIO_INPUT,
                Sentient_Forms_Llm_Capability::FUNCTION_CALLING,
                Sentient_Forms_Llm_Capability::IMAGE_INPUT,
                Sentient_Forms_Llm_Capability::PDF_INPUT,
                Sentient_Forms_Llm_Capability::WEB_SEARCH,
            ],
            Sentient_Forms_Llm_Status::ACTIVE,
            Sentient_Forms_Llm_Cost_Tier::LOWEST,
        );
    }
}