<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * AJAX endpoints for archive operations: create (.zip / .tar / .anfm),
 * pre-extract validation check, and resumable restore. Uses the parent's
 * archive job registry helper for resumable jobs.
 */
class ArchiveAjaxHandler extends AjaxHandler
{
    public function __construct()
    {
        parent::__construct();
        $this->register_actions([
            ANIBAS_FM_ARCHIVE_CREATE     => 'archive_create',
            ANIBAS_FM_ARCHIVE_CHECK      => 'archive_check',
            ANIBAS_FM_ARCHIVE_RESTORE    => 'archive_restore',
            ANIBAS_FM_CANCEL_ARCHIVE_JOB => 'cancel_archive_job',
        ]);
    }

    /* =========================================================
       ARCHIVE JOB REGISTRY
       Lightweight persistence for resumable archive operations.
       Stored in the 'anibas_fm_archive_jobs' option as a map of
       job_id => job data. Read helper lives on the parent so the
       transfer handler can surface running jobs to the UI.
    ========================================================= */

    private function register_archive_job(string $source_abs, string $output_abs, string $format): string
    {
        $job_id          = wp_generate_password(12, false);
        $jobs            = $this->get_archive_jobs();
        $jobs[$job_id] = [
            'id'         => $job_id,
            'source_abs' => $source_abs,
            'output_abs' => $output_abs,
            'format'     => $format,
            'started_at' => time(),
        ];
        anibas_fm_update_option('anibas_fm_archive_jobs', $jobs);
        return $job_id;
    }

    private function remove_archive_job(string $job_id)
    {
        $jobs = $this->get_archive_jobs();
        unset($jobs[$job_id]);
        anibas_fm_update_option('anibas_fm_archive_jobs', $jobs);
    }

    private function get_or_create_prescan_job(string $source_path, string $job_id = ''): array
    {
        if (! empty($job_id)) {
            $transient_key = 'anibas_fm_archive_prescan_' . $job_id;
            $job = get_transient($transient_key);
            if (! is_array($job) || empty($job['source_abs']) || $job['source_abs'] !== $source_path || empty($job['manifest_file'])) {
                $this->send_error(array('error' => 'PrescanJobNotFound', 'message' => esc_html__('Folder scan job not found', 'anibas-file-manager')));
            }
            $job['job_id'] = $job_id;
            $job['transient_key'] = $transient_key;
            return $job;
        }

        $job_id = 'prescan_' . wp_generate_password(12, false);
        $state_dir = anibas_fm_get_backup_dir() . '/archive-scan-state';
        if (! is_dir($state_dir)) {
            wp_mkdir_p($state_dir);
            anibas_fm_protect_dir($state_dir);
        }
        if (! is_dir($state_dir)) {
            $this->send_error(array('error' => 'PrescanStateFailed', 'message' => esc_html__('Failed to create folder scan state', 'anibas-file-manager')));
        }

        $manifest_file = $state_dir . '/' . md5(wp_normalize_path($source_path) . '|' . $job_id) . '.json';
        $transient_key = 'anibas_fm_archive_prescan_' . $job_id;
        $job = array(
            'job_id'        => $job_id,
            'source_abs'    => $source_path,
            'manifest_file' => $manifest_file,
            'transient_key' => $transient_key,
        );
        set_transient($transient_key, $job, HOUR_IN_SECONDS);
        return $job;
    }

    public function cancel_archive_job()
    {
        $this->check_create_privilege();

        $job_id = anibas_fm_fetch_request_variable('post', 'job_id', '');
        if (empty($job_id)) {
            $this->send_error(['error' => 'JobIdRequired', 'message' => esc_html__('Job ID required', 'anibas-file-manager')]);
        }

        $jobs = $this->get_archive_jobs();
        if (! isset($jobs[$job_id])) {
            // Already gone — treat as success
            $this->send_success(['message' => esc_html__('Archive job not found (already cleaned up)', 'anibas-file-manager')]);
        }

        $job    = $jobs[$job_id];
        $format = $job['format'];
        $source = $job['source_abs'];
        $output = $job['output_abs'];

        // Best-effort cleanup of engine temp files and partial output
        try {
            if (file_exists($source)) {
                if ($format === 'anfm') {
                    $engine = ArchiveCreateEngine::get_instance($source, $output);
                } elseif ($format === 'tar') {
                    $engine = TarCreateEngine::get_instance($source, $output);
                } else {
                    $engine = ZipCreateEngine::get_instance($source, $output);
                }
                $engine->cleanup(true);
            }
        } catch (\Exception $e) {
            // Proceed even if cleanup fails
        }

        $this->remove_archive_job($job_id);
        $this->send_success(['message' => esc_html__('Archive job cancelled', 'anibas-file-manager')]);
    }

