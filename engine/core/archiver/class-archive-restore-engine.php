<?php
/**
 * Chunked encrypted archive extraction engine (.anfm format).
 *
 * ALL data in .anfm archives is always AES-256-GCM encrypted.
 * If the archive is password-protected (flag bit 0 = 1), the key is
 * derived from the user's password + the salt in the header.
 * If not password-protected (flag bit 0 = 0), the encryption key is
 * embedded directly in the header's key_material field.
 * New ANFM packages also carry a fixed EOF footer so callers can reject
 * truncated backups before restore planning begins.
 *
 * Usage:
 *   $engine = ArchiveRestoreEngine::get_instance( $archive, $dest );
 *   $info   = $engine->read_header();            // check password_protected flag
 *   $engine->load_archive_manifest( $password );  // decrypt & cache the manifest
 *   while ( $engine->run_step( $password ) ) {}   // poll from AJAX
 *   $engine->cleanup();
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Archive restore requires byte-accurate streaming, seeks, atomic renames, and manifest sidecar files.

use Exception;

/**
 * Time-budgeted engine that decrypts and extracts an AES-256-GCM encrypted
 * .anfm archive across multiple requests, verifying the header/footer and
 * deriving the decryption key from an optional password.
 */
class ArchiveRestoreEngine {

    const MAGIC             = 'ANFM';
    const VERSION           = 2;
    const HEADER_SIZE       = 50;
    const CIPHER            = 'aes-256-gcm';
    const IV_LENGTH         = 12;
    const TAG_LENGTH        = 16;
    const SALT_LENGTH       = 32;
    const PBKDF2_ITERATIONS = 100000;
    const FOOTER_MAGIC      = 'ANFMEND!';
    const FOOTER_VERSION    = 1;
    const FOOTER_SIZE       = 64;

    private string $archive;
    private string $dest;

    private string $state_dir;
    private string $manifest_cache_file;
    private string $manifest_meta_file;
    private string $manifest_entries_file;
    private string $manifest_path_index_dir;
    private string $manifest_load_state_file;
    private string $state_file;
    private string $lock_file;

    private int $time_budget;

    private static $instances = [];

    /**
     * Get or create an engine instance.
     */
    public static function get_instance( string $archive, string $dest ): self {
        if ( ! file_exists( $archive ) ) {
            throw new Exception( 'Archive file does not exist' );
        }
        if ( ! is_dir( $dest ) ) {
            throw new Exception( 'Destination directory does not exist' );
        }
        $key = md5( $archive . '|' . $dest );
        if ( empty( self::$instances[ $key ] ) ) {
            self::$instances[ $key ] = new self( $archive, $dest );
        }
        return self::$instances[ $key ];
    }

    private function __construct( string $archive, string $dest ) {
        $this->archive = $archive;
        $this->dest    = rtrim( $dest, '/' );

        $state_dir = anibas_fm_get_archive_restore_state_dir( $archive, $this->dest, 'anfm' );
        if ( ! $state_dir ) {
            throw new Exception( 'Failed to create archive restore state directory' );
        }
        $this->state_dir           = $state_dir;
        $this->manifest_cache_file = $this->state_dir . '/manifest.json';
        $this->manifest_meta_file  = $this->state_dir . '/manifest.meta.json';
        $this->manifest_entries_file = $this->state_dir . '/manifest.entries.jsonl';
        $this->manifest_path_index_dir = $this->state_dir . '/manifest.path-index';
        $this->manifest_load_state_file = $this->state_dir . '/manifest.load-state.json';
        $this->state_file          = $this->state_dir . '/state.json';
        $this->lock_file           = $this->state_dir . '/lock';

        $this->time_budget = function_exists( 'anibas_fm_safe_time_budget' )
            ? anibas_fm_safe_time_budget( 20, 0.6 )
            : 20;
    }

    /* ------------------------------------- */
    /* LOCKING                               */
    /* ------------------------------------- */

