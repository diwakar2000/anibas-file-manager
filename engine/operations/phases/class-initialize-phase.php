<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;




class InitializePhase extends OperationPhase
{
    public function execute(&$job, &$work_queue, $manager, &$context)
    {
        $fs = $context['fs_adapter'];

        if (! $fs->exists($job['source_root']) && ! $fs->is_dir($job['source_root'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- second arg is an integer constant
            throw new \Exception(esc_html__('Source path does not exist', 'anibas-file-manager'), PhaseExecutor::FATAL_SOURCE_NOT_EXIST);
        }

        if ($fs->is_file($job['source_root'])) {
            $work_queue['is_single_file'] = true;
            return;
        }

        if (empty($work_queue['folders'])) {
            $work_queue['folders'] = [['path' => $job['source_root'], 'page' => 1, 'offset' => 0]];
        }
        $work_queue['is_single_file'] = false;
    }

    public function is_complete($work_queue)
    {
        return isset($work_queue['is_single_file']);
    }

    public function next_phase()
    {
        ActivityLogger::get_instance()->log_message(__CLASS__ . " Complete.");
        return 'list';
    }
}
