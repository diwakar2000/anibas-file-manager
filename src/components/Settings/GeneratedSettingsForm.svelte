<script lang="ts">
	import type { StorageField, StorageProvider, StorageSection } from "../../utils/storageProviders"

	let {
		provider,
		values,
		authToken = null,
		onChange,
		onBeforeOAuth,
	} = $props<{
		provider: StorageProvider
		values: Record<string, any>
		authToken?: string | null
		onChange: (next: Record<string, any>) => void
		onBeforeOAuth?: () => Promise<boolean>
	}>()

	let status = $state<'offline' | 'online' | 'checking'>('offline')
	let statusMessage = $state('')
	let oauthLoading = $state(false)
	let oauthMessage = $state('')
	let lastCheckSignature = ''
	let lastProviderId = ''
	let connectionCheckRequest = 0
	let showRedirectAfterAuth = $state(false)

	const config = (window as any).AnibasFMSettings

	function isBooleanField(field: StorageField): boolean {
		return field.type === 'checkbox' || field.type === 'toggle' || field.type === 'boolean'
	}

	function getValue(field: StorageField): any {
		if (values && Object.prototype.hasOwnProperty.call(values, field.key)) {
			return values[field.key]
		}
		if (field.default !== undefined) return field.default
		return isBooleanField(field) ? false : ''
	}

	function updateField(field: StorageField, value: unknown) {
		const next = { ...(values || {}), [field.key]: value }
		if (field.secret) {
			next[`${field.key}_clear`] = false
		}
		onChange(next)
	}

	function inputType(field: StorageField): string {
		if (field.type === 'password') return 'password'
		if (field.type === 'number' || field.type === 'integer') return 'number'
		if (field.type === 'url') return 'url'
		return 'text'
	}

	function fieldPlaceholder(field: StorageField): string {
		if (field.placeholder) return field.placeholder
		if (field.secret && values?.[`${field.key}_set`]) return 'Stored value will be kept'
		return ''
	}

	function isFieldVisible(field: StorageField): boolean {
		if (field.hidden) return false
		if (field.key === 'enabled') return false
		if (!field.showWhen) return true
		return Object.entries(field.showWhen).every(([key, expected]) => values?.[key] === expected)
	}

	function visibleFields(section: StorageSection): StorageField[] {
		return (section.fields || []).filter(isFieldVisible)
	}

	function requiredFields(): StorageField[] {
		const sections: StorageSection[] = provider.settings?.sections || []
		return sections
			.flatMap((section: StorageSection) => section.fields || [])
			.filter((field: StorageField) => field.required && isFieldVisible(field))
	}

	function oauthCredentialFields(): StorageField[] {
		const sections: StorageSection[] = provider.settings?.sections || []
		return sections
			.flatMap((section: StorageSection) => section.fields || [])
			.filter((field: StorageField) => field.required && isFieldVisible(field))
	}

	function hasRequiredFields(): boolean {
		if (provider.oauth?.enabled) return hasOAuthToken()
		if (!values?.enabled) return false
		return requiredFields().every((field) => {
			const value = values?.[field.key]
			return (value !== undefined && value !== '' && value !== null) || Boolean(field.secret && values?.[`${field.key}_set`])
		})
	}

	function hasOAuthToken(): boolean {
		if (values?.refresh_token_clear || values?.access_token_clear) return false
		return Boolean(values?.refresh_token || values?.refresh_token_set || values?.access_token || values?.access_token_set)
	}

	function oauthButtonLabel(): string {
		if (status === 'online') return 'Connected'
		if (oauthLoading) return 'Connecting...'
		if (hasOAuthToken()) return `Reconnect ${provider.label}`
		return provider.oauth?.buttonLabel || `Connect with ${provider.label}`
	}

	function oauthDisconnectLabel(): string {
		return provider.oauth?.revocationSupported ? 'Revoke' : 'Disconnect'
	}

	function hasOAuthAppCredentials(): boolean {
		if (hasOAuthToken()) return true
		if (provider.oauth?.credentialsConfigured) return true
		return oauthCredentialFields().every((field) => {
			const value = values?.[field.key]
			return (value !== undefined && value !== '' && value !== null) || Boolean(field.secret && values?.[`${field.key}_set`])
		})
	}

	function oauthMissingCredentialsMessage(): string {
		const labels = oauthCredentialFields()
			.filter((field) => {
				const value = values?.[field.key]
				return !((value !== undefined && value !== '' && value !== null) || Boolean(field.secret && values?.[`${field.key}_set`]))
			})
			.map((field) => field.label || field.key)
		return labels.length > 0 ? `${labels.join(' and ')} required.` : 'OAuth app credentials are required.'
	}

	function isInputRequired(field: StorageField): boolean {
		return Boolean(field.required && !provider.oauth?.credentialsConfigured && !(field.secret && values?.[`${field.key}_set`]))
	}

	function oauthScope(): string {
		return typeof values?.token_scope === 'string' ? values.token_scope.trim() : ''
	}

	function oauthScopeTags(): string[] {
		return oauthScope().split(/\s+/).filter(Boolean)
	}

	function shouldShowOAuthCredentialFields(): boolean {
		return !hasOAuthToken() && oauthCredentialFields().length > 0
	}

	function shouldShowOAuthRedirectUri(): boolean {
		return Boolean(provider.oauth?.redirectUrl && (!hasOAuthToken() || showRedirectAfterAuth))
	}

	async function copyRedirectUri() {
		const uri = provider.oauth?.redirectUrl || ''
		if (!uri) return

		try {
			if (navigator.clipboard?.writeText) {
				await navigator.clipboard.writeText(uri)
			} else {
				const input = document.createElement('textarea')
				input.value = uri
				input.setAttribute('readonly', 'readonly')
				input.style.position = 'fixed'
				input.style.opacity = '0'
				document.body.appendChild(input)
				input.select()
				document.execCommand('copy')
				document.body.removeChild(input)
			}
			oauthMessage = 'Redirect URI copied.'
		} catch {
			oauthMessage = 'Could not copy redirect URI.'
		}
	}

	async function startOAuth() {
		if (!provider.oauth?.enabled || oauthLoading || status === 'online') return
		oauthMessage = ''

		if (!hasOAuthAppCredentials()) {
			oauthMessage = oauthMissingCredentialsMessage()
			return
		}

		oauthLoading = true
		try {
			if (onBeforeOAuth) {
				const saved = await onBeforeOAuth()
				if (!saved) {
					oauthMessage = 'Settings could not be saved.'
					return
				}
			}

			const formData = new FormData()
			formData.append('action', provider.oauth.startAction || config.actions.startRemoteOAuth)
			formData.append('nonce', config.nonce)
			if (authToken) formData.append('token', authToken)
			formData.append('provider', provider.id)

			const response = await fetch(config.ajaxURL, { method: 'POST', body: formData })
			const data = await response.json()
			if (!data.success || !data.data?.authorize_url) {
				oauthMessage = data.data?.message || data.data || 'Could not start OAuth.'
				return
			}
			window.location.href = data.data.authorize_url
		} catch (error) {
			oauthMessage = error instanceof Error ? error.message : 'Could not start OAuth.'
		} finally {
			oauthLoading = false
		}
	}

	async function cancelOAuth() {
		if (oauthLoading || !hasOAuthToken()) return
		const revokeText = provider.oauth?.revocationSupported
			? `This will ask ${provider.label} to revoke the saved OAuth token and then remove the local tokens.`
			: `${provider.label} does not expose app-scoped token revocation here. This will remove only the local saved tokens.`
		const ok = window.confirm(`Cancel the authorized ${provider.label} connection?\n\n${revokeText}\n\nThis storage will go offline until it is connected again.`)
		if (!ok) return

		const nextValues = {
			...(values || {}),
			enabled: false,
			access_token: '',
			access_token_set: false,
			access_token_clear: false,
			refresh_token: '',
			refresh_token_set: false,
			refresh_token_clear: false,
			token_expires_at: 0,
			token_scope: '',
			oauth_connected_at: 0,
		}

		oauthLoading = true
		oauthMessage = ''
		try {
			const formData = new FormData()
			formData.append('action', provider.oauth?.revokeAction || config.actions.revokeRemoteOAuth)
			formData.append('nonce', config.nonce)
			if (authToken) formData.append('token', authToken)
			formData.append('provider', provider.id)

			const response = await fetch(config.ajaxURL, { method: 'POST', body: formData })
			const data = await response.json()
			if (!data.success) {
				oauthMessage = data.data?.message || data.data || 'Could not cancel the connection.'
				return
			}

			onChange(nextValues)
			status = 'offline'
			statusMessage = ''
			oauthMessage = data.data?.message || data.data || ''
		} catch (error) {
			oauthMessage = error instanceof Error ? error.message : 'Could not cancel the connection.'
		} finally {
			oauthLoading = false
		}
	}

	async function checkConnection() {
		const requestId = ++connectionCheckRequest
		if (provider.oauth?.enabled && !hasOAuthToken()) {
			status = 'offline'
			statusMessage = ''
			return
		}
		if (!provider.oauth?.enabled && !hasRequiredFields()) {
			status = 'offline'
			statusMessage = ''
			return
		}

		status = 'checking'
		const formData = new FormData()
		formData.append('action', provider.ajax?.testConnection || config.actions.testRemoteConnection)
		formData.append('nonce', config.nonce)
		if (authToken) formData.append('token', authToken)
		formData.append('type', provider.id)
		formData.append('config', JSON.stringify(values || {}))

		try {
			const response = await fetch(config.ajaxURL, { method: 'POST', body: formData })
			const data = await response.json()
			if (requestId !== connectionCheckRequest) return
			status = data.success ? 'online' : 'offline'
			statusMessage = data.data?.message || data.data || ''
		} catch {
			if (requestId !== connectionCheckRequest) return
			status = 'offline'
			statusMessage = 'Connection failed'
		}
	}

	$effect(() => {
		const currentProviderId = provider.id
		if (lastProviderId === '') {
			lastProviderId = currentProviderId
		} else if (lastProviderId !== currentProviderId) {
			lastProviderId = currentProviderId
			status = 'offline'
			statusMessage = ''
			oauthMessage = ''
			oauthLoading = false
			showRedirectAfterAuth = false
			lastCheckSignature = ''
			connectionCheckRequest++
		}
	})

	$effect(() => {
		const signature = JSON.stringify({
			id: provider.id,
			values,
		})

		if (signature === lastCheckSignature) return
		lastCheckSignature = signature

		const timer = window.setTimeout(() => {
			checkConnection()
		}, 300)

		return () => window.clearTimeout(timer)
	})
