<?php
/**
 * Chunked encrypted archive extraction engine (.anfm format).
 *
 * ALL data in .anfm archives is always AES-256-GCM encrypted.
 * If the archive is password-protected (flag bit 0 = 1), the key is
 * derived from the user's password + the salt in the header.
 * If not password-protected (flag bit 0 = 0), the encryption key is
 * embedded directly in the header's key_material field.
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

use Exception;

class ArchiveRestoreEngine {

    const MAGIC             = 'ANFM';
    const VERSION           = 1;
    const HEADER_SIZE       = 50;
    const CIPHER            = 'aes-256-gcm';
    const IV_LENGTH         = 12;
    const TAG_LENGTH        = 16;
    const SALT_LENGTH       = 32;
    const PBKDF2_ITERATIONS = 100000;

    private string $archive;
    private string $dest;

    private string $manifest_cache_file;
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

        $this->manifest_cache_file = $this->dest . '/.anfm_manifest.json';
        $this->state_file          = $this->dest . '/.anfm_state.json';
        $this->lock_file           = $this->dest . '/.anfm_lock';

        $max_time = (int) ini_get( 'max_execution_time' );
        $this->time_budget = max( 10, $max_time > 0 ? (int) ( $max_time * 0.6 ) : 20 );
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
        // Return cached manifest if already loaded
        if ( file_exists( $this->manifest_cache_file ) ) {
            $manifest = json_decode( file_get_contents( $this->manifest_cache_file ), true );
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

        $key_material = hex2bin( $header['key_material_hex'] );
        $key = self::resolve_key( $password, $key_material, $header['password_protected'] );

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
        file_put_contents( $tmp, json_encode( $manifest ) );
        rename( $tmp, $this->manifest_cache_file );

        return [
            'total'      => count( $manifest['files'] ),
            'total_size' => array_sum( array_column( $manifest['files'], 'size' ) ),
        ];
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
        $data = json_decode( file_get_contents( $this->state_file ), true );
        return is_array( $data ) ? $data : [
            'cursor'      => 0,
            'chunk_index' => 0,
            'file_offset' => 0,
            'archive_pos' => 0,
        ];
    }

    private function save_state( array $state ) {
        $tmp = $this->state_file . '.tmp';
        file_put_contents( $tmp, json_encode( $state ) );
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
        if ( $real_ancestor === false || strpos( $real_ancestor, $base ) !== 0 ) {
            throw new Exception( 'Path traversal attempt: ' . esc_html( $name ) );
        }

        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }

        return $target;
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
            if ( ! file_exists( $this->manifest_cache_file ) ) {
                throw new Exception( 'Manifest not loaded. Call load_archive_manifest() first.' );
            }

            $manifest = json_decode( file_get_contents( $this->manifest_cache_file ), true );
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

    /* ------------------------------------- */
    /* PROGRESS                              */
    /* ------------------------------------- */

    public function progress(): array {
        if ( ! file_exists( $this->manifest_cache_file ) ) {
            return [ 'current' => 0, 'total' => 0, 'percent' => 0 ];
        }

        $manifest = json_decode( file_get_contents( $this->manifest_cache_file ), true );
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
            $this->state_file,
            $this->lock_file,
            $this->manifest_cache_file . '.tmp',
            $this->state_file . '.tmp',
        ];
        foreach ( $files as $f ) {
            if ( file_exists( $f ) ) {
                wp_delete_file( $f );
            }
        }
    }

    public function is_complete(): bool {
        if ( ! file_exists( $this->manifest_cache_file ) ) {
            return false;
        }
        $manifest = json_decode( file_get_contents( $this->manifest_cache_file ), true );
        $state    = $this->load_state();
        $total    = count( $manifest['files'] ?? [] );
        return $state['cursor'] >= $total;
    }
}
