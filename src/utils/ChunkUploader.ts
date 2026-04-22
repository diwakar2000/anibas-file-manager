import { checkFmTokenError } from "../services/fileApi";

type UploadOptions = {
  url: string;
  file: File;
  chunkSize?: number;
  onProgress?: (percent: number) => void;
  onComplete?: (response: any) => void;
  onError?: (error: string) => void;
  headers?: Record<string, string>;
  params?: Record<string, any>;
};

export class ChunkUploader {
  private file: File;
  private url: string;
  private chunkSize: number;
  private onProgress?: (percent: number) => void;
  private onComplete?: (response: any) => void;
  private onError?: (error: string) => void;
  private headers: Record<string, string>;
  private params: Record<string, any>;
  private aborted = false;
  private uploadToken = '';

  constructor(options: UploadOptions) {
    this.file = options.file;
    this.url = options.url;
    this.chunkSize = options.chunkSize || 1024 * 1024;
    this.onProgress = options.onProgress;
    this.onComplete = options.onComplete;
    this.onError = options.onError;
    this.headers = options.headers || {};
    this.params = options.params || {};
  }

  async start() {
    try {
      await this.initUpload();
    } catch (error) {
      this.onError?.(error instanceof Error ? error.message : 'Failed to initialize upload');
      return;
    }

    const totalChunks = Math.ceil(this.file.size / this.chunkSize);
    let lastResult: any = null;
    
    for (let i = 0; i < totalChunks; i++) {
      if (this.aborted) break;

      const start = i * this.chunkSize;
      const end = Math.min(start + this.chunkSize, this.file.size);
      const chunk = this.file.slice(start, end);

      try {
        lastResult = await this.uploadChunk(chunk, i, totalChunks);
        const percent = Math.round(((i + 1) / totalChunks) * 100);
        this.onProgress?.(percent);
      } catch (error) {
        this.onError?.(error instanceof Error ? error.message : 'Upload failed');
        return;
      }
    }

    if (!this.aborted) {
      this.onComplete?.(lastResult || { success: true });
    }
  }

  private async initUpload(): Promise<void> {
    const formData = new FormData();
    formData.append('action', 'anibas_fm_init_upload');
    formData.append('file_name', this.file.name);
    formData.append('file_size', this.file.size.toString());
    
    Object.entries(this.params).forEach(([key, value]) => {
      if (key !== 'action') {
        formData.append(key, value);
      }
    });

    const response = await fetch(this.url, {
      method: 'POST',
      headers: this.headers,
      body: formData,
    });

    const result = await response.json();
    checkFmTokenError(result);
    
    if (!result.success) {
      const error = new Error(result.data?.message || result.data?.error || 'Failed to initialize upload');
      (error as any).code = result.data?.error;
      (error as any).conflict = result.data?.conflict;
      (error as any).existingFile = result.data?.existing_file;
      throw error;
    }

    this.uploadToken = result.data.upload_token;
    if (result.data.chunk_size) {
      this.chunkSize = result.data.chunk_size;
    }
  }

  private async uploadChunk(chunk: Blob, index: number, total: number): Promise<any> {
    const formData = new FormData();
    formData.append('chunk', chunk);
    formData.append('chunk_index', index.toString());
    formData.append('total_chunks', total.toString());
    formData.append('file_name', this.file.name);
    formData.append('file_size', this.file.size.toString());
    formData.append('upload_token', this.uploadToken);

    Object.entries(this.params).forEach(([key, value]) => {
      if (value != null) formData.append(key, value);
    });

    const response = await fetch(this.url, {
      method: 'POST',
      headers: this.headers,
      body: formData,
    });

    if (!response.ok) {
      throw new Error(`Chunk ${index} upload failed`);
    }

    const result = await response.json();
    checkFmTokenError(result);

    if (!result.success) {
      throw new Error(result.data?.message || result.data?.error || `Chunk ${index} upload failed`);
    }
    
    return result.data;
  }

  abort() {
    this.aborted = true;
  }
}
