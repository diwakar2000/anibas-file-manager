# Frontend Reference

**Stack:** Svelte 5 + TypeScript + Vite | **Build output:** `dist/main.js`, `dist/main.css`, `dist/settings.js`, `dist/settings.css`

## Entry Points

| File | Mount Target | WP Page |
|---|---|---|
| `src/main.ts` | `#anibas-file-manager` | `admin.php?page=anibas-file-manager` |
| `src/settings.ts` | `#anibas-fm-settings-root` | `admin.php?page=anibas-file-manager-settings` |
| `src/editor.ts` | (standalone tab) | Editor window |

---

## File Manager App (`src/App.svelte`)

Root component. Renders the full file manager UI.

**Layout:** Sidebar (file tree) | Main area (toolbar + file explorer + statusbar)

**Key responsibilities:**
- FM password gate (`FmPasswordGate`)
- Storage switching
- Directory navigation
- Restore running tasks on load

---

## Component Tree

```
App.svelte
├── FmPasswordGate.svelte ........... FM password modal gate
├── Sidebar/
│   ├── FileTree.svelte ............. Sidebar folder tree wrapper
│   └── TreeNode.svelte ............. Recursive tree node
├── Explorer/
│   ├── Toolbar.svelte .............. Action toolbar (create, upload, archive, etc.)
│   ├── Breadcrumbs.svelte .......... Path breadcrumb navigation
│   ├── FileExplorer.svelte ......... Main file listing (table/grid + selection + drag & drop)
│   ├── FileRow.svelte .............. Table row for list view (51KB — complex)
│   ├── GridItem.svelte ............. Grid cell for grid view
│   ├── ContextMenu.svelte .......... Right-click context menu
│   ├── Pagination.svelte ........... Page navigation
│   ├── Statusbar.svelte ............ Bottom status bar (selection, size, bg jobs)
│   ├── StorageSelector.svelte ...... Storage type switcher (local/ftp/sftp/s3)
│   ├── PreviewPanel.svelte ......... File preview side panel
│   ├── DetailsModal.svelte ......... File details modal
│   ├── SendToModal.svelte .......... Cross-storage "Send To" modal
│   └── TrashBin.svelte ............. Trash bin viewer/restore
├── Editor/
│   ├── FileEditor.svelte ........... Full-page code editor (CodeMirror)
│   ├── InlineEditor.svelte ......... Inline/modal code editor
│   └── editorLanguage.ts ........... Extension → CodeMirror language mapping
├── Shared/
│   ├── BackupModal.svelte .......... Site backup progress modal
│   ├── Loader.svelte ............... Loading spinner
│   └── Toast.svelte ................ Toast notification component
└── Settings/ (used in settings page)
    ├── Settings.svelte ............. Settings page root
    ├── SettingsForm.svelte ......... Main settings form (36KB — large)
    ├── BackupsList.svelte .......... Site/file backup listing
    ├── ConnectionStatus.svelte ..... Remote storage connection indicator
    ├── PasswordPrompt.svelte ....... Settings password prompt
    ├── PathSelector.svelte ......... Path picker (for excluded paths, root path)
    └── tabs/
        ├── GeneralSettings.svelte
        ├── FTPSettings.svelte
        ├── SFTPSettings.svelte
        ├── S3Settings.svelte
        └── S3CompatibleSettings.svelte
```

---

## State Management: `fileStore.svelte.ts`

**File:** `src/stores/fileStore.svelte.ts` (46KB) — Svelte 5 runes-based reactive store

This is the **central state manager** for the entire file manager.

**Key state (reactive via `$state`):**
- `currentPath` — current directory path
- `files` — current directory file listing
- `selectedFiles` — multi-select tracking
- `clipboard` — cut/copy buffer
- `currentStorage` — active storage adapter name
- `isLoading`, `error` — loading/error states
- `viewMode` — `'list'` or `'grid'`
- `sortField`, `sortDirection` — current sort
- `searchQuery` — filter query
- `activeJobs` — background job tracking
- `currentPage`, `totalPages` — pagination

**Key methods:**
- `navigate(path)` — change directory
- `refresh()` — reload current directory
- `createFolder(name)` / `createFile(name)` — create items
- `deleteSelected()` — delete selected items
- `copyToClipboard()` / `cutToClipboard()` / `paste()` — clipboard ops
- `transferFiles(source, dest, action, conflictMode)` — copy/move
- `startUpload(files)` — chunked upload via `ChunkUploader`
- `setStorage(name)` — switch storage adapter
- `pollJobStatus(jobId)` — poll background job progress

---

## Utilities

| File | Purpose |
|---|---|
| `src/utils/ChunkUploader.ts` | Chunked file upload manager (init → chunk → assemble) |
| `src/utils/editable.ts` | Svelte use:action for inline-editable text |
| `src/utils/fileIcons.ts` | Extension → icon/color mapping |
| `src/utils/i18n.ts` | WordPress i18n integration (`wp.i18n.__()`) |
| `src/utils/toast.ts` | Toast notification manager (queue, auto-dismiss) |

---

## Types

**`src/types/files.ts`:**
```ts
interface FileItem {
  name: string
  path: string
  is_folder: boolean
  size: number
  last_modified: string
  extension: string
  file_type: string
  permissions?: string
}
```

**`src/types.ts`:** Additional shared types for the editor/settings.

---

## Build & Dev

```bash
npm run dev          # Vite dev server (HMR — but WP needs built files)
npm run build        # Production build → dist/
```

**Vite config** (`vite.config.ts`): Multi-entry build for `main`, `settings`, and `editor`.

**Key build outputs:**
- `dist/main.js` + `dist/main.css` — file manager page
- `dist/settings.js` + `dist/settings.css` — settings page

Scripts loaded as ES modules (`type="module"`) via `script_loader_tag` filter.
