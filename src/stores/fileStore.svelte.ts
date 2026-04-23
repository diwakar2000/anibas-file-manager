import {
    fetchNode,
    createFolder as apiCreateFolder,
    deleteFile as apiDeleteFile,
    verifyDeletePassword,
    transferFile as apiTransferFile,
    getJobStatus as apiGetJobStatus,
    cancelJob as apiCancelJob,
    cancelArchiveJob as apiCancelArchiveJob,
    checkConflict as apiCheckConflict,
    requestDeleteToken as apiRequestDeleteToken,
    checkRunningTasks,
    archivePrescan as apiArchivePrescan,
    archiveCreate as apiArchiveCreate,
    archiveCheck as apiArchiveCheck,
    archiveRestore as apiArchiveRestore,
    verifyFmPassword as apiVerifyFmPassword,
    checkFmAuth as apiCheckFmAuth,
    renameFile as apiRenameFile,
    duplicateFile as apiDuplicateFile,
    emptyFolder as apiEmptyFolder,
    backupSingleFile as apiBackupSingleFile,
    setFmToken,
    setFmTokenRequiredHandler,
} from "../services/fileApi";
import type { FileItem, DirectoryResponse, Job, ArchiveJob } from "../types/files";
import { toast } from "../utils/toast";

function isNetworkError(err: unknown): boolean {
    return err instanceof TypeError;
}

const FM_SESSION_KEY = 'anibas_fm_fm_token';

// Background job poll cadence. We serialize jobs (one at a time) so this is
// at most ~1.3 req/s to admin-ajax.php — cheap, and it cuts the "stuck in
// processing" lull at the end of fast operations from up-to-2s down to
// up-to-750ms before the next item in a bulk batch can start.
const JOB_POLL_INTERVAL_MS = 750;

export class AnibasFileStore {
    currentPath = $state("/");
    currentStorage = $state("local");
    directoryCache = $state.raw<Record<string, DirectoryResponse>>({});
    expandedFolders = $state<string[]>(["/"]);
    isLoading = $state(false);
    error = $state<string | null>(null);
    deleteToken = $state<string | null>(null);
    clipboard = $state<{ paths: string[]; action: 'copy' | 'move'; storage: string } | null>(null);
    activeJobs = $state<Record<string, Job>>({});
    archiveJobs = $state<ArchiveJob[]>([]);
    deletingPaths = $state<string[]>([]);
    lastErrorType = $state<string | null>(null);
    currentOperation = $state<string | null>(null);

    // Global Paste State
    showPasteDialog = $state(false);
    pendingPasteDestination = $state<string | null>(null);

    // View mode: 'list' | 'grid'
    viewMode = $state<'list' | 'grid'>('list');

    // FM password gate
    fmGateVisible = $state(false);
    fmGateLoading = $state(false);
    fmGateError = $state<string | null>(null);

    // Multi-selection
    selectedPaths = $state<string[]>([]);
    lastSelectedPath = $state<string | null>(null);

    // Inline rename
    renamingPath = $state<string | null>(null);

    // Inline editor
    editorFile = $state<{ path: string; storage: string; canEdit: boolean } | null>(null);

    // Backup-before-edit prompt
    pendingEditorOpen = $state<{ path: string; canEdit: boolean } | null>(null);
    showBackupPrompt = $state(false);
    showBackupModal = $state(false);
    backupRunning = $state(false);

    // Preview panel — only shown when explicitly toggled
    previewOpen = $state(false);

    constructor() {
        const cfg = (window as any).AnibasFM;

        // Wire the FMTokenRequired callback so any API response can re-show the gate
        setFmTokenRequiredHandler(() => {
            this.fmGateVisible = true;
        });

        // Restore FM token from sessionStorage if the admin disabled refresh-required
        if (cfg?.fmPasswordRequired && !cfg?.fmRefreshRequired) {
            const stored = sessionStorage.getItem(FM_SESSION_KEY);
            if (stored) {
                // Silently validate the stored token before trusting it
                // Mark gate visible immediately; hide it if token is valid
                this.fmGateVisible = true;
                apiCheckFmAuth(stored).then(valid => {
                    if (valid) {
                        setFmToken(stored);
                        this.fmGateVisible = false;
                        this.initializeApp();
                        this.navigateTo(this.currentPath);
                    } else {
                        sessionStorage.removeItem(FM_SESSION_KEY);
                        // gate stays visible
                    }
                });
            } else {
                this.fmGateVisible = true;
            }
        } else if (cfg?.fmPasswordRequired) {
            // Refresh-required (default): always show the gate on page load
            this.fmGateVisible = true;
        }

        const params = new URLSearchParams(window.location.search);
        const storage = params.get('storage');
        if (storage) {
            this.currentStorage = storage;
        }
        if (!this.fmGateVisible) {
            this.initializeApp();
        }
    }

