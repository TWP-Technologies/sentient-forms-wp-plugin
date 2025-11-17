<?php

class AdapterRegistryTest extends WP_UnitTestCase
{
    public function test_registered_adapters_are_async_capable(): void
    {
        $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $this->assertNotNull( $registry, 'Adapter registry should be initialised.' );

        foreach ( $registry->get_all_adapters() as $adapter )
        {
            $this->assertInstanceOf( Sentient_Forms_Adapter_Interface::class, $adapter );
            $this->assertInstanceOf(
                Sentient_Forms_Async_Capable_Adapter_Interface::class,
                $adapter,
                sprintf( '%s must implement async interface', $adapter::class )
            );
        }
    }
}
