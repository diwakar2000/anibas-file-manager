# Anibas File Manager

A full-featured file manager for WordPress with multi-storage support, a built-in code editor, archive management, chunked uploads, and background processing for large operations.

**Version:** 0.3.0  
**Author:** Diwakar Dahal  
**License:** GPL-2.0+  
**Requires:** WordPress 5.0+, PHP 8.0+

---

## Features

### File & Folder Operations

- Browse files and folders with an expandable sidebar tree and paginated explorer
- Create, rename, copy, move, and delete files and folders
- Conflict resolution on copy/move — skip, overwrite, or auto-rename
- Download files directly from the browser
- Preview images, videos, audio, PDFs, and text files inline
- Context menu with per-item actions

### Built-in Code Editor

- Full-featured editor (CodeMirror) opens in a dedicated tab
- Syntax highlighting for PHP, JS, TS, CSS, HTML, JSON, YAML, SQL, shell scripts, and more
- Token-based session security — editor sessions expire after 2 hours
- Edits streamed in chunks for large files (up to 10 MB)
- Supports dot-files (`.htaccess`, `.env`, `.gitignore`, etc.)

### Archive Management

- Create ZIP, TAR, and ANFM (custom chunked format) archives
- Extract ZIP, TAR, and ANFM archives in place
- Resume interrupted archive operations after page refresh
- Conflict detection before overwriting existing archives
- Password-protected ANFM archives

### Upload System

- Chunked uploads (configurable chunk size, default 1 MB)
- Resumable — interrupted uploads continue where they left off
- Parallel chunk assembly on local or remote storage
- S3 multipart upload integration
- Upload progress shown in the status bar

### Storage Backends

| Backend | Protocol   | Notes                                                     |
| ------- | ---------- | --------------------------------------------------------- |
| Local   | Filesystem | Direct WP_Filesystem operations                           |
| FTP     | FTP/FTPS   | cURL-based, active & passive mode                         |
| SFTP    | SSH        | Dual backend: phpseclib + cURL                            |
| S3      | S3 API     | AWS S3, DigitalOcean Spaces, Wasabi, MinIO, Cloudflare R2 |

Switch between storage backends at runtime from the toolbar.

### Background Processing

- Long operations (copy/move large folders, remote transfers) run as queued jobs
- Four-phase execution: Initialize → List → Transfer → Wrap-up
- 10-second execution windows prevent PHP timeouts
- Job status polling with real-time progress
- Resume/cancel buttons in the status bar for interrupted jobs
- Concurrent-operation protection via per-user locks

### Security

- `manage_options` capability required
- Per-action WordPress nonces
- Multi-layer path validation: null-byte stripping → `realpath()` → root boundary → symlink rejection → blocked-path matching
- Hardcoded immutable blocked paths (wp-admin, wp-includes, wp-config.php, .git, .env, \*.sql, and more)
- User-configurable excluded paths
- Optional file manager password gate (separate from WP login)
- Optional delete-confirmation password with brute-force lockout (5 attempts → 5-minute lockout)
- Optional settings password
- Delete tokens — one-time tokens prevent accidental mass deletion
- Rate limiting: per-operation locks + minimum operation delay

---

## Architecture

### Backend (PHP)

```
engine/
├── adapters/           Storage adapter implementations
│   ├── class-local-filesystem-adapter.php
│   ├── class-ftp-filesystem-adapter.php
│   ├── class-sftp-filesystem-adapter.php
│   ├── class-s3-filesystem-adapter.php
│   └── class-s3-client.php         Lightweight S3 client (no SDK required)
├── core/
│   ├── class-ajax-handler.php      AJAX endpoint router
│   ├── class-editor-ajax.php       Editor-specific AJAX
│   ├── class-editor-page.php       Standalone editor page renderer
│   ├── class-storage-manager.php   Adapter registry & factory
│   ├── class-archive-create-engine.php   ANFM archive writer
│   ├── class-archive-restore-engine.php  ANFM archive reader
│   ├── class-zip-create-engine.php
│   ├── class-zip-restore-engine.php
│   ├── class-tar-create-engine.php
│   └── class-tar-restore-engine.php
├── handlers/
│   └── class-background-processor.php   Job queue & worker
├── operations/
│   ├── class-phase-executor.php          Time-bounded phase runner
│   └── phases/
│       ├── class-initialize-phase.php
│       ├── class-list-phase.php
│       ├── class-transfer-phase.php
│       ├── class-assembly-phase.php
│       ├── class-finalize-assembly-phase.php
│       └── class-wrapup-phase.php
└── utilities/
    ├── class-activity-logger.php
    └── class-remote-storage-tester.php
```

### Frontend (Svelte 5 + TypeScript + Vite)

