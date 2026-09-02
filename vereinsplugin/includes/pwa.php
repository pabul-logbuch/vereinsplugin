<?php
/**
 * Kern: PWA / WebApp.
 *
 * Macht den Mitgliederbereich als App installierbar:
 *  - Web App Manifest unter  /?vp_manifest=1
 *  - Service Worker unter     /?vp_sw=1   (Scope: Seiten-Root)
 *  - <link rel="manifest">, Theme-Color & Apple-Touch-Icon auf den
 *    Mitgliederbereich-/Login-Seiten
 *  - „App installieren“-Button in der Bereichs-Navigation (JS in app.js)
 *
 * Bewusst zurückhaltendes Caching: personalisierte HTML-Seiten werden NIE
 * gecacht, nur statische Assets + eine Offline-Hinweisseite.
 *
 * Voraussetzung fürs Installieren: HTTPS mit gültigem Zertifikat.
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_PWA_CACHE_VERSION', VP_VERSION );

function vp_pwa_enabled() {
	return get_option( 'vp_pwa_enabled', '1' ) === '1';
}

function vp_pwa_settings() {
	return array(
		'name'        => get_option( 'vp_app_name', get_bloginfo( 'name' ) ),
		'short_name'  => get_option( 'vp_app_short_name', mb_substr( get_option( 'vp_app_name', get_bloginfo( 'name' ) ), 0, 12 ) ),
		'theme_color' => get_option( 'vp_app_theme_color', '#1f2937' ),
		'bg_color'    => get_option( 'vp_app_bg_color', '#ffffff' ),
		'start_url'   => get_option( 'vp_member_area_url' ) ?: home_url( '/' ),
		'icon_192'    => get_option( 'vp_app_icon_192' ) ?: VP_URL . 'assets/app-icon-192.png',
		'icon_512'    => get_option( 'vp_app_icon_512' ) ?: VP_URL . 'assets/app-icon-512.png',
		'icon_mask'   => get_option( 'vp_app_icon_maskable' ) ?: VP_URL . 'assets/app-icon-maskable-512.png',
		'icon_apple'  => VP_URL . 'assets/app-icon-apple-180.png',
	);
}

/* -------- Endpunkte: Manifest + Service Worker -------- */

add_action( 'init', function () {
	add_rewrite_tag( '%vp_manifest%', '1' );
	add_rewrite_tag( '%vp_sw%', '1' );
} );

add_action( 'template_redirect', 'vp_pwa_serve_endpoints' );
function vp_pwa_serve_endpoints() {
	if ( isset( $_GET['vp_manifest'] ) ) {
		vp_pwa_output_manifest();
	}
	if ( isset( $_GET['vp_sw'] ) ) {
		vp_pwa_output_service_worker();
	}
}

function vp_pwa_output_manifest() {
	$s = vp_pwa_settings();
	nocache_headers();
	header( 'Content-Type: application/manifest+json; charset=utf-8' );

	$manifest = array(
		'name'             => $s['name'],
		'short_name'       => $s['short_name'],
		'start_url'        => $s['start_url'],
		'scope'            => home_url( '/' ),
		'display'          => 'standalone',
		'background_color' => $s['bg_color'],
		'theme_color'      => $s['theme_color'],
		'lang'            => 'de',
		'icons'           => array(
			array( 'src' => $s['icon_192'], 'sizes' => '192x192', 'type' => 'image/png' ),
			array( 'src' => $s['icon_512'], 'sizes' => '512x512', 'type' => 'image/png' ),
			array( 'src' => $s['icon_mask'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
		),
	);
	echo wp_json_encode( $manifest );
	exit;
}

function vp_pwa_output_service_worker() {
	nocache_headers();
	header( 'Content-Type: application/javascript; charset=utf-8' );
	header( 'Service-Worker-Allowed: /' );

	$s        = vp_pwa_settings();
	$cache    = 'vp-static-' . VP_PWA_CACHE_VERSION;
	$precache = wp_json_encode( array(
		VP_URL . 'assets/app.css',
		VP_URL . 'assets/app.js',
		$s['icon_192'],
		$s['icon_512'],
	) );
	$offline_html = wp_json_encode(
		'<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<title>Offline</title><body style="font-family:system-ui;padding:2rem;text-align:center">'
		. '<h1>' . esc_html( $s['name'] ) . '</h1><p>' . esc_html__( 'Keine Internetverbindung. Bitte später erneut versuchen.', 'vereinsplugin' ) . '</p>'
	);

	echo <<<JS
const CACHE = '{$cache}';
const PRECACHE = {$precache};
const OFFLINE_HTML = {$offline_html};

self.addEventListener('install', (e) => {
	self.skipWaiting();
	e.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE).catch(() => {})));
});

