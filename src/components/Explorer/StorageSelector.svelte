<script lang="ts">
	import { toast } from '../../utils/toast'

	let { currentStorage, onSelect } = $props<{
		currentStorage: string
		onSelect: (storage: string) => void
	}>()

	let showModal = $state(false)
	let selectedStorage = $state(currentStorage)
	let loading = $state(false)
	let checking = $state(false)
	let hasLoaded = $state(false)

	$effect(() => {
		selectedStorage = currentStorage
	})

	const config = (window as any).AnibasFM
	let storages = $state<Array<{ id: string; name: string; status: 'checking' | 'online' | 'offline' }>>([
		{ id: 'local', name: 'Local Files', status: 'online' }
	])

	async function openModal() {
		selectedStorage = currentStorage
		showModal = true
		
		if (!hasLoaded) {
			await loadAndCheckStorages()
			hasLoaded = true
		}
	}

	async function loadAndCheckStorages() {
		checking = true

		try {
			const response = await fetch(`${config.ajaxURL}?action=${config.actions.getRemoteSettings}&nonce=${config.settingsNonce}`)
			const data = await response.json()

			if (!data.success) {
				checking = false
				return
			}

			const remoteStorages = [
				{ id: 'ftp', name: 'FTP', config: data.data.ftp, enabled: data.data.ftp?.enabled },
				{ id: 'sftp', name: 'SFTP', config: data.data.sftp, enabled: data.data.sftp?.enabled },
				{ id: 's3', name: 'Amazon S3', config: data.data.s3, enabled: data.data.s3?.enabled },
				{ id: 's3_compatible', name: 'S3 Compatible', config: data.data.s3_compatible, enabled: data.data.s3_compatible?.enabled }
			].filter(s => s.enabled)

			storages = [
				{ id: 'local', name: 'Local Files', status: 'online' },
				...remoteStorages.map(s => ({ id: s.id, name: s.name, status: 'checking' as const }))
			]

			const tests = remoteStorages.map(async (storage) => {
				const formData = new FormData()
				formData.append('action', config.actions.testRemoteConnection)
				formData.append('nonce', config.settingsNonce)
				formData.append('type', storage.id)
				formData.append('config', JSON.stringify(storage.config))

				try {
					const res = await fetch(config.ajaxURL, { method: 'POST', body: formData })
					const result = await res.json()
					storages = storages.map(s =>
						s.id === storage.id
							? { ...s, status: result.success ? 'online' : 'offline' }
							: s
					)
				} catch (e) {
					console.error(`${storage.id} test error:`, e)
					storages = storages.map(s =>
						s.id === storage.id
							? { ...s, status: 'offline' }
							: s
					)
				}
			})

			await Promise.all(tests)

			// If the currently selected storage went offline, reset to current
			const sel = storages.find(s => s.id === selectedStorage)
			if (sel && sel.status === 'offline') {
				selectedStorage = currentStorage
			}
		} catch (e) {
			console.error('Failed to load storages:', e)
		} finally {
			checking = false
		}
	}

	async function handleSelect() {
		if (selectedStorage === currentStorage) {
			showModal = false
			return
		}

		loading = true
		try {
			await onSelect(selectedStorage)
			showModal = false
		} catch (err) {
			console.error('Failed to change storage:', err)
			toast.error('Failed to change storage: ' + (err instanceof Error ? err.message : String(err)))
		} finally {
			loading = false
		}
	}

	function getStorageName(id: string) {
		const found = storages.find(s => s.id === id)
		if (found) return found.name
		
		const names: Record<string, string> = {
			'local': 'Local Files',
			'ftp': 'FTP',
			'sftp': 'SFTP',
			's3': 'Amazon S3',
			's3_compatible': 'S3 Compatible'
		}
		return names[id] || 'Local Files'
	}
</script>

<button class="anibas-storage-selector" onclick={openModal}>
	<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
		<path d="M2 4a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V4zm2-1a1 1 0 00-1 1v8a1 1 0 001 1h8a1 1 0 001-1V4a1 1 0 00-1-1H4z"/>
		<path d="M6 8a.5.5 0 01.5-.5h3a.5.5 0 010 1h-3A.5.5 0 016 8z"/>
	</svg>
	<span>{getStorageName(currentStorage)}</span>
	<svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor">
		<path d="M2 4l4 4 4-4z"/>
	</svg>
</button>

