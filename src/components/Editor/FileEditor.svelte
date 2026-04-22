<script lang="ts">
    import { onMount, onDestroy } from 'svelte';
    import { getLanguage } from './editorLanguage';
    import { toast } from '../../utils/toast';

    const cfg: {
        ajaxURL: string;
        path: string;
        storage: string;
        canEdit: boolean;
        fileName: string;
        maxBytes: number;
        chunkBytes: number;
        actions: { getFileChunk: string; saveFile: string };
        nonce: string;
    } = (window as any).AnibasFMEditor;

    // ── State ─────────────────────────────────────────────────────────────────
    let editorEl    = $state<HTMLDivElement | null>(null);
    let status      = $state<'loading' | 'ready' | 'error' | 'saving' | 'saved'>('loading');
    let errorMsg    = $state('');
    let loadPercent = $state(0);
    let isDirty     = $state(false);
    let saveMsg     = $state('');

    // CodeMirror references (not reactive — managed imperatively)
    let cmView: any = null;

    // ── Load file in chunks ───────────────────────────────────────────────────
    async function loadFile(): Promise<string> {
        const chunks: string[] = [];
        let offset = 0;

        while (true) {
            const fd = new FormData();
            fd.append('action',  cfg.actions.getFileChunk);
            fd.append('nonce',   cfg.nonce);
            fd.append('path',    cfg.path);
            fd.append('storage', cfg.storage);
            fd.append('offset',  String(offset));

            const res  = await fetch(cfg.ajaxURL, { method: 'POST', body: fd });
            const json = await res.json();

            if (!json.success) {
                throw new Error(json.data?.message || json.data?.error || 'Failed to load file');
            }

            const { chunk, length, file_size, done } = json.data;
            const text = atob(chunk);
            chunks.push(text);
            offset += length;

            if (file_size > 0) {
                loadPercent = Math.round((offset / file_size) * 100);
            }

            if (done) break;
        }

        return chunks.join('');
    }

    // ── Save ─────────────────────────────────────────────────────────────────
    async function save() {
        if (!cmView || !cfg.canEdit || status === 'saving') return;

        const content = cmView.state.doc.toString();

        // Guard: byte size (UTF-8 can be larger than char count)
        const byteSize = new TextEncoder().encode(content).length;
        if (byteSize > cfg.maxBytes) {
            toast.error(`File exceeds the ${Math.round(cfg.maxBytes / 1048576)} MB save limit.`);
            return;
        }

        status  = 'saving';
        saveMsg = '';

        try {
            const fd = new FormData();
            fd.append('action',  cfg.actions.saveFile);
            fd.append('nonce',   cfg.nonce);
            fd.append('path',    cfg.path);
            fd.append('storage', cfg.storage);
            fd.append('content', btoa(unescape(encodeURIComponent(content)))); // UTF-8 safe base64

            const res  = await fetch(cfg.ajaxURL, { method: 'POST', body: fd });
            const json = await res.json();

            if (!json.success) {
                throw new Error(json.data?.message || 'Save failed');
            }

            isDirty = false;
            status  = 'saved';
            saveMsg = 'Saved';
            setTimeout(() => { if (status === 'saved') status = 'ready'; }, 2000);
        } catch (err: any) {
            status  = 'ready';
            saveMsg = err.message || 'Save failed';
        }
    }

    // ── Keyboard shortcut ─────────────────────────────────────────────────────
    function handleKeyDown(e: KeyboardEvent) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            save();
        }
    }

    // ── Beforeunload guard ────────────────────────────────────────────────────
    function handleBeforeUnload(e: BeforeUnloadEvent) {
        if (isDirty) {
            e.preventDefault();
        }
    }

    // ── Mount ─────────────────────────────────────────────────────────────────
    onMount(async () => {
        window.addEventListener('keydown', handleKeyDown);
        window.addEventListener('beforeunload', handleBeforeUnload);

        try {
            const content = await loadFile();

            // Dynamically import CodeMirror core + language
            const [
                { EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter, drawSelection },
                { EditorState }           ,
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
                getLanguage(cfg.fileName),
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
                EditorView.editable.of(cfg.canEdit),
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
</script>

<div class="afe-shell">
    <!-- Toolbar -->
    <div class="afe-toolbar">
        <div class="afe-file-name">
            <span class="afe-name">{cfg.fileName}</span>
            {#if isDirty}<span class="afe-dirty" title="Unsaved changes">●</span>{/if}
            {#if !cfg.canEdit}<span class="afe-badge afe-badge-readonly">Read-only</span>{/if}
        </div>

        <div class="afe-toolbar-right">
            {#if saveMsg}
                <span class="afe-save-msg" class:afe-save-error={status === 'ready' && saveMsg !== 'Saved'}>{saveMsg}</span>
            {/if}
            {#if cfg.canEdit}
                <button
                    class="afe-btn afe-btn-save"
                    onclick={save}
                    disabled={status === 'saving' || status === 'loading' || status === 'error' || !isDirty}
                >
                    {status === 'saving' ? 'Saving…' : 'Save'}
                </button>
            {/if}
        </div>
    </div>

    <!-- Body -->
    <div class="afe-body">
        {#if status === 'loading'}
            <div class="afe-loading">
                <div class="afe-spinner"></div>
                <span>Loading{loadPercent > 0 ? ` ${loadPercent}%` : '…'}</span>
            </div>
        {:else if status === 'error'}
            <div class="afe-error">
                <strong>Could not open file</strong>
                <p>{errorMsg}</p>
            </div>
        {:else}
            <div class="afe-cm-wrap" bind:this={editorEl}></div>
        {/if}
    </div>
</div>

<style>
    :global(body) {
        margin: 0;
        padding: 0;
        background: #1e1e2e;
        color: #cdd6f4;
        height: 100vh;
        overflow: hidden;
    }

    .afe-shell {
        display: flex;
        flex-direction: column;
        height: 100vh;
    }

    /* ── Toolbar ── */
    .afe-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 16px;
        height: 44px;
        background: #181825;
        border-bottom: 1px solid #313244;
        flex-shrink: 0;
        gap: 12px;
    }

    .afe-file-name {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }

    .afe-name {
        font-size: 13px;
        font-weight: 600;
        color: #cdd6f4;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .afe-dirty {
        color: #f38ba8;
        font-size: 16px;
        line-height: 1;
    }

    .afe-badge {
        font-size: 11px;
        padding: 2px 7px;
        border-radius: 3px;
        font-weight: 600;
    }

    .afe-badge-readonly {
        background: #313244;
        color: #a6adc8;
    }

    .afe-toolbar-right {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }

    .afe-save-msg {
        font-size: 12px;
        color: #a6e3a1;
    }

    .afe-save-error {
        color: #f38ba8;
    }

    .afe-btn {
        padding: 5px 14px;
        border-radius: 4px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: background 0.15s;
    }

    .afe-btn:disabled {
        opacity: 0.45;
        cursor: not-allowed;
    }

    .afe-btn-save {
        background: #89b4fa;
        color: #1e1e2e;
    }

    .afe-btn-save:not(:disabled):hover {
        background: #74c7ec;
    }

    /* ── Body ── */
    .afe-body {
        flex: 1;
        min-height: 0;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .afe-cm-wrap {
        flex: 1;
        min-height: 0;
        overflow: hidden;
    }

    /* ── Loading / Error ── */
    .afe-loading,
    .afe-error {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 12px;
        color: #a6adc8;
        font-size: 14px;
    }

    .afe-error strong {
        color: #f38ba8;
        font-size: 16px;
    }

    .afe-error p {
        margin: 0;
        font-size: 13px;
    }

    .afe-spinner {
        width: 28px;
        height: 28px;
        border: 3px solid #313244;
        border-top-color: #89b4fa;
        border-radius: 50%;
        animation: afe-spin 0.7s linear infinite;
    }

    @keyframes afe-spin {
        to { transform: rotate(360deg); }
    }
</style>
