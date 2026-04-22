<script lang="ts">
	import { __ } from "../../utils/i18n";
	import { fileStore } from "../../stores/fileStore.svelte";
	import StorageSelector from "./StorageSelector.svelte";
	import TrashBin from "./TrashBin.svelte";
	import { ChunkUploader } from "../../utils/ChunkUploader";
	import { toast } from "../../utils/toast";
	import { getFmToken, getJobStatus, checkFmTokenError } from "../../services/fileApi";
	import { isEditable } from "../../utils/editable";

	// Derive the single selected file (if exactly 1 non-folder selected)
	const selectedFile = $derived.by(() => {
		if (fileStore.selectionCount !== 1) return null;
		const path = fileStore.selectedPaths[0];
		return fileStore.currentFiles.find(f => f.path === path) ?? null;
	});

	const canEdit = $derived(!!selectedFile && !selectedFile.is_folder && isEditable(selectedFile));
	const canPreview = $derived(!!selectedFile && !selectedFile.is_folder);

	let showCreateDialog = $state(false);
	let showTrashDialog = $state(false);
	let showCreateFileDialog = $state(false);
	let showUploadDialog = $state(false);
	let showConflictDialog = $state(false);
	let folderName = $state("");
	let fileName = $state("");
	let fileContent = $state("");
	let isCreating = $state(false);
	let isPasting = $state(false);
	let isUploading = $state(false);
	let conflictMode = $state("skip");
	let isCheckingConflict = $state(false);
	let selectedFiles = $state<FileList | null>(null);
	let uploadProgress = $state(0);
	let currentUploadFile = $state("");
	let uploadPhase = $state<"uploading" | "assembling">("uploading");

	let showSizeMismatchDialog = $state(false);
	let sizeMismatchJob = $state<any>(null);

	let showBulkDeleteDialog = $state(false);
	let isBulkDeleting = $state(false);
	let bulkDeletePassword = $state("");


	async function confirmBulkDelete() {
		if (fileStore.selectedPaths.length === 0) return;
		isBulkDeleting = true;
		try {
			await fileStore.bulkDelete([...fileStore.selectedPaths], bulkDeletePassword || undefined);
			showBulkDeleteDialog = false;
			bulkDeletePassword = "";
		} catch (err: any) {
			if (err.message === "DeletePasswordRequired") {
				toast.error(__("Password required for some or all items."));
			} else {
				toast.error(err.message || __("Failed to delete some items"));
			}
		} finally {
			isBulkDeleting = false;
		}
	}

	async function handlePasteClick() {
		isCheckingConflict = true;
		await fileStore.requestPaste(fileStore.currentPath);
		isCheckingConflict = false;
	}

	async function handleCreateFolder() {
		if (!folderName.trim()) return;

		isCreating = true;
		try {
			await fileStore.createFolder(folderName.trim());
			folderName = "";
			showCreateDialog = false;
		} catch {
			// fileStore.createFolder already toasts the error
		} finally {
			isCreating = false;
		}
	}

	async function handleCreateFile() {
		if (!fileName.trim()) return;

		isCreating = true;
		try {
			const config = (window as any).AnibasFM;
			const formData = new FormData();
			formData.append("action", "anibas_fm_create_file");
			formData.append("nonce", config.createNonce);
			formData.append("parent", fileStore.currentPath);
			formData.append("name", fileName.trim());
			formData.append("content", fileContent);
			formData.append("storage", fileStore.currentStorage);
			const fmTok = getFmToken();
			if (fmTok) formData.append("fm_token", fmTok);

			const response = await fetch(config.ajaxURL, {
				method: "POST",
				body: formData,
			});
			const result = await response.json();

			checkFmTokenError(result);
			if (!result.success) {
				throw new Error(result.data?.message || result.data?.error || __("Failed to create file"));
			}

			await fileStore.loadDirectory(fileStore.currentPath);
			toast.success(`File "${fileName.trim()}" created successfully`);
			fileName = "";
			fileContent = "";
			showCreateFileDialog = false;
		} catch (err) {
			const errorMsg =
				err instanceof Error ? err.message : __("Failed to create file");
			
			// Check if this is a retry timeout - suggest page refresh
			if (errorMsg && errorMsg.includes('Please refresh the page and try again')) {
				toast.error(__("File creation timed out. Please refresh the page and try again."));
			} else {
				toast.error(errorMsg);
			}
		} finally {
			isCreating = false;
		}
	}

	async function handlePaste(mode?: string) {
		isPasting = true;
		try {
			const resolvedMode = mode || conflictMode;
			await fileStore.paste(fileStore.pendingPasteDestination || fileStore.currentPath, resolvedMode);
			fileStore.showPasteDialog = false;
			fileStore.pendingPasteDestination = null;
			conflictMode = "skip";
		} catch {
			// fileStore.paste already toasts the error
		} finally {
			isPasting = false;
		}
	}

	async function handleUpload() {
		if (!selectedFiles || selectedFiles.length === 0) return;

		// Ensure files are loaded
		if (!fileStore.currentFiles || fileStore.currentFiles.length === 0) {
			await fileStore.loadDirectory(fileStore.currentPath);
		}

		// Check for conflicts
		const existingFiles = fileStore.currentFiles || [];
		const conflicts: string[] = [];
		for (const file of selectedFiles) {
			const exists = existingFiles.some((f) => f.name === file.name);
			if (exists) conflicts.push(file.name);
		}

		// Show conflict dialog if there are conflicts
		if (conflicts.length > 0) {
			showConflictDialog = true;
			return;
		}

		// No conflicts, proceed with upload
		await performUpload("skip");
	}

	async function handleConflictResolution(mode: string) {
		showConflictDialog = false;
		await performUpload(mode);
	}

	async function performUpload(mode: string) {
		if (!selectedFiles || selectedFiles.length === 0) return;

		isUploading = true;
		const config = (window as any).AnibasFM;
		const existingFiles = fileStore.currentFiles || [];

		try {
			for (let i = 0; i < selectedFiles.length; i++) {
				let file = selectedFiles[i];

				// Check if file exists and handle conflict
				const exists = existingFiles.some((f) => f.name === file.name);
				if (exists) {
					if (mode === "skip") continue;
					if (mode === "overwrite") {
						// Delete existing file first
						await fileStore.deleteFile(
							fileStore.currentPath + "/" + file.name,
							fileStore.deleteToken || "",
						);
					}
					// For rename mode, create new File with different name
					if (mode === "rename") {
						const newName = generateUniqueName(file.name,existingFiles.map((f) => f.name));
						file = new File([file], newName, { type: file.type });
					}
				}

				currentUploadFile = file.name;
				uploadProgress = 0;
				uploadPhase = "uploading";

				await new Promise<void>((resolve, reject) => {
					const uploader = new ChunkUploader({
						url: config.ajaxURL,
						file: file,
						headers: {},
						params: {
							action: "anibas_fm_upload_chunk",
							nonce: config.createNonce,
							destination: fileStore.currentPath,
							storage: fileStore.currentStorage,
							fm_token: getFmToken(),
						},
						onProgress: (percent) => {
							uploadProgress = Math.round(percent / 2); // 0-50%
						},
						onComplete: async (response) => {
							if (response?.job_id) {
								uploadPhase = "assembling";
								uploadProgress = 50;
								try {
									await pollAssemblyProgress(response.job_id);
									resolve();
								} catch (error) {
									reject(error);
								}
							} else {
								// No assembly needed, file uploaded directly
								uploadProgress = 100;
								resolve();
							}
						},
						onError: (error) => reject(new Error(error)),
					});

					uploader.start();
				});
			}

			await fileStore.loadDirectory(fileStore.currentPath);
			showUploadDialog = false;
			selectedFiles = null;
			currentUploadFile = "";
			uploadProgress = 0;
			uploadPhase = "uploading";
			toast.success(__("Upload completed successfully"));
		} catch (err) {
			toast.error(
				err instanceof Error ? err.message : __("Failed to upload"),
			);
		} finally {
			isUploading = false;
		}
	}

	function generateUniqueName(name: string, existingNames: string[]): string {
		const lastDot = name.lastIndexOf(".");
		const baseName = lastDot > 0 ? name.substring(0, lastDot) : name;
		const ext = lastDot > 0 ? name.substring(lastDot) : "";

		let counter = 1;
		let newName = name;
		while (existingNames.includes(newName)) {
			newName = `${baseName} (${counter})${ext}`;
			counter++;
		}
		return newName;
	}

	async function pollAssemblyProgress(jobId: string) {
		while (true) {
			await new Promise((resolve) => setTimeout(resolve, 1000));

			// getJobStatus includes fm_token and calls checkFmTokenError on failure
			const job = await getJobStatus(jobId);
			const assemblyPercent =
				job.total_chunks > 0
					? Math.round((job.current_chunk / job.total_chunks) * 50)
					: 0;

			uploadProgress = 50 + assemblyPercent;

			if (job.status === "completed") {
				uploadProgress = 100;
				break;
			}

			if (job.status === "failed") {
				if (job.error_code === "FileSizeMismatch") {
					sizeMismatchJob = job;
					showSizeMismatchDialog = true;
					break;
				}
				if (job.error_code === "ChunkUploadFailed") {
					throw new Error(
						__("Upload failed after 3 attempts. The file has been removed. Please check your connection and try again."),
					);
				}
				throw new Error(job.errors?.[0] || __("Assembly failed"));
			}

		}
	}

	async function handleSizeMismatch(action: "keep" | "delete") {
		if (!sizeMismatchJob) return;

		const config = (window as any).AnibasFM;
		const formData = new FormData();
		formData.append("action", "anibas_fm_resolve_size_mismatch");
		formData.append("nonce", config.listNonce);
		formData.append("job_id", sizeMismatchJob.id);
		formData.append("action_type", action);
		const token = getFmToken();
		if (token) formData.append("fm_token", token);

		try {
			const response = await fetch(config.ajaxURL, {
				method: "POST",
				body: formData,
			});
			const result = await response.json();

			if (!result.success) {
				checkFmTokenError(result);
				throw new Error(result.data?.message || result.data?.error || __("Failed to resolve"));
			}

			showSizeMismatchDialog = false;
			sizeMismatchJob = null;

			if (action === "keep") {
				await fileStore.loadDirectory(fileStore.currentPath);
				toast.info(__("File kept despite size mismatch"));
			} else {
				await fileStore.loadDirectory(fileStore.currentPath);
				toast.success(__("Corrupted file deleted"));
			}
		} catch (err) {
			const errorMsg =
				err instanceof Error
					? err.message
					: __("Failed to resolve size mismatch");
			toast.error(errorMsg);
		}
	}

	function getJobsArray() {
		return Object.values(fileStore.activeJobs);
	}
