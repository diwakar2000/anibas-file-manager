=== Anibas File Manager ===
Contributors: diwakar2000
Donate link: https://diwakar2000.com.np/
Tags: file manager, database browser, cloud storage, backups, s3
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.2.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 8.0

Advanced File Manager with local/cloud storage, backups, and an optional guarded database browser.

== Description ==

Anibas File Manager is a powerful, modern, and secure file management solution for WordPress. It allows you to manage your local filesystem, remote/cloud storage, backups, and an opt-in guarded database browser directly from your WordPress admin dashboard.

= Features =

*   **File & Folder Operations**: Browse with an expandable sidebar tree, paginated list/grid views, previews, create, rename, duplicate, copy, move, delete, and conflict resolution.
*   **Built-in Code Editor**: CodeMirror editor with syntax highlighting for PHP, JS, TS, CSS, HTML, JSON, YAML, SQL, Python, and more. Supports dot-files and chunked loading.
*   **Archive & Backup Management**: Create/extract ZIP, TAR, and custom ANFM archives, run database + file ANFM full-site backups with streaming encrypted manifests, header/footer completeness checks, and rolling per-file edit backups.
*   **Optional Database Browser**: Browse current-site and multisite/network tables, inspect schema/indexes, page through rows, and optionally edit cells or add rows behind explicit database safeguards.
*   **Storage Backends**: Local filesystem, FTP/FTPS, SFTP, Amazon S3, S3-compatible storage, Google Drive, OneDrive, and Dropbox.
*   **OAuth Cloud Connections**: Google Drive, OneDrive, and Dropbox use OAuth connection flows with encrypted token storage.
*   **Live Cloud Availability**: Remote storage settings and storage pickers distinguish enabled connections from currently reachable connections, and offline providers are disabled until they reconnect.
*   **Advanced Upload System**: Chunked, resumable uploads with progress tracking, immediate worker dispatch, and provider-aware multipart/upload-session support.
*   **Background Processing**: Large copy, move, delete, empty-folder, archive, restore, backup, and upload-assembly operations run in conservative bounded phases. PHP timeout settings can only reduce the internal budget.
*   **Large Directory Support**: Remote/cloud listings and background scans use pagination/cursors where providers support it.
*   **Runtime Preflight**: Backup and restore check conservative PHP memory headroom and disk availability before starting, and report unknown disk availability instead of assuming it is safe.
*   **Security First**: Strict capability checks, action-specific nonces, storage-bound delete tokens, database nonces/tokens, multi-layer path validation, and protected WordPress paths.
*   **Privacy & Protection**: Optional file manager password gate, settings protection, delete-confirmation passwords, encrypted credentials, and protected hidden backup storage.

== Installation ==

1. Upload the `anibas-file-manager` folder to the `/wp-content/plugins/` directory, or install the ZIP file via the WordPress plugin dashboard.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Access the 'File Manager' menu in the admin dashboard to start managing your files.
4. (Optional) Configure remote storage, OAuth cloud providers, backups, database access, and security settings under File Manager -> Settings.

== Frequently Asked Questions ==

= Is it safe to use? =
Yes. We implement multi-layer security including path normalization, realpath validation, and a blacklist of critical WordPress files/directories that cannot be accessed or modified.

= Does it support remote storage? =
Absolutely. It supports FTP/FTPS, SFTP, Amazon S3, S3-compatible storage like DigitalOcean Spaces, Wasabi, MinIO, and Cloudflare R2, plus OAuth-backed Google Drive, OneDrive, and Dropbox.

= Does it include database browsing? =
Yes, but it is disabled by default. Add `define('ANIBAS_FM_ENABLE_DATABASE_VIEW', true);` to `wp-config.php`, then enable database browsing from File Manager -> Settings -> Security.

= Can it edit database rows? =
Yes, when you also add `define('ANIBAS_FM_ENABLE_DATABASE_EDIT', true);` and enable row editing in Settings. Editing is guarded by database nonces, optional database password sessions, primary-key checks, protected-column rules, and backend delete blocks for users/usermeta.

= Where are backup files stored? =
Full-site backups and per-file edit backups are stored in a hidden protected directory under `wp-content/.anibas-backups-{random}`. In the UI, use File Manager -> Settings -> Backups to view file backups and full-site backup archives. Full-site restore is hidden unless `ANIBAS_FM_ENABLE_SITE_RESTORE` is enabled in wp-config.php.

= How does it avoid backup and restore timeouts? =
Backup, restore, archive, database export/import, and upload assembly are split into bounded phases. Full-site ANFM packages stream encrypted JSONL manifests, database rows use JSONL row streams, and filesystem files are read through chunks/streams. Small `file_get_contents`-style reads are limited to plugin-owned metadata files under 1 MB.

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

= 1.2.0 =
* Added an opt-in Database tab with current-site and multisite/network table scopes.
* Added schema and index inspection, row estimates, bounded numbered paging, and jump-to-page controls for database tables.
* Added chunked database backup/restore with typed manifest validation, base64 JSONL row streams, keyset pagination, and staging-table restore mode.
* Made full-site backups ANFM-only, embedded the database payload in the package, and added restore package validation for extension, ANFM header metadata, EOF footer metadata, recorded package size, and encrypted manifest hash.
* Added opt-in full-site restore from the Backups page with staged archive extraction, database restore, plugin deactivation/recovery snapshot, and final wp-content/root-file swap.
* Added runtime backup/restore preflight for conservative PHP memory headroom and disk availability, with explicit admin-facing errors when disk availability cannot be determined safely.
* Updated ANFM packages to stream encrypted JSONL archive manifests, avoiding full-manifest memory loads on very large sites while preserving EOF metadata validation.
* Tightened chunking rules so filesystem files are streamed or bounded to small plugin-owned metadata files rather than loaded wholesale.
* Added guarded database cell editing and row insertion behind explicit wp-config constants, Settings toggles, nonces, optional database password sessions, and primary-key validation.
* Added metadata-aware add-row defaults for SQL defaults, current date/time fields, numeric fields, JSON/text, and enum values.
* Added explicit database redaction for `user_pass` and protected WordPress option/site-meta values such as site URLs, cron state, and rewrite rules.
* Removed destructive row-delete UI and blocked users/usermeta deletion at the backend policy layer.
* Improved database navigation persistence so the active mode, selected table, and page survive refreshes.
* Improved database password expiry recovery so the active table can continue after re-authentication without requiring a full page refresh.
* Improved remote-storage availability checks so Settings and storage pickers distinguish enabled providers from currently reachable providers.
* Prevented newly enabled or changed remote providers from being saved as active unless their live connection check passes.
* Fixed SFTP SSH-layer fallback and binary upload handling for cURL/phpseclib backends.

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
