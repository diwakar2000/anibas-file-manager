<script lang="ts">
  import type { FileItem } from "../../types/files"
  import { getFileDetails } from "../../services/fileApi"
  import { fileStore } from "../../stores/fileStore.svelte"
  import { getFileIcon, getIconUrl } from "../../utils/fileIcons"

  let { file, onclose } = $props<{ file: FileItem; onclose: () => void }>()

  const iconName = $derived(getFileIcon(file.name, file.is_folder))
  const iconUrl = $derived(getIconUrl(iconName))

  // Async details from the server
  let asyncDetails = $state<Record<string, any> | null>(null)
  let asyncError = $state<string | null>(null)
  let loading = $state(true)

  $effect(() => {
    loading = true
    asyncError = null
    asyncDetails = null
    getFileDetails(file.path, fileStore.currentStorage)
      .then((d) => { asyncDetails = d })
      .catch((e) => { asyncError = e.message || "Failed to load details" })
      .finally(() => { loading = false })
  })

  function formatSize(bytes?: number | null): string {
    if (bytes === undefined || bytes === null) return "--"
    if (bytes === 0) return "0 B"
    const k = 1024
    const sizes = ["B", "KB", "MB", "GB", "TB"]
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i]
  }

  function formatExactSize(bytes?: number | null): string {
    if (bytes === undefined || bytes === null) return ""
    return bytes.toLocaleString() + " bytes"
  }

  function formatDate(timestamp?: number | null): string {
    if (!timestamp) return "--"
    return new Date(timestamp * 1000).toLocaleString(undefined, {
      year: "numeric",
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
    })
  }

  function formatPermissions(mode?: number | null, octal?: string | null, str?: string | null): string {
    // FTP returns permission_str like "drwxr-xr-x"
    if (str) return str
    if (!mode) return "--"
    const perms = mode & 0o777
    let s = ""
    for (let i = 2; i >= 0; i--) {
      s += (perms >> (i * 3)) & 4 ? "r" : "-"
      s += (perms >> (i * 3)) & 2 ? "w" : "-"
      s += (perms >> (i * 3)) & 1 ? "x" : "-"
    }
    return `${s} (${octal || perms.toString(8).padStart(3, "0")})`
  }

  function handleOverlayClick(e: MouseEvent) {
    if ((e.target as HTMLElement).classList.contains("details-overlay")) {
      onclose()
    }
  }

  function handleKeydown(e: KeyboardEvent) {
    if (e.key === "Escape") onclose()
  }
</script>

<!-- svelte-ignore a11y_no_static_element_interactions -->
<div
  class="details-overlay"
  onclick={handleOverlayClick}
  onkeydown={handleKeydown}
  role="button"
  tabindex="-1"
  aria-label="Close details"
