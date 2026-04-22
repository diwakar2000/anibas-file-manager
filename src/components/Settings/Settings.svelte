<script lang="ts">
	import { onMount } from "svelte"
	import PasswordPrompt from "./PasswordPrompt.svelte"
	import GeneralSettings from "./tabs/GeneralSettings.svelte"
	import FTPSettings from "./tabs/FTPSettings.svelte"
	import SFTPSettings from "./tabs/SFTPSettings.svelte"
	import S3Settings from "./tabs/S3Settings.svelte"
	import S3CompatibleSettings from "./tabs/S3CompatibleSettings.svelte"
	import "../../app.css"

	const config = (window as any).AnibasFMSettings
	const TOKEN_KEY = 'anibas_fm_token'
	
	let authenticated = $state(false)
	let loading = $state(true)
	let error = $state<string | null>(null)
	let authToken = $state<string | null>(null)
	let activeTab = $state('general');
	let saving = $state(false);
	let message = $state('');

	let ftp = $state({ enabled: false, host: '', username: '', password: '', base_path: '/', use_ssl: false, port: 21, is_passive: true });
	let sftp = $state({ enabled: false, host: '', username: '', password: '', private_key: '', base_path: '/', port: 22 });
	let s3 = $state({ enabled: false, region: 'us-east-1', access_key: '', secret_key: '', bucket: '', prefix: '' });
	let s3c = $state({ enabled: false, endpoint: '', region: 'us-east-1', access_key: '', secret_key: '', bucket: '', prefix: '' });

	onMount(async () => {
		if (!config.hasPassword) {
			authenticated = true
			loading = false
			await loadRemoteSettings()
			return
		}

		const token = sessionStorage.getItem(TOKEN_KEY)
		if (token) {
			await checkAuth(token)
		} else {
			loading = false
		}
	})

	async function checkAuth(token: string) {
		try {
			const formData = new FormData()
			formData.append('action', 'anibas_fm_check_auth')
			formData.append('nonce', config.nonce)
			formData.append('token', token)

			const res = await fetch(config.ajaxURL, {
				method: 'POST',
				body: formData
			})
			
			const json = await res.json()
			
			if (json.success) {
				authenticated = true
				authToken = token
				await loadRemoteSettings()
			} else {
				sessionStorage.removeItem(TOKEN_KEY)
			}
		} catch (err) {
			sessionStorage.removeItem(TOKEN_KEY)
		} finally {
			loading = false
		}
	}

	async function handlePasswordSubmit(password: string) {
		loading = true
		error = null
		
		try {
			const formData = new FormData()
			formData.append('action', 'anibas_fm_verify_password')
			formData.append('nonce', config.nonce)
			formData.append('password', password)

			const res = await fetch(config.ajaxURL, {
				method: 'POST',
				body: formData
			})
			
			const json = await res.json()
			
			if (json.success) {
				authenticated = true
				authToken = json.data.token
				sessionStorage.setItem(TOKEN_KEY, json.data.token)
				await loadRemoteSettings()
			} else {
				error = json.data || 'Invalid password'
			}
		} catch (err: any) {
			error = err.message || 'Failed to verify password'
		} finally {
			loading = false
		}
	}

	function handlePasswordChanged() {
		sessionStorage.removeItem(TOKEN_KEY)
		authToken = null
		config.hasPassword = true
		authenticated = false
	}

	async function loadRemoteSettings() {
		const response = await fetch(`${config.ajaxURL}?action=${config.actions.getRemoteSettings}&nonce=${config.nonce}`);
		const data = await response.json();
		if (data.success) {
			// Merge over defaults so newly-introduced fields (like is_passive) get
			// their default value when the saved config predates them.
			ftp = { ...ftp, ...(data.data.ftp || {}) };
			sftp = { ...sftp, ...(data.data.sftp || {}) };
			s3 = { ...s3, ...(data.data.s3 || {}) };
			s3c = { ...s3c, ...(data.data.s3_compatible || {}) };
		}
	}

	async function saveRemoteSettings() {
		saving = true;
		message = '';
		
		const formData = new FormData();
		formData.append('action', config.actions.saveRemoteSettings);
		formData.append('nonce', config.nonce);
		formData.append('settings', JSON.stringify({ ftp, sftp, s3, s3_compatible: s3c }));

		const response = await fetch(config.ajaxURL, { method: 'POST', body: formData });
		const data = await response.json();
		
		message = data.success ? 'Settings saved successfully!' : 'Failed to save settings.';
		saving = false;
	}
</script>

<div class="anibas-fm-settings">
	{#if loading}
		<div class="text-center p-5">
			<div class="spinner-border" role="status">
				<span class="visually-hidden">Loading...</span>
			</div>
		</div>
	{:else if !authenticated}
		<PasswordPrompt 
			{loading} 
			{error} 
			onSubmit={handlePasswordSubmit} 
		/>
	{:else}
		<div class="wrap">
			<h1>File Manager Settings</h1>

			<nav class="nav-tab-wrapper">
				<button class="nav-tab" class:nav-tab-active={activeTab === 'general'} onclick={() => activeTab = 'general'}>General</button>
				<button class="nav-tab" class:nav-tab-active={activeTab === 'ftp'} onclick={() => activeTab = 'ftp'}>FTP/FTPS</button>
				<button class="nav-tab" class:nav-tab-active={activeTab === 'sftp'} onclick={() => activeTab = 'sftp'}>SFTP</button>
				<button class="nav-tab" class:nav-tab-active={activeTab === 's3'} onclick={() => activeTab = 's3'}>Amazon S3</button>
				<button class="nav-tab" class:nav-tab-active={activeTab === 's3c'} onclick={() => activeTab = 's3c'}>S3 Compatible</button>
			</nav>

			{#if activeTab === 'general'}
				<GeneralSettings {authToken} onPasswordChanged={handlePasswordChanged} />
			{:else}
				{#if message}
					<div class="notice notice-{message.includes('success') ? 'success' : 'error'}">
						<p>{message}</p>
					</div>
				{/if}

				<form onsubmit={(e) => { e.preventDefault(); saveRemoteSettings(); }}>
					{#if activeTab === 'ftp'}
						<FTPSettings bind:settings={ftp} />
					{:else if activeTab === 'sftp'}
						<SFTPSettings bind:settings={sftp} />
					{:else if activeTab === 's3'}
						<S3Settings bind:settings={s3} />
					{:else if activeTab === 's3c'}
						<S3CompatibleSettings bind:settings={s3c} />
					{/if}

					<p class="submit">
						<button type="submit" class="button button-primary" disabled={saving}>
							{saving ? 'Saving...' : 'Save Settings'}
						</button>
					</p>
				</form>
			{/if}
		</div>
	{/if}
</div>

<style>
	.anibas-fm-settings {
		max-width: 1200px;
		margin: 20px 0;
	}
	.nav-tab {
		background: none;
		border: 1px solid #ccc;
		border-bottom: none;
		padding: 8px 12px;
		cursor: pointer;
	}
	.nav-tab-active {
		background: #fff;
		border-bottom: 1px solid #fff;
		margin-bottom: -1px;
	}

</style>
