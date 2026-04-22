// ─── FM session token ─────────────────────────────────────────────────────────
// Stored in memory only (or sessionStorage when fmRefreshRequired is false).
// Set once after password verification; appended to every FM request automatically.

let _fmToken: string | null = null
let _onFmTokenRequired: (() => void) | null = null

export function setFmToken(token: string | null): void {
	_fmToken = token
}

export function getFmToken(): string | null {
	return _fmToken
}

export function setFmTokenRequiredHandler(handler: () => void): void {
	_onFmTokenRequired = handler
}

/** Appends fm_token to FormData when a token is available. */
function appendFmToken(formData: FormData): void {
	if (_fmToken) formData.append('fm_token', _fmToken)
}

/** Appends fm_token to URLSearchParams when a token is available. */
function appendFmTokenToUrl(url: URL): void {
	if (_fmToken) url.searchParams.set('fm_token', _fmToken)
}

/** Checks json error for FMTokenRequired and fires the handler if so. */
export function checkFmTokenError(json: any): void {
	if (json.data?.error === 'FMTokenRequired') {
		_fmToken = null
		_onFmTokenRequired?.()
	}
}

export async function verifyFmPassword(password: string): Promise<string> {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append('action', cfg.actions.verifyFmPassword)
	formData.append('nonce', cfg.fmNonce)
	formData.append('password', password)

	const res = await fetch(cfg.ajaxURL, { method: 'POST', body: formData })
	const json = await res.json()

	if (!json.success) {
		throw new Error(json.data || 'Invalid password')
	}
	return json.data.token as string
}

export async function checkFmAuth(token: string): Promise<boolean> {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append('action', cfg.actions.checkFmAuth)
	formData.append('nonce', cfg.fmNonce)
	formData.append('token', token)

	const res = await fetch(cfg.ajaxURL, { method: 'POST', body: formData })
	const json = await res.json()
	return json.success === true
}

// ─────────────────────────────────────────────────────────────────────────────

interface FetchParams {
	path: string
	page?: number
	storage?: string
}

export async function fetchNode(params: FetchParams) {
	const cfg = (window as any).AnibasFM

	const url = new URL(cfg.ajaxURL)
	url.searchParams.set("action", cfg.actions.getFileList)
	url.searchParams.set("nonce", cfg.listNonce)
	url.searchParams.set("dir", params.path)
	url.searchParams.set("page", String(params.page ?? 1))
	url.searchParams.set("storage", params.storage ?? "local")
	appendFmTokenToUrl(url)

	const res = await fetch(url.toString())
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		const errorType = json.data?.error
		throw new Error(JSON.stringify({ message: json.data?.message ?? "Fetch failed", errorType }))
	}
	return json.data
}

export async function createFolder(parent: string, name: string, storage?: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.createFolder)
	formData.append("nonce", cfg.createNonce)
	formData.append("parent", parent)
	formData.append("name", name)
	if (storage) formData.append("storage", storage)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		const error = json.data?.message ?? json.data?.error ?? "Failed to create folder"
		if (error.includes('failed after 3 attempts')) {
			throw new Error(`${error} Please refresh the page and try again.`)
		}
		throw new Error(error)
	}
	return json.data
}

export async function deleteFile(path: string, token?: string, deleteToken?: string, storage?: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.deleteFile)
	formData.append("nonce", cfg.deleteNonce)
	formData.append("path", path)
	if (token) formData.append("token", token)
	if (deleteToken) formData.append("delete_token", deleteToken)
	if (storage) formData.append("storage", storage)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to delete")
	}
	return json.data
}

export async function requestDeleteToken(path: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.requestDeleteToken)
	formData.append("nonce", cfg.deleteNonce)
	formData.append("path", path)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to request delete token")
	}
	return json.data.delete_token
}

export async function verifyDeletePassword(password: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.verifyDeletePassword)
	formData.append("nonce", cfg.deleteNonce)
	formData.append("password", password)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error("Invalid password")
	}
	return json.data.token
}

