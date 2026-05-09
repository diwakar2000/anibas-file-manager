export type FileItem = {
    name: string;
    path: string;
    is_folder: boolean;
    storage_id?: string;
    ui_key?: string;
    filename?: string;
    filesize?: number;
    file_type?: string;
    has_children?: boolean;
    last_modified?: number;
    permission?: number;
};

export type DirectoryResponse = {
    path: string;
    page: number;
    has_more: boolean;
    items: Record<string, FileItem> | FileItem[];
    total_items: number;
    page_size: number;
};

export type ArchiveJob = {
    id: string;
    source: string;   // relative source path used to resume
    source_name?: string; // display name (basename)
    output: string;   // output filename (e.g. "test.zip")
    format: 'zip' | 'tar' | 'anfm';
    started_at: number; // unix timestamp
    storage?: string; // storage key (e.g. 'local', 'ftp', 's3')
};

export type Job = {
    id: string;
    action: 'copy' | 'move' | 'delete' | 'rename' | 'empty';
    status: 'processing' | 'completed' | 'failed';
    source: string;
    destination: string;
    operation_mode?: 'trash' | 'delete' | null;
    current_phase?: string | null;
    processed: number;
    failed_count: number;
    current_file: string;
    type: string | null;
    progress: number;
    current_chunk: number;
    total_chunks: number;
    file_name: string;
    child_job_ids?: string[];
    // Per-file and overall progress
    total_files?: number;
    current_file_bytes?: number;
    current_file_size?: number;
};
