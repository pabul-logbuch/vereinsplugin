<?php
defined('ABSPATH') || exit;

class JB_Nextcloud {

    private string $base_url;
    private string $username;
    private string $app_password;
    private string $base_folder;

    public function __construct() {
        $this->base_url     = rtrim(get_option('jb_nc_url', ''), '/');
        $this->username     = trim(get_option('jb_nc_user', ''));
        $this->app_password = get_option('jb_nc_password', '');
        $this->base_folder  = trim(get_option('jb_nc_folder', 'JuFo-Buchhaltung'), '/');
    }

    /** Jedes Pfadsegment einzeln kodieren (Leerzeichen, #, Umlaute … dürfen die URL nicht sprengen). */
    private function encode_path(string $path): string {
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));
        return implode('/', array_map('rawurlencode', $segments));
    }

    /** Voller WebDAV-URL für einen Pfad UNTERHALB des Basis-Ordners. */
    private function webdav_url(string $path = ''): string {
        $rel = $this->base_folder;
        if ($path !== '') {
            $rel .= '/' . ltrim($path, '/');
        }
        return $this->user_root() . '/' . $this->encode_path($rel);
    }

    /** WebDAV-Wurzel des Benutzers (ohne Basis-Ordner). */
    private function user_root(): string {
        return $this->base_url . '/remote.php/dav/files/' . rawurlencode($this->username);
    }

    private function request(string $method, string $url, $body = null, array $headers = []): array {
        $args = [
            'method'    => $method,
            'headers'   => array_merge([
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->app_password),
            ], $headers),
            'timeout'   => 30,
            'sslverify' => true,
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return ['success' => false, 'code' => 0, 'body' => $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        return [
            'success' => in_array($code, [200, 201, 204, 207], true),
            'code'    => $code,
            'body'    => wp_remote_retrieve_body($response),
        ];
    }

    /**
     * Legt den Basis-Ordner UND alle Segmente von $subpath an (relativ zum
     * Basis-Ordner). Gibt true zurück, wenn danach alle Ebenen existieren.
     */
    public function ensure_folder(string $subpath = ''): bool {
        $chain = trim($this->base_folder . '/' . trim($subpath, '/'), '/');
        $parts = array_values(array_filter(explode('/', $chain), 'strlen'));

        $built = '';
        foreach ($parts as $part) {
            $built = ltrim($built . '/' . $part, '/');
            $url   = $this->user_root() . '/' . $this->encode_path($built);
            $r     = $this->request('MKCOL', $url);
            // 201 = neu angelegt, 405 = existiert bereits – beides ok.
            if (!$r['success'] && !in_array($r['code'], [405, 200, 301, 302], true)) {
                // Nicht sofort abbrechen – der Ordner könnte trotzdem existieren.
                // Am Ende per PROPFIND prüfen.
            }
        }

        $check = $this->request('PROPFIND', $this->webdav_url($subpath), null, ['Depth' => '0']);
        return $check['success'];
    }

    /**
     * Beleg hochladen. $nc_path ist relativ zum Basis-Ordner.
     * Gibt den Nextcloud-Pfad zurück oder WP_Error.
     */
    public function upload_beleg(string $local_path, string $nc_path): string|WP_Error {
        if (!$this->is_configured()) {
            return new WP_Error('nc_unconfigured', 'Nextcloud ist nicht vollständig konfiguriert (URL, Benutzername oder App-Passwort fehlt).');
        }
        if (!file_exists($local_path)) {
            return new WP_Error('file_missing', 'Lokale Datei nicht gefunden.');
        }

        $folder = trim(dirname($nc_path), '/.');
        if (!$this->ensure_folder($folder)) {
            return new WP_Error(
                'nc_folder',
                sprintf(
                    'Zielordner in Nextcloud konnte nicht angelegt/gefunden werden: "%s/%s". Prüfe Benutzername (exakter Nextcloud-Login, nicht die E-Mail) und ob WebDAV erreichbar ist.',
                    $this->base_folder,
                    $folder
                )
            );
        }

        $body = file_get_contents($local_path);
        $mime = function_exists('mime_content_type') ? (mime_content_type($local_path) ?: 'application/octet-stream') : 'application/octet-stream';

        $r = $this->request('PUT', $this->webdav_url($nc_path), $body, ['Content-Type' => $mime]);
        if (!$r['success']) {
            $hint = '';
            if ($r['code'] === 401) {
                $hint = ' – Anmeldung abgelehnt: App-Passwort prüfen (Nextcloud → Einstellungen → Sicherheit → „App-Passwort erstellen").';
            } elseif ($r['code'] === 404 || $r['code'] === 409) {
                $hint = ' – Pfad nicht gefunden: Benutzername muss der exakte Nextcloud-Login sein; Basis-Ordner „' . $this->base_folder . '" muss im Dateibereich dieses Benutzers liegen.';
            } elseif ($r['code'] === 0) {
                $hint = ' – Server nicht erreichbar: ' . wp_strip_all_tags((string) $r['body']);
            }
            return new WP_Error(
                'upload_failed',
                'Nextcloud-Upload fehlgeschlagen (HTTP ' . ($r['code'] ?: '?') . ')' . $hint
            );
        }
        return $nc_path;
    }

    /**
     * Öffentlichen Download-Link erzeugen (Share API). Fallback: interner AJAX-Stream.
     */
    public function get_download_url(string $nc_path): string {
        $share_url = $this->base_url . '/ocs/v2.php/apps/files_sharing/api/v1/shares';
        $response  = wp_remote_post($share_url, [
            'headers' => [
                'Authorization'  => 'Basic ' . base64_encode($this->username . ':' . $this->app_password),
                'OCS-APIRequest' => 'true',
            ],
            'body' => [
                'path'        => '/' . $this->base_folder . '/' . ltrim($nc_path, '/'),
                'shareType'   => 3,
                'permissions' => 1,
            ],
            'timeout' => 15,
        ]);

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            if (preg_match('/<url>(.*?)<\/url>/', $body, $m)) {
                return $m[1] . '/download';
            }
        }

        return admin_url('admin-ajax.php?action=jb_download_beleg&path=' . urlencode($nc_path) . '&nonce=' . wp_create_nonce('jb_download'));
    }

    public function stream_to_browser(string $nc_path, string $filename): void {
        $r = $this->request('GET', $this->webdav_url($nc_path));
        if (!$r['success'] || $r['body'] === '') {
            wp_die('Datei nicht gefunden.');
        }
        $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf'         => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            default       => 'application/octet-stream',
        };
        header("Content-Type: $mime");
        header('Content-Disposition: inline; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . strlen($r['body']));
        echo $r['body'];
        exit;
    }

    public function is_configured(): bool {
        return $this->base_url !== '' && $this->username !== '' && $this->app_password !== '';
    }

    public function test_connection(): array {
        if (!$this->is_configured()) {
            return ['success' => false, 'message' => 'Nextcloud nicht konfiguriert (URL, Benutzername oder App-Passwort fehlt).'];
        }
        // 1. Erreichbarkeit + Login gegen die Benutzer-Wurzel prüfen.
        $root = $this->request('PROPFIND', $this->user_root(), null, ['Depth' => '0']);
        if ($root['code'] === 401) {
            return ['success' => false, 'message' => 'Login abgelehnt (HTTP 401). App-Passwort oder Benutzername falsch.'];
        }
        if ($root['code'] === 0) {
            return ['success' => false, 'message' => 'Server nicht erreichbar: ' . wp_strip_all_tags((string) $root['body'])];
        }
        if (!$root['success']) {
            return ['success' => false, 'message' => 'WebDAV-Wurzel nicht erreichbar (HTTP ' . $root['code'] . '). Prüfe die Nextcloud-URL (nur die Basisadresse, z. B. https://cloud.verein.de – ohne /index.php, ohne /apps/…) und den Benutzernamen (exakter Login, wie er in Nextcloud unter „Benutzer" steht – nicht die E-Mail, nicht der angezeigte Name).'];
        }
        // 2. Basis-Ordner sicherstellen.
        if (!$this->ensure_folder('')) {
            return ['success' => false, 'message' => 'Verbindung ok, aber der Ordner „' . $this->base_folder . '" konnte nicht angelegt werden.'];
        }
        return ['success' => true, 'message' => 'Verbindung erfolgreich. Ordner „' . $this->base_folder . '" ist bereit.'];
    }
}

