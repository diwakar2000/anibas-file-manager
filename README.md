# Anibas File Manager

A full-featured file manager for WordPress with multi-storage support, a built-in code editor, archive management, trash system, site backups, chunked uploads, and background processing for large operations.

**Version:** 0.4.0  
**Author:** Diwakar Dahal  
**License:** GPL-2.0+  
**Requires:** WordPress 6.0+, PHP 8.0+

---

## Features

### File & Folder Operations

- Browse files and folders with an expandable sidebar tree and paginated explorer
- List and grid view modes with sorting
- Create, rename, duplicate, copy, move, and delete files and folders
- Conflict resolution on copy/move — skip, overwrite, or auto-rename
- Download files directly from the browser
- Preview images, videos, audio, PDFs, and text files inline
- Context menu with per-item actions
- File details modal with permissions, owner, MIME type, and timestamps
- Cross-storage file transfer (e.g. local → S3, FTP → local)
- Send-to modal for transferring files between storage backends

### Trash System

- Soft-delete with configurable trash directory (`.trash`)
- Index-based tracking preserving original paths for accurate restoration
- Auto-cleanup via WP-Cron (default: 30 days)
- Trash bin UI with restore and permanent delete
- Name conflict resolution on restore

### Built-in Code Editor

- Full-featured editor (CodeMirror 6) opens inline or in a dedicated tab
- Syntax highlighting for PHP, JS, TS, CSS, HTML, JSON, YAML, SQL, Python, Rust, C/C++, Java, shell scripts, and more
- Token-based session security — editor sessions expire after 2 hours
- Edits streamed in chunks for large files (up to 10 MB)
- Supports dot-files (`.htaccess`, `.env`, `.gitignore`, etc.)
- Backup-before-edit prompt

### Archive Management

- Create ZIP, TAR, and ANFM (custom chunked format) archives
- Extract ZIP, TAR, and ANFM archives in place
- Resume interrupted archive operations after page refresh
- Conflict detection before overwriting existing archives
- Password-protected ANFM archives
- Pre-scan folder size estimation before archive creation

### Site Backups

- Full site backup to `.tar` archive
- Phase-based processing (list → transfer/zip → wrapup) to avoid timeouts
- Concurrency control — blocks all destructive file operations during backup
- Per-file backup with rolling window (default: 5 snapshots per file)
- Backup management UI in Settings with restore and delete
- Auto-cleanup via WP-Cron (default: 7 days)

### Upload System

- Chunked uploads (configurable chunk size, 1–20 MB range)
- Resumable — interrupted uploads continue where they left off
- Upload token authentication per-file
- Parallel chunk assembly on local or remote storage
- S3 multipart upload integration
- Upload progress with phase indicator (uploading → assembling)
- File size verification after assembly with mismatch recovery dialog

### Storage Backends

| Backend | Protocol   | Notes                                                     |
| ------- | ---------- | --------------------------------------------------------- |
| Local   | Filesystem | Direct WP_Filesystem operations                           |
| FTP     | FTP/FTPS   | cURL-based, active & passive mode                         |
| SFTP    | SSH        | Dual backend: phpseclib + cURL                            |
| S3      | S3 API     | AWS S3, DigitalOcean Spaces, Wasabi, MinIO, Cloudflare R2 |

Switch between storage backends at runtime from the toolbar. Remote connections are stored with AES-256-GCM encryption.

### Background Processing

- Long operations (copy/move large folders, remote transfers, directory deletes) run as queued jobs
- Phase-based execution: Initialize → List → Transfer → Wrap-up
- Cross-storage transfer phase for moving files between different backends
- Delete phase for recursive directory removal
- Assembly + Finalize phases for chunked upload completion
- Time-bounded execution windows prevent PHP timeouts
- Job status polling with real-time progress in the status bar
- Cancel buttons for running jobs
- Concurrent-operation protection via per-user locks

### Security

