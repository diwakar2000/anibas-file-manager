<script lang="ts">
  import { fileStore } from "../../stores/fileStore.svelte"
  import { getFileIcon, getIconUrl } from "../../utils/fileIcons"
  import type { FileItem } from "../../types/files"

  let { file, onfilecontextmenu }: { file: FileItem; onfilecontextmenu?: (e: { file: FileItem; x: number; y: number }) => void } = $props()
  let showInfo = $state(false)
  let infoTimeout: ReturnType<typeof setTimeout> | null = null
  let tooltipStyle = $state('')

  // Get icon URL for the file or folder
  const iconName = $derived(getFileIcon(file.name, file.is_folder))
  const iconUrl = $derived(getIconUrl(iconName))

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

  function computeTooltipPosition(target: HTMLElement) {
    const rect = target.getBoundingClientRect()
    // Find the explorer-body container to constrain within
    const container = target.closest('.explorer-body') || target.closest('.explorer-container')
    const bounds = container ? container.getBoundingClientRect() : { top: 0, left: 0, right: window.innerWidth, bottom: window.innerHeight }
    const pw = 260, ph = 110, gap = 8

    let top = 0, left = 0

    // Try below
    if (rect.bottom + gap + ph <= bounds.bottom) {
      top = rect.bottom + gap
      left = rect.left + rect.width / 2 - pw / 2
    }
    // Try above
    else if (rect.top - gap - ph >= bounds.top) {
      top = rect.top - gap - ph
      left = rect.left + rect.width / 2 - pw / 2
    }
    // Try right
    else if (rect.right + gap + pw <= bounds.right) {
      top = rect.top + rect.height / 2 - ph / 2
      left = rect.right + gap
    }
    // Try left
    else {
      top = rect.top + rect.height / 2 - ph / 2
      left = rect.left - gap - pw
    }

    // Clamp within container bounds
    if (left < bounds.left + 4) left = bounds.left + 4
    if (left + pw > bounds.right - 4) left = bounds.right - 4 - pw
    if (top < bounds.top + 4) top = bounds.top + 4
    if (top + ph > bounds.bottom - 4) top = bounds.bottom - 4 - ph

    tooltipStyle = `position:fixed;top:${top}px;left:${left}px;width:${pw}px;`
  }

  function handleMouseEnter(e: MouseEvent) {
    if (infoTimeout) clearTimeout(infoTimeout)
    const target = e.currentTarget as HTMLElement
    computeTooltipPosition(target)
    infoTimeout = setTimeout(() => {
      showInfo = true
    }, 400)
  }

  function handleMouseLeave() {
    if (infoTimeout) clearTimeout(infoTimeout)
    showInfo = false
  }

  // Inline rename
  let renameValue = $state("")
  let renameInput = $state<HTMLInputElement | null>(null)

  $effect(() => {
    if (fileStore.renamingPath === file.path) {
      renameValue = file.name
      setTimeout(() => renameInput?.focus(), 0)
    }
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
        // Will handle file opening/preview generically
      }
    }
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
      // Ignore invalid payloads
    }
  }

  function handleContextMenu(e: MouseEvent) {
    e.preventDefault()
    e.stopPropagation()
    if (!fileStore.isSelected(file.path)) {
      fileStore.selectFile(file.path, {})
    }
    onfilecontextmenu?.({ file, x: e.clientX, y: e.clientY })
  }
</script>

<!-- svelte-ignore a11y_no_static_element_interactions -->
<div
  class="grid-item"
  class:is-selected={fileStore.isSelected(file.path)}
  class:is-folder={file.is_folder}
  class:is-deleting={fileStore.isDeleting(file.path)}
  class:is-drag-over={isDragOver}
  onclick={handleClick}
  onmouseenter={handleMouseEnter}
  onmouseleave={handleMouseLeave}
  oncontextmenu={handleContextMenu}
  draggable={fileStore.renamingPath !== file.path}
  ondragstart={handleDragStart}
  ondragover={handleDragOver}
  ondragleave={handleDragLeave}
  ondrop={handleDrop}
  role="button"
  tabindex="0"
  onkeydown={(e) => e.key === "Enter" && file.is_folder && fileStore.navigateTo(file.path)}
