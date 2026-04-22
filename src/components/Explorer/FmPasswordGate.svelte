<script lang="ts">
    let {
        loading = false,
        error = null,
        onSubmit,
    } = $props<{
        loading?: boolean;
        error?: string | null;
        onSubmit: (password: string) => void;
    }>();

    let password = $state("");

    function handleSubmit(e: Event) {
        e.preventDefault();
        if (password.trim()) {
            onSubmit(password);
        }
    }
</script>

<div class="fm-gate-overlay">
    <div class="fm-gate-card">
        <div class="fm-gate-icon">🔒</div>
        <h2>File Manager Protected</h2>
        <p>Enter the file manager password to continue.</p>

        <form onsubmit={handleSubmit} autocomplete="off">
            <div class="form-group">
                <!-- svelte-ignore a11y_autofocus -->
                <input
                    type="password"
                    bind:value={password}
                    placeholder="Enter password"
                    disabled={loading}
                    class="form-control"
                    autocomplete="off"
                    autofocus
                />
            </div>

            {#if error}
                <div class="error-message">{error}</div>
            {/if}

            <button
                type="submit"
                disabled={loading || !password.trim()}
                class="btn-unlock"
            >
                {loading ? "Verifying..." : "Unlock"}
            </button>
        </form>
    </div>
</div>

<style>
    .fm-gate-overlay {
        position: absolute;
        inset: 0;
        background: rgba(255, 255, 255, 0.97);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 100;
        border-radius: 4px;
    }

    .fm-gate-card {
        background: #fff;
        border: 1px solid #ccd0d4;
        border-radius: 6px;
        padding: 40px 36px;
        max-width: 360px;
        width: 100%;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        text-align: center;
    }

    .fm-gate-icon {
        font-size: 36px;
        margin-bottom: 12px;
    }

    h2 {
        margin: 0 0 8px;
        font-size: 20px;
        color: #1d2327;
    }

    p {
        color: #646970;
        font-size: 13px;
        margin: 0 0 24px;
    }

    .form-group {
        margin-bottom: 14px;
        text-align: left;
    }

    .form-control {
        width: 100%;
        padding: 9px 11px;
        border: 1px solid #8c8f94;
        border-radius: 4px;
        font-size: 14px;
        box-sizing: border-box;
    }

    .form-control:focus {
        outline: none;
        border-color: #2271b1;
        box-shadow: 0 0 0 1px #2271b1;
    }

    .error-message {
        background: #f8d7da;
        color: #721c24;
        padding: 9px 12px;
        border-radius: 4px;
        margin-bottom: 14px;
        font-size: 13px;
        text-align: left;
    }

    .btn-unlock {
        width: 100%;
        padding: 9px;
        background: #2271b1;
        color: white;
        border: none;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
    }

    .btn-unlock:hover:not(:disabled) {
        background: #135e96;
    }

    .btn-unlock:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
</style>
