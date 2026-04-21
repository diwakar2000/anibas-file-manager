<?php
/**
 * Site Backup Engine — coordinator for full-site backups.
 *
 * This is NOT a new archive format. It builds a custom file manifest
 * from the backup scope (wp-content + selected root files) and delegates
 * to TarCreateEngine or ArchiveCreateEngine for the actual archiving.
 *
 * The engine pre-writes the manifest file so the delegate engine's
 * build_manifest() is a no-op, then lets run_step() process files normally.
 *
 * Usage (from AJAX):
 *   $result = BackupEngine::start( 'tar' );         // returns job info
 *   $engine = BackupEngine::resume( $job_id );       // resume from state
 *   $more   = $engine->run_step();                   // time-budgeted
 *   $prog   = $engine->progress();                   // for polling
 *   $engine->cancel();                               // cleanup + unlock
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class BackupEngine {

    private $format;
    private $output;
    private $source;
    private $engine;
    private $job_id;
    private $password;

    /** Directories to exclude from wp-content backup. */
    private static $excluded_dirs = array();

    /**
     * Start a new backup.
     *
     * @param string      $format   'tar' or 'anfm'.
     * @param string|null $password Encryption password (only for anfm).
     * @return array { job_id, output, info }
     */
    public static function start( $format = 'tar', $password = null ) {
        if ( anibas_fm_is_backup_running() ) {
            throw new \Exception( esc_html__( 'A backup is already in progress.', 'anibas-file-manager' ) );
        }

        if ( ! in_array( $format, array( 'tar', 'anfm' ), true ) ) {
            $format = 'tar';
        }

        $backup_dir = anibas_fm_get_backup_dir();
        $timestamp  = gmdate( 'Y-m-d_His' );
        $ext        = $format === 'anfm' ? '.anfm' : '.tar';
        $filename   = 'backup-' . $timestamp . $ext;
        $output     = $backup_dir . '/' . $filename;

        $source  = untrailingslashit( realpath( ABSPATH ) );
        $job_id  = 'backup_' . wp_generate_password( 12, false );

        // Build and write a custom manifest
        $instance = new self();
        $instance->format   = $format;
        $instance->output   = $output;
        $instance->source   = $source;
        $instance->job_id   = $job_id;
        $instance->password = $password;

        $manifest_info = $instance->build_backup_manifest();

        // Now create the delegate engine — build_manifest() will skip
        // because the manifest file already exists.
        if ( $format === 'anfm' ) {
            $engine = ArchiveCreateEngine::get_instance( $source, $output );
        } else {
            $engine = TarCreateEngine::get_instance( $source, $output );
        }
        $engine->build_manifest(); // no-op — file already exists

        // Set the backup lock
        anibas_fm_set_backup_lock( $job_id, $format, $filename );

        // Persist backup job state
        $state = array(
            'job_id'   => $job_id,
            'format'   => $format,
            'output'   => $output,
            'source'   => $source,
            'password' => ! empty( $password ) ? '1' : '0',
        );
        set_transient( 'anibas_fm_backup_job_' . $job_id, $state, 2 * HOUR_IN_SECONDS );

        return array(
            'job_id' => $job_id,
            'output' => $filename,
            'info'   => $manifest_info,
        );
    }

    /**
     * Resume an existing backup job by its ID.
     *
     * @param string      $job_id   The backup job ID.
     * @param string|null $password Encryption password (only for anfm).
     * @return self
     */
    public static function resume( $job_id, $password = null ) {
        $state = get_transient( 'anibas_fm_backup_job_' . $job_id );
        if ( ! $state ) {
            throw new \Exception( esc_html__( 'Backup job not found or expired.', 'anibas-file-manager' ) );
        }

        $instance           = new self();
        $instance->job_id   = $job_id;
        $instance->format   = $state['format'];
        $instance->output   = $state['output'];
        $instance->source   = $state['source'];
        $instance->password = $password;

        if ( $state['format'] === 'anfm' ) {
            $instance->engine = ArchiveCreateEngine::get_instance( $state['source'], $state['output'] );
        } else {
            $instance->engine = TarCreateEngine::get_instance( $state['source'], $state['output'] );
        }

        return $instance;
    }

    /**
     * Run one time-budgeted step of the backup.
     *
     * @return bool true if more work remains, false if complete.
     */
    public function run_step() {
        if ( ! $this->engine ) {
            if ( $this->format === 'anfm' ) {
                $this->engine = ArchiveCreateEngine::get_instance( $this->source, $this->output );
            } else {
                $this->engine = TarCreateEngine::get_instance( $this->source, $this->output );
            }
        }

        if ( $this->format === 'anfm' ) {
            $pwd  = ! empty( $this->password ) ? $this->password : null;
            $more = $this->engine->run_step( $pwd );
        } else {
            $more = $this->engine->run_step();
        }

        if ( ! $more ) {
            $this->engine->cleanup();
            $this->finish();
        }

        return $more;
    }

    /**
     * Get current progress.
     *
     * @return array
     */
    public function progress() {
        if ( ! $this->engine ) {
            if ( $this->format === 'anfm' ) {
                $this->engine = ArchiveCreateEngine::get_instance( $this->source, $this->output );
            } else {
                $this->engine = TarCreateEngine::get_instance( $this->source, $this->output );
            }
        }

        return $this->engine->progress();
    }

    /**
     * Cancel the backup — cleanup temp files + unlock.
     */
    public function cancel() {
        if ( ! $this->engine ) {
            try {
                if ( $this->format === 'anfm' ) {
                    $this->engine = ArchiveCreateEngine::get_instance( $this->source, $this->output );
                } else {
                    $this->engine = TarCreateEngine::get_instance( $this->source, $this->output );
                }
            } catch ( \Exception $e ) {
                // Engine creation may fail if source doesn't exist; proceed with cleanup
            }
        }

        if ( $this->engine ) {
            $this->engine->cleanup( true ); // remove partial output
        }

        // Also remove the output file if it exists
        if ( file_exists( $this->output ) ) {
            wp_delete_file( $this->output );
        }

        $this->finish();
    }

    /**
     * Clear the lock and the job transient.
     */
    private function finish() {
        anibas_fm_clear_backup_lock();
        delete_transient( 'anibas_fm_backup_job_' . $this->job_id );
    }

    /**
     * Build a custom manifest covering the backup scope.
     *
     * Writes the manifest file in the format expected by TarCreateEngine
     * or ArchiveCreateEngine so their build_manifest() becomes a no-op.
     *
     * @return array Manifest info summary { total, total_size, max_file_size, max_file_name }.
     */
    private function build_backup_manifest() {
        $scope    = anibas_fm_get_backup_scope();
        $base_len = strlen( $this->source ) + 1; // for relative paths

        // Directories to exclude from the backup
        self::$excluded_dirs = array(
            realpath( anibas_fm_get_backup_dir() ),                        // backup dir itself
            realpath( anibas_fm_get_trash_dir() ),                         // trash
        );

        // Also exclude temp upload directory
        $upload_dir = wp_upload_dir();
        $temp_dir   = $upload_dir['basedir'] . '/anibas_fm_temp';
        if ( is_dir( $temp_dir ) ) {
            $real_temp = realpath( $temp_dir );
            if ( $real_temp ) {
                self::$excluded_dirs[] = $real_temp;
            }
        }

        // Filter out false values from realpath failures
        self::$excluded_dirs = array_filter( self::$excluded_dirs );

        $entries       = array();
        $max_file_size = 0;
        $max_file_name = '';

        foreach ( $scope as $path ) {
            if ( is_file( $path ) ) {
                $size = filesize( $path );
                $name = substr( $path, $base_len );

                $entries[] = array(
                    'path'  => $path,
                    'name'  => $name,
                    'size'  => $size,
                    'isdir' => false,
                );

                if ( $size > $max_file_size ) {
                    $max_file_size = $size;
                    $max_file_name = $name;
                }
            } elseif ( is_dir( $path ) ) {
                $this->scan_directory( $path, $base_len, $entries, $max_file_size, $max_file_name );
            }
        }

        $total      = count( $entries );
        $total_size = 0;
        foreach ( $entries as $entry ) {
            $total_size += $entry['size'];
        }

        $manifest_data = array(
            'total'         => $total,
            'total_size'    => $total_size,
            'max_file_size' => $max_file_size,
            'max_file_name' => $max_file_name,
            'entries'       => $entries,
        );

        // Write to the correct manifest file path for the engine
        if ( $this->format === 'anfm' ) {
            $manifest_path = $this->output . '.scan.json';
        } else {
            $manifest_path = $this->output . '.manifest.json';
        }

        $tmp = $manifest_path . '.tmp';
        file_put_contents( $tmp, wp_json_encode( $manifest_data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
        rename( $tmp, $manifest_path );

        return array(
            'total'         => $total,
            'total_size'    => $total_size,
            'max_file_size' => $max_file_size,
            'max_file_name' => $max_file_name,
        );
    }

    /**
     * Recursively scan a directory and append entries to the list.
     *
     * @param string $dir         Absolute directory path.
     * @param int    $base_len    Length of the ABSPATH prefix (for relative naming).
     * @param array  $entries     Reference to entries array.
     * @param int    $max_size    Reference to max file size tracker.
     * @param string $max_name    Reference to max file name tracker.
     */
    private function scan_directory( $dir, $base_len, &$entries, &$max_size, &$max_name ) {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ( $iterator as $item ) {
                $full_path = $item->getPathname();
                $real_path = realpath( $full_path );

                // Check exclusions
                if ( $real_path && $this->is_excluded( $real_path ) ) {
                    continue;
                }

                $relative = substr( $full_path, $base_len );

                if ( $item->isDir() ) {
                    // Check if this directory itself is excluded
                    if ( $real_path && $this->is_excluded_dir( $real_path ) ) {
                        continue;
                    }
                    $entries[] = array(
                        'path'  => $full_path,
                        'name'  => $relative,
                        'size'  => 0,
                        'isdir' => true,
                    );
                } else {
                    $size = $item->getSize();
                    $entries[] = array(
                        'path'  => $full_path,
                        'name'  => $relative,
                        'size'  => $size,
                        'isdir' => false,
                    );

                    if ( $size > $max_size ) {
                        $max_size = $size;
                        $max_name = $relative;
                    }
                }
            }
        } catch ( \Exception $e ) {
            // Skip directories we can't read
        }
    }

    /**
     * Check if a path is inside an excluded directory.
     *
     * @param string $real_path Real path to check.
     * @return bool
     */
    private function is_excluded( $real_path ) {
        foreach ( self::$excluded_dirs as $excluded ) {
            if ( strpos( $real_path, $excluded ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a directory path is an excluded directory.
     *
     * @param string $real_path Real directory path to check.
     * @return bool
     */
    private function is_excluded_dir( $real_path ) {
        $real_path = untrailingslashit( $real_path );
        foreach ( self::$excluded_dirs as $excluded ) {
            if ( $real_path === untrailingslashit( $excluded ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the job ID.
     *
     * @return string
     */
    public function get_job_id() {
        return $this->job_id;
    }

    /**
     * Get the output file path.
     *
     * @return string
     */
    public function get_output() {
        return $this->output;
    }
}
