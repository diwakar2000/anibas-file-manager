export type StorageField = {
	key: string
	type?: 'text' | 'url' | 'password' | 'number' | 'integer' | 'checkbox' | 'toggle' | 'boolean'
	label?: string
	required?: boolean
	secret?: boolean
	default?: string | number | boolean
	min?: number
	max?: number
	maxLength?: number
	placeholder?: string
	help?: string
	hidden?: boolean
	showWhen?: Record<string, unknown>
}

export type StorageSection = {
	id: string
	label?: string
	fields?: StorageField[]
}

export type StorageProvider = {
	id: string
	label: string
	order?: number
	capabilities?: Record<string, boolean | string>
	oauth?: {
		enabled?: boolean
		startAction?: string
		revokeAction?: string
		redirectUrl?: string
		buttonLabel?: string
		connectedLabel?: string
		requiredFields?: string[]
		revocationSupported?: boolean
		credentialsConfigured?: boolean
	}
	settings?: {
		enable_label?: string
		sections?: StorageSection[]
	}
	ajax?: Record<string, string>
}

type Manifest = {
	version?: number
	providers?: Record<string, StorageProvider>
}

function configWithManifest(config?: any): any {
	return config || (window as any).AnibasFMSettings || (window as any).AnibasFM || {}
}

export function getStorageManifest(config?: any): Manifest {
	return configWithManifest(config).storageManifest || { providers: {} }
}

export function getRemoteProviders(config?: any): StorageProvider[] {
	const providers = getStorageManifest(config).providers || {}
	return Object.entries(providers)
		.map(([id, provider]) => ({ ...provider, id: provider.id || id }))
		.sort((a, b) => (a.order ?? 100) - (b.order ?? 100))
}

export function getStorageLabel(id: string, config?: any, localLabel = 'Local Files'): string {
	if (id === 'local') return localLabel
	const provider = getRemoteProviders(config).find((item) => item.id === id)
	return provider?.label || id
}

export function flattenProviderFields(provider: StorageProvider): StorageField[] {
	return (provider.settings?.sections || []).flatMap((section) => section.fields || [])
}

export function getProviderDefaultValues(provider: StorageProvider): Record<string, unknown> {
	const values: Record<string, unknown> = {}
	for (const field of flattenProviderFields(provider)) {
		if (!field.key) continue
		if (field.default !== undefined) {
			values[field.key] = field.default
		} else if (field.type === 'checkbox' || field.type === 'toggle' || field.type === 'boolean') {
			values[field.key] = false
		} else {
			values[field.key] = ''
		}
	}
	return values
}

export function getEnabledRemoteStorages(summary: any, config?: any): Array<{ id: string; name: string; enabled: boolean }> {
	return getRemoteProviders(config)
		.map((provider) => ({
			id: provider.id,
			name: summary?.[provider.id]?.label || provider.label,
			enabled: Boolean(summary?.[provider.id]?.enabled),
		}))
		.filter((provider) => provider.enabled)
}
