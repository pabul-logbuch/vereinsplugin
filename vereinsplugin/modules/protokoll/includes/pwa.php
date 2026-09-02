<?php
defined('ABSPATH') || exit;

/**
 * Macht den Mitgliederbereich zu einer installierbaren PWA und verlinkt die
 * Nextcloud-Apps (Talk, Dateien, Kalender) per Deep Link.
 *
 * Bewusst KEIN Nachbau von Talk/Dateien: Ein Tipp auf „Chat" öffnet auf dem
 * Handy die installierte Nextcloud-Talk-App (die Nextcloud-Apps registrieren
 * sich für https-Links ihrer Domain), sonst den Browser.
 *
 * Caching-Strategie im Service Worker ist absichtlich zurückhaltend:
 * HTML-Seiten des Mitgliederbereichs werden NIE zwischengespeichert, weil sie
 * personenbezogene Inhalte enthalten. Gecacht werden nur statische Assets
 * (CSS, JS, Icons) und eine Offline-Hinweisseite.
 */

// ─── EINSTELLUNGEN ─────────────────────────────────────────────────────────

function pp_pwa_defaults() {
    return [
        'app_name'       => 'Jugendforum',
        'app_name_lang'  => 'Jugendforum Riedlingen',
        'theme_color'    => '#1f2430',
        'nextcloud_url'  => '',
        'talk_url'       => '',
        'files_url'      => '',
        'kalender_url'   => '',
    ];
}

function pp_pwa_option($key) {
    $werte = wp_parse_args(get_option('pp_pwa_settings', []), pp_pwa_defaults());
    return $werte[$key] ?? '';
}

/** Baut die Links zu den Nextcloud-Apps; leere Felder werden ausgelassen. */
function pp_pwa_app_links() {
    $basis = untrailingslashit(pp_pwa_option('nextcloud_url'));
    $links = [];

    $talk = pp_pwa_option('talk_url') ?: ($basis ? $basis . '/apps/spreed' : '');
    $files = pp_pwa_option('files_url') ?: ($basis ? $basis . '/apps/files' : '');
    $kalender = pp_pwa_option('kalender_url') ?: ($basis ? $basis . '/apps/calendar' : '');

    if ($talk)     $links[] = ['url' => $talk,     'label' => 'Chat',     'icon' => '💬'];
    if ($files)    $links[] = ['url' => $files,    'label' => 'Dateien',  'icon' => '📁'];
    if ($kalender) $links[] = ['url' => $kalender, 'label' => 'Kalender', 'icon' => '📅'];

    return $links;
}

add_action('admin_menu', 'pp_pwa_register_settings_page', 20);
function pp_pwa_register_settings_page() {
    add_submenu_page(
        'protokollpro', 'App & Nextcloud', 'App & Nextcloud',
        'manage_options', 'pp-app', 'pp_render_pwa_settings_page'
    );
}

add_action('admin_post_pp_save_pwa_settings', 'pp_handle_save_pwa_settings');
function pp_handle_save_pwa_settings() {
    if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_save_pwa_settings');

    $farbe = sanitize_hex_color($_POST['theme_color'] ?? '') ?: '#1f2430';

    update_option('pp_pwa_settings', [
        'app_name'      => sanitize_text_field($_POST['app_name'] ?? 'Jugendforum'),
        'app_name_lang' => sanitize_text_field($_POST['app_name_lang'] ?? ''),
        'theme_color'   => $farbe,
        'nextcloud_url' => esc_url_raw(trim($_POST['nextcloud_url'] ?? '')),
        'talk_url'      => esc_url_raw(trim($_POST['talk_url'] ?? '')),
        'files_url'     => esc_url_raw(trim($_POST['files_url'] ?? '')),
        'kalender_url'  => esc_url_raw(trim($_POST['kalender_url'] ?? '')),
    ]);

    // Cache-Version hochzählen, damit Service Worker und Manifest neu geladen werden
    update_option('pp_pwa_cache_version', intval(get_option('pp_pwa_cache_version', 1)) + 1);

    wp_safe_redirect(admin_url('admin.php?page=pp-app&pp_saved=1'));
    exit;
}