>
  <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
  <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
  <div class="details-panel" onclick={(e) => e.stopPropagation()} onkeydown={(e) => e.stopPropagation()} role="dialog" tabindex="0">
    <div class="details-header">
      <img class="details-icon" src={iconUrl} alt="" />
      <h3>{file.name}</h3>
      <button class="details-close" onclick={onclose} aria-label="Close">&times;</button>
    </div>

    <div class="details-body">
      <!-- Instant fields (from client data) -->
      <div class="detail-row">
        <span class="detail-label">Name</span>
        <span class="detail-value">{file.name}</span>
      </div>

      <div class="detail-row">
        <span class="detail-label">Type</span>
        <span class="detail-value">{file.is_folder ? "Folder" : (file.file_type || "File")}</span>
      </div>

      <div class="detail-row">
        <span class="detail-label">Location</span>
        <span class="detail-value path-value">{file.path}</span>
      </div>

      {#if !file.is_folder}
        <div class="detail-row">
          <span class="detail-label">Size</span>
          <span class="detail-value">
            {#if loading}
              <span class="calculating">Calculating...</span>
            {:else if asyncDetails?.size !== undefined && asyncDetails.size !== null}
              {formatSize(asyncDetails.size)}
              <span class="detail-sub">{formatExactSize(asyncDetails.size)}</span>
            {:else}
              {formatSize(file.filesize)}
            {/if}
          </span>
        </div>
      {/if}

      {#if !file.is_folder}
        <div class="detail-row">
          <span class="detail-label">Extension</span>
          <span class="detail-value">
            {#if loading}
              <span class="calculating">Calculating...</span>
            {:else}
              {asyncDetails?.extension || file.name.split('.').pop() || "--"}
            {/if}
          </span>
        </div>
      {/if}

      <!-- Async fields -->
      <div class="detail-row">
        <span class="detail-label">Last Modified</span>
        <span class="detail-value">
          {#if loading}
            <span class="calculating">Calculating...</span>
          {:else if asyncDetails?.last_modified}
            {formatDate(asyncDetails.last_modified)}
          {:else if file.last_modified}
            {formatDate(file.last_modified)}
          {:else}
            <span class="detail-na">N/A</span>
          {/if}
        </span>
      </div>

      <div class="detail-row">
        <span class="detail-label">Created</span>
        <span class="detail-value">
          {#if loading}
            <span class="calculating">Calculating...</span>
          {:else if asyncDetails?.created}
            {formatDate(asyncDetails.created)}
          {:else}
            <span class="detail-na">N/A</span>
          {/if}
        </span>
      </div>

      <div class="detail-row">
        <span class="detail-label">Permissions</span>
        <span class="detail-value mono">
          {#if loading}
            <span class="calculating">Calculating...</span>
          {:else if asyncDetails?.permission || asyncDetails?.permission_str}
            {formatPermissions(asyncDetails?.permission, asyncDetails?.permission_octal, asyncDetails?.permission_str)}
          {:else if file.permission}
            {formatPermissions(file.permission)}
          {:else}
            <span class="detail-na">N/A</span>
          {/if}
        </span>
      </div>

      <div class="detail-row">
        <span class="detail-label">Owner</span>
        <span class="detail-value">
          {#if loading}
            <span class="calculating">Calculating...</span>
          {:else if asyncDetails?.owner}
            {asyncDetails.owner}
          {:else}
            <span class="detail-na">N/A</span>
          {/if}
        </span>
      </div>

      <div class="detail-row">
        <span class="detail-label">Group</span>
        <span class="detail-value">
          {#if loading}
            <span class="calculating">Calculating...</span>
          {:else if asyncDetails?.group}
            {asyncDetails.group}
          {:else}
            <span class="detail-na">N/A</span>
          {/if}
        </span>
      </div>

      {#if !file.is_folder}
        <div class="detail-row">
          <span class="detail-label">MIME Type</span>
          <span class="detail-value">
            {#if loading}
              <span class="calculating">Calculating...</span>
            {:else if asyncDetails?.mime_type}
              {asyncDetails.mime_type}
            {:else}
              <span class="detail-na">N/A</span>
            {/if}
          </span>
        </div>
      {/if}

      {#if asyncError}
        <div class="detail-error">
          Failed to load extended details: {asyncError}
        </div>
      {/if}
    </div>
  </div>
</div>

<style>
  .details-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100000;
  }

  .details-panel {
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25);
    width: 460px;
    max-width: 90vw;
    max-height: 80vh;
    overflow-y: auto;
  }

  .details-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 20px;
    border-bottom: 1px solid #eee;
  }

  .details-header h3 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .details-icon {
    width: 24px;
    height: 24px;
    flex-shrink: 0;
  }

  .details-close {
    background: none;
    border: none;
    font-size: 22px;
    cursor: pointer;
    color: #666;
    padding: 0 4px;
    line-height: 1;
    flex-shrink: 0;
  }

  .details-close:hover {
    color: #333;
  }

  .details-body {
    padding: 16px 20px;
  }

  .detail-row {
    display: flex;
    padding: 7px 0;
    border-bottom: 1px solid #f5f5f5;
    font-size: 13px;
    gap: 12px;
  }

  .detail-row:last-child {
    border-bottom: none;
  }

  .detail-label {
    width: 110px;
    flex-shrink: 0;
    color: #666;
    font-weight: 500;
  }

  .detail-value {
    flex: 1;
    word-break: break-all;
    color: #333;
  }

  .detail-value.mono {
    font-family: ui-monospace, monospace;
    font-size: 12px;
  }

  .detail-sub {
    display: block;
    font-size: 11px;
    color: #999;
    margin-top: 2px;
  }

  .path-value {
    font-family: ui-monospace, monospace;
    font-size: 12px;
    color: #555;
  }

  .calculating {
    color: #999;
    font-style: italic;
  }

  .detail-na {
    color: #bbb;
  }

  .detail-error {
    margin-top: 12px;
    padding: 8px 12px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 4px;
    color: #b91c1c;
    font-size: 12px;
  }
</style>
