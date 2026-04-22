<script lang="ts">
	import { fileStore } from "../../stores/fileStore.svelte";
	import type { Job, ArchiveJob } from "../../types/files";

	function getJobsArray() {
		return Object.values(fileStore.activeJobs);
	}

	function formatAge(started_at: number): string {
		const mins = Math.floor((Date.now() / 1000 - started_at) / 60);
		if (mins < 1) return 'just now';
		if (mins === 1) return '1 min ago';
		return `${mins} mins ago`;
	}

	let resumingId = $state<string | null>(null);

	async function handleResume(job: ArchiveJob) {
		resumingId = job.id;
		await fileStore.resumeArchiveJob(job);
		resumingId = null;
	}

	function getJobStatusText(job: Job): string {
		if (job.type === 'assembly') {
			return `Assembling file...`;
		}

		// Per-file progress for background transfer jobs
		if (job.current_file && job.current_phase === 'transfer') {
			const fileProgress = job.current_file_size
				? `${formatBytes(job.current_file_bytes || 0)} / ${formatBytes(job.current_file_size)}`
				: '';
			return fileProgress
				? `${job.current_file} — ${fileProgress}`
				: job.current_file;
		}

		// Delete phase: show current file being deleted
		if (job.current_file && job.current_phase === 'delete') {
			return `Deleting: ${job.current_file}`;
		}

		if (job.current_phase === 'list' && job.action === 'delete') {
			return 'Listing files...';
		}

		if (job.current_phase) {
			return `Phase: ${job.current_phase}`;
		}

		if (job.current_file) {
			return `Processing: ${job.current_file}`;
		}

		return 'Processing...';
	}

	function formatBytes(bytes: number): string {
		if (bytes === 0) return '0 B';
		const k = 1024;
		const sizes = ['B', 'KB', 'MB', 'GB'];
		const i = Math.floor(Math.log(bytes) / Math.log(k));
		return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
	}

	function getJobProgress(job: Job): number {
		// Per-file progress for background transfers
		if (job.current_file_size && job.current_file_bytes) {
			return Math.min(100, Math.round((job.current_file_bytes / job.current_file_size) * 100));
		}
		// Overall progress for delete jobs (processed + failed / total)
		if (job.action === 'delete' && job.total_files) {
			const done = (job.processed || 0) + (job.failed_count || 0);
			return Math.min(100, Math.round((done / job.total_files) * 100));
		}
		return job.progress || 0;
	}

	function showProgressBar(job: Job): boolean {
		return job.type === 'assembly'
			|| (!!job.current_file_size && job.current_file_size > 0)
			|| (job.action === 'delete' && !!job.total_files);
	}

	function getFilesCounter(job: Job): string {
		if (!job.total_files) return '';
		const done = (job.processed || 0) + (job.failed_count || 0);
		return `${done} / ${job.total_files} files`;
	}
</script>

{#if fileStore.archiveJobs.length > 0}
	<div class="statusbar">
		{#each fileStore.archiveJobs as job (job.id)}
			<div class="job-status">
				<div class="job-info">
					<span class="job-action">🗜 Archiving</span>
					<span class="job-paths">{job.source} → {job.output}</span>
				</div>
				<div class="job-status-content">
					<div class="job-status-text">
						<span class="status-text archive-status">
							Interrupted {formatAge(job.started_at)} — state preserved on server
						</span>
					</div>
				</div>
				<button
					class="resume-btn"
					onclick={() => handleResume(job)}
					disabled={resumingId === job.id}
					title="Resume archive creation"
				>
					{resumingId === job.id ? '...' : '▶ Resume'}
				</button>
				<button
					class="cancel-btn"
					onclick={() => fileStore.cancelArchiveJob(job.id)}
					disabled={resumingId === job.id}
					title="Cancel and discard partial archive"
				>
					✖
				</button>
			</div>
		{/each}
	</div>
{/if}

{#if getJobsArray().length > 0}
	<div class="statusbar">
		{#each getJobsArray() as job}
			<div class="job-status">
				<div class="job-info">
					<span class="job-action">
						{job.action === 'delete' ? '🗑 Deleting' : job.action === 'copy' ? '📋 Copying' : job.action === 'rename' ? '✏️ Renaming' : '📦 Moving'}
					</span>
					<span class="job-paths">
						{job.source ? job.source.split('/').pop() : job.file_name || 'Unknown'}
						{#if job.action !== 'delete' && job.action !== 'rename'}
							→
							{job.destination ? job.destination.split('/').pop() : 'Destination'}
						{/if}
					</span>
				</div>
				<div class="job-status-content">
					<div class="job-status-text">
						<span class="status-text">
							{getJobStatusText(job)}
						</span>
						<span class="progress-meta">
							{#if getFilesCounter(job)}
								<span class="files-counter">{getFilesCounter(job)}</span>
							{/if}
							{#if showProgressBar(job)}
								<span class="progress-percent">
									{getJobProgress(job)}%
								</span>
							{/if}
						</span>
					</div>
					{#if showProgressBar(job)}
						<div class="progress-bar">
							<div
								class="progress-fill"
								style="width: {getJobProgress(job)}%"
							></div>
						</div>
					{/if}
				</div>
				<button
					class="cancel-btn"
					onclick={() => fileStore.cancelJob(job.id)}
					title="Cancel operation"
				>
					✖
				</button>
			</div>
		{/each}
	</div>
{/if}

<style>
	.statusbar {
		background: #f8f9fa;
		border-bottom: 1px solid #e0e0e0;
		padding: 8px 15px;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.job-status {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 6px 0;
	}

	.job-info {
		display: flex;
		flex-direction: column;
		min-width: 200px;
		flex-shrink: 0;
	}

	.job-action {
		font-weight: 600;
		font-size: 12px;
		color: #2271b1;
	}

	.job-paths {
		font-size: 11px;
		color: #666;
		margin-top: 2px;
	}

	.job-status-content {
		flex: 1;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	.job-status-text {
		display: flex;
		justify-content: space-between;
		align-items: center;
	}

	.status-text {
		font-size: 12px;
		color: #666;
		font-style: italic;
	}

	.progress-meta {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-shrink: 0;
	}

	.files-counter {
		font-size: 11px;
		color: #50575e;
		font-weight: 500;
		white-space: nowrap;
	}

	.progress-percent {
		font-size: 11px;
		color: #2271b1;
		font-weight: 600;
	}

	.progress-bar {
		width: 100%;
		height: 16px;
		background: #f0f0f0;
		border-radius: 8px;
		overflow: hidden;
	}

	.progress-fill {
		height: 100%;
		background: linear-gradient(90deg, #2271b1, #135e96);
		transition: width 0.3s ease;
		border-radius: 8px;
	}

	.cancel-btn {
		background: #dc3232;
		color: white;
		border: none;
		border-radius: 3px;
		padding: 4px 8px;
		font-size: 11px;
		cursor: pointer;
		flex-shrink: 0;
	}

	.cancel-btn:hover {
		background: #a00;
	}

	.cancel-btn:active {
		transform: scale(0.95);
	}

	.resume-btn {
		background: #2271b1;
		color: white;
		border: none;
		border-radius: 3px;
		padding: 4px 10px;
		font-size: 11px;
		cursor: pointer;
		flex-shrink: 0;
		font-weight: 600;
	}

	.resume-btn:hover:not(:disabled) {
		background: #135e96;
	}

	.resume-btn:disabled {
		opacity: 0.6;
		cursor: not-allowed;
	}

	.archive-status {
		color: #805700;
	}
</style>
