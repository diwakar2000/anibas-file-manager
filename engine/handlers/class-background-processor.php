<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Simplified background processor - processes files as discovered
 */
class BackgroundProcessor {
    
    private static $option_name = 'anibas_fm_job_queue_v2';
    private static $lock_key = 'anibas_fm_worker_lock';
    private static $shutdown_active = false;

    public static function init() {
        // Initialization handled by Anibas_File_Manager_Main instantiating the WorkerAjaxHandler
        ActivityLogger::log_message('[BackgroundProcessor] init() called.');
    }

    public static function enqueue_job( $source, $destination, $action, $conflict_mode = 'skip', $storage = 'local', $dest_is_final = false ) {
        ActivityLogger::log_message('[BackgroundProcessor] enqueue_job called: action=' . $action . ', source=' . $source);
        if ( ! in_array( $action, [ 'copy', 'move' ], true ) ) {
            ActivityLogger::log_message('[BackgroundProcessor] enqueue_job failed: Invalid action ' . $action);
            return new \WP_Error( 'invalid_action', sprintf( 'Invalid action "%s" for background job', $action ) );
        }

        // Get appropriate adapter for storage type
        $original_destination = $destination;
        if ( $storage === 'local' ) {
            if ( ! $dest_is_final && is_dir( $source ) ) {
                $destination = $destination . DIRECTORY_SEPARATOR . basename( $source );
            }

            // Prevent moving/copying to same location
            if ( realpath( $source ) === realpath( $destination ) ) {
                return new \WP_Error( 'same_location', __( 'Source and destination are the same location.', 'anibas-file-manager' ) );
            }
        } else {
            try {
                $storage_manager = \Anibas\StorageManager::get_instance();
                $adapter = $storage_manager->get_adapter( $storage );
                if ( ! $adapter ) {
                    return new \WP_Error( 'invalid_storage', sprintf( 'Invalid storage adapter: %s', $storage ) );
                }

                if ( ! $dest_is_final && $adapter->is_dir( $source ) ) {
                    $destination = rtrim( $destination, '/' ) . '/' . basename( $source );
                }

                if ( $source === $destination ) {
                    return new \WP_Error( 'same_location', __( 'Source and destination are the same location.', 'anibas-file-manager' ) );
                }
            } catch ( \Exception $e ) {
                return new \WP_Error( 'storage_check_failed', sprintf( 'Failed to check storage adapter for %s: %s', $storage, $e->getMessage() ) );
            }
        }

        self::cleanup_old_jobs();
        $queue = anibas_fm_get_option( self::$option_name, [] );
        
        // Check if same operation already exists
        foreach ( $queue as $existing_job ) {
            if ( $existing_job['source_root'] === $source && 
                $existing_job['dest_root'] === $destination && 
                $existing_job['action'] === $action &&
                in_array( $existing_job['status'], [ 'pending', 'processing', 'retrying' ] ) ) {
                return $existing_job['id']; // Return existing job ID
            }
        }
        
        $job = [
            'id'              => wp_generate_password( 12, false ),
            'source_root'     => $source,
            'dest_root'       => $destination,
            'original_dest'   => $original_destination, // Store original destination for navigation
            'work_queue_id'   => 'anibas_fm_work_queue_' . wp_generate_password( 12, false ),
            'processed_count' => 0,
            'failed_count'    => 0,
            'action'          => $action,
            'conflict_mode'   => $conflict_mode,
            'storage'         => $storage,
            'dest_is_final'   => (bool) $dest_is_final,
            'status'          => 'pending',
            'created_at'      => time(),
            'errors'          => [],
        ];

        // Initialize work queue
        $work_queue = [];
        update_option( $job['work_queue_id'], $work_queue, false );

        $queue[] = $job;
        anibas_fm_update_option( self::$option_name, $queue );

        ActivityLogger::log_message('[BackgroundProcessor] Job enqueued successfully. Job ID: ' . $job['id'] . '. Dispatching AsyncWorker.');

        AsyncWorkerDispatcher::dispatch();

        return $job['id'];
    }