</script>

<div class="settings-section">
	<div class="form-group">
		<div class="header-with-status">
			{#if provider.oauth?.enabled}
				<div class="provider-title">{provider.label}</div>
			{:else}
				<label class="toggle-label">
					<input
						type="checkbox"
						checked={Boolean(values?.enabled)}
						onchange={(event) => updateField({ key: 'enabled', type: 'toggle' }, (event.currentTarget as HTMLInputElement).checked)}
					>
					<span>{provider.settings?.enable_label || `Enable ${provider.label}`}</span>
				</label>
			{/if}
			<div class="status-indicator">
				<span class="status-dot status-{status}"></span>
				<span class="status-text">{status === 'checking' ? 'Checking...' : status === 'online' ? 'Connected' : 'Offline'}</span>
			</div>
		</div>
		{#if statusMessage}
			<p class="status-message">{statusMessage}</p>
		{/if}
	</div>

	{#if provider.oauth?.enabled}
		<div class="oauth-action">
			{#if hasOAuthToken() && provider.oauth?.redirectUrl}
				<button type="button" class="button oauth-toggle-button" onclick={() => showRedirectAfterAuth = !showRedirectAfterAuth}>
					{showRedirectAfterAuth ? 'Hide redirect URI' : 'Show redirect URI'}
				</button>
			{/if}
			{#if shouldShowOAuthRedirectUri() || shouldShowOAuthCredentialFields()}
				<div class="settings-subsection oauth-credentials">
					<div class="settings-grid">
						{#if shouldShowOAuthRedirectUri()}
							<div class="form-group oauth-redirect-field">
								<label for={`${provider.id}-oauth-redirect-uri`}>Redirect URI</label>
								<div class="oauth-redirect-control">
									<input
										id={`${provider.id}-oauth-redirect-uri`}
										type="text"
										value={provider.oauth.redirectUrl}
										class="form-control oauth-redirect-input"
										readonly
									>
									<button type="button" class="button oauth-copy-button" onclick={copyRedirectUri} aria-label="Copy redirect URI" title="Copy redirect URI">
										<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
											<rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
											<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
										</svg>
									</button>
								</div>
							</div>
						{/if}
						{#if shouldShowOAuthCredentialFields()}
							{#each oauthCredentialFields() as field}
								<div class="form-group">
									<label for={`${provider.id}-${field.key}`}>{field.label}</label>
									<input
										id={`${provider.id}-${field.key}`}
										type={inputType(field)}
										value={getValue(field)}
										class="form-control"
										required={isInputRequired(field)}
										min={field.min}
										max={field.max}
										maxlength={field.maxLength}
										placeholder={fieldPlaceholder(field)}
										oninput={(event) => {
											const raw = (event.currentTarget as HTMLInputElement).value
											updateField(field, inputType(field) === 'number' && raw !== '' ? Number(raw) : raw)
										}}
									>
									{#if field.secret && values?.[`${field.key}_set`]}
										<small class="form-hint">Saved value will be kept if left blank.</small>
									{/if}
								</div>
							{/each}
						{/if}
					</div>
				</div>
			{/if}
			<div class="oauth-buttons">
				<button type="button" class="button button-primary oauth-connect-button" onclick={startOAuth} disabled={oauthLoading || status === 'online'}>
					<span class="oauth-provider-icon-badge" aria-hidden="true">
						{#if provider.id === 'gdrive'}
							<svg class="oauth-provider-icon" viewBox="0 0 24 24">
								<path fill="#1E8E3E" d="M7.2 3.3 1.4 13.4l3.5 6.1L10.7 9.4z" />
								<path fill="#F9AB00" d="M7.2 3.3h9.6l5.8 10.1H13z" />
								<path fill="#1967D2" d="M4.9 19.5h11.7l3.5-6.1H8.4z" />
							</svg>
						{:else if provider.id === 'onedrive'}
							<svg class="oauth-provider-icon" viewBox="0 0 24 24">
								<path fill="#0364B8" d="M9.2 8.1a5.9 5.9 0 0 1 10.6 3.1 4.4 4.4 0 0 1-.2 8.7H8.8z" />
								<path fill="#0078D4" d="M4.9 10.7a5.4 5.4 0 0 1 9.8-2.2 6.7 6.7 0 0 0-5.9 11.4H5.2a4.6 4.6 0 0 1-.3-9.2z" />
								<path fill="#1490DF" d="M9.2 8.1a5.9 5.9 0 0 1 5.5.4 6.7 6.7 0 0 0-5.9 11.4H5.2a4.6 4.6 0 0 1-.3-9.2 5.4 5.4 0 0 1 4.3-2.6z" />
							</svg>
						{:else if provider.id === 'dropbox'}
							<svg class="oauth-provider-icon" viewBox="0 0 24 24">
								<path fill="#0061FF" d="M6.5 3.4 12 6.9 6.5 10.5 1 6.9zM17.5 3.4 23 6.9l-5.5 3.6L12 6.9zM6.5 11.6 12 15.1l-5.5 3.6L1 15.1zM17.5 11.6l5.5 3.5-5.5 3.6-5.5-3.6z" />
								<path fill="#0061FF" d="m12 16.3 5.5 3.5L12 23.3l-5.5-3.5z" opacity=".85" />
							</svg>
						{:else}
							<svg class="oauth-provider-icon oauth-cloud-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<path d="M17.5 19H8a5 5 0 0 1-.9-9.92A6.5 6.5 0 0 1 19 11.5h.5a3.75 3.75 0 0 1 0 7.5h-2" />
							</svg>
						{/if}
					</span>
					<span>{oauthButtonLabel()}</span>
				</button>
				{#if hasOAuthToken()}
					<button type="button" class="button oauth-cancel-button" onclick={cancelOAuth} disabled={oauthLoading}>
						{oauthDisconnectLabel()}
					</button>
				{/if}
			</div>
			{#if oauthMessage}
				<p class="status-message">{oauthMessage}</p>
			{/if}
			{#if hasOAuthToken() && oauthScope()}
				<div class="oauth-meta">
					<span>Scope</span>
					<div class="oauth-scope-tags">
						{#each oauthScopeTags() as scope}
							<span class="oauth-scope-tag">
								<svg class="oauth-scope-check" viewBox="0 0 16 16" aria-hidden="true">
									<path d="M13.5 4.5 6.25 11.75 2.5 8" />
								</svg>
								<span>{scope}</span>
							</span>
						{/each}
					</div>
				</div>
			{/if}
		</div>
	{:else if values?.enabled}
		{#each provider.settings?.sections || [] as section}
			{@const fields = visibleFields(section)}
			{#if fields.length > 0}
				<div class="settings-subsection">
					{#if section.label}
						<h3>{section.label}</h3>
					{/if}
					<div class="settings-grid">
						{#each fields as field}
							<div class="form-group">
								{#if isBooleanField(field)}
									<label for={`${provider.id}-${field.key}`} class="toggle-label">
										<input
											id={`${provider.id}-${field.key}`}
											type="checkbox"
											checked={Boolean(getValue(field))}
											onchange={(event) => updateField(field, (event.currentTarget as HTMLInputElement).checked)}
										>
										<span>{field.label}</span>
									</label>
								{:else}
									<label for={`${provider.id}-${field.key}`}>{field.label}</label>
									<input
										id={`${provider.id}-${field.key}`}
										type={inputType(field)}
										value={getValue(field)}
										class="form-control"
										required={isInputRequired(field)}
										min={field.min}
										max={field.max}
										maxlength={field.maxLength}
										placeholder={fieldPlaceholder(field)}
										oninput={(event) => {
											const raw = (event.currentTarget as HTMLInputElement).value
											updateField(field, inputType(field) === 'number' && raw !== '' ? Number(raw) : raw)
										}}
									>
									{#if field.secret && values?.[`${field.key}_set`]}
										<small class="form-hint">Saved value will be kept if left blank.</small>
									{/if}
								{/if}
								{#if field.help}
									<small class="form-hint">{field.help}</small>
								{/if}
							</div>
						{/each}
					</div>
				</div>
			{/if}
		{/each}
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

	.settings-subsection + .settings-subsection {
		margin-top: 20px;
	}

	.settings-subsection h3 {
		margin: 18px 0 0;
		font-size: 14px;
	}

	.settings-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
		gap: 20px;
		margin-top: 20px;
	}

	.oauth-action {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 12px;
		margin-top: 16px;
	}

	.oauth-credentials {
		width: 100%;
		margin-top: 0;
	}

	.oauth-credentials .settings-grid {
		margin-top: 0;
	}

	.oauth-redirect-field {
		grid-column: 1 / -1;
	}

	.oauth-redirect-input {
		background: #f6f7f7;
		color: #1d2327;
		font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
	}

	.oauth-redirect-control {
		display: grid;
		grid-template-columns: minmax(0, 1fr) auto;
		gap: 8px;
		align-items: center;
	}

	.oauth-copy-button,
	.oauth-toggle-button {
		display: inline-flex;
		align-items: center;
		justify-content: center;
	}

	.oauth-copy-button {
		width: 34px;
		height: 34px;
		padding: 0;
	}

	.oauth-buttons {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
	}

	.oauth-buttons .button {
		height: 34px;
	}

	.oauth-connect-button {
		display: inline-flex;
		align-items: center;
		gap: 7px;
		background: #fff;
		border-color: #2271b1;
		color: #2271b1;
	}

	.oauth-provider-icon-badge {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 22px;
		height: 22px;
		border-radius: 5px;
		background: transparent;
		color: #2271b1;
		flex: 0 0 auto;
	}

	.oauth-provider-icon {
		display: block;
		width: 16px;
		height: 16px;
	}

	.oauth-cloud-icon {
		flex: 0 0 auto;
	}

	.oauth-cancel-button {
		color: #b32d2e;
		border-color: #b32d2e;
	}

	.oauth-meta {
		display: grid;
		gap: 4px;
		max-width: 100%;
		color: #50575e;
	}

	.oauth-meta span {
		font-weight: 600;
	}

	.oauth-scope-tags {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	.oauth-scope-tag {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		max-width: 100%;
		padding: 3px 8px;
		border: 1px solid #c3e6cb;
		border-radius: 999px;
		background: #f0fff4;
		color: #1e6b34;
		font-size: 12px;
		line-height: 1.4;
		word-break: break-word;
	}

	.oauth-scope-check {
		width: 13px;
		height: 13px;
		flex: 0 0 auto;
		fill: none;
		stroke: currentColor;
		stroke-width: 2;
		stroke-linecap: round;
		stroke-linejoin: round;
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

	.provider-title {
		font-weight: 600;
		color: #1d2327;
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

	.header-with-status {
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 16px;
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

	.status-text,
	.status-message,
	.form-hint {
		color: #666;
		font-size: 12px;
		line-height: 1.4;
	}

	.status-text {
		font-weight: 500;
	}

	.status-message {
		margin: 4px 0 0 26px;
	}
</style>
