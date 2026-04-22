<script lang="ts">
    import { onMount, onDestroy } from 'svelte';
    import { getLanguage } from './editorLanguage';
    import { fileStore } from '../../stores/fileStore.svelte';
    import { toast } from '../../utils/toast';

    let { path, storage, canEdit } = $props<{ path: string; storage: string; canEdit: boolean }>();

    const cfg = (window as any).AnibasFM;
    const fileName = path.split('/').pop() ?? path;

    let editorEl    = $state<HTMLDivElement | null>(null);
    let status      = $state<'loading' | 'ready' | 'error' | 'saving' | 'saved'>('loading');
    let errorMsg    = $state('');
    let loadPercent = $state(0);
    let isDirty     = $state(false);
    let saveMsg     = $state('');

    let cmView: any = null;

    async function loadFile(): Promise<string> {
        const chunks: string[] = [];
        let offset = 0;

        while (true) {
            const fd = new FormData();
            fd.append('action',  cfg.actions.getFileChunk);
            fd.append('nonce',   cfg.editorNonce);
            fd.append('path',    path);
            fd.append('storage', storage);
            fd.append('offset',  String(offset));

            const res  = await fetch(cfg.ajaxURL, { method: 'POST', body: fd });
            const json = await res.json();

            if (!json.success) {
                throw new Error(json.data?.message || json.data?.error || 'Failed to load file');
            }

            const { chunk, length, file_size, done } = json.data;
            chunks.push(atob(chunk));
            offset += length;

            if (file_size > 0) loadPercent = Math.round((offset / file_size) * 100);
            if (done) break;
        }

        return chunks.join('');
    }

    async function save() {
        if (!cmView || !canEdit || status === 'saving') return;

        const content = cmView.state.doc.toString();
        const byteSize = new TextEncoder().encode(content).length;
        const maxBytes = ANIBAS_FM_EDITOR_MAX_BYTES ?? 10485760;
        if (byteSize > maxBytes) {
            toast.error(`File exceeds the ${Math.round(maxBytes / 1048576)} MB save limit.`);
            return;
        }

        status  = 'saving';
        saveMsg = '';

        try {
            const fd = new FormData();
            fd.append('action',  cfg.actions.saveFile);
            fd.append('nonce',   cfg.editorNonce);
            fd.append('path',    path);
            fd.append('storage', storage);
            const bytes = new TextEncoder().encode(content);
            let binary = '';
            for (const b of bytes) binary += String.fromCharCode(b);
            fd.append('content', btoa(binary));

            const res  = await fetch(cfg.ajaxURL, { method: 'POST', body: fd });
            const json = await res.json();

            if (!json.success) throw new Error(json.data?.message || 'Save failed');

            isDirty = false;
            status  = 'saved';
            saveMsg = 'Saved';
            setTimeout(() => { if (status === 'saved') status = 'ready'; }, 2000);
        } catch (err: any) {
            status  = 'ready';
            saveMsg = err.message || 'Save failed';
        }
    }

    function handleClose() {
        if (isDirty && !confirm('You have unsaved changes. Close anyway?')) return;
        fileStore.closeEditor();
    }

    function handleKeyDown(e: KeyboardEvent) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            save();
        }
    }

    function handleBeforeUnload(e: BeforeUnloadEvent) {
        if (isDirty) e.preventDefault();
    }

    onMount(async () => {
        window.addEventListener('keydown', handleKeyDown);
        window.addEventListener('beforeunload', handleBeforeUnload);

        try {
            const content = await loadFile();

            const [
                { EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter, drawSelection },
                { EditorState },
                { defaultKeymap, history, historyKeymap, indentWithTab },
                { indentOnInput, syntaxHighlighting, defaultHighlightStyle, bracketMatching, foldGutter },
                { oneDark },
                language,
            ] = await Promise.all([
                import('@codemirror/view'),
                import('@codemirror/state'),
                import('@codemirror/commands'),
                import('@codemirror/language'),
                import('@codemirror/theme-one-dark'),
                getLanguage(fileName),
            ]);

            const extensions: any[] = [
                lineNumbers(),
                highlightActiveLine(),
                highlightActiveLineGutter(),
                drawSelection(),
                history(),
                indentOnInput(),
                bracketMatching(),
                foldGutter(),
                syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
                oneDark,
                keymap.of([...defaultKeymap, ...historyKeymap, indentWithTab]),
                EditorView.updateListener.of((update: any) => {
                    if (update.docChanged) isDirty = true;
                }),
                EditorView.editable.of(canEdit),
                EditorView.theme({
                    '&': { height: '100%', fontSize: '13px' },
                    '.cm-scroller': { overflow: 'auto', fontFamily: "'JetBrains Mono', 'Fira Code', 'Cascadia Code', monospace" },
                }),
            ];

            if (language) extensions.push(language);

            cmView = new EditorView({
                state: EditorState.create({ doc: content, extensions }),
                parent: editorEl!,
            });

            status = 'ready';
        } catch (err: any) {
            status   = 'error';
            errorMsg = err.message || 'Failed to load file';
        }
    });

    onDestroy(() => {
        window.removeEventListener('keydown', handleKeyDown);
        window.removeEventListener('beforeunload', handleBeforeUnload);
        cmView?.destroy();
    });

    // Resolve max bytes from PHP constant exposed via config
    const ANIBAS_FM_EDITOR_MAX_BYTES = 10485760;