    /**
     * Enqueue a cross-storage directory transfer job.
     */
    public static function enqueue_cross_storage_job( $source, $destination, $action, $conflict_mode, $source_storage, $dest_storage ) {
        if ( ! in_array( $action, [ 'copy', 'move' ], true ) ) {
            return new \WP_Error( 'invalid_action', sprintf( 'Invalid action "%s" for cross-storage job', $action ) );
        }

        $sm             = StorageManager::get_instance();
        $source_adapter = $sm->get_adapter( $source_storage );
        $dest_adapter   = $sm->get_adapter( $dest_storage );

        if ( ! $source_adapter || ! $dest_adapter ) {
            return new \WP_Error( 'invalid_storage', __( 'Invalid source or destination storage adapter.', 'anibas-file-manager' ) );
        }

        $original_destination = $destination;
        if ( $source_adapter->is_dir( $source ) ) {
            $destination = rtrim( $destination, '/' ) . '/' . basename( $source );
        }

        // Prevent same-location copy
        if ( $source === $destination && $source_storage === $dest_storage ) {
            return new \WP_Error( 'same_location', __( 'Source and destination are the same location.', 'anibas-file-manager' ) );
        }

        self::cleanup_old_jobs();
        $queue = anibas_fm_get_option( self::$option_name, [] );

        // Check for duplicate
        foreach ( $queue as $existing_job ) {
            if ( $existing_job['source_root'] === $source
                && $existing_job['dest_root'] === $destination
                && $existing_job['action'] === $action
                && in_array( $existing_job['status'], [ 'pending', 'processing', 'retrying' ] ) ) {
                return $existing_job['id'];
            }
        }

        $job = [
            'id'               => wp_generate_password( 12, false ),
            'source_root'      => $source,
            'dest_root'        => $destination,
            'original_dest'    => $original_destination,
            'work_queue_id'    => 'anibas_fm_work_queue_' . wp_generate_password( 12, false ),
            'processed_count'  => 0,
            'failed_count'     => 0,
            'action'           => $action,
            'conflict_mode'    => $conflict_mode,
            'storage'          => $source_storage, // backward compat — source adapter for listing
            'source_storage'   => $source_storage,
            'dest_storage'     => $dest_storage,
            'is_cross_storage' => true,
            'status'           => 'pending',
            'created_at'       => time(),
            'errors'           => [],
        ];

        $work_queue = [];
        update_option( $job['work_queue_id'], $work_queue, false );

        $queue[] = $job;
        anibas_fm_update_option( self::$option_name, $queue );

        AsyncWorkerDispatcher::dispatch();

        return $job['id'];
    }

    /**
     * Enqueue a background delete job for a remote storage folder.
     */
    public static function enqueue_delete_job( $path, $storage ) {
        $sm      = StorageManager::get_instance();
        $adapter = $sm->get_adapter( $storage );

        if ( ! $adapter ) {
            return new \WP_Error( 'invalid_storage', sprintf( 'Invalid storage adapter: %s', $storage ) );
        }

        self::cleanup_old_jobs();
        $queue = anibas_fm_get_option( self::$option_name, [] );

        // Check for duplicate
        foreach ( $queue as $existing_job ) {
            if ( ( $existing_job['action'] ?? '' ) === 'delete'
                && $existing_job['source_root'] === $path
                && in_array( $existing_job['status'], [ 'pending', 'processing', 'retrying' ] ) ) {
                return $existing_job['id'];
            }
        }

        $job = [
            'id'              => wp_generate_password( 12, false ),
            'source_root'     => $path,
            'dest_root'       => '',
            'original_dest'   => '',
            'work_queue_id'   => 'anibas_fm_work_queue_' . wp_generate_password( 12, false ),
            'processed_count' => 0,
            'failed_count'    => 0,
            'action'          => 'delete',
            'conflict_mode'   => 'overwrite',
            'storage'         => $storage,
            'is_delete'       => true,
            'status'          => 'pending',
            'created_at'      => time(),
            'errors'          => [],
        ];

        $work_queue = [];
        update_option( $job['work_queue_id'], $work_queue, false );

        $queue[] = $job;
        anibas_fm_update_option( self::$option_name, $queue );

        AsyncWorkerDispatcher::dispatch();

        return $job['id'];
    }

