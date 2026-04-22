<script lang="ts">
  import { onMount } from "svelte"
  import { fileStore } from "./stores/fileStore.svelte"
  import FileTree from "./components/Sidebar/FileTree.svelte"
  import FileExplorer from "./components/Explorer/FileExplorer.svelte"
  import InlineEditor from "./components/Editor/InlineEditor.svelte"
  import BackupModal from "./components/Shared/BackupModal.svelte"
  import "./app.css"

  let sidebarOpen = $state(false)

  function toggleSidebar() {
    sidebarOpen = !sidebarOpen
  }

  function closeSidebar() {
    sidebarOpen = false
  }

  // Close sidebar on navigation (mobile UX)
  $effect(() => {
    fileStore.currentPath
    sidebarOpen = false
  })

  onMount(() => {
    if (!fileStore.fmGateVisible) {
      fileStore.navigateTo("/")
    }
  })
</script>

<div class="anibas-fm-app">
  <header class="fm-header">
    <!-- Logo doubles as sidebar toggle on mobile -->
    <button class="logo-btn" onclick={toggleSidebar} aria-label="Toggle file tree">
      <img
        src="{(window as any).AnibasFM?.pluginUrl}afm-logo.svg"
        alt="Anibas File Manager"
        class="logo-img"
      />
    </button>
    <div class="status">
        {#if fileStore.isLoading}
            <span class="loading-spinner"></span>
        {/if}
    </div>
  </header>

  <div class="fm-layout">
    <!-- Backdrop: closes sidebar when tapping outside on mobile -->
    {#if sidebarOpen}
      <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
      <div class="sidebar-backdrop" role="presentation" onclick={closeSidebar}></div>
    {/if}

    <aside class="fm-sidebar" class:sidebar-open={sidebarOpen}>
      <FileTree root="/" />
    </aside>

    <main class="fm-main">
      {#if fileStore.editorFile}
        <InlineEditor
          path={fileStore.editorFile.path}
          storage={fileStore.editorFile.storage}
          canEdit={fileStore.editorFile.canEdit}
        />
      {:else}
        <FileExplorer />
      {/if}
    </main>
  </div>

  {#if fileStore.error}
    <div class="fm-error-toast">
        {fileStore.error}
        <button onclick={() => fileStore.error = null}>&times;</button>
    </div>
  {/if}

  {#if fileStore.showBackupPrompt}
    <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
    <div class="backup-prompt-overlay">
      <div class="backup-prompt-dialog">
        <h3>Back up this file before editing?</h3>
        <p>A snapshot of the current file will be saved. You can restore it later from Settings ▸ Backups ▸ Single File Backups — up to 5 versions are kept per file.</p>
        <div class="backup-prompt-actions">
          <button class="bp-btn bp-cancel" onclick={() => fileStore.cancelEdit()} disabled={fileStore.backupRunning}>Cancel</button>
          <button class="bp-btn bp-skip" onclick={() => fileStore.skipBackupAndEdit()} disabled={fileStore.backupRunning}>Skip Backup</button>
          <button class="bp-btn bp-backup" onclick={() => fileStore.backupBeforeEdit()} disabled={fileStore.backupRunning}>
            {fileStore.backupRunning ? 'Backing up…' : 'Backup First'}
          </button>
        </div>
      </div>
    </div>
  {/if}

  <BackupModal
    visible={fileStore.showBackupModal}
    onclose={() => fileStore.backupFinishedThenEdit()}
    onstarted={() => { fileStore.backupRunning = true }}
    oncomplete={() => { fileStore.backupRunning = false }}
  />
</div>

<style>
  :global(:root) {
    --sidebar-width: 280px;
    --header-height: 50px;
    --border-color: #e0e0e0;
    --bg-light: #f9f9f9;
    --primary-color: #2271b1;
    --text-color: #3c434a;
  }

  :global(*) {
    cursor: default;
  }

  :global(button), :global(a), :global([role="button"]) {
    cursor: pointer;
  }

  :global(input), :global(textarea), :global(select) {
    cursor: text;
  }

  :global(input[type="checkbox"]), :global(input[type="radio"]) {
    cursor: pointer;
  }

  .anibas-fm-app {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 120px); /* Adjust for WP admin bar and padding */
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
    color: var(--text-color);
    background: #fff;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    overflow: hidden;
  }

  .fm-header {
    height: var(--header-height);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 15px;
    border-bottom: 1px solid var(--border-color);
    background: var(--bg-light);
  }

  .logo-btn {
    display: flex;
    align-items: center;
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
  }

  .logo-img {
    height: 5em;
    width: auto;
    max-width: 200px;
    display: block;
    object-fit: contain;
  }

  .fm-layout {
    display: flex;
    flex: 1;
    overflow: hidden;
    position: relative; /* needed for absolute sidebar on mobile */
  }

  .fm-sidebar {
    width: var(--sidebar-width);
    border-right: 1px solid var(--border-color);
    overflow-y: auto;
    background: var(--bg-light);
    flex-shrink: 0;
    transition: transform 0.25s ease;
  }

  .sidebar-backdrop {
    display: none;
  }

  .fm-main {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }

  /* ── Mobile ─────────────────────────────────────────────────── */
  @media (max-width: 782px) {
    .fm-sidebar {
      position: absolute;
      top: 0;
      left: 0;
      height: 100%;
      z-index: 50;
      transform: translateX(-100%);
      box-shadow: none;
    }

    .fm-sidebar.sidebar-open {
      transform: translateX(0);
      box-shadow: 4px 0 20px rgba(0, 0, 0, 0.18);
    }

    .sidebar-backdrop {
      display: block;
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, 0.35);
      z-index: 49;
    }
  }

  .loading-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid rgba(0,0,0,0.1);
    border-top: 2px solid var(--primary-color);
    border-radius: 50%;
    display: inline-block;
    animation: spin 1s linear infinite;
  }

  @keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
  }

  .fm-error-toast {
      position: fixed;
      bottom: 20px;
      right: 20px;
      background: #d63638;
      color: white;
      padding: 10px 15px;
      border-radius: 4px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.2);
      display: flex;
      align-items: center;
      gap: 10px;
      z-index: 10000;
  }

  .fm-error-toast button {
      background: transparent;
      border: none;
      color: white;
      cursor: pointer;
      font-size: 18px;
  }

  .backup-prompt-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.6);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 100000;
  }

  .backup-prompt-dialog {
      background: #fff;
      border-radius: 8px;
      padding: 28px;
      max-width: 440px;
      width: 90%;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
  }

  .backup-prompt-dialog h3 {
      margin: 0 0 10px;
      font-size: 18px;
      color: #1d2327;
  }

  .backup-prompt-dialog p {
      color: #646970;
      font-size: 14px;
      line-height: 1.5;
      margin: 0 0 20px;
  }

  .backup-prompt-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
  }

  .bp-btn {
      padding: 8px 18px;
      border: none;
      border-radius: 4px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
  }

  .bp-cancel {
      background: #f0f0f1;
      color: #2c3338;
      border: 1px solid #8c8f94;
  }

  .bp-cancel:hover {
      background: #e5e5e5;
  }

  .bp-skip {
      background: #f0f0f1;
      color: #2271b1;
      border: 1px solid #2271b1;
  }

  .bp-skip:hover {
      background: #e8f0fe;
  }

  .bp-backup {
      background: #2271b1;
      color: #fff;
  }

  .bp-backup:hover {
      background: #135e96;
  }
</style>
