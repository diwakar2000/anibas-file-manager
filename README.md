# Anibas File Manager

A full-featured, secure, and modern file manager for WordPress. Manage local files and cloud storage, edit code, preview media, create archives, browse guarded database tables, and run site backups directly from your WordPress dashboard.

**Version:** 1.2.0<br>
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

### What's New in 1.2.0
- **Opt-in database browser:** Browse current-site and multisite/network tables from a dedicated **Database** tab when explicitly enabled from `wp-config.php` and Settings.
- **Guarded database editing:** Edit individual cells and add rows behind database-specific nonces, optional database password sessions, primary-key checks, and protected-column rules.
- **Safer table UX:** Numbered pagination, schema/index views, refresh-persistent table/page state, metadata-aware add-row defaults, and explicit redaction for `user_pass` plus protected URL/cron/rewrite option values.
- **Cloud availability truth:** Remote storage settings now live-check availability, keep enabled/offline state separate, and prevent newly enabled or changed providers from being saved as active until the connection passes.
- **SFTP reliability fixes:** SFTP can fall back from cURL to phpseclib on SSH-layer failures, and binary uploads avoid `data://` null-byte failures.
- **Searchable backup inspection:** Full-site backups can be indexed in chunks, browsed as a tree, searched, and used to download an individual file directly from the encrypted ANFM package.

### What's New in 1.1.0
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

### 🗃️ Database Browser
- **Explicit Opt-in:** Database browsing is disabled by default and only appears after `ANIBAS_FM_ENABLE_DATABASE_VIEW` is enabled in `wp-config.php` and then enabled in Settings.
- **Scoped Browsing:** Browse the current site's tables, plus network/global tables on multisite when the admin has network permissions.
- **Schema & Index Views:** Inspect table columns, primary keys, generated/binary fields, indexes, row estimates, storage engine, and collation.
- **Numbered Pagination:** Database rows use bounded numbered paging with jump-to-page controls instead of loading large tables in one request.
- **Controlled Editing:** Optional row editing requires `ANIBAS_FM_ENABLE_DATABASE_EDIT`, a Settings toggle, database nonces, and valid primary keys.
- **Metadata-aware Inserts:** Add-row forms use SQL defaults where available and sensible date/time or numeric defaults for common column types.
- **Sensitive Data Protection:** `user_pass` and WordPress-critical option/site-meta values such as site URLs, cron state, and rewrite rules are explicitly redacted and cannot be edited from the table view.

### 🗜️ Archive & Backup Management
- **Archives:** Create and extract ZIP, TAR, and custom ANFM archives directly in the browser.
- **Resumable Archive Jobs:** Archive creation and extraction run in bounded steps, with status tracking and resume/cancel controls for interrupted work.
- **Site Backups:** Generate database + file full-site backups as ANFM packages with phase-based execution, optional password protection, streaming encrypted manifests, and top/header plus EOF/footer metadata checks before restore.
- **Runtime Preflight:** Backup and restore check conservative PHP memory headroom and disk availability before starting. If disk space cannot be determined, the operation reports that to the admin instead of assuming it is safe.
- **File Backups:** Maintain a rolling backup history for individual files (default: 5 snapshots per file).
- **Dedicated Backup Browser:** View, restore, and delete file backups from **Settings -> Backups -> Single File Backups**; inspect, search, download individual files from, and delete full-site ANFM archives from **Full Site Backups**. Full-site restore remains hidden until explicitly enabled.
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
- **Live Availability:** Settings and storage pickers show whether each remote connection is currently reachable, dim offline providers, and block sending files to unavailable destinations.

### 🚀 Resumable Chunked Uploads
- **Reliable Uploads:** Large files are uploaded in chunks (1–20 MB) with parallel assembly.
- **Resumable:** Interrupted uploads automatically continue where they left off.
- **Cloud Integration:** Remote uploads use provider-aware chunking/multipart sessions for S3, Google Drive, OneDrive, and Dropbox where supported.
- **Empty File Support:** Zero-byte files are handled consistently across local and remote storage.

### ⚙️ Asynchronous Background Processing
- **Non-blocking Operations:** Heavy tasks (large folder copies, remote syncs) run as queueable background jobs.
- **Phase-based Execution:** Operations are split into conservative, time-bounded phases (Init → List → Transfer → Wrap-up). PHP `max_execution_time` can only reduce the internal budget, never increase it.
- **Real-time Progress:** Monitor job status and progress directly from the UI.
- **Queued Delete & Empty Folder:** Large delete, move-to-trash, and empty-folder operations are processed in bounded queue slices instead of one long request.
- **Bounded Memory Reads:** Internal metadata reads are capped, archive manifests are streamed, and large file/chunk operations use `fread`/streaming paths instead of full-file reads.
- **Worker Dispatch:** Upload assembly and background operations dispatch workers immediately, so jobs do not depend on a later status poll to begin.