export async function emptyFolder(path: string, token?: string, storage?: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.emptyFolder)
	formData.append("nonce", cfg.deleteNonce)
	formData.append("path", path)
	if (token) formData.append("token", token)
	if (storage) formData.append("storage", storage)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to empty folder")
	}
	return json.data
}

export async function transferFile(source: string, destination: string, actionType: 'copy' | 'move' = 'copy', conflictMode: string = 'skip', sourceStorage?: string, destStorage?: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.transferFile)
	formData.append("nonce", cfg.createNonce)
	formData.append("source", source)
	formData.append("destination", destination)
	formData.append("action_type", actionType)
	formData.append("conflict_mode", conflictMode)
	if (sourceStorage && destStorage && sourceStorage !== destStorage) {
		// Cross-storage transfer
		formData.append("source_storage", sourceStorage)
		formData.append("dest_storage", destStorage)
	} else {
		// Same-storage (backward compat)
		formData.append("storage", sourceStorage || destStorage || "local")
	}
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		const label = actionType === 'move' ? 'Move' : 'Copy'
		const error = json.data?.message ?? json.data?.error ?? `Failed to ${actionType}`
		const errorCode = json.data?.error_code
		console.error(`${label} failed:`, { source, destination, error, errorCode, response: json })
		const enhancedError = new Error(error)
		;(enhancedError as any).errorCode = errorCode
		throw enhancedError
	}

	return json.data
}

export async function checkConflict(source: string, destination: string, storage?: string) {
	const cfg = (window as any).AnibasFM

	const url = new URL(cfg.ajaxURL)
	url.searchParams.set("action", cfg.actions.checkConflict)
	url.searchParams.set("nonce", cfg.listNonce)
	url.searchParams.set("source", source)
	url.searchParams.set("destination", destination)
	if (storage) url.searchParams.set("storage", storage)
	appendFmTokenToUrl(url)

	const res = await fetch(url.toString())
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to check conflict")
	}
	return json.data
}

export async function getJobStatus(jobId: string) {
	const cfg = (window as any).AnibasFM

	const url = new URL(cfg.ajaxURL)
	url.searchParams.set("action", cfg.actions.jobStatus)
	url.searchParams.set("nonce", cfg.listNonce)
	url.searchParams.set("job_id", jobId)
	appendFmTokenToUrl(url)

	const res = await fetch(url.toString())
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to get job status")
	}
	return json.data
}

export async function cancelJob(jobId: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.cancelJob)
	formData.append("nonce", cfg.listNonce)
	formData.append("job_id", jobId)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to cancel job")
	}
	return json.data
}

export async function archivePrescan(source: string, storage?: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.archiveCreate)
	formData.append("nonce", cfg.createNonce)
	formData.append("source", source)
	formData.append("format", "zip") // ignored for prescan
	formData.append("phase", "prescan")
	if (storage) formData.append("storage", storage)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Prescan failed")
	}
	return json.data as { phase: string; total: number; total_size: number; max_file_size: number; max_file_name: string }
}

export async function archiveCreate(
	source: string,
	format: 'zip' | 'anfm' | 'tar',
	phase: 'scan' | 'run' | 'cleanup',
	password?: string,
	conflictMode?: 'overwrite' | 'rename',
	jobId?: string,
	storage?: string,
) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.archiveCreate)
	formData.append("nonce", cfg.createNonce)
	formData.append("source", source)
	formData.append("format", format)
	formData.append("phase", phase)
	if (password)      formData.append("password", password)
	if (conflictMode)  formData.append("conflict_mode", conflictMode)
	if (jobId)         formData.append("job_id", jobId)
	if (storage)       formData.append("storage", storage)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Archive creation failed")
	}
	return json.data
}

export async function cancelArchiveJob(jobId: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.cancelArchiveJob)
	formData.append("nonce", cfg.createNonce)
	formData.append("job_id", jobId)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to cancel archive job")
	}
	return json.data
}

