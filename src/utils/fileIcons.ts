import {
  getIconForFile,
  getIconForFolder,
  getIconForOpenFolder,
  DEFAULT_FILE,
  DEFAULT_FOLDER,
  DEFAULT_FOLDER_OPENED,
} from 'vscode-icons-js'

export type FileIconType = string

/**
 * Get the appropriate icon filename for a file or folder.
 * @param filename - The filename (with or without extension).
 * @param isFolder - Whether this entry is a folder.
 * @param isOpen - For folders, whether it's opened.
 * @returns The icon filename (e.g., "javascript.svg").
 */
export function getFileIcon(
  filename: string,
  isFolder: boolean = false,
  isOpen: boolean = false
): FileIconType {
  if (isFolder) {
    if (isOpen) {
      return getIconForOpenFolder(filename) || DEFAULT_FOLDER_OPENED
    }
    return getIconForFolder(filename) || DEFAULT_FOLDER
  }

  return getIconForFile(filename) || DEFAULT_FILE
}

/**
 * Get recommended icon size (px) based on file type.
 * @param filename - The filename.
 * @returns Icon size in pixels.
 */
export function getIconSize(filename: string): number {
  const ext = filename.toLowerCase().split('.').pop() ?? ''
  const largeExts = new Set(['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'pdf', 'doc', 'docx'])
  return largeExts.has(ext) ? 20 : 16
}

/**
 * Build the full icon URL/path.
 * @param iconName - The icon filename from getFileIcon.
 * @param baseUrl - Base URL where icons are served (default: vscode-icons-js icons path).
 * @returns Full URL to the icon SVG.
 */
export function getIconUrl(iconName: string, baseUrl?: string): string {
  if (baseUrl) {
    return `${baseUrl}/${iconName}`
  }
  // vscode-icons-js icons are in the package's icons folder
  return `https://raw.githubusercontent.com/vscode-icons/vscode-icons/master/icons/${iconName}`
}