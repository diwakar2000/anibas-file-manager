<?php
/**
 * Chunked encrypted archive creation engine (.anfm format).
 *
 * ALL data is always AES-256-GCM encrypted. When a password is provided,
 * the encryption key is derived via PBKDF2 from the password + a random salt
 * stored in the header. When no password is provided, a random encryption key
 * is embedded directly in the header — data is still fully encrypted and
 * unreadable in any text editor, but extractable by our software without
 * a password prompt.
 *
 * Binary format (.anfm):
 *   [Header: 50 bytes]
 *     4B  Magic "ANFM"
 *     1B  Version
 *     1B  Flags (bit 0 = password-protected)
 *     32B Key material:
 *         If password-protected: PBKDF2 salt (key = derive(password, salt))
 *         If not: the 256-bit encryption key itself
 *     8B  Manifest offset (uint64 LE)
 *     4B  Manifest size (uint32 LE)
 *   [File data: sequential encrypted chunks]
 *     [12B IV][16B GCM Tag][4B len][ciphertext]
 *   [Manifest: encrypted chunk stream]
 *     JSONL: first line metadata, following lines file entries.
 *   [Footer: 64 bytes at physical EOF]
 *     Magic, footer/archive versions, final package size, manifest offset/size,
 *     and SHA-256 of the encrypted manifest region.
 *
 * Usage:
 *   $engine = ArchiveCreateEngine::get_instance($source, $output, $password);
 *   $engine->build_manifest();
 *   $info = $engine->get_manifest_info();    // return to frontend
 *   while ($engine->run_step($password)) {}  // poll from AJAX
 *   $engine->cleanup();
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

use Exception;

/**
 * Time-budgeted engine that builds an AES-256-GCM encrypted .anfm archive
 * from a source path across multiple requests, deriving the encryption key
 * from an optional password via PBKDF2 and writing the binary
 * header/data/manifest/footer format described above.
 */
class ArchiveCreateEngine {

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
    const MANIFEST_CHUNK_SIZE = 1048576;

    private string $source;
    private string $output;

    private string $scan_manifest_file;
    private string $archive_manifest_entries_file;
    private string $state_file;
    private string $lock_file;

    private int $time_budget;
    private int $chunk_size;

    private static $instances = [];

    /**
     * Get or create an engine instance.
     */
    public static function get_instance( string $source, string $output ): self {
        if ( ! file_exists( $source ) ) {
            throw new Exception( 'Source path does not exist' );
        }
        $key = md5( $source . '|' . $output );
        if ( empty( self::$instances[ $key ] ) ) {
            self::$instances[ $key ] = new self( $source, $output );
        }
        return self::$instances[ $key ];
    }

