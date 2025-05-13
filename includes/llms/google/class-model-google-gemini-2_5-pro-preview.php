<?php

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Google_Gemini_2_5_Pro_Preview_Llm extends Sentient_Forms_Abstract_Llm_Google
{
    public function __construct()
    {
        parent::__construct(
            'gemini-2.5-pro-preview',
            __( 'Google Gemini Pro 2.5 (Preview)', 'sentient-forms' ),
            __( 'Most capable model for advanced reasoning and complex tasks (Preview).', 'sentient-forms' ),
            [
                Sentient_Forms_Llm_Capability::AUDIO_INPUT,
                Sentient_Forms_Llm_Capability::FUNCTION_CALLING,
                Sentient_Forms_Llm_Capability::IMAGE_INPUT,
                Sentient_Forms_Llm_Capability::PDF_INPUT,
                Sentient_Forms_Llm_Capability::REASONING,
                Sentient_Forms_Llm_Capability::WEB_SEARCH,
            ],
            Sentient_Forms_Llm_Status::PREVIEW,
            Sentient_Forms_Llm_Cost_Tier::MEDIUM,
        );
    }
}