function pp_render_pwa_settings_page() {
    if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
    $w = wp_parse_args(get_option('pp_pwa_settings', []), pp_pwa_defaults());
    $seite = pp_pwa_find_member_page();
    ?>
    <div class="wrap pp-wrap">
        <h1>App &amp; Nextcloud</h1>
        <?php if (isset($_GET['pp_saved'])) echo '<div class="notice notice-success"><p>Gespeichert.</p></div>'; ?>

        <p>Der Mitgliederbereich lässt sich als App auf dem Handy installieren („Zum Startbildschirm hinzufügen"). Hier stellt ihr Name, Farbe und die Verlinkung zu euren Nextcloud-Apps ein.</p>

        <?php if ($seite) : ?>
            <div class="notice notice-info inline"><p>
                Mitgliederbereich gefunden: <a href="<?php echo esc_url(get_permalink($seite)); ?>" target="_blank"><?php echo esc_html(get_the_title($seite)); ?></a> — diese Seite wird als App-Startseite verwendet.
            </p></div>
        <?php else : ?>
            <div class="notice notice-warning inline"><p>
                Es wurde keine Seite mit dem Shortcode <code>[protokollpro_mitgliederbereich]</code> gefunden. Legt zuerst eine solche Seite an, sonst weiß die App nicht, wo sie starten soll.
            </p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('pp_save_pwa_settings'); ?>
            <input type="hidden" name="action" value="pp_save_pwa_settings">
            <table class="form-table">
                <tr>
                    <th><label>App-Name (unter dem Icon)</label></th>
                    <td><input type="text" name="app_name" class="regular-text" value="<?php echo esc_attr($w['app_name']); ?>">
                        <p class="description">Kurz halten — auf dem Homescreen ist wenig Platz.</p></td>
                </tr>
                <tr>
                    <th><label>Vollständiger Name</label></th>
                    <td><input type="text" name="app_name_lang" class="regular-text" value="<?php echo esc_attr($w['app_name_lang']); ?>"></td>
                </tr>
                <tr>
                    <th><label>Themenfarbe</label></th>
                    <td><input type="text" name="theme_color" value="<?php echo esc_attr($w['theme_color']); ?>" placeholder="#1f2430">
                        <p class="description">Färbt die Statusleiste, wenn die App gestartet wird.</p></td>
                </tr>
                <tr><th colspan="2"><h2 style="margin:8px 0 0">Nextcloud</h2></th></tr>
                <tr>
                    <th><label>Nextcloud-Adresse</label></th>
                    <td><input type="url" name="nextcloud_url" class="regular-text" value="<?php echo esc_attr($w['nextcloud_url']); ?>" placeholder="https://cloud.euer-verein.de">
                        <p class="description">Reicht meistens — Chat, Dateien und Kalender werden daraus abgeleitet.</p></td>
                </tr>
                <tr>
                    <th><label>Chat (Talk)</label></th>
                    <td><input type="url" name="talk_url" class="regular-text" value="<?php echo esc_attr($w['talk_url']); ?>" placeholder="leer lassen für /apps/spreed">
                        <p class="description">Für einen bestimmten Raum die volle Gesprächs-URL eintragen.</p></td>
                </tr>
                <tr>
                    <th><label>Dateien</label></th>
                    <td><input type="url" name="files_url" class="regular-text" value="<?php echo esc_attr($w['files_url']); ?>" placeholder="leer lassen für /apps/files"></td>
                </tr>
                <tr>
                    <th><label>Kalender</label></th>
                    <td><input type="url" name="kalender_url" class="regular-text" value="<?php echo esc_attr($w['kalender_url']); ?>" placeholder="leer lassen für /apps/calendar"></td>
                </tr>
            </table>
            <p><button type="submit" class="button button-primary">Speichern</button></p>
        </form>

        <hr>
        <h2>So installieren die Mitglieder die App</h2>
        <p><strong>Android (Chrome):</strong> Mitgliederbereich öffnen → es erscheint ein Hinweisbanner „App installieren", oder im Browsermenü „App installieren" / „Zum Startbildschirm hinzufügen".</p>
        <p><strong>iPhone (Safari):</strong> Mitgliederbereich öffnen → Teilen-Symbol → „Zum Home-Bildschirm". iOS zeigt kein automatisches Banner, deshalb steht im Mitgliederbereich eine entsprechende Anleitung.</p>
        <p class="description">Voraussetzung ist in beiden Fällen HTTPS — ohne gültiges Zertifikat lässt sich keine PWA installieren.</p>
    </div>
    <?php
}

/** Sucht die Seite mit dem Mitgliederbereichs-Shortcode (für start_url). */
function pp_pwa_find_member_page() {
    $cached = get_transient('pp_pwa_member_page');
    if ($cached !== false) return $cached ?: null;

    $seiten = get_posts([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        's'              => 'protokollpro_mitgliederbereich',
    ]);

    $treffer = 0;
    foreach ($seiten as $s) {
        if (has_shortcode($s->post_content, 'protokollpro_mitgliederbereich')) {
            $treffer = $s->ID;
            break;
        }
    }

    set_transient('pp_pwa_member_page', $treffer, HOUR_IN_SECONDS);
    return $treffer ?: null;
}

// Fundstelle vergessen, wenn Seiten geändert werden
add_action('save_post_page', function () { delete_transient('pp_pwa_member_page'); });

function pp_pwa_start_url() {
    $seite = pp_pwa_find_member_page();
    return $seite ? get_permalink($seite) : home_url('/');
}

// ─── MANIFEST & SERVICE WORKER AUSLIEFERN ──────────────────────────────────

add_action('template_redirect', 'pp_pwa_serve_endpoints', 5);
function pp_pwa_serve_endpoints() {
    if (!empty($_GET['pp_manifest'])) {
        pp_pwa_output_manifest();
    }
    if (!empty($_GET['pp_sw'])) {
        pp_pwa_output_service_worker();
    }
    if (!empty($_GET['pp_offline'])) {
        pp_pwa_output_offline_page();
    }
}

function pp_pwa_output_manifest() {
    $farbe = pp_pwa_option('theme_color') ?: '#1f2430';

    $manifest = [
        'name'             => pp_pwa_option('app_name_lang') ?: pp_pwa_option('app_name'),
        'short_name'       => pp_pwa_option('app_name') ?: 'Verein',
        'start_url'        => pp_pwa_start_url(),
        'scope'            => home_url('/'),
        'display'          => 'standalone',
        'orientation'      => 'portrait-primary',
        'background_color' => '#ffffff',
        'theme_color'      => $farbe,
        'lang'             => 'de',
        'icons'            => [
            ['src' => PP_URL . 'assets/pp-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => PP_URL . 'assets/pp-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => PP_URL . 'assets/pp-icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ];

    // Schnellzugriffe beim langen Antippen des App-Icons
    $start = pp_pwa_start_url();
    $manifest['shortcuts'] = [
        ['name' => 'Protokolle', 'url' => add_query_arg('pp_view', 'protokolle', $start)],
        ['name' => 'Aufgaben',   'url' => add_query_arg('pp_view', 'aufgaben', $start)],
        ['name' => 'Termine',    'url' => add_query_arg('pp_view', 'termine', $start)],
    ];

    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: max-age=3600');
    echo wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pp_pwa_output_service_worker() {
    $version  = intval(get_option('pp_pwa_cache_version', 1));
    $cache    = 'pp-static-v' . $version;
    $offline  = home_url('/?pp_offline=1');
    $assets   = [
        PP_URL . 'assets/style.css',
        PP_URL . 'assets/script.js',
        PP_URL . 'assets/pp-icon-192.png',
    ];

    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache');
    ?>
/* ProtokollPro Service Worker — Version <?php echo esc_js($version); ?> */
const CACHE = '<?php echo esc_js($cache); ?>';
const OFFLINE_URL = '<?php echo esc_js($offline); ?>';
const STATIC_ASSETS = <?php echo wp_json_encode(array_merge($assets, [$offline])); ?>;

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE).then(function (cache) {
            return cache.addAll(STATIC_ASSETS);
        }).then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (k) {
                return k !== CACHE;
            }).map(function (k) { return caches.delete(k); }));
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    var req = event.request;
    if (req.method !== 'GET') return;

    var url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // Seiteninhalte NIE cachen — sie sind personenbezogen. Nur bei
    // Netzwerkfehler die Offline-Hinweisseite zeigen.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(function () { return caches.match(OFFLINE_URL); })
        );
        return;
    }

    // Statische Dateien: erst Cache, dann Netz (und nachcachen).
    if (/\.(css|js|png|jpg|jpeg|svg|woff2?)$/i.test(url.pathname)) {
        event.respondWith(
            caches.match(req).then(function (hit) {
                return hit || fetch(req).then(function (res) {
                    var copy = res.clone();
                    caches.open(CACHE).then(function (c) { c.put(req, copy); });
                    return res;
                });
            })
        );
    }
});
    <?php
    exit;
}