    /* =========================================================
       ARCHIVE CREATE — unified for .zip, .tar and .anfm
    ========================================================= */

    public function archive_create()
    {
        $this->check_create_privilege();

        $source        = anibas_fm_fetch_request_variable('post', 'source', '');
        $format        = anibas_fm_fetch_request_variable('post', 'format', 'zip');
        $password      = anibas_fm_fetch_request_variable('post', 'password', '');
        $phase         = anibas_fm_fetch_request_variable('post', 'phase', 'scan');
        $conflict_mode = anibas_fm_fetch_request_variable('post', 'conflict_mode', ''); // 'overwrite' | 'rename' | ''
        $job_id        = anibas_fm_fetch_request_variable('post', 'job_id', '');
        $storage       = anibas_fm_fetch_request_variable('post', 'storage', 'local');

        if (empty($source)) {
            $this->send_error(array('error' => 'SourceRequired', 'message' => esc_html__('Source path required', 'anibas-file-manager')));
        }
        if (! in_array($format, ['zip', 'tar', 'anfm'], true)) {
            $this->send_error(array('error' => 'InvalidFormat', 'message' => esc_html__('Invalid archive format', 'anibas-file-manager')));
        }

        // Archive engines use native PHP filesystem — only local storage is supported.
        if ($storage !== 'local') {
            $this->send_error(array(
                'error'   => 'RemoteNotSupported',
                'message' => esc_html__('Archive creation is only supported for local storage. Please switch to local storage to archive files.', 'anibas-file-manager'),
            ));
        }

        $source_path = $this->validate_path($source);
        if (! $source_path) {
            $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid source path', 'anibas-file-manager')));
        }

        // ---- PRESCAN — format-independent, no engine files created ----
        if ($phase === 'prescan') {
            if (! is_dir($source_path) && ! is_file($source_path)) {
                $this->send_error(array('error' => 'SourceNotFound', 'message' => esc_html__('Source does not exist', 'anibas-file-manager')));
            }

            // Only sweep orphaned engine temp files when there is no active archive job
            // for this source (active jobs have valid state files we must not destroy).
            $active_jobs          = $this->get_archive_jobs();
            $has_active_job       = false;
            foreach ($active_jobs as $j) {
                if ($j['source_abs'] === $source_path) {
                    $has_active_job = true;
                    break;
                }
            }

            if (! $has_active_job) {
                foreach (['.zip', '.tar', '.anfm'] as $orphan_ext) {
                    $o = $source_path . $orphan_ext;
                    ArchiveManifestStore::cleanup($o . '.manifest.json');
                    ArchiveManifestStore::cleanup($o . '.scan.json');
                    foreach (
                        [
                            $o . '.manifest.json',
                            $o . '.scan.json',
                            $o . '.state.json',
                            $o . '.lock',
                            $o . '.manifest.json.tmp',
                            $o . '.state.json.tmp',
                        ] as $orphan
                    ) {
                        if (file_exists($orphan)) {
                            wp_delete_file($orphan);
                        }
                    }
                }
            }

            // Always sweep libzip temp files regardless of active job status —
            // they are never valid content, only libzip internal temporaries.
            foreach (glob($source_path . '.zip.*.part') ?: [] as $part_file) {
                wp_delete_file($part_file);
            }

            $prescan_job = $this->get_or_create_prescan_job($source_path, $job_id);
            $scan = ArchiveManifestStore::build_step(
                array($source_path),
                is_file($source_path) ? dirname($source_path) : $source_path,
                $prescan_job['manifest_file'],
                false
            );

            if (empty($scan['complete'])) {
                $this->send_success(array(
                    'phase'         => 'prescan_running',
                    'job_id'        => $prescan_job['job_id'],
                    'total'         => $scan['total'],
                    'total_size'    => $scan['total_size'],
                    'max_file_size' => $scan['max_file_size'],
                    'max_file_name' => $scan['max_file_name'],
                ));
            }

            delete_transient($prescan_job['transient_key']);
            ArchiveManifestStore::cleanup($prescan_job['manifest_file']);
            $this->send_success(array(
                'phase'         => 'prescan_complete',
                'total'         => $scan['total'],
                'total_size'    => $scan['total_size'],
                'max_file_size' => $scan['max_file_size'],
                'max_file_name' => $scan['max_file_name'],
            ));
        }

        // ---- Compute output path (may be adjusted for rename conflict mode) ----
        $ext_map = ['anfm' => '.anfm', 'tar' => '.tar', 'zip' => '.zip'];
        $ext     = $ext_map[$format] ?? '.zip';
        $output  = $source_path . $ext;

        try {
            // ---- SCAN — conflict check, optional rename/overwrite, manifest build ----
            if ($phase === 'scan') {

                $new_job_id = $job_id;
                if (! empty($job_id)) {
                    $jobs = $this->get_archive_jobs();
                    if (! isset($jobs[$job_id])) {
                        $this->send_error(array('error' => 'JobNotFound', 'message' => esc_html__('Archive job not found', 'anibas-file-manager')));
                    }
                    $job = $jobs[$job_id];
                    if ($job['source_abs'] !== $source_path) {
                        $this->send_error(array('error' => 'JobSourceMismatch', 'message' => esc_html__('Archive job does not match this source', 'anibas-file-manager')));
                    }
                    $output = $job['output_abs'];
                    $format = $job['format'];
                } else {
                    // If output already exists and no conflict resolution was chosen, report conflict.
                    if (file_exists($output) && empty($conflict_mode)) {
                        $this->send_success(array(
                            'phase'       => 'conflict',
                            'output'      => basename($output),
                            'output_size' => filesize($output),
                        ));
                    }

                    // Handle chosen conflict resolution mode.
                    if ($conflict_mode === 'overwrite') {
                        wp_delete_file($output);
                    } elseif ($conflict_mode === 'rename') {
                        $base = substr($output, 0, -strlen($ext));
                        $i    = 1;
                        while (file_exists("{$base} ({$i}){$ext}")) {
                            $i++;
                        }
                        $output = "{$base} ({$i}){$ext}";
                    }

                    // Register this as an active archive job so resume is possible.
                    $new_job_id = $this->register_archive_job($source_path, $output, $format);
                }

                if ($format === 'anfm') {
                    $engine = ArchiveCreateEngine::get_instance($source_path, $output);
                } elseif ($format === 'tar') {
                    $engine = TarCreateEngine::get_instance($source_path, $output);
                } else {
                    $engine = ZipCreateEngine::get_instance($source_path, $output);
                }

                $scan = $engine->build_manifest_step();
                if (empty($scan['complete'])) {
                    $this->send_success(array(
                        'phase'  => 'scan_running',
                        'info'   => array(
                            'total'         => $scan['total'],
                            'total_size'    => $scan['total_size'],
                            'max_file_size' => $scan['max_file_size'],
                            'max_file_name' => $scan['max_file_name'],
                        ),
                        'format' => $format,
                        'job_id' => $new_job_id,
                        'output' => basename($output),
                    ));
                }

                $this->send_success(array(
                    'phase'  => 'scan_complete',
                    'info'   => $engine->get_manifest_info(),
                    'format' => $format,
                    'job_id' => $new_job_id,
                    'output' => basename($output),
                ));
            }

            // ---- RUN / CLEANUP — engine needs consistent output path from job registry ----
            // If a job_id was supplied, resolve the output path from the registry so rename
            // mode continues writing to the correct (renamed) file across requests.
            if (! empty($job_id)) {
                $jobs = $this->get_archive_jobs();
                if (isset($jobs[$job_id])) {
                    $output = $jobs[$job_id]['output_abs'];
                }
            }

            if ($format === 'anfm') {
                $engine = ArchiveCreateEngine::get_instance($source_path, $output);
            } elseif ($format === 'tar') {
                $engine = TarCreateEngine::get_instance($source_path, $output);
            } else {
                $engine = ZipCreateEngine::get_instance($source_path, $output);
            }

            if ($phase === 'run') {
                $pwd  = ($format === 'anfm' && ! empty($password)) ? $password : null;
                $more = ($format === 'anfm') ? $engine->run_step($pwd) : $engine->run_step();
                $prog = $engine->progress();

                if (! $more) {
                    $engine->cleanup();
                    if (! empty($job_id)) {
                        $this->remove_archive_job($job_id);
                    }
                    $this->send_success(array('phase' => 'complete', 'progress' => $prog, 'output' => basename($output)));
                }
                $this->send_success(array('phase' => 'running', 'progress' => $prog));
            }

            if ($phase === 'cleanup') {
                $engine->cleanup(true);
                if (! empty($job_id)) {
                    $this->remove_archive_job($job_id);
                }
                $this->send_success(array('message' => esc_html__('Cleaned up', 'anibas-file-manager')));
            }
        } catch (\Exception $e) {
            $this->send_error(array('error' => esc_html($e->getMessage())));
        }
    }

