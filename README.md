# Anibas File Manager

A full-featured, secure, and modern file manager for WordPress. Manage local files and cloud storage, edit code, preview media, create archives, and run site backups directly from your WordPress dashboard.

**Version:** 1.1.0<br>
**Author:** Diwakar Dahal<br>
**License:** GPL-2.0+<br>
**Requires:** WordPress 6.0+, PHP 8.0+

---

## 🚀 Installation

1. Download the latest release `.zip` file from the [Releases](https://github.com/diwakar2000/anibas-file-manager/releases) page.
2. Log into your WordPress dashboard and navigate to **Plugins > Add New**.
3. Click **Upload Plugin** and select the `.zip` file.
4. Click **Install Now**, then **Activate**.
5. Navigate to the new **File Manager** menu in your dashboard to get started!

---

## ✨ Features

### What's New Since 1.0.0
- **New cloud providers:** Google Drive, OneDrive, and Dropbox support with OAuth connection flows.
- **Smarter settings:** Remote storage settings are now generated from a backend provider manifest, so new providers share the same settings and validation flow.
- **Large-folder resilience:** Remote listings, archive scans, delete jobs, and backup flows were hardened for paginated/cloud directories and long-running operations.
- **Backup organization:** Backup files now have a dedicated **Settings -> Backups** page, while backup creation remains under **Settings -> General**.
- **Security hardening:** Storage-bound delete tokens, safer trash password handling, protected backup state files, and stricter remote path confinement.

### 📁 Advanced File & Folder Operations
- **Intuitive UI:** Browse files with an expandable sidebar tree and paginated list/grid views.
- **Full Control:** Create, rename, duplicate, copy, move, and delete files or folders.
- **Conflict Resolution:** Seamlessly handle file conflicts during transfers (skip, overwrite, or auto-rename).
- **Rich Previews:** Preview images, videos, audio, PDFs, and text files inline.
- **Cross-Storage Transfers:** Move files between different storage backends (for example, Local to S3 or Dropbox to Local) using the "Send To" modal.
- **Remote Pagination:** Large remote folders are paginated in the UI and scanned incrementally by background jobs.

### 🗑️ Smart Trash System
- **Soft Delete:** Items are moved to a `.trash` directory instead of being permanently deleted.
- **Accurate Restoration:** Index-based tracking preserves original paths, ensuring items are restored exactly where they belong.
- **Auto-Cleanup:** WP-Cron automatically purges old trash items (default: 30 days).

### 📝 Built-in Code Editor (CodeMirror 6)
- **Syntax Highlighting:** Support for PHP, JS, TS, CSS, HTML, JSON, YAML, SQL, Python, Rust, C/C++, and more.
- **Large File Support:** Edits are streamed in chunks, safely supporting files up to 10 MB.
- **Security:** Token-based editor sessions expire automatically after 2 hours.
- **Dot-Files:** Full support for editing `.htaccess`, `.env`, and other hidden configuration files.

### 🗜️ Archive & Backup Management
- **Archives:** Create and extract ZIP, TAR, and custom ANFM archives directly in the browser.
- **Resumable Archive Jobs:** Archive creation and extraction run in bounded steps, with status tracking and resume/cancel controls for interrupted work.
- **Site Backups:** Generate full site backups (`.tar` or encrypted `.anfm`) with phase-based execution to prevent timeouts.
- **File Backups:** Maintain a rolling backup history for individual files (default: 5 snapshots per file).
- **Dedicated Backup Browser:** View, restore, and delete file backups from **Settings -> Backups -> Single File Backups**; view and delete full-site archives from **Full Site Backups**.
- **Protected Storage:** Backup files are stored in a hidden, protected directory under `wp-content/.anibas-backups-{random}` and are excluded from normal file-manager browsing.

### ☁️ Multi-Storage Backends
Switch between storage providers natively without leaving the WordPress dashboard:
- **Local:** Direct `WP_Filesystem` operations.
- **FTP/FTPS:** cURL-based, active & passive modes.
- **SFTP:** SSH-powered via phpseclib + cURL fallback.
- **Amazon S3:** Native S3 client with paginated listing, multipart upload, and chunked worker operations.
- **S3-Compatible:** Connect to DigitalOcean Spaces, Wasabi, MinIO, Cloudflare R2, or other S3-compatible providers.
- **Google Drive:** OAuth-backed browsing, upload, download, preview, and transfer support.
- **OneDrive:** OAuth-backed Microsoft Graph storage support.
- **Dropbox:** OAuth-backed Dropbox storage support, including folder traversal and upload sessions.

### 🚀 Resumable Chunked Uploads
- **Reliable Uploads:** Large files are uploaded in chunks (1–20 MB) with parallel assembly.
- **Resumable:** Interrupted uploads automatically continue where they left off.
- **Cloud Integration:** Remote uploads use provider-aware chunking/multipart sessions for S3, Google Drive, OneDrive, and Dropbox where supported.
- **Empty File Support:** Zero-byte files are handled consistently across local and remote storage.

### ⚙️ Asynchronous Background Processing
- **Non-blocking Operations:** Heavy tasks (large folder copies, remote syncs) run as queueable background jobs.
- **Phase-based Execution:** Operations are split into time-bounded phases (Init → List → Transfer → Wrap-up) to bypass PHP timeouts.
- **Real-time Progress:** Monitor job status and progress directly from the UI.
- **Queued Delete & Empty Folder:** Large delete, move-to-trash, and empty-folder operations are processed in bounded queue slices instead of one long request.
- **Worker Dispatch:** Upload assembly and background operations dispatch workers immediately, so jobs do not depend on a later status poll to begin.

### 🛡️ Iron-clad Security
- **Strict Capabilities:** `manage_options` check on all operations.
- **Nonces & Tokens:** Action-specific WordPress nonces, file-manager/session tokens, settings tokens, and storage-bound one-time delete tokens.
- **Path Protection:** Multi-layer validation prevents directory traversal. Hardcoded blocked paths protect critical WP files (`wp-config.php`, `.git`, etc.).
- **Remote Boundaries:** FTP, SFTP, S3-compatible, Google Drive, OneDrive, and Dropbox requests stay confined to their configured base path/root.
- **Password Gates:** Optional master password, settings lock, and delete-confirmation checks with brute-force lockout.
- **Encrypted Credentials:** Remote connection secrets and OAuth tokens are encrypted at rest with AES-256-GCM.

---

## Release Notes

### 1.1.0
- Added Google Drive, OneDrive, and Dropbox storage providers with OAuth connection, refresh, and disconnect flows.
- Added generated remote-storage settings forms backed by the PHP provider manifest.
- Improved remote pagination, cloud uploads, streaming previews/downloads, and zero-byte file handling across adapters.
- Hardened background jobs for large copy/move/delete/empty-folder/archive/backup/upload-assembly flows.
- Added the dedicated **Settings -> Backups** page for file backup history and full-site backup archives.
- Strengthened delete/trash/auth token handling, archive restore state storage, and remote path containment.

### 1.0.0
- Initial public release.

---

## 🛠️ Developer Guide

<details>
<summary><strong>Architecture Overview</strong></summary>

### Backend (PHP)
```text
engine/
├── adapters/                Storage adapter implementations
├── core/                    AJAX handlers & bootstrap
├── handlers/                Background job queue & worker
├── operations/              Time-bounded phase executors
├── partials/                Admin page templates
└── utilities/               Activity loggers & connection testers
```

### Frontend (Svelte 5 + TypeScript + Vite)
```text
src/
├── main.ts                  App entry point
├── settings.ts              Settings page entry point
├── stores/                  Global state (Svelte 5 runes)
├── services/                AJAX communication layer
├── components/              UI Components (Sidebar, Explorer, Editor, Settings)
└── utils/                   Uploader, icons, i18n
```
</details>

<details>
<summary><strong>Build & Development</strong></summary>

**Requirements:** Node.js 18+, npm 9+, PHP 8.0+, WordPress 6.0+

```bash
npm install        # Install frontend dependencies
npm run watch      # Development — watches both app & settings groups
npm run check      # Svelte and TypeScript validation
npm run build      # Production build → dist/
```

*The Vite build uses entry groups (`app` and `settings`) to control code splitting for optimal WordPress enqueueing.*
</details>

<details>
<summary><strong>Configuration Constants</strong></summary>

You can define these in `wp-config.php` to override default limits:

```php
// Rate limiting & Uploads
define('ANIBAS_FM_OPERATION_DELAY', 2);
define('ANIBAS_FM_LOCK_DURATION', 15);
define('ANIBAS_FM_CHUNK_SIZE_MIN', 1 * 1024 * 1024);
define('ANIBAS_FM_DEFAULT_CHUNK_SIZE', 10 * 1024 * 1024);
define('ANIBAS_FM_CHUNK_SIZE_MAX', 20 * 1024 * 1024);
define('ANIBAS_FM_UPLOAD_TOKEN_EXPIRY', 15 * MINUTE_IN_SECONDS);

// Listing, Trash & Backups
define('ANIBAS_FILE_MANAGER_DEFAULT_FILELIST_PAGE_SIZE', 100);
define('ANIBAS_FM_TRASH_MAX_AGE', 30 * DAY_IN_SECONDS);
define('ANIBAS_FM_BACKUP_MAX_AGE', 7 * DAY_IN_SECONDS);
define('ANIBAS_FM_FILE_BACKUP_KEEP', 5);

// Editor
define('ANIBAS_FM_EDITOR_MAX_BYTES', 10 * 1024 * 1024);

// OAuth cloud app credentials
define('ANIBAS_FM_GOOGLE_DRIVE_CLIENT_ID', '...');
define('ANIBAS_FM_GOOGLE_DRIVE_CLIENT_SECRET', '...');
define('ANIBAS_FM_ONEDRIVE_CLIENT_ID', '...');
define('ANIBAS_FM_ONEDRIVE_CLIENT_SECRET', '...');
define('ANIBAS_FM_ONEDRIVE_TENANT', 'common');
define('ANIBAS_FM_DROPBOX_APP_KEY', '...');
define('ANIBAS_FM_OAUTH_REFRESH_WINDOW', 10 * MINUTE_IN_SECONDS);
```
</details>

<details>
<summary><strong>AJAX Actions & Settings Storage</strong></summary>

Refer to the source code for a comprehensive list of registered AJAX endpoints (`engine/core/ajax/`). 

Plugin data is stored securely:
- **Settings:** Stored in the `AnibasFileManagerOptions` WP option.
- **Credentials:** Remote connections (`anibas_fm_remote_connections`) are encrypted using AES-256-GCM.
- **Job Queues:** Stored in WP options, while active operation locks use short-lived Transients.
- **Activity Logs:** Written to protected directories within `wp-content/`.
</details>

---

## 📄 License
GPL-2.0+ — See LICENSE.txt for details.

## 🔗 Links
- **Plugin site:** [diwakar2000.com.np/anibas-file-manager](https://diwakar2000.com.np/anibas-file-manager)
- **Author:** [Diwakar Dahal](https://diwakar2000.com.np)
