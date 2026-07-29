=== Anibas File Manager ===
Contributors: diwakar2000
Donate link: https://diwakar2000.com.np/
Tags: file manager, database browser, cloud storage, backups, s3
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.3.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 8.0

Advanced File Manager with local/cloud storage, backups, and an optional guarded database browser.

== Description ==

Anibas File Manager is a powerful, modern, and secure file management solution for WordPress. It allows you to manage your local filesystem, remote/cloud storage, backups, and an opt-in guarded database browser directly from your WordPress admin dashboard.

= Features =

*   **File & Folder Operations**: Browse with an expandable sidebar tree, paginated list/grid views, previews, create, rename, duplicate, copy, move, delete, and conflict resolution.
*   **Built-in Code Editor**: CodeMirror editor with syntax highlighting for PHP, JS, TS, CSS, HTML, JSON, YAML, SQL, Python, and more. Supports dot-files and chunked loading.
*   **Archive & Backup Management**: Create/extract ZIP, TAR, and ANFM archives, run full-site backups/restores, inspect backup contents, and keep rolling per-file edit backups.
*   **Optional Database Browser**: Browse current-site and multisite/network tables, inspect schema/indexes, page through rows, and optionally edit cells or add rows behind explicit database safeguards.
*   **Storage Backends**: Local filesystem, FTP/FTPS, SFTP, Amazon S3, S3-compatible storage, Google Drive, OneDrive, and Dropbox.
*   **OAuth Cloud Connections**: Google Drive, OneDrive, and Dropbox use OAuth connection flows with encrypted token storage.
*   **Live Cloud Availability**: Remote storage settings and storage pickers distinguish enabled connections from currently reachable connections, and offline providers are disabled until they reconnect.
*   **Advanced Upload System**: Chunked uploads with progress tracking, immediate worker dispatch, validated upload sessions, and provider-aware multipart support.
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
Absolutely. It supports FTP/FTPS, SFTP, Amazon S3, S3-compatible storage like DigitalOcean Spaces, Wasabi, MinIO, and Cloudflare R2, plus OAuth-backed Google Drive, OneDrive, and Dropbox. Transfers between two remote providers should go through local storage.

= Does it include database browsing? =
Yes, but it is disabled by default. Add `define('ANIBAS_FM_ENABLE_DATABASE_VIEW', true);` to `wp-config.php`, then enable database browsing from File Manager -> Settings -> Security.

= Can it edit database rows? =
Yes, when you also add `define('ANIBAS_FM_ENABLE_DATABASE_EDIT', true);` and enable row editing in Settings. Editing is guarded by database nonces, optional database password sessions, primary-key checks, protected-column rules, and backend delete blocks for users/usermeta.

= Where are backup files stored? =
Full-site backups and per-file edit backups are stored in a hidden protected directory under `wp-content/.anibas-backups-{random}`. In the UI, use File Manager -> Settings -> Backups to view file backups and full-site backup archives. Full-site restore is hidden unless `ANIBAS_FM_ENABLE_SITE_RESTORE` is enabled in wp-config.php.

= Can cloud backups be restored? =
Yes. Import a remote full-site `.anfm` backup into local backup storage first, then restore it from File Manager -> Settings -> Backups.

= How does it avoid backup and restore timeouts? =
Backup, restore, archive, database export/import, and upload assembly are split into bounded phases. Full-site ANFM packages stream encrypted JSONL manifests, database rows use JSONL row streams, and filesystem files are read through chunks/streams. Small `file_get_contents`-style reads are limited to plugin-owned metadata files under 1 MB.

= Which wp-config.php constants unlock advanced features? =
Use `ANIBAS_FM_ENABLE_DATABASE_VIEW` for the Database tab, `ANIBAS_FM_ENABLE_DATABASE_EDIT` for database edits, and `ANIBAS_FM_ENABLE_SITE_RESTORE` for full-site restore. OAuth providers can also use `ANIBAS_FM_GOOGLE_DRIVE_CLIENT_ID`, `ANIBAS_FM_GOOGLE_DRIVE_CLIENT_SECRET`, `ANIBAS_FM_ONEDRIVE_CLIENT_ID`, `ANIBAS_FM_ONEDRIVE_CLIENT_SECRET`, `ANIBAS_FM_ONEDRIVE_TENANT`, and `ANIBAS_FM_DROPBOX_APP_KEY`. Retention and limits can be tuned with `ANIBAS_FM_BACKUP_MAX_AGE`, `ANIBAS_FM_FILE_BACKUP_KEEP`, `ANIBAS_FM_EDITOR_MAX_BYTES`, `ANIBAS_FM_CHUNK_SIZE_MIN`, `ANIBAS_FM_DEFAULT_CHUNK_SIZE`, and `ANIBAS_FM_CHUNK_SIZE_MAX`.

