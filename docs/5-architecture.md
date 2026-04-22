# Architecture: How Everything Connects

## Plugin Lifecycle

```
WordPress loads anibas-file-manager.php
  → defines constants (ANIBAS_FILE_MANAGER_VERSION, PLUGIN_DIR, PLUGIN_URL)
  → register_activation_hook   → Anibas_File_Manager_Activator::activate()
  → register_deactivation_hook → Anibas_File_Manager_Deactivator::deactivate()
  → run_anibas_file_manager()  → new Anibas_File_Manager() → $plugin->run()
```

### Boot Sequence (Anibas_File_Manager class)

```
__construct()
  → load_dependencies()     ← requires ALL PHP files (core, adapters, handlers, operations, includes)
  → set_locale()            ← i18n setup
  → define_admin_hooks()    ← enqueue_styles + enqueue_scripts on admin pages
```

### Admin Page Load

```
User visits admin.php?page=anibas-file-manager
  → Anibas_File_Manager_Main::enqueue_styles()  → loads dist/main.css + bootstrap.min.css
  → Anibas_File_Manager_Main::enqueue_scripts() → loads dist/main.js + wp_localize_script(AnibasFM)
  → display_menu_page()                         → renders #anibas-file-manager container
  → Svelte mounts App.svelte into container
```

---

## Request Flow Diagram

```
┌──────────────────────────────────────────────────────────┐
│                    FRONTEND (Svelte)                      │
│                                                          │
│  Component (e.g. FileExplorer)                           │
│       │                                                  │
│       ▼                                                  │
│  fileStore.svelte.ts (state manager)                     │
│       │                                                  │
│       ▼                                                  │
│  fileApi.ts (service layer)                              │
│       │  sends: { action, nonce, fm_token, path,         │
│       │          storage, ...params }                    │
│       ▼                                                  │
└───────┼──────────────────────────────────────────────────┘
        │  HTTP (fetch → admin-ajax.php)
        ▼
┌──────────────────────────────────────────────────────────┐
│                     BACKEND (PHP)                         │
│                                                          │
│  WordPress wp_ajax_{action} hook                         │
│       │                                                  │
│       ▼                                                  │
│  AjaxHandler subclass (e.g. FileCrudAjaxHandler)         │
│       │  1. check privilege (nonce + cap + fm_token)     │
│       │  2. validate path                                │
│       │                                                  │
│       ▼                                                  │
│  StorageManager::get_instance()->get_adapter($storage)   │
│       │  lazy-creates adapter based on storage type      │
│       ▼                                                  │
│  FileSystemAdapter subclass                              │
│  (Local / FTP / SFTP / S3)                               │
│       │  performs filesystem operation                    │
│       ▼                                                  │
│  wp_send_json_success/error()                            │
└──────────────────────────────────────────────────────────┘
```

---

## File Structure