    /* =========================================================
       ARCHIVE CHECK — pre-extract validation for .zip, .tar and .anfm
    ========================================================= */

    public function archive_check()
    {
        $this->check_privilege();

        $path    = anibas_fm_fetch_request_variable('post', 'path', '');
        $storage = anibas_fm_fetch_request_variable('post', 'storage', 'local');

        if (empty($path)) {
            $this->send_error(array('error' => 'PathRequired', 'message' => esc_html__('Path required', 'anibas-file-manager')));
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($ext, ['zip', 'tar', 'anfm'], true)) {
            $this->send_error(array('error' => 'UnsupportedFormat', 'message' => esc_html__('Not a supported archive format', 'anibas-file-manager')));
        }

        if ($storage !== 'local') {
            $this->send_error(array(
                'error'   => 'RemoteNotSupported',
                'message' => esc_html__('Archive inspection is only supported for local storage.', 'anibas-file-manager'),
            ));
        }

        $full_path = $this->validate_path($path);
        if (! $full_path || ! is_file($full_path)) {
            $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid archive path', 'anibas-file-manager')));
        }
        $filesize = filesize($full_path);
        $temp_path = null;

        try {
            if ($ext === 'anfm') {
                $dest   = dirname($full_path);
                $engine = ArchiveRestoreEngine::get_instance($full_path, $dest);
                $header = $engine->read_header();
                $this->send_success(array(
                    'valid'              => true,
                    'format'             => 'anfm',
                    'password_protected' => $header['password_protected'],
                    'version'            => $header['version'],
                    'filesize'           => $filesize,
                ));
            } elseif ($ext === 'tar') {
                $fh          = fopen($full_path, 'rb');
                $first_block = fread($fh, 512);
                fclose($fh);
                if (strlen($first_block) < 512 || trim($first_block, "\0") === '') {
                    $this->send_success(array(
                        'valid'  => false,
                        'format' => 'tar',
                        'reason' => esc_html__('Cannot read tar file — it may be empty or corrupted.', 'anibas-file-manager'),
                    ));
                }
                $this->send_success(array(
                    'valid'              => true,
                    'format'             => 'tar',
                    'password_protected' => false,
                    'filesize'           => $filesize,
                ));
            } else {
                $zip = new \ZipArchive();
                $res = $zip->open($full_path, \ZipArchive::RDONLY);
                if ($res !== true) {
                    $this->send_success(array(
                        'valid'  => false,
                        'format' => 'zip',
                        'reason' => esc_html__('Cannot open zip file — it may be corrupted or not a valid zip archive.', 'anibas-file-manager'),
                    ));
                }
                $count = $zip->numFiles;
                $zip->close();
                $this->send_success(array(
                    'valid'              => true,
                    'format'             => 'zip',
                    'password_protected' => false,
                    'total_files'        => $count,
                    'filesize'           => $filesize,
                ));
            }
        } catch (\Exception $e) {
            $this->send_success(array(
                'valid'  => false,
                'format' => $ext,
                'reason' => esc_html($e->getMessage()),
            ));
        } finally {
            if ($temp_path && file_exists($temp_path)) {
                wp_delete_file($temp_path);
            }
        }
    }

