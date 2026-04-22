<script lang="ts">
    import { onMount, onDestroy } from "svelte";

    let {
        selectedPaths = [],
        onClose,
        onSave,
    } = $props<{
        selectedPaths: string[];
        onClose: () => void;
        onSave: (paths: string[]) => void;
    }>();

    const config = (window as any).AnibasFMSettings;

    let tree = $state<Record<string, any>>({});
    let selected = $state<Set<string>>(new Set(selectedPaths));
    let loading = $state(false);
    let expandedPaths = $state<Set<string>>(new Set(["/"]));

    onMount(() => {
        loadDirectory("/");
        document.body.style.overflow = "hidden";
    });

    onDestroy(() => {
        document.body.style.overflow = "";
    });

    function handleOverlayClick(e: MouseEvent) {
        if (e.target === e.currentTarget) {
            onClose();
        }
    }

    function handleKeyDown(e: KeyboardEvent) {
        if (e.key === "Escape") {
            onClose();
        }
    }

    async function loadDirectory(path: string) {
        if (tree[path]) return;

        loading = true;
        try {
            const url = new URL(config.ajaxURL);
            url.searchParams.set("action", config.actions.getFileList);
            url.searchParams.set("nonce", config.listNonce);
            url.searchParams.set("dir", path);
            url.searchParams.set("page", "1");

            const res = await fetch(url.toString());
            const json = await res.json();

            if (json.success && json.data.items) {
                const items = Object.keys(json.data.items)
                    .map((key) => {
                        const item = json.data.items[key];
                        return {
                            ...item,
                            name: item.filename || key,
                        };
                    });

                tree[path] = { items };
                tree = { ...tree };
            }
        } catch (err) {
            console.error("Failed to load directory:", err);
        } finally {
            loading = false;
        }
    }

    async function toggleFolder(path: string) {
        if (expandedPaths.has(path)) {
            expandedPaths.delete(path);
            expandedPaths = new Set(expandedPaths);
        } else {
            expandedPaths.add(path);
            expandedPaths = new Set(expandedPaths);
            if (!tree[path]) {
                await loadDirectory(path);
            }
        }
    }

    function toggleSelection(path: string) {
        if (selected.has(path)) {
            selected.delete(path);
        } else {
            selected.add(path);
        }
        selected = new Set(selected);
    }

    function handleSave() {
        onSave(Array.from(selected));
    }

    function renderTree(path: string, level = 0): any[] {
        const node = tree[path];
        if (!node || !node.items) return [];

        let result: any[] = [];

        for (const item of node.items) {
            const isExpanded = item.is_folder && expandedPaths.has(item.path);
            result.push({
                item,
                level,
                hasChildren: item.is_folder && item.has_children,
                isExpanded,
            });

            if (isExpanded && tree[item.path]) {
                result = result.concat(renderTree(item.path, level + 1));
            }
        }

        return result;
    }
</script>

<div
    class="modal d-block"
    role="dialog"
    aria-modal="true"
    style="background: rgba(0, 0, 0, 0.7); z-index: 999999;"
>
    <div
        class="modal-backdrop-click"
        onclick={handleOverlayClick}
        onkeydown={handleKeyDown}
        role="button"
        tabindex="-1"
        aria-label="Close modal"
        style="position: absolute; inset: 0;"
    ></div>
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="position: relative; z-index: 1;">
            <div class="modal-header">
                <h5 class="modal-title">Select Paths to Exclude</h5>
                <button
                    type="button"
                    class="btn-close"
                    onclick={onClose}
                    aria-label="Close"
                ></button>
            </div>

            <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">
                <div class="tree-container">
                    {#each renderTree("/") as { item, level, hasChildren, isExpanded }}
                        <div
                            class="tree-item d-flex align-items-center py-1"
                            style="padding-left: {level * 20}px"
                        >
                            <button
                                onclick={() => toggleFolder(item.path)}
                                disabled={!item.is_folder || !hasChildren}
                                class="btn btn-sm btn-link p-0 me-1"
                                style="width: 20px; height: 20px; {!item.is_folder || !hasChildren
                                    ? 'visibility: hidden;'
                                    : ''}"
                            >
                                {#if item.is_folder && hasChildren}
                                    <span
                                        style="font-size: 10px; display: inline-block; transition: transform 0.2s; {isExpanded
                                            ? 'transform: rotate(90deg);'
                                            : ''}">▶</span
                                    >
                                {/if}
                            </button>

                            <label
                                class="d-flex align-items-center flex-grow-1 px-2 py-1 rounded hover-bg"
                                style="cursor: pointer; margin-bottom: 0;"
                            >
                                <input
                                    type="checkbox"
                                    checked={selected.has(item.path)}
                                    onchange={() => toggleSelection(item.path)}
                                    class="form-check-input me-2"
                                    style="margin-top: 0;"
                                />
                                <span class="me-2" style="font-size: 14px;">
                                    {#if item.is_folder}
                                        {isExpanded ? "📂" : "📁"}
                                    {:else}
                                        📄
                                    {/if}
                                </span>
                                <span style="font-size: 14px;">{item.name}</span>
                            </label>
                        </div>
                    {/each}

                    {#if loading}
                        <div class="text-center p-3 text-muted fst-italic">
                            Loading...
                        </div>
                    {/if}
                </div>
            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick={onClose}>Cancel</button
                >
                <button
                    type="button"
                    class="btn btn-primary"
                    onclick={handleSave}
                >
                    Save ({selected.size} selected)
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    .tree-container {
        user-select: none;
    }

    .hover-bg:hover {
        background-color: #f8f9fa;
    }
</style>
