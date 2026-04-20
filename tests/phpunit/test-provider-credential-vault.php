<?php

class Tests_Provider_Credential_Vault extends WP_UnitTestCase
{
    public function test_encrypt_decrypt_round_trip_without_storing_plaintext_secret(): void
    {
        $vault  = new Sentient_Forms_Provider_Credential_Vault( 'local-test-key-material' );
        $secret = 'sk-or-local-vault-secret';

        $payload = $vault->encrypt( $secret );

        $this->assertIsString( $payload );
        $this->assertStringNotContainsString( $secret, $payload );

        $decoded = json_decode( $payload, true );
        $this->assertIsArray( $decoded );
        $this->assertSame( 1, $decoded['version'] );
        $this->assertSame( 'aes-256-gcm', $decoded['cipher'] );
        $this->assertNotEmpty( $decoded['iv'] );
        $this->assertNotEmpty( $decoded['tag'] );
        $this->assertNotEmpty( $decoded['ciphertext'] );

        $this->assertSame( $secret, $vault->decrypt( $payload ) );
    }

    public function test_decrypt_rejects_payload_encrypted_with_different_key_material(): void
    {
        $writer  = new Sentient_Forms_Provider_Credential_Vault( 'local-test-key-material-alpha' );
        $reader  = new Sentient_Forms_Provider_Credential_Vault( 'local-test-key-material-beta' );
        $payload = $writer->encrypt( 'sk-or-wrong-key-secret' );

        $this->assertIsString( $payload );

        $result = $reader->decrypt( $payload );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_forms_decrypt_failed', $result->get_error_code() );
    }

    public function test_decrypt_rejects_tampered_payload(): void
    {
        $vault   = new Sentient_Forms_Provider_Credential_Vault( 'local-test-key-material' );
        $payload = $vault->encrypt( 'sk-or-tampered-secret' );

        $this->assertIsString( $payload );

        $decoded = json_decode( $payload, true );
        $this->assertIsArray( $decoded );
        $decoded['tag'] = base64_encode( str_repeat( 'x', 16 ) );

        $tampered_payload = wp_json_encode( $decoded );
        $this->assertIsString( $tampered_payload );

        $result = $vault->decrypt( $tampered_payload );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_forms_decrypt_failed', $result->get_error_code() );
    }
}
