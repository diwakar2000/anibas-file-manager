<script lang="ts">
	import ConnectionStatus from '../ConnectionStatus.svelte'
	
	let { settings = $bindable() } = $props<{ settings: any }>()
</script>

<div class="settings-section">
	<div class="form-group">
		<ConnectionStatus type="ftp" {settings} bind:enabled={settings.enabled}>
			Enable FTP/FTPS Connection
		</ConnectionStatus>
	</div>

	{#if settings.enabled}
		<div class="settings-grid">
			<div class="form-group">
				<label for="ftp-host">Host</label>
				<input type="text" id="ftp-host" bind:value={settings.host} class="form-control" placeholder="ftp.example.com">
			</div>

			<div class="form-group">
				<label for="ftp-port">Port</label>
				<input type="number" id="ftp-port" bind:value={settings.port} class="form-control">
			</div>

			<div class="form-group">
				<label for="ftp-username">Username</label>
				<input type="text" id="ftp-username" bind:value={settings.username} class="form-control">
			</div>

			<div class="form-group">
				<label for="ftp-password">Password</label>
				<input type="password" id="ftp-password" bind:value={settings.password} class="form-control">
			</div>

			<div class="form-group">
				<label for="ftp-base-path">Base Path</label>
				<input type="text" id="ftp-base-path" bind:value={settings.base_path} class="form-control" placeholder="/">
			</div>

			<div class="form-group">
				<label for="ftp-use-ssl" class="toggle-label">
					<input type="checkbox" id="ftp-use-ssl" bind:checked={settings.use_ssl}>
					<span>Use SSL (FTPS)</span>
				</label>
			</div>

			<div class="form-group">
				<label for="ftp-passive" class="toggle-label">
					<input type="checkbox" id="ftp-passive" bind:checked={settings.is_passive}>
					<span>Passive Mode</span>
				</label>
				<small class="form-hint">
					Passive mode works on most networks. Disable only if your server requires Active mode or you get "Can't open data connection" errors.
				</small>
			</div>
		</div>
	{/if}
</div>

<style>
	.settings-section {
		background: #fff;
		padding: 20px;
		border: 1px solid #ddd;
		border-radius: 4px;
		margin-top: 20px;
	}

	.settings-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
		gap: 20px;
		margin-top: 20px;
	}

	.form-group {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.form-group label {
		font-weight: 600;
		color: #333;
	}

	.form-control {
		padding: 8px 12px;
		border: 1px solid #ddd;
		border-radius: 4px;
		font-size: 14px;
	}

	.form-control:focus {
		outline: none;
		border-color: #2271b1;
		box-shadow: 0 0 0 1px #2271b1;
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

	.form-hint {
		color: #666;
		font-size: 12px;
		line-height: 1.4;
	}
</style>