```
src/
├── App.svelte                  Root component
├── stores/
│   └── fileStore.svelte.ts     Global state (Svelte 5 runes)
├── services/
│   └── fileApi.ts              AJAX communication layer
├── utils/
│   ├── ChunkUploader.ts        Chunked upload orchestration
│   ├── fileIcons.ts            File-type icon mapping
│   └── toast.ts                Toast notification manager
└── components/
    ├── Sidebar/
    │   ├── FileTree.svelte      Expandable folder tree
    │   └── TreeNode.svelte
    ├── Explorer/
    │   ├── FileExplorer.svelte  Main file browser
    │   ├── FileRow.svelte       List-view row
    │   ├── GridItem.svelte      Grid-view tile
    │   ├── Toolbar.svelte       Action bar
    │   ├── Breadcrumbs.svelte
    │   ├── ContextMenu.svelte
    │   ├── Statusbar.svelte     Job progress & upload status
    │   ├── PreviewPanel.svelte
    │   ├── StorageSelector.svelte
    │   ├── Pagination.svelte
    │   └── FmPasswordGate.svelte
    ├── Editor/
    │   ├── FileEditor.svelte    Standalone editor (full page)
    │   └── InlineEditor.svelte  In-app editor panel
    ├── Settings/
    │   ├── Settings.svelte
    │   ├── SettingsForm.svelte
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

1. WordPress nonce verification (action-specific)
2. `manage_options` capability check
3. FM password gate check (if enabled)
4. Path normalization — null-byte removal, `realpath()`, manual normalization for non-existent paths
5. WordPress root boundary enforcement
6. Symlink rejection
7. Blocked-path pattern matching (exact + wildcard)
8. Per-user operation lock check

---

## AJAX Actions

| Action                             | Description                         |
| ---------------------------------- | ----------------------------------- |
| `anibas_fm_get_file_list`          | List directory contents (paginated) |
| `anibas_fm_create_folder`          | Create a new folder                 |
| `anibas_fm_delete_file`            | Delete file or folder               |
| `anibas_fm_rename_file`            | Rename file or folder               |
| `anibas_fm_transfer_file`          | Copy or move (chunked, resumable)   |
| `anibas_fm_download_file`          | Stream file to browser              |
| `anibas_fm_preview_file`           | Stream file for inline preview      |
| `anibas_fm_upload_chunk`           | Receive one upload chunk            |
| `anibas_fm_create_file`            | Finalize assembled upload           |
| `anibas_fm_resolve_size_mismatch`  | Recover from upload size mismatch   |
| `anibas_fm_job_status`             | Poll background job progress        |
| `anibas_fm_cancel_job`             | Cancel a running job                |
| `anibas_fm_check_conflict`         | Pre-check for destination conflict  |
| `anibas_fm_check_running_tasks`    | List active jobs                    |
| `anibas_fm_request_delete_token`   | Get one-time delete token           |
| `anibas_fm_archive_create`         | Create ZIP / TAR / ANFM archive     |
| `anibas_fm_archive_check`          | Poll archive creation progress      |
| `anibas_fm_archive_restore`        | Extract archive                     |
| `anibas_fm_cancel_archive_job`     | Cancel archive operation            |
| `anibas_fm_generate_editor_token`  | Get token to open editor tab        |
| `anibas_fm_init_editor_session`    | Open editor session                 |
| `anibas_fm_get_file_chunk`         | Stream file chunk to editor         |
| `anibas_fm_save_file`              | Save edited file                    |
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
ANIBAS_FM_ROOT_PATH            // WordPress root (ABSPATH)
ANIBAS_FM_OPERATION_DELAY      // Min delay between operations (seconds)
ANIBAS_FM_LOCK_DURATION        // Per-user lock TTL (seconds)
ANIBAS_FM_DEFAULT_CHUNK_SIZE   // Upload chunk size (bytes, default 1 MB)
ANIBAS_FM_UPLOAD_TOKEN_EXPIRY  // Upload token lifetime (seconds, default 5 min)
ANIBAS_FM_EDITOR_MAX_BYTES     // Max file size for editor (default 10 MB)
ANIBAS_FM_EDITOR_CHUNK_BYTES   // Editor read chunk size (default 2 MB)
ANIBAS_FM_EDITOR_TOKEN_TTL     // Time to open editor tab (seconds)
ANIBAS_FM_EDITOR_SESSION_TTL   // Editor session lifetime (seconds, 2 hrs)
```

---

## Build & Development

### Requirements

- Node.js 18+
- npm 9+
- PHP 7.4+
- WordPress 5.0+

### Commands

```bash
npm install        # Install frontend dependencies
npm run dev        # Development server with HMR
npm run build      # Production build → dist/
bash zip.sh        # Build + version bump + create distributable zip
```

### Production Build Output

```
dist/
├── main.js        # File manager frontend
├── main.css
├── settings.js    # Settings page frontend
├── settings.css
├── editor.js      # Standalone editor
└── codemirror-[hash].js   # Shared CodeMirror chunk
```

---

## Settings Storage

| Data                     | Storage                                  |
| ------------------------ | ---------------------------------------- |
| Plugin settings          | `AnibasFileManagerOptions` WP option     |
| Remote storage config    | `anibas_fm_remote_connections` WP option |
| Background job queue     | `anibas_fm_job_queue_v2` WP option       |
| Archive job registry     | `anibas_fm_archive_jobs` WP option       |
| Operation locks          | WP transients (short TTL)                |
| Upload tokens            | WP transients (5-minute TTL)             |
| Editor tokens & sessions | WP transients                            |
| Activity logs            | Protected directory under `wp-content/`  |

---

## License

GPL-2.0+ — See LICENSE.txt for details.

## Links

- Plugin site: https://www.anibas.com
- Author: https://www.diwakardhl.com