self.addEventListener('activate', (e) => {
	e.waitUntil(
		caches.keys().then((keys) => Promise.all(
			keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))
		)).then(() => self.clients.claim())
	);
});

self.addEventListener('fetch', (e) => {
	const req = e.request;
	if (req.method !== 'GET') return;
	const url = new URL(req.url);
	if (url.origin !== location.origin) return;

	// HTML/Navigation: immer Netz, niemals Cache (personalisierte Inhalte).
	if (req.mode === 'navigate') {
		e.respondWith(fetch(req).catch(() => new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
		return;
	}

	// Statische Assets: Cache-first mit Netz-Nachfüllung.
	if (/\\.(css|js|png|jpg|jpeg|svg|webp|woff2?|ico)$/i.test(url.pathname)) {
		e.respondWith(
			caches.match(req).then((hit) => hit || fetch(req).then((res) => {
				const copy = res.clone();
				caches.open(CACHE).then((c) => c.put(req, copy));
				return res;
			}).catch(() => hit))
		);
	}
});
JS;
	exit;
}

/* -------- Einbindung im <head> auf den App-Seiten -------- */

add_action( 'wp_head', 'vp_pwa_head', 2 );
function vp_pwa_head() {
	if ( ! vp_pwa_enabled() || ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! (
		has_shortcode( $post->post_content, 'verein_mitgliederbereich' )
		|| has_shortcode( $post->post_content, 'verein_login' )
	) ) {
		return;
	}
	$s = vp_pwa_settings();
	printf( '<link rel="manifest" href="%s">' . "\n", esc_url( add_query_arg( 'vp_manifest', '1', home_url( '/' ) ) ) );
	printf( '<meta name="theme-color" content="%s">' . "\n", esc_attr( $s['theme_color'] ) );
	printf( '<link rel="apple-touch-icon" href="%s">' . "\n", esc_url( $s['icon_apple'] ) );
	echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";

	// SW-Registrierung + Install-Prompt-Verdrahtung.
	$sw_url = esc_url( add_query_arg( 'vp_sw', '1', home_url( '/' ) ) );
	?>
<script>
(function(){
	if ('serviceWorker' in navigator && location.protocol === 'https:') {
		window.addEventListener('load', function(){ navigator.serviceWorker.register('<?php echo $sw_url; ?>', { scope: '/' }).catch(function(){}); });
	}
	var deferred = null;
	window.addEventListener('beforeinstallprompt', function(e){
		e.preventDefault(); deferred = e;
		document.querySelectorAll('.vp-install-btn').forEach(function(b){ b.hidden = false; });
	});
	document.addEventListener('click', function(e){
		var b = e.target.closest('.vp-install-btn'); if (!b) return;
		if (deferred) { deferred.prompt(); deferred = null; b.hidden = true; }
		else { alert('<?php echo esc_js( __( 'Zum Installieren im Browser-Menü „Zum Startbildschirm hinzufügen“ wählen.', 'vereinsplugin' ) ); ?>'); }
	});
	window.addEventListener('appinstalled', function(){
		document.querySelectorAll('.vp-install-btn').forEach(function(b){ b.hidden = true; });
	});
})();
</script>
	<?php
}
