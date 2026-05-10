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

class BackupEngine {

    private $format;
    private $output;
    private $source;
    private $engine;
    private $job_id;
    private $password;

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

        // Persist backup job state
        $state = array(
            'job_id'            => $job_id,
            'format'            => $format,
            'output'            => $output,
            'source'            => $source,
            'password'          => ! empty( $password ) ? '1' : '0',
            'manifest_complete' => false,
            'manifest_info'     => array( 'total' => 0, 'total_size' => 0, 'max_file_size' => 0, 'max_file_name' => '' ),
        );
        set_transient( 'anibas_fm_backup_job_' . $job_id, $state, 2 * HOUR_IN_SECONDS );

        // Set the backup lock before scanning so a second backup cannot start
        // while the first one is still building its manifest.
        anibas_fm_set_backup_lock( $job_id, $format, $filename );

        try {
            $manifest_info = $instance->build_backup_manifest_step();
        } catch ( \Exception $e ) {
            anibas_fm_clear_backup_lock();
            delete_transient( 'anibas_fm_backup_job_' . $job_id );
            throw $e;
        }

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

        if ( ! empty( $state['manifest_complete'] ) ) {
            if ( $state['format'] === 'anfm' ) {
                $instance->engine = ArchiveCreateEngine::get_instance( $state['source'], $state['output'] );
            } else {
                $instance->engine = TarCreateEngine::get_instance( $state['source'], $state['output'] );
            }
        }

        return $instance;
    }

    /**
     * Run one time-budgeted step of the backup.
     *
     * @return bool true if more work remains, false if complete.
     */
    public function run_step() {
        if ( ! $this->manifest_is_complete() ) {
            $scan = $this->build_backup_manifest_step();
            if ( empty( $scan['complete'] ) ) {
                return true;
            }
        }

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
        if ( ! $this->manifest_is_complete() ) {
            $state = $this->load_job_state();
            $info  = is_array( $state['manifest_info'] ?? null ) ? $state['manifest_info'] : array();
            $total = (int) ( $info['total'] ?? 0 );
            return array(
                'current'         => $total,
                'total'           => $total,
                'percent'         => 0,
                'bytes_processed' => 0,
                'total_size'      => (int) ( $info['total_size'] ?? 0 ),
                'phase'           => 'manifest',
            );
        }

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

    private function load_job_state(): array {
        $state = get_transient( 'anibas_fm_backup_job_' . $this->job_id );
        return is_array( $state ) ? $state : array();
    }

    private function save_job_state( array $state ): void {
        set_transient( 'anibas_fm_backup_job_' . $this->job_id, $state, 2 * HOUR_IN_SECONDS );
    }

    private function manifest_is_complete(): bool {
        $state = $this->load_job_state();
        return ! empty( $state['manifest_complete'] );
    }

    /**
     * Build a custom manifest covering the backup scope.
     *
     * Writes the manifest file in the format expected by TarCreateEngine
     * or ArchiveCreateEngine so their build_manifest() becomes a no-op.
     *
     * @return array Manifest info summary { total, total_size, max_file_size, max_file_name }.
     */
    private function build_backup_manifest_step() {
        $manifest_path = $this->format === 'anfm'
            ? $this->output . '.scan.json'
            : $this->output . '.manifest.json';

        $scan = ArchiveManifestStore::build_step(
            anibas_fm_get_backup_scope(),
            $this->source,
            $manifest_path,
            $this->format !== 'anfm',
            $this->backup_excluded_dirs()
        );

        $info = array(
            'total'         => (int) $scan['total'],
            'total_size'    => (int) $scan['total_size'],
            'max_file_size' => (int) $scan['max_file_size'],
            'max_file_name' => (string) $scan['max_file_name'],
        );

        $state = $this->load_job_state();
        $state['manifest_complete'] = ! empty( $scan['complete'] );
        $state['manifest_info'] = $info;
        $this->save_job_state( $state );

        $info['complete'] = ! empty( $scan['complete'] );
        return $info;
    }

    private function backup_excluded_dirs(): array {
        $excluded = array(
            anibas_fm_get_backup_dir(),
            anibas_fm_get_trash_dir(),
        );

        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['basedir'] ) ) {
            $excluded[] = $upload_dir['basedir'] . '/anibas_fm_temp';
        }

        return $excluded;
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