    private initializeApp() {
        checkRunningTasks().then(result => {
            for (const task of result.tasks ?? []) {
                this.activeJobs[task.id] = {
                    id: task.id,
                    action: task.action,
                    status: task.status,
                    processed: task.processed || 0,
                    failed_count: task.failed_count || 0,
                    current_file: task.current_file || '',
                    type: task.type || null,
                    progress: task.progress || 0,
                    current_chunk: task.current_chunk || 0,
                    total_chunks: task.total_chunks || 0,
                    file_name: task.file_name || '',
                    source: task.source || '',
                    destination: task.destination || '',
                    current_phase: task.current_phase || null,
                };
                this.pollJobStatus(task.id);
            }
            // Restore any archive jobs that were running before the page refresh
            this.archiveJobs = result.archive_jobs ?? [];

            // If a backup was running, show the backup modal so the user can track/cancel it
            if (result.backup?.running) {
                this.backupRunning = true;
                this.showBackupModal = true;
            }
        }).catch(() => {
            // Silently ignore — FMTokenRequired is handled by checkFmTokenError in fileApi
        });
    }

    async openEditor(path: string, canEdit: boolean): Promise<void> {
        // If editing (not just viewing), prompt for backup first
        if (canEdit) {
            this.pendingEditorOpen = { path, canEdit };
            this.showBackupPrompt = true;
            return;
        }

        await this.proceedToEditor(path, canEdit);
    }

    async proceedToEditor(path: string, canEdit: boolean): Promise<void> {
        const cfg = (window as any).AnibasFM;
        const formData = new FormData();
        formData.append('action', cfg.actions.initEditorSession);
        formData.append('nonce', cfg.editorNonce);
        formData.append('path', path);
        formData.append('storage', this.currentStorage);
        formData.append('can_edit', canEdit ? '1' : '0');

        const res = await fetch(cfg.ajaxURL, { method: 'POST', body: formData });
        const json = await res.json();

        if (!json.success) {
            toast.error(json.data?.message || 'Failed to open editor');
            return;
        }

        this.editorFile = { path, storage: this.currentStorage, canEdit };
    }

    /** User chose to skip backup and open editor directly. */
    skipBackupAndEdit(): void {
        if (this.pendingEditorOpen) {
            const { path, canEdit } = this.pendingEditorOpen;
            this.pendingEditorOpen = null;
            this.showBackupPrompt = false;
            this.proceedToEditor(path, canEdit);
        }
    }

    /** User chose to back up this file first — snapshot it, then open the editor. */
    async backupBeforeEdit(): Promise<void> {
        this.showBackupPrompt = false;
        if (!this.pendingEditorOpen) return;
        const { path, canEdit } = this.pendingEditorOpen;
        this.backupRunning = true;
        try {
            await apiBackupSingleFile(path, this.currentStorage);
            toast.success("Backup saved — you can restore it from Settings ▸ Backups ▸ Single File Backups.");
        } catch (err: any) {
            toast.error(err?.message || "Backup failed — not opening editor");
            this.pendingEditorOpen = null;
            this.backupRunning = false;
            return;
        }
        this.backupRunning = false;
        this.pendingEditorOpen = null;
        await this.proceedToEditor(path, canEdit);
    }

    /** Backup completed or cancelled — proceed to editor. (Kept for full-site backup resume.) */
    backupFinishedThenEdit(): void {
        this.showBackupModal = false;
        this.backupRunning = false;
        if (this.pendingEditorOpen) {
            const { path, canEdit } = this.pendingEditorOpen;
            this.pendingEditorOpen = null;
            this.proceedToEditor(path, canEdit);
        }
    }

    /** Cancel the edit entirely. */
    cancelEdit(): void {
        this.pendingEditorOpen = null;
        this.showBackupPrompt = false;
        this.showBackupModal = false;
    }

    closeEditor(): void {
        this.editorFile = null;
    }

    get currentFiles(): FileItem[] {
        const data = this.directoryCache[this.currentPath];
        if (!data || !data.items) return [];
        return Object.values(data.items)
            .filter(item => item && item.name)
            .sort((a, b) => {
                if (a.is_folder && !b.is_folder) return -1;
                if (!a.is_folder && b.is_folder) return 1;
                return a.name.localeCompare(b.name);
            });
    }

    get hasDeletePassword(): boolean {
        return (window as any).AnibasFM?.hasDeletePassword ?? false;
    }

    async loadDirectory(path: string, page = 1) {
        this.isLoading = true;
        this.error = null;
        this.lastErrorType = null;
        try {
            const data: DirectoryResponse = await fetchNode({ path, page, storage: this.currentStorage });
            if (data.items) {
                Object.keys(data.items).forEach(key => {
                    const item = data.items[key];
                    if (!item.name) {
                        item.name = item.filename || key;
                    }
                });
            }

            // If page > 1, append items to existing data
            if (page > 1 && this.directoryCache[path]) {
                const existing = this.directoryCache[path];
                data.items = { ...existing.items, ...data.items };
            }

            this.directoryCache = { ...this.directoryCache, [path]: data };
        } catch (err: any) {
            const errorData = this.parseError(err);
            this.error = errorData.message;
            this.lastErrorType = errorData.errorType;
            if (errorData.errorType === 'PathInvalid') {
                this.expandedFolders = this.expandedFolders.filter(p => p !== path);
                delete this.directoryCache[path];
                if (this.currentPath === path) {
                    this.currentPath = '/';
                }
            }
        } finally {
            this.isLoading = false;
        }
    }

