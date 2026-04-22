<script lang="ts">
  import { onMount } from 'svelte'

  interface Props {
    message: string
    type?: 'success' | 'error' | 'info'
    duration?: number
    onClose: () => void
  }

  let { message, type = 'success', duration = 3000, onClose }: Props = $props()

  let visible = $state(false)
  let timeoutId: number | undefined

  onMount(() => {
    visible = true
    if (duration > 0) {
      timeoutId = setTimeout(() => {
        visible = false
        setTimeout(onClose, 300)
      }, duration) as unknown as number
    }

    return () => {
      if (timeoutId) clearTimeout(timeoutId)
    }
  })

  function close() {
    if (timeoutId) clearTimeout(timeoutId)
    visible = false
    setTimeout(onClose, 300)
  }
</script>

<div class="toast" class:visible class:success={type === 'success'} class:error={type === 'error'} class:info={type === 'info'}>
  <div class="toast-content">
    <span class="toast-icon">
      {#if type === 'success'}✓{/if}
      {#if type === 'error'}✕{/if}
      {#if type === 'info'}ℹ{/if}
    </span>
    <span class="toast-message">{message}</span>
    <button class="toast-close" onclick={close} type="button">✕</button>
  </div>
</div>

<style>
  .toast {
    position: fixed;
    top: 20px;
    right: 20px;
    min-width: 300px;
    max-width: 500px;
    padding: 16px 20px;
    border-radius: 4px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 100001;
    opacity: 0;
    transform: translateX(400px);
    transition: all 0.3s ease;
  }

  .toast.visible {
    opacity: 1;
    transform: translateX(0);
  }

  .toast.success {
    background: #4caf50;
    color: white;
  }

  .toast.error {
    background: #f44336;
    color: white;
  }

  .toast.info {
    background: #2196f3;
    color: white;
  }

  .toast-content {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .toast-icon {
    font-size: 20px;
    font-weight: bold;
  }

  .toast-message {
    flex: 1;
    font-size: 14px;
  }

  .toast-close {
    background: transparent;
    border: none;
    color: white;
    font-size: 18px;
    cursor: pointer;
    padding: 0;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0.7;
  }

  .toast-close:hover {
    opacity: 1;
  }
</style>
