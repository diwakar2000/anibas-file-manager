# Frontend fileApi Reference

**File:** `src/services/fileApi.ts` (712 lines)

All functions read config from `window.AnibasFM` (or `window.AnibasFMSettings` for settings page). This object is injected via `wp_localize_script()` in `Anibas_File_Manager_Main::enqueue_scripts()`.

## FM Session Token Management

Token stored in memory (`_fmToken`). Set after FM password verification. Auto-appended to every request.

| Function | Purpose |
|---|---|
| `setFmToken(token)` | Store token in memory |
| `getFmToken()` | Retrieve current token |
| `setFmTokenRequiredHandler(handler)` | Callback when token expires |
| `checkFmTokenError(json)` | Detects `FMTokenRequired` error, clears token, fires handler |

**Private helpers:** `appendFmToken(formData)`, `appendFmTokenToUrl(url)` — auto-attach token to requests.

---

## API Functions → AJAX Action Mapping

### File Operations
| fileApi Function | AJAX Action | HTTP | Params |
|---|---|---|---|
| `fetchNode({path, page?, storage?})` | `getFileList` | GET | dir, page, storage |
| `createFolder(parent, name, storage?)` | `createFolder` | POST | parent, name, storage |
| `deleteFile(path, token?, deleteToken?, storage?)` | `deleteFile` | POST | path, token, delete_token, storage |
| `emptyFolder(path, token?, storage?)` | `emptyFolder` | POST | path, token, storage |
| `renameFile(path, newName, storage?)` | `renameFile` | POST | path, new_name, storage |
| `duplicateFile(path, storage?)` | `duplicateFile` | POST | path, storage |
| `getDownloadUrl(path, storage)` | `downloadFile` | GET URL | path, storage (returns URL string) |
| `getPreviewContent(path, storage)` | `previewFile` | GET | path, storage |
| `getFileDetails(path, storage)` | `getFileDetails` | GET | path, storage |

### Transfer Operations
| fileApi Function | AJAX Action | HTTP | Params |
|---|---|---|---|
| `transferFile(source, dest, actionType, conflictMode, srcStorage?, destStorage?)` | `transferFile` | POST | source, destination, action_type, conflict_mode, storage OR source_storage+dest_storage |
| `checkConflict(source, dest, storage?)` | `checkConflict` | GET | source, destination, storage |
| `getJobStatus(jobId)` | `jobStatus` | GET | job_id |
| `cancelJob(jobId)` | `cancelJob` | POST | job_id |
| `checkRunningTasks()` | `checkRunningTasks` | GET | (none) |

### Archive Operations
| fileApi Function | AJAX Action | HTTP | Params |
|---|---|---|---|
| `archivePrescan(source, storage?)` | `archiveCreate` | POST | source, format=zip, phase=prescan, storage |
| `archiveCreate(source, format, phase, password?, conflictMode?, jobId?, storage?)` | `archiveCreate` | POST | source, format, phase, password, conflict_mode, job_id, storage |
| `archiveCheck(path, storage?)` | `archiveCheck` | POST | path, storage |
| `archiveRestore(path, phase, password?, storage?)` | `archiveRestore` | POST | path, phase, password, storage |
| `cancelArchiveJob(jobId)` | `cancelArchiveJob` | POST | job_id |

### Auth Operations
| fileApi Function | AJAX Action | HTTP | Params |
|---|---|---|---|
| `verifyFmPassword(password)` | `verifyFmPassword` | POST | password |
| `checkFmAuth(token)` | `checkFmAuth` | POST | token |
| `verifyDeletePassword(password)` | `verifyDeletePassword` | POST | password |
| `requestDeleteToken(path)` | `requestDeleteToken` | POST | path |

### Trash Operations
| fileApi Function | AJAX Action | HTTP | Params |
|---|---|---|---|
| `listTrash()` | `listTrash` | POST | (none) |
| `restoreTrash(trashName)` | `restoreTrash` | POST | trash_name |
| `emptyTrashBin()` | `emptyTrash` | POST | (none) |

### Backup Operations
| fileApi Function | AJAX Action | HTTP | Params |
|---|---|---|---|
| `backupSingleFile(path, storage)` | `backupSingleFile` | POST | path, storage |
| `listFileBackups()` | `listFileBackups` | POST | (none) |
| `restoreFileBackup(key, version)` | `restoreFileBackup` | POST | key, version |
| `listSiteBackups()` | `listSiteBackups` | POST | (none) |
| `backupStart(format, password?)` | `backupStart` | POST | format, password |
| `backupPoll(jobId, password?)` | `backupPoll` | POST | job_id, password |
| `backupCancel(jobId)` | `backupCancel` | POST | job_id |
| `backupStatus()` | `backupStatus` | GET | (none) |

---

## Error Handling Pattern

Every function follows the same pattern:
```ts
const res = await fetch(...)
const json = await res.json()
if (!json.success) {
    checkFmTokenError(json)  // auto-handle expired FM session
    throw new Error(json.data?.message ?? json.data?.error ?? 'Fallback message')
}
return json.data
```

## Nonce Usage

| Nonce Key | Used For |
|---|---|
| `cfg.listNonce` | Read operations (GET requests) |
| `cfg.createNonce` | Write/transfer/backup/archive ops |
| `cfg.deleteNonce` | Delete, trash, delete-password ops |
| `cfg.fmNonce` | FM password verify/check |

## `window.AnibasFM` Config Shape

Injected by `wp_localize_script()` in `Anibas_File_Manager_Main::enqueue_scripts()`:
```ts
{
  ajaxURL: string,           // admin-ajax.php URL
  actions: { ... },          // action constant values (all 40+ actions)
  listNonce: string,
  createNonce: string,
  deleteNonce: string,
  settingsNonce: string,
  fmNonce: string,
  editorNonce: string,
  editorExtensions: string[],
  editorDotfiles: string[],
  hasDeletePassword: boolean,
  fmPasswordRequired: boolean,
  fmRefreshRequired: boolean,
  pluginUrl: string,
}
```
