<script lang="ts">
  import { fileStore } from "../../stores/fileStore.svelte"

  function navigateToPart(index: number) {
    const parts = fileStore.currentPath.split("/").filter(Boolean);
    const path = "/" + parts.slice(0, index + 1).join("/");
    if (fileStore.isDeleting(path)) return;
    fileStore.navigateTo(path);
  }
  
  function navigateToRoot() {
    if (fileStore.isDeleting("/")) return;
    fileStore.navigateTo("/");
  }
</script>

<nav class="breadcrumbs-bar">
  <div class="breadcrumbs">
    <button class="crumb" onclick={navigateToRoot}>
      <span class="icon">🏠</span>
    </button>
    
    {#each fileStore.currentPath.split("/").filter(Boolean) as part, i}
      <span class="separator">/</span>
      <button class="crumb" onclick={() => navigateToPart(i)}>
        {part}
      </button>
    {/each}
  </div>

  <!-- View Toggle -->
  <div class="view-toggle">
    <button 
      class="view-btn" 
      class:active={fileStore.viewMode === 'list'}
      onclick={() => fileStore.setViewMode('list')}
      title="List view"
      aria-label="List view"
    >
      <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
        <rect x="1" y="2" width="14" height="2" rx="1"/>
        <rect x="1" y="7" width="14" height="2" rx="1"/>
        <rect x="1" y="12" width="14" height="2" rx="1"/>
      </svg>
    </button>
    <button 
      class="view-btn" 
      class:active={fileStore.viewMode === 'grid'}
      onclick={() => fileStore.setViewMode('grid')}
      title="Grid view"
      aria-label="Grid view"
    >
      <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
        <rect x="1" y="1" width="6" height="6" rx="1"/>
        <rect x="9" y="1" width="6" height="6" rx="1"/>
        <rect x="1" y="9" width="6" height="6" rx="1"/>
        <rect x="9" y="9" width="6" height="6" rx="1"/>
      </svg>
    </button>
  </div>
</nav>

<style>
  .breadcrumbs-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 15px;
    background: #fff;
    border-bottom: 1px solid #eee;
  }

  .breadcrumbs {
    display: flex;
    align-items: center;
    font-size: 13px;
    overflow-x: auto;
    white-space: nowrap;
  }

  .view-toggle {
    display: flex;
    gap: 4px;
    flex-shrink: 0;
    margin-left: 12px;
  }

  .view-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 4px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #fff;
    cursor: pointer;
    transition: all 0.15s ease;
  }

  .view-btn:hover {
    background: #f5f5f5;
    border-color: #ccc;
  }

  .view-btn.active {
    background: #e7f3ff;
    border-color: #2271b1;
    color: #2271b1;
  }

  .view-btn svg {
    opacity: 0.7;
  }

  .view-btn.active svg {
    opacity: 1;
  }

  .crumb {
    background: none;
    border: none;
    padding: 2px 6px;
    border-radius: 3px;
    cursor: pointer;
    color: #2271b1;
    transition: background 0.1s;
  }

  .crumb:hover {
    background: #f0f6fb;
    text-decoration: underline;
  }

  .separator {
    color: #999;
    margin: 0 2px;
  }

  .icon {
      font-size: 14px;
  }
</style>
