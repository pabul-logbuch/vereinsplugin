<?php
/**
 * Teilen ohne eigene Seite: Formulare und Schichtpläne haben eine feste
 * Adresse, die das Plugin selbst ausliefert – keine WordPress-Seite mit
 * Shortcode nötig.
 *
 *   https://example.org/?vp_formular=<id>      (auch der Slug geht)
 *   https://example.org/?vp_schichtplan=<id>   (auch der Slug geht)
 *
 * Geteilt wird die ID: Slugs entstehen aus dem Titel und ändern sich beim
 * Umbenennen – ein gedruckter QR-Code muss aber gültig bleiben.
 *
 * Die Seite ist schlicht (Kopf mit Vereinsname, Inhalt, wp_head/wp_footer
 * für Styles und Skripte), passt zu jedem Theme und funktioniert auch auf
 * dem Handy nach dem QR-Scan.
 *
 * vp_teilen_box() zeigt Link, Kopieren, Öffnen und QR-Code (mit Download
 * als PNG/SVG) – genutzt in der Formular-Verwaltung, beim Schichtplan und im
 * Projekt.
 */

defined( 'ABSPATH' ) || exit;

function vp_formular_teilen_url( $f ) {
	return add_query_arg( 'vp_formular', (int) $f->id, home_url( '/' ) );
}

function vp_schichtplan_teilen_url( $event ) {
	return add_query_arg( 'vp_schichtplan', (int) $event->id, home_url( '/' ) );
}