    parseError(err: any): { message: string; errorType: string | null } {
        if (typeof err === 'string') {
            return { message: err, errorType: null };
        }
        try {
            const parsed = JSON.parse(err.message);
            return { message: parsed.message, errorType: parsed.errorType };
        } catch {
            return { message: err.message || "Unknown error", errorType: null };
        }
    }

    async navigateTo(path: string) {
        if (this.deletingPaths.includes(path)) return;
        this.currentPath = path;
        this.clearSelection();
        this.renamingPath = null;
        if (!this.directoryCache[path]) {
            await this.loadDirectory(path);
        }
    }

    // Like navigateTo, but always re-fetches the listing (ignores cache) and
    // recovers to root if the destination no longer exists.
    async navigateAndRefresh(path: string) {
        if (this.deletingPaths.includes(path)) return;
        this.currentPath = path;
        this.clearSelection();
        this.renamingPath = null;
        await this.loadDirectory(path);
        // loadDirectory resets currentPath to '/' on PathInvalid but doesn't
        // load it — finish the fallback here.
        if (this.currentPath !== path) {
            await this.loadDirectory(this.currentPath);
        }
    }

    async toggleFolder(path: string) {
        const isExpanded = this.expandedFolders.includes(path);
        if (isExpanded) {
            this.expandedFolders = this.expandedFolders.filter(p => p !== path);
        } else {
            this.expandedFolders = [...this.expandedFolders, path];
            await this.loadDirectory(path);
        }
    }

    isExpanded(path: string) {
        return this.expandedFolders.includes(path);
    }

    getFolders(path: string): FileItem[] {
        const data = this.directoryCache[path];
        if (!data || !data.items) return [];
        return Object.values(data.items)
            .filter(item => item && item.name && item.is_folder)
            .sort((a, b) => a.name.localeCompare(b.name));
    }

    isDeleting(path: string): boolean {
        return this.deletingPaths.includes(path);
    }

    async createFolder(name: string) {
        this.error = null;
        this.currentOperation = `Creating folder "${name}"...`;
        try {
            await apiCreateFolder(this.currentPath, name, this.currentStorage);
            await this.loadDirectory(this.currentPath);
            toast.success(`Folder "${name}" created successfully`);
        } catch (err: any) {
            this.error = err.message;
            if(this.error === null) this.error = "Failed to create folder: " + name;
            
            // Check if this is a retry timeout - suggest page refresh
            if (this.error && this.error.includes('Please refresh the page and try again')) {
                toast.error('Folder creation timed out. Please refresh the page and try again.');
            } else {
                toast.error(this.error);
            }
            
            throw err;
        } finally {
            this.currentOperation = null;
        }
    }

    async verifyDeletePassword(password: string) {
        try {
            this.deleteToken = await verifyDeletePassword(password);
        } catch (err: any) {
            throw new Error("Invalid password");
        }
    }

    async deleteFile(path: string, deleteToken: string) {
        this.error = null;
        const name = path.split('/').pop() || 'Item';
        // Only set currentOperation if not already in a bulk operation
        const originalOperation = this.currentOperation;
        if (!originalOperation) {
            this.currentOperation = `Deleting ${name}...`;
        }
        try {
            const result = await apiDeleteFile(path, this.deleteToken || undefined, deleteToken, this.currentStorage);
            this.deletingPaths = this.deletingPaths.filter(p => p !== path);
            this.deleteToken = null;

            // Remote folder delete returns a background job — await it so a
            // bulkDelete loop serializes instead of spawning N parallel jobs.
            if (result?.job_id) {
                this.activeJobs[result.job_id] = {
                    id: result.job_id,
                    action: 'delete',
                    status: 'processing',
                    processed: 0,
                    failed_count: 0,
                    current_file: '',
                    type: null,
                    progress: 0,
                    current_chunk: 0,
                    total_chunks: 0,
                    file_name: '',
                    source: path,
                    destination: '',
                    current_phase: null,
                };
                await this.pollJobStatus(result.job_id);
                return;
            }

            toast.success(`"${name}" deleted successfully`);
        } catch (err: any) {
            if (err.message === "DeletePasswordRequired") {
                throw new Error("DeletePasswordRequired");
            }
            if (err.message === "DeleteTokenExpired") {
                throw new Error("DeleteTokenExpired");
            }
            this.error = err.message || "Failed to delete";
            throw err;
        } finally {
            if (!originalOperation) {
                this.currentOperation = null;
                this.clearSelection();
                await this.loadDirectory(this.currentPath);
            }
        }
    }