</script>

<div class="toolbar">
	<StorageSelector
		currentStorage={fileStore.currentStorage}
		onSelect={(storage) => fileStore.changeStorage(storage)}
	/>

	<div class="divider"></div>

	<button
		class="btn btn-icon"
		onclick={() => (showUploadDialog = true)}
		data-tooltip="Upload"
		aria-label="Upload"
	>
		<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
			<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
			<polyline points="17 8 12 3 7 8" />
			<line x1="12" y1="3" x2="12" y2="15" />
		</svg>
	</button>



	<button
		class="btn btn-icon"
		onclick={() => (showCreateDialog = true)}
		data-tooltip={__("New Folder")}
		aria-label={__("New Folder")}
	>
		<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
			<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
			<line x1="12" y1="11" x2="12" y2="17" />
			<line x1="9" y1="14" x2="15" y2="14" />
		</svg>
	</button>

	<button
		class="btn btn-icon"
		onclick={() => (showCreateFileDialog = true)}
		data-tooltip={__("New File")}
		aria-label={__("New File")}
	>
		<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
			<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
			<polyline points="14 2 14 8 20 8" />
			<line x1="12" y1="18" x2="12" y2="12" />
			<line x1="9" y1="15" x2="15" y2="15" />
		</svg>
	</button>

	{#if canEdit || canPreview}
		<div class="divider"></div>
		{#if canEdit}
			<button
				class="btn btn-icon btn-icon-accent"
				onclick={() => selectedFile && fileStore.openEditor(selectedFile.path, true)}
				data-tooltip="Edit"
				aria-label="Edit"
			>
				<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
					<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
					<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
				</svg>
			</button>
		{/if}
		{#if canPreview}
			<button
				class="btn btn-icon"
				class:btn-icon-active={fileStore.previewOpen}
				onclick={() => { fileStore.previewOpen = !fileStore.previewOpen }}
				data-tooltip={fileStore.previewOpen ? 'Hide Preview' : 'Preview'}
				aria-label="Preview"
			>
				<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
					<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
					<circle cx="12" cy="12" r="3"/>
				</svg>
			</button>
		{/if}
	{/if}

	{#if fileStore.clipboard}
		<div class="divider"></div>
		<button
			class="btn btn-icon"
			onclick={handlePasteClick}
			disabled={isCheckingConflict || Object.keys(fileStore.activeJobs).length > 0}
			data-tooltip="Paste ({fileStore.clipboard.action === 'copy' ? 'Copy' : 'Move'})"
			aria-label="Paste"
		>
			<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
				<rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>
			</svg>
		</button>
		<button
			class="btn btn-icon btn-icon-ghost"
			onclick={() => fileStore.clearClipboard()}
			disabled={Object.keys(fileStore.activeJobs).length > 0}
			data-tooltip="Clear clipboard"
			aria-label="Clear clipboard"
		>
			<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
				<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
			</svg>
		</button>
	{/if}

	<div style="flex: 1;"></div>

	{#if fileStore.currentStorage === 'local'}
	<button
		class="btn btn-trash"
		onclick={() => (showTrashDialog = true)}
		aria-label={__("Trash")}
	>
		<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
			<polyline points="3 6 5 6 21 6"></polyline>
			<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
		</svg>
		{__("Trash")}
	</button>
	{/if}

	{#each getJobsArray() as job}
		<span class="job-indicator">
			⏳ {job.action === "copy" ? "Copying" : "Moving"}: {job.source} → {job.destination}
			{#if job.current_phase}
				<span class="job-phase">({job.current_phase})</span>
			{/if}
		</span>
		<button
			class="btn btn-icon btn-icon-danger"
			onclick={() => fileStore.cancelJob(job.id)}
			data-tooltip="Cancel"
			aria-label="Cancel"
		>
			<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
				<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
			</svg>
		</button>
	{/each}
</div>

{#if showCreateDialog}
	<div
		class="modal-overlay"
		onclick={() => (showCreateDialog = false)}
		onkeydown={(e) => e?.key === "Escape" && (showCreateDialog = false)}
		role="button"
		tabindex="-1"
		aria-label="Close dialog"
	>
		<div
			class="modal-content"
			onclick={(e) => e?.stopPropagation()}
			onkeydown={(e) => e?.stopPropagation()}
			role="button"
			tabindex="0"
		>
			<h3>{__("Create New Folder")}</h3>
			<input
				type="text"
				bind:value={folderName}
				placeholder={__("Folder name")}
				onkeydown={(e) => e?.key === "Enter" && handleCreateFolder()}
			/>
			<div class="modal-actions">
				<button
					class="btn btn-secondary"
					onclick={() => (showCreateDialog = false)}>{__("Cancel")}</button
				>
				<button
					class="btn btn-primary"
					onclick={handleCreateFolder}
					disabled={isCreating || !folderName.trim()}
				>
					{isCreating ? __("Creating...") : __("Create")}
				</button>
			</div>
		</div>
	</div>
{/if}

{#if showTrashDialog}
	<TrashBin
		onClose={() => (showTrashDialog = false)}
		onRestore={(path) => { showTrashDialog = false; fileStore.navigateTo(path); }}
	/>
{/if}

{#if showCreateFileDialog}
	<div
		class="modal-overlay"
		onclick={() => (showCreateFileDialog = false)}
		onkeydown={(e) => e?.key === "Escape" && (showCreateFileDialog = false)}
		role="button"
		tabindex="-1"
		aria-label="Close dialog"
	>
		<div
			class="modal-content modal-large"
			onclick={(e) => e?.stopPropagation()}
			onkeydown={(e) => e?.stopPropagation()}
			role="button"
			tabindex="0"
		>
			<h3>Create New File</h3>
			<input
				type="text"
				bind:value={fileName}
				placeholder="File name (e.g., index.html)"
				class="file-name-input"
			/>
			<textarea
				bind:value={fileContent}
				placeholder="File content (max 1 MB)"
				class="file-content-textarea"
				rows="15"
			></textarea>
			<div class="modal-actions">
				<button
					class="btn btn-secondary"
					onclick={() => (showCreateFileDialog = false)}
					>Cancel</button
				>
				<button
					class="btn btn-primary"
					onclick={handleCreateFile}
					disabled={isCreating || !fileName.trim()}
				>
					{isCreating ? "Creating..." : "Create"}
				</button>
			</div>
		</div>
	</div>
{/if}

{#if fileStore.showPasteDialog}
	<div
		class="modal-overlay"
		onclick={() => (fileStore.showPasteDialog = false)}
		onkeydown={(e) => e?.key === "Escape" && (fileStore.showPasteDialog = false)}
		role="button"
		tabindex="-1"
		aria-label="Close dialog"
	>
		<div
			class="modal-content"
			onclick={(e) => e?.stopPropagation()}
			onkeydown={(e) => e?.stopPropagation()}
			role="button"
			tabindex="0"
		>
			<h3>{__("File Conflict")}</h3>
			<p>{__("Some files already exist in the destination. How would you like to proceed?")}</p>
			<div class="radio-group">
				<label>
					<input
						type="radio"
						bind:group={conflictMode}
						value="skip"
					/>
					{__("Skip existing files")}
				</label>
				<label>
					<input
						type="radio"
						bind:group={conflictMode}
						value="overwrite"
					/>
					{__("Overwrite existing files")}
				</label>
				<label>
					<input
						type="radio"
						bind:group={conflictMode}
						value="rename"
					/>
					{__("Rename duplicates")}
				</label>
			</div>
			<div class="modal-actions">
				<button
					class="btn btn-secondary"
					onclick={() => { 
						fileStore.showPasteDialog = false; 
						fileStore.pendingPasteDestination = null; 
					}}>{__("Cancel")}</button
				>
				<button
					class="btn btn-primary"
					onclick={() => handlePaste(conflictMode)}
					disabled={isPasting}
				>
					{isPasting ? __("Pasting...") : __("Paste")}
				</button>
			</div>
		</div>
	</div>
{/if}

{#if showBulkDeleteDialog}
	<div
		class="modal-overlay"
		onclick={() => (showBulkDeleteDialog = false)}
		onkeydown={(e) => e?.key === "Escape" && (showBulkDeleteDialog = false)}
		role="button"
		tabindex="-1"
		aria-label="Close dialog"
	>
		<div
			class="modal-content"
			onclick={(e) => e?.stopPropagation()}
			onkeydown={(e) => e?.stopPropagation()}
			role="button"
			tabindex="0"
		>
			<h3>{__("Confirm Bulk Delete")}</h3>
			<p>{__("Are you sure you want to delete")} {fileStore.selectedPaths.length} {__("items?")}</p>
			<input
				type="password"
				bind:value={bulkDeletePassword}
				placeholder={__("Password (if deleting secure files)")}
				onkeydown={(e) => e?.key === "Enter" && confirmBulkDelete()}
			/>
			<div class="modal-actions">
				<button
					class="btn btn-secondary"
					onclick={() => (showBulkDeleteDialog = false)}
					disabled={isBulkDeleting}
				>
					{__("Cancel")}
				</button>
				<button
					class="btn btn-danger"
					onclick={confirmBulkDelete}
					disabled={isBulkDeleting}
				>
					{isBulkDeleting ? __("Deleting...") : __("Delete")}
				</button>
			</div>
		</div>
	</div>
{/if}

{#if showUploadDialog}
	<div
		class="modal-overlay"
		onclick={() => !isUploading && (showUploadDialog = false)}
		onkeydown={(e) =>
			e?.key === "Escape" && !isUploading && (showUploadDialog = false)}
		role="button"
		tabindex="-1"
		aria-label="Close dialog"
	>
		<div
			class="modal-content"
			onclick={(e) => e?.stopPropagation()}
			onkeydown={(e) => e?.stopPropagation()}
			role="button"
			tabindex="0"
		>
			<h3>{__("Upload Files")}</h3>
			<input
				type="file"
				multiple
				onchange={(e) => (selectedFiles = e?.currentTarget?.files)}
				disabled={isUploading}
			/>
			{#if isUploading}
				<div class="upload-progress">
					<p>
						{#if uploadPhase === "uploading"}
							{__("Uploading:")} {currentUploadFile}
						{:else}
							{__("Assembling:")} {currentUploadFile}
						{/if}
					</p>
					<div class="progress-bar">
						<div
							class="progress-fill"
							style="width: {uploadProgress}%"
						></div>
					</div>
					<p>
						{uploadProgress}% - {uploadPhase === "uploading"
							? __("Uploading chunks")
							: __("Assembling file")}
					</p>
				</div>
			{/if}
			<div class="modal-actions">
				<button
					class="btn btn-secondary"
					onclick={() => (showUploadDialog = false)}
					disabled={isUploading}>{__("Cancel")}</button
				>
				<button
					class="btn btn-primary"
					onclick={handleUpload}
					disabled={isUploading || !selectedFiles}
				>
					{isUploading ? __("Uploading...") : __("Upload")}
				</button>
			</div>
		</div>
	</div>
{/if}

{#if showConflictDialog}
	<div
		class="modal-overlay"
		onclick={() => (showConflictDialog = false)}
		onkeydown={(e) => e?.key === "Escape" && (showConflictDialog = false)}
		role="button"
		tabindex="-1"
		aria-label="Close dialog"
	>
		<div
			class="modal-content"
			onclick={(e) => e?.stopPropagation()}
			onkeydown={(e) => e?.stopPropagation()}
			role="button"
			tabindex="0"
		>
			<h3>File Conflict</h3>
			<p>Some files already exist. How would you like to proceed?</p>
			<div class="modal-actions">
				<button
					class="btn btn-secondary"
					onclick={() => handleConflictResolution("skip")}
					>Skip</button
				>
				<button
					class="btn btn-warning"
					onclick={() => handleConflictResolution("overwrite")}
					>Overwrite</button
				>
				<button
					class="btn btn-primary"
					onclick={() => handleConflictResolution("rename")}
					>Rename</button
				>
			</div>
		</div>
	</div>
{/if}

{#if showSizeMismatchDialog && sizeMismatchJob}
	<div
		class="modal-overlay"
		role="button"
		tabindex="-1"
		aria-label="Size mismatch dialog"
	>
		<div
			class="modal-content"
			onclick={(e) => e?.stopPropagation()}
			onkeydown={(e) => e?.stopPropagation()}
			role="button"
			tabindex="0"
		>
			<h3>⚠️ File Size Mismatch</h3>
			<p>The uploaded file size doesn't match the expected size:</p>
			<div class="size-details">
				<p><strong>File:</strong> {sizeMismatchJob.file_name}</p>
				<p>
					<strong>Expected:</strong>
					{(sizeMismatchJob.error_details?.expected / 1048576).toFixed(2)} MB
				</p>
				<p>
					<strong>Actual:</strong>
					{(sizeMismatchJob.error_details?.actual / 1048576).toFixed(2)} MB
				</p>
			</div>
			<p class="warning-text">
				This may indicate a corrupted or incomplete upload. What would
				you like to do?
			</p>
			<div class="modal-actions">
				<button
					class="btn btn-danger"
					onclick={() => handleSizeMismatch("delete")}
				>
					Delete File
				</button>
				<button
					class="btn btn-secondary"
					onclick={() => handleSizeMismatch("keep")}
				>
					Keep File Anyway
				</button>
			</div>
		</div>
	</div>
{/if}

<style>
	.toolbar {
		padding: 10px 15px;
		background: #fff;
		border-bottom: 1px solid #e0e0e0;
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		overflow: visible;
	}

	.btn {
		border: none;
		border-radius: 3px;
		cursor: pointer;
		font-size: 13px;
		font-weight: 500;
		display: inline-flex;
		align-items: center;
		gap: 6px;
	}

	/* Modal buttons keep padding */
	.btn-primary {
		padding: 6px 12px;
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

	.btn-secondary {
		padding: 6px 12px;
		background: #f0f0f0;
		color: #333;
	}

	.btn-secondary:hover {
		background: #e0e0e0;
	}

	.btn-danger {
		padding: 6px 12px;
		background: #dc3232;
		color: white;
	}

	.btn-danger:hover {
		background: #a00;
	}

	.btn-trash {
		padding: 6px 12px;
		background: #fafafa;
		border: 1px solid #ddd;
		color: #d63638;
	}

	.btn-trash:hover {
		background: #fdf2f2;
		border-color: #d63638;
		color: #d63638;
	}

	/* ── Icon-only toolbar buttons ── */
	.btn-icon {
		width: 30px;
		height: 30px;
		padding: 0;
		background: transparent;
		color: #555;
		border-radius: 4px;
		position: relative;
		flex-shrink: 0;
		justify-content: center;
	}

	.btn-icon:hover:not(:disabled) {
		background: #e8edf2;
		color: #1d2327;
	}

	.btn-icon:disabled {
		opacity: 0.4;
		cursor: not-allowed;
	}

	.btn-icon-active {
		background: #ddeeff;
		color: #2271b1;
	}

	.btn-icon-accent {
		color: #2271b1;
	}

	.btn-icon-accent:hover:not(:disabled) {
		background: #ddeeff;
		color: #135e96;
	}

	.btn-icon-ghost {
		color: #999;
	}

	.btn-icon-ghost:hover:not(:disabled) {
		background: rgba(220, 53, 69, 0.1);
		color: #dc3545;
	}

	.btn-icon-danger {
		color: #dc3232;
	}

	.btn-icon-danger:hover:not(:disabled) {
		background: rgba(220, 50, 50, 0.1);
	}

	/* CSS tooltip via data-tooltip — shown below buttons */
	.btn-icon[data-tooltip]::after {
		content: attr(data-tooltip);
		position: absolute;
		top: calc(100% + 6px);
		left: 50%;
		transform: translateX(-50%);
		background: #1d2327;
		color: #fff;
		padding: 3px 8px;
		border-radius: 3px;
		font-size: 11px;
		white-space: nowrap;
		pointer-events: none;
		opacity: 0;
		transition: opacity 0.15s;
		z-index: 99999;
	}

	.btn-icon[data-tooltip]:hover::after {
		opacity: 1;
	}

	.divider {
		width: 1px;
		height: 24px;
		background: #ddd;
		margin: 0 2px;
		flex-shrink: 0;
	}

	.job-indicator {
		font-size: 12px;
		color: #666;
		margin-left: auto;
		display: flex;
		align-items: center;
		gap: 8px;
	}

	.job-phase {
		font-size: 11px;
		color: #999;
	}

	.radio-group {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin: 16px 0;
	}

	.radio-group label {
		display: flex;
		align-items: center;
		gap: 8px;
		cursor: pointer;
		font-size: 13px;
		width: fit-content;
	}

	.radio-group input[type="radio"] {
		cursor: pointer;
		width: auto;
		margin: 0;
	}

	.modal-content p {
		margin: 0 0 8px 0;
		font-size: 13px;
		color: #666;
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

	.modal-content input {
		width: 100%;
		padding: 8px 10px;
		border: 1px solid #ddd;
		border-radius: 3px;
		font-size: 13px;
		margin-bottom: 16px;
		box-sizing: border-box;
	}

	.modal-content input:focus {
		outline: none;
		border-color: #2271b1;
	}

	.modal-actions {
		display: flex;
		gap: 8px;
		justify-content: flex-end;
	}

	.upload-progress {
		margin: 16px 0;
	}

	.upload-progress p {
		margin: 8px 0;
		font-size: 13px;
		color: #333;
	}

	.progress-bar {
		width: 100%;
		height: 24px;
		background: #f0f0f0;
		border-radius: 4px;
		overflow: hidden;
		margin: 8px 0;
	}

	.progress-fill {
		height: 100%;
		background: #2271b1;
		transition: width 0.3s ease;
	}

	.modal-large {
		max-width: 600px;
	}

	.file-name-input {
		width: 100%;
		padding: 8px 10px;
		border: 1px solid #ddd;
		border-radius: 3px;
		font-size: 13px;
		margin-bottom: 12px;
		box-sizing: border-box;
	}

	.file-content-textarea {
		width: 100%;
		padding: 10px;
		border: 1px solid #ddd;
		border-radius: 3px;
		font-size: 13px;
		font-family: monospace;
		margin-bottom: 16px;
		box-sizing: border-box;
		resize: vertical;
	}

	.file-name-input:focus,
	.file-content-textarea:focus {
		outline: none;
		border-color: #2271b1;
	}

	.size-details {
		background: #f8f9fa;
		padding: 12px;
		border-radius: 4px;
		margin: 12px 0;
		font-size: 13px;
	}

	.size-details p {
		margin: 4px 0;
	}

	.warning-text {
		color: #d63638;
		font-size: 13px;
		margin: 12px 0;
	}

	.btn-danger {
		background: #d63638;
		color: white;
		border: none;
	}

	.btn-danger:hover {
		background: #a00;
	}

	@media (max-width: 782px) {
		.toolbar {
			padding: 8px 10px;
			gap: 6px;
		}
		.job-indicator {
			font-size: 11px;
			flex-basis: 100%;
			margin-left: 0;
		}
	}
</style>
