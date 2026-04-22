<script lang="ts">
  import { fileStore } from "../../stores/fileStore.svelte"
  import { toast } from "../../utils/toast"
  import type { FileItem } from "../../types/files"

  let { file, onclose } = $props<{ file: FileItem; onclose: () => void }>()

  const config = (window as any).AnibasFM

  let loading = $state(true)
  let checking = $state(false)
  let storages = $state<Array<{ id: string; name: string; status: 'checking' | 'online' | 'offline' }>>([])

  // Determine available destinations based on current storage
  // If local: show all configured remote storages
  // If remote: show local
  $effect(() => {
    loadStorages()
  })

  async function loadStorages() {
    loading = true
    checking = true

    try {
      const response = await fetch(`${config.ajaxURL}?action=${config.actions.getRemoteSettings}&nonce=${config.settingsNonce}`)
      const data = await response.json()

      if (!data.success) {
        loading = false
        checking = false
        return
      }

      const currentStorage = fileStore.currentStorage

      // Build list of available destinations (exclude current)
      const availableStorages: Array<{ id: string; name: string; status: 'checking' | 'online' | 'offline' }> = []

      if (currentStorage !== 'local') {
        // On remote - can only send to local
        availableStorages.push({ id: 'local', name: 'Local Files', status: 'online' })
      } else {
        // On local - can send to any configured remote
        const remoteStorages = [
          { id: 'ftp', name: 'FTP', config: data.data.ftp, enabled: data.data.ftp?.enabled },
          { id: 'sftp', name: 'SFTP', config: data.data.sftp, enabled: data.data.sftp?.enabled },
          { id: 's3', name: 'Amazon S3', config: data.data.s3, enabled: data.data.s3?.enabled },
          { id: 's3_compatible', name: 'S3 Compatible', config: data.data.s3_compatible, enabled: data.data.s3_compatible?.enabled }
        ].filter(s => s.enabled)

        // Add local option too (for same-storage copy to different location)
        availableStorages.push({ id: 'local', name: 'Local Files (current)', status: 'online' })

        // Add remote storages with checking status
        for (const s of remoteStorages) {
          availableStorages.push({ id: s.id, name: s.name, status: 'checking' })
        }
      }

      storages = availableStorages

      // Test remote connections
      if (currentStorage === 'local') {
        const remoteStorages = storages.filter(s => s.id !== 'local')
        
        const tests = remoteStorages.map(async (storage) => {
          const settings = data.data[storage.id]
          if (!settings) return

          const formData = new FormData()
          formData.append('action', config.actions.testRemoteConnection)
          formData.append('nonce', config.settingsNonce)
          formData.append('type', storage.id)
          formData.append('config', JSON.stringify(settings))

          try {
            const res = await fetch(config.ajaxURL, { method: 'POST', body: formData })
            const result = await res.json()
            storages = storages.map(s =>
              s.id === storage.id
                ? { ...s, status: result.success ? 'online' : 'offline' }
                : s
            )
          } catch (e) {
            storages = storages.map(s =>
              s.id === storage.id
                ? { ...s, status: 'offline' }
                : s
            )
          }
        })

        await Promise.all(tests)
      }

      loading = false
      checking = false
    } catch (e) {
      console.error('Failed to load storages:', e)
      loading = false
      checking = false
    }
  }

  async function handleSendTo(destinationStorage: string) {
    // Cannot send to same storage (use Copy/Paste instead)
    if (destinationStorage === fileStore.currentStorage) {
      toast.info('Use Copy/Paste for transfers within the same storage')
      onclose()
      return
    }

    // Check for cross-storage restriction (one side must be local)
    if (fileStore.currentStorage !== 'local' && destinationStorage !== 'local') {
      toast.error('Transfer to remote storage first, then to the target storage.')
      onclose()
      return
    }

    // Set up clipboard with copy action
    fileStore.copyToClipboard([file.path])

    // Remember current storage
    const sourceStorage = fileStore.currentStorage

    // Switch to destination storage
    await fileStore.changeStorage(destinationStorage)

    // Wait for directory to load, then paste
    // The paste will use the current path in the destination storage
    setTimeout(async () => {
      try {
        await fileStore.requestPaste(fileStore.currentPath)
        toast.success(`Sent "${file.name}" to ${getStorageName(destinationStorage)}`)
      } catch (err: any) {
        toast.error(err.message || 'Failed to send file')
        // Switch back to source storage on failure
        await fileStore.changeStorage(sourceStorage)
      }
    }, 300)

    onclose()
  }

  function getStorageName(id: string): string {
    const names: Record<string, string> = {
      'local': 'Local Files',
      'ftp': 'FTP',
      'sftp': 'SFTP',
      's3': 'Amazon S3',
      's3_compatible': 'S3 Compatible'
    }
    return names[id] || id
  }
