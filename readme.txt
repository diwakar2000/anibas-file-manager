=== Anibas File Manager ===
Contributors: diwakar2000
Donate link: https://diwakar2000.com.np/
Tags: file manager, cloud storage, google drive, onedrive, dropbox
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 8.0

Advanced File Manager with local, FTP/SFTP, S3-compatible, Google Drive, OneDrive, and Dropbox support.

== Description ==

Anibas File Manager is a powerful, modern, and secure file management solution for WordPress. It allows you to manage your local filesystem as well as remote and cloud storage directly from your WordPress admin dashboard.

= Features =

*   **File & Folder Operations**: Browse with an expandable sidebar tree, paginated list/grid views, previews, create, rename, duplicate, copy, move, delete, and conflict resolution.
*   **Built-in Code Editor**: CodeMirror editor with syntax highlighting for PHP, JS, TS, CSS, HTML, JSON, YAML, SQL, Python, and more. Supports dot-files and chunked loading.
*   **Archive & Backup Management**: Create/extract ZIP, TAR, and custom ANFM archives, run full-site backups, and keep rolling per-file edit backups.
*   **Storage Backends**: Local filesystem, FTP/FTPS, SFTP, Amazon S3, S3-compatible storage, Google Drive, OneDrive, and Dropbox.
*   **OAuth Cloud Connections**: Google Drive, OneDrive, and Dropbox use OAuth connection flows with encrypted token storage.
*   **Advanced Upload System**: Chunked, resumable uploads with progress tracking, immediate worker dispatch, and provider-aware multipart/upload-session support.
*   **Background Processing**: Large copy, move, delete, empty-folder, archive, restore, backup, and upload-assembly operations run in bounded background phases.
*   **Large Directory Support**: Remote/cloud listings and background scans use pagination/cursors where providers support it.
*   **Security First**: Strict capability checks, action-specific nonces, storage-bound delete tokens, multi-layer path validation, and protected WordPress paths.
*   **Privacy & Protection**: Optional file manager password gate, settings protection, delete-confirmation passwords, encrypted credentials, and protected hidden backup storage.

== Installation ==

1. Upload the `anibas-file-manager` folder to the `/wp-content/plugins/` directory, or install the ZIP file via the WordPress plugin dashboard.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Access the 'File Manager' menu in the admin dashboard to start managing your files.
4. (Optional) Configure remote storage, OAuth cloud providers, backups, and security settings under File Manager -> Settings.

== Frequently Asked Questions ==

= Is it safe to use? =
Yes. We implement multi-layer security including path normalization, realpath validation, and a blacklist of critical WordPress files/directories that cannot be accessed or modified.

= Does it support remote storage? =
Absolutely. It supports FTP/FTPS, SFTP, Amazon S3, S3-compatible storage like DigitalOcean Spaces, Wasabi, MinIO, and Cloudflare R2, plus OAuth-backed Google Drive, OneDrive, and Dropbox.

= Where are backup files stored? =
Full-site backups and per-file edit backups are stored in a hidden protected directory under `wp-content/.anibas-backups-{random}`. In the UI, use File Manager -> Settings -> Backups to view file backups and full-site backup archives.

= What is the maximum file size for the editor? =
By default, the editor supports files up to 10 MB. This can be configured via constants if your server memory allows for larger chunks.

== Source Code ==

The full source code and build instructions are available at:
https://github.com/diwakar2000/anibas-file-manager

== Screenshots ==

1. The main file explorer showing the sidebar tree and file grid.
2. Plugin configuration settings.
3. The built-in code editor with syntax highlighting for a HTML tags.

== Changelog ==

= 1.1.0 =
* Added Google Drive, OneDrive, and Dropbox storage providers with OAuth connection flows.
* Added generated remote storage settings forms powered by the backend provider manifest.
* Added OAuth token refresh/revocation handling and encrypted cloud token storage.
* Improved remote/cloud directory pagination and background scans for large folders.
* Improved S3 and S3-compatible listing, multipart upload, empty-file handling, and queued folder deletion safety.
* Improved FTP/SFTP large-directory handling and remote path confinement.
* Added a dedicated Settings -> Backups page for file backup history and full-site backup archives.
* Hardened backup, archive, restore, delete, trash, upload assembly, and remote preview/download flows against timeouts and stale state.
* Hardened delete confirmation with storage-bound tokens and fixed trash password token usage.
* Improved archive/backup manifests so large trees are processed incrementally instead of held in one request.
* Improved editor and preview race handling, including safer CodeMirror mounting and stale text-preview guards.

= 1.0.0 =
* Initial release.