    async bulkDelete(paths: string[], password?: string) {
        this.error = null;

        let successCount = 0;
        let requiresPassword = false;
        const total = paths.length;

        for (let i = 0; i < paths.length; i++) {
            const path     = paths[i];
            const itemName = path.split('/').pop() || 'Item';
            // Refresh the label per-item: pollJobStatus clears currentOperation
            // when a background job finishes, so a single-shot label set before
            // the loop would vanish after the first item.
            this.currentOperation = total > 1
                ? `Deleting "${itemName}" (${i + 1} of ${total})...`
                : `Deleting "${itemName}"...`;
            this.deletingPaths = [...this.deletingPaths, path];
            try {
                // If we need a password and have one, try logic, else just request token.
                // The current architecture requires requesting a new token per file.
                const deleteToken = await this.requestDeleteToken(path);

                if (password && !this.deleteToken) {
                    await this.verifyDeletePassword(password);
                }

                await this.deleteFile(path, deleteToken);
                this.selectedPaths = this.selectedPaths.filter(p => p !== path);
                successCount++;
            } catch (err: any) {
                this.deletingPaths = this.deletingPaths.filter(p => p !== path);
                if (err.message === "DeletePasswordRequired") {
                    requiresPassword = true;
                    // Stop loop if password is required and we don't have it
                    if (!password) break;
                } else {
                    toast.error(`Failed to delete ${path.split('/').pop()}: ${err.message}`);
                }
            }
        }
        
        this.currentOperation = null;
        this.clearSelection();
        await this.loadDirectory(this.currentPath);
        
        if (requiresPassword && !password) {
            throw new Error("DeletePasswordRequired");
        }
        
        if (successCount > 0) {
            toast.success(`Successfully deleted ${successCount} items`);
        }
    }

    async requestDeleteToken(path: string): Promise<string> {
        return await apiRequestDeleteToken(path);
    }

    async emptyFolder(path: string) {
        this.error = null;
        const name = path.split('/').pop() || 'Folder';
        this.currentOperation = `Emptying ${name}...`;
        try {
            await apiEmptyFolder(path, this.deleteToken || undefined, this.currentStorage);
            toast.success(`"${name}" emptied successfully`);
        } catch (err: any) {
            if (err.message === 'DeletePasswordRequired') {
                throw err;
            }
            this.error = err.message || 'Failed to empty folder';
            throw err;
        } finally {
            this.currentOperation = null;
            await this.loadDirectory(this.currentPath);
        }
    }

    async duplicate(path: string) {
        if (Object.keys(this.activeJobs).length > 0) {
            const msg = 'A background operation is already running. Please wait for it to complete.';
            this.error = msg;
            toast.error(msg);
            throw new Error(msg);
        }

        const name = path.split('/').pop() || 'Item';
        this.currentOperation = `Duplicating "${name}"...`;
        try {
            const result = await apiDuplicateFile(path, this.currentStorage);

            if (result.job_id) {
                this.activeJobs[result.job_id] = {
                    id: result.job_id,
                    action: 'copy',
                    status: 'processing',
                    processed: 0,
                    failed_count: 0,
                    current_file: '',
                    type: null,
                    progress: 0,
                    current_chunk: 0,
                    total_chunks: 0,
                    file_name: name,
                    source: path,
                    destination: result.destination ?? '',
                    current_phase: null,
                };
                await this.pollJobStatus(result.job_id);
                return;
            }

            await this.loadDirectory(this.currentPath);
            toast.success(`Duplicated "${name}"`);
        } catch (err: any) {
            toast.error(err.message || 'Duplicate failed');
            throw err;
        } finally {
            if (Object.keys(this.activeJobs).length === 0) {
                this.currentOperation = null;
            }
        }
    }

    copyToClipboard(paths: string[]) {
        this.clipboard = { paths, action: 'copy', storage: this.currentStorage };
    }

    cutToClipboard(paths: string[]) {
        this.clipboard = { paths, action: 'move', storage: this.currentStorage };
    }

    clearClipboard() {
        this.clipboard = null;
    }

    async checkConflict(destination: string): Promise<boolean> {
        if (!this.clipboard || this.clipboard.paths.length === 0) return false;

        // Client-side pre-check: if the destination directory is cached and
        // none of the clipboard items share a name with existing files,
        // there is no conflict — skip the API call entirely.
        const cached = this.directoryCache[destination];
        if (cached?.items) {
            const existingNames = new Set(
                Object.values(cached.items)
                    .filter(item => item?.name)
                    .map(item => item.name)
            );
            const wouldConflict = this.clipboard.paths.some(
                p => existingNames.has(p.split('/').pop() || '')
            );
            if (!wouldConflict) return false;
        }

        if (this.clipboard.storage !== this.currentStorage) {
            // For cross-storage, if it didn't return false above, assume conflict might happen.
            // (We can't use apiCheckConflict easily here because the backend API expects source and dest on same storage)
            return true;
        }

        try {
            for (const path of this.clipboard.paths) {
                const result = await apiCheckConflict(path, destination, this.currentStorage);
                if (result.has_conflict) return true;
            }
            return false;
        } catch {
            return false;
        }
    }

