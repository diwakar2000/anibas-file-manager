<?php
/**
 * Site Backup Engine — coordinator for full-site backups.
 *
 * This is NOT a new archive format. It exports the database into a temporary
 * hidden payload directory, builds a custom file manifest from the backup
 * scope (wp-content + selected root files + payload), and delegates to
 * ArchiveCreateEngine for encrypted ANFM archiving.
 *
 * The engine pre-writes the manifest file so the delegate engine's
 * build_manifest() is a no-op, then lets run_step() process files normally.
 *
 * Usage (from AJAX):
 *   $result = BackupEngine::start();                // returns ANFM job info
 *   $engine = BackupEngine::resume( $job_id );       // resume from state
 *   $more   = $engine->run_step();                   // time-budgeted
 *   $prog   = $engine->progress();                   // for polling
 *   $engine->cancel();                               // cleanup + unlock
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Coordinates full-site backups by exporting the database into a hidden
 * payload directory, building a manifest from the configured backup scope,
 * and delegating archiving to ArchiveCreateEngine.
 */
class BackupEngine {

    private $format;
    private $output;
    private $source;
    private $engine;
    private $job_id;
    private $password;
    private $site_payload_dir;
    private $db_state_dir;
    private array $last_progress = array();

    /**
     * Start a new backup.
     *
     * @param string      $format   Kept for old callers; site backups are always ANFM.
     * @param string|null $password Encryption password (only for anfm).
     * @return array { job_id, output, info }
     */
    public static function start( $format = 'tar', $password = null ) {
        if ( anibas_fm_is_backup_running() ) {
            throw new \Exception( esc_html__( 'A backup is already in progress.', 'anibas-file-manager' ) );
        }
        if ( class_exists( SiteRestoreEngine::class ) && SiteRestoreEngine::is_running() ) {
            throw new \Exception( esc_html__( 'A site restore is already in progress.', 'anibas-file-manager' ) );
        }

        $format = 'anfm';

        $backup_dir = anibas_fm_get_backup_dir();
        $preflight = function_exists( 'anibas_fm_runtime_preflight' )
            ? anibas_fm_runtime_preflight( $backup_dir, 0, 32 * 1024 * 1024, true )
            : array( 'ok' => true, 'errors' => array(), 'warnings' => array() );
        if ( empty( $preflight['ok'] ) ) {
            $errors = is_array( $preflight['errors'] ?? null ) ? $preflight['errors'] : array();
            $message = implode( ' ', $errors );
            throw new \RuntimeException( esc_html( $message ) );
        }

        $timestamp  = gmdate( 'Y-m-d_His' );
        $ext        = '.anfm';
        $filename   = 'backup-' . $timestamp . $ext;
        $output     = $backup_dir . '/' . $filename;

        $source  = untrailingslashit( realpath( ABSPATH ) );
        $job_id  = 'backup_' . wp_generate_password( 12, false );

        $site_payload_dir = anibas_fm_get_site_backup_payload_dir( $job_id );
        $db_state_dir     = $site_payload_dir . '/database';

        $instance = new self();
        $instance->format           = $format;
        $instance->output           = $output;
        $instance->source           = $source;
        $instance->job_id           = $job_id;
        $instance->password         = $password;
        $instance->site_payload_dir = $site_payload_dir;
        $instance->db_state_dir     = $db_state_dir;

        // Persist backup job state
        $state = array(
            'job_id'            => $job_id,
            'format'            => $format,
            'output'            => $output,
            'source'            => $source,
            'password'          => ! empty( $password ) ? '1' : '0',
            'phase'             => 'database',
            'site_payload_dir'  => $site_payload_dir,
            'db_state_dir'      => $db_state_dir,
            'db_scope'          => 'all',
            'db_progress'       => array(),
            'manifest_complete' => false,
            'manifest_info'     => array( 'total' => 0, 'total_size' => 0, 'max_file_size' => 0, 'max_file_name' => '' ),
            'preflight'         => $preflight,
            'archive_capacity_checked' => false,
        );
        set_transient( 'anibas_fm_backup_job_' . $job_id, $state, 2 * HOUR_IN_SECONDS );

        // Set the backup lock before scanning so a second backup cannot start
        // while the first one is still building its manifest.
        anibas_fm_set_backup_lock( $job_id, $format, $filename );

        try {
            $db_engine = new DatabaseBackupEngine( $job_id, $db_state_dir );
            $progress  = $db_engine->initialize( 'all' );
            $state['db_progress'] = $progress;
            $instance->save_job_state( $state );
        } catch ( \Exception $e ) {
            anibas_fm_clear_backup_lock();
            delete_transient( 'anibas_fm_backup_job_' . $job_id );
            $instance->delete_tree( $site_payload_dir );
            throw $e;
        }

        return array(
            'job_id' => $job_id,
            'output' => $filename,
            'info'   => $instance->progress(),
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

        if ( ( $state['format'] ?? '' ) !== 'anfm' ) {
            throw new \Exception( esc_html__( 'Legacy TAR site backup jobs are no longer supported. Cancel and start a new ANFM backup.', 'anibas-file-manager' ) );
        }

        $instance           = new self();
        $instance->job_id           = $job_id;
        $instance->format           = 'anfm';
        $instance->output           = $state['output'];
        $instance->source           = $state['source'];
        $instance->password         = $password;
        $instance->site_payload_dir = (string) ( $state['site_payload_dir'] ?? anibas_fm_get_site_backup_payload_dir( $job_id ) );
        $instance->db_state_dir     = (string) ( $state['db_state_dir'] ?? $instance->site_payload_dir . '/database' );

        if ( ! empty( $state['manifest_complete'] ) ) {
            $instance->engine = ArchiveCreateEngine::get_instance( $state['source'], $state['output'] );
        }

        return $instance;
    }

    /**
     * Run one time-budgeted step of the backup.
     *
     * @return bool true if more work remains, false if complete.
     */
    public function run_step() {
        $state = $this->load_job_state();
        $phase = (string) ( $state['phase'] ?? 'database' );

        if ( $phase === 'database' ) {
            $progress = $this->run_database_backup_step( $state );
            if ( empty( $progress['complete'] ) ) {
                return true;
            }
            $this->finalize_database_payload( $state );
            $state['phase'] = 'manifest';
            $state['db_progress'] = $progress;
            $this->save_job_state( $state );
        }

        if ( ! $this->manifest_is_complete() ) {
            $scan = $this->build_backup_manifest_step();
            if ( empty( $scan['complete'] ) ) {
                return true;
            }
            $state = $this->load_job_state();
            $this->ensure_archive_capacity( $state );
            $state['phase'] = 'archive';
            $this->save_job_state( $state );
        }

        if ( ! $this->engine ) {
            $this->engine = ArchiveCreateEngine::get_instance( $this->source, $this->output );
        }

        $pwd  = ! empty( $this->password ) ? $this->password : null;
        $more = $this->engine->run_step( $pwd );

        if ( ! $more ) {
            $this->last_progress = $this->archive_progress_from_state( $this->load_job_state() );
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
        $state = $this->load_job_state();
        if ( empty( $state ) && ! empty( $this->last_progress ) ) {
            return $this->last_progress;
        }
        $phase = (string) ( $state['phase'] ?? 'database' );

        if ( $phase === 'database' ) {
            $progress = is_array( $state['db_progress'] ?? null ) ? $state['db_progress'] : array();
            $rows_total = (int) ( $progress['rows_estimate'] ?? 0 );
            $rows_done  = (int) ( $progress['rows_exported'] ?? 0 );
            return array(
                'current'         => $rows_done,
                'total'           => $rows_total,
                'percent'         => isset( $progress['percent'] ) ? (float) $progress['percent'] : 0,
                'bytes_processed' => 0,
                'total_size'      => 0,
                'phase'           => 'database',
                'backup_stage'    => 'database',
                'database_phase'  => (string) ( $progress['phase'] ?? 'export' ),
                'database'        => $progress,
                'preflight'       => is_array( $state['preflight'] ?? null ) ? $state['preflight'] : array(),
            );
        }

        if ( ! $this->manifest_is_complete() ) {
            $info  = is_array( $state['manifest_info'] ?? null ) ? $state['manifest_info'] : array();
            $total = (int) ( $info['total'] ?? 0 );
            return array(
                'current'         => $total,
                'total'           => $total,
                'percent'         => 0,
                'bytes_processed' => 0,
                'total_size'      => (int) ( $info['total_size'] ?? 0 ),
                'phase'           => 'manifest',
                'backup_stage'    => 'manifest',
                'preflight'       => is_array( $state['preflight'] ?? null ) ? $state['preflight'] : array(),
            );
        }

        if ( ! $this->engine ) {
            $this->engine = ArchiveCreateEngine::get_instance( $this->source, $this->output );
        }

        return $this->archive_progress_from_state( $state );
    }

    private function archive_progress_from_state( array $state ): array {
        if ( ! $this->engine ) {
            $this->engine = ArchiveCreateEngine::get_instance( $this->source, $this->output );
        }

        $progress = $this->engine->progress();
        $progress['backup_stage'] = 'archive';
        $progress['archive_phase'] = (string) ( $progress['phase'] ?? 'data' );
        $progress['preflight'] = is_array( $state['preflight'] ?? null ) ? $state['preflight'] : array();
        return $progress;
    }

    /**
     * Cancel the backup — cleanup temp files + unlock.
     */
    public function cancel() {
        if ( ! $this->engine ) {
            try {
                $this->engine = ArchiveCreateEngine::get_instance( $this->source, $this->output );
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

        if ( is_string( $this->site_payload_dir ) && $this->site_payload_dir !== '' ) {
            $this->delete_tree( $this->site_payload_dir );
        }

        $this->finish();
    }

    /**
     * Clear the lock and the job transient.
     */
    private function finish() {
        if ( is_string( $this->site_payload_dir ) && $this->site_payload_dir !== '' ) {
            $this->delete_tree( $this->site_payload_dir );
        }
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
        $manifest_path = $this->output . '.scan.json';
        $scope = anibas_fm_get_backup_scope();
        if ( is_string( $this->site_payload_dir ) && is_dir( $this->site_payload_dir ) ) {
            $scope[] = $this->site_payload_dir;
        }

        $scan = ArchiveManifestStore::build_step(
            $scope,
            $this->source,
            $manifest_path,
            false,
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

    private function run_database_backup_step( array &$state ): array {
        $db_state_dir = (string) ( $state['db_state_dir'] ?? $this->db_state_dir );
        if ( $db_state_dir === '' ) {
            throw new \RuntimeException( esc_html__( 'Database backup state directory is missing.', 'anibas-file-manager' ) );
        }

        $engine = new DatabaseBackupEngine( $this->job_id, $db_state_dir );
        $progress = $engine->run_step();
        $state['db_progress'] = $progress;
        $this->save_job_state( $state );

        return $progress;
    }

    private function finalize_database_payload( array $state ): void {
        $db_state_dir = (string) ( $state['db_state_dir'] ?? $this->db_state_dir );
        $payload_dir  = (string) ( $state['site_payload_dir'] ?? $this->site_payload_dir );
        if ( $db_state_dir === '' || $payload_dir === '' ) {
            throw new \RuntimeException( esc_html__( 'Backup payload is missing.', 'anibas-file-manager' ) );
        }

        $db_engine = new DatabaseBackupEngine( $this->job_id, $db_state_dir );
        $db_engine->cleanup( false );

        $manifest_path = $db_state_dir . '/manifest.json';
        if ( ! is_file( $manifest_path ) ) {
            throw new \RuntimeException( esc_html__( 'Database backup manifest was not created.', 'anibas-file-manager' ) );
        }

        $site_manifest = array(
            'format'            => 'anibas-site-backup',
            'format_version'    => 1,
            'created_at'        => time(),
            'site_url'          => site_url(),
            'home_url'          => home_url(),
            'wordpress_version' => get_bloginfo( 'version' ),
            'plugin_version'    => defined( 'ANIBAS_FILE_MANAGER_VERSION' ) ? ANIBAS_FILE_MANAGER_VERSION : '',
            'database_manifest' => basename( $db_state_dir ) . '/manifest.json',
            'database_dir'      => basename( $db_state_dir ),
            'files_root'        => '.',
        );

        $json = wp_json_encode( $site_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || @file_put_contents( $payload_dir . '/site-manifest.json', $json ) === false ) {
            throw new \RuntimeException( esc_html__( 'Failed to write site backup manifest.', 'anibas-file-manager' ) );
        }
    }

    private function ensure_archive_capacity( array &$state ): void {
        if ( ! empty( $state['archive_capacity_checked'] ) ) {
            return;
        }

        $info = is_array( $state['manifest_info'] ?? null ) ? $state['manifest_info'] : array();
        $total_size = (int) ( $info['total_size'] ?? 0 );
        $total_files = (int) ( $info['total'] ?? 0 );
        $manifest_allowance = max( 128 * 1024 * 1024, $total_files * 1024 );
        $required = $total_size + $manifest_allowance;

        $preflight = function_exists( 'anibas_fm_runtime_preflight' )
            ? anibas_fm_runtime_preflight( dirname( $this->output ), $required, 32 * 1024 * 1024, true )
            : array( 'ok' => true, 'errors' => array(), 'warnings' => array() );

        if ( empty( $preflight['ok'] ) ) {
            $errors = is_array( $preflight['errors'] ?? null ) ? $preflight['errors'] : array();
            throw new \RuntimeException( esc_html( implode( ' ', $errors ) ) );
        }

        $state['archive_capacity_checked'] = true;
        $state['archive_capacity_preflight'] = $preflight;
        if ( ! isset( $state['preflight'] ) || ! is_array( $state['preflight'] ) ) {
            $state['preflight'] = array( 'warnings' => array() );
        }
        if ( ! empty( $preflight['warnings'] ) && is_array( $preflight['warnings'] ) ) {
            $warnings = is_array( $state['preflight']['warnings'] ?? null ) ? $state['preflight']['warnings'] : array();
            $state['preflight']['warnings'] = array_values( array_unique( array_merge( $warnings, $preflight['warnings'] ) ) );
        }
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

    private function delete_tree( string $dir ): void {
        if ( $dir === '' || ! is_dir( $dir ) ) {
            return;
        }

        $root = untrailingslashit( wp_normalize_path( $dir ) );
        if ( strpos( basename( $root ), '.anibas-site-backup-' ) !== 0 ) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            if ( ! $item instanceof \SplFileInfo ) {
                continue;
            }
            if ( $item->isDir() && ! $item->isLink() ) {
                @rmdir( $item->getPathname() );
            } else {
                @unlink( $item->getPathname() );
            }
        }
        @rmdir( $root );
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