// Singleton
function jb_nc(): JB_Nextcloud {
    static $instance = null;
    if ($instance === null) {
        $instance = new JB_Nextcloud();
    }
    return $instance;
}

/**
 * Auslage hochladen: validiert, pusht zu Nextcloud.
 * @return string Nextcloud-Pfad oder WP_Error
 */
function jb_upload_beleg(array $file, int $auslage_id, string $datum, int $user_id): string|WP_Error {
    $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/heic', 'image/webp'];
    $max_size = 10 * 1024 * 1024;

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return new WP_Error('upload_error', 'Upload-Fehler (Code ' . ($file['error'] ?? '?') . ').');
    }
    if (($file['size'] ?? 0) > $max_size) {
        return new WP_Error('too_large', 'Datei zu groß (max. 10 MB).');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed_types, true)) {
        return new WP_Error('wrong_type', 'Nur PDF, JPG oder PNG erlaubt (erkannt: ' . esc_html((string) $mime) . ').');
    }

    $user  = get_userdata($user_id);
    $uname = sanitize_file_name($user->user_login ?? ('user' . $user_id));
    $year  = date('Y', strtotime($datum) ?: time());
    $ext   = match ($mime) {
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/heic'      => 'heic',
        default           => 'bin',
    };

    // Kein '#' o. Ä. im Dateinamen – sonst zerschießt es die WebDAV-URL.
    $safe_datum = preg_replace('/[^0-9\-]/', '', $datum);
    $nc_path = "Belege/{$year}/Auslagen/{$safe_datum}_{$uname}_Nr{$auslage_id}.{$ext}";

    return jb_nc()->upload_beleg($file['tmp_name'], $nc_path);
}
