<?php

class Tests_Text_Encoding_Check extends WP_UnitTestCase
{
    private string $temp_dir = '';

    protected function tearDown(): void
    {
        if ( '' !== $this->temp_dir && is_dir( $this->temp_dir ) )
        {
            $this->remove_directory( $this->temp_dir );
        }

        parent::tearDown();
    }

    public function test_encoding_check_passes_clean_text_files(): void
    {
        $dir = $this->make_temp_dir();
        file_put_contents( $dir . '/clean.php', "<?php\n// Clean file.\n" );
        file_put_contents( $dir . '/clean.js', "export const ok = true;\n" );

        $result = $this->run_php_script( 'scripts/check-text-encoding.php', [ $dir ] );

        $this->assertSame( 0, $result['code'], $result['output'] );
        $this->assertStringContainsString( 'text encoding check passed', $result['output'] );
    }

    public function test_encoding_check_fails_bom_prefixed_text_files(): void
    {
        $dir = $this->make_temp_dir();
        file_put_contents( $dir . '/bad.php', "\xEF\xBB\xBF<?php\n// Bad file.\n" );
        file_put_contents( $dir . '/nested-bad.json', "\xEF\xBB\xBF{\"bad\":true}\n" );

        $result = $this->run_php_script( 'scripts/check-text-encoding.php', [ $dir ] );

        $this->assertNotSame( 0, $result['code'], $result['output'] );
        $this->assertStringContainsString( 'UTF-8 BOM found: bad.php', $result['output'] );
        $this->assertStringContainsString( 'UTF-8 BOM found: nested-bad.json', $result['output'] );
    }

    public function test_wporg_scan_reports_bom_prefixed_package_files(): void
    {
        $dir = $this->make_temp_dir();
        file_put_contents( $dir . '/sentient-forms.php', "\xEF\xBB\xBF<?php\n// Plugin runtime file.\n" );
        file_put_contents( $dir . '/readme.txt', "=== Sentient Forms ===\nStable tag: 0.0.0\n" );

        $result = $this->run_php_script( 'scripts/scan-wporg-package.php', [ $dir ] );

        $this->assertNotSame( 0, $result['code'], $result['output'] );
        $this->assertStringContainsString(
            'UTF-8 BOM found in runtime text file: sentient-forms.php',
            $result['output']
        );
    }

    private function make_temp_dir(): string
    {
        $this->temp_dir = sys_get_temp_dir() . '/sentient-forms-encoding-' . uniqid( '', true );
        wp_mkdir_p( $this->temp_dir );

        return $this->temp_dir;
    }

    /**
     * @param array<int,string> $args
     *
     * @return array{code:int, output:string}
     */
    private function run_php_script( string $relative_script, array $args ): array
    {
        $root    = dirname( __DIR__, 2 );
        $command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/' . $relative_script );
        foreach ( $args as $arg )
        {
            $command .= ' ' . escapeshellarg( $arg );
        }

        $output = [];
        $code   = 0;
        exec( $command . ' 2>&1', $output, $code );

        return [
            'code'   => $code,
            'output' => implode( "\n", $output ),
        ];
    }

    private function remove_directory( string $dir ): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $item )
        {
            if ( $item->isDir() )
            {
                rmdir( $item->getPathname() );
                continue;
            }

            unlink( $item->getPathname() );
        }

        rmdir( $dir );
    }
}