- `manage_options` capability required for all operations
- Per-action WordPress nonces (list, create, delete, settings, editor)
- Multi-layer path validation: null-byte stripping → `realpath()` → root boundary → blocked-path matching
- Hardcoded immutable blocked paths (wp-admin, wp-includes, wp-config.php, .git, .env, \*.sql, and more)
- User-configurable excluded paths
- Optional file manager password gate (separate from WP login, with session persistence option)
- Optional delete-confirmation password with brute-force lockout (5 attempts → 5-minute lockout)
- Optional settings password
- Delete tokens — one-time tokens prevent accidental mass deletion
- Rate limiting: per-operation locks + minimum operation delay
- Remote storage path validation for upload destinations
- Robust MIME detection with fallback chain for safe file downloads

---

## Architecture

### Backend (PHP)

```
engine/
├── adapters/                Storage adapter implementations
│   ├── interface-filesystem-adapter.php    Adapter contract
│   ├── class-local-filesystem-adapter.php
│   ├── class-ftp-filesystem-adapter.php
│   ├── class-sftp-filesystem-adapter.php
│   ├── class-s3-filesystem-adapter.php
│   └── class-s3-client.php                Lightweight S3 client (no SDK)
├── core/
│   ├── class-ajax-handler.php             Base AJAX handler (privilege checks)
│   ├── class-anibas-file-manager-main.php Plugin bootstrap & asset enqueue
│   ├── class-storage-manager.php          Adapter registry & factory
│   ├── class-editor-ajax.php              Editor AJAX endpoints
│   ├── class-editor-page.php              Standalone editor page renderer
│   ├── class-backup-engine.php            Site backup engine
│   ├── ajax/
│   │   ├── class-file-crud-ajax-handler.php     List, create, delete, rename, download, preview
│   │   ├── class-transfer-ajax-handler.php      Copy, move, duplicate, job status, conflict check
│   │   ├── class-upload-ajax-handler.php        Chunked upload init & receive
│   │   ├── class-archive-ajax-handler.php       Archive create, restore, cancel
│   │   ├── class-trash-ajax-handler.php         Trash list, restore, empty
│   │   ├── class-backup-ajax-handler.php        Site & file backup endpoints
│   │   ├── class-auth-ajax-handler.php          Password verification & auth checks
│   │   └── class-settings-ajax-handler.php      Settings & remote storage config
│   └── archiver/
│       ├── class-archive-create-engine.php      ANFM archive writer
│       ├── class-archive-restore-engine.php     ANFM archive reader
│       ├── class-zip-create-engine.php
│       ├── class-zip-restore-engine.php
│       ├── class-tar-create-engine.php
│       └── class-tar-restore-engine.php
├── handlers/
│   └── class-background-processor.php     Job queue & worker
├── operations/
│   ├── class-phase-executor.php           Time-bounded phase runner
│   └── phases/
│       ├── class-initialize-phase.php
│       ├── class-list-phase.php
│       ├── class-transfer-phase.php
│       ├── class-cross-storage-transfer-phase.php
│       ├── class-delete-phase.php
│       ├── class-assembly-phase.php
│       ├── class-finalize-assembly-phase.php
│       └── class-wrapup-phase.php
├── partials/                              Admin page templates
│   ├── anibas-file-manager-admin-display.php
│   └── anibas-file-manager-settings.php
└── utilities/
    ├── class-activity-logger.php
    └── class-remote-storage-tester.php
```

### Frontend (Svelte 5 + TypeScript + Vite)

