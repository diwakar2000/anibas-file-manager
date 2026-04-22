declare global {
  interface Window {
    AnibasFM: {
      ajaxURL: string;
      listFileNonce: string;
      getFileListURL: string;
      pluginUrl: string;
    };
  }
}
export interface AnibasFolderItem {
  is_folder: true;
  path: string;
  permission: number;
  last_modified: number;
  files?: Record<string, AnibasFileItem | AnibasFolderItem>;
}
export interface AnibasFileItem {
  is_folder: false;
  path: string;
  permission: number;
  last_modified: number;
  filename: string;
  filesize: number;
  file_type: string;
  unique: number;
}
export type AnibasItem = AnibasFolderItem | AnibasFileItem;
export type TreeData = Record<string, AnibasItem>;
