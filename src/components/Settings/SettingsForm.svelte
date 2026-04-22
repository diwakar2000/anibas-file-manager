<script lang="ts">
    import PathSelector from "./PathSelector.svelte";
    import BackupModal from "../Shared/BackupModal.svelte";
    import BackupsList from "./BackupsList.svelte";

    const CHUNK_SIZE_MIN = 1048576;   // 1 MB — must match ANIBAS_FM_CHUNK_SIZE_MIN
    const CHUNK_SIZE_MAX = 20971520;  // 20 MB — must match ANIBAS_FM_CHUNK_SIZE_MAX
    const CHUNK_SIZE_STEP = 1048576;  // 1 MB

    let { authToken = null, onPasswordChanged } = $props<{
        authToken: string | null;
        onPasswordChanged: () => void;
    }>();

    let showBackupModal = $state(false);
    let backupRunning = $state(false);

    const config = (window as any).AnibasFMSettings;

    let activeTab = $state<"general" | "security">("general");
    let currentPassword = $state("");
    let newPassword = $state("");
    let confirmPassword = $state("");
    let deletePassword = $state("");
    let confirmDeletePassword = $state("");
    let showPasswordFields = $state(false);
    let showDeletePasswordFields = $state(false);
    let currentDeletePassword = $state("");
    let showFmPasswordFields = $state(false);
    let fmCurrentPassword = $state("");
    let fmPassword = $state("");
    let confirmFmPassword = $state("");
    let fmRefreshRequired = $state<boolean>(config.fmPasswordRefreshRequired ?? true);
    let excludedPaths = $state<string[]>(config.excludedPaths || []);
    let chunkSize = $state<number>(config.chunkSize || 1048576);
    let deleteToTrash = $state<boolean>(config.deleteToTrash ?? false);
    let remoteFileBackupsEnabled = $state<boolean>(config.remoteFileBackupsEnabled ?? false);
    let debugMode = $state<boolean>(config.debugMode ?? false);
    let showPathSelector = $state(false);
    let removeSettingsPassword = $state(false);
    let loading = $state(false);
    let message = $state<{ type: "success" | "error"; text: string } | null>(
        null,
    );

    // Baseline snapshot for dirty-tracking. Reset after each successful save.
    let baseline = $state({
        chunkSize: config.chunkSize || 1048576,
        excludedPaths: JSON.stringify(config.excludedPaths || []),
        deleteToTrash: config.deleteToTrash ?? false,
        remoteFileBackupsEnabled: config.remoteFileBackupsEnabled ?? false,
        debugMode: config.debugMode ?? false,
        fmRefreshRequired: config.fmPasswordRefreshRequired ?? true,
    });

    const isDirty = $derived(
        chunkSize !== baseline.chunkSize ||
        deleteToTrash !== baseline.deleteToTrash ||
        remoteFileBackupsEnabled !== baseline.remoteFileBackupsEnabled ||
        debugMode !== baseline.debugMode ||
        fmRefreshRequired !== baseline.fmRefreshRequired ||
        JSON.stringify(excludedPaths) !== baseline.excludedPaths ||
        !!newPassword || !!deletePassword || !!fmPassword ||
        removeSettingsPassword
    );

    function resetBaseline() {
        baseline = {
            chunkSize,
            excludedPaths: JSON.stringify(excludedPaths),
            deleteToTrash,
            remoteFileBackupsEnabled,
            debugMode,
            fmRefreshRequired,
        };
    }

    async function handleSubmit(e: Event) {
        e.preventDefault();

        if (chunkSize < CHUNK_SIZE_MIN || chunkSize > CHUNK_SIZE_MAX) {
            message = { type: "error", text: "Chunk size must be between 1 MB and 20 MB" };
            return;
        }

        if (newPassword && newPassword !== confirmPassword) {
            message = { type: "error", text: "Passwords do not match" };
            return;
        }

        if (deletePassword && deletePassword !== confirmDeletePassword) {
            message = { type: "error", text: "Delete passwords do not match" };
            return;
        }

        if (fmPassword && fmPassword !== confirmFmPassword) {
            message = { type: "error", text: "File manager passwords do not match" };
            return;
        }

        if (showFmPasswordFields && config.hasFmPassword && !fmCurrentPassword) {
            message = { type: "error", text: "Current file manager password is required" };
            return;
        }

        if (showDeletePasswordFields && config.hasDeletePassword && !currentDeletePassword) {
            message = { type: "error", text: "Current delete password is required" };
            return;
        }

        if (showPasswordFields && !removeSettingsPassword && newPassword && !currentPassword && !authToken && config.hasPassword) {
            message = {
                type: "error",
                text: "Current password is required to change password",
            };
            return;
        }

        if (removeSettingsPassword && !currentPassword && !authToken) {
            message = {
                type: "error",
                text: "Current password is required to remove password",
            };
            return;
        }

        loading = true;
        message = null;

        try {
            const formData = new FormData();
            formData.append("action", "anibas_fm_save_settings");
            formData.append("nonce", config.nonce);

            if (newPassword) {
                // Always require current password for password change
                formData.append("password", currentPassword);
            } else if (authToken) {
                formData.append("token", authToken);
            } else {
                formData.append("password", currentPassword);
            }

            if (newPassword) {
                formData.append("new_password", newPassword);
            }
            if (removeSettingsPassword) {
                formData.append("remove_settings_password", "1");
            }
            if (showDeletePasswordFields) {
                formData.append("delete_password", deletePassword);
                if (config.hasDeletePassword) {
                    formData.append("current_delete_password", currentDeletePassword);
                }
            }
            if (showFmPasswordFields) {
                formData.append("fm_password", fmPassword);
                formData.append("fm_password_refresh_required", fmRefreshRequired ? "1" : "0");
                if (config.hasFmPassword) {
                    formData.append("fm_current_password", fmCurrentPassword);
                }
            }
            excludedPaths.forEach((path) => {
                formData.append("excluded_paths[]", path);
            });
            formData.append("chunk_size", chunkSize.toString());
            formData.append("delete_to_trash", deleteToTrash ? "1" : "0");
            formData.append("remote_file_backups_enabled", remoteFileBackupsEnabled ? "1" : "0");
            if (config.isLocalhost) {
                formData.append("debug_mode", debugMode ? "1" : "0");
            }

            const res = await fetch(config.ajaxURL, {
                method: "POST",
                body: formData,
            });

            const json = await res.json();

            if (json.success) {
                const didChangePassword = !!newPassword;
                const didRemovePassword = removeSettingsPassword;
                message = { type: "success", text: json.data.message };
                currentPassword = "";
                newPassword = "";
                confirmPassword = "";
                deletePassword = "";
                confirmDeletePassword = "";
                currentDeletePassword = "";
                showPasswordFields = false;
                showDeletePasswordFields = false;
                showFmPasswordFields = false;
                fmCurrentPassword = "";
                fmPassword = "";
                confirmFmPassword = "";
                removeSettingsPassword = false;
                resetBaseline();
                if (didChangePassword || didRemovePassword) {
                    onPasswordChanged();
                }
            } else {
                message = {
                    type: "error",
                    text: json.data || "Failed to save settings",
                };
            }
        } catch (err: any) {
            message = {
                type: "error",
                text: err.message || "Failed to save settings",
            };
        } finally {
            loading = false;
        }
    }

    function handlePathsSelected(paths: string[]) {
        excludedPaths = paths;
        showPathSelector = false;
    }

    function removePath(path: string) {
        excludedPaths = excludedPaths.filter((p) => p !== path);
    }