    private function __construct( string $source, string $output ) {
        $this->source = rtrim( $source, '/' );
        $this->output = $output;

        $output_dir = dirname( $output );
        if ( ! is_dir( $output_dir ) ) {
            throw new Exception( 'Output directory does not exist' );
        }

        $this->scan_manifest_file = $output . '.scan.json';
        $this->archive_manifest_entries_file = $output . '.files.jsonl';
        $this->state_file         = $output . '.state.json';
        $this->lock_file          = $output . '.lock';

        $this->time_budget = function_exists( 'anibas_fm_safe_time_budget' )
            ? anibas_fm_safe_time_budget( 20, 0.6 )
            : 20;

        $this->chunk_size = intval( anibas_fm_get_option( 'chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE ) );
        $this->chunk_size = function_exists( 'anibas_fm_safe_chunk_size' )
            ? anibas_fm_safe_chunk_size( $this->chunk_size )
            : max( ANIBAS_FM_CHUNK_SIZE_MIN, min( ANIBAS_FM_CHUNK_SIZE_MAX, $this->chunk_size ) );

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
            throw new Exception( 'Another archive process is running' );
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
    /* ENCRYPTION                            */
    /* ------------------------------------- */

    /**
     * Derive a 256-bit key from password + salt via PBKDF2.
     */
    private static function derive_key( string $password, string $salt ): string {
        return hash_pbkdf2( 'sha256', $password, $salt, self::PBKDF2_ITERATIONS, 32, true );
    }

    /**
     * Encrypt data with AES-256-GCM. Returns [ iv, tag, ciphertext ].
     */
    private static function encrypt( string $data, string $key ): array {
        $iv  = random_bytes( self::IV_LENGTH );
        $tag = '';
        $ciphertext = openssl_encrypt(
            $data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH
        );
        if ( $ciphertext === false ) {
            throw new Exception( 'Encryption failed: ' . esc_html( openssl_error_string() ) );
        }
        return [ 'iv' => $iv, 'tag' => $tag, 'data' => $ciphertext ];
    }

    /**
     * Write an encrypted chunk to a file handle.
     * Returns number of bytes written.
     */
    private static function write_encrypted_chunk( $fh, string $data, string $key ): int {
        $enc = self::encrypt( $data, $key );
        fwrite( $fh, $enc['iv'] );                                // 12
        fwrite( $fh, $enc['tag'] );                               // 16
        fwrite( $fh, pack( 'V', strlen( $enc['data'] ) ) );      // 4
        fwrite( $fh, $enc['data'] );                              // N
        return self::IV_LENGTH + self::TAG_LENGTH + 4 + strlen( $enc['data'] );
    }

    /**
     * Resolve the encryption key from password + key material, or use key material directly.
     *
     * @param string|null $password     User password (null = no password).
     * @param string      $key_material 32 bytes from the header.
     * @param bool        $is_protected Whether the archive is password-protected.
     * @return string 32-byte encryption key.
     */
    private static function resolve_key( ?string $password, string $key_material, bool $is_protected ): string {
        if ( $is_protected ) {
            if ( empty( $password ) ) {
                throw new Exception( 'Password required for this archive' );
            }
            return self::derive_key( $password, $key_material );
        }
        // No password — key material IS the encryption key
        return $key_material;
    }

    /* ------------------------------------- */
    /* SCAN MANIFEST (file listing)          */
    /* ------------------------------------- */

    /**
     * Build a manifest of all source files.
     * Tracks max_file_size so the frontend can decide standard zip vs .anfm.
     */
    public function build_manifest() {
        if ( file_exists( $this->scan_manifest_file ) ) {
            return;
        }
        do {
            $result = $this->build_manifest_step( PHP_INT_MAX );
        } while ( empty( $result['complete'] ) );
    }

    public function build_manifest_step( ?int $time_budget = null ): array {
        $base_path = is_file( $this->source ) ? dirname( $this->source ) : $this->source;
        return ArchiveManifestStore::build_step(
            array( $this->source ),
            $base_path,
            $this->scan_manifest_file,
            false,
            array(),
            $time_budget
        );
    }

    /**
     * Return scan manifest summary for the frontend.
     */
    public function get_manifest_info(): array {
        if ( ! file_exists( $this->scan_manifest_file ) ) {
            throw new Exception( 'Manifest not built. Call build_manifest() first.' );
        }
        return ArchiveManifestStore::read_info( $this->scan_manifest_file );
    }

    /* ------------------------------------- */
    /* STATE                                 */
    /* ------------------------------------- */

    private function load_state(): array {
        if ( ! file_exists( $this->state_file ) ) {
            return [
                'phase'              => 'init',
                'cursor'             => 0,
                'file_offset'        => 0,
                'archive_pos'        => self::HEADER_SIZE,
                'chunks_written'     => 0,
                'current_file_start' => self::HEADER_SIZE,
                'bytes_processed'    => 0,
                'key_material_hex'   => '',
                'password_protected' => false,
            ];
        }
        $data = anibas_fm_read_small_json_file( $this->state_file );
        return is_array( $data ) ? $data : $this->load_state_defaults();
    }

    private function load_state_defaults(): array {
        return [
            'phase'              => 'init',
            'cursor'             => 0,
            'file_offset'        => 0,
            'archive_pos'        => self::HEADER_SIZE,
            'chunks_written'     => 0,
            'current_file_start' => self::HEADER_SIZE,
            'bytes_processed'    => 0,
            'salt_hex'           => '',
        ];
    }

    private function save_state( array $state ) {
        $tmp = $this->state_file . '.tmp';
        file_put_contents( $tmp, wp_json_encode( $state ) );
        rename( $tmp, $this->state_file );
    }

    /* ------------------------------------- */
    /* HEADER                                */
    /* ------------------------------------- */

    /**
     * Write the archive header.
     *
     * @param bool $password_protected Whether a user password is used.
     * @return string 32-byte key material written to the header.
     */
    private function init_archive( bool $password_protected ): string {
        // Always generate random 32 bytes.
        // If password-protected: this is the PBKDF2 salt.
        // If not: this IS the encryption key (embedded in the header).
        $key_material = random_bytes( self::SALT_LENGTH );
        $flags        = $password_protected ? 1 : 0;

        $fh = fopen( $this->output, 'wb' );
        fwrite( $fh, self::MAGIC );                         // 4
        fwrite( $fh, pack( 'C', self::VERSION ) );          // 1
        fwrite( $fh, pack( 'C', $flags ) );                 // 1
        fwrite( $fh, $key_material );                        // 32
        fwrite( $fh, pack( 'P', 0 ) );                      // 8 placeholder
        fwrite( $fh, pack( 'V', 0 ) );                      // 4 placeholder
        fclose( $fh );

        return $key_material;
    }

    /**
     * Update header with manifest offset and size.
     */
    private function finalize_header( int $manifest_offset, int $manifest_size ): void {
        $fh = fopen( $this->output, 'r+b' );
        fseek( $fh, 38 ); // offset to manifest_offset field
        fwrite( $fh, pack( 'P', $manifest_offset ) );
        fwrite( $fh, pack( 'V', $manifest_size ) );
        fclose( $fh );
    }

    /**
     * Write a fixed-size footer at the physical end of the ANFM package.
     *
     * Footer layout (64 bytes):
     *   8B magic "ANFMEND!"
     *   1B footer version
     *   1B archive version
     *   2B footer flags/reserved
     *   8B final package size
     *   8B manifest offset
     *   4B manifest size
     *   32B SHA-256 of the encrypted manifest region
     */
    private function write_footer( int $manifest_offset, int $manifest_size, string $manifest_hash ): int {
        if ( strlen( $manifest_hash ) !== 32 ) {
            throw new Exception( 'Invalid ANFM footer hash length' );
        }

        $package_size = $manifest_offset + $manifest_size + self::FOOTER_SIZE;
        $footer = self::FOOTER_MAGIC
            . pack( 'C', self::FOOTER_VERSION )
            . pack( 'C', self::VERSION )
            . pack( 'v', 0 )
            . pack( 'P', $package_size )
            . pack( 'P', $manifest_offset )
            . pack( 'V', $manifest_size )
            . $manifest_hash;

        if ( strlen( $footer ) !== self::FOOTER_SIZE ) {
            throw new Exception( 'Invalid ANFM footer size' );
        }

        $fh = fopen( $this->output, 'r+b' );
        if ( ! $fh ) {
            throw new Exception( 'Failed to open archive for footer write' );
        }
        fseek( $fh, $manifest_offset + $manifest_size );
        $written = fwrite( $fh, $footer );
        fclose( $fh );

        if ( $written !== self::FOOTER_SIZE ) {
            throw new Exception( 'Failed to write ANFM footer' );
        }

        return $package_size;
    }

    private function hash_file_region( int $offset, int $length ): string {
        $fh = fopen( $this->output, 'rb' );
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
                throw new Exception( 'Failed to hash ANFM manifest' );
            }
            $remaining -= strlen( $chunk );
            hash_update( $ctx, $chunk );
        }
        fclose( $fh );

        return hash_final( $ctx, true );
    }

    /* ------------------------------------- */
    /* MAIN WORKER                           */
    /* ------------------------------------- */

    /**
     * Process one time-bounded step of archive creation.
     *
     * All data is always encrypted. If a password is provided, the key is
     * derived from it via PBKDF2 (password-protected archive). If null,
     * a random key embedded in the header is used (obfuscated archive).
     *
     * @param string|null $password  User password, or null for keyless encryption.
     *                               Must match on every call for password-protected archives.
     * @return bool true if more work remains, false if complete.
     */
    public function run_step( ?string $password = null ): bool {
        $lock = $this->acquire_lock();

        try {
            if ( ! file_exists( $this->scan_manifest_file ) ) {
                throw new Exception( 'Scan manifest not built. Call build_manifest() first.' );
            }

            $scan = ArchiveManifestStore::read_manifest( $this->scan_manifest_file );
            if ( ! ArchiveManifestStore::is_valid_manifest( $scan ) ) {
                throw new Exception( 'Invalid scan manifest file' );
            }
            $total = (int) ( $scan['total'] ?? 0 );
            $state = $this->load_state();

            $password_protected = ! empty( $password );

            // Phase: init — write archive header
            if ( $state['phase'] === 'init' ) {
                $key_material = $this->init_archive( $password_protected );
                $state['key_material_hex']   = bin2hex( $key_material );
                $state['password_protected'] = $password_protected;
                $state['phase']              = 'data';
                $state['archive_pos']        = self::HEADER_SIZE;
                $state['current_file_start'] = self::HEADER_SIZE;
                $this->save_state( $state );
            }

            // Phase: data — write encrypted file chunks
            if ( $state['phase'] === 'data' ) {
                $key_material = hex2bin( $state['key_material_hex'] );
                $is_protected = ! empty( $state['password_protected'] );
                $key = self::resolve_key( $password, $key_material, $is_protected );

                $fh    = fopen( $this->output, 'r+b' );
                fseek( $fh, $state['archive_pos'] );
                $start = microtime( true );

                while ( $state['cursor'] < $total ) {
                    $entry = ArchiveManifestStore::current_entry( $scan, $state );
                    if ( ! $entry ) {
                        $state['cursor'] = $total;
                        $this->save_state( $state );
                        break;
                    }
                    $file_path = $entry['path'];

                    // Skip missing files
                    if ( ! file_exists( $file_path ) || ! is_file( $file_path ) ) {
                        ArchiveManifestStore::advance_entry( $scan, $state );
                        $state['file_offset']        = 0;
                        $state['chunks_written']     = 0;
                        $state['current_file_start'] = $state['archive_pos'];
                        $this->save_state( $state );
                        continue;
                    }

                    $src = fopen( $file_path, 'rb' );
                    if ( ! $src ) {
                        ArchiveManifestStore::advance_entry( $scan, $state );
                        $state['file_offset']        = 0;
                        $state['chunks_written']     = 0;
                        $state['current_file_start'] = $state['archive_pos'];
                        $this->save_state( $state );
                        continue;
                    }
                    if ( $state['file_offset'] > 0 ) {
                        fseek( $src, $state['file_offset'] );
                    }

                    while ( ! feof( $src ) ) {
                        $chunk = fread( $src, $this->chunk_size );
                        if ( $chunk === false || strlen( $chunk ) === 0 ) {
                            break;
                        }

                        $written = self::write_encrypted_chunk( $fh, $chunk, $key );

                        $state['archive_pos']     += $written;
                        $state['file_offset']     += strlen( $chunk );
                        $state['chunks_written']++;
                        $state['bytes_processed'] += strlen( $chunk );

                        // Check time budget after each chunk
                        if ( ( microtime( true ) - $start ) > $this->time_budget ) {
                            fclose( $src );
                            fclose( $fh );
                            $this->save_state( $state );
                            $this->release_lock( $lock );
                            return true;
                        }
                    }

                    fclose( $src );

                    // File complete — record entry for archive manifest
                    $this->append_archive_manifest_entry( (int) $state['cursor'], [
                        'name'   => $entry['name'],
                        'size'   => $entry['size'],
                        'offset' => $state['current_file_start'],
                        'chunks' => $state['chunks_written'],
                    ] );

                    ArchiveManifestStore::advance_entry( $scan, $state );
                    $state['file_offset']        = 0;
                    $state['chunks_written']     = 0;
                    $state['current_file_start'] = $state['archive_pos'];
                    $this->save_state( $state );
                }

                fclose( $fh );
                $state['phase'] = 'finalize';
                $this->save_state( $state );
            }

            // Phase: finalize — write encrypted archive manifest and update header
            if ( $state['phase'] === 'finalize' ) {
                $key_material = hex2bin( $state['key_material_hex'] );
                $is_protected = ! empty( $state['password_protected'] );
                $key = self::resolve_key( $password, $key_material, $is_protected );
                $start = microtime( true );

                if ( ! $this->summarize_archive_entries_step( $state, $scan, $start ) ) {
                    $this->save_state( $state );
                    $this->release_lock( $lock );
                    return true;
                }

                if ( ! $this->write_archive_manifest_step( $state, $key, $start ) ) {
                    $this->save_state( $state );
                    $this->release_lock( $lock );
                    return true;
                }

                $manifest_offset = (int) $state['manifest_offset'];
                $manifest_size = (int) $state['manifest_archive_pos'] - $manifest_offset;
                $manifest_hash = $this->hash_file_region( $manifest_offset, $manifest_size );
                $package_size  = $this->write_footer( $manifest_offset, $manifest_size, $manifest_hash );

                // Write manifest location into header after the footer exists.
                $this->finalize_header( $manifest_offset, $manifest_size );

                $state['archive_pos'] = $package_size;
                $state['package_size'] = $package_size;
                $state['manifest_hash'] = bin2hex( $manifest_hash );
                $state['phase'] = 'complete';
                $this->save_state( $state );
                $this->release_lock( $lock );
                return false;
            }

            $this->release_lock( $lock );
            return $state['phase'] !== 'complete';

        } catch ( Exception $e ) {
            $this->release_lock( $lock );
            throw $e;
        }
    }

    private function append_archive_manifest_entry( int $cursor, array $entry ): void {
        $json = wp_json_encode( array(
            'cursor' => $cursor,
            'entry'  => $entry,
        ) );
        if ( ! is_string( $json ) ) {
            throw new Exception( 'Failed to encode archive manifest entry' );
        }
        file_put_contents( $this->archive_manifest_entries_file, $json . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
    }

    private function summarize_archive_entries_step( array &$state, array $scan, float $started ): bool {
        if ( ! empty( $state['manifest_summary_complete'] ) ) {
            return true;
        }

        if ( ! isset( $state['manifest_summary'] ) || ! is_array( $state['manifest_summary'] ) ) {
            $state['manifest_summary'] = array(
                'total'         => 0,
                'total_size'    => 0,
                'max_file_size' => 0,
                'max_file_name' => '',
            );
            $state['manifest_summary_entry_offset'] = 0;
            $state['manifest_summary_last_cursor'] = -1;
            $state['manifest_summary_legacy_index'] = 0;
            $state['manifest_summary_legacy_done'] = false;
        }

        if ( empty( $state['manifest_summary_legacy_done'] ) ) {
            $legacy_entries = ! empty( $state['file_entries'] ) && is_array( $state['file_entries'] )
                ? $state['file_entries']
                : array();
            $index = max( 0, (int) ( $state['manifest_summary_legacy_index'] ?? 0 ) );
            $count = count( $legacy_entries );

            while ( $index < $count ) {
                if ( is_array( $legacy_entries[ $index ] ) ) {
                    $this->add_entry_to_summary( $state['manifest_summary'], $legacy_entries[ $index ] );
                }
                $index++;
                $state['manifest_summary_legacy_index'] = $index;

                if ( ( microtime( true ) - $started ) > $this->time_budget ) {
                    return false;
                }
            }

            $state['manifest_summary_legacy_done'] = true;
        }

        if ( is_file( $this->archive_manifest_entries_file ) ) {
            $in = fopen( $this->archive_manifest_entries_file, 'rb' );
            if ( ! $in ) {
                throw new Exception( 'Failed to read archive manifest entries' );
            }

            $offset = max( 0, (int) ( $state['manifest_summary_entry_offset'] ?? 0 ) );
            if ( $offset > 0 ) {
                fseek( $in, $offset );
            }

            $last_cursor = (int) ( $state['manifest_summary_last_cursor'] ?? -1 );
            try {
                while ( ( $line = fgets( $in ) ) !== false ) {
                    $next = ftell( $in );
                    $row = json_decode( trim( $line ), true );
                    if ( is_array( $row ) ) {
                        $cursor = isset( $row['cursor'] ) ? (int) $row['cursor'] : $last_cursor + 1;
                        $entry = isset( $row['entry'] ) && is_array( $row['entry'] ) ? $row['entry'] : $row;
                        if ( $cursor > $last_cursor ) {
                            $this->add_entry_to_summary( $state['manifest_summary'], $entry );
                            $last_cursor = $cursor;
                            $state['manifest_summary_last_cursor'] = $last_cursor;
                        }
                    }

                    $state['manifest_summary_entry_offset'] = $next !== false ? (int) $next : $offset;
                    if ( ( microtime( true ) - $started ) > $this->time_budget ) {
                        return false;
                    }
                }
            } finally {
                fclose( $in );
            }
        }

        $summary = is_array( $state['manifest_summary'] ?? null ) ? $state['manifest_summary'] : array();
        if ( (int) ( $summary['total'] ?? 0 ) === 0 && (int) ( $scan['total'] ?? 0 ) === 0 ) {
            $state['manifest_summary'] = array(
                'total'         => 0,
                'total_size'    => 0,
                'max_file_size' => 0,
                'max_file_name' => '',
            );
        }

        $state['manifest_summary_complete'] = true;
        return true;
    }

    private function write_archive_manifest_step( array &$state, string $key, float $started ): bool {
        if ( ! empty( $state['manifest_write_complete'] ) ) {
            return true;
        }

        if ( empty( $state['manifest_write_started'] ) ) {
            $state['manifest_write_started'] = true;
            $state['manifest_offset'] = (int) $state['archive_pos'];
            $state['manifest_archive_pos'] = (int) $state['archive_pos'];
            $state['manifest_entry_offset'] = 0;
            $state['manifest_last_cursor'] = -1;
            $state['manifest_legacy_index'] = 0;
            $state['manifest_legacy_done'] = false;
            $state['manifest_meta_written'] = false;
        }

        $fh = fopen( $this->output, 'r+b' );
        if ( ! $fh ) {
            throw new Exception( 'Failed to open archive for manifest write' );
        }
        fseek( $fh, (int) $state['manifest_archive_pos'] );

        $buffer = '';
        $flush = function () use ( &$buffer, &$state, $fh, $key ): void {
            if ( $buffer === '' ) {
                return;
            }
            $state['manifest_archive_pos'] = (int) $state['manifest_archive_pos'] + self::write_encrypted_chunk( $fh, $buffer, $key );
            $buffer = '';
        };
        $emit = function ( string $line ) use ( &$buffer, $flush ): void {
            if ( strlen( $line ) > self::MANIFEST_CHUNK_SIZE ) {
                throw new Exception( 'Archive manifest entry exceeds the safe chunk size' );
            }
            if ( $buffer !== '' && strlen( $buffer ) + strlen( $line ) > self::MANIFEST_CHUNK_SIZE ) {
                $flush();
            }
            $buffer .= $line;
        };

        try {
            if ( empty( $state['manifest_meta_written'] ) ) {
                $summary = is_array( $state['manifest_summary'] ?? null ) ? $state['manifest_summary'] : array();
                $meta = array(
                    'manifest_format'  => 'jsonl',
                    'manifest_version' => 2,
                    'total'            => (int) ( $summary['total'] ?? 0 ),
                    'total_size'       => (int) ( $summary['total_size'] ?? 0 ),
                    'max_file_size'    => (int) ( $summary['max_file_size'] ?? 0 ),
                    'max_file_name'    => (string) ( $summary['max_file_name'] ?? '' ),
                );
                $meta_json = wp_json_encode( array( 'meta' => $meta ) );
                if ( ! is_string( $meta_json ) ) {
                    throw new Exception( 'Failed to encode archive manifest metadata' );
                }
                $emit( $meta_json . "\n" );
                $flush();
                $state['manifest_meta_written'] = true;

                if ( ( microtime( true ) - $started ) > $this->time_budget ) {
                    return false;
                }
            }

            if ( empty( $state['manifest_legacy_done'] ) ) {
                $legacy_entries = ! empty( $state['file_entries'] ) && is_array( $state['file_entries'] )
                    ? $state['file_entries']
                    : array();
                $index = max( 0, (int) ( $state['manifest_legacy_index'] ?? 0 ) );
                $count = count( $legacy_entries );

                while ( $index < $count ) {
                    if ( is_array( $legacy_entries[ $index ] ) ) {
                        $entry_json = wp_json_encode( $legacy_entries[ $index ] );
                        if ( ! is_string( $entry_json ) ) {
                            throw new Exception( 'Failed to encode archive manifest entry' );
                        }
                        $emit( $entry_json . "\n" );
                    }
                    $index++;
                    $state['manifest_legacy_index'] = $index;

                    if ( ( microtime( true ) - $started ) > $this->time_budget ) {
                        $flush();
                        return false;
                    }
                }

                $state['manifest_legacy_done'] = true;
            }

            if ( is_file( $this->archive_manifest_entries_file ) ) {
                $in = fopen( $this->archive_manifest_entries_file, 'rb' );
                if ( ! $in ) {
                    throw new Exception( 'Failed to read archive manifest entries' );
                }

                $offset = max( 0, (int) ( $state['manifest_entry_offset'] ?? 0 ) );
                if ( $offset > 0 ) {
                    fseek( $in, $offset );
                }

                $last_cursor = (int) ( $state['manifest_last_cursor'] ?? -1 );
                try {
                    while ( ( $line = fgets( $in ) ) !== false ) {
                        $next = ftell( $in );
                        $row = json_decode( trim( $line ), true );
                        if ( is_array( $row ) ) {
                            $cursor = isset( $row['cursor'] ) ? (int) $row['cursor'] : $last_cursor + 1;
                            $entry = isset( $row['entry'] ) && is_array( $row['entry'] ) ? $row['entry'] : $row;
                            if ( $cursor > $last_cursor ) {
                                $entry_json = wp_json_encode( $entry );
                                if ( ! is_string( $entry_json ) ) {
                                    throw new Exception( 'Failed to encode archive manifest entry' );
                                }
                                $emit( $entry_json . "\n" );
                                $last_cursor = $cursor;
                                $state['manifest_last_cursor'] = $last_cursor;
                            }
                        }

                        $state['manifest_entry_offset'] = $next !== false ? (int) $next : $offset;
                        if ( ( microtime( true ) - $started ) > $this->time_budget ) {
                            $flush();
                            return false;
                        }
                    }
                } finally {
                    fclose( $in );
                }
            }

            $flush();
            $state['manifest_write_complete'] = true;
            return true;
        } finally {
            fclose( $fh );
        }
    }

    private function add_entry_to_summary( array &$summary, array $entry ): void {
        $size = (int) ( $entry['size'] ?? 0 );
        $name = (string) ( $entry['name'] ?? '' );
        $summary['total'] = (int) ( $summary['total'] ?? 0 ) + 1;
        $summary['total_size'] = (int) ( $summary['total_size'] ?? 0 ) + $size;
        if ( $size > (int) ( $summary['max_file_size'] ?? 0 ) ) {
            $summary['max_file_size'] = $size;
            $summary['max_file_name'] = $name;
        }
    }

    /* ------------------------------------- */
    /* PROGRESS                              */
    /* ------------------------------------- */

    public function progress(): array {
        if ( ! file_exists( $this->scan_manifest_file ) ) {
            return [ 'current' => 0, 'total' => 0, 'percent' => 0, 'bytes_processed' => 0, 'total_size' => 0, 'phase' => 'init' ];
        }

        $scan  = ArchiveManifestStore::read_manifest( $this->scan_manifest_file );
        $state = $this->load_state();

        $phase = (string) ( $state['phase'] ?? 'init' );
        $summary = is_array( $state['manifest_summary'] ?? null ) ? $state['manifest_summary'] : array();
        $total = (int) ( $scan['total'] ?? 0 );
        $total_size = (int) ( $scan['total_size'] ?? 0 );
        $current = (int) ( $state['cursor'] ?? 0 );

        if ( $phase === 'complete' && ! empty( $state['manifest_summary_complete'] ) ) {
            $total = (int) ( $summary['total'] ?? 0 );
            $total_size = (int) ( $summary['total_size'] ?? 0 );
            $current = $total;
        }

        return [
            'current'         => $current,
            'total'           => $total,
            'percent'         => $total > 0 ? round( ( $current / $total ) * 100, 2 ) : 0,
            'bytes_processed' => (int) ( $state['bytes_processed'] ?? 0 ),
            'total_size'      => $total_size,
            'phase'           => $phase,
        ];
    }

    /* ------------------------------------- */
    /* CLEANUP                               */
    /* ------------------------------------- */

    public function cleanup( bool $remove_output = false ) {
        $files = [
            $this->archive_manifest_entries_file,
            $this->archive_manifest_entries_file . '.tmp',
            $this->state_file,
            $this->lock_file,
            $this->state_file . '.tmp',
        ];
        if ( $remove_output ) {
            $files[] = $this->output;
        }
        foreach ( $files as $f ) {
            if ( file_exists( $f ) ) {
                wp_delete_file( $f );
            }
        }
        ArchiveManifestStore::cleanup( $this->scan_manifest_file );
    }

    public function is_complete(): bool {
        $state = $this->load_state();
        return ( $state['phase'] ?? '' ) === 'complete';
    }

    public function get_output_path(): string {
        return $this->output;
    }
}
