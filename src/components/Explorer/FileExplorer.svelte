<script lang="ts">
  import { onMount } from "svelte";
  import { fileStore } from "../../stores/fileStore.svelte";
  import { toast } from "../../utils/toast";
  import { getDownloadUrl } from "../../services/fileApi";
  import { isEditable } from "../../utils/editable";
  import Breadcrumbs from "./Breadcrumbs.svelte";
  import FileRow from "./FileRow.svelte";
  import GridItem from "./GridItem.svelte";
  import Toolbar from "./Toolbar.svelte";
  import Statusbar from "./Statusbar.svelte";
  import Pagination from "./Pagination.svelte";
  import ContextMenu from "./ContextMenu.svelte";
  import FmPasswordGate from "./FmPasswordGate.svelte";
  import PreviewPanel from "./PreviewPanel.svelte";
  import DetailsModal from "./DetailsModal.svelte";
  import SendToModal from "./SendToModal.svelte";
  import type { FileItem } from "../../types/files";

  // ── Mobile: default to grid view on narrow screens ──────────
  const mobileQuery = window.matchMedia("(max-width: 782px)");

  function applyMobileViewMode(mobile: boolean) {
    if (mobile && fileStore.viewMode === "list") {
      fileStore.setViewMode("grid");
    } else if (!mobile && fileStore.viewMode === "grid") {
      fileStore.setViewMode("list");
    }
  }

  onMount(() => {
    applyMobileViewMode(mobileQuery.matches);
    const handler = (e: MediaQueryListEvent) => applyMobileViewMode(e.matches);
    mobileQuery.addEventListener("change", handler);
    return () => mobileQuery.removeEventListener("change", handler);
  });

  // ── Search + Sort ────────────────────────────────────────────
  let searchQuery = $state("");
  let sortKey = $state<"name" | "size" | "type" | "modified">("name");
  let sortAsc = $state(true);

  // Reset search/sort and close any open menus/dialogs when directory or storage changes
  $effect(() => {
    fileStore.currentPath;
    fileStore.currentStorage;
    searchQuery = "";
    sortKey = "name";
    sortAsc = true;
    // Close context menus
    showBgMenu = false;
    showGridContextMenu = false;
    selectedGridFile = null;
    // Close dialogs
    showDeleteDialog = false;
    showPasswordDialog = false;
    showArchiveDialog = false;
    showExtractPasswordDialog = false;
    showEmptyFolderDialog = false;
    showEmptyFolderPasswordDialog = false;
    showZipFallbackDialog = false;
    showGridDetails = false;
    showGridSendTo = false;
    archiveConflict = null;
    deleteToken = null;
  });

  const displayFiles = $derived.by(() => {
    let files = fileStore.currentFiles;
    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase();
      files = files.filter((f) => f.name.toLowerCase().includes(q));
    }
    return [...files].sort((a, b) => {
      if (a.is_folder && !b.is_folder) return -1;
      if (!a.is_folder && b.is_folder) return 1;
      let cmp = 0;
      switch (sortKey) {
        case "name":
          cmp = a.name.localeCompare(b.name);
          break;
        case "size":
          cmp = (a.filesize ?? 0) - (b.filesize ?? 0);
          break;
        case "type":
          cmp = (a.file_type ?? "").localeCompare(b.file_type ?? "");
          break;
        case "modified":
          cmp = (a.last_modified ?? 0) - (b.last_modified ?? 0);
          break;
      }
      return sortAsc ? cmp : -cmp;
    });
  });

  function toggleSort(key: typeof sortKey) {
    if (sortKey === key) {
      sortAsc = !sortAsc;
    } else {
      sortKey = key;
      sortAsc = true;
    }
  }

  // ── Bulk delete ─────────────────────────────────────────────
  let showBulkDeleteConfirm = $state(false);
  let bulkDeletePending = $state<Array<{ path: string; token: string }>>([]);
  let isBulkDeleting = $state(false);

  async function initBulkDelete() {
    const paths = [...fileStore.selectedPaths];
    if (paths.length === 0) return;
    try {
      const tokens = await Promise.all(
        paths.map((p) => fileStore.requestDeleteToken(p)),
      );
      bulkDeletePending = paths.map((path, i) => ({ path, token: tokens[i] }));
      showBulkDeleteConfirm = true;
    } catch (err: any) {
      toast.error(err.message || "Failed to initiate delete");
    }
  }

  async function confirmBulkDelete() {
    isBulkDeleting = true;
    showBulkDeleteConfirm = false;
    const items = [...bulkDeletePending];
    bulkDeletePending = [];
    let failed = 0;
    for (const { path, token } of items) {
      try {
        fileStore.deletingPaths = [...fileStore.deletingPaths, path];
        await fileStore.deleteFile(path, token);
      } catch {
        fileStore.deletingPaths = fileStore.deletingPaths.filter(
          (p) => p !== path,
        );
        failed++;
      }
    }
    isBulkDeleting = false;
    if (failed > 0) toast.error(`${failed} item(s) could not be deleted`);
    fileStore.clearSelection();
  }

  // ── Download helper ──────────────────────────────────────────
  function triggerDownload(path: string) {
    const url = getDownloadUrl(path, fileStore.currentStorage);
    const a = document.createElement("a");
    a.href = url;
    a.setAttribute("download", "");
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  }

  // ── Keyboard shortcuts ───────────────────────────────────────
  $effect(() => {
    const handler = (e: KeyboardEvent) => {
      if (fileStore.fmGateVisible) return;
      const tag = (e.target as HTMLElement)?.tagName;
      if (tag === "INPUT" || tag === "TEXTAREA") return;
      if (e.key === "F2" && fileStore.selectionCount === 1) {
        e.preventDefault();
        fileStore.startRename(fileStore.selectedPaths[0]);
      } else if (e.key === "Delete" && fileStore.hasSelection) {
        e.preventDefault();
        initBulkDelete();
      } else if ((e.ctrlKey || e.metaKey) && e.key === "a") {
        e.preventDefault();
        fileStore.selectAll(displayFiles.map((f) => f.path));
      } else if (
        (e.ctrlKey || e.metaKey) &&
        e.key === "c" &&
        fileStore.selectionCount === 1
      ) {
        const p = fileStore.selectedPaths[0];
        fileStore.copyToClipboard([p]);
        toast.info(`"${p.split("/").pop()}" copied to clipboard`);
      } else if (
        (e.ctrlKey || e.metaKey) &&
        e.key === "x" &&
        fileStore.selectionCount === 1
      ) {
        const p = fileStore.selectedPaths[0];
        fileStore.cutToClipboard([p]);
        toast.info(`"${p.split("/").pop()}" cut to clipboard`);
      } else if (e.key === "Escape") {
        if (fileStore.renamingPath) {
          fileStore.stopRename();
        } else {
          fileStore.clearSelection();
        }
      }
    };
    document.addEventListener("keydown", handler);
    return () => document.removeEventListener("keydown", handler);
  });

  let showBgMenu = $state(false);
  let bgMenuX = $state(0);
  let bgMenuY = $state(0);

  // Grid view context menu
  let showGridContextMenu = $state(false);
  let gridContextMenuX = $state(0);
  let gridContextMenuY = $state(0);
  let selectedGridFile: FileItem | null = $state(null);

  // Grid view archive state
  let showArchiveDialog = $state(false);
  let archiveFormat = $state<"zip" | "anfm" | "tar">("zip");
  let archivePassword = $state("");
  let isScanning = $state(false);
  let isCreatingArchive = $state(false);
  let showZipFallbackDialog = $state(false);
  let archiveConflict = $state<{ output: string; output_size: number } | null>(
    null,
  );
  let archiveConflictMode = $state<"overwrite" | "rename">("rename");
  let prescanInfo = $state<{
    total: number;
    total_size: number;
    max_file_size: number;
    max_file_name: string;
  } | null>(null);
  const ZIP_MAX_FILE_SIZE = 100 * 1024 * 1024;

  // Grid view extract state
  let showExtractPasswordDialog = $state(false);
  let extractPassword = $state("");
  let isExtracting = $state(false);

  // Grid view delete state
  let showDeleteDialog = $state(false);
  let showPasswordDialog = $state(false);
  let deletePassword = $state("");
  let isDeletingFile = $state(false);
  let deleteToken = $state<string | null>(null);

  // Grid view details state
  let showGridDetails = $state(false);

  // Grid view send to state
  let showGridSendTo = $state(false);

  function handleBodyClick(e: MouseEvent) {
    const target = e.target as HTMLElement;
    if (!target.closest(".file-row") && !target.closest(".grid-item")) {
      fileStore.clearSelection();
    }
  }

  function handleBodyContext(e: MouseEvent) {
    const target = e.target as HTMLElement;
    if (target.closest(".file-row") || target.closest(".grid-item")) return;
    e.preventDefault();
    bgMenuX = e.clientX;
    bgMenuY = e.clientY;
    showBgMenu = true;
  }

  function handleGridContextMenu(data: {
    file: FileItem;
    x: number;
    y: number;
  }) {
    selectedGridFile = data.file;
    gridContextMenuX = data.x;
    gridContextMenuY = data.y;
    showGridContextMenu = true;
  }


  function getFileContextMenuItems(file: FileItem): {
    label?: string;
    icon?: string;
    action?: () => void;
    disabled?: boolean;
    separator?: boolean;
    danger?: boolean;
    thickSeparator?: boolean;
  }[] {
    const busy = Object.keys(fileStore.activeJobs).length > 0;
    const archiveType = fileStore.isArchive(file);
    const items: {
      label?: string;
      icon?: string;
      action?: () => void;
      disabled?: boolean;
      separator?: boolean;
      danger?: boolean;
      thickSeparator?: boolean;
    }[] = [];

    // Icon SVGs (16x16 viewBox)
    const icons = {
      edit: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
      preview: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
      open: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 19a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h4l2 2h4a2 2 0 0 1 2 2v1M5 19h14a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v5a2 2 0 0 1-2 2z"/></svg>',
      emptyFolder: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
      copy: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15V4a2 2 0 0 1 2-2h9"/></svg>',
      cut: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>',
      paste: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>',
      compress: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>',
      extract: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 22h14a2 2 0 0 0 2-2V7.5L14.5 2H6a2 2 0 0 0-2 2v4"/><path d="M14 2v6h6"/><path d="M2 15v-3h6v3"/><path d="M2 10v3h3v-3"/><path d="M5 10V8a2 2 0 0 1 4 0v2"/></svg>',
      rename: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5"/></svg>',
      download: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
      delete: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
      details: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    };

    // "Edit" / "Preview" appear first for files
    if (!file.is_folder) {
      if (isEditable(file)) {
        items.push({
          label: "Edit",
          icon: icons.edit,
          action: () => fileStore.openEditor(file.path, true),
        });
      }
      items.push({
        label: "Preview",
        icon: icons.preview,
        action: () => { fileStore.selectedPaths = [file.path]; fileStore.previewOpen = true; },
      });
      items.push({ separator: true });
    }

    if (file.is_folder) {
      items.push({
        label: "Open",
        icon: icons.open,
        action: () => fileStore.navigateTo(file.path),
      });
      items.push({
        label: "Empty Folder",
        icon: icons.emptyFolder,
        action: () => handleEmptyFolder(file),
        disabled: busy,
      });
      items.push({ separator: true });
    }

    items.push(
      {
        label: "Copy",
        icon: icons.copy,
        action: () => {
          fileStore.copyToClipboard([file.path]);
          toast.info(`"${file.name}" copied to clipboard`);
        },
        disabled: busy,
      },
      {
        label: "Cut",
        icon: icons.cut,
        action: () => {
          fileStore.cutToClipboard([file.path]);
          toast.info(`"${file.name}" cut to clipboard`);
        },
        disabled: busy,
      },
    );

    if (fileStore.clipboard) {
      items.push({
        label: `Paste (${fileStore.clipboard.action === "copy" ? "Copy" : "Move"})`,
        icon: icons.paste,
        action: () => handleGridPaste(file),
        disabled: busy || !file.is_folder,
      });
    }

    items.push({ separator: true });

    if (file.is_folder) {
      items.push({
        label: isScanning ? "Scanning..." : "Compress",
        icon: icons.compress,
        action: () => openCompressDialog(file),
        disabled: busy || isScanning,
      });
    }

    if (archiveType) {
      items.push({
        label: "Extract",
        icon: icons.extract,
        action: () => handleExtract(file),
        disabled: busy,
      });
    }

    items.push({ separator: true });
    items.push({
      label: "Rename",
      icon: icons.rename,
      action: () => fileStore.startRename(file.path),
      disabled: busy,
    });
    if (!file.is_folder) {
      items.push({
        label: "Download",
        icon: icons.download,
        action: () => triggerDownload(file.path),
      });
    }
    items.push({ separator: true });
    items.push({
      label: "Send to...",
      icon: "📤",
      action: () => (showGridSendTo = true),
    });
    items.push({ separator: true });
    items.push({
      label: "Details",
      icon: "ℹ️",
      action: () => (showGridDetails = true),
      danger: true,
    });

    return items;
  }

  function getBgMenuItems() {
    const busy = Object.keys(fileStore.activeJobs).length > 0;
    const items: {
      label?: string;
      icon?: string;
      action?: () => void;
      disabled?: boolean;
      separator?: boolean;
    }[] = [];

    if (fileStore.clipboard) {
      items.push({
        label: `Paste (${fileStore.clipboard.action === "copy" ? "Copy" : "Move"})`,
        icon: "📌",
        action: async () => {
          try {
            await fileStore.requestPaste(fileStore.currentPath);
          } catch {}
        },
        disabled: busy,
      });
      items.push({ separator: true });
    }

    items.push({
      label: "Refresh",
      icon: "🔄",
      action: () => fileStore.loadDirectory(fileStore.currentPath),
    });

    return items;
  }

  // --- Grid view: Paste into folder ---
  async function handleGridPaste(file: FileItem) {
    if (!file.is_folder || !fileStore.clipboard) return;
    try {
      await fileStore.requestPaste(file.path);
    } catch {}
  }

  // --- Grid view: Delete flow ---
  let showEmptyFolderDialog = $state(false);
  let emptyFolderTarget = $state<FileItem | null>(null);
  let showEmptyFolderPasswordDialog = $state(false);
  let emptyFolderPassword = $state("");
  let isEmptyingFolder = $state(false);

  function handleEmptyFolder(file: FileItem) {
    emptyFolderTarget = file;
    showEmptyFolderDialog = true;
  }

  async function confirmEmptyFolder() {
    if (!emptyFolderTarget) return;
    showEmptyFolderDialog = false;
    try {
      await fileStore.emptyFolder(emptyFolderTarget.path);
      emptyFolderTarget = null;
    } catch (err: any) {
      if (err.message === "DeletePasswordRequired") {
        showEmptyFolderPasswordDialog = true;
      } else {
        toast.error(err.message || "Failed to empty folder");
        emptyFolderTarget = null;
      }
    }
  }

  async function handleEmptyFolderPasswordSubmit() {
    if (!emptyFolderPassword.trim() || !emptyFolderTarget) return;
    isEmptyingFolder = true;
    try {
      await fileStore.verifyDeletePassword(emptyFolderPassword);
      await fileStore.emptyFolder(emptyFolderTarget.path);
      showEmptyFolderPasswordDialog = false;
      emptyFolderPassword = "";
      emptyFolderTarget = null;
    } catch (err: any) {
      toast.error(err.message || "Invalid password");
    } finally {
      isEmptyingFolder = false;
    }
  }

  async function handleGridDelete(file: FileItem) {
    if (fileStore.isDeleting(file.path)) return;
    selectedGridFile = file;
    try {
      deleteToken = await fileStore.requestDeleteToken(file.path);
      showDeleteDialog = true;
    } catch (err: any) {
      toast.error(err.message || "Failed to initiate delete");
    }
  }

  async function confirmGridDelete() {
    if (!deleteToken || !selectedGridFile) return;
    const file = selectedGridFile;
    fileStore.deletingPaths = [...fileStore.deletingPaths, file.path];
    showDeleteDialog = false;
    try {
      await fileStore.deleteFile(file.path, deleteToken);
      deleteToken = null;
    } catch (err: any) {
      fileStore.deletingPaths = fileStore.deletingPaths.filter(
        (p) => p !== file.path,
      );
      if (err.message === "DeletePasswordRequired") {
        showPasswordDialog = true;
      } else if (err.message === "DeleteTokenExpired") {
        toast.error("Delete confirmation expired. Please try again.");
      } else {
        toast.error(err.message || "Failed to delete");
      }
    }
  }

  async function handleGridPasswordSubmit() {
    if (!deletePassword.trim() || !deleteToken || !selectedGridFile) return;
    isDeletingFile = true;
    try {
      await fileStore.verifyDeletePassword(deletePassword);
      await fileStore.deleteFile(selectedGridFile.path, deleteToken);
      showPasswordDialog = false;
      deletePassword = "";
      deleteToken = null;
    } catch (err: any) {
      if (err.message === "DeleteTokenExpired") {
        toast.error("Delete confirmation expired. Please try again.");
        showPasswordDialog = false;
      } else {
        toast.error(err.message || "Invalid password");
      }
    } finally {
      isDeletingFile = false;
    }
  }

  // --- Grid view: Compress flow ---
  async function openCompressDialog(file: FileItem) {
    selectedGridFile = file;
    isScanning = true;
    try {
      const info = await fileStore.prescanFolder(file.path);
      prescanInfo = info;
      archiveFormat = info.max_file_size > ZIP_MAX_FILE_SIZE ? "tar" : "zip";
      archivePassword = "";
      showArchiveDialog = true;
    } catch {
    } finally {
      isScanning = false;
    }
  }

  async function confirmCompress(conflictMode?: "overwrite" | "rename") {
    if (!selectedGridFile) return;
    isCreatingArchive = true;
    try {
      await fileStore.startArchiveCreate(
        selectedGridFile.path,
        archiveFormat,
        archiveFormat === "anfm" && archivePassword.trim()
          ? archivePassword.trim()
          : undefined,
        conflictMode,
      );
      showArchiveDialog = false;
      archiveConflict = null;
    } catch (err: any) {
      if (err.message === "ArchiveConflict") {
        archiveConflict = { output: err.output, output_size: err.output_size };
        archiveConflictMode = "rename";
      } else if (err.message === "ZipConnectionTimeout") {
        showArchiveDialog = false;
        archiveConflict = null;
        archiveFormat = "tar";
        archivePassword = "";
        showZipFallbackDialog = true;
      }
    } finally {
      isCreatingArchive = false;
    }
  }

  async function confirmCompressConflict() {
    archiveConflict = null;
    await confirmCompress(archiveConflictMode);
  }

  async function confirmZipFallback() {
    if (!selectedGridFile) return;
    isCreatingArchive = true;
    try {
      await fileStore.startArchiveCreate(
        selectedGridFile.path,
        archiveFormat,
        archiveFormat === "anfm" && archivePassword.trim()
          ? archivePassword.trim()
          : undefined,
      );
      showZipFallbackDialog = false;
    } catch {
    } finally {
      isCreatingArchive = false;
    }
  }

  // --- Grid view: Extract flow ---
  async function handleExtract(file: FileItem) {
    selectedGridFile = file;
    try {
      const info = await fileStore.checkArchive(file.path);
      if (!info.valid) {
        toast.error(
          info.reason ||
            "This file is not a valid archive — it may be corrupted.",
        );
        return;
      }
      if (info.password_protected) {
        extractPassword = "";
        showExtractPasswordDialog = true;
      } else {
        await fileStore.startArchiveRestore(file.path);
      }
    } catch {}
  }

  async function confirmExtractWithPassword() {
    if (!extractPassword.trim() || !selectedGridFile) return;
    isExtracting = true;
    showExtractPasswordDialog = false;
    try {
      await fileStore.startArchiveRestore(
        selectedGridFile.path,
        extractPassword.trim(),
      );
    } catch {
    } finally {
      isExtracting = false;
      extractPassword = "";
    }
  }

  function formatSize(bytes?: number) {
    if (bytes === undefined) return "";
    if (bytes === 0) return "0 B";
    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + " " + sizes[i];
  }
