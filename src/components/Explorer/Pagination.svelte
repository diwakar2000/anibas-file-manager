<script lang="ts">
  import { fileStore } from "../../stores/fileStore.svelte"

  let { path } = $props<{ path: string }>()

  const data = $derived(fileStore.directoryCache[path])

  async function loadMore() {
    if (!data || fileStore.isLoading) return
    await fileStore.loadDirectory(path, data.page + 1)
  }
</script>

{#if data && data.has_more}
  <div class="pagination">
    <button
      type="button"
      onclick={loadMore}
      disabled={fileStore.isLoading}
    >
      {fileStore.isLoading ? "Loading..." : `Load More (${data.total_items - (data.page * data.page_size)} remaining)`}
    </button>
  </div>
{/if}

<style>
  .pagination {
    padding: 12px;
    display: flex;
    justify-content: center;
  }

  button {
    padding: 6px 14px;
    border: 1px solid #ccc;
    background: white;
    cursor: pointer;
    border-radius: 4px;
    font-size: 12px;
  }

  button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  button:hover:not(:disabled) {
    background: #f0f0f0;
  }
</style>