</script>

<div class="inline-editor">
    <div class="ie-toolbar">
        <button class="ie-back" onclick={handleClose} title="Back to file manager" aria-label="Back to file manager">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </button>

        <div class="ie-file-info">
            <span class="ie-filename">{fileName}</span>
            {#if isDirty}<span class="ie-dirty" title="Unsaved changes">●</span>{/if}
            {#if !canEdit}<span class="ie-badge-readonly">Read-only</span>{/if}
        </div>

        <div class="ie-toolbar-right">
            {#if saveMsg}
                <span class="ie-save-msg" class:ie-save-error={status === 'ready' && saveMsg !== 'Saved'}>{saveMsg}</span>
            {/if}
            {#if canEdit}
                <button
                    class="ie-btn ie-btn-save"
                    onclick={save}
                    disabled={status === 'saving' || status === 'loading' || status === 'error' || !isDirty}
                >
                    {status === 'saving' ? 'Saving…' : 'Save'}
                </button>
            {/if}
        </div>
    </div>

    <div class="ie-body">
        <!-- Always in DOM so bind:this resolves before CodeMirror attaches -->
        <div class="ie-cm-wrap" bind:this={editorEl} class:ie-hidden={status === 'loading' || status === 'error'}></div>

        {#if status === 'loading'}
            <div class="ie-overlay ie-loading">
                <div class="ie-spinner"></div>
                <span>Loading{loadPercent > 0 ? ` ${loadPercent}%` : '…'}</span>
            </div>
        {:else if status === 'error'}
            <div class="ie-overlay ie-error">
                <strong>Could not open file</strong>
                <p>{errorMsg}</p>
                <button class="ie-btn" onclick={handleClose}>Go back</button>
            </div>
        {/if}
    </div>
</div>

<style>
    .inline-editor {
        display: flex;
        flex-direction: column;
        height: 100%;
        background: #1e1e2e;
    }

    .ie-toolbar {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 0 12px;
        height: 44px;
        background: #181825;
        border-bottom: 1px solid #313244;
        flex-shrink: 0;
    }

    .ie-back {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border: none;
        background: #313244;
        color: #cdd6f4;
        border-radius: 4px;
        cursor: pointer;
        flex-shrink: 0;
        transition: background 0.15s;
    }

    .ie-back:hover {
        background: #45475a;
    }

    .ie-file-info {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 1;
        min-width: 0;
    }

    .ie-filename {
        font-size: 13px;
        font-weight: 600;
        color: #cdd6f4;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ie-dirty {
        color: #f38ba8;
        font-size: 16px;
        line-height: 1;
        flex-shrink: 0;
    }

    .ie-badge-readonly {
        font-size: 11px;
        padding: 2px 7px;
        border-radius: 3px;
        font-weight: 600;
        background: #313244;
        color: #a6adc8;
        flex-shrink: 0;
    }

    .ie-toolbar-right {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }

    .ie-save-msg {
        font-size: 12px;
        color: #a6e3a1;
    }

    .ie-save-error {
        color: #f38ba8;
    }

    .ie-btn {
        padding: 5px 14px;
        border-radius: 4px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: background 0.15s;
    }

    .ie-btn:disabled {
        opacity: 0.45;
        cursor: not-allowed;
    }

    .ie-btn-save {
        background: #89b4fa;
        color: #1e1e2e;
    }

    .ie-btn-save:not(:disabled):hover {
        background: #74c7ec;
    }

    .ie-body {
        flex: 1;
        min-height: 0;
        overflow: hidden;
        position: relative;
        display: flex;
        flex-direction: column;
    }

    .ie-cm-wrap {
        flex: 1;
        min-height: 0;
        overflow: hidden;
    }

    .ie-hidden {
        visibility: hidden;
        pointer-events: none;
    }

    .ie-overlay {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 12px;
        color: #a6adc8;
        font-size: 14px;
        background: #1e1e2e;
        z-index: 1;
    }

    .ie-error strong {
        color: #f38ba8;
        font-size: 16px;
    }

    .ie-error p {
        margin: 0;
        font-size: 13px;
    }

    .ie-spinner {
        width: 28px;
        height: 28px;
        border: 3px solid #313244;
        border-top-color: #89b4fa;
        border-radius: 50%;
        animation: ie-spin 0.7s linear infinite;
    }

    @keyframes ie-spin {
        to { transform: rotate(360deg); }
    }
</style>