</script>

<div class="explorer-container">
  {#if fileStore.fmGateVisible}
    <FmPasswordGate
      loading={fileStore.fmGateLoading}
      error={fileStore.fmGateError}
      onSubmit={(password) => fileStore.verifyFmPassword(password)}
    />
  {/if}

  <Toolbar />
  <Statusbar />
  <Breadcrumbs />

  {#if fileStore.currentOperation}
    <div class="operation-indicator">
      <div class="spinner-small"></div>
      <span>{fileStore.currentOperation}</span>
    </div>
  {/if}

  {#if fileStore.error}
    <div class="error-banner">
      ⚠️ {fileStore.error}
      <button onclick={() => (fileStore.error = null)}>✖</button>
    </div>
  {/if}

  <div class="explorer-layout">
    <div class="explorer-main">
      <div class="bulk-bar" class:bulk-bar-active={fileStore.hasSelection}>
        {#if fileStore.hasSelection}
          <span class="bulk-count">{fileStore.selectionCount} selected</span>
          <div class="bulk-actions">
            <button
              class="bulk-btn"
              onclick={() => {
                const paths = [...fileStore.selectedPaths];
                fileStore.copyToClipboard(paths);
                const label = paths.length === 1
                  ? `"${paths[0].split("/").pop()}" copied`
                  : `${paths.length} items copied`;
                toast.info(label);
              }}>Copy</button
            >
            <button
              class="bulk-btn"
              onclick={() => {
                const paths = [...fileStore.selectedPaths];
                fileStore.cutToClipboard(paths);
                const label = paths.length === 1
                  ? `"${paths[0].split("/").pop()}" cut`
                  : `${paths.length} items cut`;
                toast.info(label);
              }}>Cut</button
            >
            {#if fileStore.selectionCount === 1}
              <button
                class="bulk-btn"
                onclick={() => fileStore.duplicate(fileStore.selectedPaths[0])}
                >Duplicate</button
              >
              <button
                class="bulk-btn"
                onclick={() =>
                  fileStore.startRename(fileStore.selectedPaths[0])}
                >Rename</button
              >
            {/if}
            <button
              class="bulk-btn bulk-btn-danger"
              onclick={initBulkDelete}
              disabled={isBulkDeleting}>Delete</button
            >
            <button
              class="bulk-btn bulk-btn-ghost"
              onclick={() => fileStore.clearSelection()}>Deselect all</button
            >
          </div>
        {/if}

        <!-- svelte-ignore a11y_no_static_element_interactions -->
        <div class="search-group">
          <input
            type="text"
            bind:value={searchQuery}
            placeholder="Search..."
            class="search-input"
            onclick={(e) => e.stopPropagation()}
          />
          {#if searchQuery}
            <span class="search-count"
              >{displayFiles.length} result{displayFiles.length !== 1
                ? "s"
                : ""}</span
            >
            <button class="search-clear" onclick={() => (searchQuery = "")}
              >✕</button
            >
          {/if}
        </div>
      </div>

      <!-- Horizontal scroll wrapper keeps column header + body aligned -->
      <div class="explorer-scroll">
      {#if fileStore.viewMode === "list"}
        <div class="explorer-header">
          <div class="col-check"></div>
          <button
            class="col col-name sort-header"
            onclick={() => toggleSort("name")}
          >
            Name {sortKey === "name" ? (sortAsc ? "▲" : "▼") : ""}
          </button>
          <button
            class="col col-size sort-header"
            onclick={() => toggleSort("size")}
          >
            Size {sortKey === "size" ? (sortAsc ? "▲" : "▼") : ""}
          </button>
          <button
            class="col col-type sort-header"
            onclick={() => toggleSort("type")}
          >
            Type {sortKey === "type" ? (sortAsc ? "▲" : "▼") : ""}
          </button>
          <button
            class="col col-modified sort-header"
            onclick={() => toggleSort("modified")}
          >
            Last Modified {sortKey === "modified" ? (sortAsc ? "▲" : "▼") : ""}
          </button>
          <div class="col col-permissions sort-header">Permissions</div>
        </div>
      {/if}

      <!-- svelte-ignore a11y_no_static_element_interactions a11y_click_events_have_key_events -->
      <!-- svelte-ignore a11y_click_events_have_key_events -->
      <div
        class="explorer-body"
        class:grid-view={fileStore.viewMode === "grid"}
        onclick={handleBodyClick}
        oncontextmenu={handleBodyContext}
      >
        {#if fileStore.isLoading && fileStore.currentFiles.length === 0}
          <div class="loading-overlay">
            <div class="spinner"></div>
            <p>Loading files...</p>
          </div>
        {:else if displayFiles.length === 0}
          <div class="empty-state">
            {#if searchQuery}
              <p>No files match "{searchQuery}".</p>
            {:else}
              <p>This folder is empty.</p>
            {/if}
          </div>
        {:else}
          {#each displayFiles as file (file.path)}
            {#if fileStore.viewMode === "list"}
              <FileRow {file} />
            {:else}
              <GridItem {file} onfilecontextmenu={handleGridContextMenu} />
            {/if}
          {/each}
        {/if}
      </div>
      </div>
      <!-- .explorer-scroll -->

      <Pagination path={fileStore.currentPath} />
    </div>
    <!-- .explorer-main -->

    <PreviewPanel />
  </div>
  <!-- .explorer-layout -->
</div>
<!-- .explorer-container -->

{#if showBgMenu}
  <ContextMenu
    items={getBgMenuItems()}
    x={bgMenuX}
    y={bgMenuY}
    onclose={() => (showBgMenu = false)}
  />
{/if}

{#if showGridContextMenu && selectedGridFile}
  {@const items = getFileContextMenuItems(selectedGridFile)}
  <ContextMenu
    {items}
    x={gridContextMenuX}
    y={gridContextMenuY}
    onclose={() => {
      showGridContextMenu = false;
    }}
  />
{/if}

{#if showArchiveDialog && selectedGridFile}
  <div
    class="modal-overlay"
    onclick={() => (showArchiveDialog = false)}
    onkeydown={(e) => e?.key === "Escape" && (showArchiveDialog = false)}
    role="button"
    tabindex="-1"
    aria-label="Close dialog"
  >
    <div
      class="modal-content"
      onclick={(e) => e.stopPropagation()}
      onkeydown={(e) => e.stopPropagation()}
      role="button"
      tabindex="0"
    >
      <h3>Create Archive</h3>
      <p>Choose archive format for <strong>{selectedGridFile.name}</strong></p>

      {#if prescanInfo}
        <div class="prescan-stats">
          <small
            >{prescanInfo.total.toLocaleString()} files &middot; {formatSize(
              prescanInfo.total_size,
            )} total &middot; largest file: {formatSize(
              prescanInfo.max_file_size,
            )}</small
          >
        </div>
      {/if}

      {#if prescanInfo && prescanInfo.max_file_size > ZIP_MAX_FILE_SIZE}
        <div class="zip-warn">
          <small
            ><strong>ZIP not available.</strong> This folder contains a file
            larger than 100 MB (<em>{prescanInfo.max_file_name}</em> — {formatSize(
              prescanInfo.max_file_size,
            )}). Use TAR or Anibas Archive instead.</small
          >
        </div>
      {/if}

      <div class="format-options">
        <label
          class="format-option"
          class:disabled-option={prescanInfo !== null &&
            prescanInfo.max_file_size > ZIP_MAX_FILE_SIZE}
        >
          <input
            type="radio"
            bind:group={archiveFormat}
            value="zip"
            disabled={prescanInfo !== null &&
              prescanInfo.max_file_size > ZIP_MAX_FILE_SIZE}
          />
          <span><strong>ZIP</strong> — Standard format, works everywhere</span>
        </label>
        <label class="format-option">
          <input type="radio" bind:group={archiveFormat} value="tar" />
          <span
            ><strong>TAR</strong> — Universal format, supports large files (macOS,
            Linux, Windows)</span
          >
        </label>
        <label class="format-option">
          <input type="radio" bind:group={archiveFormat} value="anfm" />
          <span
            ><strong>Anibas Archive (.anfm)</strong> — Encrypted, supports large
            files<br /><small class="format-plugin-warn"
              >Only extractable with the Anibas File Manager plugin</small
            ></span
          >
        </label>
      </div>

      {#if archiveFormat === "anfm"}
        <div class="anfm-note">
          <small
            >You can optionally set a password for additional security.</small
          >
        </div>
        <input
          type="password"
          bind:value={archivePassword}
          placeholder="Password (optional)"
        />
      {/if}

      {#if archiveConflict}
        <div class="conflict-section">
          <p class="conflict-title">
            ⚠ <strong>{archiveConflict.output}</strong> already exists ({formatSize(
              archiveConflict.output_size,
            )})
          </p>
          <div class="format-options">
            <label class="format-option">
              <input
                type="radio"
                bind:group={archiveConflictMode}
                value="rename"
              />
              <span
                >Create with a new name (e.g. <em
                  >{archiveConflict.output.replace(/(\.\w+)$/, " (1)$1")}</em
                >)</span
              >
            </label>
            <label class="format-option">
              <input
                type="radio"
                bind:group={archiveConflictMode}
                value="overwrite"
              />
              <span>Overwrite existing file</span>
            </label>
          </div>
        </div>
      {/if}

      <div class="modal-actions">
        <button
          class="btn btn-secondary"
          onclick={() => {
            showArchiveDialog = false;
            archiveConflict = null;
          }}
          disabled={isCreatingArchive}>Cancel</button
        >
        {#if archiveConflict}
          <button
            class="btn btn-primary"
            onclick={confirmCompressConflict}
            disabled={isCreatingArchive}
          >
            {isCreatingArchive ? "Creating..." : "Continue"}
          </button>
        {:else}
          <button
            class="btn btn-primary"
            onclick={() => confirmCompress()}
            disabled={isCreatingArchive}
          >
            {isCreatingArchive ? "Creating..." : "Create Archive"}
          </button>
        {/if}
      </div>
    </div>
  </div>
{/if}

{#if showZipFallbackDialog && selectedGridFile}
  <div
    class="modal-overlay"
    onclick={() => (showZipFallbackDialog = false)}
    onkeydown={(e) => e?.key === "Escape" && (showZipFallbackDialog = false)}
    role="button"
    tabindex="-1"
    aria-label="Close dialog"
  >
    <div
      class="modal-content"
      onclick={(e) => e.stopPropagation()}
      onkeydown={(e) => e.stopPropagation()}
      role="button"
      tabindex="0"
    >
      <h3>ZIP Connection Timeout</h3>
      <p>
        The ZIP archive timed out and the partial file has been removed. Choose
        an alternative format for <strong>{selectedGridFile.name}</strong>:
      </p>

      <div class="format-options">
        <label class="format-option">
          <input type="radio" bind:group={archiveFormat} value="tar" />
          <span
            ><strong>TAR</strong> — Universal format, supports large files (macOS,
            Linux, Windows)</span
          >
        </label>
        <label class="format-option">
          <input type="radio" bind:group={archiveFormat} value="anfm" />
          <span
            ><strong>Anibas Archive (.anfm)</strong> — Encrypted, supports large
            files<br /><small class="format-plugin-warn"
              >Only extractable with the Anibas File Manager plugin</small
            ></span
          >
        </label>
      </div>

      {#if archiveFormat === "anfm"}
        <div class="anfm-note">
          <small>You can optionally set a password.</small>
        </div>
        <input
          type="password"
          bind:value={archivePassword}
          placeholder="Password (optional)"
        />
      {/if}

      <div class="modal-actions">
        <button
          class="btn btn-secondary"
          onclick={() => (showZipFallbackDialog = false)}
          disabled={isCreatingArchive}>Cancel</button
        >
        <button
          class="btn btn-primary"
          onclick={confirmZipFallback}
          disabled={isCreatingArchive}
        >
          {isCreatingArchive ? "Creating..." : "Create Archive"}
        </button>
      </div>
    </div>
  </div>
{/if}

{#if showExtractPasswordDialog && selectedGridFile}
  <div
    class="modal-overlay"
    onclick={() => (showExtractPasswordDialog = false)}
    onkeydown={(e) =>
      e?.key === "Escape" && (showExtractPasswordDialog = false)}
    role="button"
    tabindex="-1"
    aria-label="Close dialog"
  >
    <div
      class="modal-content"
      onclick={(e) => e.stopPropagation()}
      onkeydown={(e) => e.stopPropagation()}
      role="button"
      tabindex="0"
    >
      <h3>Password Required</h3>
      <p>This archive is password-protected.</p>
      <input
        type="password"
        bind:value={extractPassword}
        placeholder="Enter archive password"
        onkeydown={(e) => e.key === "Enter" && confirmExtractWithPassword()}
      />
      <div class="modal-actions">
        <button
          class="btn btn-secondary"
          onclick={() => (showExtractPasswordDialog = false)}>Cancel</button
        >
        <button
          class="btn btn-primary"
          onclick={confirmExtractWithPassword}
          disabled={isExtracting || !extractPassword.trim()}
        >
          {isExtracting ? "Extracting..." : "Extract"}
        </button>
      </div>
    </div>
  </div>
{/if}

{#if showDeleteDialog && selectedGridFile}
  <div
    class="modal-overlay"
    onclick={() => {
      showDeleteDialog = false;
      deleteToken = null;
    }}
    onkeydown={(e) => e?.key === "Escape" && (showDeleteDialog = false)}
    role="button"
    tabindex="-1"
    aria-label="Close dialog"
  >
    <div
      class="modal-content"
      onclick={(e) => e.stopPropagation()}
      onkeydown={(e) => e.stopPropagation()}
      role="button"
      tabindex="0"
    >
      <h3>Confirm Delete</h3>
      <p>
        Are you sure you want to delete this {selectedGridFile.is_folder
          ? "folder"
          : "file"}?
      </p>
      <p class="delete-name">{selectedGridFile.name}</p>
      <div class="modal-actions">
        <button
          class="btn btn-secondary"
          onclick={() => {
            showDeleteDialog = false;
            deleteToken = null;
          }}>Cancel</button
        >
        <button class="btn btn-danger" onclick={confirmGridDelete}
          >Delete</button
        >
      </div>
    </div>
  </div>
{/if}

{#if showPasswordDialog && selectedGridFile}
  <div
    class="modal-overlay"
    onclick={() => (showPasswordDialog = false)}
    onkeydown={(e) => e?.key === "Escape" && (showPasswordDialog = false)}
    role="button"
    tabindex="-1"
    aria-label="Close dialog"
  >
    <div
      class="modal-content"
      onclick={(e) => e.stopPropagation()}
      onkeydown={(e) => e.stopPropagation()}
      role="button"
      tabindex="0"
    >
      <h3>Delete Password Required</h3>
      <input
        type="password"
        bind:value={deletePassword}
        placeholder="Enter delete password"
        onkeydown={(e) => e.key === "Enter" && handleGridPasswordSubmit()}
      />
      <div class="modal-actions">
        <button
          class="btn btn-secondary"
          onclick={() => (showPasswordDialog = false)}>Cancel</button
        >
        <button
          class="btn btn-danger"
          onclick={handleGridPasswordSubmit}
          disabled={isDeletingFile || !deletePassword.trim()}
        >
          {isDeletingFile ? "Deleting..." : "Delete"}
        </button>
      </div>
    </div>
  </div>
{/if}

{#if showBulkDeleteConfirm}
  <div
    class="modal-overlay"
    onclick={() => {
      showBulkDeleteConfirm = false;
      bulkDeletePending = [];
    }}
    onkeydown={(e) => e.key === "Escape" && (showBulkDeleteConfirm = false)}
    role="button"
    tabindex="-1"
    aria-label="Close dialog"
  >
    <div
      class="modal-content"
      onclick={(e) => e.stopPropagation()}
      onkeydown={(e) => e.stopPropagation()}
      role="button"
      tabindex="0"
    >
      <h3>Confirm Delete</h3>
      <p>
        Delete <strong>{bulkDeletePending.length}</strong>
        item{bulkDeletePending.length !== 1 ? "s" : ""}? This cannot be undone.
      </p>
      <div class="modal-actions">
        <button
          class="btn btn-secondary"
          onclick={() => {
            showBulkDeleteConfirm = false;
            bulkDeletePending = [];
          }}>Cancel</button
        >
        <button class="btn btn-danger" onclick={confirmBulkDelete}
          >Delete</button
        >
      </div>
    </div>
  </div>
{/if}

{#if showEmptyFolderDialog && emptyFolderTarget}
  <div
    class="modal-overlay"
    onclick={() => (showEmptyFolderDialog = false)}
    onkeydown={(e) => e?.key === "Escape" && (showEmptyFolderDialog = false)}
    role="button"
    tabindex="-1"
    aria-label="Close dialog"
  >
    <div
      class="modal-content"
      onclick={(e) => e.stopPropagation()}
      onkeydown={(e) => e.stopPropagation()}
      role="button"
      tabindex="0"
    >
      <h3>Empty Folder</h3>
      <p>
        Delete all contents of <strong>"{emptyFolderTarget.name}"</strong>? The folder itself will be kept.
      </p>
      <div class="modal-actions">
        <button
          class="btn btn-secondary"
          onclick={() => (showEmptyFolderDialog = false)}>Cancel</button
        >
        <button class="btn btn-danger" onclick={confirmEmptyFolder}
          >Empty</button
        >
      </div>
    </div>
  </div>
{/if}

{#if showEmptyFolderPasswordDialog && emptyFolderTarget}
  <div
    class="modal-overlay"
    onclick={() => { showEmptyFolderPasswordDialog = false; emptyFolderTarget = null; }}
    onkeydown={(e) => e?.key === "Escape" && (showEmptyFolderPasswordDialog = false)}
    role="button"
    tabindex="-1"
    aria-label="Close dialog"
  >
    <div
      class="modal-content"
      onclick={(e) => e.stopPropagation()}
      onkeydown={(e) => e.stopPropagation()}
      role="button"
      tabindex="0"
    >
      <h3>Delete Password Required</h3>
      <p>Enter the delete password to empty <strong>"{emptyFolderTarget.name}"</strong>.</p>
      <input
        type="password"
        bind:value={emptyFolderPassword}
        placeholder="Delete password"
        class="modal-input"
        onkeydown={(e) => e?.key === "Enter" && handleEmptyFolderPasswordSubmit()}
      />
      <div class="modal-actions">
        <button
          class="btn btn-secondary"
          onclick={() => { showEmptyFolderPasswordDialog = false; emptyFolderTarget = null; emptyFolderPassword = ""; }}
          >Cancel</button
        >
        <button
          class="btn btn-danger"
          onclick={handleEmptyFolderPasswordSubmit}
          disabled={isEmptyingFolder}
          >{isEmptyingFolder ? "Verifying..." : "Confirm"}</button
        >
      </div>
    </div>
  </div>
{/if}

{#if showGridDetails && selectedGridFile}
  <DetailsModal file={selectedGridFile} onclose={() => (showGridDetails = false)} />
{/if}

{#if showGridSendTo && selectedGridFile}
  <SendToModal file={selectedGridFile!} onclose={() => (showGridSendTo = false)} />
{/if}

<style>
  .explorer-container {
    position: relative;
    display: flex;
    flex-direction: column;
    height: 100%;
  }

  .explorer-layout {
    display: flex;
    flex: 1;
    min-height: 0; /* Important for scrollable flex children */
  }

  .explorer-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0; /* Allow text truncation */
  }

  .explorer-header {
    display: flex;
    padding: 8px 15px;
    background: #fdfdfd;
    border-bottom: 2px solid #f0f0f0;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: #666;
    letter-spacing: 0.05em;
  }

  .col {
    padding: 0 10px;
  }
  .col-check {
    width: 2em;
    flex-shrink: 0;
    padding: 0 4px 0 4px;
  }
  .col-name {
    flex: 4;
  }
  .col-size {
    flex: 1;
    text-align: right;
  }
  .col-type {
    flex: 1.5;
  }
  .col-modified {
    flex: 2;
  }
  .col-permissions {
    flex: 1.5;
    font-size: 11px;
  }

  .sort-header {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: #555;
    letter-spacing: 0.05em;
    text-align: left;
    padding: 0 10px;
    display: flex;
    align-items: center;
    gap: 4px;
  }
  .sort-header:hover {
    color: #2271b1;
  }

  /* Search group (inside bulk-bar, right-aligned) */
  .search-group {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-left: auto;
  }
  .search-input {
    width: 180px;
    padding: 5px 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 13px;
    outline: none;
  }
  .search-input:focus {
    border-color: #2271b1;
    box-shadow: 0 0 0 1px #2271b1;
  }
  .search-count {
    font-size: 12px;
    color: #888;
    white-space: nowrap;
  }
  .search-clear {
    background: none;
    border: none;
    color: #888;
    cursor: pointer;
    font-size: 14px;
    padding: 2px 4px;
    line-height: 1;
  }
  .search-clear:hover {
    color: #333;
  }

  /* Bulk action bar */
  .bulk-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-bottom: 1px solid #e0e0e0;
    background: #f9f9f9;
    font-size: 13px;
    min-height: 2.5em;
  }
  .bulk-bar-active {
    background: #e8f0fe;
    border-bottom-color: #c5d4f8;
  }
  .bulk-count {
    font-weight: 600;
    color: #1a56db;
    margin-right: 4px;
  }
  .bulk-actions {
    display: flex;
    gap: 6px;
  }
  .bulk-btn {
    padding: 4px 10px;
    border: 1px solid #8c8f94;
    border-radius: 3px;
    background: #fff;
    color: #333;
    font-size: 12px;
    cursor: pointer;
  }
  .bulk-btn:hover {
    background: #f0f0f1;
  }
  .bulk-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
  .bulk-btn-danger {
    border-color: #d63638;
    color: #d63638;
  }
  .bulk-btn-danger:hover {
    background: #fce8e8;
  }
  .bulk-btn-ghost {
    border-color: transparent;
    color: #555;
  }
  .bulk-btn-ghost:hover {
    background: #dce8fd;
  }

  @media (max-width: 782px) {
    .bulk-bar {
      flex-wrap: wrap;
      gap: 6px;
    }
    .search-group {
      order: -1;
      width: 100%;
      flex-shrink: 0;
    }
    .bulk-actions {
      flex-wrap: wrap;
      gap: 4px;
    }
    .bulk-btn {
      font-size: 11px;
      padding: 3px 8px;
    }
  }

  .explorer-scroll {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow-x: auto;
    overflow-y: hidden;
    min-height: 0;
    min-width: 0;
  }

  .explorer-body {
    flex: 1;
    overflow-y: auto;
    overflow-x: visible; /* horizontal scroll handled by .explorer-scroll */
    position: relative;
  }

  .explorer-body.grid-view {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 8px;
    padding: 16px;
    align-content: start;
  }

  .empty-state {
    padding: 40px;
    text-align: center;
    color: #999;
  }

  .loading-overlay {
    padding: 40px;
    text-align: center;
    color: #666;
  }

  .spinner {
    width: 30px;
    height: 30px;
    border: 3px solid #eee;
    border-top: 3px solid #2271b1;
    border-radius: 50%;
    margin: 0 auto 10px;
    animation: spin 1s linear infinite;
  }

  @keyframes spin {
    0% {
      transform: rotate(0deg);
    }
    100% {
      transform: rotate(360deg);
    }
  }

  .error-banner {
    background: #fee;
    border: 1px solid #fcc;
    color: #c33;
    padding: 10px 15px;
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 13px;
  }

  .error-banner button {
    background: none;
    border: none;
    color: #c33;
    cursor: pointer;
    font-size: 16px;
    padding: 0 5px;
    opacity: 0.7;
  }

  .error-banner button:hover {
    opacity: 1;
  }

  .operation-indicator {
    background: #e7f3ff;
    border: 1px solid #b3d9ff;
    color: #0060df;
    padding: 8px 15px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    font-weight: 500;
  }

  .spinner-small {
    width: 16px;
    height: 16px;
    border: 2px solid #b3d9ff;
    border-top: 2px solid #0060df;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
  }

  .modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100000;
  }

  .modal-content {
    background: white;
    padding: 24px;
    border-radius: 4px;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
  }

  .modal-content h3 {
    margin: 0 0 16px 0;
    font-size: 16px;
    font-weight: 600;
  }

  .modal-content input:not([type="radio"]):not([type="checkbox"]) {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    font-size: 13px;
    margin-bottom: 16px;
    box-sizing: border-box;
  }

  .modal-content input:not([type="radio"]):not([type="checkbox"]):focus {
    outline: none;
    border-color: #2271b1;
  }

  .modal-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
  }

  .btn {
    padding: 6px 12px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
  }

  .btn-secondary {
    background: #f0f0f0;
    color: #333;
  }

  .btn-secondary:hover {
    background: #e0e0e0;
  }

  .btn-primary {
    background: #2271b1;
    color: white;
  }

  .btn-primary:hover {
    background: #135e96;
  }

  .btn-primary:disabled {
    background: #ccc;
    cursor: not-allowed;
  }

  .btn-danger {
    background: #dc3545;
    color: white;
  }

  .btn-danger:hover {
    background: #c82333;
  }

  .btn-danger:disabled {
    background: #ccc;
    cursor: not-allowed;
  }

  .delete-name {
    font-weight: 600;
    color: #333;
    word-break: break-all;
  }

  .format-options {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 12px 0;
  }

  .format-option {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    cursor: pointer;
    font-size: 13px;
    line-height: 1.4;
    width: fit-content;
  }

  .format-option input[type="radio"] {
    margin-top: 3px;
  }

  .anfm-note {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 3px;
    padding: 8px 10px;
    margin-bottom: 12px;
    color: #664d03;
  }

  .anfm-note small {
    font-size: 12px;
    line-height: 1.4;
  }

  .format-plugin-warn {
    display: block;
    margin-top: 2px;
    color: #805700;
    font-size: 11px;
  }

  .prescan-stats {
    background: #f0f4f8;
    border: 1px solid #d0d7de;
    border-radius: 3px;
    padding: 6px 10px;
    margin-bottom: 12px;
    color: #444;
  }

  .prescan-stats small {
    font-size: 12px;
  }

  .conflict-section {
    background: #fff8e1;
    border: 1px solid #f5c518;
    border-radius: 3px;
    padding: 10px 12px;
    margin-bottom: 12px;
  }

  .conflict-title {
    margin: 0 0 8px;
    font-size: 12px;
    color: #5a3e00;
  }

  .zip-warn {
    background: #fde8e8;
    border: 1px solid #f5c2c2;
    border-radius: 3px;
    padding: 8px 10px;
    margin-bottom: 12px;
    color: #922;
  }

  .zip-warn small {
    font-size: 12px;
    line-height: 1.4;
  }

  .disabled-option {
    opacity: 0.45;
    cursor: not-allowed;
  }

  .disabled-option input[type="radio"] {
    cursor: not-allowed;
  }
</style>
