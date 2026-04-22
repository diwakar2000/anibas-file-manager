# AJAX Handlers Reference

All handlers live in `engine/core/ajax/` and extend `Anibas\AjaxHandler` (defined in `engine/core/class-ajax-handler.php`).
All are in `namespace Anibas`. Instantiated in `Anibas_File_Manager_Main::init_ajax_handler()` (`engine/core/class-anibas-file-manager-main.php`).

## Base: `AjaxHandler` (`engine/core/class-ajax-handler.php`)

**Properties:** `$root_path` (realpath ABSPATH)

**Privilege methods (protected):**
- `check_admin_privilege()` — `manage_options` cap check
- `check_nonce($nonce)` — verifies WP nonce from request
- `check_privilege()` — fm_token + list nonce + admin
- `check_create_privilege()` — fm_token + create nonce + admin + backup lock
- `check_delete_privilege()` — fm_token + delete nonce + admin + backup lock
- `check_backup_privilege()` — create nonce + admin (no fm_token)
- `check_save_settings_privilege()` — settings nonce + admin
- `check_fm_token()` — validates FM password session token via transient
- `block_during_backup()` — returns 423 if backup is running

**Helpers:**
- `validate_path($path)` — null-byte strip, realpath check, blocked/excluded path check → returns full_path or false
- `get_storage_adapter($storage)` — calls `StorageManager::get_instance()->get_adapter()`
- `get_archive_jobs()` — reads archive job registry, prunes >2hr entries

---

## `FileCrudAjaxHandler` (`class-file-crud-ajax-handler.php`)

| Action Constant | Method | Privilege | Purpose |
|---|---|---|---|
| `ANIBAS_FM_GET_FILE_LIST` | `get_file_list()` | `check_privilege()` | List directory contents (paginated) |
| `ANIBAS_FM_CREATE_FOLDER` | `create_folder()` | `check_create_privilege()` | Create new folder |
| `ANIBAS_FM_CREATE_FILE` | `create_file()` | `check_create_privilege()` | Create new file |
| `ANIBAS_FM_DELETE_FILE` | `delete_file()` | `check_delete_privilege()` | Delete file/folder (trash or permanent) |
| `ANIBAS_FM_EMPTY_FOLDER` | `empty_folder()` | `check_delete_privilege()` | Empty folder contents |
| `ANIBAS_FM_RENAME_FILE` | `rename_file()` | `check_create_privilege()` | Rename file/folder |
| `ANIBAS_FM_DOWNLOAD_FILE` | `download_file()` | `check_privilege()` | Stream file download |
| `ANIBAS_FM_PREVIEW_FILE` | `preview_file()` | `check_privilege()` | Preview file (presigned URL for S3) |
| `ANIBAS_FM_GET_FILE_DETAILS` | `get_file_details()` | `check_privilege()` | Get extended file metadata |

---

## `TransferAjaxHandler` (`class-transfer-ajax-handler.php`)

| Action Constant | Method | Privilege | Purpose |
|---|---|---|---|
| `ANIBAS_FM_DUPLICATE_FILE` | `duplicate_file()` | `check_create_privilege()` | Duplicate file/folder |
| `ANIBAS_FM_TRANSFER_FILE` | `transfer_file()` | `check_create_privilege()` | Copy/move (same or cross-storage) |
| `ANIBAS_FM_JOB_STATUS` | `get_job_status()` | `check_privilege()` | Poll background job status |
| `ANIBAS_FM_CANCEL_JOB` | `cancel_job()` | `check_create_privilege()` | Cancel background job |
| `ANIBAS_FM_CHECK_CONFLICT` | `check_conflict()` | `check_privilege()` | Check if destination exists |
| `ANIBAS_FM_CHECK_RUNNING_TASKS` | `check_running_tasks()` | `check_privilege()` | List active bg + archive jobs |
| `ANIBAS_FM_RESOLVE_SIZE_MISMATCH` | `resolve_size_mismatch()` | `check_create_privilege()` | Resolve upload size mismatch |

---

## `ArchiveAjaxHandler` (`class-archive-ajax-handler.php`)

| Action Constant | Method | Privilege | Purpose |
|---|---|---|---|
| `ANIBAS_FM_ARCHIVE_CREATE` | `archive_create()` | `check_create_privilege()` | Create zip/tar/anfm archive |
| `ANIBAS_FM_ARCHIVE_CHECK` | `archive_check()` | `check_privilege()` | Poll archive creation progress |
| `ANIBAS_FM_ARCHIVE_RESTORE` | `archive_restore()` | `check_create_privilege()` | Extract archive |
| `ANIBAS_FM_CANCEL_ARCHIVE_JOB` | `cancel_archive_job()` | `check_create_privilege()` | Cancel archive operation |

Supported formats: `zip`, `tar`, `anfm` (encrypted custom format).

---

## `AuthAjaxHandler` (`class-auth-ajax-handler.php`)

| Action Constant | Method | Privilege | Purpose |
|---|---|---|---|
| `ANIBAS_FM_VERIFY_FM_PASSWORD` | `verify_fm_password()` | nonce+admin | FM gate password verify → issues session token |
| `ANIBAS_FM_CHECK_FM_AUTH` | `check_fm_auth()` | nonce+admin | Check if FM session token is valid |
| `ANIBAS_FM_VERIFY_DELETE_PASSWORD` | `verify_delete_password()` | nonce+admin | Verify delete-confirmation password |
| `ANIBAS_FM_VERIFY_PASSWORD` | `verify_password()` | settings nonce | Verify settings password |
| `ANIBAS_FM_CHECK_AUTH` | `check_auth()` | settings nonce | Check settings auth session |
| `ANIBAS_FM_REQUEST_DELETE_TOKEN` | `request_delete_token()` | delete nonce+admin | Issue one-time delete token |