</script>

<!-- svelte-ignore a11y_no_static_element_interactions -->
<div
  class="sendto-overlay"
  onclick={(e) => { if (e.target === e.currentTarget) onclose() }}
  onkeydown={(e) => e.key === 'Escape' && onclose()}
  role="button"
  tabindex="-1"
  aria-label="Close"
>
  <div class="sendto-panel" role="dialog" aria-modal="true">
    <div class="sendto-header">
      <h3>Send "{file.name}" to</h3>
      <button class="sendto-close" onclick={onclose} aria-label="Close">&times;</button>
    </div>

    <div class="sendto-body">
      {#if loading}
        <div class="sendto-loading">
          <div class="spinner"></div>
          <p>Loading available destinations...</p>
        </div>
      {:else}
        {#if checking}
          <div class="sendto-checking">
            <div class="spinner"></div>
            <p>Checking connections...</p>
          </div>
        {/if}

        <div class="sendto-list">
          {#each storages as storage}
            <button
              class="sendto-option"
              class:disabled={storage.status !== 'online'}
              disabled={storage.status !== 'online' || checking}
              onclick={() => handleSendTo(storage.id)}
            >
              <span class="sendto-name">{storage.name}</span>
              {#if storage.status === 'checking'}
                <span class="sendto-status checking">Checking...</span>
              {:else if storage.status === 'offline'}
                <span class="sendto-status offline">Offline</span>
              {:else}
                <span class="sendto-status online">Online</span>
              {/if}
            </button>
          {/each}
        </div>

        {#if storages.length === 0}
          <p class="sendto-empty">No other storages configured.</p>
        {/if}
      {/if}
    </div>

    <div class="sendto-footer">
      <button class="btn btn-secondary" onclick={onclose}>Cancel</button>
    </div>
  </div>
</div>

<style>
  .sendto-overlay {
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

  .sendto-panel {
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25);
    width: 380px;
    max-width: 90vw;
  }

  .sendto-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid #eee;
  }

  .sendto-header h3 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 280px;
  }

  .sendto-close {
    background: none;
    border: none;
    font-size: 22px;
    cursor: pointer;
    color: #666;
    padding: 0 4px;
    line-height: 1;
  }

  .sendto-close:hover {
    color: #333;
  }

  .sendto-body {
    padding: 16px 20px;
    min-height: 100px;
  }

  .sendto-loading,
  .sendto-checking {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: #f0f6fc;
    border-radius: 4px;
    margin-bottom: 12px;
  }

  .sendto-loading p,
  .sendto-checking p {
    margin: 0;
    color: #2271b1;
    font-size: 14px;
  }

  .spinner {
    width: 20px;
    height: 20px;
    border: 3px solid #e0e0e0;
    border-top-color: #2271b1;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
  }

  @keyframes spin {
    to { transform: rotate(360deg); }
  }

  .sendto-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .sendto-option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border: 2px solid #ddd;
    border-radius: 4px;
    background: #fff;
    cursor: pointer;
    transition: all 0.2s;
    text-align: left;
    font-size: 14px;
  }

  .sendto-option:hover:not(:disabled) {
    border-color: #2271b1;
    background: #f6f7f7;
  }

  .sendto-option.disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .sendto-name {
    font-weight: 500;
    color: #333;
  }

  .sendto-status {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 3px;
    font-weight: 500;
  }

  .sendto-status.checking {
    background: #f0b849;
    color: #fff;
  }

  .sendto-status.offline {
    background: #d63638;
    color: #fff;
  }

  .sendto-status.online {
    background: #00a32a;
    color: #fff;
  }

  .sendto-empty {
    text-align: center;
    color: #666;
    font-size: 14px;
    padding: 20px;
  }

  .sendto-footer {
    display: flex;
    justify-content: flex-end;
    padding: 12px 20px 16px;
    border-top: 1px solid #eee;
  }

  .btn {
    padding: 8px 16px;
    border-radius: 4px;
    font-size: 14px;
    cursor: pointer;
    border: none;
    font-weight: 500;
  }

  .btn-secondary {
    background: #f0f0f1;
    color: #2c3338;
  }

  .btn-secondary:hover {
    background: #dcdcde;
  }
</style>
