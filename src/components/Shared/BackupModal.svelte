<script lang="ts">
  import { onDestroy } from "svelte"
  import { backupStart, backupPoll, backupCancel, backupStatus } from "../../services/fileApi"

  let {
    visible = false,
    onclose,
    onstarted,
    oncomplete,
  } = $props<{
    visible: boolean
    onclose: () => void
    onstarted?: () => void
    oncomplete?: () => void
  }>()

  // State
  let phase = $state<"choose" | "running" | "done" | "error">("choose")
  let format = $state<"tar" | "anfm">("tar")
  let password = $state("")
  let confirmPassword = $state("")
  let jobId = $state("")
  let outputFile = $state("")
  let errorMsg = $state("")
  let cancelling = $state(false)
  let starting = $state(false)

  // Progress
  let progress = $state<{
    current: number
    total: number
    percent: number
    bytes_processed: number
    total_size: number
    phase: string
  }>({ current: 0, total: 0, percent: 0, bytes_processed: 0, total_size: 0, phase: "init" })

  let pollTimer: ReturnType<typeof setTimeout> | null = null

  function formatBytes(bytes: number): string {
    if (bytes === 0) return "0 B"
    const k = 1024
    const sizes = ["B", "KB", "MB", "GB"]
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + " " + sizes[i]
  }

  async function handleStart() {
    if (format === "anfm" && password && password !== confirmPassword) {
      errorMsg = "Passwords do not match"
      return
    }

    starting = true
    errorMsg = ""

    try {
      const result = await backupStart(format, format === "anfm" && password ? password : undefined)
      jobId = result.job_id
      outputFile = result.output
      phase = "running"
      onstarted?.()
      pollBackup()
    } catch (err: any) {
      errorMsg = err.message || "Failed to start backup"
      phase = "error"
    } finally {
      starting = false
    }
  }

  async function pollBackup() {
    if (!jobId) return

    try {
      const result = await backupPoll(jobId, format === "anfm" && password ? password : undefined)
      progress = result.progress

      if (result.done) {
        phase = "done"
        oncomplete?.()
        return
      }

      // Continue polling
      pollTimer = setTimeout(pollBackup, 2000)
    } catch (err: any) {
      errorMsg = err.message || "Backup failed"
      phase = "error"
    }
  }

  async function handleCancel() {
    if (!jobId || cancelling) return
    cancelling = true

    try {
      await backupCancel(jobId)
      cleanup()
      onclose()
    } catch (err: any) {
      errorMsg = err.message || "Failed to cancel"
    } finally {
      cancelling = false
    }
  }

  function handleDone() {
    cleanup()
    onclose()
  }

  function cleanup() {
    if (pollTimer) {
      clearTimeout(pollTimer)
      pollTimer = null
    }
    phase = "choose"
    jobId = ""
    outputFile = ""
    errorMsg = ""
    password = ""
    confirmPassword = ""
    progress = { current: 0, total: 0, percent: 0, bytes_processed: 0, total_size: 0, phase: "init" }
  }

  // Check if a backup is already running on mount
  $effect(() => {
    if (visible && phase === "choose") {
      backupStatus().then((status) => {
        if (status.running && status.job_id) {
          jobId = status.job_id
          outputFile = status.output ?? ""
          format = (status.format as "tar" | "anfm") ?? "tar"
          if (status.progress) progress = status.progress
          phase = "running"
          onstarted?.()
          pollBackup()
        }
      }).catch(() => {
        // ignore
      })
    }
  })

  // Portal: mount overlay into document.body so it escapes any parent
  // overflow/transform containers (WordPress admin wrappers)
  let overlayEl: HTMLDivElement | undefined = $state(undefined)

  $effect(() => {
    if (overlayEl) {
      document.body.appendChild(overlayEl)
    }
  })

  // Cleanup on hide
  $effect(() => {
    if (!visible && pollTimer) {
      clearTimeout(pollTimer)
      pollTimer = null
    }
  })

  onDestroy(() => {
    if (overlayEl?.parentNode) {
      overlayEl.parentNode.removeChild(overlayEl)
    }
  })
</script>