function pp_pwa_output_offline_page() {
    $farbe = pp_pwa_option('theme_color') ?: '#1f2430';
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offline</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; min-height: 100vh; display: flex;
               align-items: center; justify-content: center; background: <?php echo esc_attr($farbe); ?>; color: #fff; }
        .box { text-align: center; padding: 32px; max-width: 380px; }
        h1 { font-size: 20px; margin: 0 0 10px; }
        p { color: #c9cfda; line-height: 1.5; }
        button { margin-top: 18px; padding: 10px 18px; border: none; border-radius: 6px;
                 background: #fff; color: #1f2430; font-size: 15px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Keine Verbindung</h1>
        <p>Protokolle und Aufgaben brauchen eine Internetverbindung, damit alle denselben Stand sehen. Sobald du wieder online bist, geht es weiter.</p>
        <button onclick="location.reload()">Erneut versuchen</button>
    </div>
</body>
</html>
    <?php
    exit;
}

// ─── EINBINDUNG IM FRONTEND ────────────────────────────────────────────────

add_action('wp_head', 'pp_pwa_head_tags');
function pp_pwa_head_tags() {
    if (!pp_pwa_ist_mitgliederseite()) return;
    $farbe = pp_pwa_option('theme_color') ?: '#1f2430';
    ?>
    <link rel="manifest" href="<?php echo esc_url(home_url('/?pp_manifest=1')); ?>">
    <meta name="theme-color" content="<?php echo esc_attr($farbe); ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo esc_attr(pp_pwa_option('app_name')); ?>">
    <link rel="apple-touch-icon" href="<?php echo esc_url(PP_URL . 'assets/pp-icon-apple-180.png'); ?>">
    <?php
}

function pp_pwa_ist_mitgliederseite() {
    global $post;
    return is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'protokollpro_mitgliederbereich');
}

/** Registriert den Service Worker und steuert den Installationshinweis. */
add_action('wp_footer', 'pp_pwa_footer_script');
function pp_pwa_footer_script() {
    if (!pp_pwa_ist_mitgliederseite()) return;
    ?>
    <script>
    (function () {
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('<?php echo esc_js(home_url('/?pp_sw=1')); ?>', { scope: '<?php echo esc_js(parse_url(home_url('/'), PHP_URL_PATH) ?: '/'); ?>' })
                    .catch(function (e) { console.log('ProtokollPro: Service Worker nicht registriert', e); });
            });
        }

        var banner = document.getElementById('pp-install-banner');
        if (!banner) return;

        // Bereits als App gestartet? Dann nichts anzeigen.
        var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        if (standalone || localStorage.getItem('pp_install_dismissed') === '1') return;

        var btn = document.getElementById('pp-install-btn');
        var iosHint = document.getElementById('pp-install-ios');
        var deferred = null;

        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferred = e;
            banner.style.display = 'flex';
            if (btn) btn.style.display = '';
        });

        if (btn) {
            btn.addEventListener('click', function () {
                if (!deferred) return;
                deferred.prompt();
                deferred.userChoice.then(function () {
                    deferred = null;
                    banner.style.display = 'none';
                });
            });
        }

        // iOS zeigt kein Installations-Ereignis — dort die Anleitung einblenden.
        var istIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
        if (istIOS && iosHint) {
            iosHint.style.display = '';
            banner.style.display = 'flex';
        }

        var close = document.getElementById('pp-install-close');
        if (close) {
            close.addEventListener('click', function () {
                localStorage.setItem('pp_install_dismissed', '1');
                banner.style.display = 'none';
            });
        }
    })();
    </script>
    <?php
}

/** Installationsbanner + Leiste mit den Nextcloud-Apps (im Mitgliederbereich). */
function pp_render_app_leiste() {
    $links = pp_pwa_app_links();
    ?>
    <div class="pp-install-banner" id="pp-install-banner" style="display:none">
        <div class="pp-install-text">
            <strong>Als App installieren</strong>
            <span id="pp-install-ios" style="display:none">Teilen-Symbol antippen und „Zum Home-Bildschirm" wählen.</span>
        </div>
        <button type="button" class="pp-btn pp-btn-small pp-btn-primary" id="pp-install-btn" style="display:none">Installieren</button>
        <button type="button" class="pp-install-close" id="pp-install-close" aria-label="Hinweis ausblenden">×</button>
    </div>

    <?php if ($links) : ?>
        <div class="pp-app-leiste">
            <?php foreach ($links as $l) : ?>
                <a class="pp-app-kachel" href="<?php echo esc_url($l['url']); ?>" target="_blank" rel="noopener">
                    <span class="pp-app-icon"><?php echo esc_html($l['icon']); ?></span>
                    <span><?php echo esc_html($l['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
}
