<?php
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ANIBAS_FILE_MANAGER_DEFAULT_FILE_SIZE', 100 );

// AJAX Actions
define( 'ANIBAS_FM_GET_FILE_LIST', 'anibas_fm_get_file_list' );
define( 'ANIBAS_FM_CREATE_FOLDER', 'anibas_fm_create_folder' );
define( 'ANIBAS_FM_DELETE_FILE', 'anibas_fm_delete_file' );
define( 'ANIBAS_FM_SAVE_SETTINGS', 'anibas_fm_save_settings' );
define( 'ANIBAS_FM_VERIFY_PASSWORD', 'anibas_fm_verify_password' );
define( 'ANIBAS_FM_CHECK_AUTH', 'anibas_fm_check_auth' );
define( 'ANIBAS_FM_VERIFY_DELETE_PASSWORD', 'anibas_fm_verify_delete_password' );
define( 'ANIBAS_FM_TRANSFER_FILE', 'anibas_fm_transfer_file' );
define( 'ANIBAS_FM_JOB_STATUS', 'anibas_fm_job_status' );
define( 'ANIBAS_FM_CANCEL_JOB', 'anibas_fm_cancel_job' );
define( 'ANIBAS_FM_CHECK_CONFLICT', 'anibas_fm_check_conflict' );
define( 'ANIBAS_FM_CHECK_RUNNING_TASKS', 'anibas_fm_check_running_tasks' );
define( 'ANIBAS_FM_REQUEST_DELETE_TOKEN', 'anibas_fm_request_delete_token' );
define( 'ANIBAS_FM_GET_REMOTE_SETTINGS', 'anibas_get_remote_settings' );
define( 'ANIBAS_FM_SAVE_REMOTE_SETTINGS', 'anibas_save_remote_settings' );
define( 'ANIBAS_FM_TEST_REMOTE_CONNECTION', 'anibas_test_remote_connection' );
define( 'ANIBAS_FM_UPLOAD_CHUNK', 'anibas_fm_upload_chunk' );
define( 'ANIBAS_FM_INIT_UPLOAD', 'anibas_fm_init_upload' );
define( 'ANIBAS_FM_CREATE_FILE', 'anibas_fm_create_file' );
define( 'ANIBAS_FM_RESOLVE_SIZE_MISMATCH', 'anibas_fm_resolve_size_mismatch' );
define( 'ANIBAS_FM_ARCHIVE_CREATE', 'anibas_fm_archive_create' );
define( 'ANIBAS_FM_ARCHIVE_CHECK', 'anibas_fm_archive_check' );
define( 'ANIBAS_FM_ARCHIVE_RESTORE', 'anibas_fm_archive_restore' );
define( 'ANIBAS_FM_CANCEL_ARCHIVE_JOB', 'anibas_fm_cancel_archive_job' );
define( 'ANIBAS_FM_VERIFY_FM_PASSWORD', 'anibas_fm_verify_fm_password' );
define( 'ANIBAS_FM_CHECK_FM_AUTH', 'anibas_fm_check_fm_auth' );
define( 'ANIBAS_FM_RENAME_FILE', 'anibas_fm_rename_file' );
define( 'ANIBAS_FM_DUPLICATE_FILE', 'anibas_fm_duplicate_file' );
define( 'ANIBAS_FM_DOWNLOAD_FILE', 'anibas_fm_download_file' );
define( 'ANIBAS_FM_PREVIEW_FILE', 'anibas_fm_preview_file' );
define( 'ANIBAS_FM_GET_FILE_DETAILS', 'anibas_fm_get_file_details' );

// Nonces
define( 'ANIBAS_FM_NONCE_LIST', 'anibas-fm-list' );
define( 'ANIBAS_FM_NONCE_CREATE', 'anibas-fm-create' );
define( 'ANIBAS_FM_NONCE_DELETE', 'anibas-fm-delete' );
define( 'ANIBAS_FM_NONCE_SETTINGS', 'anibas-fm-settings' );
define( 'ANIBAS_FM_NONCE_FM', 'anibas-fm-file-manager' );

// Paths
define( 'ANIBAS_FM_ROOT_PATH', realpath( ABSPATH ) );
define( 'ANIBAS_FM_ROOT_PATH_PLACEHOLDER', '/' );

// Rate Limiting
define( 'ANIBAS_FM_OPERATION_DELAY', 2 ); // seconds
define( 'ANIBAS_FM_LOCK_DURATION', 3 ); // seconds

// Upload / chunked transfer
define( 'ANIBAS_FM_CHUNK_SIZE_MIN',     1048576 );  // 1 MB
define( 'ANIBAS_FM_CHUNK_SIZE_MAX',     20971520 ); // 20 MB
define( 'ANIBAS_FM_DEFAULT_CHUNK_SIZE', 10485760 );  // 1 MB
define( 'ANIBAS_FM_UPLOAD_TOKEN_EXPIRY', 300 ); // 5 minutes

