<?php
defined('ABSPATH') || exit;

class JB_Nextcloud {

    private string $base_url;
    private string $username;
    private string $app_password;
    private string $base_folder;

    public function __construct() {
        $this->base_url     = rtrim(get_option('jb_nc_url', ''), '/');
        $this->username     = get_option('jb_nc_user', '');
        $this->app_password = get_option('jb_nc_password', '');
        $this->base_folder  = trim(get_option('jb_nc_folder', 'JuFo-Buchhaltung'), '/');
    }

    private function webdav_url(string $path = ''): string {
        return $this->base_url
            . '/remote.php/dav/files/'
            . rawurlencode($this->username) . '/'
            . $this->base_folder
            . ($path ? '/' . ltrim($path, '/') : '');
    }

    private function request(string $method, string $path, $body = null, array $headers = []): array {
        $url = $this->webdav_url($path);
        $args = [
            'method'  => $method,
            'headers' => array_merge([
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->app_password),
            ], $headers),
            'timeout' => 30,
            'sslverify' => true,
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        return [
            'success' => in_array($code, [200, 201, 204, 207]),
            'code'    => $code,
            'body'    => wp_remote_retrieve_body($response),
        ];
    }

    public function ensure_folder(string $path): bool {
        $parts = explode('/', trim($path, '/'));
        $built = '';
        foreach ($parts as $part) {
            $built .= '/' . $part;
            $r = $this->request('MKCOL', $built);
            // 405 = already exists, that's fine
            if (!$r['success'] && ($r['code'] ?? 0) !== 405) {
                return false;
            }
        }
        return true;
    }

    /**
     * Beleg hochladen.
     * Gibt Nextcloud-Pfad zurück oder WP_Error.
     */
    public function upload_beleg(string $local_path, string $nc_path): string|WP_Error {
        if (!file_exists($local_path)) {
            return new WP_Error('file_missing', 'Lokale Datei nicht gefunden.');
        }

        $folder = dirname($nc_path);
        $this->ensure_folder($folder);

        $body = file_get_contents($local_path);
        $mime = mime_content_type($local_path) ?: 'application/octet-stream';

        $r = $this->request('PUT', $nc_path, $body, ['Content-Type' => $mime]);
        if (!$r['success']) {
            return new WP_Error('upload_failed',
                'Nextcloud Upload fehlgeschlagen (HTTP ' . ($r['code'] ?? '?') . ')');
        }
        return $nc_path;
    }

    /**
     * Direkten Download-Link erzeugen (via Nextcloud Share API).
     * Falls kein Share möglich, gibt WebDAV-URL zurück (nur intern nutzbar).
     */
    public function get_download_url(string $nc_path): string {
        // Erstelle temporären Share-Link
        $share_url = $this->base_url . '/ocs/v2.php/apps/files_sharing/api/v1/shares';
        $response = wp_remote_post($share_url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->app_password),
                'OCS-APIRequest' => 'true',
            ],
            'body' => [
                'path'        => '/' . $this->base_folder . '/' . ltrim($nc_path, '/'),
                'shareType'   => 3,  // Public link
                'permissions' => 1,  // Read only
            ],
            'timeout' => 15,
        ]);

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            if (preg_match('/<url>(.*?)<\/url>/', $body, $m)) {
                return $m[1] . '/download';
            }
        }

        // Fallback: Admin-seitiger Download via AJAX
        return admin_url('admin-ajax.php?action=jb_download_beleg&path=' . urlencode($nc_path) . '&nonce=' . wp_create_nonce('jb_download'));
    }

    /**
     * Datei herunterladen und an Browser senden.
     */
    public function stream_to_browser(string $nc_path, string $filename): void {
        $r = $this->request('GET', $nc_path);
        if (!$r['success'] || empty($r['body'])) {
            wp_die('Datei nicht gefunden.');
        }
        $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = match($ext) {
            'pdf'  => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            default => 'application/octet-stream',
        };
        header("Content-Type: $mime");
        header('Content-Disposition: inline; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . strlen($r['body']));
        echo $r['body'];
        exit;
    }

    public function is_configured(): bool {
        return !empty($this->base_url) && !empty($this->username) && !empty($this->app_password);
    }

    public function test_connection(): array {
        if (!$this->is_configured()) {
            return ['success' => false, 'message' => 'Nextcloud nicht konfiguriert.'];
        }
        $r = $this->request('PROPFIND', '', null, ['Depth' => '0']);
        if ($r['success']) {
            return ['success' => true, 'message' => 'Verbindung erfolgreich.'];
        }
        return ['success' => false, 'message' => 'Verbindung fehlgeschlagen (HTTP ' . ($r['code'] ?? '?') . ').'];
    }
}

// Singleton
function jb_nc(): JB_Nextcloud {
    static $instance = null;
    if ($instance === null) $instance = new JB_Nextcloud();
    return $instance;
}

/**
 * Auslage hochladen: validiert, lädt in Temp-Dir, pusht zu Nextcloud.
 * @return string Nextcloud-Pfad oder WP_Error
 */
function jb_upload_beleg(array $file, int $auslage_id, string $datum, int $user_id): string|WP_Error {
    $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/heic', 'image/webp'];
    $max_size = 10 * 1024 * 1024; // 10 MB

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return new WP_Error('upload_error', 'Upload-Fehler: ' . $file['error']);
    }
    if ($file['size'] > $max_size) {
        return new WP_Error('too_large', 'Datei zu groß (max. 10 MB).');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed_types)) {
        return new WP_Error('wrong_type', 'Nur PDF, JPG, PNG erlaubt.');
    }

    $user    = get_userdata($user_id);
    $uname   = sanitize_file_name($user->user_login ?? 'unbekannt');
    $year    = date('Y', strtotime($datum));
    $ext     = match($mime) {
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        default           => 'jpg',
    };

    $nc_path = "Belege/{$year}/Auslagen/{$datum}_{$uname}_#{$auslage_id}.{$ext}";

    return jb_nc()->upload_beleg($file['tmp_name'], $nc_path);
}
