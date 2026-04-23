=== Anibas File Manager ===
Contributors: diwakar2000
Donate link: https://diwakar2000.com.np/
Tags: file manager, editor, archive, cloud storage, s3 compatible
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 0.5.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Advanced File Manager with multi-storage support, code editor, archive management, and background processing.

== Description ==

Anibas File Manager is a powerful, modern, and secure file management solution for WordPress. It allows you to manage your local filesystem as well as remote storage (FTP, SFTP, S3) directly from your WordPress admin dashboard.

= Features =

*   **File & Folder Operations**: Browse with an expandable sidebar tree, create, rename, copy, move, and delete files/folders with conflict resolution (skip, overwrite, or auto-rename).
*   **Built-in Code Editor**: Full-featured CodeMirror editor with syntax highlighting for PHP, JS, TS, CSS, HTML, and more. Supports dot-files and chunked-loading for large files.
*   **Archive Management**: Create and extract ZIP, TAR, and custom ANFM archives. Resume interrupted archive operations.
*   **Storage Backends**: Native support for Local Filesystem, FTP/FTPS, SFTP (phpseclib & cURL), and S3-Compatible Storage (AWS S3, DigitalOcean Spaces, Wasabi, MinIO, Cloudflare R2).
*   **Advanced Upload System**: Chunked, resumable uploads with progress tracking and parallel assembly.
*   **Background Processing**: Long-running operations like large folder transfers or remote synchronization run as background jobs with real-time progress polling.
*   **Security First**: Built with strict capability checks, action-specific nonces, multi-layer path validation, and hardcoded blocked paths for mission-critical WordPress files.
*   **Privacy & Protection**: Optional file manager password gate, settings protection, and delete-confirmation passwords with brute-force protection.

== Frequently Asked Questions ==

= Is it safe to use? =
Yes. We implement multi-layer security including path normalization, realpath validation, and a blacklist of critical WordPress files/directories that cannot be accessed or modified.

= Does it support remote storage? =
Absolutely. It supports FTP, SFTP, and any S3-compatible storage like AWS, DigitalOcean Spaces, and Cloudflare R2.

= What is the maximum file size for the editor? =
By default, the editor supports files up to 10 MB. This can be configured via constants if your server memory allows for larger chunks.

== Screenshots ==

1. The main file explorer showing the sidebar tree and file grid.
2. Plugin configuration settings.
3. The built-in code editor with syntax highlighting for a HTML tags.

== Changelog ==

= 1.0 =
* Initial release.

