import type { FileItem } from "../types/files";

function getEditableSets(): { extensions: Set<string>; dotfiles: Set<string> } {
    const cfg = (window as any).AnibasFM;
    return {
        extensions: new Set<string>(cfg?.editorExtensions ?? []),
        dotfiles:   new Set<string>(cfg?.editorDotfiles   ?? []),
    };
}

export function isEditable(f: FileItem): boolean {
    if (f.is_folder) return false;
    const { extensions, dotfiles } = getEditableSets();
    if (dotfiles.has(f.name)) return true;
    const ext = f.name.split(".").pop()?.toLowerCase() ?? "";
    if (ext === "") return true;
    return extensions.has(ext);
}
