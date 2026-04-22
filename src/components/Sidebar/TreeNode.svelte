<script lang="ts">
    import { fileStore } from "../../stores/fileStore.svelte";
    import TreeNode from "./TreeNode.svelte"

    let { path, label = "", isRoot = false } = $props<{
        path: string;
        label?: string;
        isRoot?: boolean;
    }>();

    const name = $derived(label || path.split("/").pop() || "/");

    async function handleToggle(e: MouseEvent) {
        e.stopPropagation();
        if (fileStore.isDeleting(path)) return;
        await fileStore.toggleFolder(path);
    }

    async function handleSelect() {
        if (fileStore.isDeleting(path)) return;
        await fileStore.navigateTo(path);
    }
</script>

<div class="tree-node" class:is-selected={fileStore.currentPath === path} class:is-deleting={fileStore.isDeleting(path)}>
    <div
        class="node-content"
        onclick={handleSelect}
        onkeydown={(e) => e.key === "Enter" && handleSelect()}
        role="button"
        tabindex="0"
    >
        <button
            class="toggle-btn"
            class:is-expanded={fileStore.isExpanded(path)}
            onclick={handleToggle}
            aria-label={fileStore.isExpanded(path) ? "Collapse" : "Expand"}
            disabled={fileStore.isDeleting(path)}
        >
            {#if !isRoot}
                <span class="chevron">▶</span>
            {/if}
        </button>
        <span class="icon">{fileStore.isExpanded(path) ? "📂" : "📁"}</span>
        <span class="name">{name}</span>
    </div>

    {#if fileStore.isExpanded(path)}
        <div class="node-children">
            {#if fileStore.directoryCache[path]}
                {#each fileStore.getFolders(path) as child (child.path)}
                    <TreeNode path={child.path} />
                {:else}
                    {#if fileStore.isLoading && !fileStore.directoryCache[path]}
                        <div class="node-loading">Loading...</div>
                    {/if}
                {/each}
            {/if}
            
            {#if fileStore.directoryCache[path]?.has_more}
                <button 
                    class="load-more-btn"
                    onclick={async () => {
                        const data = fileStore.directoryCache[path];
                        if (data) await fileStore.loadDirectory(path, data.page + 1);
                    }}
                    disabled={fileStore.isLoading}
                >
                    {fileStore.isLoading ? "Loading..." : "Load more..."}
                </button>
            {/if}
        </div>
    {/if}
</div>

<style>
    .tree-node {
        display: flex;
        flex-direction: column;
    }

    .node-content {
        display: flex;
        align-items: center;
        padding: 4px 8px;
        cursor: pointer;
        border-radius: 4px;
        margin: 0 4px;
        transition: background 0.1s;
        white-space: nowrap;
    }

    .node-content:hover {
        background: rgba(0, 0, 0, 0.05);
    }

    .is-selected > .node-content {
        background: #e7f3ff;
        color: #0060df;
        font-weight: 500;
    }

    .toggle-btn {
        background: none;
        border: none;
        padding: 0;
        margin-right: 4px;
        width: 16px;
        height: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #666;
    }

    .chevron {
        font-size: 8px;
        transition: transform 0.2s;
        display: inline-block;
    }

    .toggle-btn.is-expanded .chevron {
        transform: rotate(90deg);
    }

    .icon {
        margin-right: 6px;
        font-size: 14px;
    }

    .name {
        font-size: 13px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .node-children {
        margin-left: 16px;
        border-left: 1px solid #eee;
    }

    .node-loading {
        padding: 4px 24px;
        font-size: 12px;
        color: #999;
        font-style: italic;
    }

    .load-more-btn {
        margin: 4px 8px;
        padding: 4px 12px;
        background: #f0f0f0;
        border: 1px solid #ddd;
        border-radius: 3px;
        cursor: pointer;
        font-size: 11px;
        color: #666;
        width: calc(100% - 16px);
    }

    .load-more-btn:hover:not(:disabled) {
        background: #e0e0e0;
    }

    .load-more-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .is-deleting {
        opacity: 0.5;
        pointer-events: none;
    }
</style>