/* -------------------------------------------------------------------------
 * Ausliefern
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', 'vp_teilen_ausliefern', 1 );
function vp_teilen_ausliefern() {
	if ( isset( $_GET['vp_formular'] ) && function_exists( 'vp_shortcode_formular' ) ) {
		$schl = sanitize_title( wp_unslash( $_GET['vp_formular'] ) );
		$f    = ctype_digit( $schl ) ? vp_formular_get( (int) $schl ) : vp_formular_get_by_slug( $schl );
		if ( ! $f && ctype_digit( $schl ) ) {
			$f = vp_formular_get_by_slug( $schl );
		}
		if ( ! $f || ( 'entwurf' === $f->status && ! current_user_can( 'vp_manage_formulare' ) ) ) {
			vp_teilen_seite( __( 'Nicht gefunden', 'vereinsplugin' ), '<div class="vp-card vp-note vp-note-warn">' . esc_html__( 'Dieses Formular gibt es nicht (mehr) oder es ist noch nicht veröffentlicht.', 'vereinsplugin' ) . '</div>', 404 );
		}
		vp_teilen_seite( $f->titel, vp_shortcode_formular( array( 'id' => (int) $f->id ) ) );
	}
	if ( isset( $_GET['vp_schichtplan'] ) && function_exists( 'wl_get_event_by_slug' ) ) {
		$schl  = sanitize_title( wp_unslash( $_GET['vp_schichtplan'] ) );
		$event = ( ctype_digit( $schl ) && function_exists( 'wl_get_event' ) ) ? wl_get_event( (int) $schl ) : null;
		$event = $event ?: wl_get_event_by_slug( $schl );
		if ( ! $event ) {
			vp_teilen_seite( __( 'Nicht gefunden', 'vereinsplugin' ), '<div class="vp-card vp-note vp-note-warn">' . esc_html__( 'Diesen Schichtplan gibt es nicht (mehr).', 'vereinsplugin' ) . '</div>', 404 );
		}
		$inhalt = $event->aktiv
			? wl_render_schichtplan( $event->id )
			: '<div class="wl-wrap"><div class="wl-notice wl-notice-warn">' . esc_html__( 'Dieser Schichtplan ist aktuell nicht aktiv.', 'vereinsplugin' ) . '</div></div>';
		vp_teilen_seite( $event->titel, $inhalt );
	}
}

/** Schlichte, eigenständige Seite ausgeben und beenden. */
function vp_teilen_seite( $titel, $inhalt, $status = 200 ) {
	// Styles der Bereiche laden (sonst nur auf Seiten mit Shortcode).
	$css = VP_PATH . 'assets/app.css';
	wp_enqueue_style( 'vp-app', VP_URL . 'assets/app.css', array(), is_readable( $css ) ? filemtime( $css ) : VP_VERSION );
	$js = VP_PATH . 'assets/app.js';
	if ( is_readable( $js ) ) {
		wp_enqueue_script( 'vp-app', VP_URL . 'assets/app.js', array( 'jquery' ), filemtime( $js ), true );
	}
	status_header( $status );
	nocache_headers(); // Formulare mit Nonce / freie Plätze: nie aus dem Cache
	add_filter( 'pre_get_document_title', static function () use ( $titel ) {
		return $titel . ' – ' . get_bloginfo( 'name' );
	} );
	?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<?php wp_head(); ?>
<style>
body.vp-teilen-seite{margin:0;background:#f6f7f9;color:#1f2937;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.vp-teilen-kopf{background:#fff;border-bottom:1px solid #e5e7eb;padding:12px 16px}
.vp-teilen-kopf a{color:inherit;text-decoration:none;font-weight:600}
.vp-teilen-inhalt{max-width:820px;margin:0 auto;padding:20px 16px 48px}
@media (prefers-color-scheme:dark){body.vp-teilen-seite{background:#111827;color:#e5e7eb}.vp-teilen-kopf{background:#1f2937;border-color:#374151}}
</style>
</head>
<body <?php body_class( 'vp-teilen-seite' ); ?>>
<?php if ( function_exists( 'wp_body_open' ) ) { wp_body_open(); } ?>
<header class="vp-teilen-kopf"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a></header>
<main class="vp-teilen-inhalt vp-app"><?php echo $inhalt; // phpcs:ignore WordPress.Security.EscapeOutput -- fertiges HTML der Module ?></main>
<?php wp_footer(); ?>
</body>
</html>
	<?php
	exit;
}

/* -------------------------------------------------------------------------
 * Teilen-Box: Link, Kopieren, Öffnen, QR-Code
 * ---------------------------------------------------------------------- */

/**
 * @param string $url    Adresse zum Teilen
 * @param string $name   Dateiname für den QR-Download (ohne Endung)
 * @param string $hinweis optionaler Hinweis (z. B. „noch Entwurf“)
 */
function vp_teilen_box( $url, $name = 'qr', $hinweis = '' ) {
	wp_enqueue_script( 'vp-girocode' );
	static $nr = 0;
	$nr++;
	$id   = 'vp-teilen-' . $nr;
	$name = sanitize_file_name( $name ) ?: 'qr';
	$h    = '<div class="vp-teilen" id="' . esc_attr( $id ) . '" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;margin:8px 0">';
	$h   .= '<div class="vp-girocode-qr" data-epc="' . esc_attr( $url ) . '" data-label="' . esc_attr__( 'QR-Code', 'vereinsplugin' ) . '" style="width:150px;height:150px;background:#fff;padding:6px;border-radius:8px;flex:none"></div>';
	$h   .= '<div style="flex:1;min-width:220px">';
	$h   .= '<input type="text" readonly value="' . esc_attr( $url ) . '" onclick="this.select()" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid rgba(127,127,127,.35);border-radius:8px;font:inherit">';
	$h   .= '<p style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 0">'
		. '<button type="button" class="vp-btn vp-btn-primary" data-vp-kopieren="' . esc_attr( $url ) . '">' . esc_html__( 'Link kopieren', 'vereinsplugin' ) . '</button>'
		. '<a class="vp-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Öffnen', 'vereinsplugin' ) . '</a>'
		. '<button type="button" class="vp-btn" data-vp-qr-png="' . esc_attr( $id ) . '" data-name="' . esc_attr( $name ) . '">' . esc_html__( 'QR als PNG', 'vereinsplugin' ) . '</button>'
		. '<button type="button" class="vp-btn" data-vp-qr-svg="' . esc_attr( $id ) . '" data-name="' . esc_attr( $name ) . '">' . esc_html__( 'QR als SVG', 'vereinsplugin' ) . '</button>'
		. '</p>';
	if ( $hinweis ) {
		$h .= '<p class="vp-muted" style="margin:6px 0 0">' . esc_html( $hinweis ) . '</p>';
	}
	$h .= '</div></div>';
	if ( 1 === $nr ) {
		$h .= '<style>.vp-teilen .vp-girocode-qr svg{display:block;width:100%;height:100%}</style>';
	}
	$h .= vp_teilen_js_einmal();
	return $h;
}

/** Kopier-/QR-Skript nur einmal je Seite ausgeben (Teilen-Box, Shortcode-Knopf). */
function vp_teilen_js_einmal() {
	static $da = false;
	if ( $da ) {
		return '';
	}
	$da = true;
	return vp_teilen_js();
}

function vp_teilen_js() {
	$kopiert = esc_js( __( 'Kopiert ✓', 'vereinsplugin' ) );
	return '<script>(function(){'
		. 'function svg(id){var el=document.querySelector("#"+id+" .vp-girocode-qr svg");return el?new XMLSerializer().serializeToString(el):"";}'
		. 'function laden(href,name){var a=document.createElement("a");a.href=href;a.download=name;document.body.appendChild(a);a.click();a.remove();}'
		. 'document.addEventListener("click",function(e){var b=e.target.closest("[data-vp-kopieren],[data-vp-qr-png],[data-vp-qr-svg]");if(!b)return;'
		. 'if(b.hasAttribute("data-vp-kopieren")){var t=b.getAttribute("data-vp-kopieren"),alt=b.textContent;var ok=function(){b.textContent="' . $kopiert . '";setTimeout(function(){b.textContent=alt;},1500);};'
		. 'if(navigator.clipboard){navigator.clipboard.writeText(t).then(ok);}else{var i=document.createElement("textarea");i.value=t;document.body.appendChild(i);i.select();document.execCommand("copy");i.remove();ok();}return;}'
		. 'var id=b.getAttribute("data-vp-qr-png")||b.getAttribute("data-vp-qr-svg"),s=svg(id),name=b.getAttribute("data-name")||"qr";if(!s)return;'
		. 'if(!/xmlns=/.test(s))s=s.replace("<svg","<svg xmlns=\"http://www.w3.org/2000/svg\"");'
		. 'var url="data:image/svg+xml;charset=utf-8,"+encodeURIComponent(s);'
		. 'if(b.hasAttribute("data-vp-qr-svg")){laden(url,name+".svg");return;}'
		. 's=s.replace("<svg","<svg width=\\"1000\\" height=\\"1000\\"");url="data:image/svg+xml;charset=utf-8,"+encodeURIComponent(s);'
		. 'var img=new Image();img.onload=function(){var c=document.createElement("canvas");c.width=c.height=1000;var x=c.getContext("2d");x.fillStyle="#fff";x.fillRect(0,0,1000,1000);x.drawImage(img,40,40,920,920);laden(c.toDataURL("image/png"),name+".png");};img.src=url;'
		. '});})();</script>';
}
