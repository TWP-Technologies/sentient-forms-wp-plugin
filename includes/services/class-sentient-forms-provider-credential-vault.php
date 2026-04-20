<?php
/**
 * Local credential vault for provider secrets.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Provider_Credential_Vault
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION = 1;

    public function __construct( private ?string $key_material = null )
    {
    }

    public function encrypt( string $secret ): string | WP_Error
    {
        $secret = trim( $secret );
        if ( '' === $secret )
        {
            return new WP_Error( 'sentient_forms_empty_secret', __( 'Provider secret cannot be empty.', 'sentient-forms' ) );
        }

        if ( ! function_exists( 'openssl_encrypt' ) )
        {
            return new WP_Error( 'sentient_forms_crypto_unavailable', __( 'OpenSSL encryption is not available on this site.', 'sentient-forms' ) );
        }

        try
        {
            $iv = random_bytes( 12 );
        }
        catch ( Throwable $throwable )
        {
            return new WP_Error( 'sentient_forms_crypto_random_failed', __( 'Could not generate secure random bytes for provider secret storage.', 'sentient-forms' ) );
        }

        $key = $this->encryption_key();
        if ( is_wp_error( $key ) )
        {
            return $key;
        }

        $tag        = '';
        $ciphertext = openssl_encrypt(
            $secret,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ( false === $ciphertext || '' === $tag )
        {
            return new WP_Error( 'sentient_forms_encrypt_failed', __( 'Provider secret could not be encrypted.', 'sentient-forms' ) );
        }

        $payload = wp_json_encode(
            [
                'version'    => self::VERSION,
                'cipher'     => self::CIPHER,
                'iv'         => base64_encode( $iv ),
                'tag'        => base64_encode( $tag ),
                'ciphertext' => base64_encode( $ciphertext ),
            ]
        );

        if ( ! is_string( $payload ) )
        {
            return new WP_Error( 'sentient_forms_encrypt_encode_failed', __( 'Encrypted provider secret could not be encoded.', 'sentient-forms' ) );
        }

        return $payload;
    }

    public function decrypt( string $payload ): string | WP_Error
    {
        if ( ! function_exists( 'openssl_decrypt' ) )
        {
            return new WP_Error( 'sentient_forms_crypto_unavailable', __( 'OpenSSL decryption is not available on this site.', 'sentient-forms' ) );
        }

        $key = $this->encryption_key();
        if ( is_wp_error( $key ) )
        {
            return $key;
        }

        $decoded = json_decode( $payload, true );
        if ( ! is_array( $decoded ) )
        {
            return new WP_Error( 'sentient_forms_invalid_secret_payload', __( 'Encrypted provider secret payload is invalid.', 'sentient-forms' ) );
        }

        if (
            self::VERSION !== (int) ( $decoded['version'] ?? 0 )
            || self::CIPHER !== (string) ( $decoded['cipher'] ?? '' )
        )
        {
            return new WP_Error( 'sentient_forms_unsupported_secret_payload', __( 'Encrypted provider secret payload is not supported.', 'sentient-forms' ) );
        }

        $iv         = $this->decode_base64_field( $decoded['iv'] ?? null, 'iv' );
        $tag        = $this->decode_base64_field( $decoded['tag'] ?? null, 'tag' );
        $ciphertext = $this->decode_base64_field( $decoded['ciphertext'] ?? null, 'ciphertext' );

        if ( is_wp_error( $iv ) )
        {
            return $iv;
        }

        if ( is_wp_error( $tag ) )
        {
            return $tag;
        }

        if ( is_wp_error( $ciphertext ) )
        {
            return $ciphertext;
        }

        $secret = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ( false === $secret )
        {
            return new WP_Error( 'sentient_forms_decrypt_failed', __( 'Provider secret could not be decrypted.', 'sentient-forms' ) );
        }

        return $secret;
    }

    private function decode_base64_field( mixed $value, string $field ): string | WP_Error
    {
        if ( ! is_string( $value ) || '' === $value )
        {
            return new WP_Error( 'sentient_forms_invalid_secret_payload', sprintf( 'Encrypted provider secret field is missing: %s.', $field ) );
        }

        $decoded = base64_decode( $value, true );
        if ( ! is_string( $decoded ) )
        {
            return new WP_Error( 'sentient_forms_invalid_secret_payload', sprintf( 'Encrypted provider secret field is invalid: %s.', $field ) );
        }

        return $decoded;
    }

    private function encryption_key(): string | WP_Error
    {
        $salt = is_string( $this->key_material ) ? $this->key_material : '';
        if ( '' === $salt && function_exists( 'wp_salt' ) )
        {
            $salt = wp_salt( 'auth' );
        }

        if ( '' === $salt && defined( 'AUTH_KEY' ) )
        {
            $salt = (string) AUTH_KEY;
        }

        if ( '' === trim( $salt ) )
        {
            return new WP_Error( 'sentient_forms_crypto_key_unavailable', __( 'Provider secret encryption key material is not available on this site.', 'sentient-forms' ) );
        }

        return hash( 'sha256', $salt, true );
    }
}