---

## `SettingsAjaxHandler` (`class-settings-ajax-handler.php`)

| Action Constant | Method | Privilege | Purpose |
|---|---|---|---|
| `ANIBAS_FM_SAVE_SETTINGS` | `save_settings()` | `check_save_settings_privilege()` | Save all plugin settings |
| `ANIBAS_FM_GET_REMOTE_SETTINGS` | `get_remote_settings()` | `check_save_settings_privilege()` | Get remote storage configs (redacted secrets) |
| `ANIBAS_FM_SAVE_REMOTE_SETTINGS` | `save_remote_settings()` | `check_save_settings_privilege()` | Save remote storage configs (encrypts secrets) |
| `ANIBAS_FM_TEST_REMOTE_CONNECTION` | `test_remote_connection()` | `check_save_settings_privilege()` | Test FTP/SFTP/S3 connection |

---

## `TrashAjaxHandler` (`class-trash-ajax-handler.php`)

| Action Constant | Method | Privilege | Purpose |
|---|---|---|---|
| `ANIBAS_FM_LIST_TRASH` | `list_trash()` | `check_privilege()` | List trash contents from index.json |
| `ANIBAS_FM_RESTORE_TRASH` | `restore_trash()` | `check_create_privilege()` | Restore item to original path |
| `ANIBAS_FM_EMPTY_TRASH` | `empty_trash()` | `check_delete_privilege()` | Permanently delete all trash |

Trash dir: `ABSPATH/.trash/`, tracked via `index.json` ledger.

---

## `UploadAjaxHandler` (`class-upload-ajax-handler.php`)

| Action Constant | Method | Privilege | Purpose |
|---|---|---|---|
| `ANIBAS_FM_INIT_UPLOAD` | `init_upload()` | `check_create_privilege()` | Initialize upload session (returns token) |
| `ANIBAS_FM_UPLOAD_CHUNK` | `upload_chunk()` | token-based auth | Receive a file chunk (multipart) |

---

## `BackupAjaxHandler` (`class-backup-ajax-handler.php`)

| Action Constant | Method | Privilege | Purpose |
|---|---|---|---|
| `ANIBAS_FM_BACKUP_SINGLE_FILE` | `backup_single_file()` | `check_create_privilege()` | Pre-edit file backup snapshot |
| `ANIBAS_FM_LIST_FILE_BACKUPS` | `list_file_backups()` | `check_privilege()` | List per-file backup versions |
| `ANIBAS_FM_RESTORE_FILE_BACKUP` | `restore_file_backup()` | `check_create_privilege()` | Restore a file backup version |
| `ANIBAS_FM_LIST_SITE_BACKUPS` | `list_site_backups()` | `check_backup_privilege()` | List whole-site backup archives |
| `ANIBAS_FM_BACKUP_START` | `backup_start()` | `check_backup_privilege()` | Start site backup (tar/anfm) |
| `ANIBAS_FM_BACKUP_POLL` | `backup_poll()` | `check_backup_privilege()` | Poll site backup progress |
| `ANIBAS_FM_BACKUP_CANCEL` | `backup_cancel()` | `check_backup_privilege()` | Cancel running site backup |
| `ANIBAS_FM_BACKUP_STATUS` | `backup_status()` | `check_backup_privilege()` | Check if any backup is running |

---

## `EditorAjax` (`engine/core/class-editor-ajax.php`)

**Not an AjaxHandler subclass** — registers actions directly. Uses `check_ajax_referer()`.

| Action Constant | Method | Purpose |
|---|---|---|
| `ANIBAS_FM_INIT_EDITOR_SESSION` | `init_editor_session()` | Create editor session transient |
| `ANIBAS_FM_GET_FILE_CHUNK` | `get_file_chunk()` | Chunked file read (base64 response) |
| `ANIBAS_FM_SAVE_FILE` | `save_file()` | Write file content (auto-backup before save) |

Sessions keyed by `user_id + path + storage`. Max file: 10MB. Chunk: 2MB.

---

## WP Option Keys

- `AnibasFileManagerOptions` — main settings array (accessed via `anibas_fm_get_option()`)
- `anibas_fm_remote_connections` — remote storage configs (encrypted secrets)
- `anibas_file_manager_log_dir` — random log directory path
- `anibas_file_manager_backup_dir` — random backup directory path

## Nonce Keys

| Constant | Value | Used By |
|---|---|---|
| `ANIBAS_FM_NONCE_LIST` | `anibas-fm-list` | Read operations |
| `ANIBAS_FM_NONCE_CREATE` | `anibas-fm-create` | Write/transfer/backup ops |
| `ANIBAS_FM_NONCE_DELETE` | `anibas-fm-delete` | Delete operations |
| `ANIBAS_FM_NONCE_SETTINGS` | `anibas-fm-settings` | Settings operations |
| `ANIBAS_FM_NONCE_FM` | `anibas-fm-file-manager` | FM password gate |
| `ANIBAS_FM_NONCE_EDITOR` | `anibas-fm-editor` | Editor operations |
