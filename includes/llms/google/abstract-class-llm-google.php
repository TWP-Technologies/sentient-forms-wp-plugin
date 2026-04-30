<?php

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

abstract class Sentient_Forms_Abstract_Llm_Google extends Sentient_Forms_Abstract_Llm
{
    public function __construct(
        string                       $id,
        string                       $name,
        string                       $description,
        array                        $capabilities,
        Sentient_Forms_Llm_Status    $status,
        Sentient_Forms_Llm_Cost_Tier $cost_tier,
        bool                         $is_default_for_free_tier = false,
    ) {
        parent::__construct(
            $id,
            $name,
            $description,
            Sentient_Forms_Llm_Provider::GOOGLE,
            $capabilities,
            $status,
            $cost_tier,
            $is_default_for_free_tier,
            'Gemini',
        );
    }
}
