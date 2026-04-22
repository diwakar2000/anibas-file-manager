<script lang="ts">
  import { fileStore } from "../../stores/fileStore.svelte"
  import { toast } from "../../utils/toast"
  import { getFileIcon, getIconUrl } from "../../utils/fileIcons"
  import { getDownloadUrl } from "../../services/fileApi"
  import type { FileItem } from "../../types/files"
  import ContextMenu from "./ContextMenu.svelte"
  import { isEditable } from "../../utils/editable"
  import { __ } from "../../utils/i18n";
  import DetailsModal from "./DetailsModal.svelte"
  import SendToModal from "./SendToModal.svelte"

  let { file } = $props<{ file: FileItem }>()

  // Get icon URL for the file or folder
  const iconName = $derived(getFileIcon(file.name, file.is_folder))
  const iconUrl = $derived(getIconUrl(iconName))

  // Delete state
  let showPasswordDialog = $state(false)
  let showDeleteDialog = $state(false)
  let password = $state("")
  let isDeleting = $state(false)
  let deleteToken = $state<string | null>(null)

  // Archive state
  let showArchiveDialog = $state(false)
  let showZipFallbackDialog = $state(false)
  let archiveConflict = $state<{ output: string; output_size: number } | null>(null)
  let archiveConflictMode = $state<'overwrite' | 'rename'>('rename')
  let archiveFormat = $state<'zip' | 'anfm' | 'tar'>('zip')
  let archivePassword = $state("")
  let isScanning = $state(false)
  let isCreatingArchive = $state(false)
  let prescanInfo = $state<{ total: number; total_size: number; max_file_size: number; max_file_name: string } | null>(null)
  const ZIP_MAX_FILE_SIZE = 100 * 1024 * 1024 // 100 MB

  // Extract state
  let showExtractPasswordDialog = $state(false)
  let extractPassword = $state("")
  let isExtracting = $state(false)

  // Details state
  let showDetails = $state(false)

  // Send to state
  let showSendTo = $state(false)

  // Empty folder state
  let showEmptyFolderDialog = $state(false)
  let showEmptyFolderPasswordDialog = $state(false)
  let emptyFolderPassword = $state("")
  let isEmptyingFolder = $state(false)

  // Context menu state
  let showContextMenu = $state(false)
  let menuX = $state(0)
  let menuY = $state(0)

  // Inline rename state
  let renameValue = $state("")
  let renameInput = $state<HTMLInputElement | null>(null)

  $effect(() => {
    if (fileStore.renamingPath === file.path) {
      renameValue = file.name
      // Focus the input on next tick
      setTimeout(() => renameInput?.focus(), 0)
    }
  })

  // Close all local menus/dialogs when the directory or storage changes
  $effect(() => {
    fileStore.currentPath;
    fileStore.currentStorage;
    showContextMenu = false;
    showDeleteDialog = false;
    showPasswordDialog = false;
    showArchiveDialog = false;
    showExtractPasswordDialog = false;
    showEmptyFolderDialog = false;
    showEmptyFolderPasswordDialog = false;
    showZipFallbackDialog = false;
    showDetails = false;
    showSendTo = false;
    archiveConflict = null;
    deleteToken = null;
  })

  async function commitRename() {
    const trimmed = renameValue.trim()
    if (!trimmed || trimmed === file.name) { fileStore.stopRename(); return }
    try { await fileStore.renameFile(file.path, trimmed) }
    catch { /* toast shown by store */ }
  }

  function handleRenameKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter') { e.preventDefault(); commitRename() }
    else if (e.key === 'Escape') { fileStore.stopRename() }
    e.stopPropagation()
  }

  // Click handling: single click selects, double click explores/renames
  let clickTimeout: ReturnType<typeof setTimeout> | null = null
  let clickCount = 0

  function handleClick(e: MouseEvent) {
    if (fileStore.renamingPath === file.path) return

    if (e.ctrlKey || e.metaKey || e.shiftKey) {
      fileStore.selectFile(file.path, { ctrl: e.ctrlKey || e.metaKey, shift: e.shiftKey })
      return
    }

    clickCount++
    if (clickCount === 1) {
      fileStore.selectFile(file.path)
      clickTimeout = setTimeout(() => { clickCount = 0 }, 300)
    } else if (clickCount === 2) {
      if (clickTimeout) { clearTimeout(clickTimeout); clickTimeout = null }
      clickCount = 0
      if (file.is_folder) {
        if (!fileStore.isDeleting(file.path)) fileStore.navigateTo(file.path)
      } else {
        // Trigger generic open/preview action (to be handled later)
      }
    }
  }

  function triggerDownload() {
    const url = getDownloadUrl(file.path, fileStore.currentStorage)
    const a = document.createElement('a')
    a.href = url; a.setAttribute('download', '')
    document.body.appendChild(a); a.click(); document.body.removeChild(a)
  }

  // --- Drag and Drop ---
  let isDragOver = $state(false);

  function handleDragStart(e: DragEvent) {
    if (!e.dataTransfer || fileStore.renamingPath === file.path) return;
    const paths = fileStore.isSelected(file.path) ? [...fileStore.selectedPaths] : [file.path];
    e.dataTransfer.setData('application/json', JSON.stringify({ paths, action: e.altKey ? 'copy' : 'move', storage: fileStore.currentStorage }));
    e.dataTransfer.effectAllowed = 'copyMove';
  }

  function handleDragOver(e: DragEvent) {
    if (!file.is_folder || fileStore.isDeleting(file.path)) return;
    e.preventDefault();
    if (e.dataTransfer) {
      e.dataTransfer.dropEffect = e.altKey ? 'copy' : 'move';
    }
    isDragOver = true;
  }

  function handleDragLeave(e: DragEvent) {
    isDragOver = false;
  }

  async function handleDrop(e: DragEvent) {
    isDragOver = false;
    if (!file.is_folder || !e.dataTransfer) return;
    e.preventDefault();
    try {
      const data = JSON.parse(e.dataTransfer.getData('application/json'));
      if (data.paths && data.paths.length > 0) {
        if (data.paths.includes(file.path)) return; // Prevent self-drop
        
        fileStore.clipboard = { paths: data.paths, action: e.altKey ? 'copy' : data.action, storage: data.storage || fileStore.currentStorage };
        await fileStore.requestPaste(file.path);
      }
    } catch {
      // Ignore invalid payloads (like external files)
    }
  }

  function handleContextMenu(e: MouseEvent) {
    e.preventDefault()
    e.stopPropagation()
    // Select the item on right-click if not already selected
    if (!fileStore.isSelected(file.path)) {
      fileStore.selectFile(file.path, {})
    }
    menuX = e.clientX
    menuY = e.clientY
    showContextMenu = true
  }


  function getContextMenuItems() {
    const busy = Object.keys(fileStore.activeJobs).length > 0
    const archiveType = fileStore.isArchive(file)
    const items: { label?: string; icon?: string; action?: () => void; disabled?: boolean; separator?: boolean; danger?: boolean; thickSeparator?: boolean }[] = []

    // Icon SVGs (16x16 viewBox)
    const icons = {
      edit: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
      preview: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
      open: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 19a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h4l2 2h4a2 2 0 0 1 2 2v1M5 19h14a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v5a2 2 0 0 1-2 2z"/></svg>',
      emptyFolder: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
      copy: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15V4a2 2 0 0 1 2-2h9"/></svg>',
      cut: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>',
      paste: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>',
      compress: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>',
      extract: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 22h14a2 2 0 0 0 2-2V7.5L14.5 2H6a2 2 0 0 0-2 2v4"/><path d="M14 2v6h6"/><path d="M2 15v-3h6v3"/><path d="M2 10v3h3v-3"/><path d="M5 10V8a2 2 0 0 1 4 0v2"/></svg>',
      rename: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5"/></svg>',
      download: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
      delete: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
      details: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    }

    // "Edit" / "Preview" appear first for files
    if (!file.is_folder) {
      if (isEditable(file)) {
        items.push({ label: __('Edit'), icon: icons.edit, action: () => fileStore.openEditor(file.path, true) })
      }
      items.push({ label: __('Preview'), icon: icons.preview, action: () => { fileStore.selectedPaths = [file.path]; fileStore.previewOpen = true } })
      items.push({ separator: true })
    }

    if (file.is_folder) {
      items.push({ label: __('Open'), icon: icons.open, action: () => fileStore.navigateTo(file.path) })
      items.push({ label: __('Empty Folder'), icon: icons.emptyFolder, action: () => (showEmptyFolderDialog = true), disabled: busy })
      items.push({ separator: true })
    }

    items.push(
      { label: __('Copy'), icon: icons.copy, action: () => { fileStore.copyToClipboard([file.path]); toast.info(`"${file.name}" copied to clipboard`) }, disabled: busy },
      { label: __('Cut'), icon: icons.cut, action: () => { fileStore.cutToClipboard([file.path]); toast.info(`"${file.name}" cut to clipboard`) }, disabled: busy },
      { label: __('Duplicate'), icon: icons.copy, action: () => handleDuplicate(), disabled: busy },
    )

    if (fileStore.clipboard) {
      items.push(
        { label: `${__('Paste')} (${fileStore.clipboard.action === 'copy' ? __('Copy') : __('Move')})`, icon: icons.paste, action: () => handlePasteHere(), disabled: busy || !file.is_folder },
      )
    }

    items.push({ separator: true })

    if (file.is_folder) {
      items.push({ label: isScanning ? __('Scanning...') : __('Compress'), icon: icons.compress, action: () => openCompressDialog(), disabled: busy || isScanning })
    }

    if (archiveType) {
      items.push({ label: __('Extract'), icon: icons.extract, action: () => handleExtract(), disabled: busy })
    }

    items.push({ separator: true })
    items.push({ label: __('Rename'), icon: icons.rename, action: () => fileStore.startRename(file.path), disabled: busy })
    if (!file.is_folder) {
      items.push({ label: __('Download'), icon: icons.download, action: () => triggerDownload() })
    }
    items.push({ separator: true })
    items.push({ label: __('Send to...'), icon: '📤', action: () => (showSendTo = true) })
    items.push({ separator: true })
    items.push({ label: __('Details'), icon: icons.details, action: () => (showDetails = true) })
    items.push({ thickSeparator: true })
    items.push({ label: __('Delete'), icon: icons.delete, action: () => handleDelete(), disabled: busy, danger: true })

    return items
  }

  // --- Paste into folder ---
  async function handlePasteHere() {
    if (!file.is_folder || !fileStore.clipboard) return
    try {
      await fileStore.paste(file.path, 'skip')
    } catch { /* toast already shown */ }
  }

  // --- Duplicate ---
  async function handleDuplicate() {
    try {
      await fileStore.duplicate(file.path)
    } catch { /* toast already shown */ }
  }

  // --- Delete flow ---
  async function handleDelete() {
    if (fileStore.isDeleting(file.path)) return
    try {
      deleteToken = await fileStore.requestDeleteToken(file.path)
      showDeleteDialog = true
    } catch (err: any) {
      toast.error(err.message || "Failed to initiate delete")
    }
  }

  async function confirmDelete() {
    if (!deleteToken) return
    fileStore.deletingPaths = [...fileStore.deletingPaths, file.path]
    showDeleteDialog = false
    try {
      await fileStore.deleteFile(file.path, deleteToken)
      deleteToken = null
    } catch (err: any) {
      fileStore.deletingPaths = fileStore.deletingPaths.filter(p => p !== file.path)
      if (err.message === "DeletePasswordRequired") {
        showPasswordDialog = true
      } else if (err.message === "DeleteTokenExpired") {
        toast.error("Delete confirmation expired. Please try again.")
      } else {
        toast.error(err.message || "Failed to delete")
      }
    }
  }

  function cancelDelete() {
    showDeleteDialog = false
    deleteToken = null
  }

  async function handlePasswordSubmit() {
    if (!password.trim() || !deleteToken) return
    isDeleting = true
    try {
      await fileStore.verifyDeletePassword(password)
      await fileStore.deleteFile(file.path, deleteToken)
      showPasswordDialog = false
      password = ""
      deleteToken = null
    } catch (err: any) {
      if (err.message === "DeleteTokenExpired") {
        toast.error("Delete confirmation expired. Please try again.")
        showPasswordDialog = false
      } else {
        toast.error(err.message || "Invalid password")
      }
    } finally {
      isDeleting = false
    }
  }

  // --- Empty folder flow ---
  async function confirmEmptyFolder() {
    showEmptyFolderDialog = false
    try {
      await fileStore.emptyFolder(file.path)
    } catch (err: any) {
      if (err.message === "DeletePasswordRequired") {
        showEmptyFolderPasswordDialog = true
      } else {
        toast.error(err.message || "Failed to empty folder")
      }
    }
  }

  async function handleEmptyFolderPasswordSubmit() {
    if (!emptyFolderPassword.trim()) return
    isEmptyingFolder = true
    try {
      await fileStore.verifyDeletePassword(emptyFolderPassword)
      await fileStore.emptyFolder(file.path)
      showEmptyFolderPasswordDialog = false
      emptyFolderPassword = ""
    } catch (err: any) {
      toast.error(err.message || "Invalid password")
    } finally {
      isEmptyingFolder = false
    }
  }

  // --- Compress flow ---
  async function openCompressDialog() {
    isScanning = true
    try {
      const info = await fileStore.prescanFolder(file.path)
      prescanInfo = info
      // Default to tar if any file exceeds ZIP threshold
      archiveFormat = info.max_file_size > ZIP_MAX_FILE_SIZE ? 'tar' : 'zip'
      archivePassword = ""
      showArchiveDialog = true
    } catch { /* toast already shown */ }
    finally { isScanning = false }
  }

  async function confirmCompress(conflictMode?: 'overwrite' | 'rename') {
    isCreatingArchive = true
    try {
      await fileStore.startArchiveCreate(
        file.path,
        archiveFormat,
        archiveFormat === 'anfm' && archivePassword.trim() ? archivePassword.trim() : undefined,
        conflictMode,
      )
      showArchiveDialog = false
      archiveConflict = null
    } catch (err: any) {
      if (err.message === 'ArchiveConflict') {
        archiveConflict = { output: err.output, output_size: err.output_size }
        archiveConflictMode = 'rename'
        // keep archive dialog open to show conflict section
      } else if (err.message === 'ZipConnectionTimeout') {
        showArchiveDialog = false
        archiveConflict = null
        archiveFormat = 'tar'
        archivePassword = ""
        showZipFallbackDialog = true
      }
      // other errors already toasted by store
    } finally { isCreatingArchive = false }
  }

  async function confirmCompressConflict() {
    archiveConflict = null
    await confirmCompress(archiveConflictMode)
  }

  async function confirmZipFallback() {
    isCreatingArchive = true
    try {
      await fileStore.startArchiveCreate(
        file.path,
        archiveFormat,
        archiveFormat === 'anfm' && archivePassword.trim() ? archivePassword.trim() : undefined
      )
      showZipFallbackDialog = false
    } catch { /* toast already shown */ }
    finally { isCreatingArchive = false }
  }

  // --- Extract flow ---
  async function handleExtract() {
    try {
      const info = await fileStore.checkArchive(file.path)
      if (!info.valid) {
        toast.error(info.reason || 'This file is not a valid archive — it may be corrupted.')
        return
      }
      if (info.password_protected) {
        extractPassword = ""
        showExtractPasswordDialog = true
      } else {
        await fileStore.startArchiveRestore(file.path)
      }
    } catch { /* toast already shown */ }
  }

  async function confirmExtractWithPassword() {
    if (!extractPassword.trim()) return
    isExtracting = true
    showExtractPasswordDialog = false
    try {
      await fileStore.startArchiveRestore(file.path, extractPassword.trim())
    } catch { /* toast already shown */ }
    finally {
      isExtracting = false
      extractPassword = ""
    }
  }

  function formatSize(bytes?: number) {
    if (bytes === undefined) return ""
    if (bytes === 0) return "0 B"
    const k = 1024
    const sizes = ["B", "KB", "MB", "GB", "TB"]
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + " " + sizes[i]
  }

  function formatDate(timestamp?: number) {
    if (!timestamp) return ""
    return new Date(timestamp * 1000).toLocaleString(undefined, {
      year: "numeric",
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit"
    })
  }

  function formatPermissions(mode?: number): string {
    if (!mode) return "--"
    const perms = mode & 0o777
    let str = ""
    for (let i = 2; i >= 0; i--) {
      str += (perms >> (i * 3)) & 4 ? "r" : "-"
      str += (perms >> (i * 3)) & 2 ? "w" : "-"
      str += (perms >> (i * 3)) & 1 ? "x" : "-"
    }
    return `${str} (${perms.toString(8).padStart(3, "0")})`
  }
