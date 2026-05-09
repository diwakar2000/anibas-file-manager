<script lang="ts">
  import { onDestroy } from "svelte"
  import { fileStore } from "../../stores/fileStore.svelte"
  import { getDownloadUrl, getPreviewContent, getPreviewUrl } from "../../services/fileApi"

  // Only show if exactly 1 non-folder is selected
  const activeFile = $derived.by(() => {
    if (fileStore.previewFile && !fileStore.previewFile.is_folder) {
      return fileStore.previewFile
    }
    if (fileStore.selectionCount === 1 && fileStore.selectedFile && !fileStore.selectedFile.is_folder) {
      return fileStore.selectedFile
    }
    if (fileStore.selectionCount === 1) {
      const file = fileStore.primarySelectedFile
      if (file && !file.is_folder) return file
    }
    return null
  })

  // Derive preview type
  const previewType = $derived.by(() => {
    if (!activeFile) return null
    const ext = activeFile.name.split('.').pop()?.toLowerCase() || ''
    if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'ico'].includes(ext)) return 'image'
    if (ext === 'pdf') return 'pdf'
    if (['txt', 'log', 'md', 'csv', 'json', 'js', 'html', 'css', 'php', 'ts', 'xml', 'yml', 'yaml', 'ini', 'sh', 'py'].includes(ext)) return 'text'
    if (['mp4', 'webm', 'ogg', 'mov'].includes(ext)) return 'video'
    if (['mp3', 'wav', 'ogg'].includes(ext)) return 'audio'
    return 'unknown'
  })

  const previewUrl = $derived(activeFile ? getPreviewUrl(activeFile, fileStore.currentStorage) : '')

  const TEXT_PREVIEW_LIMIT = 102400 // 100 KB
  const MIN_PREVIEW_WIDTH = 320
  const DEFAULT_PREVIEW_WIDTH = 460
  const EDGE_PADDING = 48

  let textContent = $state<string | null>(null)
  let textLoading = $state(false)
  let textError = $state<string | null>(null)
  let panelEl = $state<HTMLElement | null>(null)
  let previewWidth = $state(DEFAULT_PREVIEW_WIDTH)
  let previewMaximized = $state(false)
  let isResizing = $state(false)
  let resizePointerId: number | null = null
  let textPreviewRequestId = 0

  const previewStyle = $derived(previewMaximized ? "" : `width: ${previewWidth}px`)

  $effect(() => {
    if (previewType !== 'text' || !activeFile || !fileStore.previewOpen) {
      textPreviewRequestId++
      textContent = null
      textLoading = false
      textError = null
      return
    }

    const requestId = ++textPreviewRequestId
    // For remote storage, skip the request entirely if the file is already
    // known to exceed the limit — fetching the full file is unsafe for large files.
    const isRemote = fileStore.currentStorage !== 'local'
    if (isRemote && activeFile.filesize !== undefined && activeFile.filesize > TEXT_PREVIEW_LIMIT) {
      textContent = null
      textLoading = false
      textError = 'File is too large to preview on remote storage'
      return
    }

    textLoading = true
    textError = null
    textContent = null

    getPreviewContent(activeFile.path, fileStore.currentStorage, { storageId: activeFile.storage_id })
      .then(text => {
        if (requestId !== textPreviewRequestId) return
        textContent = text
      })
      .catch(err => {
        if (requestId !== textPreviewRequestId) return
        textError = err.message
      })
      .finally(() => {
        if (requestId !== textPreviewRequestId) return
        textLoading = false
      })
  })

  function formatSize(bytes?: number) {
    if (bytes === undefined) return ""
    if (bytes === 0) return "0 B"
    const k = 1024
    const sizes = ["B", "KB", "MB", "GB", "TB"]
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + " " + sizes[i]
  }

  function downloadFile() {
    if (!previewUrl) return;
    if (!activeFile) return;
    const downloadUrl = getDownloadUrl(activeFile.path, fileStore.currentStorage, { storageId: activeFile.storage_id })
    const a = document.createElement('a');
    a.href = downloadUrl; a.setAttribute('download', '');
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
  }

  function clampPreviewWidth(width: number): number {
    const layoutWidth = panelEl?.parentElement?.clientWidth || window.innerWidth
    const maxWidth = Math.max(MIN_PREVIEW_WIDTH, layoutWidth - EDGE_PADDING)
    return Math.round(Math.min(Math.max(width, MIN_PREVIEW_WIDTH), maxWidth))
  }

  function handleResizeMove(e: PointerEvent) {
    if (!isResizing || !panelEl) return
    if (resizePointerId !== null && e.pointerId !== resizePointerId) return
    e.preventDefault()
    const rect = panelEl.getBoundingClientRect()
    previewWidth = clampPreviewWidth(rect.right - e.clientX)
  }

  function stopResize(e?: PointerEvent) {
    if (!isResizing) return
    if (e && resizePointerId !== null && e.pointerId !== resizePointerId) return
    isResizing = false
    resizePointerId = null
    document.body.style.cursor = ""
    document.body.style.userSelect = ""
    window.removeEventListener("pointermove", handleResizeMove)
    window.removeEventListener("pointerup", stopResize)
    window.removeEventListener("pointercancel", stopResize)
  }

  function startResize(e: PointerEvent) {
    if (!panelEl) return
    previewMaximized = false
    isResizing = true
    resizePointerId = e.pointerId
    ;(e.currentTarget as HTMLElement).setPointerCapture?.(e.pointerId)
    document.body.style.cursor = "ew-resize"
    document.body.style.userSelect = "none"
    window.addEventListener("pointermove", handleResizeMove)
    window.addEventListener("pointerup", stopResize)
    window.addEventListener("pointercancel", stopResize)
    e.preventDefault()
    e.stopPropagation()
  }

  function toggleMaximize() {
    previewMaximized = !previewMaximized
  }

  onDestroy(stopResize)
