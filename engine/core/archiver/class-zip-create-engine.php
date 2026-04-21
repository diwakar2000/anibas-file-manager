<?php
/**
 * Chunked zip creation engine.
 *
 * Creates a zip archive from files/folders across multiple HTTP requests,
 * respecting max_execution_time and memory limits. Uses flock for concurrency
 * control and atomic state writes for crash safety.
 *
 * Usage:
 *   $engine = ZipCreateEngine::get_instance( $source_path, $output_zip );
 *   $engine->build_manifest();              // Scan source, build file list
 *   $info = $engine->get_manifest_info();   // Return to frontend for pre-check
 *   // Frontend checks $info['max_file_size'] — if above threshold, prompt
 *   // to use internal archive format instead of standard zip.
 *   while ( $engine->run_step() ) { }       // Call repeatedly from AJAX
 *   $engine->cleanup();                     // Remove temp state files
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

use Exception, ZipArchive, RecursiveDirectoryIterator, RecursiveIteratorIterator;

class ZipCreateEngine {

    private string $source;
    private string $output;

    private string $manifest_file;
    private string $state_file;
    private string $lock_file;

    private int $time_budget;

    private static $instances = [];

    /**
     * Get or create an engine instance for a given source + output pair.
     */
    public static function get_instance( string $source, string $output ): ZipCreateEngine {
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

        $this->manifest_file = $output . '.manifest.json';
        $this->state_file    = $output . '.state.json';
        $this->lock_file     = $output . '.lock';

        // Use ~60% of available execution time, minimum 10s
        $max_time = (int) ini_get( 'max_execution_time' );
        $this->time_budget = max( 10, $max_time > 0 ? (int) ( $max_time * 0.6 ) : 20 );
    }

    /* ------------------------------------- */
    /* LOCKING                               */
    /* ------------------------------------- */

    /**
     * Acquire an exclusive non-blocking lock.
     *
     * @return resource File handle holding the lock.
     */
    private function acquire_lock() {
        $lock = fopen( $this->lock_file, 'c' );

        if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
            if ( $lock ) {
                fclose( $lock );
            }
            throw new Exception( 'Another zip creation process is running' );
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
     * Build a manifest of all files to be zipped.
     *
     * Scans source path, records every file with its size, and tracks
     * max_file_size / max_file_name so the frontend can decide whether
     * standard zip is feasible or an internal archive format is needed.
     *
     * Uses atomic write (tmp + rename) for crash safety.
     */
    public function build_manifest() {
        if ( file_exists( $this->manifest_file ) ) {
            return;
        }

        $entries       = [];
        $max_file_size = 0;
        $max_file_name = '';

        if ( is_file( $this->source ) ) {
            $size = filesize( $this->source );
            $name = basename( $this->source );
            $entries[] = [
                'path' => $this->source,
                'name' => $name,
                'size' => $size,
            ];
            $max_file_size = $size;
            $max_file_name = $name;
        } else {
            // Directory — recursively list all files
            $base_len = strlen( $this->source ) + 1; // +1 for trailing slash
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $this->source,
                    RecursiveDirectoryIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ( $iterator as $item ) {
                if ( $item->isFile() ) {
                    $full_path     = $item->getPathname();
                    $relative_path = substr( $full_path, $base_len );
                    $size          = $item->getSize();

                    $entries[] = [
                        'path' => $full_path,
                        'name' => $relative_path,
                        'size' => $size,
                    ];

                    if ( $size > $max_file_size ) {
                        $max_file_size = $size;
                        $max_file_name = $relative_path;
                    }
                }
            }
        }

        // Atomic write
        $tmp = $this->manifest_file . '.tmp';
        file_put_contents( $tmp, json_encode( [
            'total'         => count( $entries ),
            'total_size'    => array_sum( array_column( $entries, 'size' ) ),
            'max_file_size' => $max_file_size,
            'max_file_name' => $max_file_name,
            'entries'       => $entries,
        ] ) );
        rename( $tmp, $this->manifest_file );
    }

    /* ------------------------------------- */
    /* MANIFEST INFO (for frontend)          */
    /* ------------------------------------- */

    /**
     * Return manifest summary for the frontend to decide how to proceed.
     *
     * Call after build_manifest(). The frontend should check max_file_size
     * against a threshold (e.g. 50MB) and prompt the user to use the
     * internal archive format if any file exceeds it.
     *
     * @return array{ total: int, total_size: int, max_file_size: int, max_file_name: string }
     */
    public function get_manifest_info(): array {
        if ( ! file_exists( $this->manifest_file ) ) {
            throw new Exception( 'Manifest not built. Call build_manifest() first.' );
        }

        $manifest = json_decode( file_get_contents( $this->manifest_file ), true );

        return [
            'total'         => isset( $manifest['total'] ) ? (int) $manifest['total'] : 0,
            'total_size'    => isset( $manifest['total_size'] ) ? (int) $manifest['total_size'] : 0,
            'max_file_size' => isset( $manifest['max_file_size'] ) ? (int) $manifest['max_file_size'] : 0,
            'max_file_name' => isset( $manifest['max_file_name'] ) ? $manifest['max_file_name'] : '',
        ];
    }

    /* ------------------------------------- */
    /* STATE                                 */
    /* ------------------------------------- */

    private function load_state(): array {
        if ( ! file_exists( $this->state_file ) ) {
            return [
                'cursor'      => 0,
                'bytes_added' => 0,
            ];
        }

        $data = json_decode( file_get_contents( $this->state_file ), true );

        return is_array( $data ) ? $data : [
            'cursor'      => 0,
            'bytes_added' => 0,
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
     * Validate a file path is within the source directory.
     */
    private function validate_path( string $path ): bool {
        if ( is_file( $this->source ) ) {
            return realpath( $path ) === realpath( $this->source );
        }

        $base = realpath( $this->source );
        $real = realpath( $path );

        if ( $real === false || $base === false ) {
            return false;
        }

        return strpos( $real, $base ) === 0;
    }

    /* ------------------------------------- */
    /* MAIN WORKER                           */
    /* ------------------------------------- */

    /**
     * Add files to the zip archive in a time-bounded step.
     *
     * ZipArchive::addFile() is memory-efficient — it streams the file
     * data when the archive is closed, not when addFile is called.
     * We close and reopen periodically to flush data to disk and
     * prevent holding too many file handles.
     *
     * Important: The frontend should verify via get_manifest_info()
     * that no file exceeds the safe threshold before calling run_step().
     * Files above the threshold should use the internal archive format.
     *
     * @return bool true if more work remains, false if zip creation is complete.
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

            // Open in CREATE mode for first step, CHECKCONS for subsequent (appends)
            $mode = file_exists( $this->output ) ? ZipArchive::CHECKCONS : ZipArchive::CREATE;
            if ( $zip->open( $this->output, $mode ) !== true ) {
                throw new Exception( 'Cannot create/open zip file' );
            }

            $start          = microtime( true );
            $files_in_batch = 0;

            while ( $state['cursor'] < $total ) {

                $entry     = $entries[ $state['cursor'] ];
                $file_path = $entry['path'];
                $zip_name  = $entry['name'];
                $file_size = $entry['size'];

                // Validate the file still exists and is within source
                if ( ! file_exists( $file_path ) || ! $this->validate_path( $file_path ) ) {
                    $state['cursor']++;
                    $this->save_state( $state );
                    continue;
                }

                $zip->addFile( $file_path, $zip_name );
                $files_in_batch++;

                $state['cursor']++;
                $state['bytes_added'] += $file_size;

                // Periodically flush: close and reopen every 100 files
                // This prevents ZipArchive from holding too many file handles
                // and also writes data to disk, bounding memory usage
                if ( $files_in_batch >= 100 ) {
                    $zip->close();
                    $this->save_state( $state );
                    $files_in_batch = 0;

                    if ( $state['cursor'] < $total ) {
                        if ( $zip->open( $this->output, ZipArchive::CHECKCONS ) !== true ) {
                            throw new Exception( 'Cannot reopen zip file after flush' );
                        }
                    }
                }

                // Check time budget
                if ( ( microtime( true ) - $start ) > $this->time_budget ) {
                    $zip->close();
                    $this->save_state( $state );
                    $this->release_lock( $lock );
                    return true;
                }
            }

            $zip->close();
            $this->save_state( $state );
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
     * Get zip creation progress.
     *
     * @return array{ current: int, total: int, percent: float, bytes_added: int, total_size: int }
     */
    public function progress(): array {
        if ( ! file_exists( $this->manifest_file ) ) {
            return [ 'current' => 0, 'total' => 0, 'percent' => 0, 'bytes_added' => 0, 'total_size' => 0 ];
        }

        $manifest   = json_decode( file_get_contents( $this->manifest_file ), true );
        $state      = $this->load_state();
        $total      = isset( $manifest['total'] ) ? (int) $manifest['total'] : 0;
        $total_size = isset( $manifest['total_size'] ) ? (int) $manifest['total_size'] : 0;
        $current    = isset( $state['cursor'] ) ? (int) $state['cursor'] : 0;

        return [
            'current'     => $current,
            'total'       => $total,
            'percent'     => $total > 0 ? round( ( $current / $total ) * 100, 2 ) : 0,
            'bytes_added' => isset( $state['bytes_added'] ) ? (int) $state['bytes_added'] : 0,
            'total_size'  => $total_size,
        ];
    }

    /* ------------------------------------- */
    /* CLEANUP                               */
    /* ------------------------------------- */

    /**
     * Remove manifest, state, and lock files.
     * Optionally remove the output zip file as well (for cancellation).
     * Always removes libzip temp files ({output}.XXXXXX.part) left behind
     * when ZipArchive::close() is interrupted by a connection reset.
     */
    public function cleanup( bool $remove_output = false ) {
        $files = [
            $this->manifest_file,
            $this->state_file,
            $this->lock_file,
            $this->manifest_file . '.tmp',
            $this->state_file . '.tmp',
        ];

        if ( $remove_output ) {
            $files[] = $this->output;
        }

        foreach ( $files as $file ) {
            if ( file_exists( $file ) ) {
                wp_delete_file( $file );
            }
        }

        // libzip writes a temp file named {output}.XXXXXX.part while closing the
        // archive, then atomically renames it. If the PHP process is killed or the
        // connection resets mid-close, these temp files are left behind. Remove them.
        foreach ( glob( $this->output . '.*.part' ) ?: [] as $part_file ) {
            wp_delete_file( $part_file );
        }
    }

    /**
     * Check if zip creation is complete.
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

    /**
     * Get the output zip file path.
     */
    public function get_output_path(): string {
        return $this->output;
    }
}