{#if visible}
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <div class="anibas-fm-backup-overlay" bind:this={overlayEl}>
    <div class="anibas-fm-backup-modal">
      {#if phase === "choose"}
        <h3>Site Backup</h3>
        <p class="anibas-fm-description">
          Create a backup of your site's wp-content directory and essential root files.
          Backups are stored for 7 days and automatically purged.
        </p>

        <div class="anibas-fm-form-group">
          <label for="backup-format">Backup Format</label>
          <select id="backup-format" bind:value={format} class="anibas-fm-form-control">
            <option value="tar">.tar (standard archive)</option>
            <option value="anfm">.anfm (encrypted archive)</option>
          </select>
        </div>

        {#if format === "anfm"}
          <div class="anibas-fm-form-group">
            <label for="backup-password">Encryption Password (optional)</label>
            <input
              id="backup-password"
              type="password"
              bind:value={password}
              class="anibas-fm-form-control"
              placeholder="Leave empty for keyless encryption"
              autocomplete="new-password"
            />
          </div>
          {#if password}
            <div class="anibas-fm-form-group">
              <label for="backup-confirm-password">Confirm Password</label>
              <input
                id="backup-confirm-password"
                type="password"
                bind:value={confirmPassword}
                class="anibas-fm-form-control"
                autocomplete="new-password"
              />
            </div>
          {/if}
        {/if}

        {#if errorMsg}
          <div class="anibas-fm-error-msg">{errorMsg}</div>
        {/if}

        <div class="anibas-fm-modal-actions">
          <button class="anibas-fm-btn anibas-fm-btn-secondary" onclick={onclose} disabled={starting}>
            Cancel
          </button>
          <button class="anibas-fm-btn anibas-fm-btn-primary" onclick={handleStart} disabled={starting}>
            {starting ? "Starting..." : "Start Backup"}
          </button>
        </div>

      {:else if phase === "running"}
        <h3>Backup In Progress</h3>
        <p class="anibas-fm-description">
          Please do not close this page or perform other file operations until the backup is complete.
        </p>

        <div class="anibas-fm-progress-section">
          <div class="anibas-fm-progress-bar-track">
            <div class="anibas-fm-progress-bar-fill" style="width: {progress.percent}%"></div>
          </div>
          <div class="anibas-fm-progress-stats">
            <span>{progress.percent.toFixed(1)}%</span>
            <span>{formatBytes(progress.bytes_processed)} / {formatBytes(progress.total_size)}</span>
          </div>
          <div class="anibas-fm-progress-detail">
            Files: {progress.current} / {progress.total}
          </div>
        </div>

        <div class="anibas-fm-modal-actions">
          <button class="anibas-fm-btn anibas-fm-btn-danger" onclick={handleCancel} disabled={cancelling}>
            {cancelling ? "Cancelling..." : "Cancel Backup"}
          </button>
        </div>

      {:else if phase === "done"}
        <h3>Backup Complete</h3>
        <div class="anibas-fm-success-icon">&#10003;</div>
        <p class="anibas-fm-description">
          Your backup has been saved as <strong>{outputFile}</strong> in the backups directory.
        </p>
        <div class="anibas-fm-progress-stats" style="margin-bottom: 20px;">
          <span>Files: {progress.total}</span>
          <span>Size: {formatBytes(progress.total_size)}</span>
        </div>

        <div class="anibas-fm-modal-actions">
          <button class="anibas-fm-btn anibas-fm-btn-primary" onclick={handleDone}>
            Done
          </button>
        </div>

      {:else if phase === "error"}
        <h3>Backup Failed</h3>
        <div class="anibas-fm-error-icon">&#10007;</div>
        {#if errorMsg}
          <div class="anibas-fm-error-msg">{errorMsg}</div>
        {/if}

        <div class="anibas-fm-modal-actions">
          <button class="anibas-fm-btn anibas-fm-btn-secondary" onclick={() => { phase = "choose"; errorMsg = "" }}>
            Try Again
          </button>
          <button class="anibas-fm-btn anibas-fm-btn-primary" onclick={handleDone}>
            Close
          </button>
        </div>
      {/if}
    </div>
  </div>
{/if}

<style>
  .anibas-fm-backup-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100000;
  }

  .anibas-fm-backup-modal {
    background: #fff;
    border-radius: 8px;
    padding: 30px;
    max-width: 480px;
    width: 90%;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
  }

  .anibas-fm-backup-modal h3 {
    margin: 0 0 10px;
    font-size: 20px;
    color: #1d2327;
  }

  .anibas-fm-description {
    color: #646970;
    font-size: 14px;
    margin: 0 0 20px;
    line-height: 1.5;
  }

  .anibas-fm-form-group {
    margin-bottom: 16px;
  }

  .anibas-fm-form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    font-size: 14px;
    color: #1d2327;
  }

  .anibas-fm-form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #8c8f94;
    border-radius: 4px;
    font-size: 14px;
    box-sizing: border-box;
  }

  .anibas-fm-form-control:focus {
    outline: none;
    border-color: #2271b1;
    box-shadow: 0 0 0 1px #2271b1;
  }

  select.anibas-fm-form-control {
    cursor: pointer;
  }

  .anibas-fm-progress-section {
    margin: 20px 0;
  }

  .anibas-fm-progress-bar-track {
    height: 20px;
    background: #e0e0e0;
    border-radius: 10px;
    overflow: hidden;
  }

  .anibas-fm-progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #2271b1, #135e96);
    border-radius: 10px;
    transition: width 0.3s ease;
    min-width: 2%;
  }

  .anibas-fm-progress-stats {
    display: flex;
    justify-content: space-between;
    margin-top: 10px;
    font-size: 13px;
    color: #646970;
  }

  .anibas-fm-progress-detail {
    text-align: center;
    margin-top: 6px;
    font-size: 13px;
    color: #646970;
  }

  .anibas-fm-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 24px;
  }

  .anibas-fm-btn {
    padding: 8px 20px;
    border: none;
    border-radius: 4px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
  }

  .anibas-fm-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .anibas-fm-btn-primary {
    background: #2271b1;
    color: #fff;
  }

  .anibas-fm-btn-primary:hover:not(:disabled) {
    background: #135e96;
  }

  .anibas-fm-btn-secondary {
    background: #f0f0f1;
    color: #2c3338;
    border: 1px solid #8c8f94;
  }

  .anibas-fm-btn-secondary:hover:not(:disabled) {
    background: #e5e5e5;
  }

  .anibas-fm-btn-danger {
    background: #d63638;
    color: #fff;
  }

  .anibas-fm-btn-danger:hover:not(:disabled) {
    background: #a00;
  }

  .anibas-fm-error-msg {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #d63638;
    padding: 10px 14px;
    border-radius: 4px;
    font-size: 13px;
    margin-bottom: 16px;
  }

  .anibas-fm-success-icon {
    font-size: 48px;
    color: #00a32a;
    text-align: center;
    margin: 10px 0;
  }

  .anibas-fm-error-icon {
    font-size: 48px;
    color: #d63638;
    text-align: center;
    margin: 10px 0;
  }
</style>
