<?php

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

abstract class Sentient_Forms_Abstract_Llm implements Sentient_Forms_Llm_Model_Interface
{
    protected string                       $id;
    protected string                       $name;
    protected string                       $description;
    protected Sentient_Forms_Llm_Provider  $provider;
    protected array                        $capabilities             = [];
    protected Sentient_Forms_Llm_Status    $status;
    protected Sentient_Forms_Llm_Cost_Tier $cost_tier;
    protected bool                         $is_default_for_free_tier = false;
    protected ?string                      $family                   = null;

    public function __construct(
        string                       $id,
        string                       $name,
        string                       $description,
        Sentient_Forms_Llm_Provider  $provider,
        array                        $capabilities,
        Sentient_Forms_Llm_Status    $status,
        Sentient_Forms_Llm_Cost_Tier $cost_tier,
        bool                         $is_default_for_free_tier = false,
        ?string                      $family = null,
    ) {
        $this->id                       = $id;
        $this->name                     = $name;
        $this->description              = $description;
        $this->provider                 = $provider;
        $this->capabilities             = $capabilities;
        $this->status                   = $status;
        $this->cost_tier                = $cost_tier;
        $this->is_default_for_free_tier = $is_default_for_free_tier;
        $this->family                   = $family;
    }

    public function get_id(): string
    {
        return $this->id;
    }

    public function get_name(): string
    {
        return $this->name;
    }

    public function get_description(): string
    {
        return $this->description;
    }

    public function get_provider(): Sentient_Forms_Llm_Provider
    {
        return $this->provider;
    }

    public function get_capabilities(): array
    {
        return $this->capabilities;
    }

    public function get_status(): Sentient_Forms_Llm_Status
    {
        return $this->status;
    }

    public function get_cost_tier(): Sentient_Forms_Llm_Cost_Tier
    {
        return $this->cost_tier;
    }

    public function is_default_for_free_tier(): bool
    {
        return $this->is_default_for_free_tier;
    }

    public function get_family(): ?string
    {
        return $this->family;
    }
}