// Trash
define( 'ANIBAS_FM_TRASH_DIR_NAME', '.trash' );
define( 'ANIBAS_FM_TRASH_MAX_AGE', 30 * DAY_IN_SECONDS ); // 30 days
define( 'ANIBAS_FM_TRASH_CRON_HOOK', 'anibas_fm_trash_cleanup' );
define( 'ANIBAS_FM_EMPTY_FOLDER', 'anibas_fm_empty_folder' );
define( 'ANIBAS_FM_LIST_TRASH', 'anibas_fm_list_trash' );
define( 'ANIBAS_FM_RESTORE_TRASH', 'anibas_fm_restore_trash' );
define( 'ANIBAS_FM_EMPTY_TRASH', 'anibas_fm_empty_trash' );
define( 'ANIBAS_FM_BACKUP_SINGLE_FILE', 'anibas_fm_backup_single_file' );
define( 'ANIBAS_FM_LIST_FILE_BACKUPS', 'anibas_fm_list_file_backups' );
define( 'ANIBAS_FM_RESTORE_FILE_BACKUP', 'anibas_fm_restore_file_backup' );
define( 'ANIBAS_FM_LIST_SITE_BACKUPS', 'anibas_fm_list_site_backups' );

// Temp Operations
define( 'ANIBAS_FM_TEMP_MAX_AGE', 86400 ); // 24 hours
define( 'ANIBAS_FM_TEMP_CRON_HOOK', 'anibas_fm_temp_cleanup' );

// Backup
define( 'ANIBAS_FM_BACKUP_START',       'anibas_fm_backup_start' );
define( 'ANIBAS_FM_BACKUP_POLL',        'anibas_fm_backup_poll' );
define( 'ANIBAS_FM_BACKUP_CANCEL',      'anibas_fm_backup_cancel' );
define( 'ANIBAS_FM_BACKUP_STATUS',      'anibas_fm_backup_status' );
define( 'ANIBAS_FM_BACKUP_DIR_NAME',    'anibas-backups' );
define( 'ANIBAS_FM_BACKUP_MAX_AGE',     7 * DAY_IN_SECONDS ); // 7 days
define( 'ANIBAS_FM_BACKUP_CRON_HOOK',   'anibas_fm_backup_cleanup' );
define( 'ANIBAS_FM_BACKUP_LOCK_KEY',    'anibas_fm_backup_running' );
define( 'ANIBAS_FM_FILE_BACKUP_KEEP',   5 ); // per-file rolling window size

// Editor
define( 'ANIBAS_FM_GENERATE_EDITOR_TOKEN', 'anibas_fm_generate_editor_token' );
define( 'ANIBAS_FM_INIT_EDITOR_SESSION',  'anibas_fm_init_editor_session' );
define( 'ANIBAS_FM_GET_FILE_CHUNK',        'anibas_fm_get_file_chunk' );
define( 'ANIBAS_FM_SAVE_FILE',             'anibas_fm_save_file' );
define( 'ANIBAS_FM_NONCE_EDITOR',          'anibas-fm-editor' );
define( 'ANIBAS_FM_EDITOR_TOKEN_TTL',      300 );           // seconds — window to open the tab
define( 'ANIBAS_FM_EDITOR_SESSION_TTL',    7200 );          // seconds — how long the edit session lasts (2 hrs)
define( 'ANIBAS_FM_EDITOR_MAX_BYTES',      10485760 );      // 10 MB
define( 'ANIBAS_FM_EDITOR_CHUNK_BYTES',    2097152 );       // 2 MB read chunk

define( 'ANIBAS_FM_EDITOR_EXTENSIONS', [
    // text / config
    'txt', 'log', 'md', 'csv', 'ini', 'cfg', 'conf', 'env',
    // web
    'html', 'htm', 'css', 'js', 'ts', 'jsx', 'tsx', 'vue', 'svelte',
    // backend
    'php', 'py', 'rb', 'java', 'go', 'rs', 'swift', 'kt', 'cs', 'c', 'cpp', 'h',
    // data / build
    'json', 'xml', 'yaml', 'yml', 'toml', 'sql', 'sh', 'bash', 'zsh', 'ps1', 'bat', 'cmd',
] );

// Dot-files allowed by exact name (no extension)
define( 'ANIBAS_FM_EDITOR_DOTFILES', [
    '.gitignore', '.gitattributes', '.gitmodules', '.editorconfig',
    '.htaccess', '.env', '.prettierrc', '.eslintrc', '.babelrc', '.npmrc',
] );