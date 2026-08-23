<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;


class AssemblyPhase extends OperationPhase
{
    public function execute(&$job, &$work_queue, $manager, &$context)
    {
        $log = ActivityLogger::get_instance();

        // Verify assembly token
        $this->verify_assembly_token($job);

        // Verify all chunks exist on first run
        if ($job['current_chunk'] === 0) {
            $log->log_message(sprintf('[Assembly] Starting "%s" (%d chunks, %s) → storage: %s, dest: %s', $job['file_name'], $job['total_chunks'], size_format($job['file_size'] ?? 0), $job['storage'], $job['destination']));
            $this->verify_all_chunks_exist($job);
            $log->log_message('[Assembly] All chunks verified on disk.');
        }

        $storage = $job['storage'];
        $temp_dir = $job['temp_dir'];
        $total_chunks = $job['total_chunks'];
        $current_chunk = $job['current_chunk'];
        $job['current_phase'] = 'assembly';
        $job['current_file'] = $job['file_name'];
        $job['current_file_size'] = (int) ($job['file_size'] ?? 0);
        $job['current_file_bytes'] = $total_chunks > 0
            ? (int) floor(($current_chunk / $total_chunks) * $job['current_file_size'])
            : 0;

        // Process chunks in batches to avoid timeout
        $chunks_per_batch = 10;
        $end_chunk = min($current_chunk + $chunks_per_batch, $total_chunks);

        $log->log_message(sprintf('[Assembly] Processing chunks %d–%d of %d for "%s"', $current_chunk, $end_chunk - 1, $total_chunks - 1, $job['file_name']));

        if ($storage === 'local') {
            $this->assemble_local_chunks($job, $current_chunk, $end_chunk, $temp_dir);
        } else {
            $this->assemble_remote_chunks($job, $current_chunk, $end_chunk, $temp_dir, $storage);
        }

        // Only update current_chunk after successful processing
        $job['current_chunk'] = $end_chunk;
        $job['current_file_bytes'] = $total_chunks > 0
            ? (int) floor(($end_chunk / $total_chunks) * $job['current_file_size'])
            : $job['current_file_size'];

        if ($end_chunk >= $total_chunks) {
            $log->log_message(sprintf('[Assembly] All chunks assembled locally for "%s". Proceeding to finalize.', $job['file_name']));
        }
    }

    private function verify_all_chunks_exist(&$job)
    {
        $temp_dir = $job['temp_dir'];
        $total_chunks = $job['total_chunks'];
        $missing_chunks = [];

        for ($i = 0; $i < $total_chunks; $i++) {
            $chunk_file = $temp_dir . '/chunk_' . $i;
            if (! file_exists($chunk_file)) {
                $missing_chunks[] = $i;
            }
        }

        if (! empty($missing_chunks)) {
            $count = count($missing_chunks);
            $sample = array_slice($missing_chunks, 0, 10);
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $count is int, $sample is array of ints
            throw new \Exception(
                sprintf(
                    /* translators: 1: number of missing chunks, 2: sample chunk numbers */
                    esc_html__('Upload incomplete: %1$d chunk(s) missing (e.g., chunks %2$s). Please re-upload the file.', 'anibas-file-manager'),
                    $count,
                    esc_html(implode(', ', $sample))
                )
            );
        }
    }

    private function verify_assembly_token(&$job)
    {
        $token_key = ! empty($job['upload_id'])
            ? 'anibas_fm_upload_' . $job['upload_id'] . '_' . $job['user_id'] . '_assembly'
            : 'anibas_fm_upload_' . md5($job['file_name'] . $job['file_size'] . $job['user_id']) . '_assembly';
        $stored_token = get_transient($token_key);

        if (! $stored_token) {
            ActivityLogger::get_instance()->log_message('[Assembly] Token expired or not found for "' . $job['file_name'] . '" (key: ' . $token_key . ')');
            throw new \Exception(esc_html__('Assembly token expired or invalid', 'anibas-file-manager'));
        }

        // Renew token for next batch
        set_transient($token_key, $stored_token, 3600);
    }

    private function assemble_local_chunks(&$job, $start, $end, $temp_dir)
    {
        $final_file = $temp_dir . '/' . $job['file_name'];
        $mode = $start === 0 ? 'wb' : 'ab';

        if (! is_dir($temp_dir)) {
            throw new \Exception(esc_html__('Temp directory not found: ', 'anibas-file-manager') . esc_html($temp_dir));
        }

        $out = fopen($final_file, $mode);
        if (! $out) {
            throw new \Exception(esc_html__('Failed to open output file: ', 'anibas-file-manager') . esc_html($final_file));
        }

        for ($i = $start; $i < $end; $i++) {
            $chunk_file = $temp_dir . '/chunk_' . $i;
            if (! file_exists($chunk_file)) {
                fclose($out);
                throw new \Exception(esc_html__('Chunk ', 'anibas-file-manager') . esc_html($i) . esc_html__(' not found at: ', 'anibas-file-manager') . esc_html($chunk_file));
            }

            $chunk = fopen($chunk_file, 'rb');
            if (! $chunk) {
                fclose($out);
                throw new \Exception(esc_html__('Failed to open chunk ', 'anibas-file-manager') . esc_html($i));
            }

            stream_copy_to_stream($chunk, $out);
            fclose($chunk);
            wp_delete_file($chunk_file);
        }

        fclose($out);
    }

    private function assemble_remote_chunks(&$job, $start, $end, $temp_dir, $storage)
    {
        $this->assemble_local_chunks($job, $start, $end, $temp_dir);
    }

    public function is_complete($work_queue)
    {
        return false; // Handled by job status check
    }

    public function next_phase()
    {
        ActivityLogger::get_instance()->log_message(__CLASS__ . " Complete.");
        return 'finalize';
    }
}
