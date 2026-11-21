<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · FLASH ALERT PHP HELPER (assets/js/alert.js)
 * ==============================================================================
 */

if (!function_exists('ilmuna_flash_push')) {
    function ilmuna_flash_push(array $item)
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION['_ilmuna_flash'][] = $item;
    }

    function ilmuna_toast($type, $title, $message, $duration = 4500)
    {
        ilmuna_flash_push([
            'mode'     => 'toast',
            'type'     => (string)$type,
            'title'    => (string)$title,
            'message'  => (string)$message,
            'duration' => (int)$duration,
        ]);
    }

    function ilmuna_alert($type, $title, $message, $confirmText = 'Mengerti')
    {
        ilmuna_flash_push([
            'mode'        => 'alert',
            'type'        => (string)$type,
            'title'       => (string)$title,
            'message'     => (string)$message,
            'confirmText' => (string)$confirmText,
        ]);
    }

    function ilmuna_render_alerts()
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['_ilmuna_flash'])) {
            return;
        }
        $items = $_SESSION['_ilmuna_flash'];
        unset($_SESSION['_ilmuna_flash']);

        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        echo "<script>\nwindow.addEventListener('DOMContentLoaded',function(){\n  if(!window.Ilmuna)return;\n  var E=Ilmuna.escape;\n";
        foreach ($items as $it) {
            $type  = json_encode((string)($it['type'] ?? 'info'), $flags);
            $title = json_encode((string)($it['title'] ?? ''), $flags);
            $msg   = json_encode((string)($it['message'] ?? ''), $flags);
            if (($it['mode'] ?? 'toast') === 'alert') {
                $btn = json_encode((string)($it['confirmText'] ?? 'Mengerti'), $flags);
                echo "  Ilmuna.alert({$type}, E({$title}), E({$msg}), {$btn});\n";
            } else {
                $dur = (int)($it['duration'] ?? 4500);
                echo "  Ilmuna.toast({$type}, E({$title}), E({$msg}), {$dur});\n";
            }
        }
        echo "});\n</script>\n";
    }
}