    async requestPaste(destination: string) {
        if (!this.clipboard || this.clipboard.paths.length === 0) return;

        // Cross-storage guard: one side must be local
        if (this.clipboard.storage !== this.currentStorage) {
            if (this.clipboard.storage !== 'local' && this.currentStorage !== 'local') {
                toast.error('Transfer files to local storage first, then to the target storage.');
                return;
            }
        }

        if (Object.keys(this.activeJobs).length > 0) {
            this.error = 'A background operation is already running. Please wait for it to complete.';
            toast.error(this.error);
            return;
        }

        const hasConflict = await this.checkConflict(destination);
        if (hasConflict) {
            this.pendingPasteDestination = destination;
            this.showPasteDialog = true;
        } else {
            await this.paste(destination, 'skip');
        }
    }

    async paste(destination: string, conflictMode: string = 'skip') {
        if (!this.clipboard || this.clipboard.paths.length === 0) return;

        // Cross-storage guard: one side must be local
        if (this.clipboard.storage !== this.currentStorage) {
            if (this.clipboard.storage !== 'local' && this.currentStorage !== 'local') {
                toast.error('Transfer files to local storage first, then to the target storage.');
                return;
            }
        }

        if (Object.keys(this.activeJobs).length > 0) {
            this.error = 'A background operation is already running. Please wait for it to complete.';
            throw new Error(this.error);
        }

        this.error = null;

        try {
            const { paths, action, storage: sourceStorage } = this.clipboard;
            this.clearClipboard();

            const total = paths.length;
            const verb  = action === 'copy' ? 'Copying' : 'Moving';

            // Process items one at a time: await the AJAX *and* the resulting
            // background job (if any) before starting the next. This prevents
            // N parallel jobs from stampeding the worker on a bulk paste.
            for (let i = 0; i < paths.length; i++) {
                const path      = paths[i];
                const sourceDir = path.substring(0, path.lastIndexOf('/')) || '/';
                const itemName  = path.split('/').pop() || 'Item';
                this.currentOperation = total > 1
                    ? `${verb} "${itemName}" (${i + 1} of ${total})...`
                    : `${verb} "${itemName}"...`;

                try {
                    const result = await apiTransferFile(path, destination, action, conflictMode, sourceStorage, this.currentStorage);

                    if (result.job_id) {
                        this.activeJobs[result.job_id] = {
                            id: result.job_id,
                            action,
                            status: 'processing',
                            processed: 0,
                            failed_count: 0,
                            current_file: '',
                            type: null,
                            progress: 0,
                            current_chunk: 0,
                            total_chunks: 0,
                            file_name: '',
                            source: path,
                            destination: destination,
                            current_phase: null
                        };
                        await this.pollJobStatus(result.job_id);
                    } else if (action === 'move' && sourceStorage === this.currentStorage) {
                        delete this.directoryCache[sourceDir];
                        delete this.directoryCache[destination];
                    }
                } catch (itemErr: any) {
                    // Surface the per-item failure but keep the queue moving
                    toast.error(`Failed to ${action} "${itemName}": ${itemErr.message || 'Unknown error'}`);
                }
            }

            await this.loadDirectory(this.currentPath);
            if (destination !== this.currentPath) {
                await this.loadDirectory(destination);
            }
        } catch (err: any) {
            this.error = err.message;
            this.clearClipboard();
            if(this.error === null) this.error = "Failed to paste";
            toast.error(this.error);
            await this.loadDirectory(this.currentPath);
            throw err;
        } finally {
            this.clearSelection();
            if (Object.keys(this.activeJobs).length === 0) {
                this.currentOperation = null;
            }
        }
    }

