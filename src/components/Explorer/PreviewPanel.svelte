<script lang="ts">
  import { fileStore } from "../../stores/fileStore.svelte"
  import { getDownloadUrl, getPreviewContent } from "../../services/fileApi"

  // Only show if exactly 1 non-folder is selected
  const activeFile = $derived.by(() => {
    if (fileStore.selectionCount === 1) {
      const path = fileStore.selectedPaths[0]
      const file = fileStore.currentFiles.find(f => f.path === path)
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

  const previewUrl = $derived(activeFile ? getDownloadUrl(activeFile.path, fileStore.currentStorage) : '')

  const TEXT_PREVIEW_LIMIT = 102400 // 100 KB

  let textContent = $state<string | null>(null)
  let textLoading = $state(false)
  let textError = $state<string | null>(null)

  $effect(() => {
    if (previewType === 'text' && activeFile && fileStore.previewOpen) {
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

      getPreviewContent(activeFile.path, fileStore.currentStorage)
        .then(text => {
          textContent = text
        })
        .catch(err => {
          textError = err.message
        })
        .finally(() => {
          textLoading = false
        })
    }
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
    const a = document.createElement('a');
    a.href = previewUrl; a.setAttribute('download', '');
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
  }
</script>

{#if fileStore.previewOpen && activeFile}
<div class="preview-panel">
  <div class="preview-header">
    <div class="preview-title" title={activeFile.name}>{activeFile.name}</div>
    <div class="preview-size">{formatSize(activeFile.filesize)}</div>
    <div class="preview-actions">
      <button class="preview-btn" onclick={downloadFile} title="Download">⬇️</button>
      <button class="preview-btn" onclick={() => { fileStore.previewOpen = false }} title="Close">✖</button>
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
    width: 300px;
    background: #fdfdfd;
    border-left: 1px solid #e0e0e0;
    display: flex;
    flex-direction: column;
    height: 100%;
    flex-shrink: 0;
    box-shadow: -2px 0 10px rgba(0,0,0,0.02);
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
    padding-right: 50px; /* space for actions */
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
    background: transparent;
    border: none;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    font-size: 12px;
    color: #646970;
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
</style>