    public static function run_worker() {
        ActivityLogger::log_message('[BackgroundProcessor] run_worker() triggered.');
        if ( ! self::acquire_lock() ) {
            ActivityLogger::log_message('[BackgroundProcessor] run_worker() could not acquire lock. Exiting.');
            return;
        }

        $queue = self::load_and_clean_queue();
        if ( ! $queue ) {
            ActivityLogger::log_message('[BackgroundProcessor] run_worker() found no jobs in queue. Releasing lock and exiting.');
            self::release_lock();
            return;
        }

        ActivityLogger::log_message('[BackgroundProcessor] run_worker() loaded queue, top job ID: ' . $queue[0]['id'] . ' with status: ' . $queue[0]['status']);

        $job = &$queue[0];

        // Register shutdown handler to catch fatal errors
        self::$shutdown_active = true;
        register_shutdown_function( function() use ( &$job, &$queue ) {
            if ( ! self::$shutdown_active ) {
                return;
            }
            $error = error_get_last();
            if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ] ) ) {
                self::fail_job( $job, 'Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] );
                $queue[0] = $job;
                self::save_queue( $queue );
                self::release_lock();
            }
        } );
        
        if ( ! self::is_job_processable( $job ) ) {
            self::$shutdown_active = false;
            self::release_lock();
            return;
        }

        $work_queue = self::load_work_queue( $job );
        if ( $work_queue === null ) {
            self::$shutdown_active = false;
            self::fail_job( $job, 'Work queue not found' );
            $queue[0] = $job;
            self::save_queue( $queue );
            self::release_lock();
            return;
        }

        $job['status'] = 'processing';
        $queue[0] = $job;
        self::save_queue( $queue );

        // Set 5-minute heartbeat to prevent zombie jobs if the worker crashes
        set_transient( 'anibas_fm_worker_heartbeat_' . $job['id'], time(), 300 );

        ActivityLogger::log_message('[BackgroundProcessor] run_worker() beginning process_job() for Job ID: ' . $job['id']);

        try {
            $is_complete = self::process_job( $job, $work_queue );
            
            if ( $is_complete && $job['status'] !== 'failed' ) {
                self::complete_job( $job );
            }
            
            $queue[0] = $job;
            self::save_queue( $queue );
            
            self::$shutdown_active = false;
            self::release_lock();
            self::log_job_state( $job, 'passed' );
        } catch ( \Exception $e ) {
            self::$shutdown_active = false;
            self::fail_job( $job, 'Fatal: ' . $e->getMessage() );
            $queue[0] = $job;
            self::save_queue( $queue );
            self::release_lock();
            self::log_job_state( $job, 'failed' );
            ActivityLogger::log_message('[BackgroundProcessor] run_worker() fatal exception for Job ID ' . $job['id'] . ': ' . $e->getMessage());
        }
    }

    private static function acquire_lock() {
        $lock_time = get_option( self::$lock_key );
        if ( $lock_time ) {
            // Check if lock has expired (e.g. 32 seconds)
            if ( time() - (int)$lock_time > 32 ) {
                // Expired. Atomically steal it by deleting and attempting to re-add.
                delete_option( self::$lock_key );
                $acquired = add_option( self::$lock_key, time(), '', 'no' );
                if ( $acquired ) ActivityLogger::log_message('[BackgroundProcessor] Lock was expired, stolen and acquired successfully.');
                return $acquired;
            }
            return false;
        }

        // Try to atomically acquire the lock
        $acquired = add_option( self::$lock_key, time(), '', 'no' );
        if ( $acquired ) {
            ActivityLogger::log_message('[BackgroundProcessor] Lock acquired successfully.');
        }
        return $acquired;
    }

    public static function is_worker_locked() {
        $lock_time = get_option( self::$lock_key );
        if ( $lock_time && ( time() - (int)$lock_time <= 32 ) ) {
            return true;
        }
        return false;
    }

    private static function release_lock() {
        delete_option( self::$lock_key );
        ActivityLogger::log_message('[BackgroundProcessor] Lock released.');
    }

    private static function load_and_clean_queue() {
        $queue = anibas_fm_get_option( self::$option_name, [] );
        if ( empty( $queue ) ) {
            return null;
        }

        $modified = false;
        $now = time();

        // Pass 1: Heartbeat check for zombie jobs
        foreach ( $queue as &$job ) {
            if ( $job['status'] === 'processing' ) {
                $heartbeat = get_transient( 'anibas_fm_worker_heartbeat_' . $job['id'] );
                if ( ! $heartbeat ) {
                    $job['status'] = 'failed';
                    $job['errors'][] = esc_html__( 'Job timed out or worker crashed (Heartbeat lost).', 'anibas-file-manager' );
                    $job['created_at'] = $now; // Reset time so the frontend has 30s to read the failure state
                    $modified = true;
                }
            }
        }
        unset( $job );

        // Pass 2: Filter out old completed/failed jobs
        $filtered = [];
        foreach ( $queue as $job ) {
            if ( $job['status'] === 'completed' && isset( $job['completed_at'] ) && $job['completed_at'] < $now - 30 ) {
                $modified = true;
                continue;
            }
            if ( $job['status'] === 'failed' && isset( $job['created_at'] ) && $job['created_at'] < $now - 30 ) {
                $modified = true;
                continue;
            }
            $filtered[] = $job;
        }

        if ( $modified ) {
            anibas_fm_update_option( self::$option_name, $filtered );
        }

        if ( empty( $filtered ) ) {
            return null;
        }

        return $filtered;
    }

    private static function save_queue( $queue ) {
        anibas_fm_update_option( self::$option_name, $queue );
    }

    private static function is_job_processable( $job ) {
        if ( in_array( $job['status'], [ 'completed', 'failed', 'awaiting_user' ] ) ) {
            return false;
        }
        return true;
    }

    private static function load_work_queue( &$job ) {
        // Assembly jobs don't have work_queue_id
        if ( isset( $job['type'] ) && $job['type'] === 'assembly' ) {
            return [];
        }
        
        if ( ! isset( $job['work_queue_id'] ) ) {
            return null;
        }
        
        $work_queue = get_option( $job['work_queue_id'], null );
        if ( $work_queue === null ) {
            return null;
        }
        return is_array( $work_queue ) ? $work_queue : [];
    }

    private static function fail_job( &$job, $error ) {
        $job['status'] = 'failed';
        $job['errors'][] = $error;
        if ( isset( $job['work_queue_id'] ) ) {
            delete_option( $job['work_queue_id'] );
        }
    }

    private static function complete_job( &$job ) {
        $job['status'] = 'completed';
        $job['completed_at'] = time();
        if ( isset( $job['work_queue_id'] ) ) {
            delete_option( $job['work_queue_id'] );
        }
    }

    private static function process_job( &$job, &$work_queue ) {
        ActivityLogger::log_message('[BackgroundProcessor] process_job() called for Job ID: ' . $job['id']);
        if ( isset( $job['type'] ) && $job['type'] === 'assembly' ) {
            return self::process_assembly_job( $job );
        }

        $sm = StorageManager::get_instance();

        // Delete job: use delete-mode PhaseExecutor
        if ( ! empty( $job['is_delete'] ) ) {
            $storage = $job['storage'] ?? 'local';
            try {
                $adapter = $sm->get_adapter( $storage );
                if ( ! $adapter ) {
                    self::fail_job( $job, "Invalid storage adapter: {$storage}" );
                    return true;
                }
            } catch ( \Exception $e ) {
                self::fail_job( $job, "Failed to get storage adapter: " . $e->getMessage() );
                return true;
            }

            $executor    = new PhaseExecutor( $adapter, null, 'delete' );
            $is_complete = $executor->execute_with_time_limit( $job, $work_queue, $adapter );

            ActivityLogger::log_message('[BackgroundProcessor] Delete phase executor returned is_complete: ' . ($is_complete ? 'true' : 'false'));

            if ( ! $is_complete ) {
                update_option( $job['work_queue_id'], $work_queue, false );
            }
            return $is_complete;
        }

        // Cross-storage job: use both adapters
        if ( ! empty( $job['is_cross_storage'] ) ) {
            try {
                $source_adapter = $sm->get_adapter( $job['source_storage'] );
                $dest_adapter   = $sm->get_adapter( $job['dest_storage'] );
                if ( ! $source_adapter || ! $dest_adapter ) {
                    self::fail_job( $job, 'Invalid cross-storage adapter(s).' );
                    return true;
                }
            } catch ( \Exception $e ) {
                self::fail_job( $job, 'Failed to connect storage: ' . $e->getMessage() );
                return true;
            }

            $executor    = new PhaseExecutor( $source_adapter, $dest_adapter );
            $is_complete = $executor->execute_with_time_limit( $job, $work_queue, $source_adapter );

            ActivityLogger::log_message('[BackgroundProcessor] Cross-storage phase executor returned is_complete: ' . ($is_complete ? 'true' : 'false'));

            if ( ! $is_complete ) {
                update_option( $job['work_queue_id'], $work_queue, false );
            }
            return $is_complete;
        }

        // Same-storage job (existing logic)
        $storage = $job['storage'] ?? 'local';
        if ( $storage === 'local' ) {
            $manager = new LocalFileSystemAdapter();
        } else {
            try {
                $manager = $sm->get_adapter( $storage );
                if ( ! $manager ) {
                    self::fail_job( $job, "Invalid storage adapter: {$storage}" );
                    return true;
                }
            } catch ( \Exception $e ) {
                self::fail_job( $job, "Failed to get storage adapter: " . $e->getMessage() );
                return true;
            }
        }

        $executor = new PhaseExecutor( $manager );

        $is_complete = $executor->execute_with_time_limit( $job, $work_queue, $manager );

        ActivityLogger::log_message('[BackgroundProcessor] Standard phase executor returned is_complete: ' . ($is_complete ? 'true' : 'false'));

        if ( ! $is_complete ) {
            update_option( $job['work_queue_id'], $work_queue, false );
        }

        return $is_complete;
    }

    private static function process_assembly_job( &$job ) {
        // Determine which phase to run
        if ( $job['current_chunk'] < $job['total_chunks'] ) {
            $phase = new AssemblyPhase();
        } else {
            $phase = new FinalizeAssemblyPhase();
        }
        
        try {
            $work_queue = [];
            $context = [];
            $phase->execute( $job, $work_queue, null, $context );
            
            // Check if we need to continue or if we're done
            if ( $phase instanceof FinalizeAssemblyPhase ) {
                // S3 uploads are chunked — finalize sets s3_upload_done when complete
                if ( isset( $job['s3_upload_done'] ) && ! $job['s3_upload_done'] ) {
                    return false; // More S3 parts to upload; come back next poll
                }
                return true; // Finalize complete, job done
            }
            
            if ( $job['current_chunk'] >= $job['total_chunks'] ) {
                return false; // Assembly done, finalize on next run
            }
            
            return false; // More chunks to process
        } catch ( \Exception $e ) {
            $error_msg = $e->getMessage();

            // Check if this is a size mismatch that needs user action
            if ( isset( $job['error_code'] ) && $job['error_code'] === 'FileSizeMismatch' ) {
                ActivityLogger::get_instance()->log_message( '[Job ' . $job['id'] . '] FileSizeMismatch — awaiting user action. ' . $error_msg );
                $job['status'] = 'awaiting_user';
                $job['errors'][] = $error_msg;
                return true; // Stop processing
            }

            // Check if this is a chunk retry (not max retries yet)
            if ( strpos( $error_msg, 'failed (attempt' ) !== false && strpos( $error_msg, 'attempt 3/3' ) === false ) {
                ActivityLogger::get_instance()->log_message( '[Job ' . $job['id'] . '] Retrying: ' . $error_msg );
                $job['status'] = 'retrying';
                $job['errors'][] = $error_msg;
                $job['error_code'] = 'ChunkRetry';
                return false; // Continue processing on next request
            }

            // Max retries reached or other fatal error
            ActivityLogger::get_instance()->log_message( '[Job ' . $job['id'] . '] FAILED: ' . $error_msg );
            self::fail_job( $job, $error_msg );

            // If it's a chunk failure after max retries, clean up the temp file
            if ( strpos( $error_msg, 'after 3 attempts' ) !== false ) {
                $job['error_code'] = 'ChunkUploadFailed';
                // Delete temp file on remote storage
                if ( isset( $job['storage'] ) && $job['storage'] !== 'local' ) {
                    try {
                        $adapter = StorageManager::get_instance()->get_adapter( $job['storage'] );
                        $temp_file = rtrim( $job['destination'], '/' ) . '/' . $job['file_name'] . '.tmp';
                        $adapter->unlink( $temp_file );
                    } catch ( \Exception $cleanup_error ) {
                        // Ignore cleanup errors
                    }
                }
            }

            return true; // Stop processing
        }
    }

    public static function get_job_status( $job_id ) {
        // Fallback: If loopback HTTP requests fail (e.g. Docker port mappings, basic auth),
        // we use the frontend's status polling to process a chunk of work synchronously.
        if ( ! self::is_worker_locked() ) {
            ActivityLogger::log_message('[BackgroundProcessor] Inline fallback triggered from get_job_status.');
            self::run_worker();
        }

        $queue = anibas_fm_get_option( self::$option_name, [] );
        foreach ( $queue as $job ) {
            if ( $job['id'] === $job_id ) {
                // Assembly jobs have different structure
                if ( isset( $job['type'] ) && $job['type'] === 'assembly' ) {
                    return [
                        'id' => $job['id'],
                        'status' => $job['status'],
                        'type' => 'assembly',
                        'file_name' => $job['file_name'],
                        'current_chunk' => $job['current_chunk'] ?? 0,
                        'total_chunks' => $job['total_chunks'],
                        'progress' => $job['total_chunks'] > 0 ? round( ( $job['current_chunk'] / $job['total_chunks'] ) * 100 ) : 0,
                        'errors' => $job['errors'] ?? [],
                        'error_code' => $job['error_code'] ?? null,
                        'error_details' => $job['error_details'] ?? null,
                    ];
                }
                
                // Regular jobs
                $work_queue = isset( $job['work_queue_id'] ) ? get_option( $job['work_queue_id'], null ) : null;
                
                $job_data = [
                    'id' => $job['id'],
                    'status' => $job['status'],
                    'source' => $job['source_root'],
                    'destination' => $job['original_dest'] ?? $job['dest_root'],
                    'action' => $job['action'],
                    'current_phase' => $work_queue ? ( $work_queue['current_phase'] ?? 'initialize' ) : null,
                    'processed_count' => $job['processed_count'],
                    'failed_count' => $job['failed_count'],
                    'total_files' => $job['total_files'] ?? 0,
                    'current_file' => $job['current_file'] ?? '',
                    'current_file_bytes' => $job['current_file_bytes'] ?? 0,
                    'current_file_size' => $job['current_file_size'] ?? 0,
                    'errors' => $job['errors'],
                ];
                
                // Convert absolute paths to relative paths for security
                return anibas_fm_convert_paths_in_job_data( $job_data );
            }
        }
        return null;
    }

    public static function cancel_job( $job_id ) {
        $queue = anibas_fm_get_option( self::$option_name, [] );
        foreach ( $queue as $key => $job ) {
            if ( $job['id'] === $job_id ) {
                delete_option( $job['work_queue_id'] );
                unset( $queue[ $key ] );
                anibas_fm_update_option( self::$option_name, array_values( $queue ) );
                return true;
            }
        }
        return false;
    }

    public static function clear_all_jobs() {
        anibas_fm_update_option( self::$option_name, [] );
        delete_transient( self::$lock_key );
        return true;
    }

    public static function cleanup_old_jobs() {
        $queue = anibas_fm_get_option( self::$option_name, [] );
        $cutoff = time() - HOUR_IN_SECONDS;
        
        $queue = array_filter( $queue, function( $job ) use ( $cutoff ) {
            if ( $job['status'] === 'completed' && isset( $job['completed_at'] ) && $job['completed_at'] < $cutoff ) {
                return false;
            }
            if ( $job['status'] === 'failed' && isset( $job['created_at'] ) && $job['created_at'] < $cutoff ) {
                return false;
            }
            return true;
        });
        
        anibas_fm_update_option( self::$option_name, array_values( $queue ) );
    }

    /**
     * Log job state changes to a JSON file in the anibas-logs directory.
     * 
     * The log file is named job-log.json and contains an array of log entries.
     * Each log entry contains the following information:
     * - timestamp: The date and time of the event in the format Y-m-d H:i:s.
     * - event: The type of event that triggered the log entry (started, finished, failed).
     * - job_id: The ID of the job that triggered the log entry.
     * - status: The current status of the job (pending, processing, completed, failed).
     * - type: The type of job (operation, transfer).
     * - action: The action being performed by the job (copy, move, delete).
     * - source: The source file or directory of the job.
     * - destination: The destination file or directory of the job.
     * - processed_count: The number of files that have been processed so far.
     * - failed_count: The number of files that have failed to be processed.
     * - total: The total number of files that need to be processed.
     * - errors: An array of error messages encountered during the job.
     * - error_code: The error code encountered during the job, if any.
     */
    private static function log_job_state( $job, $event ) {
        $log_dir = anibas_fm_get_log_file_path();

        $log_file = $log_dir . '/.job-log.json';
        $timestamp = date( 'Y-m-d H:i:s' );
        
        $log_entry = [
            'timestamp' => $timestamp,
            'event' => $event,
            'job_id' => $job['id'],
            'status' => $job['status'],
            'type' => $job['type'] ?? 'operation',
            'action' => $job['action'] ?? null,
            'source' => $job['source_root'] ?? $job['file_name'] ?? null,
            'destination' => $job['dest_root'] ?? $job['destination'] ?? null,
            'processed_count' => $job['processed_count'] ?? $job['current_chunk'] ?? 0,
            'failed_count' => $job['failed_count'] ?? 0,
            'total' => $job['total_chunks'] ?? null,
            'errors' => $job['errors'] ?? [],
            'error_code' => $job['error_code'] ?? null,
        ];

        // Reset log on new job start
        if ( $event === 'started' ) {
            $logs = [ $log_entry ];
        } else {
            // Keep last 100 entries
            $logs = [];
            if ( file_exists( $log_file ) ) {
                $content = file_get_contents( $log_file );
                $logs = json_decode( $content, true ) ?: [];
            }
            
            $logs[] = $log_entry;
            $logs = array_slice( $logs, -100 );
        }
        
        file_put_contents( $log_file, json_encode( $logs, JSON_PRETTY_PRINT ) );
        
        // Keep last failed job separately
        if ( $event === 'failed' || $job['status'] === 'failed' ) {
            $failed_log_file = $log_dir . '/last-failed-job.json';
            file_put_contents( $failed_log_file, json_encode( $job, JSON_PRETTY_PRINT ) );
        }
    }
}

BackgroundProcessor::init();