{#if showModal}
	<div class="anibas-storage-modal-overlay" onclick={() => !loading && (showModal = false)} role="button" tabindex="-1" onkeydown={(e) => e.key === 'Escape' && !loading && (showModal = false)}>
		<div class="anibas-storage-modal-content" onclick={(e) => e.stopPropagation()} role="button" tabindex="0" onkeydown={(e) => e.stopPropagation()}>
			<h3>Select Storage</h3>
			
			{#if checking}
				<div class="anibas-storage-checking">
					<div class="anibas-spinner"></div>
					<p>Checking connections...</p>
				</div>
			{/if}

			<div class="anibas-storage-list">
				{#each storages as storage}
					<label class="anibas-storage-option" class:anibas-checking={storage.status === 'checking'} class:anibas-offline={storage.status === 'offline' && storage.id !== 'local'}>
						<input
							type="radio"
							name="storage"
							value={storage.id}
							bind:group={selectedStorage}
							disabled={loading || storage.status === 'checking' || (storage.status === 'offline' && storage.id !== 'local')}
						>
						<span>{storage.name}</span>
						{#if storage.status === 'checking'}
							<span class="anibas-status-badge anibas-checking-badge">Checking...</span>
						{:else if storage.status === 'offline' && storage.id !== 'local'}
							<span class="anibas-status-badge anibas-offline-badge">Could not connect</span>
						{/if}
					</label>
				{/each}
			</div>

			<div class="anibas-storage-modal-actions">
				<button class="anibas-storage-btn-cancel" onclick={() => showModal = false} disabled={loading}>
					Cancel
				</button>
				<button class="anibas-storage-btn-select" onclick={handleSelect} disabled={loading || checking}>
					{loading ? 'Loading...' : 'Select'}
				</button>
			</div>
		</div>
	</div>
{/if}

<style>
	.anibas-storage-selector {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 6px 12px;
		background: #fff;
		border: 1px solid #ddd;
		border-radius: 4px;
		cursor: pointer;
		font-size: 14px;
		color: #2c3338;
		transition: all 0.2s;
	}

	.anibas-storage-selector:hover {
		border-color: #2271b1;
		background: #f6f7f7;
	}

	.anibas-storage-modal-overlay {
		position: fixed;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		background: rgba(0, 0, 0, 0.5);
		display: flex !important;
		align-items: center;
		justify-content: center;
		z-index: 999999;
	}

	.anibas-storage-modal-content {
		background: white;
		border-radius: 4px;
		padding: 24px;
		min-width: 400px;
		max-width: 500px;
		box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
		position: relative;
		z-index: 1000000;
		display: block !important;
	}

	.anibas-storage-modal-content h3 {
		margin: 0 0 20px;
		font-size: 18px;
		color: #1d2327;
	}

	.anibas-storage-checking {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 16px;
		background: #f0f6fc;
		border-radius: 4px;
		margin-bottom: 16px;
	}

	.anibas-storage-checking p {
		margin: 0;
		color: #2271b1;
		font-size: 14px;
	}

	.anibas-spinner {
		width: 20px;
		height: 20px;
		border: 3px solid #e0e0e0;
		border-top-color: #2271b1;
		border-radius: 50%;
		animation: anibas-spin 0.8s linear infinite;
	}

	@keyframes anibas-spin {
		to { transform: rotate(360deg); }
	}

	.anibas-storage-list {
		display: flex;
		flex-direction: column;
		gap: 12px;
		margin-bottom: 24px;
	}

	.anibas-storage-option {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 12px;
		border: 2px solid #ddd;
		border-radius: 4px;
		cursor: pointer;
		transition: all 0.2s;
	}

	.anibas-storage-option.anibas-checking,
	.anibas-storage-option.anibas-offline {
		opacity: 0.6;
		cursor: not-allowed;
	}

	.anibas-storage-option:hover:not(.anibas-checking):not(.anibas-offline) {
		border-color: #2271b1;
		background: #f6f7f7;
	}

	.anibas-storage-option:has(input:checked) {
		border-color: #2271b1;
		background: #f0f6fc;
	}

	.anibas-storage-option input {
		width: 18px;
		height: 18px;
		cursor: pointer;
	}

	.anibas-storage-option span {
		font-size: 14px;
		font-weight: 500;
		flex: 1;
	}

	.anibas-status-badge {
		font-size: 12px;
		padding: 4px 8px;
		border-radius: 3px;
	}

	.anibas-checking-badge {
		background: #f0b849;
		color: #fff;
	}

	.anibas-offline-badge {
		background: #d63638;
		color: #fff;
		font-size: 11px;
		padding: 2px 6px;
	}

	.anibas-storage-modal-actions {
		display: flex;
		justify-content: flex-end;
		gap: 12px;
	}

	.anibas-storage-btn-cancel, .anibas-storage-btn-select {
		padding: 8px 16px;
		border-radius: 4px;
		font-size: 14px;
		cursor: pointer;
		border: none;
		font-weight: 500;
	}

	.anibas-storage-btn-cancel {
		background: #f0f0f1;
		color: #2c3338;
	}

	.anibas-storage-btn-cancel:hover:not(:disabled) {
		background: #dcdcde;
	}

	.anibas-storage-btn-select {
		background: #2271b1;
		color: white;
	}

	.anibas-storage-btn-select:hover:not(:disabled) {
		background: #135e96;
	}

	.anibas-storage-btn-cancel:disabled, .anibas-storage-btn-select:disabled {
		opacity: 0.6;
		cursor: not-allowed;
	}
</style>
