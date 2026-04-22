<script lang="ts">
  import { fileStore } from "../../stores/fileStore.svelte"
  import TreeNode from "./TreeNode.svelte"

  let { root = "/" } = $props<{ root?: string }>()
</script>

<div class="file-tree">
  <div class="tree-root">
    {#if fileStore.directoryCache[root] || fileStore.expandedFolders.includes(root)}
      <TreeNode path={root} label="Root" isRoot={true} />
    {/if}
  </div>
</div>

<style>
  .file-tree {
    padding: 10px 0;
    user-select: none;
  }
  
  .tree-root {
      margin-bottom: 5px;
  }
</style>