    /* =========================================================
       ARCHIVE RESTORE — unified extraction for .zip, .tar and .anfm
    ========================================================= */

    public function archive_restore()
    {
        $this->check_create_privilege();

        $path     = anibas_fm_fetch_request_variable('post', 'path', '');
        $password = anibas_fm_fetch_request_variable('post', 'password', '');
        $phase    = anibas_fm_fetch_request_variable('post', 'phase', 'init');
        $storage  = anibas_fm_fetch_request_variable('post', 'storage', 'local');

        if (empty($path)) {
            $this->send_error(array('error' => 'PathRequired', 'message' => esc_html__('Path required', 'anibas-file-manager')));
        }

        // Archive engines use native PHP filesystem — only local storage is supported.
        if ($storage !== 'local') {
            $this->send_error(array(
                'error'   => 'RemoteNotSupported',
                'message' => esc_html__('Archive extraction is only supported for local storage. Please switch to local storage to extract files.', 'anibas-file-manager'),
            ));
        }

        $full_path = $this->validate_path($path);
        if (! $full_path || ! is_file($full_path)) {
            $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid archive path', 'anibas-file-manager')));
        }

        $ext  = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
        $dest = dirname($full_path);

        try {
            if ($ext === 'anfm') {
                $this->restore_anfm($full_path, $dest, $password, $phase);
            } elseif ($ext === 'tar') {
                $this->restore_tar($full_path, $dest, $phase);
            } elseif ($ext === 'zip') {
                $this->restore_zip($full_path, $dest, $phase);
            } else {
                $this->send_error(array('error' => esc_html__('Unsupported archive format', 'anibas-file-manager')));
            }
        } catch (\Exception $e) {
            $this->send_error(array('error' => esc_html($e->getMessage())));
        }
    }

