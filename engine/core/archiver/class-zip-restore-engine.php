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

use Exception, ZipArchive;

class ZipRestoreEngine {

    private string $zip;
    private string $dest;

    private string $manifest_file;
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

        $this->manifest_file = $this->dest . '/.archive_manifest.json';
        $this->state_file    = $this->dest . '/.archive_state.json';
        $this->lock_file     = $this->dest . '/.archive_lock';

        // Use ~60% of available execution time, minimum 10s
        $max_time = (int) ini_get( 'max_execution_time' );
        $this->time_budget = max( 10, $max_time > 0 ? (int) ( $max_time * 0.6 ) : 20 );

        $this->chunk_size = intval( anibas_fm_get_option( 'chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE ) );
        if ( $this->chunk_size < ANIBAS_FM_CHUNK_SIZE_MIN ) $this->chunk_size = ANIBAS_FM_CHUNK_SIZE_MIN;
        if ( $this->chunk_size > ANIBAS_FM_CHUNK_SIZE_MAX ) $this->chunk_size = ANIBAS_FM_CHUNK_SIZE_MAX;

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
    public function build_manifest() {
        if ( file_exists( $this->manifest_file ) ) {
            return;
        }

        $zip = new ZipArchive();

        if ( $zip->open( $this->zip ) !== true ) {
            throw new Exception( 'Cannot open zip file' );
        }

        $entries = [];

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $stat = $zip->statIndex( $i );

            // Skip directories
            if ( substr( $stat['name'], -1 ) === '/' ) {
                continue;
            }

            $entries[] = [
                'i' => $i,
                'n' => $stat['name'],
                's' => $stat['size'],
            ];
        }

        $zip->close();

        // Atomic write: write to tmp file, then rename
        $tmp = $this->manifest_file . '.tmp';
        file_put_contents( $tmp, json_encode( [
            'total'   => count( $entries ),
            'entries' => $entries,
        ] ) );
        rename( $tmp, $this->manifest_file );
    }

    /* ------------------------------------- */
    /* STATE                                 */
    /* ------------------------------------- */

    private function load_state(): array {
        if ( ! file_exists( $this->state_file ) ) {
            return [
                'cursor' => 0,
                'file'   => null,
                'offset' => 0,
            ];
        }

        $data = json_decode( file_get_contents( $this->state_file ), true );

        return is_array( $data ) ? $data : [
            'cursor' => 0,
            'file'   => null,
            'offset' => 0,
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
     * Validate the target path is within the destination directory.
     * Check BEFORE creating directories to prevent traversal via mkdir.
     */
    private function safe_path( string $file ): string {
        // Reject obviously malicious names
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
        if ( $real_ancestor === false || strpos( $real_ancestor, $base ) !== 0 ) {
            throw new Exception( 'Zip path traversal attempt: ' . esc_html( $file ) );
        }

        // Safe to create the directory now
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }

        return $target;
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

        try {
            if ( ! file_exists( $this->manifest_file ) ) {
                throw new Exception( 'Manifest not built. Call build_manifest() first.' );
            }

            $manifest = json_decode( file_get_contents( $this->manifest_file ), true );

            if ( ! is_array( $manifest ) || ! isset( $manifest['entries'] ) ) {
                throw new Exception( 'Invalid manifest file' );
            }

            $entries = $manifest['entries'];
            $total   = count( $entries );
            $state   = $this->load_state();

            if ( $state['cursor'] >= $total ) {
                $this->release_lock( $lock );
                return false;
            }

            $zip = new ZipArchive();
            if ( $zip->open( $this->zip ) !== true ) {
                throw new Exception( 'Cannot open zip file' );
            }

            $start = microtime( true );

            while ( $state['cursor'] < $total ) {

                $entry = $entries[ $state['cursor'] ];
                $name  = $entry['n'];

                $target = $this->safe_path( $name );

                $stream = $zip->getStream( $name );
                if ( ! $stream ) {
                    // Skip unreadable entries instead of crashing the whole operation
                    $state['cursor']++;
                    $state['file']   = null;
                    $state['offset'] = 0;
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
                $this->save_state( $state );
            }

            $zip->close();
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

    /**
     * Get extraction progress.
     *
     * @return array{ current: int, total: int, percent: float }
     */
    public function progress(): array {
        if ( ! file_exists( $this->manifest_file ) ) {
            return [ 'current' => 0, 'total' => 0, 'percent' => 0 ];
        }

        $manifest = json_decode( file_get_contents( $this->manifest_file ), true );
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
                   $this->manifest_file . '.tmp', $this->state_file . '.tmp' ];

        foreach ( $files as $file ) {
            if ( file_exists( $file ) ) {
                wp_delete_file( $file );
            }
        }
    }

    /**
     * Check if extraction is complete (all entries processed).
     */
    public function is_complete(): bool {
        if ( ! file_exists( $this->manifest_file ) ) {
            return false;
        }

        $manifest = json_decode( file_get_contents( $this->manifest_file ), true );
        $state    = $this->load_state();
        $total    = isset( $manifest['total'] ) ? (int) $manifest['total'] : 0;

        return $state['cursor'] >= $total;
    }
}