</script>

<div class="settings-form">
    <form onsubmit={handleSubmit} autocomplete="off">
        <div class="sticky-bar">
            <div class="tabs">
                <button
                    type="button"
                    class="tab-button"
                    class:active={activeTab === "general"}
                    onclick={() => activeTab = "general"}
                >
                    General
                </button>
                <button
                    type="button"
                    class="tab-button"
                    class:active={activeTab === "security"}
                    onclick={() => activeTab = "security"}
                >
                    Security
                </button>
            </div>
            <button
                type="submit"
                disabled={loading || !isDirty}
                class="btn btn-primary save-btn"
                class:dirty={isDirty}
            >
                {loading ? "Saving..." : isDirty ? "Save Changes" : "Saved"}
            </button>
        </div>

        {#if message}
            <div class="message message-{message.type}">
                {message.text}
            </div>
        {/if}

        <div class="form-content">
        {#if activeTab === "general"}
            <div class="card">
                <h3>Upload Settings</h3>
                <p class="description">
                    Configure file upload chunk size (1 MB - 20 MB).
                </p>

                <div class="form-group">
                    <label for="chunk-size">
                        Chunk Size: {(chunkSize / 1048576).toFixed(2)} MB
                    </label>
                    <input
                        id="chunk-size"
                        type="range"
                        bind:value={chunkSize}
                        min={CHUNK_SIZE_MIN}
                        max={CHUNK_SIZE_MAX}
                        step={CHUNK_SIZE_STEP}
                        class="slider"
                    />
                    <div class="slider-labels">
                        <span>1 MB</span>
                        <span>20 MB</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <h3>Delete Behavior</h3>
                <p class="description">
                    Choose whether deleted files are moved to a hidden trash folder or permanently removed.
                </p>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input
                            type="checkbox"
                            bind:checked={deleteToTrash}
                        />
                        Move deleted files to Trash
                    </label>
                    {#if deleteToTrash}
                        <div class="form-text">
                            Deleted files will be kept for 30 days in a hidden <code>.trash</code> folder inside <code>wp-content/uploads</code>, then automatically purged.
                        </div>
                    {:else}
                        <div class="warning-inline" style="margin-top: 8px;">
                            &#9888; Files will be permanently deleted with no recovery option.
                        </div>
                    {/if}
                </div>
            </div>

            <div class="card max-w-100">
                <h3>Excluded Paths</h3>
                <p class="description">
                    Select files and folders to exclude from the file manager.
                </p>

                {#if excludedPaths.length > 0}
                    <div class="excluded-list">
                        {#each excludedPaths as path}
                            <span class="excluded-chip">
                                {path}
                                <button
                                    type="button"
                                    onclick={() => removePath(path)}
                                    class="btn-remove-chip"
                                    title="Remove"
                                >×</button>
                            </span>
                        {/each}
                    </div>
                {:else}
                    <p class="no-paths">No paths excluded</p>
                {/if}

                <button
                    type="button"
                    onclick={() => (showPathSelector = true)}
                    class="btn btn-secondary"
                >
                    Manage Excluded Paths
                </button>
            </div>

            <div class="card max-w-100">
                <h3>Site Backup</h3>
                <p class="description">
                    Create a full backup of your site's wp-content directory and essential root files.
                    Backups are kept for 7 days before automatic cleanup.
                </p>

                <button
                    type="button"
                    onclick={() => (showBackupModal = true)}
                    class="btn btn-secondary"
                    disabled={backupRunning}
                >
                    {backupRunning ? "Backup In Progress..." : "Start Backup"}
                </button>
            </div>

            <BackupsList />

            <div class="card max-w-100">
                <h3>Remote File Backups</h3>
                <p class="description">
                    When editing files on remote storage (S3, FTP, SFTP), download and keep a copy before overwriting.
                    Local file edits are always backed up.
                </p>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input
                            type="checkbox"
                            bind:checked={remoteFileBackupsEnabled}
                        />
                        Back up remote files on save
                    </label>
                    {#if remoteFileBackupsEnabled}
                        <div class="form-text">
                            The last 5 versions of each edited file are kept per source path. Adds bandwidth overhead since each save downloads the current file first.
                        </div>
                    {:else}
                        <div class="form-text">
                            Remote edits will not be backed up. Recommended unless you copy remote files to local without reviewing.
                        </div>
                    {/if}
                </div>
            </div>

            {#if config.isLocalhost}
                <div class="card max-w-100">
                    <h3>Debug Mode</h3>
                    <p class="description">
                        Available on localhost only. When enabled, plugin log files will be visible in the file explorer.
                    </p>

                    <div class="form-group">
                        <label class="checkbox-label">
                            <input
                                type="checkbox"
                                bind:checked={debugMode}
                            />
                            Enable debug mode
                        </label>
                        {#if debugMode}
                            <div class="form-text">
                                Log directory is now visible in the file explorer. This setting is ignored on production servers.
                            </div>
                        {/if}
                    </div>
                </div>
            {/if}
        {:else}
            <div class="card">
                <h3>Settings Password Protection</h3>
                <p class="description">Set a password to protect these settings.</p>

                {#if config.hasPassword && !authToken}
                    <div class="form-group">
                        <label for="current-password">Current Password</label>
                        <div class="d-flex gap-2 align-items-start">
                            <input
                                id="current-password"
                                type="password"
                                bind:value={currentPassword}
                                class="form-control"
                                style="max-width: 400px;"
                                autocomplete="off"
                                required
                            />
                            <button
                                type="button"
                                onclick={() => {
                                    showPasswordFields = !showPasswordFields;
                                    removeSettingsPassword = false;
                                }}
                                class="btn btn-sm btn-outline-secondary"
                            >
                                {showPasswordFields ? "Cancel" : "Change Password"}
                            </button>
                            <button
                                type="button"
                                onclick={() => {
                                    removeSettingsPassword = !removeSettingsPassword;
                                    showPasswordFields = false;
                                    newPassword = "";
                                    confirmPassword = "";
                                }}
                                class="btn btn-sm btn-outline-danger"
                            >
                                {removeSettingsPassword ? "Cancel Removal" : "Remove Password"}
                            </button>
                        </div>
                    </div>
                {:else if config.hasPassword && authToken}
                    <div class="alert alert-info">
                        You are authenticated.
                        <button
                            type="button"
                            onclick={() => {
                                showPasswordFields = !showPasswordFields;
                                removeSettingsPassword = false;
                            }}
                            class="btn btn-sm btn-link p-0"
                        >
                            {showPasswordFields
                                ? "Cancel password change"
                                : "Change password"}
                        </button>
                        &nbsp;|&nbsp;
                        <button
                            type="button"
                            onclick={() => {
                                removeSettingsPassword = !removeSettingsPassword;
                                showPasswordFields = false;
                                newPassword = "";
                                confirmPassword = "";
                            }}
                            class="btn btn-sm btn-link p-0"
                            style="color: #d63638;"
                        >
                            {removeSettingsPassword
                                ? "Cancel removal"
                                : "Remove password"}
                        </button>
                    </div>
                {/if}

                {#if removeSettingsPassword}
                    <div class="alert alert-warning" style="margin-top: 15px;">
                        ⚠ This will remove settings password protection. You must provide your current password to confirm.
                    </div>
                {/if}

                {#if showPasswordFields || !config.hasPassword}
                    {#if config.hasPassword}
                        <div class="form-group">
                            <label for="current-password-change"
                                >Current Password</label
                            >
                            <input
                                id="current-password-change"
                                type="password"
                                bind:value={currentPassword}
                                class="form-control"
                                autocomplete="off"
                                required
                            />
                        </div>
                    {/if}

                    <div class="form-group">
                        <label for="new-password"
                            >{config.hasPassword
                                ? "New Password"
                                : "Set Password"}</label
                        >
                        <input
                            id="new-password"
                            type="password"
                            bind:value={newPassword}
                            class="form-control"
                            autocomplete="new-password"
                            required={!config.hasPassword}
                        />
                    </div>

                    <div class="form-group">
                        <label for="confirm-password"
                            >Confirm {config.hasPassword ? "New" : ""} Password</label
                        >
                        <input
                            id="confirm-password"
                            type="password"
                            bind:value={confirmPassword}
                            class="form-control"
                            autocomplete="new-password"
                            required={!config.hasPassword}
                        />
                    </div>
                {/if}
            </div>

            <div class="card">
                <h3>Delete Protection</h3>
                <p class="description">Require a password to delete files and folders.</p>

                <button
                    type="button"
                    onclick={() => (showDeletePasswordFields = !showDeletePasswordFields)}
                    class="btn btn-sm btn-outline-secondary"
                >
                    {showDeletePasswordFields ? "Cancel" : (config.hasDeletePassword ? "Change Delete Password" : "Set Delete Password")}
                </button>

                {#if showDeletePasswordFields}
                    {#if config.hasDeletePassword}
                        <div class="form-group" style="margin-top: 15px;">
                            <label for="current-delete-password">Current Delete Password</label>
                            <input
                                id="current-delete-password"
                                type="password"
                                bind:value={currentDeletePassword}
                                class="form-control"
                                autocomplete="off"
                                required
                            />
                        </div>
                    {/if}

                    <div class="form-group" style={!config.hasDeletePassword ? "margin-top: 15px;" : ""}>
                        <label for="delete-password">{config.hasDeletePassword ? "New Delete Password" : "Delete Password"}</label>
                        <input
                            id="delete-password"
                            type="password"
                            bind:value={deletePassword}
                            class="form-control"
                            placeholder={config.hasDeletePassword ? "Leave empty to remove password" : ""}
                            autocomplete="new-password"
                        />
                        {#if config.hasDeletePassword}
                            <small style="color: #666; display: block; margin-top: 5px;">
                                Leave empty to remove delete password protection
                            </small>
                        {/if}
                    </div>

                    {#if deletePassword}
                        <div class="form-group">
                            <label for="confirm-delete-password">Confirm Delete Password</label>
                            <input
                                id="confirm-delete-password"
                                type="password"
                                bind:value={confirmDeletePassword}
                                class="form-control"
                                autocomplete="new-password"
                                required
                            />
                        </div>
                    {/if}
                {/if}
            </div>

            <div class="card">
                <h3>File Manager Password Protection</h3>
                <p class="description">Require a password to access the file manager page.</p>

                <button
                    type="button"
                    onclick={() => (showFmPasswordFields = !showFmPasswordFields)}
                    class="btn btn-sm btn-outline-secondary"
                >
                    {showFmPasswordFields ? "Cancel" : (config.hasFmPassword ? "Change File Manager Password" : "Set File Manager Password")}
                </button>

                {#if showFmPasswordFields}
                    {#if config.hasFmPassword}
                        <div class="form-group" style="margin-top: 15px;">
                            <label for="fm-current-password">Current File Manager Password</label>
                            <input
                                id="fm-current-password"
                                type="password"
                                bind:value={fmCurrentPassword}
                                class="form-control"
                                autocomplete="off"
                                required
                            />
                        </div>
                    {/if}

                    <div class="form-group" style={!config.hasFmPassword ? "margin-top: 15px;" : ""}>
                        <label for="fm-password">{config.hasFmPassword ? "New File Manager Password" : "File Manager Password"}</label>
                        <input
                            id="fm-password"
                            type="password"
                            bind:value={fmPassword}
                            class="form-control"
                            placeholder={config.hasFmPassword ? "Leave empty to remove password" : ""}
                            autocomplete="new-password"
                        />
                        {#if config.hasFmPassword}
                            <small style="color: #666; display: block; margin-top: 5px;">
                                Leave empty to remove file manager password protection
                            </small>
                        {/if}
                    </div>

                    {#if fmPassword}
                        <div class="form-group">
                            <label for="confirm-fm-password">Confirm File Manager Password</label>
                            <input
                                id="confirm-fm-password"
                                type="password"
                                bind:value={confirmFmPassword}
                                class="form-control"
                                autocomplete="new-password"
                                required
                            />
                        </div>
                    {/if}

                    <div class="form-group">
                        <label class="checkbox-label">
                            <input
                                type="checkbox"
                                bind:checked={fmRefreshRequired}
                            />
                            Require password on every page refresh
                        </label>
                        {#if !fmRefreshRequired}
                            <div class="warning-inline">
                                &#9888; Less secure. Your session token will survive page refreshes but is cleared when the tab closes.
                            </div>
                        {/if}
                    </div>
                {/if}
            </div>
        {/if}
        </div>
    </form>
</div>

{#if showPathSelector}
    <PathSelector
        selectedPaths={excludedPaths}
        onClose={() => (showPathSelector = false)}
        onSave={handlePathsSelected}
    />
{/if}

<BackupModal
    visible={showBackupModal}
    onclose={() => { showBackupModal = false; backupRunning = false }}
    onstarted={() => { backupRunning = true }}
    oncomplete={() => { backupRunning = false }}
/>

<style>
    .settings-form {
        background: white;
        border-radius: 4px;
        border: 1px solid #ccd0d4;
    }

    .sticky-bar {
        position: sticky;
        top: 32px; /* WP admin bar height on desktop */
        z-index: 10;
        display: flex;
        align-items: stretch;
        justify-content: space-between;
        gap: 12px;
        background: #f6f7f7;
        border-bottom: 1px solid #ccd0d4;
        padding-right: 16px;
        border-radius: 4px 4px 0 0;
    }

    @media screen and (max-width: 782px) {
        .sticky-bar {
            top: 46px; /* WP admin bar height on mobile */
        }
    }

    .tabs {
        display: flex;
        flex: 1;
        min-width: 0;
    }

    .tab-button {
        padding: 12px 24px;
        border: none;
        background: transparent;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        color: #50575e;
        border-bottom: 2px solid transparent;
        transition: all 0.2s;
    }

    .tab-button:hover {
        color: #2271b1;
    }

    .tab-button.active {
        color: #2271b1;
        background: white;
        border-bottom-color: #2271b1;
    }

    .save-btn {
        align-self: center;
        white-space: nowrap;
    }

    .save-btn.dirty {
        background: #d63638;
        box-shadow: 0 0 0 3px rgba(214, 54, 56, 0.2);
        animation: dirtyPulse 1.6s ease-in-out infinite;
    }

    .save-btn.dirty:hover:not(:disabled) {
        background: #b32d2e;
    }

    @keyframes dirtyPulse {
        0%, 100% { box-shadow: 0 0 0 3px rgba(214, 54, 56, 0.2); }
        50%      { box-shadow: 0 0 0 6px rgba(214, 54, 56, 0.0); }
    }

    .form-content {
        padding: 20px;
    }

    .message {
        margin: 16px 20px 0;
    }

    .card {
        margin-bottom: 30px;
        padding-bottom: 30px;
        border-bottom: 1px solid #eee;
    }

    .card:last-of-type {
        border-bottom: none;
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

    .form-group {
        margin-bottom: 15px;
    }

    label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
        font-size: 14px;
    }

    .form-control {
        width: 100%;
        max-width: 400px;
        padding: 8px 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
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

    .btn-primary {
        background: #2271b1;
        color: white;
    }

    .btn-primary:hover:not(:disabled) {
        background: #135e96;
    }

    .btn-secondary {
        background: #f0f0f1;
        color: #2c3338;
        border: 1px solid #8c8f94;
    }

    .btn-secondary:hover {
        background: #e5e5e5;
    }

    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .excluded-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 15px;
        margin-bottom: 15px;
    }

    .excluded-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        background: #f0f0f1;
        border: 1px solid #c3c4c7;
        border-radius: 4px;
        font-size: 13px;
        color: #2c3338;
        overflow: auto;
        white-space: nowrap;
        max-width: 100vw;
        text-wrap: wrap;
    }

    .btn-remove-chip {
        background: transparent;
        border: none;
        color: #d63638;
        font-size: 18px;
        cursor: pointer;
        padding: 0;
        width: 18px;
        height: 18px;
        line-height: 1;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .btn-remove-chip:hover {
        color: #a00;
        background: rgba(214, 54, 56, 0.1);
        border-radius: 50%;
    }

    .no-paths {
        color: #999;
        font-style: italic;
        margin-top: 15px;
        font-size: 14px;
    }

    .message {
        padding: 12px;
        border-radius: 4px;
        margin-bottom: 20px;
        font-size: 14px;
    }

    .message-success {
        background: #d7f0db;
        color: #1e4620;
        border: 1px solid #00a32a;
    }

    .message-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #d63638;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        font-size: 14px;
    }

    .form-control {
        padding: 8px 12px;
        border: 1px solid #8c8f94;
        border-radius: 4px;
        font-size: 14px;
    }

    .form-control:focus {
        outline: none;
        border-color: #2271b1;
        box-shadow: 0 0 0 1px #2271b1;
    }

    .form-text {
        display: block;
        margin-top: 6px;
        color: #646970;
        font-size: 13px;
    }

    .slider {
        width: 100%;
        height: 6px;
        border-radius: 3px;
        background: #ddd;
        outline: none;
        -webkit-appearance: none;
        appearance: none;
    }

    .slider::-webkit-slider-thumb {
        -webkit-appearance: none;
        appearance: none;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        background: #2271b1;
        cursor: pointer;
    }

    .slider::-moz-range-thumb {
        width: 18px;
        height: 18px;
        border-radius: 50%;
        background: #2271b1;
        cursor: pointer;
        border: none;
    }

    .slider-labels {
        display: flex;
        justify-content: space-between;
        margin-top: 8px;
        font-size: 12px;
        color: #646970;
    }

    .max-w-100{
        max-width: 100%;
    }

    .checkbox-label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 500;
        font-size: 14px;
        cursor: pointer;
        margin-bottom: 0;
    }

    .checkbox-label input[type="checkbox"] {
        width: 16px;
        height: 16px;
        cursor: pointer;
        flex-shrink: 0;
    }

    .warning-inline {
        margin-top: 8px;
        padding: 8px 12px;
        background: #fff8e5;
        border: 1px solid #f0c33c;
        border-radius: 4px;
        color: #7a5a00;
        font-size: 13px;
    }

    .btn-sm {
        padding: 5px 12px;
        font-size: 13px;
    }

    .btn-outline-secondary {
        background: transparent;
        color: #2c3338;
        border: 1px solid #8c8f94;
    }

    .btn-outline-secondary:hover {
        background: #f0f0f1;
    }

    .btn-outline-danger {
        background: transparent;
        color: #d63638;
        border: 1px solid #d63638;
    }

    .btn-outline-danger:hover {
        background: #fce8e8;
    }

    .alert-warning {
        padding: 12px;
        background: #fff8e5;
        border: 1px solid #f0c33c;
        border-radius: 4px;
        color: #7a5a00;
        font-size: 13px;
    }
</style>
