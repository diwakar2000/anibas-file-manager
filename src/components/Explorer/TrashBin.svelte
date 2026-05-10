<script lang="ts">
    import { onMount } from "svelte";
    import { __ } from "../../utils/i18n";
    import { toast } from "../../utils/toast";
    import {
        listTrash,
        restoreTrash as apiRestoreTrash,
        emptyTrashBin,
        deleteTrashItem as apiDeleteTrashItem,
        getJobStatus as apiGetJobStatus,
    } from "../../services/fileApi";

    // Polling cadence for background delete jobs. Each poll hits
    // BackgroundProcessor::get_job_status, which has an inline fallback
    // that runs the next chunk of work when the worker lock is free — so
    // polling is literally what drives the delete forward when the
    // loopback dispatcher can't reach the nopriv endpoint.
    const JOB_POLL_INTERVAL_MS = 1000;

    async function waitForJobs(jobIds: string[]): Promise<void> {
        if (!jobIds || jobIds.length === 0) return;
        const pending = new Set(jobIds);
        while (pending.size > 0) {
            for (const id of Array.from(pending)) {
                try {
                    const job = await apiGetJobStatus(id);
                    // 'Job not found' comes back as an error, which means
                    // the job queue already cleaned it up after completion.
                    if (!job || job.status === 'completed' || job.status === 'failed') {
                        pending.delete(id);
                    }
                } catch {
                    // Treat a missing job as completed — the queue purges
                    // finished jobs after a short window.
                    pending.delete(id);
                }
            }
            if (pending.size > 0) {
                await new Promise(r => setTimeout(r, JOB_POLL_INTERVAL_MS));
            }
        }
    }

    let { onClose, onRestore } = $props<{ onClose: () => void; onRestore: (path: string) => void }>();

    let trashItems = $state<any[]>([]);
    let isLoading = $state(true);
    let isEmptying = $state(false);
    let restoringItem = $state<string | null>(null);
    let deletingItem = $state<string | null>(null);
    let trashPassword = $state("");
    let passwordRequired = $state(false);
    let trashPage = $state(1);
    let trashTotalItems = $state(0);
    let trashHasMore = $state(false);

    const hasDeletePassword = (window as any).AnibasFM?.hasDeletePassword ?? false;
    const trashPageSize = 50;
    const trashTotalPages = $derived.by(() => Math.max(1, Math.ceil(trashTotalItems / trashPageSize)));

    async function fetchTrash(page = trashPage) {
        isLoading = true;
        try {
            let targetPage = Math.max(1, page);
            while (true) {
                const data = await listTrash(targetPage, trashPageSize);
                const items = Array.isArray(data?.items) ? data.items : [];
                const total = Number(data?.total_items || 0);
                if (targetPage > 1 && items.length === 0 && total > 0) {
                    targetPage = Math.max(1, Math.ceil(total / trashPageSize));
                    continue;
                }

                trashItems = items;
                trashTotalItems = total;
                trashPage = Number(data?.page || targetPage);
                trashHasMore = Boolean(data?.has_more);
                break;
            }
        } catch (err: any) {
            toast.error(err.message || __("Error loading trash"));
        } finally {
            isLoading = false;
        }
    }

    async function goToTrashPage(page: number) {
        if (isLoading || isEmptying) return;
        await fetchTrash(Math.min(Math.max(1, page), trashTotalPages));
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
            const data = await emptyTrashBin(trashPassword);
            const jobIds: string[] = Array.isArray(data?.job_ids) ? data.job_ids : [];

            if (jobIds.length > 0) {
                // Surface the in-flight state, then block on the jobs so the
                // UI only declares "emptied" after the workers finish. The
                // polling itself drives each worker via the inline fallback.
                toast.success(data?.message || __("Emptying trash in the background…"));
                trashItems = [];
                trashTotalItems = 0;
                trashPage = 1;
                trashHasMore = false;
                passwordRequired = false;
                trashPassword = "";
                await waitForJobs(jobIds);
                toast.success(__("Trash emptied"));
                // Re-fetch to catch anything that might still be present
                // (e.g. a job that failed — list_trash will show it back).
                await fetchTrash();
            } else {
                toast.success(data?.message || __("Trash emptied"));
                trashItems = [];
                trashTotalItems = 0;
                trashPage = 1;
                trashHasMore = false;
                passwordRequired = false;
                trashPassword = "";
            }
        } catch (e: any) {
            if (e.message === "DeletePasswordRequired") {
                passwordRequired = true;
                toast.error(__("Delete password required"));
            } else {
                toast.error(e.message || __("Error emptying trash"));
            }
        } finally {
            isEmptying = false;
        }
    }

    async function deleteTrashItem(trashName: string) {
        if (!confirm(__("Are you sure you want to permanently delete this item?"))) return;
        deletingItem = trashName;
        try {
            const data = await apiDeleteTrashItem(trashName, trashPassword);
            const jobId: string | undefined = data?.job_id;

            if (jobId) {
                // Keep the row in the list (but marked as "deleting") until
                // the background job actually finishes. Polling drives the
                // worker when the loopback dispatcher can't.
                toast.success(data?.message || __("Deleting item in the background…"));
                passwordRequired = false;
                trashPassword = "";
                await waitForJobs([jobId]);
                await fetchTrash(trashPage);
                toast.success(__("Item permanently deleted"));
            } else {
                toast.success(data?.message || __("Item permanently deleted"));
                await fetchTrash(trashPage);
                passwordRequired = false;
                trashPassword = "";
            }
        } catch (e: any) {
            if (e.message === "DeletePasswordRequired") {
                passwordRequired = true;
                toast.error(__("Delete password required"));
            } else {
                toast.error(e.message || __("Error deleting item"));
            }
        } finally {
            deletingItem = null;
        }
    }

    onMount(() => {
        fetchTrash(1);
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
            <button class="btn btn-icon btn-icon-danger" onclick={emptyTrash} disabled={isEmptying || trashTotalItems === 0} data-tooltip={__("Empty Trash")} aria-label={__("Empty Trash")}>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"></polyline>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                </svg>
            </button>
        </div>

        {#if isLoading}
            <div class="trash-state">{__("Loading...")}</div>
        {:else if isEmptying}
            <div class="trash-state">
                <span class="spinner" style="margin-right: 8px;"></span> {__("Emptying trash... Please wait.")}
            </div>
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
                        <div class="trash-actions">
                            <button class="btn btn-secondary btn-sm" onclick={() => restoreTrash(item.trash_name)} disabled={restoringItem === item.trash_name || deletingItem === item.trash_name}>
                                {restoringItem === item.trash_name ? __("Restoring...") : __("Restore")}
                            </button>
                            <button class="btn btn-secondary btn-sm btn-danger-sm" onclick={() => deleteTrashItem(item.trash_name)} disabled={restoringItem === item.trash_name || deletingItem === item.trash_name}>
                                {deletingItem === item.trash_name ? __("Deleting...") : __("Delete")}
                            </button>
                        </div>
                    </div>
                {/each}
            </div>
            {#if trashTotalItems > trashPageSize}
                <div class="trash-pagination">
                    <button
                        class="trash-page-btn"
                        onclick={() => goToTrashPage(trashPage - 1)}
                        disabled={isLoading || trashPage <= 1}
                        aria-label={__("Previous page")}
                    >‹</button>
                    <span class="trash-page-meta">{trashPage} / {trashTotalPages}</span>
                    <button
                        class="trash-page-btn"
                        onclick={() => goToTrashPage(trashPage + 1)}
                        disabled={isLoading || !trashHasMore}
                        aria-label={__("Next page")}
                    >›</button>
                </div>
            {/if}
        {/if}

        {#if (passwordRequired || hasDeletePassword) && trashTotalItems > 0}
            <div class="trash-password-gate">
                <input type="password" bind:value={trashPassword} placeholder={__("Delete Password")} class="trash-password-input" />
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
.trash-actions { display: flex; gap: 8px; }
.trash-pagination { display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 12px; }
.trash-page-btn { width: 30px; height: 30px; border: 1px solid #dcdcde; border-radius: 4px; background: #fff; color: #1d2327; cursor: pointer; font-size: 18px; line-height: 1; }
.trash-page-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.trash-page-meta { font-size: 12px; color: #646970; min-width: 52px; text-align: center; }
.btn-sm { padding: 4px 10px; font-size: 12px; }
.btn-danger-sm { color: #d63638; border-color: #d63638; }
.btn-danger-sm:hover { background: #d63638; color: white; }
.spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid rgba(0,0,0,0.1);
    border-radius: 50%;
    border-top-color: #333;
    animation: spin 1s ease-in-out infinite;
    vertical-align: middle;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
.trash-password-gate { margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee; }
.trash-password-input { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
</style>
