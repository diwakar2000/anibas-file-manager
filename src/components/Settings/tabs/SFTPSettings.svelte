<script lang="ts">
	import ConnectionStatus from '../ConnectionStatus.svelte'
	
	let { settings = $bindable() } = $props<{ settings: any }>()
</script>

<div class="settings-section">
	<div class="form-group">
		<ConnectionStatus type="sftp" {settings} bind:enabled={settings.enabled}>
			Enable SFTP Connection
		</ConnectionStatus>
	</div>

	{#if settings.enabled}
		<div class="settings-grid">
			<div class="form-group">
				<label for="sftp-host">Host</label>
				<input id="sftp-host" type="text" bind:value={settings.host} class="form-control" placeholder="sftp.example.com">
			</div>

			<div class="form-group">
				<label for="sftp-port">Port</label>
				<input id="sftp-port" type="number" bind:value={settings.port} class="form-control">
			</div>

			<div class="form-group">
				<label for="sftp-username">Username</label>
				<input id="sftp-username" type="text" bind:value={settings.username} class="form-control">
			</div>

			<div class="form-group">
				<label for="sftp-password">Password</label>
				<input id="sftp-password" type="password" bind:value={settings.password} class="form-control" placeholder="Leave empty if using key">
			</div>

			<div class="form-group">
				<label for="sftp-private-key">Private Key Path</label>
				<input id="sftp-private-key" type="text" bind:value={settings.private_key} class="form-control" placeholder="/path/to/key">
			</div>

			<div class="form-group">
				<label for="sftp-base-path">Base Path</label>
				<input id="sftp-base-path" type="text" bind:value={settings.base_path} class="form-control" placeholder="/">
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
</style>