```
src/
├── main.ts                     App entry point
├── settings.ts                 Settings page entry point
├── editor.ts                   Standalone editor entry point
├── App.svelte                  Root component
├── Settings.svelte             Settings root
├── types.ts                    Shared type definitions
├── stores/
│   └── fileStore.svelte.ts     Global state (Svelte 5 runes)
├── services/
│   └── fileApi.ts              AJAX communication layer
├── utils/
│   ├── ChunkUploader.ts        Chunked upload orchestration
│   ├── fileIcons.ts            File-type icon mapping
│   ├── editable.ts             Editable file detection
│   ├── i18n.ts                 Internationalization wrapper
│   └── toast.ts                Toast notification manager
└── components/
    ├── Sidebar/
    │   ├── FileTree.svelte     Expandable folder tree
    │   └── TreeNode.svelte
    ├── Explorer/
    │   ├── FileExplorer.svelte Main file browser
    │   ├── FileRow.svelte      List-view row
    │   ├── GridItem.svelte     Grid-view tile
    │   ├── Toolbar.svelte      Action bar (upload, create, edit, preview, paste)
    │   ├── Breadcrumbs.svelte
    │   ├── ContextMenu.svelte
    │   ├── Statusbar.svelte    Job progress & upload status
    │   ├── PreviewPanel.svelte
    │   ├── DetailsModal.svelte File/folder metadata inspector
    │   ├── SendToModal.svelte  Cross-storage transfer dialog
    │   ├── TrashBin.svelte     Trash management dialog
    │   ├── StorageSelector.svelte
    │   ├── Pagination.svelte
    │   └── FmPasswordGate.svelte
    ├── Editor/
    │   ├── FileEditor.svelte     Standalone editor (full page)
    │   ├── InlineEditor.svelte   In-app editor panel
    │   └── editorLanguage.ts     CodeMirror language loading
    ├── Settings/
    │   ├── Settings.svelte
    │   ├── SettingsForm.svelte
    │   ├── BackupsList.svelte    Backup management UI
    │   ├── ConnectionStatus.svelte
    │   ├── PathSelector.svelte
    │   ├── PasswordPrompt.svelte
    │   └── tabs/
    │       ├── GeneralSettings.svelte
    │       ├── FTPSettings.svelte
    │       ├── SFTPSettings.svelte
    │       ├── S3Settings.svelte
    │       └── S3CompatibleSettings.svelte
    └── Shared/
        ├── BackupModal.svelte    Site backup progress/cancel dialog
        ├── Toast.svelte
        └── Loader.svelte
```

---

## Security Reference

### Hardcoded Blocked Paths

```
wp-admin/                wp-includes/             wp-config.php
.htaccess                nginx.conf               .user.ini
php.ini                  web.config               .git/
.svn/                    .hg/                     .bzr/
.env                     .env.local               .env.production
*.sql                    *.sql.gz                 wp-content/backup-db/
wp-content/backups/      error_log                debug.log
wp-content/debug.log     wp-content/plugins/anibas-file-manager/
```

### Request Validation Chain

1. WordPress nonce verification (action-specific: list, create, delete, settings, editor)
2. `manage_options` capability check
3. FM password gate check (if enabled)
4. Backup lock check — blocks destructive operations during site backup
5. Path normalization — null-byte removal, `realpath()`, manual normalization for non-existent paths
6. WordPress root boundary enforcement
7. Blocked-path pattern matching (exact + wildcard)
8. User-excluded path filtering
9. Per-user operation lock check

---

## AJAX Actions

### File CRUD (`FileCrudAjaxHandler`)

| Action                             | Description                         |
| ---------------------------------- | ----------------------------------- |
| `anibas_fm_get_file_list`          | List directory contents (paginated) |
| `anibas_fm_create_folder`          | Create a new folder                 |
| `anibas_fm_create_file`            | Create a new file with content      |
| `anibas_fm_delete_file`            | Delete file or folder (or trash)    |
| `anibas_fm_empty_folder`           | Empty folder contents               |
| `anibas_fm_rename_file`            | Rename file or folder               |
| `anibas_fm_get_file_details`       | Get file/folder metadata            |
| `anibas_fm_download_file`          | Stream file to browser              |
| `anibas_fm_preview_file`           | Stream file for inline preview      |

### Transfer (`TransferAjaxHandler`)

