<?php
/**
 * Build attachment file references for CPS execution payloads.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sentient_Forms_Attachment_File_Ref_Builder {
	public const MAX_FILE_BYTES = 10485760; // 10MB
	private const DEFAULT_MAX_FILES = 5;
	private const MAX_FILES_UPPER_BOUND = 20;

	private Sentient_Forms_Plugin $plugin;
	private Sentient_Forms_Pull_Token_Service $pull_token_service;

	public function __construct(
		Sentient_Forms_Plugin $plugin,
		?Sentient_Forms_Pull_Token_Service $pull_token_service = null
	) {
		$this->plugin             = $plugin;
		$this->pull_token_service = $pull_token_service ?? new Sentient_Forms_Pull_Token_Service( $plugin );
	}

	/**
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $entry
	 * @param array<string,mixed> $context
	 *
	 * @return array{file_refs:array<int,array<string,mixed>>,attachment_manifest:array<string,mixed>}
	 */
	public function build_for_execution( array $form, array $entry, array $context = array() ): array {
		$resolved = $this->resolve_attachment_mapping_context( $context );
		$mapping  = $resolved['attachment_mapping'];
		$mode     = $mapping['mode'];

		$file_refs     = array();
		$drop_reasons  = array();
		$max_files     = (int) $mapping['max_files'];
		$license_data  = $this->plugin->get_license_data();
		$local_site_id = sanitize_text_field( (string) $this->plugin->get_local_site_identifier() );

		if ( 'none' !== $mode ) {
			if ( $this->mode_includes_gf_upload( $mode ) ) {
				foreach ( $mapping['gf_upload_field_ids'] as $field_id ) {
					if ( count( $file_refs ) >= $max_files ) {
						$this->record_drop_reason( $drop_reasons, 'max_files_reached' );
						break;
					}

					if ( ! $this->is_upload_field( $form, $field_id ) ) {
						$this->record_drop_reason( $drop_reasons, 'invalid_upload_field' );
						continue;
					}

					$urls = $this->extract_entry_file_urls( $entry[ $field_id ] ?? null );
					if ( empty( $urls ) ) {
						continue;
					}

					foreach ( $urls as $url ) {
						if ( count( $file_refs ) >= $max_files ) {
							$this->record_drop_reason( $drop_reasons, 'max_files_reached' );
							break;
						}

						$path = $this->resolve_local_path_from_url( $url );
						if ( null === $path ) {
							$this->record_drop_reason( $drop_reasons, 'missing_file' );
							continue;
						}

						$file_ref = $this->build_single_file_ref(
							$path,
							'gf_upload',
							array(
								'field_id' => $field_id,
							),
							$license_data,
							$local_site_id,
							$context
						);
						if ( null === $file_ref ) {
							$this->record_drop_reason( $drop_reasons, 'invalid_file' );
							continue;
						}

						$file_refs[] = $file_ref;
					}
				}
			}

			if ( $this->mode_includes_media( $mode ) && count( $file_refs ) < $max_files ) {
				foreach ( $mapping['media_ids'] as $media_id ) {
					if ( count( $file_refs ) >= $max_files ) {
						$this->record_drop_reason( $drop_reasons, 'max_files_reached' );
						break;
					}

					$path = get_attached_file( $media_id );
					if ( ! is_string( $path ) || '' === $path ) {
						$this->record_drop_reason( $drop_reasons, 'missing_media' );
						continue;
					}

					$real_path = realpath( $path );
					if ( false === $real_path || ! is_file( $real_path ) ) {
						$this->record_drop_reason( $drop_reasons, 'missing_media' );
						continue;
					}

					$file_ref = $this->build_single_file_ref(
						$real_path,
						'media',
						array(
							'media_id' => $media_id,
						),
						$license_data,
						$local_site_id,
						$context
					);
					if ( null === $file_ref ) {
						$this->record_drop_reason( $drop_reasons, 'invalid_media_file' );
						continue;
					}

					$file_refs[] = $file_ref;
				}
			}
		}

		return array(
			'file_refs'           => $file_refs,
			'attachment_manifest' => array(
				'mapping_source'          => $resolved['source'],
				'attachment_mode'         => $mode,
				'requested_gf_fields'     => $mapping['gf_upload_field_ids'],
				'requested_media_ids'     => $mapping['media_ids'],
				'max_files'               => $max_files,
				'resolved_file_ref_count' => count( $file_refs ),
				'drop_reasons'            => $drop_reasons,
			),
		);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array{attachment_mapping:array{mode:string,gf_upload_field_ids:array<int,string>,media_ids:array<int,int>,max_files:int},source:string}
	 */
	private function resolve_attachment_mapping_context( array $context ): array {
		$candidates = array();

		if ( isset( $context['attachment_mapping'] ) && is_array( $context['attachment_mapping'] ) ) {
			$candidates[] = $context['attachment_mapping'];
		}

		if ( isset( $context['settings'] ) && is_array( $context['settings'] ) ) {
			if ( isset( $context['settings']['attachment_mapping'] ) && is_array( $context['settings']['attachment_mapping'] ) ) {
				$candidates[] = $context['settings']['attachment_mapping'];
			}

			if (
				isset( $context['settings']['settings'] )
				&& is_array( $context['settings']['settings'] )
				&& isset( $context['settings']['settings']['attachment_mapping'] )
				&& is_array( $context['settings']['settings']['attachment_mapping'] )
			) {
				$candidates[] = $context['settings']['settings']['attachment_mapping'];
			}
		}

		foreach ( $candidates as $candidate ) {
			$sanitized = self::sanitize_attachment_mapping( $candidate );
			if ( ! empty( $sanitized ) ) {
				return array(
					'attachment_mapping' => $sanitized,
					'source'             => 'explicit_mapping',
				);
			}
		}

		return array(
			'attachment_mapping' => self::sanitize_attachment_mapping( array() ),
			'source'             => 'default_none',
		);
	}

	/**
	 * @param array<string,mixed> $mapping
	 * @return array{mode:string,gf_upload_field_ids:array<int,string>,media_ids:array<int,int>,max_files:int}
	 */
	public static function sanitize_attachment_mapping( array $mapping ): array {
		$mode = isset( $mapping['mode'] ) ? sanitize_key( (string) $mapping['mode'] ) : 'none';
		$allowed_modes = array( 'none', 'gf_upload', 'media_library', 'mixed' );
		if ( ! in_array( $mode, $allowed_modes, true ) ) {
			$mode = 'none';
		}

		$gf_upload_field_ids = array();
		if ( isset( $mapping['gf_upload_field_ids'] ) && is_array( $mapping['gf_upload_field_ids'] ) ) {
			foreach ( $mapping['gf_upload_field_ids'] as $field_id ) {
				if ( ! is_scalar( $field_id ) ) {
					continue;
				}

				$normalized = sanitize_text_field( (string) $field_id );
				if ( '' !== $normalized ) {
					$gf_upload_field_ids[] = $normalized;
				}
			}
		}

		$media_ids = array();
		if ( isset( $mapping['media_ids'] ) && is_array( $mapping['media_ids'] ) ) {
			foreach ( $mapping['media_ids'] as $media_id ) {
				$normalized = (int) $media_id;
				if ( $normalized > 0 ) {
					$media_ids[] = $normalized;
				}
			}
		}

		$max_files = isset( $mapping['max_files'] ) ? (int) $mapping['max_files'] : self::DEFAULT_MAX_FILES;
		$max_files = max( 1, min( self::MAX_FILES_UPPER_BOUND, $max_files ) );

		return array(
			'mode'                => $mode,
			'gf_upload_field_ids' => array_values( array_unique( $gf_upload_field_ids ) ),
			'media_ids'           => array_values( array_unique( $media_ids ) ),
			'max_files'           => $max_files,
		);
	}

	/**
	 * @param array<string,mixed> $form
	 */
	private function is_upload_field( array $form, string $field_id ): bool {
		$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();
		foreach ( $fields as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}

			$current_id = isset( $field->id ) ? (string) $field->id : '';
			if ( $current_id !== $field_id ) {
				continue;
			}

			$type = isset( $field->type ) ? sanitize_key( (string) $field->type ) : '';
			return in_array( $type, array( 'fileupload', 'post_image' ), true );
		}

		return false;
	}

	/**
	 * @return array<int,string>
	 */
	private function extract_entry_file_urls( $raw_value ): array {
		$urls = array();

		if ( is_array( $raw_value ) ) {
			foreach ( $raw_value as $item ) {
				$urls = array_merge( $urls, $this->extract_entry_file_urls( $item ) );
			}
			return array_values( array_unique( $urls ) );
		}

		if ( ! is_scalar( $raw_value ) ) {
			return array();
		}

		$value = trim( (string) $raw_value );
		if ( '' === $value ) {
			return array();
		}

		$maybe_array = json_decode( $value, true );
		if ( is_array( $maybe_array ) ) {
			foreach ( $maybe_array as $item ) {
				$urls = array_merge( $urls, $this->extract_entry_file_urls( $item ) );
			}
			return array_values( array_unique( $urls ) );
		}

		$maybe_unserialized = maybe_unserialize( $value );
		if ( is_array( $maybe_unserialized ) ) {
			foreach ( $maybe_unserialized as $item ) {
				$urls = array_merge( $urls, $this->extract_entry_file_urls( $item ) );
			}
			return array_values( array_unique( $urls ) );
		}

		if ( false !== strpos( $value, '|' ) || false !== strpos( $value, "\n" ) ) {
			$parts = preg_split( '/[|\r\n]+/', $value );
			if ( is_array( $parts ) ) {
				foreach ( $parts as $part ) {
					$part = trim( (string) $part );
					if ( '' !== $part && filter_var( $part, FILTER_VALIDATE_URL ) ) {
						$urls[] = $part;
					}
				}
				return array_values( array_unique( $urls ) );
			}
		}

		if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return array( $value );
		}

		return array();
	}

	private function resolve_local_path_from_url( string $url ): ?string {
		$uploads = wp_get_upload_dir();
		$baseurl = isset( $uploads['baseurl'] ) ? trailingslashit( (string) $uploads['baseurl'] ) : '';
		$basedir = isset( $uploads['basedir'] ) ? trailingslashit( (string) $uploads['basedir'] ) : '';
		if ( ! empty( $uploads['error'] ) || '' === $baseurl || '' === $basedir ) {
			return null;
		}

		$clean_url = preg_replace( '/[?#].*$/', '', $url );
		if ( ! is_string( $clean_url ) || '' === $clean_url ) {
			return null;
		}

		$base_parts = wp_parse_url( $baseurl );
		$url_parts  = wp_parse_url( $clean_url );
		if ( ! is_array( $base_parts ) || ! is_array( $url_parts ) ) {
			return null;
		}

		$base_host = strtolower( (string) ( $base_parts['host'] ?? '' ) );
		$url_host  = strtolower( (string) ( $url_parts['host'] ?? '' ) );
		$base_port = $this->normalize_url_port( $base_parts );
		$url_port  = $this->normalize_url_port( $url_parts );
		if ( '' !== $base_host && $base_host === $url_host && $base_port !== $url_port ) {
			return null;
		}

		$base_path = trailingslashit( rawurldecode( (string) ( $base_parts['path'] ?? '/' ) ) );
		$url_path  = rawurldecode( (string) ( $url_parts['path'] ?? '' ) );
		if ( '' === $url_path || ! str_starts_with( $url_path, $base_path ) ) {
			return null;
		}

		$relative  = ltrim( (string) substr( $url_path, strlen( $base_path ) ), '/' );
		$candidate = $basedir . str_replace( '/', DIRECTORY_SEPARATOR, $relative );

		$real_candidate = realpath( $candidate );
		$real_base      = realpath( untrailingslashit( $basedir ) );
		if ( false === $real_candidate || false === $real_base || ! is_file( $real_candidate ) ) {
			return null;
		}

		$real_candidate = wp_normalize_path( $real_candidate );
		$real_base      = trailingslashit( wp_normalize_path( $real_base ) );
		if ( ! str_starts_with( $real_candidate, $real_base ) ) {
			return null;
		}

		return $real_candidate;
	}

	/**
	 * Treat explicit default ports the same as omitted ports so equivalent
	 * uploads URLs survive http/https storage differences.
	 *
	 * @param array<string,mixed> $url_parts Parsed URL parts.
	 */
	private function normalize_url_port( array $url_parts ): ?int {
		if ( ! isset( $url_parts['port'] ) ) {
			return null;
		}

		$port   = (int) $url_parts['port'];
		$scheme = strtolower( (string) ( $url_parts['scheme'] ?? '' ) );
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			return null;
		}

		return $port;
	}

	/**
	 * @param array<string,mixed> $metadata
	 * @param array<string,mixed> $license_data
	 * @param array<string,mixed> $context
	 *
	 * @return array<string,mixed>|null
	 */
	private function build_single_file_ref(
		string $path,
		string $source_type,
		array $metadata,
		array $license_data,
		string $local_site_identifier,
		array $context
	): ?array {
		$size = @filesize( $path );
		if ( ! is_int( $size ) || $size <= 0 || $size > self::MAX_FILE_BYTES ) {
			return null;
		}

		$filename = wp_basename( $path );
		$mime     = $this->detect_content_type( $path, $filename, $metadata );
		if ( '' === $mime ) {
			return null;
		}

		$file_ref_id = wp_generate_uuid4();
		$hash        = @hash_file( 'sha256', $path );
		$hash_sha256 = is_string( $hash ) && '' !== $hash ? strtolower( $hash ) : null;

		$ticket = array(
			'file_ref_id'          => $file_ref_id,
			'source_type'          => $source_type,
			'locator_type'         => 'absolute_path',
			'locator_value'        => $path,
			'filename'             => $filename,
			'content_type'         => $mime,
			'size_bytes'           => $size,
			'hash_sha256'          => $hash_sha256,
			'license_id'           => sanitize_text_field( (string) ( $license_data['license_id'] ?? '' ) ),
			'site_id'              => sanitize_text_field( (string) ( $license_data['site_id'] ?? '' ) ),
			'local_site_identifier' => $local_site_identifier,
			'execution_request_id' => sanitize_text_field( (string) ( $context['execution_request_id'] ?? '' ) ),
		);
		$token_payload = $this->pull_token_service->issue_token( $ticket );
		$token         = (string) $token_payload['token'];

		$file_ref = array(
			'file_ref_id'  => $file_ref_id,
			'source_type'  => $source_type,
			'filename'     => $filename,
			'content_type' => $mime,
			'size_bytes'   => $size,
			'pull_url'     => rest_url( '/sentient-forms/v1/files/pull/' . $token ),
			'expires_at'   => $token_payload['expires_at'],
		);

		if ( is_string( $hash_sha256 ) && preg_match( '/^[a-f0-9]{64}$/', $hash_sha256 ) ) {
			$file_ref['hash_sha256'] = $hash_sha256;
		}

		if ( isset( $metadata['field_id'] ) ) {
			$file_ref['field_id'] = (string) $metadata['field_id'];
		}

		if ( isset( $metadata['media_id'] ) ) {
			$file_ref['media_id'] = (int) $metadata['media_id'];
		}

		return $file_ref;
	}

	/**
	 * @param array<string,mixed> $metadata
	 */
	private function detect_content_type( string $path, string $filename, array $metadata ): string {
		if ( isset( $metadata['media_id'] ) ) {
			$media_mime = get_post_mime_type( (int) $metadata['media_id'] );
			if ( is_string( $media_mime ) && '' !== $media_mime ) {
				return sanitize_text_field( $media_mime );
			}
		}

		$detected = wp_check_filetype_and_ext( $path, $filename );
		$type     = is_array( $detected ) ? (string) ( $detected['type'] ?? '' ) : '';
		if ( '' !== $type ) {
			return sanitize_text_field( $type );
		}

		if ( function_exists( 'mime_content_type' ) ) {
			$fallback = @mime_content_type( $path );
			if ( is_string( $fallback ) && '' !== $fallback ) {
				return sanitize_text_field( $fallback );
			}
		}

		return '';
	}

	/**
	 * @param array<string,int> $drop_reasons
	 */
	private function record_drop_reason( array &$drop_reasons, string $reason ): void {
		if ( ! isset( $drop_reasons[ $reason ] ) ) {
			$drop_reasons[ $reason ] = 0;
		}
		$drop_reasons[ $reason ]++;
	}

	private function mode_includes_gf_upload( string $mode ): bool {
		return in_array( $mode, array( 'gf_upload', 'mixed' ), true );
	}

	private function mode_includes_media( string $mode ): bool {
		return in_array( $mode, array( 'media_library', 'mixed' ), true );
	}
}