</script>

{#if fileStore.previewOpen && activeFile}
<div
  class="preview-panel"
  class:preview-panel-maximized={previewMaximized}
  bind:this={panelEl}
  style={previewStyle}
>
  <button
    type="button"
    class="preview-resize-handle"
    onpointerdown={startResize}
    class:preview-resize-handle-active={isResizing}
    title="Resize preview"
    aria-label="Resize preview"
  ></button>
  {#if isResizing}
    <div class="preview-drag-shield" aria-hidden="true"></div>
  {/if}
  <div class="preview-header">
    <div class="preview-title" title={activeFile.name}>{activeFile.name}</div>
    <div class="preview-size">{formatSize(activeFile.filesize)}</div>
    <div class="preview-actions">
      <button class="preview-btn" onclick={downloadFile} title="Download" aria-label="Download">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
      </button>
      <button class="preview-btn" onclick={toggleMaximize} title={previewMaximized ? "Restore preview" : "Maximize preview"} aria-label={previewMaximized ? "Restore preview" : "Maximize preview"}>
        {#if previewMaximized}
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3v5H3"/><path d="M16 3v5h5"/><path d="M8 21v-5H3"/><path d="M16 21v-5h5"/></svg>
        {:else}
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3H3v5"/><path d="M16 3h5v5"/><path d="M8 21H3v-5"/><path d="M16 21h5v-5"/></svg>
        {/if}
      </button>
      <button class="preview-btn" onclick={() => { fileStore.previewOpen = false }} title="Close" aria-label="Close preview">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
    </div>
  </div>
  
  <div class="preview-content">
    {#if previewType === 'image'}
      <img src={previewUrl} alt={activeFile.name} class="preview-image" />
    {:else if previewType === 'video'}
      <!-- svelte-ignore a11y_media_has_caption -->
      <video src={previewUrl} controls class="preview-video"></video>
    {:else if previewType === 'audio'}
      <!-- svelte-ignore a11y_media_has_caption -->
      <audio src={previewUrl} controls class="preview-audio"></audio>
    {:else if previewType === 'pdf'}
      <iframe src={previewUrl} title={activeFile.name} class="preview-pdf" frameborder="0"></iframe>
    {:else if previewType === 'text'}
      {#if textLoading}
        <div class="preview-loading">
          <div class="preview-spinner"></div>
          <span>Loading text preview...</span>
        </div>
      {:else if textError}
        <div class="preview-error">{textError}</div>
      {:else}
        <pre class="preview-text"><code>{textContent}</code></pre>
      {/if}
    {:else}
      <div class="preview-unsupported">
        <div class="unsupported-icon">📂</div>
        <p>No preview available for this file type.</p>
        <button class="btn-download" onclick={downloadFile}>Download File</button>
      </div>
    {/if}
  </div>
</div>
{/if}

<style>
  .preview-panel {
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    min-width: 320px;
    max-width: calc(100% - 48px);
    background: #fdfdfd;
    border-left: 1px solid #e0e0e0;
    display: flex;
    flex-direction: column;
    z-index: 30;
    box-shadow: -10px 0 28px rgba(0,0,0,0.16);
  }
  .preview-panel-maximized {
    left: 0;
    width: auto !important;
    min-width: 0;
    max-width: none;
    border-left: 0;
  }
  .preview-resize-handle {
    position: absolute;
    top: 0;
    left: -5px;
    width: 10px;
    height: 100%;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: ew-resize;
    z-index: 2;
  }
  .preview-resize-handle::after {
    content: "";
    position: absolute;
    top: 0;
    left: 4px;
    width: 2px;
    height: 100%;
    background: transparent;
  }
  .preview-resize-handle:hover::after,
  .preview-resize-handle:focus-visible::after,
  .preview-resize-handle-active::after {
    background: #2271b1;
  }
  .preview-drag-shield {
    position: absolute;
    inset: 0;
    z-index: 1;
    cursor: ew-resize;
    background: transparent;
  }
  .preview-panel-maximized .preview-resize-handle {
    display: none;
  }
  .preview-header {
    padding: 12px 16px;
    border-bottom: 1px solid #e0e0e0;
    background: #fff;
    flex-shrink: 0;
    position: relative;
  }
  .preview-title {
    font-size: 14px;
    font-weight: 600;
    color: #2c3338;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding-right: 92px;
  }
  .preview-size {
    font-size: 12px;
    color: #646970;
  }
  .preview-actions {
    position: absolute;
    top: 10px;
    right: 12px;
    display: flex;
    gap: 4px;
  }
  .preview-btn {
    width: 26px;
    height: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    color: #646970;
  }
  .preview-btn svg {
    width: 15px;
    height: 15px;
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
  }
  .preview-btn:hover {
    background: #f0f0f1;
    color: #d63638;
  }
  .preview-content {
    flex: 1;
    overflow: auto;
    display: flex;
    justify-content: center;
    align-items: center;
    background: #f0f0f1;
  }
  .preview-image {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    background: repeating-conic-gradient(#e0e0e0 0% 25%, transparent 0% 50%) 50% / 20px 20px;
  }
  .preview-pdf {
    width: 100%;
    height: 100%;
  }
  .preview-video {
    width: 100%;
    max-height: 100%;
  }
  .preview-audio {
    width: 100%;
    padding: 20px;
  }
  .preview-text {
    width: 100%;
    height: 100%;
    margin: 0;
    background: #fff;
    padding: 16px;
    font-family: ui-monospace, SFMono-Regular, Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 13px;
    line-height: 1.5;
    overflow: auto;
    color: #2c3338;
    white-space: pre-wrap;
    box-sizing: border-box;
  }
  .preview-loading, .preview-error, .preview-unsupported {
    color: #646970;
    font-size: 13px;
    text-align: center;
    padding: 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    height: 100%;
    width: 100%;
  }
  .preview-spinner {
    width: 24px;
    height: 24px;
    border: 3px solid #e0e0e0;
    border-top: 3px solid #2271b1;
    border-radius: 50%;
    animation: preview-spin 1s linear infinite;
  }
  @keyframes preview-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
  }
  .unsupported-icon {
    font-size: 48px;
    opacity: 0.5;
  }
  .btn-download {
    margin-top: 8px;
    padding: 8px 16px;
    background: #2271b1;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 500;
  }
  .btn-download:hover {
    background: #135e96;
  }

  @media (max-width: 782px) {
    .preview-panel {
      left: 0;
      width: auto !important;
      min-width: 0;
      max-width: none;
      border-left: 0;
    }
    .preview-resize-handle {
      display: none;
    }
  }
</style>