| Action                             | Description                         |
| ---------------------------------- | ----------------------------------- |
| `anibas_fm_transfer_file`          | Copy or move (chunked, resumable)   |
| `anibas_fm_duplicate_file`         | Duplicate file or folder            |
| `anibas_fm_job_status`             | Poll background job progress        |
| `anibas_fm_cancel_job`             | Cancel a running job                |
| `anibas_fm_check_conflict`         | Pre-check for destination conflict  |
| `anibas_fm_check_running_tasks`    | List active jobs                    |
| `anibas_fm_request_delete_token`   | Get one-time delete token           |
| `anibas_fm_resolve_size_mismatch`  | Recover from upload size mismatch   |

### Upload (`UploadAjaxHandler`)

| Action                             | Description                         |
| ---------------------------------- | ----------------------------------- |
| `anibas_fm_init_upload`            | Initialize upload, get token        |
| `anibas_fm_upload_chunk`           | Receive one upload chunk            |

### Archive (`ArchiveAjaxHandler`)

| Action                             | Description                         |
| ---------------------------------- | ----------------------------------- |
| `anibas_fm_archive_create`         | Create ZIP / TAR / ANFM archive     |
| `anibas_fm_archive_check`          | Poll archive creation progress      |
| `anibas_fm_archive_restore`        | Extract archive                     |
| `anibas_fm_cancel_archive_job`     | Cancel archive operation            |

### Trash (`TrashAjaxHandler`)

| Action                             | Description                         |
| ---------------------------------- | ----------------------------------- |
| `anibas_fm_list_trash`             | List trashed items                  |
| `anibas_fm_restore_trash`          | Restore item from trash             |
| `anibas_fm_empty_trash`            | Permanently delete all trash        |

### Backup (`BackupAjaxHandler`)

| Action                             | Description                         |
| ---------------------------------- | ----------------------------------- |
| `anibas_fm_backup_start`           | Start site backup                   |
| `anibas_fm_backup_poll`            | Poll backup progress                |
| `anibas_fm_backup_cancel`          | Cancel running backup               |
| `anibas_fm_backup_status`          | Check backup status                 |
| `anibas_fm_backup_single_file`     | Backup a single file before edit    |
| `anibas_fm_list_file_backups`      | List per-file backups               |
| `anibas_fm_restore_file_backup`    | Restore a file backup               |
| `anibas_fm_list_site_backups`      | List site backups                   |

### Editor (`EditorAjax`)

| Action                             | Description                         |
| ---------------------------------- | ----------------------------------- |
| `anibas_fm_generate_editor_token`  | Get token to open editor tab        |
| `anibas_fm_init_editor_session`    | Open editor session                 |
| `anibas_fm_get_file_chunk`         | Stream file chunk to editor         |
| `anibas_fm_save_file`              | Save edited file                    |

### Auth & Settings (`AuthAjaxHandler` + `SettingsAjaxHandler`)

| Action                             | Description                         |
| ---------------------------------- | ----------------------------------- |
| `anibas_fm_save_settings`          | Save general settings               |
| `anibas_fm_verify_password`        | Verify settings password            |
| `anibas_fm_verify_delete_password` | Verify delete password              |
| `anibas_fm_verify_fm_password`     | Verify FM gate password             |
| `anibas_fm_check_auth`             | Check settings auth status          |
| `anibas_fm_check_fm_auth`          | Check FM gate auth status           |
| `anibas_get_remote_settings`       | Get remote storage config           |
| `anibas_save_remote_settings`      | Save remote storage config          |
| `anibas_test_remote_connection`    | Test remote connection              |

---

## Configuration Constants