### 🛡️ Iron-clad Security
- **Strict Capabilities:** `manage_options` check on all operations.
- **Nonces & Tokens:** Action-specific WordPress nonces, file-manager/session tokens, settings tokens, and storage-bound one-time delete tokens.
- **Path Protection:** Multi-layer validation prevents directory traversal. Hardcoded blocked paths protect critical WP files (`wp-config.php`, `.git`, etc.).
- **Remote Boundaries:** FTP, SFTP, S3-compatible, Google Drive, OneDrive, and Dropbox requests stay confined to their configured base path/root.
- **Password Gates:** Optional master password, settings lock, and delete-confirmation checks with brute-force lockout.
- **Database Safeguards:** Optional database password sessions, explicit database enable constants, scoped table access, protected columns, and blocked user/usermeta deletion.
- **Encrypted Credentials:** Remote connection secrets and OAuth tokens are encrypted at rest with AES-256-GCM.

---

## Release Notes

### 1.2.0
- Added an opt-in Database tab with scoped table browsing, schema/index inspection, numbered pagination, and refresh-persistent table/page navigation.
- Added chunked database backup/restore with typed manifest validation, base64 JSONL row streams, keyset pagination, and staging-table restore mode.
- Made full-site backups ANFM-only, embedded the database payload in the package, and added restore package validation for extension, ANFM header metadata, EOF footer metadata, recorded package size, and encrypted manifest hash.
- Added opt-in full-site restore from the Backups page with staged archive extraction, database restore, plugin deactivation/recovery snapshot, and final wp-content/root-file swap.
- Added runtime backup/restore preflight for conservative PHP memory headroom and disk availability, with explicit admin-facing errors when disk availability cannot be determined safely.
- Updated ANFM packages to stream encrypted JSONL archive manifests, avoiding full-manifest memory loads on very large sites while keeping restore metadata validated at EOF.
- Added chunked backup inspection that builds a protected searchable manifest index, browses full-site backups as folders, searches large manifests, and streams individual files by encrypted offset/chunk metadata.
- Rewrote backed-up `home`/`siteurl` values and source URL occurrences in every non-binary restored database value, including serialized, JSON escaped-slash, and URL-encoded payloads.
- Tightened chunking rules so filesystem files are streamed or bounded to small plugin-owned metadata files rather than loaded wholesale.
- Added guarded database cell editing and row insertion behind explicit constants, settings toggles, nonces, optional database password sessions, and primary-key validation.
- Added database safety rules for explicit redaction of `user_pass` and protected WordPress option/site-meta values, while allowing normal developer debugging fields to remain visible.
- Added metadata-aware add-row defaults for SQL defaults, current date/time columns, numeric fields, JSON/text, and enum values.
- Removed destructive row-delete UI and blocked users/usermeta deletion at the backend policy layer.
- Improved database password expiry recovery so the active table can continue after re-authentication without requiring a full page refresh.
- Improved remote-storage availability checks, disabled offline destinations in storage pickers, and prevented unavailable newly enabled cloud connections from being saved as active.
- Fixed SFTP SSH-layer fallback and binary upload handling for cURL/phpseclib backends.

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
├── database/                Database browser, pagination & safety policy
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
├── components/              UI Components (Sidebar, Explorer, Editor, Database, Settings)
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

You can define these in `wp-config.php` before WordPress loads the plugin. These are the supported user-facing constants; AJAX action constants are internal and should not be overridden.

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

// Database browser and editor (disabled by default)
define('ANIBAS_FM_ENABLE_DATABASE_VIEW', true);
define('ANIBAS_FM_ENABLE_DATABASE_EDIT', true);
define('ANIBAS_FM_DATABASE_TOKEN_TTL', HOUR_IN_SECONDS);

// Full-site restore gate (disabled by default; backup creation remains available)
define('ANIBAS_FM_ENABLE_SITE_RESTORE', true);

// OAuth cloud app credentials
define('ANIBAS_FM_GOOGLE_DRIVE_CLIENT_ID', '...');
define('ANIBAS_FM_GOOGLE_DRIVE_CLIENT_SECRET', '...');
define('ANIBAS_FM_ONEDRIVE_CLIENT_ID', '...');
define('ANIBAS_FM_ONEDRIVE_CLIENT_SECRET', '...');
define('ANIBAS_FM_ONEDRIVE_TENANT', 'common');
define('ANIBAS_FM_DROPBOX_APP_KEY', '...');
define('ANIBAS_FM_OAUTH_REFRESH_WINDOW', 10 * MINUTE_IN_SECONDS);
```

`ANIBAS_FM_ENABLE_DATABASE_VIEW` only reveals the Database tab after the matching Settings toggle is also enabled. `ANIBAS_FM_ENABLE_DATABASE_EDIT` likewise requires the Database edit Settings toggle. The older lowercase aliases `anibas_enable_database_view` and `anibas_enable_database_edit` are accepted for compatibility, but the uppercase constants above are preferred.
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
