<script lang="ts">
    import { deleteFileBackup, deleteFileBackupTree, deleteSiteBackup, listFileBackups, restoreFileBackup, listSiteBackups } from "../../services/fileApi";

    type Tab = 'files' | 'site';
    let { authToken = null } = $props<{ authToken?: string | null }>();

    let activeTab = $state<Tab>('files');

    let fileGroups = $state<any[]>([]);
    let siteBackups = $state<any[]>([]);
    let isLoading = $state(false);
    let loadedFiles = $state(false);
    let loadedSite = $state(false);
    let error = $state<string | null>(null);
    let restoring = $state<string | null>(null);
    let deleting = $state<string | null>(null);
    let restoredMessage = $state<string | null>(null);

    let expandedGroups = $state<Record<string, boolean>>({});
    function toggleGroup(key: string) {
        expandedGroups = { ...expandedGroups, [key]: !expandedGroups[key] };
    }

    async function loadFiles() {
        isLoading = true;
        error = null;
        try {
            fileGroups = await listFileBackups(authToken);
            loadedFiles = true;
        } catch (e: any) {
            error = e?.message || 'Failed to load file backups';
        } finally {
            isLoading = false;
        }
    }

    async function loadSite() {
        isLoading = true;
        error = null;
        try {
            siteBackups = await listSiteBackups(authToken);
            loadedSite = true;
        } catch (e: any) {
            error = e?.message || 'Failed to load site backups';
        } finally {
            isLoading = false;
        }
    }

    function selectTab(tab: Tab) {
        if (tab === activeTab) return;
        activeTab = tab;
        error = null;
        restoredMessage = null;
        if (tab === 'files' && !loadedFiles) loadFiles();
        else if (tab === 'site' && !loadedSite) loadSite();
    }

    async function restore(key: string, version: string) {
        const token = key + '__' + version;
        restoring = token;
        restoredMessage = null;
        error = null;
        try {
            const data = await restoreFileBackup(key, version, authToken);
            restoredMessage = data?.message
                ? data.message + (data.restored_to ? ' → ' + data.restored_to : '')
                : 'Backup restored';
        } catch (e: any) {
            error = e?.message || 'Failed to restore backup';
        } finally {
            restoring = null;
        }
    }

    async function removeFileBackup(key: string, version: string) {
        const token = 'file__' + key + '__' + version;
        if (!confirm('Delete this backup version?')) return;
        deleting = token;
        restoredMessage = null;
        error = null;
        try {
            const data = await deleteFileBackup(key, version, authToken);
            fileGroups = fileGroups
                .map((group) => group.key !== key
                    ? group
                    : { ...group, versions: group.versions.filter((ver: any) => ver.name !== version) })
                .filter((group) => group.versions.length > 0);
            restoredMessage = data?.message || 'Backup deleted';
        } catch (e: any) {
            error = e?.message || 'Failed to delete backup';
        } finally {
            deleting = null;
        }
    }

    async function removeFileBackupTree(key: string) {
        const token = 'filetree__' + key;
        if (!confirm('Delete the full backup history for this file?')) return;
        deleting = token;
        restoredMessage = null;
        error = null;
        try {
            const data = await deleteFileBackupTree(key, authToken);
            fileGroups = fileGroups.filter((group) => group.key !== key);
            restoredMessage = data?.message || 'Backup history deleted';
        } catch (e: any) {
            error = e?.message || 'Failed to delete backup history';
        } finally {
            deleting = null;
        }
    }

    async function removeSiteBackup(name: string) {
        const token = 'site__' + name;
        if (!confirm('Delete this site backup?')) return;
        deleting = token;
        restoredMessage = null;
        error = null;
        try {
            const data = await deleteSiteBackup(name, authToken);
            siteBackups = siteBackups.filter((item) => item.name !== name);
            restoredMessage = data?.message || 'Backup deleted';
        } catch (e: any) {
            error = e?.message || 'Failed to delete site backup';
        } finally {
            deleting = null;
        }
    }

    $effect(() => {
        if (!loadedFiles) loadFiles();
    });

    function formatSize(bytes: number) {
        if (!bytes || bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function formatDate(ts: number) {
        if (!ts) return '';
        return new Date(ts * 1000).toLocaleString();
    }
</script>

<div class="card max-w-100">
    <h3>Backups</h3>
    <div class="backups-tabs">
        <button
            type="button"
            class="backup-tab-btn"
            class:active={activeTab === 'files'}
            onclick={() => selectTab('files')}
        >Single File Backups</button>
        <button
            type="button"
            class="backup-tab-btn"
            class:active={activeTab === 'site'}
            onclick={() => selectTab('site')}
        >Full Site Backups</button>
    </div>

    {#if activeTab === 'files'}
        <p class="description">
            Snapshots of individual files taken before each edit. The last 5 versions are kept per file.
        </p>
    {:else}
        <p class="description">
            Full site archives created from General settings. Restoring is not available here - listing only.
        </p>
    {/if}

    {#if error}
        <div class="backup-msg error">{error}</div>
    {/if}
    {#if restoredMessage}
        <div class="backup-msg success">{restoredMessage}</div>
    {/if}

    <div class="backup-results">
        {#if isLoading}
            <div class="backup-state">Loading…</div>
        {:else if activeTab === 'files'}
            {#if fileGroups.length === 0}
                <div class="backup-state">No file backups yet. They are created automatically when you edit a file.</div>
            {:else}
                <div class="backup-list">
                    {#each fileGroups as group}
                        <div class="backup-group">
                            <div class="backup-group-top">
                                <button type="button" class="backup-group-header" onclick={() => toggleGroup(group.key)}>
                                    <span class="chevron" class:open={expandedGroups[group.key]}>▸</span>
                                    <div class="backup-info">
                                        <span class="backup-name" title={group.source}>{group.basename}</span>
                                        <span class="backup-meta">
                                            {group.storage} • {group.source} • {group.versions.length} {group.versions.length === 1 ? 'version' : 'versions'}
                                        </span>
                                    </div>
                                </button>
                                <button
                                    type="button"
                                    class="backup-icon-btn group-delete-btn"
                                    onclick={() => removeFileBackupTree(group.key)}
                                    disabled={deleting === 'filetree__' + group.key}
                                    title="Delete all backups for this file"
                                    aria-label="Delete all backups for this file"
                                >
                                    {deleting === 'filetree__' + group.key ? '...' : '×'}
                                </button>
                            </div>
                            {#if expandedGroups[group.key]}
                                <div class="backup-versions">
                                    {#each group.versions as ver}
                                        {@const token = group.key + '__' + ver.name}
                                        <div class="backup-version">
                                            <div class="backup-info">
                                                <span class="backup-name">{formatDate(ver.mtime)}</span>
                                                <span class="backup-meta">{formatSize(ver.filesize)} • {ver.name}</span>
                                            </div>
                                            <div class="backup-actions">
                                                <button
                                                    type="button"
                                                    class="btn btn-secondary btn-sm"
                                                    onclick={() => restore(group.key, ver.name)}
                                                    disabled={restoring === token || deleting === 'file__' + group.key + '__' + ver.name}
                                                >
                                                    {restoring === token ? 'Restoring…' : 'Restore'}
                                                </button>
                                                <button
                                                    type="button"
                                                    class="backup-icon-btn"
                                                    onclick={() => removeFileBackup(group.key, ver.name)}
                                                    disabled={deleting === 'file__' + group.key + '__' + ver.name || restoring === token}
                                                    title="Delete backup"
                                                    aria-label="Delete backup"
                                                >
                                                    {deleting === 'file__' + group.key + '__' + ver.name ? '...' : '×'}
                                                </button>
                                            </div>
                                        </div>
                                    {/each}
                                </div>
                            {/if}
                        </div>
                    {/each}
                </div>
            {/if}
        {:else}
            {#if siteBackups.length === 0}
                <div class="backup-state">No full-site backups yet. Use the Site Backup card in General settings to create one.</div>
            {:else}
                <div class="backup-list">
                    {#each siteBackups as item}
                        <div class="backup-item">
                            <div class="backup-info">
                                <span class="backup-name" title={item.name}>{item.name}</span>
                                <span class="backup-meta">{formatDate(item.mtime)} • {formatSize(item.filesize)} • {item.format.toUpperCase()}</span>
                            </div>
                            <button
                                type="button"
                                class="backup-icon-btn"
                                onclick={() => removeSiteBackup(item.name)}
                                disabled={deleting === 'site__' + item.name}
                                title="Delete backup"
                                aria-label="Delete backup"
                            >
                                {deleting === 'site__' + item.name ? '...' : '×'}
                            </button>
                        </div>
                    {/each}
                </div>
            {/if}
        {/if}
    </div>
</div>

<style>
    .card {
        margin-bottom: 30px;
        padding-bottom: 30px;
        border-bottom: 1px solid #eee;
    }
    .max-w-100 {
        max-width: 100%;
    }
    h3 {
        margin: 0 0 5px;
        font-size: 18px;
    }
    .description {
        color: #666;
        margin: 0 0 20px;
        font-size: 14px;
    }
    .btn {
        padding: 8px 16px;
        border: none;
        border-radius: 4px;
        font-size: 14px;
        cursor: pointer;
        font-weight: 500;
    }
    .btn-secondary {
        background: #f0f0f1;
        color: #2c3338;
        border: 1px solid #8c8f94;
    }
    .btn-secondary:hover:not(:disabled) {
        background: #e5e5e5;
    }
    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    .backups-tabs {
        display: flex;
        gap: 4px;
        border-bottom: 1px solid #ddd;
        margin: 0 0 12px;
    }
    .backup-tab-btn {
        background: none;
        border: none;
        padding: 8px 14px;
        font-size: 14px;
        font-weight: 500;
        color: #646970;
        cursor: pointer;
        border-bottom: 2px solid transparent;
        margin-bottom: -1px;
    }
    .backup-tab-btn.active {
        color: #1d2327;
        border-bottom-color: #2271b1;
    }
    .backup-state {
        text-align: center;
        padding: 22px;
        color: #666;
        font-style: italic;
    }
    .backup-msg {
        padding: 8px 12px;
        border-radius: 4px;
        font-size: 13px;
        margin-bottom: 10px;
    }
    .backup-msg.error { background: #fcf0f1; color: #a00; border: 1px solid #eba3a7; }
    .backup-msg.success { background: #edfaef; color: #1e6b2a; border: 1px solid #a7d9b0; }
    .backup-results {
        max-height: 360px;
        max-height: min(420px, 52vh);
        overflow-y: auto;
        overscroll-behavior: contain;
        padding-right: 4px;
    }
    .backup-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding-right: 4px;
    }
    .backup-item,
    .backup-group {
        border: 1px solid #eee;
        border-radius: 4px;
        background: #fafafa;
    }
    .backup-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 15px;
    }
    .backup-group-top {
        display: flex;
        align-items: stretch;
        gap: 8px;
        padding: 0 10px 0 0;
    }
    .backup-actions {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .backup-group-header {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
        padding: 10px 15px;
        background: transparent;
        border: none;
        cursor: pointer;
        text-align: left;
        flex: 1 1 auto;
        min-width: 0;
    }
    .backup-group-header:hover { background: #f0f0f1; }
    .chevron {
        display: inline-block;
        transition: transform 0.15s ease;
        color: #646970;
        font-size: 12px;
    }
    .backup-icon-btn {
        width: 28px;
        height: 28px;
        border: 1px solid #dcdcde;
        border-radius: 4px;
        background: #fff;
        color: #b32d2e;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        line-height: 1;
        padding: 0;
        flex: 0 0 28px;
    }
    .backup-icon-btn:hover:not(:disabled) {
        background: #fcf0f1;
        border-color: #d63638;
    }
    .backup-icon-btn:disabled {
        opacity: 0.6;
        cursor: default;
    }
    .group-delete-btn {
        align-self: center;
        margin-right: 4px;
    }
    .chevron.open { transform: rotate(90deg); }
    .backup-versions {
        padding: 0 10px 10px 30px;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .backup-version {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 12px;
        border: 1px solid #eee;
        border-radius: 4px;
        background: #fff;
    }
    .backup-info {
        display: flex;
        flex-direction: column;
        gap: 3px;
        overflow: hidden;
        text-align: left;
        min-width: 0;
    }
    .backup-name {
        font-weight: 500;
        font-size: 14px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 420px;
    }
    .backup-meta {
        font-size: 11px;
        color: #777;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 420px;
    }
    .btn-sm { padding: 4px 10px; font-size: 12px; }
</style>
