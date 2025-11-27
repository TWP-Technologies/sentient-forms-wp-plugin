<?php
/**
 * Sentient Forms logging helper.
 *
 * Rotates JSONL logs under wp-content/uploads/sentient-forms/logs,
 * masks PII, and supports correlation IDs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sentient_Forms_Logger {
	private const DEFAULT_MAX_BYTES = 5_000_000; // 5 MB
	private const DEFAULT_MAX_FILES = 5;
	private const DEFAULT_BASENAME = 'sf.log';
	private const LOG_DIR_RELATIVE = 'sentient-forms/logs';

	private bool $enabled;
	private int $max_bytes;
	private int $max_files;
	private string $log_path;

	public function __construct( bool $enabled = false, ?int $max_bytes = null, ?int $max_files = null ) {
		$this->enabled   = $enabled;
		$this->max_bytes = $max_bytes ?? self::DEFAULT_MAX_BYTES;
		$this->max_files = $max_files ?? self::DEFAULT_MAX_FILES;
		$this->log_path  = $this->build_log_path();
	}

	public function is_enabled(): bool {
		return $this->enabled;
	}

	public function debug( string $message, array $context = [] ): void {
		$this->write( 'debug', $message, $context );
	}

	public function info( string $message, array $context = [] ): void {
		$this->write( 'info', $message, $context );
	}

	public function error( string $message, array $context = [] ): void {
		$this->write( 'error', $message, $context );
	}

	public function correlation_id( ?string $candidate = null ): string {
		if ( $candidate && wp_is_uuid( $candidate ) ) {
			return $candidate;
		}
		return wp_generate_uuid4();
	}

	public function mask_email( ?string $email ): ?string {
		if ( empty( $email ) || ! is_string( $email ) ) {
			return $email;
		}
		$parts = explode( '@', $email );
		if ( count( $parts ) !== 2 ) {
			return $email;
		}
		$local = $parts[0];
		if ( strlen( $local ) > 2 ) {
			$local = substr( $local, 0, 2 ) . '…';
		} else {
			$local = $local . '…';
		}
		return $local . '@' . $parts[1];
	}

	public function mask_ip( ?string $ip ): ?string {
		if ( empty( $ip ) || ! is_string( $ip ) ) {
			return $ip;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $ip );
			return sprintf( '%s.%s.%s.0/24', $parts[0] ?? '0', $parts[1] ?? '0', $parts[2] ?? '0' );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return substr( $ip, 0, 9 ) . '…/64';
		}
		return $ip;
	}

	public function mask_user_agent( ?string $ua ): ?string {
		if ( empty( $ua ) || ! is_string( $ua ) ) {
			return $ua;
		}
		return substr( md5( $ua ), 0, 8 );
	}

	public function mask_url_host( ?string $url ): ?string {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return $url;
		}
		$parsed = wp_parse_url( $url );
		return $parsed['host'] ?? $url;
	}

	public function get_log_path(): string {
		return $this->log_path;
	}

	public function get_log_dir(): string {
		return dirname( $this->log_path );
	}

	private function write( string $level, string $message, array $context ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$line = wp_json_encode(
			[
				'ts'        => gmdate( 'c' ),
				'level'     => $level,
				'message'   => $message,
				'context'   => $context,
			]
		);

		if ( false === $line ) {
			return;
		}

		$dir = dirname( $this->log_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( $this->needs_rotation() ) {
			$this->rotate();
		}

		file_put_contents( $this->log_path, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
	}

	private function build_log_path(): string {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] ?? WP_CONTENT_DIR . '/uploads' );
		return $base . trailingslashit( self::LOG_DIR_RELATIVE ) . self::DEFAULT_BASENAME;
	}

	private function needs_rotation(): bool {
		if ( ! file_exists( $this->log_path ) ) {
			return false;
		}
		return filesize( $this->log_path ) >= $this->max_bytes;
	}

	private function rotate(): void {
		// Delete oldest
		$max = max( 1, $this->max_files );
		$oldest = $this->log_path . '.' . $max . '.gz';
		if ( file_exists( $oldest ) ) {
			@unlink( $oldest );
		}

		// Shift existing
		for ( $i = $max - 1; $i >= 1; $i-- ) {
			$src = $this->log_path . '.' . $i . '.gz';
			$dst = $this->log_path . '.' . ( $i + 1 ) . '.gz';
			if ( file_exists( $src ) ) {
				@rename( $src, $dst );
			}
		}

		// Compress current
		if ( file_exists( $this->log_path ) ) {
			$content = file_get_contents( $this->log_path );
			if ( false !== $content ) {
				file_put_contents( $this->log_path . '.1.gz', gzencode( $content, 6 ) );
			}
			@unlink( $this->log_path );
		}
	}
}
