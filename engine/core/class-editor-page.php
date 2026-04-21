<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * Registers the hidden editor admin page and handles the full standalone
 * page render before WordPress outputs any admin chrome.
 * Helper logic for the editor.
 */
class EditorPage
{


    // ── Static helpers ────────────────────────────────────────────────────────

    public static function session_key(int $user_id, string $path, string $storage): string
    {
        return 'anibas_fm_edit_session_' . $user_id . '_' . md5($path . '|' . $storage);
    }

    public static function is_editable_file(string $path): bool
    {
        $path = trim($path);
        if ($path === '') {
            return false;
        }

        $base = basename($path);
        if ($base === '' || $base === '.' || $base === '..') {
            return false;
        }

        if (in_array($base, ANIBAS_FM_EDITOR_DOTFILES, true)) {
            return true;
        }

        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if ($ext === '') {
            return false;
        }

        return in_array($ext, ANIBAS_FM_EDITOR_EXTENSIONS, true);
    }
}
