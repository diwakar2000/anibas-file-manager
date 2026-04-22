# Storage Adapters & Their Relation to AJAX

## Architecture Overview

```
AJAX Handler → get_storage_adapter($storage) → StorageManager (singleton) → FileSystemAdapter subclass
```

The `$storage` parameter comes from frontend requests (default: `'local'`). Valid values: `local`, `ftp`, `sftp`, `s3`, `s3_compatible`.

---

## StorageManager (`engine/core/class-storage-manager.php`)

**Namespace:** `Anibas` | **Pattern:** Singleton + lazy-loading

```php
StorageManager::get_instance()->get_adapter('local');  // returns cached adapter
StorageManager::get_instance()->has_adapter('s3');     // checks if configured
```

**Key Methods:**
- `get_instance()` — singleton access
- `get_adapter($storage)` — lazy-creates and caches adapter instances
- `has_adapter($storage)` — checks if adapter config exists
- `set_current_storage($storage)` — sets default storage
- `validate_cross_storage_transfer($src, $dest)` — ensures one side is local, remote supports chunking
- `get_cross_storage_temp_dir()` — staging dir for cross-storage transfers

**Adapter creation:** `create_adapter($storage)` reads configs from `anibas_fm_remote_connections` option (decrypted via `anibas_fm_get_remote_settings()`).

---

## Abstract Base: `FileSystemAdapter` (`engine/adapters/interface-filesystem-adapter.php`)

**Namespace:** `Anibas`

### Abstract Methods (must implement)
| Method | Signature | Purpose |
|---|---|---|
| `validate_path` | `($path)` | Resolve & validate path |
| `exists` | `($path)` | Check existence |
| `is_file` | `($path)` | Is regular file |
| `is_dir` | `($path)` | Is directory |
| `mkdir` | `($path)` | Create directory |
| `scandir` | `($path)` | List entries (names only) |
| `listDirectory` | `($path)` | List entries (with metadata) |
| `rmdir` | `($path)` | Remove directory recursively |
| `copy` | `($source, $target)` | Copy file/folder |
| `move` | `($source, $target)` | Move file/folder |
| `unlink` | `($path)` | Delete single file |
| `put_contents` | `($path, $content)` | Write file |
| `append_contents` | `($path, $content)` | Append to file |
| `get_contents` | `($path)` | Read entire file |

### Concrete Methods (overridable)
| Method | Default | Purpose |
|---|---|---|
| `delete($path)` | Trash or bg-delete for dirs, unlink for files | Smart delete routing |
| `getDetails($path)` | Basic name/path/extension | Extended metadata |
| `stream_contents($path)` | echo get_contents | Stream file to output |
| `get_temporary_link($path)` | `false` | Presigned URL (S3 only) |
| `get_size($path)` | `false` | File size |
| `is_empty($path)` | `false` | Check if dir is empty |
| `is_local_storage()` | `false` | Override in Local |
| `supports_chunked_transfer()` | `false` | Override in adapters that support it |
| `download_to_local($remote, $local)` | get_contents + file_put_contents | Download remote → local |
| `upload_from_local($local, $remote)` | file_get_contents + put_contents | Upload local → remote |
| `download_to_local_chunked(...)` | Single-shot fallback | Chunked download |
| `upload_from_local_chunked(...)` | Single-shot fallback | Chunked upload |

### Static Helpers
- `ownership_hint($path)` — POSIX owner/process user mismatch hint
- `delete_failure_reason($path)` — Human-readable failure diagnosis

---

## `LocalFileSystemAdapter` (`engine/adapters/class-local-filesystem-adapter.php`)

**Extends:** `FileSystemAdapter` | **Namespace:** `Anibas`

**Unique to Local:**
- `is_local_storage()` → `true`
- `assertAllowed($path)` — validates against blocked paths + excluded paths
- `validate_path($path)` — realpath resolution + assertAllowed
- `listDirectory($path, $page, $pageSize)` — paginated listing with file type detection
- `listFilesIterative($root)` — recursive file listing for transfers
- `frontendPathToReal($frontendPath)` — convert frontend relative path to absolute
- `moveToTrash($path)` — moves to `.trash/` with index.json ledger, cross-device safe
- `emptyFolder($path)` — empties folder contents
- `copyFileInChunks($src, $dst, $chunk_size, $bytes_copied)` — resumable chunked copy
- `getCopyProgress($src, $dst)` — check chunked copy progress
- `deleteDestination($destination)` — delete target on conflict
- `resolveNameClash($destination)` — auto-rename (e.g. `file (1).txt`)
- `scanLevel($dir)` — single-level directory scan
- `getFileTypeFromExtension($ext)` — maps extension → type category
- `getDetails($path)` — full metadata: size, permissions, owner, group, mime, created, modified

