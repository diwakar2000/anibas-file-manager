<script lang="ts">
    import { listFileBackups, restoreFileBackup, listSiteBackups } from "../../services/fileApi";

    type Tab = 'files' | 'site';
    let activeTab = $state<Tab>('files');

    let fileGroups = $state<any[]>([]);
    let siteBackups = $state<any[]>([]);
    let isLoading = $state(false);
    let loadedFiles = $state(false);
    let loadedSite = $state(false);
    let error = $state<string | null>(null);
    let restoring = $state<string | null>(null);
    let restoredMessage = $state<string | null>(null);

    let expandedGroups = $state<Record<string, boolean>>({});
    function toggleGroup(key: string) {
        expandedGroups = { ...expandedGroups, [key]: !expandedGroups[key] };
    }

    async function loadFiles() {
        isLoading = true;
        error = null;
        try {
            fileGroups = await listFileBackups();
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
            siteBackups = await listSiteBackups();
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
            const data = await restoreFileBackup(key, version);
            restoredMessage = data?.message
                ? data.message + (data.restored_to ? ' → ' + data.restored_to : '')
                : 'Backup restored';
        } catch (e: any) {
            error = e?.message || 'Failed to restore backup';
        } finally {
            restoring = null;
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
            Full site archives created from the <strong>Site Backup</strong> card. Restoring is not available here — listing only.
        </p>
    {/if}

    {#if error}
        <div class="backup-msg error">{error}</div>
    {/if}
    {#if restoredMessage}
        <div class="backup-msg success">{restoredMessage}</div>
    {/if}

    {#if isLoading}
        <div class="backup-state">Loading…</div>
    {:else if activeTab === 'files'}
        {#if fileGroups.length === 0}
            <div class="backup-state">No file backups yet. They are created automatically when you edit a file.</div>
        {:else}
            <div class="backup-list">
                {#each fileGroups as group}
                    <div class="backup-group">
                        <button type="button" class="backup-group-header" onclick={() => toggleGroup(group.key)}>
                            <span class="chevron" class:open={expandedGroups[group.key]}>▸</span>
                            <div class="backup-info">
                                <span class="backup-name" title={group.source}>{group.basename}</span>
                                <span class="backup-meta">
                                    {group.storage} • {group.source} • {group.versions.length} {group.versions.length === 1 ? 'version' : 'versions'}
                                </span>
                            </div>
                        </button>
                        {#if expandedGroups[group.key]}
                            <div class="backup-versions">
                                {#each group.versions as ver}
                                    {@const token = group.key + '__' + ver.name}
                                    <div class="backup-version">
                                        <div class="backup-info">
                                            <span class="backup-name">{formatDate(ver.mtime)}</span>
                                            <span class="backup-meta">{formatSize(ver.filesize)} • {ver.name}</span>
                                        </div>
                                        <button
                                            type="button"
                                            class="btn btn-secondary btn-sm"
                                            onclick={() => restore(group.key, ver.name)}
                                            disabled={restoring === token}
                                        >
                                            {restoring === token ? 'Restoring…' : 'Restore'}
                                        </button>
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
            <div class="backup-state">No full-site backups yet. Use the <strong>Site Backup</strong> card above to create one.</div>
        {:else}
            <div class="backup-list">
                {#each siteBackups as item}
                    <div class="backup-item">
                        <div class="backup-info">
                            <span class="backup-name" title={item.name}>{item.name}</span>
                            <span class="backup-meta">{formatDate(item.mtime)} • {formatSize(item.filesize)} • {item.format.toUpperCase()}</span>
                        </div>
                    </div>
                {/each}
            </div>
        {/if}
    {/if}
</div>

<style>
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
    .backup-list {
        max-height: 350px;
        overflow-y: auto;
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
    .backup-group { overflow: hidden; }
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
    }
    .backup-group-header:hover { background: #f0f0f1; }
    .chevron {
        display: inline-block;
        transition: transform 0.15s ease;
        color: #646970;
        font-size: 12px;
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