</script>

<!-- svelte-ignore a11y_no_static_element_interactions -->
<div
  class="file-row"
  class:is-folder={file.is_folder}
  class:is-selected={fileStore.isSelected(file.path)}
  class:is-deleting={fileStore.isDeleting(file.path)}
  class:is-renaming={fileStore.renamingPath === file.path}
  class:is-drag-over={isDragOver}
  onclick={handleClick}
  oncontextmenu={handleContextMenu}
  onkeydown={(e) => e.key === "Enter" && file.is_folder && fileStore.navigateTo(file.path)}
  draggable={fileStore.renamingPath !== file.path}
  ondragstart={handleDragStart}
  ondragover={handleDragOver}
  ondragleave={handleDragLeave}
  ondrop={handleDrop}
  role="row"
  tabindex="0"
>
  <div class="col-check">
    <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
    <input
      type="checkbox"
      class="row-checkbox"
      checked={fileStore.isSelected(file.path)}
      onclick={(e) => { e.stopPropagation(); fileStore.selectFile(file.path, { ctrl: true }) }}
    />
  </div>
  <div class="col col-name">
    <img class="icon" src={iconUrl} alt={file.is_folder ? "Folder" : "File"} />
    {#if fileStore.renamingPath === file.path}
      <!-- svelte-ignore a11y_autofocus -->
      <input
        class="rename-input"
        type="text"
        bind:value={renameValue}
        bind:this={renameInput}
        onkeydown={handleRenameKeydown}
        onblur={commitRename}
        onclick={(e) => e.stopPropagation()}
        autofocus
      />
    {:else}
      <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
      <span class="name-text" ondblclick={(e) => { e.stopPropagation(); fileStore.startRename(file.path) }}>{file.name}</span>
    {/if}
  </div>
  <div class="col col-size">
    {file.is_folder ? "--" : formatSize(file.filesize)}
  </div>
  <div class="col col-type">
    {file.is_folder ? "Folder" : (file.file_type || "File")}
  </div>
  <div class="col col-modified">
    {formatDate(file.last_modified)}
  </div>
  <div class="col col-permissions">
    {formatPermissions(file.permission)}
  </div>
</div>

{#if showContextMenu}
  <ContextMenu
    items={getContextMenuItems()}
    x={menuX}
    y={menuY}
    onclose={() => showContextMenu = false}
  />
{/if}

{#if isScanning}
  <div class="loading-overlay">
    <div class="loading-spinner"></div>
    <p>Scanning folder...</p>
  </div>
{/if}

{#if showArchiveDialog}
  <div
    class="modal-overlay"
    onclick={() => showArchiveDialog = false}
    onkeydown={(e) => e?.key === "Escape" && (showArchiveDialog = false)}
    role="button"
    tabindex="-1"
    aria-label="Close dialog"
  >
    <div
      class="modal-content"
      onclick={(e) => e.stopPropagation()}
      onkeydown={(e) => e.stopPropagation()}
      role="button"
      tabindex="0"
    >
      <h3>Create Archive</h3>
      <p>Choose archive format for <strong>{file.name}</strong></p>

      {#if prescanInfo}
        <div class="prescan-stats">
          <small>{prescanInfo.total.toLocaleString()} files &middot; {formatSize(prescanInfo.total_size)} total &middot; largest file: {formatSize(prescanInfo.max_file_size)}</small>
        </div>
      {/if}

      {#if prescanInfo && prescanInfo.max_file_size > ZIP_MAX_FILE_SIZE}
        <div class="zip-warn">
          <small><strong>ZIP not available.</strong> This folder contains a file larger than 100 MB (<em>{prescanInfo.max_file_name}</em> — {formatSize(prescanInfo.max_file_size)}). Use TAR or Anibas Archive instead.</small>
        </div>
      {/if}

      <div class="format-options">
        <label class="format-option" class:disabled-option={prescanInfo !== null && prescanInfo.max_file_size > ZIP_MAX_FILE_SIZE}>
          <input type="radio" bind:group={archiveFormat} value="zip" disabled={prescanInfo !== null && prescanInfo.max_file_size > ZIP_MAX_FILE_SIZE} />
          <span><strong>ZIP</strong> — Standard format, works everywhere</span>
        </label>
        <label class="format-option">
          <input type="radio" bind:group={archiveFormat} value="tar" />
          <span><strong>TAR</strong> — Universal format, supports large files (macOS, Linux, Windows)</span>
        </label>
        <label class="format-option">
          <input type="radio" bind:group={archiveFormat} value="anfm" />
          <span><strong>Anibas Archive (.anfm)</strong> — Encrypted, supports large files<br><small class="format-plugin-warn">Only extractable with the Anibas File Manager plugin</small></span>
        </label>
      </div>

      {#if archiveFormat === 'anfm'}
        <div class="anfm-note">
          <small>You can optionally set a password for additional security.</small>
        </div>
        <input
          type="password"
          bind:value={archivePassword}
          placeholder="Password (optional)"
        />
      {/if}

      {#if archiveConflict}
        <div class="conflict-section">
          <p class="conflict-title">⚠ <strong>{archiveConflict.output}</strong> already exists ({formatSize(archiveConflict.output_size)})</p>
          <div class="format-options">
            <label class="format-option">
              <input type="radio" bind:group={archiveConflictMode} value="rename" />
              <span>Create with a new name (e.g. <em>{archiveConflict.output.replace(/(\.\w+)$/, ' (1)$1')}</em>)</span>
            </label>
            <label class="format-option">
              <input type="radio" bind:group={archiveConflictMode} value="overwrite" />
              <span>Overwrite existing file</span>
            </label>
          </div>
        </div>
      {/if}

      <div class="modal-actions">
        <button class="btn btn-secondary" onclick={() => { showArchiveDialog = false; archiveConflict = null }} disabled={isCreatingArchive}>Cancel</button>
        {#if archiveConflict}
          <button class="btn btn-primary" onclick={confirmCompressConflict} disabled={isCreatingArchive}>
            {isCreatingArchive ? 'Creating...' : 'Continue'}
          </button>
        {:else}
          <button class="btn btn-primary" onclick={() => confirmCompress()} disabled={isCreatingArchive}>
            {isCreatingArchive ? 'Creating...' : 'Create Archive'}
          </button>
        {/if}
      </div>
    </div>
  </div>
{/if}

{#if showZipFallbackDialog}
  <div
    class="modal-overlay"
    onclick={() => showZipFallbackDialog = false}
    onkeydown={(e) => e?.key === "Escape" && (showZipFallbackDialog = false)}
    role="button"
    tabindex="-1"
    aria-label="Close dialog"
  >
    <div
      class="modal-content"
      onclick={(e) => e.stopPropagation()}
      onkeydown={(e) => e.stopPropagation()}
      role="button"
      tabindex="0"
    >
      <h3>ZIP Connection Timeout</h3>
      <p>The ZIP archive timed out and the partial file has been removed. Choose an alternative format for <strong>{file.name}</strong>:</p>

      <div class="format-options">
        <label class="format-option">
          <input type="radio" bind:group={archiveFormat} value="tar" />
          <span><strong>TAR</strong> — Universal format, supports large files (macOS, Linux, Windows)</span>
        </label>
        <label class="format-option">
          <input type="radio" bind:group={archiveFormat} value="anfm" />
          <span><strong>Anibas Archive (.anfm)</strong> — Encrypted, supports large files<br><small class="format-plugin-warn">Only extractable with the Anibas File Manager plugin</small></span>
        </label>
      </div>

      {#if archiveFormat === 'anfm'}
        <div class="anfm-note">
          <small>You can optionally set a password.</small>
        </div>
        <input
          type="password"
          bind:value={archivePassword}
          placeholder="Password (optional)"
        />
      {/if}

      <div class="modal-actions">
        <button class="btn btn-secondary" onclick={() => showZipFallbackDialog = false} disabled={isCreatingArchive}>Cancel</button>
        <button class="btn btn-primary" onclick={confirmZipFallback} disabled={isCreatingArchive}>
          {isCreatingArchive ? 'Creating...' : 'Create Archive'}
        </button>
      </div>
    </div>
  </div>
{/if}

{#if showExtractPasswordDialog}
  <div
    class="modal-overlay"
    onclick={() => showExtractPasswordDialog = false}
    onkeydown={(e) => e?.key === "Escape" && (showExtractPasswordDialog = false)}
    role="button"
    tabindex="-1"
    aria-label="Close dialog"
  >
    <div
      class="modal-content"
      onclick={(e) => e.stopPropagation()}
      onkeydown={(e) => e.stopPropagation()}
      role="button"
      tabindex="0"
    >
      <h3>Password Required</h3>
      <p>This archive is password-protected.</p>
      <input
        type="password"
        bind:value={extractPassword}
        placeholder="Enter archive password"
        onkeydown={(e) => e.key === "Enter" && confirmExtractWithPassword()}
      />
      <div class="modal-actions">
        <button class="btn btn-secondary" onclick={() => showExtractPasswordDialog = false}>Cancel</button>
        <button class="btn btn-primary" onclick={confirmExtractWithPassword} disabled={isExtracting || !extractPassword.trim()}>
          {isExtracting ? "Extracting..." : "Extract"}
        </button>
      </div>
    </div>
  </div>
{/if}

{#if showDeleteDialog}
  <div 
    class="modal-overlay" 
    onclick={cancelDelete}
    onkeydown={(e) => e?.key === "Escape" && cancelDelete()}
    role="button"
    tabindex="-1"
    aria-label="Close dialog"
  >
    <div 
      class="modal-content" 
      onclick={(e) => e.stopPropagation()}
      onkeydown={(e) => e.stopPropagation()}
      role="button"
      tabindex="0"
    >
      <h3>Confirm Delete</h3>
      <p>Are you sure you want to delete this {file.is_folder ? "folder" : "file"}?</p>
      <p class="delete-name">{file.name}</p>
      <div class="modal-actions">
        <button class="btn btn-secondary" onclick={cancelDelete}>Cancel</button>
        <button class="btn btn-danger" onclick={confirmDelete}>
          Delete
        </button>
      </div>
    </div>
  </div>
{/if}

{#if showEmptyFolderDialog}
  <div class="modal-overlay" onclick={() => showEmptyFolderDialog = false} onkeydown={(e) => e?.key === "Escape" && (showEmptyFolderDialog = false)} role="button" tabindex="-1" aria-label="Close">
    <div class="modal-content" onclick={(e) => e.stopPropagation()} onkeydown={(e) => e.stopPropagation()} role="button" tabindex="0">
      <h3>Empty Folder</h3>
      <p>Delete all contents of <strong>"{file.name}"</strong>? The folder itself will be kept.</p>
      <div class="modal-actions">
        <button class="btn btn-secondary" onclick={() => showEmptyFolderDialog = false}>Cancel</button>
        <button class="btn btn-danger" onclick={confirmEmptyFolder}>Empty</button>
      </div>
    </div>
  </div>
{/if}

{#if showEmptyFolderPasswordDialog}
  <div class="modal-overlay" onclick={() => { showEmptyFolderPasswordDialog = false; emptyFolderPassword = "" }} onkeydown={(e) => e?.key === "Escape" && (showEmptyFolderPasswordDialog = false)} role="button" tabindex="-1" aria-label="Close">
    <div class="modal-content" onclick={(e) => e.stopPropagation()} onkeydown={(e) => e.stopPropagation()} role="button" tabindex="0">
      <h3>Delete Password Required</h3>
      <p>Enter the delete password to empty <strong>"{file.name}"</strong>.</p>
      <input type="password" bind:value={emptyFolderPassword} placeholder="Delete password" class="modal-input" onkeydown={(e) => e?.key === "Enter" && handleEmptyFolderPasswordSubmit()} />
      <div class="modal-actions">
        <button class="btn btn-secondary" onclick={() => { showEmptyFolderPasswordDialog = false; emptyFolderPassword = "" }}>Cancel</button>
        <button class="btn btn-danger" onclick={handleEmptyFolderPasswordSubmit} disabled={isEmptyingFolder || !emptyFolderPassword.trim()}>
          {isEmptyingFolder ? "Verifying..." : "Confirm"}
        </button>
      </div>
    </div>
  </div>
{/if}

{#if showPasswordDialog}
  <div 
    class="modal-overlay" 
    onclick={() => showPasswordDialog = false}
    onkeydown={(e) => e?.key === "Escape" && (showPasswordDialog = false)}
    role="button"
    tabindex="-1"
    aria-label="Close dialog"
  >
    <div 
      class="modal-content" 
      onclick={(e) => e.stopPropagation()}
      onkeydown={(e) => e.stopPropagation()}
      role="button"
      tabindex="0"
    >
      <h3>Delete Password Required</h3>
      <input 
        type="password" 
        bind:value={password}
        placeholder="Enter delete password"
        onkeydown={(e) => e.key === "Enter" && handlePasswordSubmit()}
      />
      <div class="modal-actions">
        <button class="btn btn-secondary" onclick={() => showPasswordDialog = false}>Cancel</button>
        <button class="btn btn-danger" onclick={handlePasswordSubmit} disabled={isDeleting || !password.trim()}>
          {isDeleting ? "Deleting..." : "Delete"}
        </button>
      </div>
    </div>
  </div>
{/if}

{#if showDetails}
  <DetailsModal {file} onclose={() => (showDetails = false)} />
{/if}

{#if showSendTo}
  <SendToModal file={file} onclose={() => (showSendTo = false)} />
{/if}

<style>
  .file-row {
    display: flex;
    padding: 10px 5px 10px 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 13px;
    cursor: default;
    transition: background 0.1s;
    align-items: center;
    user-select: none;
  }

  .col-check {
    width: 28px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px 0 8px;
  }

  .row-checkbox {
    opacity: 0;
    width: 14px;
    height: 14px;
    cursor: pointer;
    flex-shrink: 0;
    transition: opacity 0.1s;
  }

  .file-row:hover .row-checkbox,
  .file-row.is-selected .row-checkbox {
    opacity: 1;
  }

  .rename-input {
    flex: 1;
    padding: 2px 6px;
    border: 1px solid #2271b1;
    border-radius: 3px;
    font-size: 13px;
    font-weight: 500;
    outline: none;
    box-shadow: 0 0 0 1px #2271b1;
    min-width: 0;
  }

  .file-row:hover {
    background: #f8f9fa;
  }

  .file-row.is-drag-over {
    background: #e6f7ff;
    outline: 2px dashed #1890ff;
    outline-offset: -2px;
  }

  .file-row.is-selected {
    background-color: #e7f3ff;
    outline: 1px solid #2271b1;
  }

  .file-row.is-folder {
    color: #2271b1;
    cursor: pointer;
  }

  .col {
    padding: 0 10px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .col-name {
    flex: 4;
    display: flex;
    align-items: center;
  }

  .col-size { flex: 1; text-align: right; color: #666; }
  .col-type { flex: 1.5; color: #666; }
  .col-modified { flex: 2; color: #666; }
  .col-permissions { flex: 1.5; color: #666; font-family: ui-monospace, monospace; font-size: 11px; }

  .icon {
    margin-right: 10px;
    width: 20px;
    height: 20px;
    object-fit: contain;
  }

  .name-text {
      font-weight: 500;
  }

  .is-folder .name-text {
      font-weight: 600;
  }

  .is-deleting {
    opacity: 0.5;
    pointer-events: none;
  }

  .modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100000;
  }

  .modal-content {
    background: white;
    padding: 24px;
    border-radius: 4px;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
  }

  .modal-content h3 {
    margin: 0 0 16px 0;
    font-size: 16px;
    font-weight: 600;
  }

  .modal-content input:not([type="radio"]):not([type="checkbox"]) {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    font-size: 13px;
    margin-bottom: 16px;
    box-sizing: border-box;
  }

  .modal-content input:not([type="radio"]):not([type="checkbox"]):focus {
    outline: none;
    border-color: #2271b1;
  }

  .modal-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
  }

  .btn {
    padding: 6px 12px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
  }

  .btn-secondary {
    background: #f0f0f0;
    color: #333;
  }

  .btn-secondary:hover {
    background: #e0e0e0;
  }

  .btn-danger {
    background: #dc3545;
    color: white;
  }

  .btn-danger:hover {
    background: #c82333;
  }

  .btn-danger:disabled {
    background: #ccc;
    cursor: not-allowed;
  }

  .delete-name {
    font-weight: 600;
    color: #333;
    word-break: break-all;
  }

  .btn-primary {
    background: #2271b1;
    color: white;
  }

  .btn-primary:hover {
    background: #135e96;
  }

  .btn-primary:disabled {
    background: #ccc;
    cursor: not-allowed;
  }

  .format-options {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 12px 0;
  }

  .format-option {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    cursor: pointer;
    font-size: 13px;
    line-height: 1.4;
    width: fit-content;
  }

  .format-option input[type="radio"] {
    margin-top: 3px;
  }

  .anfm-note {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 3px;
    padding: 8px 10px;
    margin-bottom: 12px;
    color: #664d03;
  }

  .anfm-note small {
    font-size: 12px;
    line-height: 1.4;
  }

  .format-plugin-warn {
    display: block;
    margin-top: 2px;
    color: #805700;
    font-size: 11px;
  }

  .prescan-stats {
    background: #f0f4f8;
    border: 1px solid #d0d7de;
    border-radius: 3px;
    padding: 6px 10px;
    margin-bottom: 12px;
    color: #444;
  }

  .prescan-stats small {
    font-size: 12px;
  }

  .conflict-section {
    background: #fff8e1;
    border: 1px solid #f5c518;
    border-radius: 3px;
    padding: 10px 12px;
    margin-bottom: 12px;
  }

  .conflict-title {
    margin: 0 0 8px;
    font-size: 12px;
    color: #5a3e00;
  }

  .zip-warn {
    background: #fde8e8;
    border: 1px solid #f5c2c2;
    border-radius: 3px;
    padding: 8px 10px;
    margin-bottom: 12px;
    color: #922;
  }

  .zip-warn small {
    font-size: 12px;
    line-height: 1.4;
  }

  .disabled-option {
    opacity: 0.45;
    cursor: not-allowed;
  }

  .disabled-option input[type="radio"] {
    cursor: not-allowed;
  }

  .loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.9);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 100001;
    gap: 12px;
  }

  .loading-overlay p {
    color: #666;
    font-size: 14px;
    margin: 0;
  }

  .loading-spinner {
    width: 40px;
    height: 40px;
    border: 3px solid #f0f0f0;
    border-top-color: #2271b1;
    border-radius: 50%;
    animation: spin 1s linear infinite;
  }

  @keyframes spin {
    to { transform: rotate(360deg); }
  }
</style>