export async function archiveCheck(path: string, storage?: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.archiveCheck)
	formData.append("nonce", cfg.listNonce)
	formData.append("path", path)
	if (storage) formData.append("storage", storage)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Archive check failed")
	}
	return json.data
}

export async function archiveRestore(path: string, phase: 'init' | 'run' | 'cleanup', password?: string, storage?: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.archiveRestore)
	formData.append("nonce", cfg.createNonce)
	formData.append("path", path)
	formData.append("phase", phase)
	if (password) formData.append("password", password)
	if (storage)  formData.append("storage", storage)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Archive extraction failed")
	}
	return json.data
}

export async function duplicateFile(path: string, storage?: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append('action', cfg.actions.duplicateFile)
	formData.append('nonce', cfg.createNonce)
	formData.append('path', path)
	if (storage) formData.append('storage', storage)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: 'POST', body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? 'Duplicate failed')
	}
	return json.data as { job_id?: string; destination?: string; message?: string }
}

export async function renameFile(path: string, newName: string, storage?: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append('action', cfg.actions.renameFile)
	formData.append('nonce', cfg.createNonce)
	formData.append('path', path)
	formData.append('new_name', newName)
	if (storage) formData.append('storage', storage)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: 'POST', body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? 'Rename failed')
	}
	return json.data
}

export function getDownloadUrl(path: string, storageName: string = 'local'): string {
	const cfg = (window as any).AnibasFM
	const url = new URL(cfg.ajaxURL)
	url.searchParams.set('action', cfg.actions.downloadFile)
	url.searchParams.set('path', path)
	url.searchParams.set('storage', storageName)
	url.searchParams.set('nonce', cfg.listNonce)
	appendFmTokenToUrl(url)
	return url.toString()
}

export async function getPreviewContent(path: string, storageName: string = 'local'): Promise<string> {
	const cfg = (window as any).AnibasFM
	const url = new URL(cfg.ajaxURL)
	url.searchParams.set('action', cfg.actions.previewFile)
	url.searchParams.set('path', path)
	url.searchParams.set('storage', storageName)
	url.searchParams.set('nonce', cfg.listNonce)
	appendFmTokenToUrl(url)

	const res = await fetch(url.toString())
	const json = await res.json()
	checkFmTokenError(json)

	if (!json.success) {
		throw new Error(json.data?.message || 'Failed to preview file')
	}

	return json.data.content
}

export async function getFileDetails(path: string, storageName: string = 'local'): Promise<Record<string, any>> {
	const cfg = (window as any).AnibasFM
	const url = new URL(cfg.ajaxURL)
	url.searchParams.set('action', cfg.actions.getFileDetails)
	url.searchParams.set('path', path)
	url.searchParams.set('storage', storageName)
	url.searchParams.set('nonce', cfg.listNonce)
	appendFmTokenToUrl(url)

	const res = await fetch(url.toString())
	const json = await res.json()
	checkFmTokenError(json)

	if (!json.success) {
		throw new Error(json.data?.message || 'Failed to fetch file details')
	}

	return json.data.details
}

export async function checkRunningTasks() {
	const cfg = (window as any).AnibasFM

	const url = new URL(cfg.ajaxURL)
	url.searchParams.set("action", cfg.actions.checkRunningTasks)
	url.searchParams.set("nonce", cfg.listNonce)
	appendFmTokenToUrl(url)

	const res = await fetch(url.toString())
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to check running tasks")
	}
	return json.data
}

export async function listTrash() {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.listTrash || "anibas_fm_list_trash")
	formData.append("nonce", cfg.listNonce)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to list trash")
	}
	return json.data.items || []
}

export async function restoreTrash(trashName: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.restoreTrash || "anibas_fm_restore_trash")
	formData.append("nonce", cfg.deleteNonce)
	formData.append("trash_name", trashName)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to restore item")
	}
	return json.data
}

