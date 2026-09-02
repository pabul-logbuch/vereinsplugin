<?php
/**
 * Kern: zentrale Shortcode-Registry.
 *
 * Einzige Wahrheit darüber, welche Shortcodes das Plugin (inkl. Module)
 * bereitstellt – wird für die einzige Admin-Seite („Verein → Shortcodes“)
 * und perspektivisch für Block-Editor-Vorschläge genutzt.
 *
 * Die Shortcodes selbst werden weiterhin von den Modulen registriert; hier
 * steht nur die Beschreibung + Zuordnung.
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return array[] Gruppen => [ ['tag'=>'', 'attrs'=>'', 'desc'=>'', 'scope'=>'public|member|vorstand'] ]
 */
function vp_shortcode_catalog() {
	$catalog = array(

		__( 'Kern', 'vereinsplugin' ) => array(
			array(
				'tag'   => 'verein_mitgliederbereich',
				'attrs' => 'start="wuensche"',
				'desc'  => __( 'Der gemeinsame Mitgliederbereich mit Tab-Navigation über alle Module (Wünsche, Protokolle, Aufgaben, Termine, Auslagen, Veranstaltungen, Schichtpläne). Diese eine Seite ersetzt die bisherigen Einzel-Mitgliederbereiche. Attribut start= wählt den zuerst geöffneten Tab.', 'vereinsplugin' ),
				'scope' => 'member',
			),
			array(
				'tag'   => 'verein_login',
				'attrs' => 'redirect="/mitglieder"',
				'desc'  => __( 'Login-Formular für Mitglieder. Bereits eingeloggte sehen einen Abmelde-Hinweis.', 'vereinsplugin' ),
				'scope' => 'public',
			),
		),

		__( 'Wunschliste & Spenden', 'vereinsplugin' ) => array(
			array(
				'tag'   => 'wunschliste',
				'attrs' => 'kategorie="Sport" status="offen"',
				'desc'  => __( 'Öffentliche Spender-Ansicht: Wünsche mit Kategorie-Filter, Preis/Preisspanne, Produktlinks und Spenden-Modal mit Bankverbindung.', 'vereinsplugin' ),
				'scope' => 'public',
			),
			array(
				'tag'   => 'wunschliste_verwaltung',
				'attrs' => '',
				'desc'  => __( 'Mitglieder-Verwaltung der Wünsche (anlegen/bearbeiten/löschen). Auch im Mitgliederbereich als Tab enthalten.', 'vereinsplugin' ),
				'scope' => 'member',
			),
			array(
				'tag'   => 'wunschliste_voting',
				'attrs' => '',
				'desc'  => __( 'Abstimmungsseite über Wünsche inkl. Kategorie-Filter; eingeloggte Mitglieder können direkt bearbeiten.', 'vereinsplugin' ),
				'scope' => 'public',
			),
			array(
				'tag'   => 'wunschliste_login',
				'attrs' => '',
				'desc'  => __( 'Login-Formular (Modul-Variante). Empfohlen wird stattdessen [verein_login].', 'vereinsplugin' ),
				'scope' => 'public',
			),
			array(
				'tag'   => 'schichtplan',
				'attrs' => 'event="stadtfestival-2026"',
				'desc'  => __( 'Öffentlicher Schichtplan einer Veranstaltung als Tageskalender; ohne event= eine Auswahlübersicht aller aktiven Veranstaltungen. Eintragen ohne Account möglich, mit Bestätigungs-Mail, .ics-Kalenderdatei und Austragungslink.', 'vereinsplugin' ),
				'scope' => 'public',
			),
			array(
				'tag'   => 'schichtplan_verwaltung',
				'attrs' => '',
				'desc'  => __( 'Mitglieder-Verwaltung für Veranstaltungen, Stationen und Schichten inkl. Druck-/PDF-Export.', 'vereinsplugin' ),
				'scope' => 'member',
			),
		),

		__( 'Sitzungen & Protokolle', 'vereinsplugin' ) => array(
			array(
				'tag'   => 'protokollpro_mitgliederbereich',
				'attrs' => '',
				'desc'  => __( 'Voller Protokoll-Mitgliederbereich: Protokolle, TOPs, Themenspeicher, Aufgaben, Termine, Kreise/Rollen, Kalender-Sync, Live-Sitzungsmodus (?pp_view=live). Auch im Kern-Mitgliederbereich als Tab.', 'vereinsplugin' ),
				'scope' => 'member',
			),
			array(
				'tag'   => 'protokollpro_oeffentlich',
				'attrs' => 'gremium="MV"',
				'desc'  => __( 'Öffentlich sichtbare, abgeschlossene Protokolle (z. B. Mitgliederversammlung) mit Beschlüssen.', 'vereinsplugin' ),
				'scope' => 'public',
			),
			array(
				'tag'   => 'protokollpro_organigramm',
				'attrs' => '',
				'desc'  => __( 'Radiale SVG-Mindmap aller Gremien/Kreise aus den Eltern-Kind-Beziehungen.', 'vereinsplugin' ),
				'scope' => 'public',
			),
			array(
				'tag'   => 'protokollpro_kreis',
				'attrs' => 'id="5"  oder  name="Beirat"',
				'desc'  => __( 'Öffentlicher Steckbrief eines Gremiums/Kreises: Zweck, Rollen mit Zuständigkeiten und aktueller Besetzung. Die ID steht im Mitgliederbereich bei den Kreisen.', 'vereinsplugin' ),
				'scope' => 'public',
			),
		),

		__( 'Buchhaltung & Auslagen', 'vereinsplugin' ) => array(
			array(
				'tag'   => 'jb_auslage_einreichen',
				'attrs' => '',
				'desc'  => __( 'Formular für Mitglieder, um eine Auslagen-Erstattung mit Beleg (Nextcloud-Upload) einzureichen.', 'vereinsplugin' ),
				'scope' => 'member',
			),
			array(
				'tag'   => 'jb_meine_auslagen',
				'attrs' => '',
				'desc'  => __( 'Übersicht der eigenen eingereichten Auslagen mit Status (offen / genehmigt / ausgezahlt).', 'vereinsplugin' ),
				'scope' => 'member',
			),
			array(
				'tag'   => 'jb_kassenbericht',
				'attrs' => '',
				'desc'  => __( 'Kassenbericht / EÜR-Übersicht. Nur für Kassier:in / Vorstand (Capability jb_view_journal).', 'vereinsplugin' ),
				'scope' => 'vorstand',
			),
		),

		__( 'Veranstaltungs-Publisher', 'vereinsplugin' ) => array(
			array(
				'tag'   => 'verein_veranstaltungen',
				'attrs' => '(geplant, Stage 2)',
				'desc'  => __( 'Frontend-Verwaltung von Veranstaltungen und Verteil-Kampagnen (Mastodon, Bluesky, Telegram, Presse, Signal …). Ersetzt den bisherigen wp-admin-Editor des „Veranstaltung“-Beitragstyps. Bis dahin bleibt der Editor über „Verein → Shortcodes → Direktlinks“ erreichbar.', 'vereinsplugin' ),
				'scope' => 'member',
			),
		),
	);

	/**
	 * Module/Erweiterungen können den Katalog ergänzen.
	 */
	return apply_filters( 'vp_shortcode_catalog', $catalog );
}

