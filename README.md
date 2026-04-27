# Anibas File Manager

A full-featured, secure, and modern file manager for WordPress. Manage local files and remote storage, edit code, create archives, and run site backups—all directly from your WordPress dashboard.

**Version:** 0.6.0  
**Author:** Diwakar Dahal  
**License:** GPL-2.0+  
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

### 📁 Advanced File & Folder Operations
- **Intuitive UI:** Browse files with an expandable sidebar tree and paginated list/grid views.
- **Full Control:** Create, rename, duplicate, copy, move, and delete files or folders.
- **Conflict Resolution:** Seamlessly handle file conflicts during transfers (skip, overwrite, or auto-rename).
- **Rich Previews:** Preview images, videos, audio, PDFs, and text files inline.
- **Cross-Storage Transfers:** Easily move files between different storage backends (e.g., Local to S3) using the "Send To" modal.

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
- **Site Backups:** Generate full site backups (`.tar`) with phase-based execution to prevent timeouts.
- **File Backups:** Maintain a rolling backup history for individual files (default: 5 snapshots per file).

### ☁️ Multi-Storage Backends
Switch between storage providers natively without leaving the WordPress dashboard:
- **Local:** Direct `WP_Filesystem` operations.
- **FTP/FTPS:** cURL-based, active & passive modes.
- **SFTP:** SSH-powered via phpseclib + cURL fallback.
- **S3-Compatible:** Connect to AWS S3, DigitalOcean Spaces, Wasabi, MinIO, or Cloudflare R2 using a lightweight, native client.

### 🚀 Resumable Chunked Uploads
- **Reliable Uploads:** Large files are uploaded in chunks (1–20 MB) with parallel assembly.
- **Resumable:** Interrupted uploads automatically continue where they left off.
- **S3 Integration:** Direct multipart upload support for cloud storage.

### ⚙️ Asynchronous Background Processing
- **Non-blocking Operations:** Heavy tasks (large folder copies, remote syncs) run as queueable background jobs.
- **Phase-based Execution:** Operations are split into time-bounded phases (Init → List → Transfer → Wrap-up) to bypass PHP timeouts.
- **Real-time Progress:** Monitor job status and progress directly from the UI.

### 🛡️ Iron-clad Security
- **Strict Capabilities:** `manage_options` check on all operations.
- **Nonces & Tokens:** Action-specific WordPress nonces and one-time delete tokens.
- **Path Protection:** Multi-layer validation prevents directory traversal. Hardcoded blocked paths protect critical WP files (`wp-config.php`, `.git`, etc.).
- **Password Gates:** Optional master password, settings lock, and delete-confirmation checks with brute-force lockout.

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
define('ANIBAS_FM_CHUNK_SIZE_MAX', 20 * 1024 * 1024);

// Trash & Backups
define('ANIBAS_FM_TRASH_MAX_AGE', 30 * DAY_IN_SECONDS);
define('ANIBAS_FM_BACKUP_MAX_AGE', 7 * DAY_IN_SECONDS);
define('ANIBAS_FM_FILE_BACKUP_KEEP', 5);

// Editor
define('ANIBAS_FM_EDITOR_MAX_BYTES', 10 * 1024 * 1024);
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