export async function emptyTrashBin() {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.emptyTrash || "anibas_fm_empty_trash")
	formData.append("nonce", cfg.deleteNonce)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to empty trash")
	}
	return json.data
}

// ─── Per-file backups (snapshot + restore, mirrors trash UX) ────────────────

export async function backupSingleFile(path: string, storage: string) {
	const cfg = (window as any).AnibasFM
	const formData = new FormData()
	formData.append("action", cfg.actions.backupSingleFile)
	formData.append("nonce", cfg.createNonce)
	formData.append("path", path)
	formData.append("storage", storage)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to back up file")
	}
	return json.data
}

export async function listFileBackups() {
	const cfg = (window as any).AnibasFM ?? (window as any).AnibasFMSettings
	const formData = new FormData()
	formData.append("action", cfg.actions.listFileBackups)
	formData.append("nonce", cfg.createNonce)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to list file backups")
	}
	return json.data.items || []
}

export async function restoreFileBackup(key: string, version: string) {
	const cfg = (window as any).AnibasFM ?? (window as any).AnibasFMSettings
	const formData = new FormData()
	formData.append("action", cfg.actions.restoreFileBackup)
	formData.append("nonce", cfg.createNonce)
	formData.append("key", key)
	formData.append("version", version)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to restore backup")
	}
	return json.data
}

export async function listSiteBackups() {
	const cfg = (window as any).AnibasFM ?? (window as any).AnibasFMSettings
	const formData = new FormData()
	formData.append("action", cfg.actions.listSiteBackups)
	formData.append("nonce", cfg.createNonce)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to list site backups")
	}
	return json.data.items || []
}

// ─── Backup ──────────────────────────────────────────────────────────────────

export async function backupStart(format: 'tar' | 'anfm' = 'tar', password?: string) {
	const cfg = (window as any).AnibasFM ?? (window as any).AnibasFMSettings
	const formData = new FormData()
	formData.append("action", cfg.actions.backupStart)
	formData.append("nonce", cfg.createNonce)
	formData.append("format", format)
	if (password) formData.append("password", password)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to start backup")
	}
	return json.data as { job_id: string; output: string; info: { total: number; total_size: number } }
}

export async function backupPoll(jobId: string, password?: string) {
	const cfg = (window as any).AnibasFM ?? (window as any).AnibasFMSettings
	const formData = new FormData()
	formData.append("action", cfg.actions.backupPoll)
	formData.append("nonce", cfg.createNonce)
	formData.append("job_id", jobId)
	if (password) formData.append("password", password)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Backup poll failed")
	}
	return json.data as { done: boolean; progress: { current: number; total: number; percent: number; bytes_processed: number; total_size: number; phase: string } }
}

export async function backupCancel(jobId: string) {
	const cfg = (window as any).AnibasFM ?? (window as any).AnibasFMSettings
	const formData = new FormData()
	formData.append("action", cfg.actions.backupCancel)
	formData.append("nonce", cfg.createNonce)
	formData.append("job_id", jobId)
	appendFmToken(formData)

	const res = await fetch(cfg.ajaxURL, { method: "POST", body: formData })
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to cancel backup")
	}
	return json.data
}

export async function backupStatus() {
	const cfg = (window as any).AnibasFM ?? (window as any).AnibasFMSettings
	const nonce = cfg.createNonce
	const url = new URL(cfg.ajaxURL)
	url.searchParams.set("action", cfg.actions.backupStatus)
	url.searchParams.set("nonce", nonce)
	appendFmTokenToUrl(url)

	const res = await fetch(url.toString())
	const json = await res.json()

	if (!json.success) {
		checkFmTokenError(json)
		throw new Error(json.data?.message ?? json.data?.error ?? "Failed to check backup status")
	}
	return json.data as { running: boolean; job_id?: string; format?: string; output?: string; started_at?: number; progress?: any }
}

