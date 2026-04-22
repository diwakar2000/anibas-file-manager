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
        if (password) {
            onSubmit(password);
        }
    }
</script>

<div class="password-prompt">
    <div class="prompt-card">
        <h2>Settings Password Required</h2>
        <p>Enter the settings password to access File Manager settings.</p>

        <form onsubmit={handleSubmit}>
            <div class="form-group">
                <input
                    type="password"
                    bind:value={password}
                    placeholder="Enter password"
                    disabled={loading}
                    class="form-control"
                    autocomplete="off"
                />
            </div>

            {#if error}
                <div class="error-message">{error}</div>
            {/if}

            <button
                type="submit"
                disabled={loading || !password}
                class="btn btn-primary"
            >
                {loading ? "Verifying..." : "Unlock Settings"}
            </button>
        </form>
    </div>
</div>

<style>
    .password-prompt {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 400px;
    }

    .prompt-card {
        background: white;
        padding: 40px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        max-width: 400px;
        width: 100%;
    }

    h2 {
        margin: 0 0 10px;
        font-size: 24px;
    }

    p {
        color: #666;
        margin-bottom: 20px;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-control {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
    }

    .error-message {
        background: #f8d7da;
        color: #721c24;
        padding: 10px;
        border-radius: 4px;
        margin-bottom: 15px;
        font-size: 14px;
    }

    .btn {
        width: 100%;
        padding: 10px;
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

    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
</style>
