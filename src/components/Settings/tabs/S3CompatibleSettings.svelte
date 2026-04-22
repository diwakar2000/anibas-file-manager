<script lang="ts">
	import ConnectionStatus from '../ConnectionStatus.svelte'
	
	let { settings = $bindable() } = $props<{ settings: any }>()
</script>

<div class="settings-section">
	<div class="form-group">
		<ConnectionStatus type="s3_compatible" {settings} bind:enabled={settings.enabled}>
			Enable S3 Compatible Storage
		</ConnectionStatus>
		<p class="help-text">Works with MinIO, DigitalOcean Spaces, Wasabi, and other S3-compatible services</p>
	</div>

	{#if settings.enabled}
		<div class="settings-grid">
			<div class="form-group">
				<label for="s3-endpoint">Endpoint URL</label>
				<input type="text" id="s3-endpoint" bind:value={settings.endpoint} class="form-control" placeholder="https://minio.example.com">
			</div>

			<div class="form-group">
				<label for="s3-region">Region</label>
				<input type="text" id="s3-region" bind:value={settings.region} class="form-control" placeholder="us-east-1">
			</div>

			<div class="form-group">
				<label for="s3-bucket">Bucket</label>
				<input type="text" id="s3-bucket" bind:value={settings.bucket} class="form-control" placeholder="my-bucket">
			</div>

			<div class="form-group">
				<label for="s3-access-key">Access Key</label>
				<input type="text" id="s3-access-key" bind:value={settings.access_key} class="form-control">
			</div>

			<div class="form-group">
				<label for="s3-secret-key">Secret Key</label>
				<input type="password" id="s3-secret-key" bind:value={settings.secret_key} class="form-control">
			</div>

			<div class="form-group">
				<label for="s3-prefix">Prefix (Optional)</label>
				<input type="text" id="s3-prefix" bind:value={settings.prefix} class="form-control" placeholder="uploads/">
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

	.help-text {
		font-size: 13px;
		color: #666;
		margin: 4px 0 0 26px;
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