**Copy status codes:**
- `9` = `COPY_OPERATION_COMPLETE`
- `10` = `COPY_OPERATION_IN_PROGRESS`
- `1+` = error codes

---

## `FTPFileSystemAdapter` (`engine/adapters/class-ftp-filesystem-adapter.php`)

**Extends:** `FileSystemAdapter` | **Namespace:** `Anibas`

**Constructor:** `($host, $username, $password, $base_path, $use_ssl, $port, $is_passive, $insecure_ssl)`

**Key differences from Local:**
- Uses PHP `ftp_*` functions
- `validate_path()` — resolves relative to `$base_path`
- `supports_chunked_transfer()` → `false` (no range support)
- No trash support
- `listDirectory()` returns simplified metadata (no owner/group/permissions)
- `getDetails()` returns size, modified date, extension, mime

---

## `SFTPFileSystemAdapter` (`engine/adapters/class-sftp-filesystem-adapter.php`)

**Extends:** `FileSystemAdapter` | **Namespace:** `Anibas`

**Constructor:** `($host, $username, $password, $private_key, $base_path, $port)`

Uses `phpseclib3` library via Composer.

**Key differences:**
- `supports_chunked_transfer()` → `true`
- `read_chunk($path, $offset, $length)` — range-based read for editor
- `download_to_local_chunked()` / `upload_from_local_chunked()` — true chunked transfers
- `get_file_size($path)` — stat-based size
- Full `getDetails()` with permissions, owner UID/GID

---

## `S3FileSystemAdapter` (`engine/adapters/class-s3-filesystem-adapter.php`)

**Extends:** `FileSystemAdapter` | **Namespace:** `Anibas`

**Constructor:** `($s3_client, $bucket, $prefix, $chunk_size)`

Uses custom `AnibasS3Client` (no AWS SDK dependency).

**Key differences:**
- `supports_chunked_transfer()` → `true`
- `get_temporary_link($path, $duration)` — presigned S3 URL
- `upload_file($local_path, $remote_path)` — multipart upload for large files
- `download_file($remote_path, $local_path)` — chunked download
- `copyFileInChunks()` — server-side multipart copy
- `getCopyProgress()` — tracks multipart copy progress
- Paths are S3 keys (prefix-relative), dirs simulated via `/` suffix

---

## `AnibasS3Client` (`engine/adapters/class-s3-client.php`)

**Namespace:** `Anibas` | Zero-dependency S3 client using WP HTTP API

**Constructor:** `($access_key, $secret_key, $region, $endpoint, $path_style)`

**Methods:**
| Method | Purpose |
|---|---|
| `doesObjectExist($bucket, $key)` | HEAD check |
| `listObjectsV2($params)` | List objects |
| `putObject($params)` | Upload object |
| `headObject($params)` | Get object metadata |
| `copyObject($params)` | Server-side copy |
| `deleteObject($params)` | Delete object |
| `getObject($params)` | Download object |
| `getPresignedUrl($bucket, $key, $expires)` | Generate presigned URL |
| `createMultipartUpload($params)` | Start multipart |
| `uploadPart($params)` | Upload part |
| `uploadPartCopy($params)` | Copy part (server-side) |
| `completeMultipartUpload($params)` | Finalize multipart |
| `abortMultipartUpload($params)` | Cancel multipart |

Also has `S3Exception` class with `getAwsErrorCode()`.

---

## How AJAX Handlers Use Adapters

```
1. Frontend sends: { action: 'anibas_fm_get_file_list', storage: 'sftp', path: '/var/www', nonce: '...' }

2. FileCrudAjaxHandler::get_file_list()
   → $this->check_privilege()
   → $storage = request('storage', 'local')
   → $adapter = $this->get_storage_adapter($storage)  // from base AjaxHandler
   → $full_path = $adapter->validate_path($path)
   → $files = $adapter->listDirectory($full_path)
   → wp_send_json_success($files)
```

**Pattern for all AJAX handlers:**
1. Check privilege (nonce + capability + FM token + backup lock)
2. Get storage adapter via `$this->get_storage_adapter($storage)`
3. Validate path via `$adapter->validate_path($path)`
4. Call adapter method (list/copy/move/delete/etc.)
5. Return JSON response

**Cross-storage transfers** go through `BackgroundProcessor` → `PhaseExecutor` → uses both source and dest adapters.
