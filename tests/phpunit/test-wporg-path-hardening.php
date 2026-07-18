<?php

class Tests_Wporg_Path_Hardening extends WP_UnitTestCase
{
    public function test_logger_disables_when_upload_dir_is_unavailable(): void
    {
        $filter = static function ( array $uploads ): array
        {
            $uploads['basedir'] = '';
            $uploads['error']   = 'Uploads unavailable for test.';

            return $uploads;
        };

        add_filter( 'upload_dir', $filter );
        wp_upload_dir( null, false, true );

        try
        {
            $logger = new Sentient_Forms_Logger( true );

            $this->assertFalse( $logger->is_enabled() );
            $this->assertSame( '', $logger->get_log_path() );
            $this->assertSame( '', $logger->get_log_dir() );
        }
        finally
        {
            remove_filter( 'upload_dir', $filter );
            wp_upload_dir( null, false, true );
        }
    }

    public function test_attachment_file_ref_resolves_only_uploads_urls(): void
    {
        $uploads = wp_get_upload_dir();
        $this->assertEmpty( $uploads['error'] );
        $this->assertNotEmpty( $uploads['basedir'] );
        $this->assertNotEmpty( $uploads['baseurl'] );

        $upload_path = trailingslashit( $uploads['basedir'] ) . 'sentient-forms-upload-path-test.txt';
        $content_path = trailingslashit( WP_CONTENT_DIR ) . 'sentient-forms-non-upload-path-test.txt';

        try
        {
            wp_mkdir_p( dirname( $upload_path ) );
            $this->assertNotFalse( file_put_contents( $upload_path, 'upload file' ) );
            $this->assertNotFalse( file_put_contents( $content_path, 'content file' ) );

            $builder = new Sentient_Forms_Attachment_File_Ref_Builder( Sentient_Forms_Plugin::instance() );
            $method  = new ReflectionMethod( $builder, 'resolve_local_path_from_url' );
            $method->setAccessible( true );

            $upload_url = trailingslashit( $uploads['baseurl'] ) . 'sentient-forms-upload-path-test.txt';
            $canonical_upload_path = realpath( $upload_path );
            $this->assertNotFalse( $canonical_upload_path );
            $this->assertSame(
                wp_normalize_path( $canonical_upload_path ),
                wp_normalize_path( (string) $method->invoke( $builder, $upload_url ) )
            );

            $upload_scheme = wp_parse_url( $upload_url, PHP_URL_SCHEME );
            $swapped_scheme_upload_url = set_url_scheme(
                $upload_url,
                'https' === $upload_scheme ? 'http' : 'https'
            );
            $this->assertSame(
                wp_normalize_path( $canonical_upload_path ),
                wp_normalize_path( (string) $method->invoke( $builder, $swapped_scheme_upload_url ) )
            );

            $upload_parts = wp_parse_url( $upload_url );
            $this->assertIsArray( $upload_parts );
            $migrated_host_upload_url = sprintf(
                '%s://%s%s',
                (string) ( $upload_parts['scheme'] ?? 'https' ),
                'old.example.test',
                (string) ( $upload_parts['path'] ?? '' )
            );
            $this->assertSame(
                wp_normalize_path( $canonical_upload_path ),
                wp_normalize_path( (string) $method->invoke( $builder, $migrated_host_upload_url ) )
            );

            $swapped_parts = wp_parse_url( $swapped_scheme_upload_url );
            $this->assertIsArray( $swapped_parts );
            if ( empty( $swapped_parts['port'] ) )
            {
                $swapped_scheme = strtolower( (string) ( $swapped_parts['scheme'] ?? 'https' ) );
                $default_port   = 'http' === $swapped_scheme ? 80 : 443;
                $explicit_default_port_upload_url = sprintf(
                    '%s://%s:%d%s',
                    $swapped_scheme,
                    (string) ( $swapped_parts['host'] ?? '' ),
                    $default_port,
                    (string) ( $swapped_parts['path'] ?? '' )
                );
                $this->assertSame(
                    wp_normalize_path( $canonical_upload_path ),
                    wp_normalize_path( (string) $method->invoke( $builder, $explicit_default_port_upload_url ) )
                );
            }

            $content_url = content_url( 'sentient-forms-non-upload-path-test.txt' );
            $this->assertNull( $method->invoke( $builder, $content_url ) );
        }
        finally
        {
            if ( file_exists( $upload_path ) )
            {
                wp_delete_file( $upload_path );
            }
            if ( file_exists( $content_path ) )
            {
                wp_delete_file( $content_path );
            }
        }
    }
}
