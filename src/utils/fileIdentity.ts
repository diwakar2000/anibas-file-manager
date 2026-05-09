import type { FileItem } from "../types/files";

export function hash8(value: string): string {
    let hash = 0x811c9dc5;
    for (let i = 0; i < value.length; i++) {
        hash ^= value.charCodeAt(i);
        hash = Math.imul(hash, 0x01000193);
    }
    return (hash >>> 0).toString(16).padStart(8, "0").slice(0, 8);
}

export function fileKeyBase(file: FileItem, index: number, namespace = ""): string {
    const base = String(file.ui_key || file.storage_id || file.path || file.name || `item-${index}`);
    return namespace ? `${namespace}:${base}` : base;
}

export function withUniqueFileKeys(files: FileItem[], namespace = ""): FileItem[] {
    const seen = new Map<string, number>();
    return files.map((file, index) => {
        const base = fileKeyBase(file, index, namespace);
        const count = seen.get(base) ?? 0;
        seen.set(base, count + 1);

        const uiKey = count === 0
            ? base
            : `${base}:${hash8(`${base}|${count}|${file.name}|${file.filesize ?? ""}|${file.last_modified ?? ""}|${index}`)}`;

        return file.ui_key === uiKey ? file : { ...file, ui_key: uiKey };
    });
}
