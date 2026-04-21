<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;


class PhaseExecutor
{
    // Fatal exception codes — used to detect job-stopping errors without string matching
    const FATAL_SOURCE_NOT_EXIST    = 100;
    const FATAL_INVALID_PHASE       = 101;
    const FATAL_DEST_INSIDE_SOURCE  = 102;

    private $phases = [];
    private $time_limit = 10;
    private $fs_adapter;
    private $extra_context = [];

    /**
     * @param FileSystemAdapter|null $fs_adapter      Primary adapter (source for cross-storage).
     * @param FileSystemAdapter|null $dest_adapter     Destination adapter (only for cross-storage).
     * @param string                 $mode             'transfer' (default) or 'delete'.
     */
    public function __construct(?FileSystemAdapter $fs_adapter = null, ?FileSystemAdapter $dest_adapter = null, string $mode = 'transfer')
    {
        $this->fs_adapter = $fs_adapter ?? new LocalFileSystemAdapter();

        if ($mode === 'delete') {
            $this->phases = [
                'initialize' => new InitializePhase(),
                'list'       => new ListPhase(),
                'delete'     => new DeletePhase(),
            ];
        } elseif ($dest_adapter) {
            // Cross-storage mode: swap transfer phase, pass both adapters
            $this->phases = [
                'initialize' => new InitializePhase(),
                'list' => new ListPhase(),
                'transfer' => new CrossStorageTransferPhase(),
                'wrapup' => new WrapupPhase(),
            ];
            $this->extra_context = [
                'source_adapter' => $this->fs_adapter,
                'dest_adapter'   => $dest_adapter,
            ];
        } else {
            $this->phases = [
                'initialize' => new InitializePhase(),
                'list' => new ListPhase(),
                'transfer' => new TransferPhase(),
                'wrapup' => new WrapupPhase(),
            ];
        }
    }

    public function execute_with_time_limit(&$job, &$work_queue, $manager)
    {
        $context = array_merge([
            'start_time' => microtime(true),
            'time_limit' => $this->time_limit,
            'fs_adapter' => $this->fs_adapter,
        ], $this->extra_context);

        if (! isset($work_queue['current_phase'])) {
            $work_queue['current_phase'] = 'initialize';
        }

        $phase_name = $work_queue['current_phase'];

        if (! isset($this->phases[$phase_name])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- second arg is an integer constant
            throw new \Exception(esc_html__('Invalid phase: ', 'anibas-file-manager') . esc_html($phase_name), self::FATAL_INVALID_PHASE);
        }

        $phase = $this->phases[$phase_name];

        try {
            $phase->execute($job, $work_queue, $manager, $context);
        } catch (\Exception $e) {
            $error_msg = esc_html__('Phase ', 'anibas-file-manager') . esc_html($phase_name) . ': ' . esc_html($e->getMessage());

            // Fatal errors that should stop the job — detected by exception code
            if (in_array($e->getCode(), [self::FATAL_SOURCE_NOT_EXIST, self::FATAL_DEST_INSIDE_SOURCE], true)) {
                $job['errors'][] = $error_msg;
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $error_msg is built from esc_html__ + esc_html
                throw new \Exception($error_msg);
            }

            $job['errors'][] = $error_msg;
            return false;
        }

        if ($phase->is_complete($work_queue)) {
            $next = $phase->next_phase();
            if (! $next) {
                return true;
            }

            // When transitioning from list phase, snapshot the total file count
            if ($phase_name === 'list' && ! isset($job['total_files'])) {
                $file_count   = count($work_queue['files_to_process'] ?? []);
                $folder_count = ($job['action'] ?? '') === 'delete' ? count($work_queue['folders_to_process'] ?? []) : 0;
                $job['total_files'] = $file_count + $folder_count;
            }

            $work_queue['current_phase'] = $next;
            return false;
        }

        return false;
    }
}
