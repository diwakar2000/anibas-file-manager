<?php
/**
 * Chunked zip extraction engine.
 *
 * Extracts a zip archive across multiple HTTP requests, respecting
 * max_execution_time and memory limits. Uses flock for concurrency
 * control and atomic state writes for crash safety.
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Zip restore requires byte-accurate streaming, seeks, atomic renames, and manifest sidecar files.

use Exception, ZipArchive;

class ZipRestoreEngine {

    private string $zip;
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
     * Get or create an engine instance for a given zip + destination pair.
     */
    public static function get_instance( string $zip, string $dest ): ZipRestoreEngine {
        if ( ! file_exists( $zip ) ) {
            throw new Exception( 'Zip file does not exist' );
        }
        if ( ! is_dir( $dest ) ) {
            throw new Exception( 'Destination directory does not exist' );
        }
        $key = md5( $zip . '|' . $dest );
        if ( empty( self::$instances[ $key ] ) ) {
            self::$instances[ $key ] = new self( $zip, $dest );
        }
        return self::$instances[ $key ];
    }

    private function __construct( string $zip, string $dest ) {
        $this->zip  = $zip;
        $this->dest = rtrim( $dest, '/' );

        $state_dir = anibas_fm_get_archive_restore_state_dir( $zip, $this->dest, 'zip' );
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
    /* LOCKING (advisory, per-request)       */
    /* ------------------------------------- */

    /**
     * Acquire an exclusive non-blocking lock.
     *
     * flock() is a process-level advisory lock tied to the file descriptor.
     * It prevents concurrent requests from running extraction simultaneously.
     * The lock is automatically released when the file handle is closed or
     * when the PHP process terminates (even on fatal error / timeout).
     *
     * @return resource File handle holding the lock.
     */
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

    /**
     * Release the lock and close the file handle.
     *
     * @param resource $lock File handle from acquire_lock().
     */
    private function release_lock( $lock ) {
        if ( is_resource( $lock ) ) {
            flock( $lock, LOCK_UN );
            fclose( $lock );
        }
    }

    /* ------------------------------------- */
    /* MANIFEST BUILD                        */
    /* ------------------------------------- */

    /**
     * Build a manifest of all files in the zip archive.
     *
     * Uses atomic write (tmp + rename) so a crash mid-write
     * won't leave a corrupt manifest.
     */
    public function build_manifest(): void {
        do {
            $result = $this->build_manifest_step( PHP_INT_MAX );
        } while ( empty( $result['complete'] ) );
    }

    public function build_manifest_step( ?int $time_budget = null ): array {
        if ( $this->manifest_ready() ) {
            $meta = $this->load_manifest_meta();
            return $this->manifest_scan_progress( true, $meta );
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

        $zip = new ZipArchive();
        if ( $zip->open( $this->zip ) !== true ) {
            throw new Exception( 'Cannot open zip file' );
        }

        $entries_fh = fopen( $tmp_entries, 'ab' );
        if ( ! $entries_fh ) {
            $zip->close();
            throw new Exception( 'Failed to prepare zip manifest' );
        }

        $total_items = (int) $zip->numFiles;
        try {
            for ( $i = (int) ( $state['index'] ?? 0 ); $i < $total_items; $i++ ) {
                $stat = $zip->statIndex( $i );
                if ( is_array( $stat ) && isset( $stat['name'] ) && substr( (string) $stat['name'], -1 ) !== '/' ) {
                    $entry = array(
                        'i' => $i,
                        'n' => (string) $stat['name'],
                        's' => (int) ( $stat['size'] ?? 0 ),
                    );
                    $encoded = wp_json_encode( $entry );
                    if ( ! is_string( $encoded ) || fwrite( $entries_fh, $encoded . "\n" ) === false ) {
                        throw new Exception( 'Failed to write zip manifest entry' );
                    }
                    $state['total'] = (int) ( $state['total'] ?? 0 ) + 1;
                    $state['total_size'] = (int) ( $state['total_size'] ?? 0 ) + (int) $entry['s'];
                }

                $state['index'] = $i + 1;
                $state['total_items'] = $total_items;
                if ( ( microtime( true ) - $start ) > $budget ) {
                    fclose( $entries_fh );
                    $zip->close();
                    $this->save_manifest_scan_state( $state );
                    return $this->manifest_scan_progress( false, $state );
                }
            }

            fclose( $entries_fh );
            $entries_fh = null;
            $zip->close();
            $zip = null;

            $meta = array(
                'total'       => (int) ( $state['total'] ?? 0 ),
                'total_size'  => (int) ( $state['total_size'] ?? 0 ),
                'index'       => $total_items,
                'total_items' => $total_items,
            );
            $encoded_meta = wp_json_encode( $meta );
            if ( ! is_string( $encoded_meta ) || @file_put_contents( $tmp_meta, $encoded_meta ) === false ) {
                throw new Exception( 'Failed to write zip manifest metadata' );
            }
            if ( ! rename( $tmp_entries, $this->manifest_entries_file )
                || ! rename( $tmp_meta, $this->manifest_file ) ) {
                throw new Exception( 'Failed to finalize zip manifest' );
            }
            wp_delete_file( $this->manifest_scan_state_file );
            return $this->manifest_scan_progress( true, $meta );
        } catch ( \Throwable $e ) {
            if ( is_resource( $entries_fh ) ) {
                fclose( $entries_fh );
            }
            if ( $zip instanceof ZipArchive ) {
                $zip->close();
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
            if ( is_array( $state ) && isset( $state['index'] ) ) {
                return $state;
            }
        }

        return array(
            'started'     => false,
            'index'       => 0,
            'total'       => 0,
            'total_size'  => 0,
            'total_items' => 0,
        );
    }

    private function save_manifest_scan_state( array $state ): void {
        $tmp = $this->manifest_scan_state_file . '.tmp';
        $encoded = wp_json_encode( $state );
        if ( ! is_string( $encoded ) || @file_put_contents( $tmp, $encoded ) === false ) {
            throw new Exception( 'Failed to save zip manifest scan state' );
        }
        rename( $tmp, $this->manifest_scan_state_file );
    }

    private function manifest_scan_progress( bool $complete, array $state ): array {
        $total_items = max( 1, (int) ( $state['total_items'] ?? 1 ) );
        $processed = min( $total_items, (int) ( $state['index'] ?? 0 ) );

        return array(
            'complete'    => $complete,
            'total'       => (int) ( $state['total'] ?? 0 ),
            'total_size'  => (int) ( $state['total_size'] ?? 0 ),
            'processed'   => $processed,
            'total_items' => $total_items,
            'percent'     => round( min( 100, ( $processed / $total_items ) * 100 ), 2 ),
        );
    }

    private function read_manifest_entry_at_offset( $entries_fh, int $offset ): array {
        if ( fseek( $entries_fh, $offset ) !== 0 ) {
            throw new Exception( 'Failed to seek zip manifest entry' );
        }

        $line = fgets( $entries_fh );
        if ( $line === false ) {
            throw new Exception( 'Failed to read zip manifest entry' );
        }

        $next_offset = ftell( $entries_fh );
        $entry = json_decode( trim( $line ), true );
        if ( ! is_array( $entry ) || ! isset( $entry['n'] ) ) {
            throw new Exception( 'Invalid zip manifest entry' );
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
                'file'         => null,
                'offset'       => 0,
                'entry_offset' => 0,
            ];
        }

        $data = anibas_fm_read_small_json_file( $this->state_file );

        return is_array( $data ) ? $data : [
            'cursor'       => 0,
            'file'         => null,
            'offset'       => 0,
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
     * Validate the target path is within the destination directory.
     * Check BEFORE creating directories to prevent traversal via mkdir.
     */
    private function safe_path( string $file ): string {
        if ( strpos( $file, '..' ) !== false ) {
            throw new Exception( 'Zip path traversal attempt: ' . esc_html( $file ) );
        }

        $base   = realpath( $this->dest );
        $target = $this->dest . '/' . $file;
        $dir    = dirname( $target );

        // Resolve what the parent path would be (without creating it yet)
        // Walk up until we find an existing ancestor to realpath-check
        $check_dir = $dir;
        while ( ! is_dir( $check_dir ) && $check_dir !== $this->dest ) {
            $check_dir = dirname( $check_dir );
        }

        $real_ancestor = realpath( $check_dir );
        if ( $base === false || $real_ancestor === false || ! $this->path_is_inside( $real_ancestor, $base ) || is_link( $target ) ) {
            throw new Exception( 'Zip path traversal attempt: ' . esc_html( $file ) );
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
     * Extract files from the zip in a time-bounded step.
     *
     * @return bool true if more work remains, false if extraction is complete.
     */
    public function run_step(): bool {
        $lock = $this->acquire_lock();
        $zip = null;
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

            $zip = new ZipArchive();
            if ( $zip->open( $this->zip ) !== true ) {
                throw new Exception( 'Cannot open zip file' );
            }

            $start = microtime( true );
            $entries_fh = fopen( $this->manifest_entries_file, 'rb' );
            if ( ! $entries_fh ) {
                throw new Exception( 'Failed to open zip manifest entries' );
            }

            while ( $state['cursor'] < $total ) {
                [ $entry, $next_entry_offset ] = $this->read_manifest_entry_at_offset( $entries_fh, (int) ( $state['entry_offset'] ?? 0 ) );
                $name  = $entry['n'];

                $target = $this->safe_path( $name );

                $stream = $zip->getStream( $name );
                if ( ! $stream ) {
                    // Skip unreadable entries instead of crashing the whole operation
                    $state['cursor']++;
                    $state['file']   = null;
                    $state['offset'] = 0;
                    $state['entry_offset'] = $next_entry_offset;
                    $this->save_state( $state );
                    continue;
                }

                // Determine if we're resuming a partially written file
                $is_resume = ( $state['file'] === $name && $state['offset'] > 0 );

                $out = fopen( $target, $is_resume ? 'c+' : 'w' );

                if ( $is_resume ) {
                    // Seek the output file to the resume position
                    fseek( $out, $state['offset'] );

                    // Fast-forward the zip stream past already-written bytes
                    $skip = $state['offset'];
                    while ( $skip > 0 && ! feof( $stream ) ) {
                        $buf = fread( $stream, min( $skip, $this->chunk_size ) );
                        if ( $buf === false ) {
                            break;
                        }
                        $skip -= strlen( $buf );
                    }
                }

                // Reset offset for fresh files (fixes accumulation bug)
                if ( ! $is_resume ) {
                    $state['offset'] = 0;
                }

                while ( ! feof( $stream ) ) {
                    $chunk = fread( $stream, $this->chunk_size );
                    if ( $chunk === false ) {
                        break;
                    }

                    fwrite( $out, $chunk );

                    $state['file']    = $name;
                    $state['offset'] += strlen( $chunk );

                    // Check time budget after each chunk
                    if ( ( microtime( true ) - $start ) > $this->time_budget ) {
                        fclose( $stream );
                        fclose( $out );
                        fclose( $entries_fh );
                        $this->save_state( $state );
                        $zip->close();
                        $this->release_lock( $lock );
                        return true;
                    }
                }

                fclose( $stream );
                fclose( $out );

                // File complete — advance cursor, reset per-file state
                $state['cursor']++;
                $state['file']   = null;
                $state['offset'] = 0;
                $state['entry_offset'] = $next_entry_offset;
                $this->save_state( $state );
            }

            fclose( $entries_fh );
            $zip->close();
            $this->release_lock( $lock );
            return false;

        } catch ( \Throwable $e ) {
            if ( is_resource( $entries_fh ) ) {
                fclose( $entries_fh );
            }
            if ( $zip instanceof ZipArchive ) {
                $zip->close();
            }
            $this->release_lock( $lock );
            throw $e;
        }
    }

    /* ------------------------------------- */
    /* PROGRESS                              */
    /* ------------------------------------- */

    /**
     * Get extraction progress.
     *
     * @return array{ current: int, total: int, percent: float }
     */
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

    /**
     * Remove manifest, state, and lock files after extraction completes or is cancelled.
     */
    public function cleanup() {
        $files = [ $this->manifest_file, $this->state_file, $this->lock_file,
                   $this->manifest_entries_file, $this->manifest_entries_file . '.tmp',
                   $this->manifest_scan_state_file, $this->manifest_scan_state_file . '.tmp',
                   $this->manifest_file . '.tmp', $this->state_file . '.tmp' ];

        foreach ( $files as $file ) {
            if ( file_exists( $file ) ) {
                wp_delete_file( $file );
            }
        }
        if ( is_dir( $this->state_dir ) ) {
            @rmdir( $this->state_dir );
        }
    }

    /**
     * Check if extraction is complete (all entries processed).
     */
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
