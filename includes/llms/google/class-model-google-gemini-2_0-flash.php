<?php

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Google_Gemini_2_0_Flash_Llm extends Sentient_Forms_Abstract_Llm_Google
{
    public function __construct()
    {
        parent::__construct(
            'gemini-2.0-flash',
            __( 'Google Gemini Flash 2.0', 'sentient-forms' ),
            __( 'Fast and efficient, good for summarization and chat applications.', 'sentient-forms' ),
            [
                Sentient_Forms_Llm_Capability::AUDIO_INPUT,
                Sentient_Forms_Llm_Capability::FUNCTION_CALLING,
                Sentient_Forms_Llm_Capability::IMAGE_INPUT,
                Sentient_Forms_Llm_Capability::PDF_INPUT,
                Sentient_Forms_Llm_Capability::WEB_SEARCH,
            ],
            Sentient_Forms_Llm_Status::ACTIVE,
            Sentient_Forms_Llm_Cost_Tier::LOW,
            true,
        );
    }
}