>
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <input
    type="checkbox"
    class="grid-checkbox"
    checked={fileStore.isSelected(file.path)}
    onclick={(e) => { e.stopPropagation(); fileStore.selectFile(file.path, { ctrl: true }) }}
  />
  <div class="icon-wrapper">
    <img class="icon" src={iconUrl} alt={file.is_folder ? "Folder" : "File"} />
  </div>
  {#if fileStore.renamingPath === file.path}
    <!-- svelte-ignore a11y_autofocus -->
    <input
      class="grid-rename-input"
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
    <div class="file-name" class:is-selected={fileStore.isSelected(file.path)} title={file.name} ondblclick={(e) => { e.stopPropagation(); fileStore.startRename(file.path) }}>{file.name}</div>
  {/if}
  
  {#if showInfo}
    <div class="info-panel" style={tooltipStyle}>
      <div class="info-row">
        <span class="info-label">Name:</span>
        <span class="info-value">{file.name}</span>
      </div>
      <div class="info-row">
        <span class="info-label">Type:</span>
        <span class="info-value">{file.is_folder ? "Folder" : (file.file_type || "File")}</span>
      </div>
      {#if !file.is_folder}
        <div class="info-row">
          <span class="info-label">Size:</span>
          <span class="info-value">{formatSize(file.filesize)}</span>
        </div>
      {/if}
      <div class="info-row">
        <span class="info-label">Modified:</span>
        <span class="info-value">{formatDate(file.last_modified)}</span>
      </div>
    </div>
  {/if}
</div>

<style>
  .grid-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 16px 12px 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: background-color 0.15s ease;
    position: relative;
    user-select: none;
  }

  .grid-checkbox {
    position: absolute;
    top: 6px;
    left: 6px;
    width: 14px;
    height: 14px;
    opacity: 0;
    cursor: pointer;
    transition: opacity 0.1s;
    z-index: 1;
  }

  .grid-item:hover .grid-checkbox,
  .grid-item.is-selected .grid-checkbox {
    opacity: 1;
  }

  .grid-rename-input {
    width: 100%;
    padding: 2px 6px;
    border: 1px solid #2271b1;
    border-radius: 3px;
    font-size: 12px;
    text-align: center;
    outline: none;
    box-shadow: 0 0 0 1px #2271b1;
    margin-top: 4px;
    box-sizing: border-box;
  }

  .grid-item:hover {
    background-color: #f0f4f8;
  }

  .grid-item.is-drag-over {
    background-color: #e6f7ff;
    outline: 2px dashed #1890ff;
    outline-offset: -2px;
  }

  .grid-item.is-selected {
    background-color: #e7f3ff;
    outline: 2px solid #2271b1;
  }

  .grid-item.is-folder {
    color: #2271b1;
  }

  .grid-item.is-deleting {
    opacity: 0.5;
    pointer-events: none;
  }

  .icon-wrapper {
    width: 64px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 8px;
  }

  .icon {
    width: 48px;
    height: 48px;
    object-fit: contain;
  }

  .file-name {
    font-size: 12px;
    text-align: center;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    line-height: 1.3;
    padding: 2px 4px;
    border-radius: 3px;
  }

  .file-name.is-selected {
    white-space: normal;
    word-break: break-word;
    background: #cce5ff;
  }

  .is-folder .file-name {
    font-weight: 600;
  }

  .info-panel {
    background: #1e1e1e;
    color: #fff;
    padding: 12px 16px;
    border-radius: 6px;
    font-size: 12px;
    z-index: 100000;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
    pointer-events: none;
  }

  .info-row {
    display: flex;
    margin-bottom: 6px;
    line-height: 1.4;
  }

  .info-row:last-child {
    margin-bottom: 0;
  }

  .info-label {
    color: #aaa;
    min-width: 60px;
    flex-shrink: 0;
  }

  .info-value {
    color: #fff;
    word-break: break-word;
  }
</style>