= What is the maximum file size for the editor? =
By default, the editor supports files up to 10 MB. This can be configured via constants if your server memory allows for larger chunks.

== Third-Party Services ==

This plugin can optionally connect your site to the following third-party services when you explicitly configure and enable them under File Manager -> Settings -> Remote Storage. No data is sent to any of these services unless you turn on the corresponding integration and provide your own credentials.

* **Amazon S3 / S3-compatible storage** - file listing, upload, download, and delete requests are sent directly to the S3 endpoint you configure. [AWS Privacy Notice](https://aws.amazon.com/privacy/) / [AWS Customer Agreement](https://aws.amazon.com/agreement/)
* **Dropbox** - connects via Dropbox OAuth; file/folder operations are sent to the Dropbox API (`api.dropboxapi.com`, `content.dropboxapi.com`). [Dropbox Privacy Policy](https://www.dropbox.com/privacy) / [Dropbox Terms](https://www.dropbox.com/terms)
* **Google Drive** - connects via Google OAuth; file/folder operations are sent to the Google Drive API (`www.googleapis.com`). [Google Privacy Policy](https://policies.google.com/privacy) / [Google Terms of Service](https://policies.google.com/terms)
* **Microsoft OneDrive** - connects via Microsoft OAuth; file/folder operations are sent to the Microsoft Graph API (`graph.microsoft.com`, `login.microsoftonline.com`). [Microsoft Privacy Statement](https://privacy.microsoft.com/privacystatement) / [Microsoft Services Agreement](https://www.microsoft.com/servicesagreement)
* **FTP/FTPS and SFTP** - connects directly to the host and port you configure using credentials you provide; no Anibas-operated or other third-party server is involved.

Remote storage credentials and OAuth tokens are encrypted at rest in the WordPress database and are only transmitted to the provider you configure.

== Source Code ==

The full source code and build instructions are available at:
https://github.com/diwakar2000/anibas-file-manager

== Screenshots ==

1. The main file explorer showing the sidebar tree and file grid.
2. Backup settings with full-site backup inspection and restore controls.
3. Remote storage settings with live connection status.
4. The Database tab with schema, indexes, and paginated rows.
5. The built-in code editor with syntax highlighting for HTML and other file types.

== Changelog ==

= 1.3.0 =
* Added opt-in full-site restore with staged file/database restore, preserve-old-data choices, critical-stage cancellation rules, and overwrite fallback when staging cannot continue.
* Added searchable ANFM backup inspection with chunked indexing, tree browsing, search, and single-file downloads.
* Added cloud backup send/import flows, including remote full-site backup detection and import into local backup storage before restore.
* Improved database tools with saved table/page state, safer add-row defaults, password-expiry recovery, explicit redaction, and no destructive row-delete UI.
* Hardened backup, archive, and database streams with ANFM header/footer validation, JSONL manifests, conservative memory/disk preflight, and URL rewriting during restore.
* Hardened AJAX and security paths for raw JSON values, encrypted credential saves, editor permissions, storage-bound tokens, and upload-session validation.
* Improved remote storage reliability with live availability checks, disabled offline destinations, SFTP fallback/binary-upload fixes, bounded previews/downloads, and clearer cloud status.
* Added reusable custom dialogs for sensitive backup/restore/send flows and cleaned up PHP 8 typing/WPCS handling.

= 1.2.0 =
* Added the guarded Database tab with scoped table access, schema/index inspection, numbered pagination, and optional cell editing/add-row controls.
* Added protected ANFM full-site backup creation with database payloads, encrypted manifests, and hidden backup storage.
* Improved large-operation queues for remote pagination, archives, delete/empty-folder, upload assembly, and zero-byte files.
* Hardened delete/trash tokens, archive restore state storage, remote path confinement, and backup browsing.

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
