<?php
/**
 * Chunked TAR archive extraction engine.
 *
 * Extracts a standard POSIX TAR (UStar) archive across multiple HTTP requests,
 * respecting max_execution_time and memory limits. Uses flock for concurrency
 * control and atomic state writes for crash safety.
 *
 * TAR is sequential — we read headers one at a time and extract each file's
 * data in chunks. No random access or central directory is needed.
 *
 * Usage:
 *   $engine = TarRestoreEngine::get_instance( $archive, $dest );
 *   $engine->build_manifest();
 *   while ( $engine->run_step() ) { }
 *   $engine->cleanup();
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- TAR restore requires byte-accurate streaming, seeks, atomic renames, and manifest sidecar files.

use Exception;

class TarRestoreEngine {

    private string $archive;
    private string $dest;

    private string $state_dir;
    private string $manifest_file;
    private string $manifest_entries_file;
    private string $manifest_scan_state_file;
    private string $state_file;
    private string $lock_file;

    private int $time_budget;
    private int $chunk_size;

    private static $instances = [];

    /**
     * Get or create an engine instance.
     */
    public static function get_instance( string $archive, string $dest ): self {
        if ( ! file_exists( $archive ) ) {
            throw new Exception( 'TAR file does not exist' );
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

        $state_dir = anibas_fm_get_archive_restore_state_dir( $archive, $this->dest, 'tar' );
        if ( ! $state_dir ) {
            throw new Exception( 'Failed to create archive restore state directory' );
        }
        $this->state_dir     = $state_dir;
        $this->manifest_file = $this->state_dir . '/manifest.json';
        $this->manifest_entries_file = $this->state_dir . '/manifest.entries.jsonl';
        $this->manifest_scan_state_file = $this->state_dir . '/manifest.scan-state.json';
        $this->state_file    = $this->state_dir . '/state.json';
        $this->lock_file     = $this->state_dir . '/lock';

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
    /* TAR HEADER PARSING                    */
    /* ------------------------------------- */

    /**
     * Parse a 512-byte TAR header block.
     *
     * @param string $block 512-byte raw header.
     * @return array|null Parsed entry or null if end-of-archive.
     */
    private function parse_header( string $block ): ?array {
        // End-of-archive marker: 512 bytes of zeros
        if ( trim( $block, "\0" ) === '' ) {
            return null;
        }

        // Validate UStar magic (optional — some tars don't have it)
        $magic = substr( $block, 257, 5 );

        $name   = rtrim( substr( $block, 0, 100 ), "\0" );
        $prefix = rtrim( substr( $block, 345, 155 ), "\0" );

        // Combine prefix + name for long paths
        if ( $prefix !== '' ) {
            $name = $prefix . '/' . $name;
        }

        $size_octal = trim( substr( $block, 124, 12 ), "\0 " );
        $size       = octdec( $size_octal );

        $type_flag = $block[156];

        // Type: '5' = directory, '0' or "\0" = regular file, 'L' = GNU long name
        $is_dir = ( $type_flag === '5' );

        // Verify header checksum
        $stored_checksum = octdec( trim( substr( $block, 148, 8 ), "\0 " ) );
        $check_block = substr( $block, 0, 148 ) . '        ' . substr( $block, 156 );
        $computed = 0;
        for ( $i = 0; $i < 512; $i++ ) {
            $computed += ord( $check_block[ $i ] );
        }
        if ( $computed !== $stored_checksum ) {
            throw new Exception( 'Corrupt TAR header: checksum mismatch for entry "' . esc_html( $name ) . '"' );
        }

        return [
            'name'      => $name,
            'size'      => (int) $size,
            'is_dir'    => $is_dir,
            'type_flag' => $type_flag,
        ];
    }

    /* ------------------------------------- */
    /* MANIFEST BUILD                        */
    /* ------------------------------------- */

    /**
     * Scan the TAR archive and build a manifest of all entries.
     *
     * Records each entry's name, size, type, and byte offset within the
     * archive for efficient extraction.
     */
    public function build_manifest(): void {
        do {
            $result = $this->build_manifest_step( PHP_INT_MAX );
        } while ( empty( $result['complete'] ) );
    }

    public function build_manifest_step( ?int $time_budget = null ): array {
        if ( $this->manifest_ready() ) {
            return $this->manifest_scan_progress( true, $this->load_manifest_meta() );
        }

        if ( file_exists( $this->manifest_file ) && ! file_exists( $this->manifest_entries_file ) ) {
            wp_delete_file( $this->manifest_file );
        }

        $budget = $time_budget ?? ( function_exists( 'anibas_fm_safe_time_budget' )
            ? max( 2, anibas_fm_safe_time_budget( 10, 0.45 ) )
            : 8 );
        $start = microtime( true );
        $state = $this->load_manifest_scan_state();
        $tmp_entries = $this->manifest_entries_file . '.tmp';
        $tmp_meta = $this->manifest_file . '.tmp';

        if ( ! empty( $state['started'] ) && ! file_exists( $tmp_entries ) ) {
            wp_delete_file( $this->manifest_scan_state_file );
            $state = $this->load_manifest_scan_state();
        }

        if ( empty( $state['started'] ) ) {
            wp_delete_file( $tmp_entries );
            wp_delete_file( $tmp_meta );
            $state['started'] = true;
        }

        $fh = fopen( $this->archive, 'rb' );
        if ( ! $fh ) {
            throw new Exception( 'Cannot open TAR archive' );
        }
        $entries_fh = fopen( $tmp_entries, 'ab' );
        if ( ! $entries_fh ) {
            fclose( $fh );
            throw new Exception( 'Failed to prepare TAR manifest' );
        }

        try {
            fseek( $fh, (int) ( $state['archive_offset'] ?? 0 ) );
            while ( true ) {
                $header_block = fread( $fh, 512 );
                if ( strlen( $header_block ) < 512 ) {
                    break;
                }

                $entry = $this->parse_header( $header_block );
                if ( $entry === null ) {
                    break;
                }

                if ( $entry['type_flag'] === 'L' ) {
                    $long_name_data = fread( $fh, $entry['size'] );
                    $long_name = rtrim( $long_name_data, "\0" );
                    $remainder = $entry['size'] % 512;
                    if ( $remainder > 0 ) {
                        fread( $fh, 512 - $remainder );
                    }
                    $header_block = fread( $fh, 512 );
                    if ( strlen( $header_block ) < 512 ) {
                        break;
                    }
                    $entry = $this->parse_header( $header_block );
                    if ( $entry === null ) {
                        break;
                    }
                    $entry['name'] = $long_name;
                }

                $data_offset = ftell( $fh );
                if ( $data_offset === false ) {
                    throw new Exception( 'Failed to read TAR manifest position' );
                }

                $manifest_entry = array(
                    'n' => (string) $entry['name'],
                    's' => (int) $entry['size'],
                    'd' => (bool) $entry['is_dir'],
                    'o' => (int) $data_offset,
                );
                $encoded = wp_json_encode( $manifest_entry );
                if ( ! is_string( $encoded ) || fwrite( $entries_fh, $encoded . "\n" ) === false ) {
                    throw new Exception( 'Failed to write TAR manifest entry' );
                }

                $state['total'] = (int) ( $state['total'] ?? 0 ) + 1;
                if ( empty( $entry['is_dir'] ) ) {
                    $state['total_size'] = (int) ( $state['total_size'] ?? 0 ) + (int) $entry['size'];
                }

                $next_offset = (int) $data_offset;
                if ( $entry['size'] > 0 ) {
                    $data_blocks = (int) ceil( $entry['size'] / 512 ) * 512;
                    $next_offset += $data_blocks;
                    fseek( $fh, $next_offset );
                }
                $state['archive_offset'] = $next_offset;

                if ( ( microtime( true ) - $start ) > $budget ) {
                    fclose( $entries_fh );
                    fclose( $fh );
                    $this->save_manifest_scan_state( $state );
                    return $this->manifest_scan_progress( false, $state );
                }
            }

            fclose( $entries_fh );
            $entries_fh = null;
            fclose( $fh );
            $fh = null;

            $archive_size = filesize( $this->archive );
            $meta = array(
                'total'          => (int) ( $state['total'] ?? 0 ),
                'total_size'     => (int) ( $state['total_size'] ?? 0 ),
                'archive_offset' => is_int( $archive_size ) ? $archive_size : (int) ( $state['archive_offset'] ?? 0 ),
            );
            $encoded_meta = wp_json_encode( $meta );
            if ( ! is_string( $encoded_meta ) || @file_put_contents( $tmp_meta, $encoded_meta ) === false ) {
                throw new Exception( 'Failed to write TAR manifest metadata' );
            }
            if ( ! rename( $tmp_entries, $this->manifest_entries_file )
                || ! rename( $tmp_meta, $this->manifest_file ) ) {
                throw new Exception( 'Failed to finalize TAR manifest' );
            }
            wp_delete_file( $this->manifest_scan_state_file );
            return $this->manifest_scan_progress( true, $meta );
        } catch ( \Throwable $e ) {
            if ( is_resource( $entries_fh ) ) {
                fclose( $entries_fh );
            }
            if ( is_resource( $fh ) ) {
                fclose( $fh );
            }
            throw $e;
        }
    }

    private function manifest_ready(): bool {
        return file_exists( $this->manifest_file ) && file_exists( $this->manifest_entries_file );
    }

    private function load_manifest_meta(): array {
        $meta = anibas_fm_read_small_json_file( $this->manifest_file );
        return is_array( $meta ) ? $meta : array();
    }

    private function load_manifest_scan_state(): array {
        if ( file_exists( $this->manifest_scan_state_file ) ) {
            $state = anibas_fm_read_small_json_file( $this->manifest_scan_state_file );
            if ( is_array( $state ) && isset( $state['archive_offset'] ) ) {
                return $state;
            }
        }

        return array(
            'started'        => false,
            'archive_offset' => 0,
            'total'          => 0,
            'total_size'     => 0,
        );
    }

    private function save_manifest_scan_state( array $state ): void {
        $tmp = $this->manifest_scan_state_file . '.tmp';
        $encoded = wp_json_encode( $state );
        if ( ! is_string( $encoded ) || @file_put_contents( $tmp, $encoded ) === false ) {
            throw new Exception( 'Failed to save TAR manifest scan state' );
        }
        rename( $tmp, $this->manifest_scan_state_file );
    }

    private function manifest_scan_progress( bool $complete, array $state ): array {
        $archive_size = max( 1, (int) filesize( $this->archive ) );
        $processed = min( $archive_size, (int) ( $state['archive_offset'] ?? 0 ) );

        return array(
            'complete'       => $complete,
            'total'          => (int) ( $state['total'] ?? 0 ),
            'total_size'     => (int) ( $state['total_size'] ?? 0 ),
            'processed'      => $processed,
            'total_items'    => $archive_size,
            'percent'        => round( min( 100, ( $processed / $archive_size ) * 100 ), 2 ),
        );
    }

    private function read_manifest_entry_at_offset( $entries_fh, int $offset ): array {
        if ( fseek( $entries_fh, $offset ) !== 0 ) {
            throw new Exception( 'Failed to seek TAR manifest entry' );
        }

        $line = fgets( $entries_fh );
        if ( $line === false ) {
            throw new Exception( 'Failed to read TAR manifest entry' );
        }

        $next_offset = ftell( $entries_fh );
        $entry = json_decode( trim( $line ), true );
        if ( ! is_array( $entry ) || ! isset( $entry['n'] ) ) {
            throw new Exception( 'Invalid TAR manifest entry' );
        }

        return array( $entry, is_int( $next_offset ) ? $next_offset : $offset );
    }

    private function manifest_offset_for_cursor( int $cursor ): int {
        if ( $cursor <= 0 ) {
            return 0;
        }

        $fh = fopen( $this->manifest_entries_file, 'rb' );
        if ( ! $fh ) {
            return 0;
        }

        $offset = 0;
        for ( $i = 0; $i < $cursor; $i++ ) {
            if ( fgets( $fh ) === false ) {
                break;
            }
            $pos = ftell( $fh );
            if ( is_int( $pos ) ) {
                $offset = $pos;
            }
        }
        fclose( $fh );

        return $offset;
    }

    /* ------------------------------------- */
    /* STATE                                 */
    /* ------------------------------------- */

    private function load_state(): array {
        if ( ! file_exists( $this->state_file ) ) {
            return [
                'cursor'       => 0,
                'file_offset'  => 0,
                'entry_offset' => 0,
            ];
        }
        $data = anibas_fm_read_small_json_file( $this->state_file );
        return is_array( $data ) ? $data : [
            'cursor'       => 0,
            'file_offset'  => 0,
            'entry_offset' => 0,
        ];
    }

    private function save_state( array $state ): void {
        $tmp = $this->state_file . '.tmp';
        file_put_contents( $tmp, wp_json_encode( $state ) );
        rename( $tmp, $this->state_file );
    }

    /* ------------------------------------- */
    /* SECURITY                              */
    /* ------------------------------------- */

    /**
     * Validate target path is within destination. Create directories if safe.
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
     * Extract files from the TAR archive in a time-bounded step.
     *
     * Reads file data directly from the archive at the recorded offsets.
     * Supports resuming mid-file across requests.
     *
     * @return bool true if more work remains, false if extraction is complete.
     */
    public function run_step(): bool {
        $lock = $this->acquire_lock();
        $fh = null;
        $entries_fh = null;

        try {
            if ( ! $this->manifest_ready() ) {
                throw new Exception( 'Manifest not built. Call build_manifest() first.' );
            }

            $manifest = $this->load_manifest_meta();
            $total   = (int) ( $manifest['total'] ?? 0 );
            $state   = $this->load_state();
            if ( ! array_key_exists( 'entry_offset', $state ) ) {
                $state['entry_offset'] = $this->manifest_offset_for_cursor( (int) ( $state['cursor'] ?? 0 ) );
            }

            if ( $state['cursor'] >= $total ) {
                $this->release_lock( $lock );
                return false;
            }

            $fh    = fopen( $this->archive, 'rb' );
            if ( ! $fh ) {
                throw new Exception( 'Cannot open TAR archive' );
            }
            $entries_fh = fopen( $this->manifest_entries_file, 'rb' );
            if ( ! $entries_fh ) {
                throw new Exception( 'Failed to open TAR manifest entries' );
            }
            $start = microtime( true );

            while ( $state['cursor'] < $total ) {
                [ $entry, $next_entry_offset ] = $this->read_manifest_entry_at_offset( $entries_fh, (int) ( $state['entry_offset'] ?? 0 ) );
                $name       = $entry['n'];
                $size       = (int) $entry['s'];
                $is_dir     = ! empty( $entry['d'] );
                $data_start = (int) $entry['o'];

                // Directories: just create them
                if ( $is_dir ) {
                    $target = $this->safe_path( $name );
                    if ( ! is_dir( $target ) ) {
                        mkdir( $target, 0755, true );
                    }
                    $state['cursor']++;
                    $state['file_offset'] = 0;
                    $state['entry_offset'] = $next_entry_offset;
                    $this->save_state( $state );

                    if ( ( microtime( true ) - $start ) > $this->time_budget ) {
                        fclose( $entries_fh );
                        fclose( $fh );
                        $this->release_lock( $lock );
                        return true;
                    }
                    continue;
                }

                // Regular file: extract data
                $target = $this->safe_path( $name );

                $is_resume = ( $state['file_offset'] > 0 );
                $out = fopen( $target, $is_resume ? 'ab' : 'wb' );

                // Seek archive to correct position (data_start + already-written offset)
                fseek( $fh, $data_start + $state['file_offset'] );

                $remaining = $size - $state['file_offset'];

                while ( $remaining > 0 ) {
                    $to_read = min( $remaining, $this->chunk_size );
                    $chunk   = fread( $fh, $to_read );

                    if ( $chunk === false || strlen( $chunk ) === 0 ) {
                        break;
                    }

                    fwrite( $out, $chunk );
                    $bytes_read = strlen( $chunk );
                    $state['file_offset'] += $bytes_read;
                    $remaining            -= $bytes_read;

                    // Check time budget after each chunk
                    if ( ( microtime( true ) - $start ) > $this->time_budget ) {
                        fclose( $out );
                        fclose( $entries_fh );
                        fclose( $fh );
                        $this->save_state( $state );
                        $this->release_lock( $lock );
                        return true;
                    }
                }

                fclose( $out );

                // File complete — advance cursor
                $state['cursor']++;
                $state['file_offset'] = 0;
                $state['entry_offset'] = $next_entry_offset;
                $this->save_state( $state );
            }

            fclose( $entries_fh );
            fclose( $fh );
            $this->release_lock( $lock );
            return false;

        } catch ( \Throwable $e ) {
            if ( is_resource( $entries_fh ) ) {
                fclose( $entries_fh );
            }
            if ( is_resource( $fh ) ) {
                fclose( $fh );
            }
            $this->release_lock( $lock );
            throw $e;
        }
    }

    /* ------------------------------------- */
    /* PROGRESS                              */
    /* ------------------------------------- */

    public function progress(): array {
        if ( ! $this->manifest_ready() ) {
            return [ 'current' => 0, 'total' => 0, 'percent' => 0 ];
        }

        $manifest = $this->load_manifest_meta();
        $state    = $this->load_state();
        $total    = isset( $manifest['total'] ) ? (int) $manifest['total'] : 0;
        $current  = isset( $state['cursor'] ) ? (int) $state['cursor'] : 0;

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
            $this->manifest_file,
            $this->manifest_entries_file,
            $this->state_file,
            $this->lock_file,
            $this->manifest_entries_file . '.tmp',
            $this->manifest_scan_state_file,
            $this->manifest_scan_state_file . '.tmp',
            $this->manifest_file . '.tmp',
            $this->state_file . '.tmp',
        ];
        foreach ( $files as $f ) {
            if ( file_exists( $f ) ) {
                wp_delete_file( $f );
            }
        }
        if ( is_dir( $this->state_dir ) ) {
            @rmdir( $this->state_dir );
        }
    }

    public function is_complete(): bool {
        if ( ! $this->manifest_ready() ) {
            return false;
        }
        $manifest = $this->load_manifest_meta();
        $state    = $this->load_state();
        $total    = isset( $manifest['total'] ) ? (int) $manifest['total'] : 0;
        return $state['cursor'] >= $total;
    }
}