/**
 * Direktlinks zu Admin-Seiten, die (noch) kein Frontend-Pendant haben.
 * Diese Seiten sind aus dem Menü entfernt, aber weiter über ihre URL erreichbar.
 */
function vp_admin_direct_links() {
	return array(
		array( 'label' => __( 'Mitglied anlegen', 'vereinsplugin' ),        'url' => admin_url( 'admin.php?page=wunschliste-mitglied' ) ),
		array( 'label' => __( 'Mitglieder-Import (CSV/XML)', 'vereinsplugin' ), 'url' => admin_url( 'admin.php?page=wunschliste-mitglieder-import' ) ),
		array( 'label' => __( 'Wunschlisten-Import (CSV/XML)', 'vereinsplugin' ), 'url' => admin_url( 'admin.php?page=wunschliste-import' ) ),
		array( 'label' => __( 'Abstimmung: Gast-Codes', 'vereinsplugin' ),   'url' => admin_url( 'admin.php?page=wunschliste-gastcodes' ) ),
		array( 'label' => __( 'Schichtplan-Import (CSV/XML)', 'vereinsplugin' ), 'url' => admin_url( 'admin.php?page=wunschliste-schichtplan-import' ) ),
		array( 'label' => __( 'Veranstaltungen (Beitragstyp-Editor)', 'vereinsplugin' ), 'url' => admin_url( 'edit.php?post_type=veranstaltung' ) ),
	);
}
