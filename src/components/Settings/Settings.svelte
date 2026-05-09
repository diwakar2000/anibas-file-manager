<script lang="ts">
	import { onMount } from "svelte"
	import PasswordPrompt from "./PasswordPrompt.svelte"
	import BackupsList from "./BackupsList.svelte"
	import GeneralSettings from "./tabs/GeneralSettings.svelte"
	import GeneratedSettingsForm from "./GeneratedSettingsForm.svelte"
	import {
		getProviderDefaultValues,
		getRemoteProviders,
		type StorageProvider,
	} from "../../utils/storageProviders"
	import "../../app.css"

	const config = (window as any).AnibasFMSettings
	const TOKEN_KEY = 'anibas_fm_token'
	const remoteProviders = getRemoteProviders(config)
	const providerById: Record<string, StorageProvider> = Object.fromEntries(remoteProviders.map((provider) => [provider.id, provider]))
	
	let authenticated = $state(false)
	let loading = $state(true)
	let error = $state<string | null>(null)
	let authToken = $state<string | null>(null)
	let activeTab = $state('general');
	let saving = $state(false);
	let message = $state('');
	let remoteSettings = $state<Record<string, Record<string, any>>>(buildRemoteSettings({}));

	onMount(async () => {
		readOAuthReturn()

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

	function handlePasswordChanged(hasPassword = true) {
		sessionStorage.removeItem(TOKEN_KEY)
		authToken = null
		config.hasPassword = hasPassword
		authenticated = !hasPassword
	}

	async function loadRemoteSettings() {
		const url = new URL(config.ajaxURL, window.location.origin)
		url.searchParams.set('action', config.actions.getRemoteSettings)
		url.searchParams.set('nonce', config.nonce)
		if (authToken) {
			url.searchParams.set('token', authToken)
		}

		const response = await fetch(url.toString());
		const data = await response.json();
		if (data.success) {
			initializeRemoteSettings(data.data || {});
		}
	}

	function initializeRemoteSettings(saved: Record<string, any>) {
		remoteSettings = buildRemoteSettings(saved);
	}

	function buildRemoteSettings(saved: Record<string, any>) {
		const next: Record<string, Record<string, any>> = {};
		for (const provider of remoteProviders) {
			next[provider.id] = {
				...getProviderDefaultValues(provider),
				...(saved[provider.id] || {}),
			};
		}
		return next;
	}

	function updateRemoteSettings(providerId: string, nextValues: Record<string, any>) {
		remoteSettings = {
			...remoteSettings,
			[providerId]: nextValues,
		};
	}

	function setActiveTab(tab: string) {
		if (activeTab === tab) return
		activeTab = tab
		message = ''
	}

	function readOAuthReturn() {
		const params = new URLSearchParams(window.location.search)
		const status = params.get('anibas_oauth_status')
		const provider = params.get('anibas_oauth_provider')
		const oauthMessage = params.get('anibas_oauth_message')
		if (!status) return

		if (provider && providerById[provider]) {
			activeTab = provider
		}
		message = oauthMessage || (status === 'success' ? 'OAuth connected.' : 'OAuth failed.')
		params.delete('anibas_oauth_status')
		params.delete('anibas_oauth_provider')
		params.delete('anibas_oauth_message')
		const next = `${window.location.pathname}${params.toString() ? `?${params.toString()}` : ''}${window.location.hash}`
		window.history.replaceState(null, '', next)
	}

	async function saveRemoteSettings(showMessage = true, providerId?: string, providerValues?: Record<string, any>): Promise<boolean> {
		saving = true;
		if (showMessage) message = '';
		try {
			const settingsToSave = providerId && providerValues
				? { ...remoteSettings, [providerId]: providerValues }
				: remoteSettings;
			const formData = new FormData();
			formData.append('action', config.actions.saveRemoteSettings);
			formData.append('nonce', config.nonce);
			if (authToken) {
				formData.append('token', authToken);
			}
			formData.append('settings', JSON.stringify(settingsToSave));

			const response = await fetch(config.ajaxURL, { method: 'POST', body: formData });
			const data = await response.json();

			if (showMessage) {
				message = data.success ? 'Settings saved successfully!' : (data.data?.message || data.data || 'Failed to save settings.');
			}
			if (data.success && providerId && providerValues) {
				remoteSettings = settingsToSave;
			}
			return Boolean(data.success);
		} catch (error) {
			if (showMessage) {
				message = error instanceof Error ? error.message : 'Failed to save settings.';
			}
			return false;
		} finally {
			saving = false;
		}
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
				<button class="nav-tab" class:nav-tab-active={activeTab === 'general'} onclick={() => setActiveTab('general')}>General</button>
				<button class="nav-tab" class:nav-tab-active={activeTab === 'backups'} onclick={() => setActiveTab('backups')}>Backups</button>
				{#each remoteProviders as provider}
					<button class="nav-tab" class:nav-tab-active={activeTab === provider.id} onclick={() => setActiveTab(provider.id)}>{provider.label}</button>
				{/each}
			</nav>

			{#if activeTab === 'general'}
				<GeneralSettings {authToken} onPasswordChanged={handlePasswordChanged} />
			{:else if activeTab === 'backups'}
				<BackupsList {authToken} />
			{:else}
				{#if message}
					<div class="notice notice-{message.includes('success') ? 'success' : 'error'}">
						<p>{message}</p>
					</div>
				{/if}

				<form onsubmit={(e) => { e.preventDefault(); saveRemoteSettings(); }}>
					{#if providerById[activeTab]}
						{#key activeTab}
							<GeneratedSettingsForm
								provider={providerById[activeTab]}
								values={remoteSettings[activeTab] || getProviderDefaultValues(providerById[activeTab])}
								{authToken}
								onChange={(nextValues) => updateRemoteSettings(activeTab, nextValues)}
								onBeforeOAuth={() => saveRemoteSettings(false)}
							/>
						{/key}
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