```
anibas-file-manager/
│
├── anibas-file-manager.php ............. Plugin bootstrap (defines constants, hooks, runs plugin)
├── uninstall.php ....................... Cleanup on uninstall
├── index.php ........................... Silence
│
├── includes/ ........................... WP Plugin Boilerplate classes
│   ├── class-anibas-file-manager.php ... Core plugin class (loads deps, hooks)
│   ├── class-anibas-file-manager-loader.php .. Hook registration manager
│   ├── class-anibas-file-manager-activator.php
│   ├── class-anibas-file-manager-deactivator.php
│   ├── class-anibas-file-manager-i18n.php
│   ├── constants.php ................... ALL action names, nonces, limits, paths
│   └── functions.php ................... Global helpers (options, paths, trash, backup, encryption)
│
├── engine/ ............................. Core business logic
│   ├── core/
│   │   ├── class-anibas-file-manager-main.php .. Admin page registration, script enqueue
│   │   ├── class-ajax-handler.php .............. Base AJAX handler (privilege, path, adapter)
│   │   ├── class-storage-manager.php ........... Singleton adapter registry (lazy-load)
│   │   ├── class-editor-page.php ............... Editor helpers (is_editable, session_key)
│   │   ├── class-editor-ajax.php ............... Editor AJAX (chunked read/save)
│   │   ├── class-backup-engine.php ............. Site backup coordinator
│   │   ├── ajax/ ............................... Domain-specific AJAX handlers
│   │   │   ├── class-file-crud-ajax-handler.php   (list, create, delete, rename, download, preview)
│   │   │   ├── class-transfer-ajax-handler.php    (copy, move, duplicate, job mgmt)
│   │   │   ├── class-archive-ajax-handler.php     (zip/tar/anfm create & restore)
│   │   │   ├── class-auth-ajax-handler.php        (FM password, delete password, tokens)
│   │   │   ├── class-settings-ajax-handler.php    (save/load settings, remote configs)
│   │   │   ├── class-trash-ajax-handler.php       (list, restore, empty trash)
│   │   │   ├── class-upload-ajax-handler.php      (chunked file upload)
│   │   │   └── class-backup-ajax-handler.php      (file backups, site backups)
│   │   └── archiver/ .......................... Archive engines (time-budgeted, resumable)
│   │       ├── class-zip-create-engine.php
│   │       ├── class-zip-restore-engine.php
│   │       ├── class-tar-create-engine.php
│   │       ├── class-tar-restore-engine.php
│   │       ├── class-archive-create-engine.php  (anfm = encrypted custom format)
│   │       └── class-archive-restore-engine.php
│   │
│   ├── adapters/ .......................... Filesystem adapters (polymorphic)
│   │   ├── interface-filesystem-adapter.php ... Abstract base (FileSystemAdapter)
│   │   ├── class-local-filesystem-adapter.php  (local fs, trash support)
│   │   ├── class-ftp-filesystem-adapter.php    (PHP ftp_* functions)
│   │   ├── class-sftp-filesystem-adapter.php   (phpseclib3, chunked)
│   │   ├── class-s3-filesystem-adapter.php     (custom S3 client, multipart)
│   │   └── class-s3-client.php ................ Zero-dep S3 client (AnibasS3Client)
│   │
│   ├── handlers/
│   │   └── class-background-processor.php ..... BG job queue (copy/move/delete phases)
│   │
│   ├── operations/ ........................ Phase-based execution system
│   │   ├── interface-operation-phases.php
│   │   ├── class-phase-executor.php .......... Runs phases with time budget
│   │   └── phases/
│   │       ├── class-initialize-phase.php .... Setup job state
│   │       ├── class-list-phase.php .......... Recursive directory scan
│   │       ├── class-transfer-phase.php ...... Copy/move files
│   │       ├── class-cross-storage-transfer-phase.php .. Local↔Remote chunked
│   │       ├── class-wrapup-phase.php ........ Post-transfer cleanup (move = delete source)
│   │       ├── class-delete-phase.php ........ Background recursive delete
│   │       ├── class-assembly-phase.php ...... Chunked upload assembly
│   │       └── class-finalize-assembly-phase.php .. Finalize assembled upload
│   │
│   ├── utilities/
│   │   ├── class-activity-logger.php ......... File-based activity logging
│   │   └── class-remote-storage-tester.php ... Connection test helper
│   │
│   └── partials/ .......................... Admin page HTML templates
│       ├── anibas-file-manager-admin-display.php
│       └── anibas-file-manager-settings.php
│
├── src/ ................................. Frontend source (Svelte 5 + TypeScript)
│   ├── main.ts .......................... File manager entry point
│   ├── settings.ts ...................... Settings page entry point
│   ├── editor.ts ........................ Editor entry point
│   ├── App.svelte ....................... File manager root component
│   ├── Settings.svelte .................. Settings root component
│   ├── services/fileApi.ts .............. AJAX service layer (all API calls)
│   ├── stores/fileStore.svelte.ts ....... Central reactive state (Svelte 5 runes)
│   ├── types/files.ts ................... FileItem interface
│   ├── types.ts ......................... Shared types
│   ├── utils/ ........................... Utilities
│   │   ├── ChunkUploader.ts ............. Chunked upload manager
│   │   ├── editable.ts .................. Inline edit action
│   │   ├── fileIcons.ts ................. Icon/color mapping
│   │   ├── i18n.ts ...................... WP i18n wrapper
│   │   └── toast.ts ..................... Toast notification system
│   └── components/ ...................... See 4-frontend.md for full tree
│
├── dist/ ................................ Built frontend assets
├── bootstrap/ ........................... Bootstrap CSS/JS
├── assets/ .............................. Screenshots
├── languages/ ........................... Translation files
└── vendor/ .............................. Composer deps (phpseclib3)
```

