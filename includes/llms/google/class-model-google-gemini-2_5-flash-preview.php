<?php

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Google_Gemini_2_5_Flash_Preview_Llm extends Sentient_Forms_Abstract_Llm_Google
{
    public function __construct()
    {
        parent::__construct(
            'gemini-2.5-flash-preview',
            __( 'Google Gemini Flash 2.5 (Preview)', 'sentient-forms' ),
            __( 'Next-generation speed and efficiency for scalable AI tasks (Preview).', 'sentient-forms' ),
            [
                Sentient_Forms_Llm_Capability::AUDIO_INPUT,
                Sentient_Forms_Llm_Capability::FUNCTION_CALLING,
                Sentient_Forms_Llm_Capability::IMAGE_INPUT,
                Sentient_Forms_Llm_Capability::PDF_INPUT,
                Sentient_Forms_Llm_Capability::WEB_SEARCH,
            ],
            Sentient_Forms_Llm_Status::PREVIEW,
            Sentient_Forms_Llm_Cost_Tier::LOW,
        );
    }
}