    private function restore_anfm(string $archive, string $dest, string $password, string $phase)
    {
        $engine = ArchiveRestoreEngine::get_instance($archive, $dest);
        $pwd    = ! empty($password) ? $password : null;

        if ($phase === 'init') {
            $info = $engine->load_archive_manifest($pwd);
            $this->send_success(array('phase' => 'ready', 'info' => $info));
        }

        if ($phase === 'run') {
            $more = $engine->run_step($pwd);
            $prog = $engine->progress();
            if (! $more) {
                $engine->cleanup();
                $this->send_success(array('phase' => 'complete', 'progress' => $prog));
            }
            $this->send_success(array('phase' => 'running', 'progress' => $prog));
        }

        if ($phase === 'cleanup') {
            $engine->cleanup();
            $this->send_success(array('message' => esc_html__('Cleaned up', 'anibas-file-manager')));
        }
    }

    private function restore_tar(string $archive, string $dest, string $phase)
    {
        $engine = TarRestoreEngine::get_instance($archive, $dest);

        if ($phase === 'init') {
            $engine->build_manifest();
            $this->send_success(array('phase' => 'ready', 'info' => array('total' => $engine->progress()['total'])));
        }

        if ($phase === 'run') {
            $more = $engine->run_step();
            $prog = $engine->progress();
            if (! $more) {
                $engine->cleanup();
                $this->send_success(array('phase' => 'complete', 'progress' => $prog));
            }
            $this->send_success(array('phase' => 'running', 'progress' => $prog));
        }

        if ($phase === 'cleanup') {
            $engine->cleanup();
            $this->send_success(array('message' => esc_html__('Cleaned up', 'anibas-file-manager')));
        }
    }

    private function restore_zip(string $archive, string $dest, string $phase)
    {
        $engine = ZipRestoreEngine::get_instance($archive, $dest);

        if ($phase === 'init') {
            $engine->build_manifest();
            $this->send_success(array('phase' => 'ready', 'info' => array('total' => $engine->progress()['total'])));
        }

        if ($phase === 'run') {
            $more = $engine->run_step();
            $prog = $engine->progress();
            if (! $more) {
                $engine->cleanup();
                $this->send_success(array('phase' => 'complete', 'progress' => $prog));
            }
            $this->send_success(array('phase' => 'running', 'progress' => $prog));
        }

        if ($phase === 'cleanup') {
            $engine->cleanup();
            $this->send_success(array('message' => esc_html__('Cleaned up', 'anibas-file-manager')));
        }
    }
}