    /**
     * Poll a background job until it reaches a terminal state. Returns a
     * Promise<void> that resolves on completion, failure, OR polling error —
     * callers who want to serialize bulk work should await this so the next
     * item doesn't start until the current one has gracefully finished.
     * Resolving (rather than rejecting) on failure is intentional: a bulk
     * paste/delete should continue with remaining items after a single
     * failure, not abort the whole batch.
     */
    async pollJobStatus(jobId: string): Promise<void> {
        // Preserve the frontend-assigned action (e.g. 'rename') across API updates,
        // since the backend job always reports the underlying action ('move').
        const frontendAction = this.activeJobs[jobId]?.action;

        return new Promise<void>((resolve) => {
            let consecutiveFailures = 0;
            const MAX_CONSECUTIVE_FAILURES = 5;

            const poll = async () => {
                try {
                    const job = await apiGetJobStatus(jobId);
                    
                    // If the job was cancelled/deleted from activeJobs while we were polling, terminate the poll loop cleanly
                    if (!this.activeJobs[jobId]) {
                        resolve();
                        return;
                    }

                    consecutiveFailures = 0;
                    const effectiveAction = (frontendAction === 'rename' ? 'rename' : job.action) as 'copy' | 'move' | 'delete' | 'rename';

                    this.activeJobs[jobId] = {
                        id: jobId,
                        action: effectiveAction,
                        status: job.status,
                        source: job.source,
                        destination: job.destination,
                        current_phase: job.current_phase,
                        processed: job.processed_count,
                        failed_count: job.failed_count,
                        current_file: job.current_file || '',
                        type: job.type || null,
                        progress: job.progress || 0,
                        current_chunk: job.current_chunk || 0,
                        total_chunks: job.total_chunks || 0,
                        file_name: job.file_name || '',
                        total_files: job.total_files || 0,
                        current_file_bytes: job.current_file_bytes || 0,
                        current_file_size: job.current_file_size || 0,
                    };

                    if (job.status === 'completed' || job.status === 'failed') {
                        delete this.activeJobs[jobId];
                        this.currentOperation = null;
                        this.clearSelection();

                        if (job.status === 'failed') {
                            this.error = 'Job failed: ' + (job.errors?.[0] || 'Unknown error');
                            toast.error(this.error);
                        } else {
                            const actionText = effectiveAction === 'delete' ? 'deleted' : effectiveAction === 'copy' ? 'copied' : effectiveAction === 'rename' ? 'renamed' : 'moved';
                            const name = job.source.split('/').pop() || 'Item';
                            toast.success(`"${name}" ${actionText} successfully`);
                        }

                        // Surgical cache invalidation: only drop entries for
                        // the source's parent and the destination's parent
                        // (the two directories whose listings actually changed).
                        // Other cached views (siblings, expanded tree branches)
                        // remain valid and don't need to refetch.
                        this.invalidateCacheForJob(job.source, job.destination);
                        resolve();
                        void this.loadDirectory(this.currentPath);
                        if (job.destination && job.destination !== this.currentPath) {
                            void this.loadDirectory(job.destination);
                        }
                    } else {
                        setTimeout(poll, JOB_POLL_INTERVAL_MS);
                    }
                } catch (err) {
                    // Poll request failed (network blip, transient server
                    // hiccup) — the job itself may still be running. Do NOT
                    // wipe cache or drop the activeJobs entry: retry with
                    // backoff and only give up after several failures.
                    consecutiveFailures++;
                    if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) {
                        delete this.activeJobs[jobId];
                        this.currentOperation = null;
                        toast.error('Lost connection to background job — refreshing view');
                        await this.loadDirectory(this.currentPath);
                        resolve();
                        return;
                    }
                    const backoff = Math.min(8000, JOB_POLL_INTERVAL_MS * Math.pow(2, consecutiveFailures - 1));
                    setTimeout(poll, backoff);
                }
            };

            poll();
        });
    }

    private invalidateCacheForJob(source: string, destination: string | null | undefined) {
        const dirs = new Set<string>();
        const parentOf = (p: string) => {
            const trimmed = p.replace(/\/+$/, '');
            const idx = trimmed.lastIndexOf('/');
            return idx <= 0 ? '/' : trimmed.slice(0, idx);
        };
        if (source) dirs.add(parentOf(source));
        if (destination) dirs.add(parentOf(destination));
        for (const dir of dirs) {
            delete this.directoryCache[dir];
        }
    }

    async cancelJob(jobId: string) {
        try {
            await apiCancelJob(jobId);
            const cancelled = this.activeJobs[jobId];
            delete this.activeJobs[jobId];
            if (cancelled) {
                this.invalidateCacheForJob(cancelled.source, cancelled.destination);
            }
            await this.loadDirectory(this.currentPath);
            toast.info('Operation cancelled');
        } catch (err: any) {
            this.error = err.message;
            if(this.error === null) this.error = "Failed to cancel job";
            toast.error(this.error);
            throw err;
        }
    }

    archiveProgress = $state<{ phase: string; progress?: any; format?: string; info?: any } | null>(null);

    async prescanFolder(source: string) {
        try {
            return await apiArchivePrescan(source, this.currentStorage);
        } catch (err: any) {
            toast.error(err.message || 'Folder scan failed');
            throw err;
        }
    }

    async startArchiveCreate(
        source: string,
        format: 'zip' | 'anfm' | 'tar',
        password?: string,
        conflictMode?: 'overwrite' | 'rename',
    ) {
        this.error = null;
        const name = source.split('/').pop() || 'Item';
        this.currentOperation = `Scanning "${name}" for archiving...`;
        let jobId: string | undefined;
        try {
            const scan = await apiArchiveCreate(source, format, 'scan', password, conflictMode, undefined, this.currentStorage);

            // Backend detected an existing output file and no conflict resolution was given
            if (scan.phase === 'conflict') {
                throw Object.assign(new Error('ArchiveConflict'), {
                    output: scan.output,
                    output_size: scan.output_size,
                });
            }

            jobId = scan.job_id as string;
            // Track it so Statusbar shows it and resume is possible after refresh
            this.archiveJobs = [
                ...this.archiveJobs.filter(j => j.id !== jobId),
                { id: jobId!, source: name, output: scan.output as string, format, started_at: Math.floor(Date.now() / 1000), storage: this.currentStorage },
            ];
            this.archiveProgress = { phase: 'scan_complete', info: scan.info, format };

            this.currentOperation = `Archiving "${name}"...`;
            let running = true;
            let zipRetried = false;
            while (running) {
                try {
                    const result = await apiArchiveCreate(source, format, 'run', password, undefined, jobId, this.currentStorage);
                    this.archiveProgress = { phase: result.phase, progress: result.progress, format };
                    if (result.phase === 'complete') {
                        running = false;
                    }
                } catch (runErr: unknown) {
                    if (format === 'zip' && isNetworkError(runErr)) {
                        if (!zipRetried) {
                            zipRetried = true;
                            this.currentOperation = `ZIP connection failed, retrying "${name}"...`;
                            continue;
                        }
                        for (let attempt = 0; attempt < 3; attempt++) {
                            try { await apiArchiveCreate(source, format, 'cleanup', undefined, undefined, jobId, this.currentStorage); break; } catch {}
                            if (attempt < 2) await new Promise(r => setTimeout(r, 800));
                        }
                        throw new Error('ZipConnectionTimeout');
                    }
                    throw runErr;
                }
            }

            if (jobId) this.archiveJobs = this.archiveJobs.filter(j => j.id !== jobId);
            await this.loadDirectory(this.currentPath);
            toast.success(`Archive "${name}.${format}" created successfully`);
        } catch (err: any) {
            if (err.message === 'ArchiveConflict') throw err; // handled by UI
            if (err.message !== 'ZipConnectionTimeout') {
                this.error = err.message;
                toast.error(err.message || 'Archive creation failed');
            }
            if (jobId) this.archiveJobs = this.archiveJobs.filter(j => j.id !== jobId);
            await this.loadDirectory(this.currentPath);
            throw err;
        } finally {
            this.archiveProgress = null;
            this.currentOperation = null;
        }
    }

    /** Resume an archive job that was interrupted by a page refresh. */
    async resumeArchiveJob(job: ArchiveJob) {
        this.error = null;
        this.currentOperation = `Resuming archive "${job.output}"...`;
        try {
            this.archiveProgress = { phase: 'running', format: job.format };
            let running = true;
            let zipRetried = false;
            while (running) {
                try {
                    const result = await apiArchiveCreate(job.source, job.format, 'run', undefined, undefined, job.id, job.storage);
                    this.archiveProgress = { phase: result.phase, progress: result.progress, format: job.format };
                    if (result.phase === 'complete') running = false;
                } catch (runErr: unknown) {
                    if (job.format === 'zip' && isNetworkError(runErr)) {
                        if (!zipRetried) { zipRetried = true; continue; }
                        for (let attempt = 0; attempt < 3; attempt++) {
                            try { await apiArchiveCreate(job.source, job.format, 'cleanup', undefined, undefined, job.id, job.storage); break; } catch {}
                            if (attempt < 2) await new Promise(r => setTimeout(r, 800));
                        }
                        throw new Error('ZipConnectionTimeout');
                    }
                    throw runErr;
                }
            }
            this.archiveJobs = this.archiveJobs.filter(j => j.id !== job.id);
            await this.loadDirectory(this.currentPath);
            toast.success(`Archive "${job.output}" completed`);
        } catch (err: any) {
            // State files may be gone — remove the stale job either way
            this.archiveJobs = this.archiveJobs.filter(j => j.id !== job.id);
            if (err.message !== 'ZipConnectionTimeout') {
                toast.error(err.message || 'Resume failed — archive state may have been cleared');
            }
            await this.loadDirectory(this.currentPath);
        } finally {
            this.archiveProgress = null;
            this.currentOperation = null;
        }
    }

    async cancelArchiveJob(jobId: string) {
        try {
            await apiCancelArchiveJob(jobId);
        } catch { /* best-effort */ }
        this.archiveJobs = this.archiveJobs.filter(j => j.id !== jobId);
        await this.loadDirectory(this.currentPath);
    }

    async checkArchive(path: string) {
        try {
            return await apiArchiveCheck(path, this.currentStorage);
        } catch (err: any) {
            toast.error(err.message || 'Archive check failed');
            throw err;
        }
    }

    async startArchiveRestore(path: string, password?: string) {
        this.error = null;
        const name = path.split('/').pop() || 'Archive';
        this.currentOperation = `Preparing to extract "${name}"...`;

        // Snapshot existing entries so we can highlight whatever extraction adds.
        const destPath = this.currentPath;
        const beforePaths = new Set(
            Object.values(this.directoryCache[destPath]?.items || {})
                .map((it: any) => it?.path)
                .filter(Boolean) as string[]
        );

        try {
            const init = await apiArchiveRestore(path, 'init', password, this.currentStorage);
            this.archiveProgress = { phase: 'ready', info: init.info };

            this.currentOperation = `Extracting "${name}"...`;
            let running = true;
            while (running) {
                const result = await apiArchiveRestore(path, 'run', password, this.currentStorage);
                this.archiveProgress = { phase: result.phase, progress: result.progress };
                if (result.phase === 'complete') {
                    running = false;
                }
            }

            await this.loadDirectory(destPath);

            const afterItems = Object.values(this.directoryCache[destPath]?.items || {}) as any[];
            const newPaths = afterItems
                .map(it => it?.path)
                .filter((p: string | undefined): p is string => !!p && !beforePaths.has(p));
            if (newPaths.length > 0 && this.currentPath === destPath) {
                this.selectAll(newPaths);
            }

            toast.success(`"${name}" extracted successfully`);
        } catch (err: any) {
            this.error = err.message;
            toast.error(err.message || 'Extraction failed');
            await this.loadDirectory(this.currentPath);
            throw err;
        } finally {
            this.archiveProgress = null;
            this.currentOperation = null;
        }
    }

    isArchive(file: FileItem): false | 'zip' | 'anfm' | 'tar' {
        if (file.is_folder) return false;
        const ext = file.name.split('.').pop()?.toLowerCase();
        if (ext === 'zip') return 'zip';
        if (ext === 'anfm') return 'anfm';
        if (ext === 'tar') return 'tar';
        return false;
    }

    toggleViewMode() {
        this.viewMode = this.viewMode === 'list' ? 'grid' : 'list';
    }

    setViewMode(mode: 'list' | 'grid') {
        this.viewMode = mode;
    }

    selectFile(path: string | null, opts: { ctrl?: boolean; shift?: boolean } = {}) {
        if (path === null) { this.clearSelection(); return; }

        if (opts.shift && this.lastSelectedPath) {
            const files = this.currentFiles.map(f => f.path);
            const lastIdx = files.indexOf(this.lastSelectedPath);
            const currIdx = files.indexOf(path);
            if (lastIdx !== -1 && currIdx !== -1) {
                const [start, end] = lastIdx < currIdx ? [lastIdx, currIdx] : [currIdx, lastIdx];
                const rangeSet = new Set([...this.selectedPaths, ...files.slice(start, end + 1)]);
                this.selectedPaths = Array.from(rangeSet);
                return;
            }
        }

        if (opts.ctrl) {
            if (this.selectedPaths.includes(path)) {
                this.selectedPaths = this.selectedPaths.filter(p => p !== path);
            } else {
                this.selectedPaths = [...this.selectedPaths, path];
            }
        } else {
            this.selectedPaths = [path];
        }
        this.lastSelectedPath = path;
    }

    selectAll(paths: string[]) {
        this.selectedPaths = [...paths];
        this.lastSelectedPath = paths[paths.length - 1] ?? null;
    }

    clearSelection() {
        this.selectedPaths = [];
        this.lastSelectedPath = null;
        this.previewOpen = false;
    }

    isSelected(path: string): boolean {
        return this.selectedPaths.includes(path);
    }

    get selectionCount(): number { return this.selectedPaths.length; }
    get hasSelection(): boolean { return this.selectedPaths.length > 0; }

    startRename(path: string) { this.renamingPath = path; }
    stopRename() { this.renamingPath = null; }

    async renameFile(path: string, newName: string) {
        const oldName = path.split('/').pop() || path;
        this.currentOperation = `Renaming "${oldName}"...`;
        try {
            const result = await apiRenameFile(path, newName, this.currentStorage);

            if (result.job_id) {
                // Remote rename — routed through BackgroundProcessor
                this.activeJobs[result.job_id] = {
                    id: result.job_id,
                    action: 'rename',
                    status: 'processing',
                    processed: 0,
                    failed_count: 0,
                    current_file: '',
                    type: null,
                    progress: 0,
                    current_chunk: 0,
                    total_chunks: 0,
                    file_name: oldName,
                    source: path,
                    destination: '',
                    current_phase: null,
                };
                this.renamingPath = null;
                this.pollJobStatus(result.job_id);
                return;
            }

            // Local rename — synchronous success
            this.renamingPath = null;
            await this.loadDirectory(this.currentPath);
            toast.success(`Renamed to "${newName}" successfully`);
        } catch (err: any) {
            toast.error(err.message || 'Rename failed');
            throw err;
        } finally {
            this.currentOperation = null;
        }
    }

    async verifyFmPassword(password: string) {
        const cfg = (window as any).AnibasFM;
        this.fmGateLoading = true;
        this.fmGateError = null;
        try {
            const token = await apiVerifyFmPassword(password);
            setFmToken(token);
            // Persist in sessionStorage only when refresh-required is disabled
            if (!cfg?.fmRefreshRequired) {
                sessionStorage.setItem(FM_SESSION_KEY, token);
            }
            this.fmGateVisible = false;
            this.initializeApp();
            await this.navigateTo(this.currentPath);
        } catch (err: any) {
            this.fmGateError = err.message || 'Invalid password';
        } finally {
            this.fmGateLoading = false;
        }
    }

    async changeStorage(storage: string) {
        this.currentStorage = storage;

        const url = new URL(window.location.href);
        url.searchParams.set('storage', storage);
        window.history.pushState({}, '', url);

        this.currentPath = "/";
        this.directoryCache = {};
        // Do not clear the clipboard so cross-storage transfers are possible
        this.expandedFolders = [];
        this.selectedPaths = [];
        this.deletingPaths = [];
        this.renamingPath = null;
        this.previewOpen = false;
        this.currentOperation = null;
        this.error = null;
        await this.loadDirectory("/");
        this.expandedFolders = ["/"];
    }
}

export const fileStore: AnibasFileStore = new AnibasFileStore();
