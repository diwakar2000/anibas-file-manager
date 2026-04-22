<script lang="ts">
  type MenuItem = {
    label?: string;
    icon?: string;
    action?: () => void;
    disabled?: boolean;
    separator?: boolean;
    danger?: boolean;
    thickSeparator?: boolean;
  };

  let { items, x, y, onclose } = $props<{
    items: MenuItem[];
    x: number;
    y: number;
    onclose: () => void;
  }>();

  function handleBackdropClick() {
    onclose();
  }

  function handleItemClick(item: MenuItem) {
    if (item.separator) return;
    if (item.disabled) return;
    item.action?.();
    onclose();
  }

  // Adjust position so menu doesn't overflow viewport
  let menuEl = $state<HTMLElement | null>(null);
  let adjustedX = $state(x);
  let adjustedY = $state(y);

  $effect(() => {
    if (menuEl) {
      const rect = menuEl.getBoundingClientRect();
      const vw = window.innerWidth;
      const vh = window.innerHeight;
      adjustedX = (x + rect.width > vw) ? vw - rect.width - 4 : x;
      adjustedY = (y + rect.height > vh) ? vh - rect.height - 4 : y;
    }
  });
</script>

<!-- svelte-ignore a11y_no_static_element_interactions -->
<!-- svelte-ignore a11y_click_events_have_key_events -->
<div class="context-backdrop" onclick={handleBackdropClick} oncontextmenu={(e) => { e.preventDefault(); handleBackdropClick(); }}>
  <div
    class="context-menu"
    bind:this={menuEl}
    style="left: {adjustedX}px; top: {adjustedY}px;"
    onclick={(e) => e.stopPropagation()}
    oncontextmenu={(e) => e.stopPropagation()}
  >
    {#each items as item (item.label)}
      {#if 'separator' in item && item.separator}
        <div class={`separator ${item.thickSeparator ? 'thick' : ''}`}></div>
      {:else if !('separator' in item)}
        <button
          class="menu-item"
          class:disabled={item.disabled}
          class:danger={item.danger}
          onclick={() => handleItemClick(item)}
          disabled={item.disabled}
        >
          {#if item.icon}<span class="menu-icon">{@html item.icon}</span>{/if}
          <span class="menu-label">{item.label}</span>
        </button>
      {/if}
    {/each}
  </div>
</div>

<style>
  .context-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 99999;
  }

  .context-menu {
    position: fixed;
    background: #fff;
    border: 1px solid #d0d0d0;
    border-radius: 6px;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
    min-width: 180px;
    padding: 4px 0;
    z-index: 100000;
  }

  .menu-item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 7px 14px;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 13px;
    color: #333;
    text-align: left;
    white-space: nowrap;
    transition: background 0.1s;
  }

  .menu-item:hover:not(:disabled) {
    background: #f0f4f8;
  }

  .menu-item.danger {
    color: #dc2626;
  }

  .menu-item.danger:hover:not(:disabled) {
    background: #fef2f2;
  }

  .menu-item.disabled {
    color: #aaa;
    cursor: default;
  }

  .menu-icon {
    font-size: 15px;
    width: 20px;
    text-align: center;
    flex-shrink: 0;
  }

  .menu-label {
    flex: 1;
  }

  .separator {
    height: 1px;
    background: #e8e8e8;
    margin: 4px 0;
  }

  .separator.thick {
    height: 2px;
    background: #d1d5db;
    margin: 8px 0;
  }
</style>
