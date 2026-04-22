<script lang="ts">
    import { __ } from "../../utils/i18n";
    import { toast } from "../../utils/toast";
    import {
        listTrash,
        restoreTrash as apiRestoreTrash,
        emptyTrashBin,
    } from "../../services/fileApi";

    let { onClose, onRestore } = $props<{ onClose: () => void; onRestore: (path: string) => void }>();

    let trashItems = $state<any[]>([]);
    let isLoading = $state(true);
    let isEmptying = $state(false);
    let restoringItem = $state<string | null>(null);

    async function fetchTrash() {
        isLoading = true;
        try {
            trashItems = await listTrash();
        } catch (err: any) {
            toast.error(err.message || __("Error loading trash"));
        } finally {
            isLoading = false;
        }
    }

    async function restoreTrash(trashName: string) {
        restoringItem = trashName;
        try {
            const data = await apiRestoreTrash(trashName);
            const restoredTo: string = data?.restored_to || '/';
            const restoredFolder = restoredTo.includes('/')
                ? restoredTo.substring(0, restoredTo.lastIndexOf('/')) || '/'
                : '/';
            toast.success(data?.message || __("Restored successfully"));
            onClose();
            onRestore(restoredFolder);
        } catch (e: any) {
            toast.error(e.message || __("Error restoring item"));
        } finally {
            restoringItem = null;
        }
    }

    async function emptyTrash() {
        if (!confirm(__("Are you sure you want to permanently delete all items in the trash?"))) return;
        isEmptying = true;
        try {
            const data = await emptyTrashBin();
            toast.success(data?.message || __("Trash emptied"));
            trashItems = [];
        } catch (e: any) {
            toast.error(e.message || __("Error emptying trash"));
        } finally {
            isEmptying = false;
        }
    }

    $effect(() => {
        fetchTrash();
    });

    function formatSize(bytes: number) {
        if (!bytes || bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function formatDate(ts: number) {
        if (!ts) return "";
        return new Date(ts * 1000).toLocaleString();
    }
</script>

<div class="anibas-storage-modal-overlay" onclick={onClose} role="button" tabindex="-1" onkeydown={(e) => e.key === 'Escape' && onClose()}>
    <div class="anibas-storage-modal-content trash-modal" onclick={(e) => e.stopPropagation()} role="button" tabindex="0" onkeydown={(e) => e.stopPropagation()}>
        <div class="trash-header">
            <h3>{__("Trash")}</h3>
            <button class="btn btn-icon btn-icon-danger" onclick={emptyTrash} disabled={isEmptying || trashItems.length === 0} data-tooltip={__("Empty Trash")} aria-label={__("Empty Trash")}>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"></polyline>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                </svg>
            </button>
        </div>

        {#if isLoading}
            <div class="trash-state">{__("Loading...")}</div>
        {:else if trashItems.length === 0}
            <div class="trash-state">{__("Trash is empty.")}</div>
        {:else}
            <div class="trash-list">
                {#each trashItems as item}
                    <div class="trash-item">
                        <div class="trash-info">
                            <span class="trash-name" title={item.name}>{item.name}</span>
                            <span class="trash-meta">{formatDate(item.trashed_at)} {item.is_folder ? '' : '• ' + formatSize(item.filesize)}</span>
                        </div>
                        <button class="btn btn-secondary btn-sm" onclick={() => restoreTrash(item.trash_name)} disabled={restoringItem === item.trash_name}>
                            {restoringItem === item.trash_name ? __("Restoring...") : __("Restore")}
                        </button>
                    </div>
                {/each}
            </div>
        {/if}

        <div class="anibas-storage-modal-actions" style="margin-top: 20px;">
            <button class="anibas-storage-btn-cancel" onclick={onClose}>{__("Close")}</button>
        </div>
    </div>
</div>

<style>
.anibas-storage-modal-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); display: flex !important; align-items: center; justify-content: center; z-index: 999999;
}
.trash-modal {
    background: white; border-radius: 4px; padding: 24px; min-width: 500px; max-width: 600px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); display: flex; flex-direction: column; max-height: 80vh;
}
.trash-header {
    display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ddd; padding-bottom: 12px; margin-bottom: 15px; gap: 10px;
}
.trash-header h3 { margin: 0; font-size: 16px; font-weight: 600; color: #1d2327; }
.trash-state { text-align: center; padding: 30px; color: #666; font-style: italic; }
.trash-list { height: 350px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; padding-right: 5px; }
.trash-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; border: 1px solid #eee; border-radius: 4px; background: #fafafa; }
.trash-info { display: flex; flex-direction: column; gap: 4px; overflow: hidden; text-align: left; }
.trash-name { font-weight: 500; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 400px; }
.trash-meta { font-size: 11px; color: #777; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 400px; }
.anibas-storage-modal-actions { display: flex; justify-content: flex-end; }
.anibas-storage-btn-cancel { padding: 8px 16px; border-radius: 4px; font-size: 14px; cursor: pointer; border: none; font-weight: 500; background: #f0f0f1; color: #2c3338; }
.btn-sm { padding: 4px 10px; font-size: 12px; }
</style>