    private function acquire_lock() {
        $lock = fopen( $this->lock_file, 'c' );
        if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
            if ( $lock ) {
                fclose( $lock );
            }
            throw new Exception( 'Another restore process is running' );
        }
        return $lock;
    }

    private function release_lock( $lock ) {
        if ( is_resource( $lock ) ) {
            flock( $lock, LOCK_UN );
            fclose( $lock );
        }
    }

    /* ------------------------------------- */
    /* ENCRYPTION HELPERS                    */
    /* ------------------------------------- */

    private static function derive_key( string $password, string $salt ): string {
        return hash_pbkdf2( 'sha256', $password, $salt, self::PBKDF2_ITERATIONS, 32, true );
    }

    /**
     * Read one encrypted chunk from a file handle.
     * Returns decrypted data and advances the file pointer.
     */
    private static function read_encrypted_chunk( $fh, string $key ): string {
        $iv  = fread( $fh, self::IV_LENGTH );
        $tag = fread( $fh, self::TAG_LENGTH );
        $len_raw = fread( $fh, 4 );

        if ( strlen( $iv ) !== self::IV_LENGTH || strlen( $tag ) !== self::TAG_LENGTH || strlen( $len_raw ) !== 4 ) {
            throw new Exception( 'Corrupt archive: unexpected end of encrypted chunk header' );
        }

        $len = unpack( 'V', $len_raw )[1];
        $ciphertext = fread( $fh, $len );

        if ( strlen( $ciphertext ) !== $len ) {
            throw new Exception( 'Corrupt archive: truncated chunk data' );
        }

        $plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
        if ( $plaintext === false ) {
            throw new Exception( 'Decryption failed — wrong password or corrupt data' );
        }

        return $plaintext;
    }

    /**
     * Resolve the encryption key from password + key material, or use key material directly.
     *
     * @param string|null $password         User password (null = no password).
     * @param string      $key_material     32 bytes from the header.
     * @param bool        $password_protected Whether the archive requires a password.
     * @return string 32-byte encryption key.
     */
    private static function resolve_key( ?string $password, string $key_material, bool $password_protected ): string {
        if ( $password_protected ) {
            if ( empty( $password ) ) {
                throw new Exception( 'Password required for this archive' );
            }
            return self::derive_key( $password, $key_material );
        }
        // Not password-protected — key material IS the encryption key
        return $key_material;
    }

    private function resolve_verified_key( ?string $password, array $header ): string {
        $key_material = hex2bin( $header['key_material_hex'] );
        $key = self::resolve_key( $password, $key_material, $header['password_protected'] );

        if ( ! empty( $header['password_protected'] ) ) {
            $fh = fopen( $this->archive, 'rb' );
            if ( ! $fh ) {
                throw new Exception( 'Failed to open archive manifest' );
            }
            try {
                fseek( $fh, (int) $header['manifest_offset'] );
                self::read_encrypted_chunk( $fh, $key );
            } finally {
                fclose( $fh );
            }
        }

        return $key;
    }

    /* ------------------------------------- */
    /* HEADER                                */
    /* ------------------------------------- */

    /**
     * Read and validate the archive header.
     *
     * @return array{ version: int, password_protected: bool, key_material_hex: string,
     *               manifest_offset: int, manifest_size: int }
     */
    public function read_header(): array {
        $fh = fopen( $this->archive, 'rb' );
        $header = fread( $fh, self::HEADER_SIZE );
        fclose( $fh );

        if ( strlen( $header ) < self::HEADER_SIZE ) {
            throw new Exception( 'Invalid archive: header too short' );
        }

        $magic = substr( $header, 0, 4 );
        if ( $magic !== self::MAGIC ) {
            throw new Exception( 'Invalid archive: bad magic bytes' );
        }

        $version = unpack( 'C', $header[4] )[1];
        if ( $version > self::VERSION ) {
            throw new Exception( 'Archive version ' . esc_html( $version ) . ' is not supported' );
        }

        $flags              = unpack( 'C', $header[5] )[1];
        $password_protected = ( $flags & 1 ) === 1;
        $key_material       = substr( $header, 6, self::SALT_LENGTH );

        $manifest_offset = unpack( 'P', substr( $header, 38, 8 ) )[1];
        $manifest_size   = unpack( 'V', substr( $header, 46, 4 ) )[1];

        return [
            'version'            => $version,
            'password_protected' => $password_protected,
            'key_material_hex'   => bin2hex( $key_material ),
            'manifest_offset'    => $manifest_offset,
            'manifest_size'      => $manifest_size,
        ];
    }

    /**
     * Read and validate the optional ANFM footer.
     *
     * New site backups require this footer through SiteBackupPackageValidator.
     * Normal archive extraction accepts older ANFM archives without a footer,
     * but validates the footer strictly whenever it is present.
     *
     * @return array{footer_version:int,archive_version:int,package_size:int,manifest_offset:int,manifest_size:int,manifest_hash_hex:string}|null
     */
    public function read_footer(): ?array {
        $size = is_file( $this->archive ) ? (int) filesize( $this->archive ) : 0;
        if ( $size < self::HEADER_SIZE + self::FOOTER_SIZE ) {
            return null;
        }

        $fh = fopen( $this->archive, 'rb' );
        if ( ! $fh ) {
            throw new Exception( 'Failed to open archive footer' );
        }

        fseek( $fh, -self::FOOTER_SIZE, SEEK_END );
        $footer = fread( $fh, self::FOOTER_SIZE );
        fclose( $fh );

        if ( ! is_string( $footer ) || strlen( $footer ) !== self::FOOTER_SIZE ) {
            return null;
        }

        if ( substr( $footer, 0, 8 ) !== self::FOOTER_MAGIC ) {
            return null;
        }

        $footer_version = unpack( 'C', $footer[8] )[1];
        $archive_version = unpack( 'C', $footer[9] )[1];
        $package_size = unpack( 'P', substr( $footer, 12, 8 ) )[1];
        $manifest_offset = unpack( 'P', substr( $footer, 20, 8 ) )[1];
        $manifest_size = unpack( 'V', substr( $footer, 28, 4 ) )[1];
        $manifest_hash = substr( $footer, 32, 32 );

        if ( (int) $footer_version > self::FOOTER_VERSION || (int) $archive_version > self::VERSION ) {
            throw new Exception( 'Archive footer version is not supported' );
        }

        if ( (int) $package_size !== $size ) {
            throw new Exception( 'Archive footer size does not match file size' );
        }

        if ( (int) $manifest_offset + (int) $manifest_size + self::FOOTER_SIZE !== $size ) {
            throw new Exception( 'Archive footer manifest bounds are invalid' );
        }

        $actual_hash = $this->hash_file_region( (int) $manifest_offset, (int) $manifest_size );
        if ( ! hash_equals( $manifest_hash, $actual_hash ) ) {
            throw new Exception( 'Archive footer manifest hash mismatch' );
        }

        return [
            'footer_version'    => (int) $footer_version,
            'archive_version'   => (int) $archive_version,
            'package_size'      => (int) $package_size,
            'manifest_offset'   => (int) $manifest_offset,
            'manifest_size'     => (int) $manifest_size,
            'manifest_hash_hex' => bin2hex( $manifest_hash ),
        ];
    }

    private function hash_file_region( int $offset, int $length ): string {
        $fh = fopen( $this->archive, 'rb' );
        if ( ! $fh ) {
            throw new Exception( 'Failed to open archive for footer hash' );
        }

        fseek( $fh, $offset );
        $remaining = $length;
        $ctx = hash_init( 'sha256' );
        while ( $remaining > 0 ) {
            $chunk = fread( $fh, min( 1048576, $remaining ) );
            if ( $chunk === false || $chunk === '' ) {
                fclose( $fh );
                throw new Exception( 'Failed to hash archive manifest' );
            }
            $remaining -= strlen( $chunk );
            hash_update( $ctx, $chunk );
        }
        fclose( $fh );

        return hash_final( $ctx, true );
    }

    /* ------------------------------------- */
    /* ARCHIVE MANIFEST                      */
    /* ------------------------------------- */

    /**
     * Read, decrypt, and cache the archive manifest.
     *
     * This must be called once before run_step(). For password-protected
     * archives, this verifies the password by attempting to decrypt the
     * manifest (GCM auth will fail if wrong). On success, the decrypted
     * manifest is cached to disk so subsequent requests skip re-derivation.
     *
     * @param string|null $password Required only if archive is password-protected.
     * @return array Manifest info: total files, total size.
     */
    public function load_archive_manifest( ?string $password = null ): array {
        if ( file_exists( $this->manifest_meta_file ) && file_exists( $this->manifest_entries_file ) ) {
            $header = $this->read_header();
            $this->resolve_verified_key( $password, $header );
            $meta = $this->load_streaming_manifest_meta();
            return [
                'total'      => (int) ( $meta['total'] ?? 0 ),
                'total_size' => (int) ( $meta['total_size'] ?? 0 ),
            ];
        }

        if ( file_exists( $this->manifest_cache_file ) ) {
            $header = $this->read_header();
            $this->resolve_verified_key( $password, $header );
            $manifest = anibas_fm_read_small_json_file( $this->manifest_cache_file );
            if ( is_array( $manifest ) && isset( $manifest['files'] ) ) {
                return [
                    'total'      => count( $manifest['files'] ),
                    'total_size' => array_sum( array_column( $manifest['files'], 'size' ) ),
                ];
            }
        }

        $header = $this->read_header();

        if ( $header['manifest_offset'] === 0 || $header['manifest_size'] === 0 ) {
            throw new Exception( 'Archive has no manifest — it may be incomplete' );
        }

        $footer = $this->read_footer();
        if ( $footer !== null ) {
            if ( (int) $footer['manifest_offset'] !== (int) $header['manifest_offset']
                || (int) $footer['manifest_size'] !== (int) $header['manifest_size'] ) {
                throw new Exception( 'Archive header/footer manifest metadata mismatch' );
            }
        }

        $key_material = hex2bin( $header['key_material_hex'] );
        $key = self::resolve_key( $password, $key_material, $header['password_protected'] );

        if ( (int) $header['version'] >= 2 ) {
            return $this->load_streaming_archive_manifest( $header, $key );
        }

        if ( (int) $header['manifest_size'] > 1048576 ) {
            throw new Exception( 'Legacy ANFM manifest is larger than the safe restore limit for this archive version' );
        }

        $fh = fopen( $this->archive, 'rb' );
        fseek( $fh, $header['manifest_offset'] );
        $manifest_json = self::read_encrypted_chunk( $fh, $key );
        fclose( $fh );

        $manifest = json_decode( $manifest_json, true );
        if ( ! is_array( $manifest ) || ! isset( $manifest['files'] ) ) {
            throw new Exception( 'Corrupt archive manifest' );
        }

        // Cache to disk (atomic write)
        $tmp = $this->manifest_cache_file . '.tmp';
        file_put_contents( $tmp, wp_json_encode( $manifest ) );
        rename( $tmp, $this->manifest_cache_file );

        return [
            'total'      => count( $manifest['files'] ),
            'total_size' => array_sum( array_column( $manifest['files'], 'size' ) ),
        ];
    }

    public function preview_manifest( ?string $password = null, int $limit = 80, bool $validate_footer = true ): array {
        $limit  = max( 1, min( 200, $limit ) );
        $header = $this->read_header();

        if ( $header['manifest_offset'] === 0 || $header['manifest_size'] === 0 ) {
            throw new Exception( 'Archive has no manifest — it may be incomplete' );
        }

        if ( $validate_footer ) {
            $footer = $this->read_footer();
            if ( $footer !== null ) {
                if ( (int) $footer['manifest_offset'] !== (int) $header['manifest_offset']
                    || (int) $footer['manifest_size'] !== (int) $header['manifest_size'] ) {
                    throw new Exception( 'Archive header/footer manifest metadata mismatch' );
                }
            }
        }

        $key_material = hex2bin( $header['key_material_hex'] );
        $key = self::resolve_key( $password, $key_material, $header['password_protected'] );

        if ( (int) $header['version'] >= 2 ) {
            return $this->preview_streaming_archive_manifest( $header, $key, $limit );
        }

        if ( (int) $header['manifest_size'] > 1048576 ) {
            throw new Exception( 'Legacy ANFM manifest is larger than the safe preview limit for this archive version' );
        }

        $fh = fopen( $this->archive, 'rb' );
        fseek( $fh, $header['manifest_offset'] );
        $manifest_json = self::read_encrypted_chunk( $fh, $key );
        fclose( $fh );

        $manifest = json_decode( $manifest_json, true );
        if ( ! is_array( $manifest ) || ! isset( $manifest['files'] ) || ! is_array( $manifest['files'] ) ) {
            throw new Exception( 'Corrupt archive manifest' );
        }

        $files = array();
        foreach ( array_slice( $manifest['files'], 0, $limit ) as $entry ) {
            if ( is_array( $entry ) ) {
                $files[] = $this->normalize_preview_entry( $entry );
            }
        }

        $total = count( $manifest['files'] );
        return [
            'total'         => $total,
            'total_size'    => array_sum( array_map( static function ( $entry ) {
                return is_array( $entry ) ? (int) ( $entry['size'] ?? 0 ) : 0;
            }, $manifest['files'] ) ),
            'max_file_size' => 0,
            'max_file_name' => '',
            'files'         => $files,
            'limit'         => $limit,
            'truncated'     => $total > count( $files ),
        ];
    }

    private function preview_streaming_archive_manifest( array $header, string $key, int $limit ): array {
        $fh = fopen( $this->archive, 'rb' );
        if ( ! $fh ) {
            throw new Exception( 'Failed to open archive manifest' );
        }

        fseek( $fh, (int) $header['manifest_offset'] );
        $remaining = (int) $header['manifest_size'];
        $carry = '';
        $meta = null;
        $files = array();

        while ( $remaining > 0 && count( $files ) < $limit ) {
            $before = ftell( $fh );
            if ( $before === false ) {
                fclose( $fh );
                throw new Exception( 'Failed to read streaming manifest position' );
            }

            $plaintext = self::read_encrypted_chunk( $fh, $key );
            $after = ftell( $fh );
            if ( $after === false || $after <= $before ) {
                fclose( $fh );
                throw new Exception( 'Failed to advance streaming manifest' );
            }

            $remaining -= (int) ( $after - $before );
            if ( $remaining < 0 ) {
                fclose( $fh );
                throw new Exception( 'Streaming manifest exceeded its declared bounds' );
            }

            $carry .= $plaintext;
            $this->consume_preview_manifest_lines( $carry, $meta, $files, $limit );
        }

        fclose( $fh );

        if ( ! is_array( $meta ) ) {
            throw new Exception( 'Streaming archive manifest metadata is missing' );
        }

        return [
            'total'         => (int) ( $meta['total'] ?? 0 ),
            'total_size'    => (int) ( $meta['total_size'] ?? 0 ),
            'max_file_size' => (int) ( $meta['max_file_size'] ?? 0 ),
            'max_file_name' => (string) ( $meta['max_file_name'] ?? '' ),
            'files'         => $files,
            'limit'         => $limit,
            'truncated'     => (int) ( $meta['total'] ?? 0 ) > count( $files ),
        ];
    }

    private function consume_preview_manifest_lines( string &$buffer, ?array &$meta, array &$files, int $limit ): void {
        while ( count( $files ) < $limit && ( $pos = strpos( $buffer, "\n" ) ) !== false ) {
            $line = substr( $buffer, 0, $pos );
            $buffer = substr( $buffer, $pos + 1 );
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }

            $row = json_decode( $line, true );
            if ( ! is_array( $row ) ) {
                throw new Exception( 'Streaming archive manifest contains invalid JSON' );
            }

            if ( $meta === null ) {
                $candidate = isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : null;
                if ( ! is_array( $candidate )
                    || (string) ( $candidate['manifest_format'] ?? '' ) !== 'jsonl'
                    || (int) ( $candidate['manifest_version'] ?? 0 ) !== 2 ) {
                    throw new Exception( 'Streaming archive manifest metadata is invalid' );
                }
                $meta = $candidate;
                continue;
            }

            $files[] = $this->normalize_preview_entry( $row );
        }
    }

    private function normalize_preview_entry( array $entry ): array {
        $name = (string) ( $entry['name'] ?? '' );
        return [
            'name' => $name,
            'size' => (int) ( $entry['size'] ?? 0 ),
            'kind' => str_ends_with( $name, '/' ) ? 'directory' : 'file',
        ];
    }

    public function manifest_cache_ready(): bool {
        return file_exists( $this->manifest_meta_file )
            && file_exists( $this->manifest_entries_file )
            && is_dir( $this->manifest_path_index_dir );
    }

    public function prepare_manifest_cache_step( ?string $password = null ): array {
        if ( $this->manifest_cache_ready() ) {
            $header = $this->read_header();
            $this->resolve_verified_key( $password, $header );
            $meta = $this->load_streaming_manifest_meta();
            return $this->manifest_prepare_result( true, array(
                'manifest_size' => 1,
                'remaining'     => 0,
                'entries'       => (int) ( $meta['total'] ?? 0 ),
                'meta'          => $meta,
            ) );
        }

        $header = $this->read_header();
        if ( $header['manifest_offset'] === 0 || $header['manifest_size'] === 0 ) {
            throw new Exception( 'Archive has no manifest — it may be incomplete' );
        }

        $footer = $this->read_footer();
        if ( $footer !== null ) {
            if ( (int) $footer['manifest_offset'] !== (int) $header['manifest_offset']
                || (int) $footer['manifest_size'] !== (int) $header['manifest_size'] ) {
                throw new Exception( 'Archive header/footer manifest metadata mismatch' );
            }
        }

        if ( (int) $header['version'] < 2 ) {
            $info = $this->load_archive_manifest( $password );
            $this->prepare_legacy_manifest_entries();
            return $this->manifest_prepare_result( true, array(
                'manifest_size' => 1,
                'remaining'     => 0,
                'entries'       => (int) ( $info['total'] ?? 0 ),
                'meta'          => array(
                    'total'      => (int) ( $info['total'] ?? 0 ),
                    'total_size' => (int) ( $info['total_size'] ?? 0 ),
                ),
            ) );
        }

        $key_material = hex2bin( $header['key_material_hex'] );
        $key = self::resolve_key( $password, $key_material, $header['password_protected'] );
        $tmp_entries = $this->manifest_entries_file . '.tmp';
        $tmp_meta    = $this->manifest_meta_file . '.tmp';
        $tmp_index_dir = $this->manifest_path_index_dir . '.tmp';

        $state = $this->load_manifest_prepare_state( $header );
        $is_new = empty( $state['started'] );
        if ( ! $is_new && ( ! file_exists( $tmp_entries ) || ! is_dir( $tmp_index_dir ) ) ) {
            $state = $this->load_manifest_prepare_state( array_merge( $header, array( 'force_new' => true ) ) );
            $is_new = true;
        }
        if ( $is_new ) {
            wp_delete_file( $tmp_entries );
            wp_delete_file( $tmp_meta );
            $this->delete_manifest_path_index_dir( $tmp_index_dir );
            $this->ensure_manifest_path_index_dir( $tmp_index_dir );
            $state['started'] = true;
        }

        $entries_fh = fopen( $tmp_entries, 'ab' );
        if ( ! $entries_fh ) {
            throw new Exception( 'Failed to prepare streaming manifest cache' );
        }

        $fh = fopen( $this->archive, 'rb' );
        if ( ! $fh ) {
            fclose( $entries_fh );
            $this->delete_manifest_path_index_dir( $tmp_index_dir );
            throw new Exception( 'Failed to open archive manifest' );
        }

        try {
            fseek( $fh, (int) $state['archive_pos'] );
            $start = microtime( true );
            $budget = function_exists( 'anibas_fm_safe_time_budget' )
                ? max( 2, anibas_fm_safe_time_budget( 10, 0.45 ) )
                : 8;

            while ( (int) $state['remaining'] > 0 ) {
                $before = ftell( $fh );
                if ( $before === false ) {
                    throw new Exception( 'Failed to read streaming manifest position' );
                }

                $plaintext = self::read_encrypted_chunk( $fh, $key );
                $after = ftell( $fh );
                if ( $after === false || $after <= $before ) {
                    throw new Exception( 'Failed to advance streaming manifest' );
                }

                $state['archive_pos'] = (int) $after;
                $state['remaining'] = (int) $state['remaining'] - (int) ( $after - $before );
                if ( (int) $state['remaining'] < 0 ) {
                    throw new Exception( 'Streaming manifest exceeded its declared bounds' );
                }

                $state['carry'] = (string) ( $state['carry'] ?? '' ) . $plaintext;
                $this->consume_manifest_prepare_lines( $state, $entries_fh, $tmp_index_dir );

                if ( ( microtime( true ) - $start ) > $budget ) {
                    $this->save_manifest_prepare_state( $state );
                    fclose( $fh );
                    fclose( $entries_fh );
                    $fh = null;
                    $entries_fh = null;
                    return $this->manifest_prepare_result( false, $state );
                }
            }

            if ( (string) ( $state['carry'] ?? '' ) !== '' ) {
                $state['carry'] .= "\n";
                $this->consume_manifest_prepare_lines( $state, $entries_fh, $tmp_index_dir );
            }

            fclose( $fh );
            fclose( $entries_fh );
            $fh = null;
            $entries_fh = null;

            $meta = is_array( $state['meta'] ?? null ) ? $state['meta'] : null;
            if ( ! is_array( $meta ) ) {
                wp_delete_file( $tmp_entries );
                throw new Exception( 'Streaming archive manifest metadata is missing' );
            }

            $encoded = wp_json_encode( $meta );
            if ( ! is_string( $encoded ) || @file_put_contents( $tmp_meta, $encoded ) === false ) {
                wp_delete_file( $tmp_entries );
                throw new Exception( 'Failed to cache streaming manifest metadata' );
            }

            if ( ! rename( $tmp_entries, $this->manifest_entries_file )
                || ! rename( $tmp_meta, $this->manifest_meta_file ) ) {
                throw new Exception( 'Failed to finalize streaming manifest cache' );
            }
            $this->delete_manifest_path_index_dir( $this->manifest_path_index_dir );
            if ( ! rename( $tmp_index_dir, $this->manifest_path_index_dir ) ) {
                throw new Exception( 'Failed to finalize backup manifest path index' );
            }
            wp_delete_file( $this->manifest_load_state_file );

            return $this->manifest_prepare_result( true, $state );
        } catch ( \Throwable $e ) {
            if ( is_resource( $fh ) ) {
                fclose( $fh );
            }
            if ( is_resource( $entries_fh ) ) {
                fclose( $entries_fh );
            }
            wp_delete_file( $tmp_entries );
            wp_delete_file( $tmp_meta );
            $this->delete_manifest_path_index_dir( $tmp_index_dir );
            wp_delete_file( $this->manifest_load_state_file );
            throw $e;
        }
    }

    public function browse_manifest( string $directory = '', int $cursor = 0, int $limit = 300 ): array {
        $this->assert_manifest_cache_ready();
        $prefix = $this->normalize_manifest_directory( $directory );
        $children = array();
        $scanned = 0;
        $next_cursor = max( 0, $cursor );
        $complete = false;
        $start = microtime( true );
        $budget = function_exists( 'anibas_fm_safe_time_budget' )
            ? max( 2, anibas_fm_safe_time_budget( 20, 0.6 ) )
            : 20;

        $fh = fopen( $this->manifest_entries_file, 'rb' );
        if ( ! $fh ) {
            throw new Exception( 'Failed to open backup manifest index' );
        }
        if ( $next_cursor > 0 ) {
            fseek( $fh, $next_cursor );
        }

        while ( ( $line_start = ftell( $fh ) ) !== false && ( $line = fgets( $fh ) ) !== false ) {
            $next = ftell( $fh );
            $next_cursor = $next !== false ? (int) $next : $next_cursor;
            $scanned++;

            $entry = $this->decode_manifest_entry_line( $line );
            if ( is_array( $entry ) ) {
                $child = $this->manifest_child_from_entry( $entry, $prefix );
                if ( is_array( $child ) ) {
                    $key = (string) $child['path'];
                    if ( isset( $children[ $key ] ) ) {
                        $children[ $key ]['files'] += (int) $child['files'];
                        $children[ $key ]['total_size'] += (int) $child['total_size'];
                    } else {
                        $children[ $key ] = $child;
                    }
                }
            }

            if ( ( microtime( true ) - $start ) > $budget ) {
                break;
            }
        }

        if ( feof( $fh ) ) {
            $complete = true;
        }
        fclose( $fh );

        $items = array_values( $children );
        usort( $items, static function ( array $a, array $b ): int {
            if ( $a['kind'] !== $b['kind'] ) {
                return $a['kind'] === 'directory' ? -1 : 1;
            }
            return strnatcasecmp( (string) $a['name'], (string) $b['name'] );
        } );

        return array(
            'path'        => $prefix,
            'children'    => $items,
            'cursor'      => $next_cursor,
            'complete'    => $complete,
            'scanned'     => $scanned,
            'limit'       => 0,
        );
    }

    public function search_manifest( string $query, int $cursor = 0, int $limit = 100 ): array {
        $this->assert_manifest_cache_ready();
        $query = trim( $query );
        if ( $query === '' ) {
            return array(
                'query'    => '',
                'matches'  => array(),
                'cursor'   => 0,
                'complete' => true,
                'scanned'  => 0,
                'limit'    => 0,
            );
        }

        $needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
        $matches = array();
        $scanned = 0;
        $next_cursor = max( 0, $cursor );
        $complete = false;
        $start = microtime( true );
        $budget = function_exists( 'anibas_fm_safe_time_budget' )
            ? max( 2, anibas_fm_safe_time_budget( 20, 0.6 ) )
            : 20;

        $fh = fopen( $this->manifest_entries_file, 'rb' );
        if ( ! $fh ) {
            throw new Exception( 'Failed to open backup manifest index' );
        }
        if ( $next_cursor > 0 ) {
            fseek( $fh, $next_cursor );
        }

        while ( ( $line = fgets( $fh ) ) !== false ) {
            $next = ftell( $fh );
            $next_cursor = $next !== false ? (int) $next : $next_cursor;
            $scanned++;

            $entry = $this->decode_manifest_entry_line( $line );
            if ( is_array( $entry ) ) {
                $name = (string) ( $entry['name'] ?? '' );
                $haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
                if ( $name !== '' && strpos( $haystack, $needle ) !== false ) {
                    $matches[] = $this->normalize_manifest_result_entry( $entry );
                }
            }

            if ( ( microtime( true ) - $start ) > $budget ) {
                break;
            }
        }

        if ( feof( $fh ) ) {
            $complete = true;
        }
        fclose( $fh );

        return array(
            'query'    => $query,
            'matches'  => $matches,
            'cursor'   => $next_cursor,
            'complete' => $complete,
            'scanned'  => $scanned,
            'limit'    => 0,
        );
    }

    public function find_manifest_entry( string $name ): ?array {
        $this->assert_manifest_cache_ready();
        $name = $this->normalize_manifest_file_name( $name );
        if ( $name === '' ) {
            return null;
        }

        $indexed_entry = $this->find_manifest_entry_from_index( $name );
        if ( is_array( $indexed_entry ) ) {
            return $indexed_entry;
        }

        return null;
    }

    private function find_manifest_entry_from_index( string $name ): ?array {
        $bucket = $this->manifest_index_bucket_file( $name, $this->manifest_path_index_dir );
        if ( ! is_file( $bucket ) ) {
            return null;
        }

        $hash = hash( 'sha256', $name );
        $bucket_fh = fopen( $bucket, 'rb' );
        if ( ! $bucket_fh ) {
            return null;
        }

        $entries_fh = fopen( $this->manifest_entries_file, 'rb' );
        if ( ! $entries_fh ) {
            fclose( $bucket_fh );
            throw new Exception( 'Failed to open backup manifest index' );
        }

        while ( ( $line = fgets( $bucket_fh ) ) !== false ) {
            $row = json_decode( trim( $line ), true );
            if ( ! is_array( $row )
                || (string) ( $row['hash'] ?? '' ) !== $hash
                || (string) ( $row['name'] ?? '' ) !== $name ) {
                continue;
            }

            $offset = (int) ( $row['offset'] ?? -1 );
            if ( $offset < 0 || fseek( $entries_fh, $offset ) !== 0 ) {
                continue;
            }

            $entry_line = fgets( $entries_fh );
            if ( $entry_line === false ) {
                continue;
            }

            $entry = $this->decode_manifest_entry_line( $entry_line );
            if ( is_array( $entry ) && (string) ( $entry['name'] ?? '' ) === $name ) {
                fclose( $entries_fh );
                fclose( $bucket_fh );
                return $entry;
            }
        }

        fclose( $entries_fh );
        fclose( $bucket_fh );
        return null;
    }

    public function stream_manifest_file( string $name, ?string $password = null ): void {
        $entry = $this->find_manifest_entry( $name );
        if ( ! is_array( $entry ) ) {
            throw new Exception( 'File not found in backup manifest' );
        }

        $entry_name = (string) ( $entry['name'] ?? '' );
        if ( $entry_name === '' || str_ends_with( $entry_name, '/' ) ) {
            throw new Exception( 'Cannot download a directory from a backup package' );
        }

        $size = max( 0, (int) ( $entry['size'] ?? 0 ) );
        $offset = max( 0, (int) ( $entry['offset'] ?? 0 ) );
        $chunks = max( 0, (int) ( $entry['chunks'] ?? 0 ) );
        $download_name = sanitize_file_name( basename( str_replace( '\\', '/', $entry_name ) ) );
        if ( $download_name === '' ) {
            $download_name = 'backup-file';
        }

        $header = $this->read_header();
        $key = $this->resolve_verified_key( $password, $header );

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $download_name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $download_name ) );
        header( 'Content-Length: ' . $size );
        header( 'Expires: 0' );
        header( 'Cache-Control: no-cache, must-revalidate' );
        header( 'Pragma: public' );

        if ( $size === 0 || $chunks === 0 ) {
            exit;
        }

        $fh = fopen( $this->archive, 'rb' );
        if ( ! $fh ) {
            throw new Exception( 'Failed to open backup package' );
        }
        fseek( $fh, $offset );

        for ( $i = 0; $i < $chunks; $i++ ) {
            $data = self::read_encrypted_chunk( $fh, $key );
            echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary download stream.
            flush();
        }

        fclose( $fh );
        exit;
    }

    private function assert_manifest_cache_ready(): void {
        if ( ! $this->manifest_cache_ready() ) {
            throw new Exception( 'Backup manifest is not ready. Inspect the backup first.' );
        }
    }

    private function load_manifest_prepare_state( array $header ): array {
        if ( empty( $header['force_new'] ) && file_exists( $this->manifest_load_state_file ) ) {
            $state = anibas_fm_read_small_json_file( $this->manifest_load_state_file );
            if ( is_array( $state ) && isset( $state['archive_pos'], $state['remaining'], $state['manifest_size'] ) ) {
                return $state;
            }
        }

        return array(
            'started'       => false,
            'archive_pos'   => (int) $header['manifest_offset'],
            'remaining'     => (int) $header['manifest_size'],
            'manifest_size' => (int) $header['manifest_size'],
            'carry'         => '',
            'entries'       => 0,
            'meta'          => null,
        );
    }

    private function save_manifest_prepare_state( array $state ): void {
        $tmp = $this->manifest_load_state_file . '.tmp';
        $encoded = wp_json_encode( $state );
        if ( ! is_string( $encoded ) || @file_put_contents( $tmp, $encoded ) === false ) {
            throw new Exception( 'Failed to save backup manifest inspection state' );
        }
        rename( $tmp, $this->manifest_load_state_file );
    }

    private function manifest_prepare_result( bool $complete, array $state ): array {
        $manifest_size = max( 1, (int) ( $state['manifest_size'] ?? 1 ) );
        $remaining = max( 0, (int) ( $state['remaining'] ?? 0 ) );
        $processed = max( 0, $manifest_size - $remaining );
        $meta = is_array( $state['meta'] ?? null ) ? $state['meta'] : array();

        return array(
            'status'           => $complete ? 'ready' : 'preparing',
            'complete'         => $complete,
            'processed_bytes'  => $processed,
            'total_bytes'      => $manifest_size,
            'percent'          => round( min( 100, ( $processed / $manifest_size ) * 100 ), 2 ),
            'entries_indexed'  => (int) ( $state['entries'] ?? ( $meta['total'] ?? 0 ) ),
            'total'            => (int) ( $meta['total'] ?? 0 ),
            'total_size'       => (int) ( $meta['total_size'] ?? 0 ),
            'max_file_size'    => (int) ( $meta['max_file_size'] ?? 0 ),
            'max_file_name'    => (string) ( $meta['max_file_name'] ?? '' ),
        );
    }

    private function consume_manifest_prepare_lines( array &$state, $entries_fh, string $index_dir ): void {
        $buffer = (string) ( $state['carry'] ?? '' );
        while ( ( $pos = strpos( $buffer, "\n" ) ) !== false ) {
            $line = substr( $buffer, 0, $pos );
            $buffer = substr( $buffer, $pos + 1 );
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }

            $row = json_decode( $line, true );
            if ( ! is_array( $row ) ) {
                throw new Exception( 'Streaming archive manifest contains invalid JSON' );
            }

            if ( ! is_array( $state['meta'] ?? null ) ) {
                $candidate = isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : null;
                if ( ! is_array( $candidate )
                    || (string) ( $candidate['manifest_format'] ?? '' ) !== 'jsonl'
                    || (int) ( $candidate['manifest_version'] ?? 0 ) !== 2 ) {
                    throw new Exception( 'Streaming archive manifest metadata is invalid' );
                }
                $state['meta'] = $candidate;
                continue;
            }

            $entry_json = wp_json_encode( $row );
            $entry_offset = ftell( $entries_fh );
            if ( ! is_string( $entry_json ) || fwrite( $entries_fh, $entry_json . "\n" ) === false ) {
                throw new Exception( 'Failed to cache streaming archive manifest entry' );
            }
            if ( $entry_offset !== false ) {
                $this->index_manifest_entry( $row, (int) $entry_offset, $index_dir );
            }
            $state['entries'] = (int) ( $state['entries'] ?? 0 ) + 1;
        }
        $state['carry'] = $buffer;
    }

    private function ensure_manifest_path_index_dir( string $dir ): void {
        if ( is_dir( $dir ) ) {
            return;
        }
        if ( ! wp_mkdir_p( $dir ) ) {
            throw new Exception( 'Failed to prepare backup manifest path index' );
        }
    }

    private function delete_manifest_path_index_dir( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $files = glob( rtrim( $dir, '/' ) . '/*.jsonl' );
        if ( is_array( $files ) ) {
            foreach ( $files as $file ) {
                if ( is_file( $file ) ) {
                    wp_delete_file( $file );
                }
            }
        }

        @rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    }

    private function index_manifest_entry( array $entry, int $offset, string $index_dir ): void {
        $name = (string) ( $entry['name'] ?? '' );
        if ( $name === '' || str_ends_with( $name, '/' ) ) {
            return;
        }

        $hash = hash( 'sha256', $name );
        $bucket = $this->manifest_index_bucket_file( $name, $index_dir );
        $line = wp_json_encode( array(
            'hash'   => $hash,
            'name'   => $name,
            'offset' => $offset,
        ) );
        if ( ! is_string( $line ) || @file_put_contents( $bucket, $line . "\n", FILE_APPEND | LOCK_EX ) === false ) {
            throw new Exception( 'Failed to write backup manifest path index' );
        }
    }

    private function manifest_index_bucket_file( string $name, string $index_dir ): string {
        $hash = hash( 'sha256', $name );
        return rtrim( $index_dir, '/' ) . '/' . substr( $hash, 0, 2 ) . '.jsonl';
    }

    private function prepare_legacy_manifest_entries(): void {
        if ( $this->manifest_cache_ready() || ! file_exists( $this->manifest_cache_file ) ) {
            return;
        }

        $manifest = anibas_fm_read_small_json_file( $this->manifest_cache_file );
        $files = is_array( $manifest['files'] ?? null ) ? $manifest['files'] : array();
        $tmp_entries = $this->manifest_entries_file . '.tmp';
        $tmp_index_dir = $this->manifest_path_index_dir . '.tmp';
        $this->delete_manifest_path_index_dir( $tmp_index_dir );
        $this->ensure_manifest_path_index_dir( $tmp_index_dir );
        $entries_fh = fopen( $tmp_entries, 'wb' );
        if ( ! $entries_fh ) {
            $this->delete_manifest_path_index_dir( $tmp_index_dir );
            throw new Exception( 'Failed to prepare legacy backup manifest index' );
        }
        foreach ( $files as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $encoded = wp_json_encode( $entry );
            if ( is_string( $encoded ) ) {
                $entry_offset = ftell( $entries_fh );
                fwrite( $entries_fh, $encoded . "\n" );
                if ( $entry_offset !== false ) {
                    $this->index_manifest_entry( $entry, (int) $entry_offset, $tmp_index_dir );
                }
            }
        }
        fclose( $entries_fh );

        $meta = array(
            'total'      => count( $files ),
            'total_size' => array_sum( array_map( static function ( $entry ) {
                return is_array( $entry ) ? (int) ( $entry['size'] ?? 0 ) : 0;
            }, $files ) ),
        );
        $encoded_meta = wp_json_encode( $meta );
        if ( ! is_string( $encoded_meta ) || @file_put_contents( $this->manifest_meta_file . '.tmp', $encoded_meta ) === false ) {
            wp_delete_file( $tmp_entries );
            $this->delete_manifest_path_index_dir( $tmp_index_dir );
            throw new Exception( 'Failed to prepare legacy backup manifest metadata' );
        }
        if ( ! rename( $tmp_entries, $this->manifest_entries_file )
            || ! rename( $this->manifest_meta_file . '.tmp', $this->manifest_meta_file ) ) {
            $this->delete_manifest_path_index_dir( $tmp_index_dir );
            throw new Exception( 'Failed to finalize legacy backup manifest index' );
        }
        $this->delete_manifest_path_index_dir( $this->manifest_path_index_dir );
        if ( ! rename( $tmp_index_dir, $this->manifest_path_index_dir ) ) {
            throw new Exception( 'Failed to finalize backup manifest path index' );
        }
    }

    private function normalize_manifest_directory( string $directory ): string {
        $directory = str_replace( '\\', '/', trim( $directory ) );
        $directory = ltrim( $directory, '/' );
        if ( $directory === '' ) {
            return '';
        }
        if ( strpos( $directory, "\0" ) !== false || strpos( $directory, '..' ) !== false ) {
            throw new Exception( 'Invalid backup manifest directory' );
        }
        return trailingslashit( $directory );
    }

    private function normalize_manifest_file_name( string $name ): string {
        $name = str_replace( '\\', '/', trim( $name ) );
        $name = ltrim( $name, '/' );
        if ( strpos( $name, "\0" ) !== false || strpos( $name, '..' ) !== false ) {
            throw new Exception( 'Invalid backup manifest file path' );
        }
        return $name;
    }

    private function decode_manifest_entry_line( string $line ): ?array {
        $row = json_decode( trim( $line ), true );
        if ( ! is_array( $row ) ) {
            return null;
        }
        return isset( $row['entry'] ) && is_array( $row['entry'] ) ? $row['entry'] : $row;
    }

    private function manifest_child_from_entry( array $entry, string $prefix ): ?array {
        $name = (string) ( $entry['name'] ?? '' );
        if ( $name === '' || ( $prefix !== '' && ! str_starts_with( $name, $prefix ) ) ) {
            return null;
        }

        $rest = $prefix === '' ? $name : substr( $name, strlen( $prefix ) );
        if ( $rest === '' ) {
            return null;
        }

        $slash = strpos( $rest, '/' );
        $size = (int) ( $entry['size'] ?? 0 );
        if ( $slash === false ) {
            return array(
                'name'       => $rest,
                'path'       => $prefix . $rest,
                'kind'       => 'file',
                'size'       => $size,
                'total_size' => $size,
                'files'      => 1,
            );
        }

        $segment = substr( $rest, 0, $slash );
        if ( $segment === '' ) {
            return null;
        }
        return array(
            'name'       => $segment,
            'path'       => $prefix . $segment . '/',
            'kind'       => 'directory',
            'size'       => 0,
            'total_size' => $size,
            'files'      => 1,
        );
    }

    private function normalize_manifest_result_entry( array $entry ): array {
        $name = (string) ( $entry['name'] ?? '' );
        return array(
            'name' => $name,
            'path' => $name,
            'size' => (int) ( $entry['size'] ?? 0 ),
            'kind' => str_ends_with( $name, '/' ) ? 'directory' : 'file',
        );
    }

    private function load_streaming_archive_manifest( array $header, string $key ): array {
        $tmp_entries = $this->manifest_entries_file . '.tmp';
        $tmp_meta    = $this->manifest_meta_file . '.tmp';
        $tmp_index_dir = $this->manifest_path_index_dir . '.tmp';
        $this->delete_manifest_path_index_dir( $tmp_index_dir );
        $this->ensure_manifest_path_index_dir( $tmp_index_dir );
        $entries_fh  = fopen( $tmp_entries, 'wb' );
        if ( ! $entries_fh ) {
            $this->delete_manifest_path_index_dir( $tmp_index_dir );
            throw new Exception( 'Failed to prepare streaming manifest cache' );
        }

        $fh = fopen( $this->archive, 'rb' );
        if ( ! $fh ) {
            fclose( $entries_fh );
            $this->delete_manifest_path_index_dir( $tmp_index_dir );
            throw new Exception( 'Failed to open archive manifest' );
        }

        fseek( $fh, (int) $header['manifest_offset'] );
        $remaining = (int) $header['manifest_size'];
        $carry = '';
        $meta = null;

        while ( $remaining > 0 ) {
            $before = ftell( $fh );
            if ( $before === false ) {
                fclose( $fh );
                fclose( $entries_fh );
                throw new Exception( 'Failed to read streaming manifest position' );
            }
            $plaintext = self::read_encrypted_chunk( $fh, $key );
            $after = ftell( $fh );
            if ( $after === false || $after <= $before ) {
                fclose( $fh );
                fclose( $entries_fh );
                throw new Exception( 'Failed to advance streaming manifest' );
            }
            $remaining -= (int) ( $after - $before );
            if ( $remaining < 0 ) {
                fclose( $fh );
                fclose( $entries_fh );
                throw new Exception( 'Streaming manifest exceeded its declared bounds' );
            }

            $carry .= $plaintext;
            $this->consume_manifest_lines( $carry, $meta, $entries_fh, $tmp_index_dir );
        }

        if ( $carry !== '' ) {
            $carry .= "\n";
            $this->consume_manifest_lines( $carry, $meta, $entries_fh, $tmp_index_dir );
        }

        fclose( $fh );
        fclose( $entries_fh );

        if ( ! is_array( $meta ) ) {
            wp_delete_file( $tmp_entries );
            $this->delete_manifest_path_index_dir( $tmp_index_dir );
            throw new Exception( 'Streaming archive manifest metadata is missing' );
        }

        $encoded = wp_json_encode( $meta );
        if ( ! is_string( $encoded ) || @file_put_contents( $tmp_meta, $encoded ) === false ) {
            wp_delete_file( $tmp_entries );
            $this->delete_manifest_path_index_dir( $tmp_index_dir );
            throw new Exception( 'Failed to cache streaming manifest metadata' );
        }

        if ( ! rename( $tmp_entries, $this->manifest_entries_file )
            || ! rename( $tmp_meta, $this->manifest_meta_file ) ) {
            $this->delete_manifest_path_index_dir( $tmp_index_dir );
            throw new Exception( 'Failed to finalize streaming manifest cache' );
        }
        $this->delete_manifest_path_index_dir( $this->manifest_path_index_dir );
        if ( ! rename( $tmp_index_dir, $this->manifest_path_index_dir ) ) {
            throw new Exception( 'Failed to finalize backup manifest path index' );
        }

        return [
            'total'      => (int) ( $meta['total'] ?? 0 ),
            'total_size' => (int) ( $meta['total_size'] ?? 0 ),
        ];
    }

    private function consume_manifest_lines( string &$buffer, ?array &$meta, $entries_fh, string $index_dir ): void {
        while ( ( $pos = strpos( $buffer, "\n" ) ) !== false ) {
            $line = substr( $buffer, 0, $pos );
            $buffer = substr( $buffer, $pos + 1 );
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }

            $row = json_decode( $line, true );
            if ( ! is_array( $row ) ) {
                throw new Exception( 'Streaming archive manifest contains invalid JSON' );
            }

            if ( $meta === null ) {
                $candidate = isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : null;
                if ( ! is_array( $candidate )
                    || (string) ( $candidate['manifest_format'] ?? '' ) !== 'jsonl'
                    || (int) ( $candidate['manifest_version'] ?? 0 ) !== 2 ) {
                    throw new Exception( 'Streaming archive manifest metadata is invalid' );
                }
                $meta = $candidate;
                continue;
            }

            $entry_json = wp_json_encode( $row );
            if ( ! is_string( $entry_json ) ) {
                throw new Exception( 'Failed to cache streaming archive manifest entry' );
            }
            $entry_offset = ftell( $entries_fh );
            if ( fwrite( $entries_fh, $entry_json . "\n" ) === false ) {
                throw new Exception( 'Failed to cache streaming archive manifest entry' );
            }
            if ( $entry_offset !== false ) {
                $this->index_manifest_entry( $row, (int) $entry_offset, $index_dir );
            }
        }
    }

    private function load_streaming_manifest_meta(): array {
        $meta = anibas_fm_read_small_json_file( $this->manifest_meta_file );
        return is_array( $meta ) ? $meta : array();
    }

    /* ------------------------------------- */
    /* STATE                                 */
    /* ------------------------------------- */

    private function load_state(): array {
        if ( ! file_exists( $this->state_file ) ) {
            return [
                'cursor'      => 0,
                'chunk_index' => 0,
                'file_offset' => 0,
                'archive_pos' => 0,
            ];
        }
        $data = anibas_fm_read_small_json_file( $this->state_file );
        return is_array( $data ) ? $data : [
            'cursor'      => 0,
            'chunk_index' => 0,
            'file_offset' => 0,
            'archive_pos' => 0,
        ];
    }

    private function save_state( array $state ) {
        $tmp = $this->state_file . '.tmp';
        file_put_contents( $tmp, wp_json_encode( $state ) );
        rename( $tmp, $this->state_file );
    }

    /* ------------------------------------- */
    /* SECURITY                              */
    /* ------------------------------------- */

    /**
     * Validate target path is within destination. Check before mkdir.
     */
    private function safe_path( string $name ): string {
        if ( strpos( $name, '..' ) !== false ) {
            throw new Exception( 'Path traversal attempt: ' . esc_html( $name ) );
        }

        $base   = realpath( $this->dest );
        $target = $this->dest . '/' . $name;
        $dir    = dirname( $target );

        // Walk up to find an existing ancestor for realpath check
        $check_dir = $dir;
        while ( ! is_dir( $check_dir ) && $check_dir !== $this->dest ) {
            $check_dir = dirname( $check_dir );
        }

        $real_ancestor = realpath( $check_dir );
        if ( $base === false || $real_ancestor === false || ! $this->path_is_inside( $real_ancestor, $base ) || is_link( $target ) ) {
            throw new Exception( 'Path traversal attempt: ' . esc_html( $name ) );
        }

        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }

        return $target;
    }

    private function path_is_inside( string $path, string $base ): bool {
        $path = untrailingslashit( wp_normalize_path( $path ) );
        $base = untrailingslashit( wp_normalize_path( $base ) );
        return $path === $base || str_starts_with( $path . '/', trailingslashit( $base ) );
    }

    /* ------------------------------------- */
    /* MAIN WORKER                           */
    /* ------------------------------------- */

    /**
     * Extract files in a time-bounded step.
     *
     * All chunks are always decrypted. For password-protected archives the
     * password must be provided on every call. For non-protected archives
     * the key is read from the header automatically.
     *
     * @param string|null $password Required only if archive is password-protected.
     * @return bool true if more work remains, false if complete.
     */
    public function run_step( ?string $password = null ): bool {
        $lock = $this->acquire_lock();

        try {
            if ( file_exists( $this->manifest_meta_file ) && file_exists( $this->manifest_entries_file ) ) {
                $more = $this->run_streaming_step( $password );
                $this->release_lock( $lock );
                return $more;
            }

            if ( ! file_exists( $this->manifest_cache_file ) ) {
                throw new Exception( 'Manifest not loaded. Call load_archive_manifest() first.' );
            }

            $manifest = anibas_fm_read_small_json_file( $this->manifest_cache_file );
            $files    = $manifest['files'];
            $total    = count( $files );

            $header       = $this->read_header();
            $key_material = hex2bin( $header['key_material_hex'] );
            $key          = self::resolve_key( $password, $key_material, $header['password_protected'] );

            $state = $this->load_state();

            if ( $state['cursor'] >= $total ) {
                $this->release_lock( $lock );
                return false;
            }

            $fh    = fopen( $this->archive, 'rb' );
            $start = microtime( true );

            while ( $state['cursor'] < $total ) {

                $file_entry = $files[ $state['cursor'] ];
                $name       = $file_entry['name'];
                $file_size  = $file_entry['size'];
                $offset     = $file_entry['offset'];
                $chunks     = $file_entry['chunks'];

                $target = $this->safe_path( $name );

                // Determine if resuming mid-file
                $is_resume   = ( $state['chunk_index'] > 0 && $state['archive_pos'] > 0 );
                $out         = fopen( $target, $is_resume ? 'ab' : 'wb' );
                $chunk_index = $state['chunk_index'];

                // Seek archive to correct position
                if ( $is_resume && $state['archive_pos'] > 0 ) {
                    fseek( $fh, $state['archive_pos'] );
                } else {
                    fseek( $fh, $offset );
                    $state['archive_pos'] = $offset;
                }

                while ( $chunk_index < $chunks ) {
                    $data = self::read_encrypted_chunk( $fh, $key );

                    fwrite( $out, $data );
                    $chunk_index++;
                    $state['chunk_index'] = $chunk_index;
                    $state['archive_pos'] = ftell( $fh );
                    $state['file_offset'] += strlen( $data );

                    // Check time budget
                    if ( ( microtime( true ) - $start ) > $this->time_budget ) {
                        fclose( $out );
                        fclose( $fh );
                        $this->save_state( $state );
                        $this->release_lock( $lock );
                        return true;
                    }
                }

                fclose( $out );

                // File complete — advance to next
                $state['cursor']++;
                $state['chunk_index'] = 0;
                $state['file_offset'] = 0;
                $state['archive_pos'] = 0;
                $this->save_state( $state );
            }

            fclose( $fh );
            $this->release_lock( $lock );
            return false;

        } catch ( Exception $e ) {
            $this->release_lock( $lock );
            throw $e;
        }
    }

    private function run_streaming_step( ?string $password = null ): bool {
        $meta = $this->load_streaming_manifest_meta();
        $total = (int) ( $meta['total'] ?? 0 );

        $header       = $this->read_header();
        $key_material = hex2bin( $header['key_material_hex'] );
        $key          = self::resolve_key( $password, $key_material, $header['password_protected'] );

        $state = $this->load_state();
        if ( (int) ( $state['cursor'] ?? 0 ) >= $total ) {
            return false;
        }

        $fh = fopen( $this->archive, 'rb' );
        if ( ! $fh ) {
            throw new Exception( 'Failed to open archive for restore' );
        }
        $start = microtime( true );

        while ( (int) ( $state['cursor'] ?? 0 ) < $total ) {
            $file_entry = $this->current_streaming_entry( $state );
            if ( ! is_array( $file_entry ) ) {
                fclose( $fh );
                throw new Exception( 'Streaming archive manifest ended before all files were restored' );
            }

            $name   = (string) ( $file_entry['name'] ?? '' );
            $offset = (int) ( $file_entry['offset'] ?? 0 );
            $chunks = (int) ( $file_entry['chunks'] ?? 0 );
            $target = $this->safe_path( $name );

            $is_resume = ( (int) ( $state['chunk_index'] ?? 0 ) > 0 && (int) ( $state['archive_pos'] ?? 0 ) > 0 );
            $out = fopen( $target, $is_resume ? 'ab' : 'wb' );
            if ( ! $out ) {
                fclose( $fh );
                throw new Exception( 'Failed to open restore target file' );
            }

            $chunk_index = (int) ( $state['chunk_index'] ?? 0 );
            if ( $is_resume ) {
                fseek( $fh, (int) $state['archive_pos'] );
            } else {
                fseek( $fh, $offset );
                $state['archive_pos'] = $offset;
            }

            while ( $chunk_index < $chunks ) {
                $data = self::read_encrypted_chunk( $fh, $key );
                fwrite( $out, $data );
                $chunk_index++;
                $state['chunk_index'] = $chunk_index;
                $state['archive_pos'] = ftell( $fh );
                $state['file_offset'] = (int) ( $state['file_offset'] ?? 0 ) + strlen( $data );

                if ( ( microtime( true ) - $start ) > $this->time_budget ) {
                    fclose( $out );
                    fclose( $fh );
                    $this->save_state( $state );
                    return true;
                }
            }

            fclose( $out );
            $state['cursor'] = (int) ( $state['cursor'] ?? 0 ) + 1;
            $state['chunk_index'] = 0;
            $state['file_offset'] = 0;
            $state['archive_pos'] = 0;
            $state['manifest_entry_offset'] = (int) ( $state['next_manifest_entry_offset'] ?? $state['manifest_entry_offset'] ?? 0 );
            unset( $state['current_manifest_entry'], $state['current_manifest_entry_cursor'], $state['next_manifest_entry_offset'] );
            $this->save_state( $state );
        }

        fclose( $fh );
        return false;
    }

    private function current_streaming_entry( array &$state ): ?array {
        $cursor = (int) ( $state['cursor'] ?? 0 );
        if ( isset( $state['current_manifest_entry_cursor'], $state['current_manifest_entry'] )
            && (int) $state['current_manifest_entry_cursor'] === $cursor
            && is_array( $state['current_manifest_entry'] ) ) {
            $entry = isset( $state['current_manifest_entry']['entry'] ) && is_array( $state['current_manifest_entry']['entry'] )
                ? $state['current_manifest_entry']['entry']
                : $state['current_manifest_entry'];
            $state['current_manifest_entry'] = $entry;
            return $entry;
        }

        $offset = max( 0, (int) ( $state['manifest_entry_offset'] ?? 0 ) );
        $fh = fopen( $this->manifest_entries_file, 'rb' );
        if ( ! $fh ) {
            return null;
        }
        if ( $offset > 0 ) {
            fseek( $fh, $offset );
        }

        $line = fgets( $fh );
        $next = ftell( $fh );
        fclose( $fh );
        if ( $line === false || $line === '' ) {
            return null;
        }

        $entry = $this->decode_manifest_entry_line( $line );
        if ( ! is_array( $entry ) ) {
            return null;
        }

        $state['current_manifest_entry'] = $entry;
        $state['current_manifest_entry_cursor'] = $cursor;
        $state['next_manifest_entry_offset'] = $next !== false ? (int) $next : $offset;

        return $entry;
    }

    /* ------------------------------------- */
    /* PROGRESS                              */
    /* ------------------------------------- */

    public function progress(): array {
        if ( file_exists( $this->manifest_meta_file ) && file_exists( $this->manifest_entries_file ) ) {
            $meta = $this->load_streaming_manifest_meta();
            $state = $this->load_state();
            $total = (int) ( $meta['total'] ?? 0 );
            $current = (int) ( $state['cursor'] ?? 0 );
            return [
                'current' => $current,
                'total'   => $total,
                'percent' => $total > 0 ? round( ( $current / $total ) * 100, 2 ) : 0,
            ];
        }

        if ( ! file_exists( $this->manifest_cache_file ) ) {
            return [ 'current' => 0, 'total' => 0, 'percent' => 0 ];
        }

        $manifest = anibas_fm_read_small_json_file( $this->manifest_cache_file );
        $state    = $this->load_state();
        $total    = count( $manifest['files'] ?? [] );
        $current  = (int) ( $state['cursor'] ?? 0 );

        return [
            'current' => $current,
            'total'   => $total,
            'percent' => $total > 0 ? round( ( $current / $total ) * 100, 2 ) : 0,
        ];
    }

    /* ------------------------------------- */
    /* CLEANUP                               */
    /* ------------------------------------- */

    public function cleanup() {
        $files = [
            $this->manifest_cache_file,
            $this->manifest_meta_file,
            $this->manifest_entries_file,
            $this->manifest_load_state_file,
            $this->state_file,
            $this->lock_file,
            $this->manifest_cache_file . '.tmp',
            $this->manifest_meta_file . '.tmp',
            $this->manifest_entries_file . '.tmp',
            $this->manifest_load_state_file . '.tmp',
            $this->state_file . '.tmp',
        ];
        foreach ( $files as $f ) {
            if ( file_exists( $f ) ) {
                wp_delete_file( $f );
            }
        }
        $this->delete_manifest_path_index_dir( $this->manifest_path_index_dir );
        $this->delete_manifest_path_index_dir( $this->manifest_path_index_dir . '.tmp' );
        if ( is_dir( $this->state_dir ) ) {
            @rmdir( $this->state_dir );
        }
    }

    public function is_complete(): bool {
        if ( file_exists( $this->manifest_meta_file ) && file_exists( $this->manifest_entries_file ) ) {
            $meta = $this->load_streaming_manifest_meta();
            $state = $this->load_state();
            return (int) ( $state['cursor'] ?? 0 ) >= (int) ( $meta['total'] ?? 0 );
        }

        if ( ! file_exists( $this->manifest_cache_file ) ) {
            return false;
        }
        $manifest = anibas_fm_read_small_json_file( $this->manifest_cache_file );
        $state    = $this->load_state();
        $total    = count( $manifest['files'] ?? [] );
        return $state['cursor'] >= $total;
    }
}