```php
// Paths
ANIBAS_FM_ROOT_PATH               // WordPress root (realpath of ABSPATH)

// Rate limiting
ANIBAS_FM_OPERATION_DELAY         // Min delay between operations (2 seconds)
ANIBAS_FM_LOCK_DURATION           // Per-user lock TTL (3 seconds)

// Upload & chunking
ANIBAS_FM_CHUNK_SIZE_MIN          // Minimum chunk size (1 MB)
ANIBAS_FM_CHUNK_SIZE_MAX          // Maximum chunk size (20 MB)
ANIBAS_FM_DEFAULT_CHUNK_SIZE      // Default chunk size (10 MB)
ANIBAS_FM_UPLOAD_TOKEN_EXPIRY     // Upload token lifetime (5 minutes)

// Trash
ANIBAS_FM_TRASH_DIR_NAME          // Trash directory name (.trash)
ANIBAS_FM_TRASH_MAX_AGE           // Auto-cleanup age (30 days)

// Backup
ANIBAS_FM_BACKUP_DIR_NAME         // Backup directory name (anibas-backups)
ANIBAS_FM_BACKUP_MAX_AGE          // Backup retention (7 days)
ANIBAS_FM_FILE_BACKUP_KEEP        // Per-file backup rolling window (5)

// Editor
ANIBAS_FM_EDITOR_MAX_BYTES        // Max file size for editor (10 MB)
ANIBAS_FM_EDITOR_CHUNK_BYTES      // Editor read chunk size (2 MB)
ANIBAS_FM_EDITOR_TOKEN_TTL        // Time to open editor tab (300 seconds)
ANIBAS_FM_EDITOR_SESSION_TTL      // Editor session lifetime (2 hours)
```

---

## Build & Development

### Requirements

- Node.js 18+
- npm 9+
- PHP 8.0+
- WordPress 6.0+

### Commands

```bash
npm install        # Install frontend dependencies
npm run watch      # Development — watches both app & settings groups
npm run build      # Production build → dist/
bash zip.sh        # Build + version bump + create distributable zip
```

> **Note:** `npm run dev` starts Vite's dev server which doesn't write physical files to `dist/`. Since WordPress enqueues from `dist/`, use `npm run watch` for development instead.

### Build Architecture

The Vite build uses **entry groups** to control code splitting:

| Group      | Entries                | Shared Chunks                |
| ---------- | ---------------------- | ---------------------------- |
| `app`      | `main.ts`, `editor.ts` | `codemirror-[hash].js`, `svelte-[hash].js` |
| `settings` | `settings.ts`          | None (all inlined)           |

Groups are built separately so shared code within a group becomes shared chunks (good), while code shared across groups is duplicated (intentional — avoids tiny chunks WordPress can't enqueue).

### Production Build Output

```
dist/
├── main.js                    # File manager frontend
├── main.css
├── settings.js                # Settings page frontend
├── settings.css
├── editor.js                  # Standalone editor
├── editor.css
├── codemirror-[hash].js       # Shared CodeMirror chunk
├── svelte-[hash].js           # Shared Svelte runtime chunk
└── fonts/                     # Icon fonts
```

---

## Settings Storage

| Data                     | Storage                                  |
| ------------------------ | ---------------------------------------- |
| Plugin settings          | `AnibasFileManagerOptions` WP option     |
| Remote storage config    | `anibas_fm_remote_connections` WP option (AES-256-GCM encrypted) |
| Background job queue     | `anibas_fm_job_queue_v2` WP option       |
| Archive job registry     | `anibas_fm_archive_jobs` WP option       |
| Operation locks          | WP transients (short TTL)                |
| Upload tokens            | WP transients (5-minute TTL)             |
| FM session tokens        | WP transients (per-user)                 |
| Editor tokens & sessions | WP transients                            |
| Backup lock state        | WP transients                            |
| Activity logs            | Protected directory under `wp-content/`  |
| Trash index              | `.trash/index.json` under WordPress root |

---

## License

GPL-2.0+ — See LICENSE.txt for details.

## Links

- Plugin site: https://diwakar2000.com.np/anibas-file-manager
- Author: https://diwakar2000.com.np
