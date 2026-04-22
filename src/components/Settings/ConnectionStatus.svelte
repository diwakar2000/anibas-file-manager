<script lang="ts">
	let { type, settings, enabled = $bindable(), children } = $props<{ type: string, settings: any, enabled: boolean, children: import('svelte').Snippet }>()
	
	let status = $state<'offline' | 'online' | 'checking'>('offline')
	let statusMessage = $state('')

	async function checkConnection() {
		const requiredFields = {
			ftp: ['host', 'username'],
			sftp: ['host', 'username'],
			s3: ['access_key', 'secret_key', 'bucket'],
			s3_compatible: ['endpoint', 'access_key', 'secret_key', 'bucket']
		}

		const required = requiredFields[type as keyof typeof requiredFields] || []
		const hasRequired = required.every(field => settings[field])

		if (!enabled || !hasRequired) {
			status = 'offline'
			return
		}

		status = 'checking'
		const cfg = (window as any).AnibasFMSettings
		const formData = new FormData()
		formData.append('action', cfg.actions.testRemoteConnection)
		formData.append('nonce', cfg.nonce)
		formData.append('type', type)
		formData.append('config', JSON.stringify(settings))

		try {
			const response = await fetch(cfg.ajaxURL, { method: 'POST', body: formData })
			const data = await response.json()
			status = data.success ? 'online' : 'offline'
			statusMessage = data.data?.message || data.data || ''
		} catch (e) {
			status = 'offline'
			statusMessage = 'Connection failed'
		}
	}

	$effect(() => {
		if (enabled) {
			checkConnection()
		} else {
			status = 'offline'
		}
	})
</script>

<div class="header-with-status">
	<label class="toggle-label">
		<input type="checkbox" bind:checked={enabled}>
		<span>{@render children()}</span>
	</label>
	<div class="status-indicator">
		<span class="status-dot status-{status}"></span>
		<span class="status-text">{status === 'checking' ? 'Checking...' : status === 'online' ? 'Connected' : 'Offline'}</span>
	</div>
</div>
{#if statusMessage}
	<p class="status-message">{statusMessage}</p>
{/if}

<style>
	.header-with-status {
		display: flex;
		justify-content: space-between;
		align-items: center;
	}

	.toggle-label {
		display: flex;
		align-items: center;
		gap: 8px;
		cursor: pointer;
		font-weight: 600;
	}

	.toggle-label input[type="checkbox"] {
		width: 18px;
		height: 18px;
		cursor: pointer;
	}

	.status-indicator {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 13px;
	}

	.status-dot {
		width: 10px;
		height: 10px;
		border-radius: 50%;
	}

	.status-dot.status-offline {
		background: #d3d3d3;
	}

	.status-dot.status-online {
		background: #46b450;
		box-shadow: 0 0 4px #46b450;
	}

	.status-dot.status-checking {
		background: #f0b849;
		animation: pulse 1.5s infinite;
	}

	@keyframes pulse {
		0%, 100% { opacity: 1; }
		50% { opacity: 0.5; }
	}

	.status-text {
		color: #666;
		font-weight: 500;
	}

	.status-message {
		font-size: 12px;
		color: #666;
		margin: 4px 0 0 26px;
	}
</style>