---

## Key Data Flows

### 1. File Listing
```
FileExplorer → fileStore.navigate(path) → fileApi.fetchNode() → FileCrudAjaxHandler::get_file_list()
  → adapter.listDirectory(path, page, pageSize) → wp_send_json_success(files)
```

### 2. File Upload (Chunked)
```
Toolbar (drop/select) → fileStore.startUpload() → ChunkUploader
  → fileApi POST init_upload → UploadAjaxHandler::init_upload() → returns upload_token
  → fileApi POST upload_chunk × N → UploadAjaxHandler::upload_chunk() → adapter.append_contents()
  → Final chunk → BackgroundProcessor.enqueue_job(assembly) → AssemblyPhase → FinalizeAssemblyPhase
```

### 3. Copy/Move (Background)
```
Toolbar paste → fileStore.paste() → fileApi.transferFile()
  → TransferAjaxHandler::transfer_file()
     if small file: adapter.copy/move() → immediate response
     if folder: BackgroundProcessor::enqueue_job()
       → PhaseExecutor.execute_with_time_limit()
         → InitializePhase → ListPhase → TransferPhase → WrapupPhase
       → Frontend polls via fileApi.getJobStatus() → BackgroundProcessor::get_job_status()
```

### 4. Archive Create
```
Toolbar → archivePrescan() → archiveCreate(phase='scan') → archiveCreate(phase='run') × N → archiveCreate(phase='cleanup')
  → ArchiveAjaxHandler → ZipCreateEngine/TarCreateEngine/ArchiveCreateEngine
    → build_manifest() → run_step() (time-budgeted) → progress() → cleanup()
```

### 5. Site Backup
```
Settings/BackupModal → fileApi.backupStart(format) → BackupAjaxHandler::backup_start()
  → BackupEngine::start() → builds manifest → sets lock → returns job_id
  → fileApi.backupPoll(jobId) × N → BackupAjaxHandler::backup_poll()
    → BackupEngine::resume(jobId) → run_step() → progress()
  → done → BackupEngine::finish() → clears lock
```

### 6. Cross-Storage Transfer
```
SendToModal → fileApi.transferFile(src, dst, action, conflict, srcStorage, destStorage)
  → TransferAjaxHandler validates cross-storage → BackgroundProcessor::enqueue_cross_storage_job()
    → PhaseExecutor (source_adapter + dest_adapter)
      → ListPhase (scan source) → CrossStorageTransferPhase (download→local→upload) → WrapupPhase
```

---

## Security Layers

1. **WordPress capability:** `manage_options` (admin only)
2. **Nonce verification:** per-operation nonces (list/create/delete/settings/editor)
3. **FM password gate:** optional session token (transient-based, per-user)
4. **Delete password:** optional second factor for destructive ops
5. **Path validation:** realpath + blocked paths + excluded paths
6. **Backup lock:** blocks all write ops during site backup (HTTP 423)
7. **Rate limiting:** operation delay + lock duration constants
8. **Credential encryption:** AES-256-GCM for stored remote passwords

---

## WP Options Used

| Option Key | Type | Purpose |
|---|---|---|
| `AnibasFileManagerOptions` | array | All plugin settings (via `anibas_fm_get/update_option`) |
| `anibas_fm_remote_connections` | array | Remote storage configs (encrypted secrets) |
| `anibas_file_manager_log_dir` | string | Randomized log directory path |
| `anibas_file_manager_backup_dir` | string | Randomized backup directory path |

## Transients Used

| Key Pattern | TTL | Purpose |
|---|---|---|
| `anibas_fm_fm_token_{user_id}` | session | FM password session hash |
| `anibas_fm_backup_running` | 2hr | Backup lock data |
| `anibas_fm_backup_job_{id}` | 2hr | Backup job state |
| `anibas_fm_worker_lock` | 32s | Background processor mutex |

## Cron Hooks

| Hook | Callback | Schedule |
|---|---|---|
| `anibas_fm_trash_cleanup` | `anibas_fm_purge_trash()` | Daily |
| `anibas_fm_temp_cleanup` | `anibas_fm_purge_temp()` | Daily |
| `anibas_fm_backup_cleanup` | `anibas_fm_purge_old_backups()` | Daily |
