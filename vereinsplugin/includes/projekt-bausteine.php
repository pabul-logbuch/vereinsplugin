<?php
/**
 * Projekt-Bausteine: kombinierbare Vorlagen für Veranstaltungen und Projekte.
 *
 * Ein **Baustein** ist ein Bündel fertiger Einträge für die vier Listen eines
 * Projekts – Ablauf, Öffentlichkeitsarbeit, Kalkulation und ToDos. Jeder
 * Baustein deckt genau ein Thema ab („Musik & GEMA", „Ausschank &
 * Gestattung", „Bilder & Formate"). Ein Projekt wendet beliebig viele davon
 * an: ein Konzert mit Bierbar nimmt Ablauf + GEMA + Ausschank + Technik, ein
 * Workshop nimmt Anmeldung + Referent:innen + Förderung.
 *
 * Eine **Vorlage** ist nur eine benannte Kombination von Bausteinen – der
 * Schnellstart beim Anlegen („Fest mit Ausschank & Musik"). Danach lässt sich
 * jeder Baustein einzeln nachrüsten oder wieder entfernen.
 *
 * Anwenden ist additiv und wiederholbar: Einträge, die es unter gleichem Titel
 * schon gibt, werden übersprungen. Entfernen löscht nur, was aus dem Baustein
 * stammt (Spalte `quelle`) und noch offen ist – bearbeitete oder erledigte
 * Einträge bleiben.
 *
 * Fristen stehen als Tage relativ zum Projektbeginn. Ohne Beginn werden die
 * Einträge ohne Datum angelegt (die Checkliste stimmt, nur die Termine fehlen).
 *
 * Erweiterbar über die Filter `vp_projekt_bausteine` und `vp_projekt_vorlagen`.
 *
 * Rechtliche Hinweise in den Merkblättern sind allgemeine Orientierung, kein
 * Ersatz für die Auskunft der eigenen Kommune.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Bildformate – einmal definiert, zweifach genutzt: als Checkliste im
 * Baustein „Bilder & Formate" und als Spickzettel im Reiter.
 * ---------------------------------------------------------------------- */

function vp_projekt_bild_formate() {
	return apply_filters( 'vp_projekt_bild_formate', array(
		'quer'      => array(
			'titel'       => __( 'Titelbild quer · 16:9 · 1920 × 1080 px', 'vereinsplugin' ),
			'plattformen' => __( 'Website-Beitrag, Veranstaltungskalender, Newsletter-Kopf, Telegram', 'vereinsplugin' ),
			'kanal'       => 'website',
		),
		'og'        => array(
			'titel'       => __( 'Link-Vorschau (Open Graph) · 1,91:1 · 1200 × 630 px', 'vereinsplugin' ),
			'plattformen' => __( 'So sieht der geteilte Link aus: WhatsApp, Signal, Telegram, Mastodon, Bluesky, Facebook', 'vereinsplugin' ),
			'kanal'       => 'website',
		),
		'quadrat'   => array(
			'titel'       => __( 'Feed-Beitrag quadratisch · 1:1 · 1080 × 1080 px', 'vereinsplugin' ),
			'plattformen' => __( 'Instagram-Raster, WhatsApp-Kanal, Signal, Telegram, Mastodon', 'vereinsplugin' ),
			'kanal'       => 'social',
		),
		'hoch'      => array(
			'titel'       => __( 'Feed-Beitrag hoch · 4:5 · 1080 × 1350 px', 'vereinsplugin' ),
			'plattformen' => __( 'Instagram und Facebook – nimmt im Feed die meiste Fläche ein', 'vereinsplugin' ),
			'kanal'       => 'social',
		),
		'klassisch' => array(
			'titel'       => __( 'Fotoformat · 4:3 · 1440 × 1080 px', 'vereinsplugin' ),
			'plattformen' => __( 'Klassisches Kameraformat. Instagram beschneidet es – wer die volle Fläche will, exportiert zusätzlich 4:5', 'vereinsplugin' ),
			'kanal'       => 'social',
		),
		'feedquer'  => array(
			'titel'       => __( 'Feed-Beitrag quer · 16:9 · 1200 × 675 px', 'vereinsplugin' ),
			'plattformen' => __( 'X, Bluesky, Mastodon, Facebook', 'vereinsplugin' ),
			'kanal'       => 'social',
		),
		'story'     => array(
			'titel'       => __( 'Story & Status · 9:16 · 1080 × 1920 px', 'vereinsplugin' ),
			'plattformen' => __( 'Instagram- und Facebook-Story, WhatsApp-Status, Signal-Status, TikTok', 'vereinsplugin' ),
			'kanal'       => 'social',
		),
		'fb_event'  => array(
			'titel'       => __( 'Facebook-Veranstaltung: Titelbild · ~1,9:1 · 1920 × 1005 px', 'vereinsplugin' ),
			'plattformen' => __( 'Nur nötig, wenn ihr eine Facebook-Veranstaltung anlegt', 'vereinsplugin' ),
			'kanal'       => 'social',
		),
		'profil'    => array(
			'titel'       => __( 'Kanal-/Gruppenbild · 1:1 · 800 × 800 px', 'vereinsplugin' ),
			'plattformen' => __( 'WhatsApp-Kanal, Telegram-Gruppe, Signal-Gruppe, Veranstaltungslogo', 'vereinsplugin' ),
			'kanal'       => 'messenger',
		),
		'plakat'    => array(
			'titel'       => __( 'Plakat A3 hoch · 297 × 420 mm · 300 dpi', 'vereinsplugin' ),
			'plattformen' => __( 'Druck-PDF mit 3 mm Beschnitt; A2 für große Flächen, A4 für Schaukästen', 'vereinsplugin' ),
			'kanal'       => 'plakat',
		),
		'flyer'     => array(
			'titel'       => __( 'Flyer / Handzettel A6 · 105 × 148 mm · 300 dpi', 'vereinsplugin' ),
			'plattformen' => __( 'Zum Auslegen und Verteilen; Rückseite für Programm und Anfahrt nutzen', 'vereinsplugin' ),
			'kanal'       => 'plakat',
		),
	) );
}

/** Die Bildformate als Checklisten-Einträge für den Baustein „Bilder". */
function vp_projekt_bild_formate_punkte() {
	$punkte = array();
	foreach ( vp_projekt_bild_formate() as $f ) {
		$punkte[] = array(
			'kanal' => $f['kanal'],
			'titel' => sprintf( __( 'Bild: %s', 'vereinsplugin' ), $f['titel'] ),
			'tage'  => -18,
			'text'  => $f['plattformen'],
		);
	}
	return $punkte;
}

/* -------------------------------------------------------------------------
 * Katalog
 * ---------------------------------------------------------------------- */

function vp_projekt_baustein_gruppen() {
	return array(
		'basis'           => __( 'Grundgerüst', 'vereinsplugin' ),
		'oeffentlichkeit' => __( 'Öffentlichkeitsarbeit', 'vereinsplugin' ),
		'recht'           => __( 'Genehmigungen, Recht & Sicherheit', 'vereinsplugin' ),
		'organisation'    => __( 'Organisation & Programm', 'vereinsplugin' ),
	);
}

/**
 * Alle Bausteine.
 *
 * Aufbau eines Bausteins:
 *   label, kurz, gruppe, arten (Vorschlagsfilter, leer = immer), hinweis
 *   ablauf[]          phase, titel, tage, zeit, text
 *   oeffentlichkeit[] kanal, titel, tage, text
 *   kalkulation[]     titel, richtung (ausgabe|einnahme), text
 *   todos[]           titel, tage, text
 * `tage` zählt relativ zum Projektbeginn (negativ = vorher).
 */
function vp_projekt_bausteine() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$b = array();

	/* ---- Grundgerüst ---------------------------------------------------- */

	$b['ablauf_basis'] = array(
		'label'  => __( 'Ablauf: Vorbereitung, Tag, Nachbereitung', 'vereinsplugin' ),
		'kurz'   => __( 'Die Meilensteine von der ersten Idee bis zur Auswertung.', 'vereinsplugin' ),
		'gruppe' => 'basis',
		'arten'  => array(),
		'ablauf' => array(
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Ziel, Rahmen und Verantwortliche klären', 'vereinsplugin' ), 'tage' => -56, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Kalkulation & Budget, Genehmigungen/Raum anfragen', 'vereinsplugin' ), 'tage' => -42, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Helfende anfragen, Schichtplan veröffentlichen', 'vereinsplugin' ), 'tage' => -28, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Öffentlichkeitsarbeit startet', 'vereinsplugin' ), 'tage' => -21, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Einkauf, Material & Technik bereit', 'vereinsplugin' ), 'tage' => -3, 'zeit' => '18:00' ),
			array( 'phase' => 'durchfuehrung', 'titel' => __( 'Aufbau', 'vereinsplugin' ), 'tage' => 0, 'zeit' => '', 'versatz' => '-2 hours' ),
			array( 'phase' => 'durchfuehrung', 'titel' => __( 'Briefing der Helfenden', 'vereinsplugin' ), 'tage' => 0, 'zeit' => '', 'versatz' => '-30 minutes' ),
			array( 'phase' => 'durchfuehrung', 'titel' => __( 'Beginn / Einlass', 'vereinsplugin' ), 'tage' => 0, 'zeit' => '' ),
			array( 'phase' => 'durchfuehrung', 'titel' => __( 'Ende & Abbau', 'vereinsplugin' ), 'tage' => 0, 'zeit' => '', 'ende' => true ),
			array( 'phase' => 'nachbereitung', 'titel' => __( 'Danke an Helfende, Nachbericht & Fotos', 'vereinsplugin' ), 'tage' => 3, 'zeit' => '18:00' ),
			array( 'phase' => 'nachbereitung', 'titel' => __( 'Belege einreichen & abrechnen', 'vereinsplugin' ), 'tage' => 7, 'zeit' => '18:00' ),
			array( 'phase' => 'nachbereitung', 'titel' => __( 'Auswertung im Kreis: Was lief gut, was nehmen wir mit?', 'vereinsplugin' ), 'tage' => 14, 'zeit' => '18:00' ),
		),
	);

	$b['orga_basis'] = array(
		'label'  => __( 'Orga-Grundlagen: Ort, Team, Material', 'vereinsplugin' ),
		'kurz'   => __( 'Die ToDos, die bei jeder Veranstaltung anfallen.', 'vereinsplugin' ),
		'gruppe' => 'basis',
		'arten'  => array(),
		'todos'  => array(
			array( 'titel' => __( 'Ort/Raum anfragen und verbindlich buchen', 'vereinsplugin' ), 'tage' => -56 ),
			array( 'titel' => __( 'Termin mit Kreis, Vorstand und anderen Veranstaltungen abgleichen', 'vereinsplugin' ), 'tage' => -56 ),
			array( 'titel' => __( 'Verantwortlichkeiten festlegen: Leitung, Technik, Kasse, Presse', 'vereinsplugin' ), 'tage' => -49 ),
			array( 'titel' => __( 'Material- und Packliste schreiben', 'vereinsplugin' ), 'tage' => -21 ),
			array( 'titel' => __( 'Schlüssel, Zugang und Ansprechperson vor Ort klären', 'vereinsplugin' ), 'tage' => -7 ),
			array( 'titel' => __( 'Auf- und Abbauteam einteilen', 'vereinsplugin' ), 'tage' => -7 ),
			array( 'titel' => __( 'Kasse und Wechselgeld vorbereiten', 'vereinsplugin' ), 'tage' => -2 ),
			array( 'titel' => __( 'Müllentsorgung und Endreinigung klären', 'vereinsplugin' ), 'tage' => -2 ),
		),
	);

	$b['kalkulation_basis'] = array(
		'label'       => __( 'Kostenkalkulation (Grundgerüst)', 'vereinsplugin' ),
		'kurz'        => __( 'Die üblichen Posten als Gerüst – Beträge tragt ihr selbst ein.', 'vereinsplugin' ),
		'gruppe'      => 'basis',
		'arten'       => array(),
		'hinweis'     => __( 'Die Posten werden ohne Betrag angelegt. Tragt eure Schätzung ein – die Summe der Ausgaben ist der Vorschlag für den Budgetantrag beim Kreis.', 'vereinsplugin' ),
		'kalkulation' => array(
			array( 'titel' => __( 'Raum-/Platzmiete', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Material & Deko', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Druck: Plakate, Flyer', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Verpflegung & Getränke', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Technik: Miete, Strom', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Honorare & Fahrtkosten', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Gebühren: Genehmigungen, GEMA, Versicherung', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Puffer für Unvorhergesehenes (ca. 10 %)', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Eintritt / Teilnahmebeitrag', 'vereinsplugin' ), 'richtung' => 'einnahme' ),
			array( 'titel' => __( 'Verkauf: Getränke, Essen', 'vereinsplugin' ), 'richtung' => 'einnahme' ),
			array( 'titel' => __( 'Spenden', 'vereinsplugin' ), 'richtung' => 'einnahme' ),
			array( 'titel' => __( 'Zuschuss / Förderung', 'vereinsplugin' ), 'richtung' => 'einnahme' ),
			array( 'titel' => __( 'Eigenmittel des Kreises', 'vereinsplugin' ), 'richtung' => 'einnahme' ),
		),
		'todos'       => array(
			array( 'titel' => __( 'Kalkulation im Kreis beschließen und Budget beantragen', 'vereinsplugin' ), 'tage' => -42 ),
			array( 'titel' => __( 'Nach der Veranstaltung: Ist-Kosten gegen die Kalkulation stellen', 'vereinsplugin' ), 'tage' => 14 ),
		),
	);

	/* ---- Öffentlichkeitsarbeit ------------------------------------------ */

	$b['pr_basis'] = array(
		'label'           => __( 'Öffentlichkeitsarbeit: Zeitplan', 'vereinsplugin' ),
		'kurz'            => __( 'Wann welcher Kanal dran ist – von der Ankündigung bis zum Danke-Post.', 'vereinsplugin' ),
		'gruppe'          => 'oeffentlichkeit',
		'arten'           => array(),
		'oeffentlichkeit' => array(
			array( 'kanal' => 'website', 'titel' => __( 'Termin auf Website & in Veranstaltungskalender eintragen', 'vereinsplugin' ), 'tage' => -28 ),
			array( 'kanal' => 'plakat', 'titel' => __( 'Plakate & Flyer gestalten, drucken und aushängen', 'vereinsplugin' ), 'tage' => -21 ),
			array( 'kanal' => 'kooperation', 'titel' => __( 'Partner, Schulen und andere Vereine informieren', 'vereinsplugin' ), 'tage' => -21 ),
			array( 'kanal' => 'social', 'titel' => __( 'Ankündigung auf Social Media', 'vereinsplugin' ), 'tage' => -14 ),
			array( 'kanal' => 'presse', 'titel' => __( 'Pressemitteilung an die Lokalpresse', 'vereinsplugin' ), 'tage' => -10 ),
			array( 'kanal' => 'newsletter', 'titel' => __( 'Newsletter an Mitglieder', 'vereinsplugin' ), 'tage' => -7 ),
			array( 'kanal' => 'messenger', 'titel' => __( 'Erinnerung in Messenger-Gruppen und Story', 'vereinsplugin' ), 'tage' => -2 ),
			array( 'kanal' => 'sonstiges', 'titel' => __( 'Fotos machen (Einverständnis beachten)', 'vereinsplugin' ), 'tage' => 0 ),
			array( 'kanal' => 'social', 'titel' => __( 'Nachbericht & Danke-Post', 'vereinsplugin' ), 'tage' => 2 ),
		),
	);

	$b['pr_texte'] = array(
		'label'           => __( 'Texte für alle Kanäle', 'vereinsplugin' ),
		'kurz'            => __( 'Checkliste: welcher Text in welcher Länge für welchen Kanal.', 'vereinsplugin' ),
		'gruppe'          => 'oeffentlichkeit',
		'arten'           => array(),
		'hinweis'         => __( 'Einmal gründlich schreiben, dann kürzen: aus dem Website-Text wird der Newsletter-Abschnitt, daraus der Social-Media-Post, daraus die Messenger-Nachricht. Eckdaten (was, wann, wo, für wen, Eintritt) stehen in jedem Text.', 'vereinsplugin' ),
		'oeffentlichkeit' => array(
			array( 'kanal' => 'website', 'titel' => __( 'Text Website/WordPress: Titel, Teaser (2–3 Sätze), Fließtext, Eckdaten', 'vereinsplugin' ), 'tage' => -28, 'text' => __( 'Eckdaten nach oben, Fließtext darunter. Überschrift sagt, was passiert – nicht nur den Veranstaltungsnamen.', 'vereinsplugin' ) ),
			array( 'kanal' => 'website', 'titel' => __( 'Kurztext für den Veranstaltungskalender (max. ~300 Zeichen)', 'vereinsplugin' ), 'tage' => -28 ),
			array( 'kanal' => 'newsletter', 'titel' => __( 'Newsletter-Abschnitt: Betreffzeile, 5–8 Zeilen, ein klarer Link', 'vereinsplugin' ), 'tage' => -14 ),
			array( 'kanal' => 'presse', 'titel' => __( 'Pressemitteilung: W-Fragen im ersten Absatz, ein Zitat, Pressekontakt', 'vereinsplugin' ), 'tage' => -10, 'text' => __( 'Wer, was, wann, wo, warum – im ersten Absatz. Dann ein Zitat der Kreisleitung, am Ende Kontakt und Hinweis auf Bildmaterial.', 'vereinsplugin' ) ),
			array( 'kanal' => 'social', 'titel' => __( 'Social-Media-Post lang: Instagram, Facebook, Mastodon (+ Hashtags)', 'vereinsplugin' ), 'tage' => -14 ),
			array( 'kanal' => 'social', 'titel' => __( 'Social-Media-Post kurz: Bluesky, X – max. 300 Zeichen + Link', 'vereinsplugin' ), 'tage' => -14 ),
			array( 'kanal' => 'social', 'titel' => __( 'Story-/Status-Text: ein Satz, Datum, Sticker mit Link oder Countdown', 'vereinsplugin' ), 'tage' => -3 ),
			array( 'kanal' => 'messenger', 'titel' => __( 'Kurznachricht für WhatsApp-, Signal- und Telegram-Gruppen zum Weiterleiten', 'vereinsplugin' ), 'tage' => -3, 'text' => __( 'So schreiben, dass Mitglieder sie ohne Änderung weiterleiten können: 3–4 Zeilen, Datum, Ort, Link.', 'vereinsplugin' ) ),
			array( 'kanal' => 'social', 'titel' => __( 'Erinnerungs-Post am Vortag', 'vereinsplugin' ), 'tage' => -1 ),
			array( 'kanal' => 'social', 'titel' => __( 'Nachbericht und Danke-Post mit Fotos', 'vereinsplugin' ), 'tage' => 2 ),
		),
		'todos'           => array(
			array( 'titel' => __( 'Wer schreibt welchen Text? Zuständigkeiten verteilen', 'vereinsplugin' ), 'tage' => -35 ),
			array( 'titel' => __( 'Texte gegenlesen lassen (Vier-Augen-Prinzip)', 'vereinsplugin' ), 'tage' => -16 ),
		),
	);

	$b['pr_bilder'] = array(
		'label'           => __( 'Bilder & Formate für alle Plattformen', 'vereinsplugin' ),
		'kurz'            => __( 'Ein Motiv, alle Zuschnitte: Feed, Story, Titelbild, Plakat.', 'vereinsplugin' ),
		'gruppe'          => 'oeffentlichkeit',
		'arten'           => array(),
		'hinweis'         => __( 'Gestaltet ein Grundmotiv und exportiert daraus die Formate. Wichtig: Schrift und Logo so setzen, dass beim Zuschnitt auf 1:1 und 9:16 nichts abgeschnitten wird – in der Story bleiben oben und unten je ~250 px für Bedienelemente frei. Die Maße sind Richtwerte; die Plattformen ändern sie gelegentlich.', 'vereinsplugin' ),
		'oeffentlichkeit' => array_merge(
			array(
				array( 'kanal' => 'sonstiges', 'titel' => __( 'Grundmotiv gestalten (daraus alle Formate ableiten)', 'vereinsplugin' ), 'tage' => -24, 'text' => __( 'Titel, Datum, Uhrzeit, Ort, Logo, ggf. Logos der Fördernden. Kontraste prüfen – Text muss auch auf dem Handy lesbar sein.', 'vereinsplugin' ) ),
			),
			vp_projekt_bild_formate_punkte(),
			array(
				array( 'kanal' => 'sonstiges', 'titel' => __( 'Bildrechte klären: Einverständnis der abgebildeten Personen, Quellenangaben', 'vereinsplugin' ), 'tage' => -21 ),
				array( 'kanal' => 'sonstiges', 'titel' => __( 'Alt-Texte für alle Bilder schreiben (Barrierefreiheit)', 'vereinsplugin' ), 'tage' => -10 ),
				array( 'kanal' => 'sonstiges', 'titel' => __( 'Fotograf:in für die Veranstaltung einteilen', 'vereinsplugin' ), 'tage' => -7 ),
				array( 'kanal' => 'sonstiges', 'titel' => __( 'Fotos sichten, auswählen und für Nachbericht & Presse aufbereiten', 'vereinsplugin' ), 'tage' => 2 ),
			)
		),
	);

	$b['pr_presse'] = array(
		'label'           => __( 'Pressearbeit', 'vereinsplugin' ),
		'kurz'            => __( 'Verteiler, Redaktionsschluss, Nachfassen, Nachbericht.', 'vereinsplugin' ),
		'gruppe'          => 'oeffentlichkeit',
		'arten'           => array(),
		'hinweis'         => __( 'Amts- und Mitteilungsblätter haben feste Redaktionsschlüsse, oft ein bis zwei Wochen vor Erscheinen – der Termin dafür liegt also deutlich vor der Pressemitteilung an die Tageszeitung. Fragt einmal nach dem Redaktionsplan und tragt die Termine fürs ganze Jahr ein.', 'vereinsplugin' ),
		'oeffentlichkeit' => array(
			array( 'kanal' => 'presse', 'titel' => __( 'Presseverteiler aktualisieren: Lokalzeitung, Radio, Amtsblatt, Online-Portale', 'vereinsplugin' ), 'tage' => -35 ),
			array( 'kanal' => 'presse', 'titel' => __( 'Termin fürs Amts-/Mitteilungsblatt einreichen (Redaktionsschluss beachten)', 'vereinsplugin' ), 'tage' => -28 ),
			array( 'kanal' => 'presse', 'titel' => __( 'Pressemitteilung verschicken: Text, 1–2 Fotos, Bildunterschrift, Kontakt', 'vereinsplugin' ), 'tage' => -10 ),
			array( 'kanal' => 'presse', 'titel' => __( 'Nachfassen: Redaktion anrufen, Fototermin anbieten', 'vereinsplugin' ), 'tage' => -5 ),
			array( 'kanal' => 'presse', 'titel' => __( 'Nachbericht mit Foto an die Presse schicken', 'vereinsplugin' ), 'tage' => 2 ),
		),
		'todos'           => array(
			array( 'titel' => __( 'Pressekontakt im Kreis festlegen: eine Person, eine Nummer, eine Mailadresse', 'vereinsplugin' ), 'tage' => -35 ),
		),
	);

	/* ---- Genehmigungen, Recht & Sicherheit ------------------------------ */

	$b['gema'] = array(
		'label'       => __( 'Musik & GEMA', 'vereinsplugin' ),
		'kurz'        => __( 'Anmeldung vorher, Musikfolge nachher, Gebühren einplanen.', 'vereinsplugin' ),
		'gruppe'      => 'recht',
		'arten'       => array( 'veranstaltung', 'aktion' ),
		'hinweis'     => __( 'Öffentliche Musiknutzung ist in aller Regel GEMA-pflichtig – auch die Playlist vom Handy. Der Tarif hängt an Raumgröße bzw. Besucherzahl und am Eintrittsgeld. Meldet vor der Veranstaltung an: nachträgliche Meldungen sind teurer. Viele Dachverbände (Landesjugendring, Stadtjugendring, BDKJ, Sportbund …) haben Rahmenverträge mit deutlichen Nachlässen – vorher prüfen, ob euer Verein darunter fällt. Bei Live-Musik kommt zusätzlich die Künstlersozialabgabe auf Honorare in Betracht.', 'vereinsplugin' ),
		'ablauf'      => array(
			array( 'phase' => 'vorbereitung', 'titel' => __( 'GEMA: Tarif und Rahmenvertrag des Dachverbands prüfen', 'vereinsplugin' ), 'tage' => -35, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'GEMA: Veranstaltung anmelden', 'vereinsplugin' ), 'tage' => -28, 'zeit' => '18:00', 'text' => __( 'Online im GEMA-Portal, vor der Veranstaltung. Angaben: Datum, Raumgröße/Besucherzahl, Eintritt, Art der Musiknutzung.', 'vereinsplugin' ) ),
			array( 'phase' => 'nachbereitung', 'titel' => __( 'GEMA: Musikfolge/Setlist einreichen', 'vereinsplugin' ), 'tage' => 7, 'zeit' => '18:00' ),
			array( 'phase' => 'nachbereitung', 'titel' => __( 'GEMA-Rechnung prüfen und bezahlen', 'vereinsplugin' ), 'tage' => 21, 'zeit' => '18:00' ),
		),
		'kalkulation' => array(
			array( 'titel' => __( 'GEMA-Gebühr', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Gage / Honorar der Musiker:innen', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Künstlersozialabgabe auf Honorare', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
		),
		'todos'       => array(
			array( 'titel' => __( 'Klären: Live-Musik, Playlist oder beides?', 'vereinsplugin' ), 'tage' => -42 ),
			array( 'titel' => __( 'Bei Live-Bands: Setlist von der Band anfordern', 'vereinsplugin' ), 'tage' => -3 ),
		),
	);

	$b['ausschank'] = array(
		'label'       => __( 'Ausschank & Gestattung (§ 12 GastG)', 'vereinsplugin' ),
		'kurz'        => __( 'Gestattung beantragen, Jugendschutz, Einkauf, Pfand, Abrechnung.', 'vereinsplugin' ),
		'gruppe'      => 'recht',
		'arten'       => array( 'veranstaltung', 'aktion' ),
		'hinweis'     => __( 'Für den Ausschank bei einem einzelnen Fest genügt meist eine vorübergehende Gestattung nach § 12 Gaststättengesetz. Beantragt wird sie beim Ordnungs- oder Gewerbeamt der Kommune – je nach Ort mehrere Wochen vorher und gegen eine Gebühr. Dazu kommen Jugendschutz (Aushang, Ausweiskontrolle: Bier und Wein ab 16, Spirituosen ab 18), Sperrzeiten und – bei nennenswerten Einnahmen – die Frage nach dem steuerlichen Geschäftsbetrieb. Was genau für euch gilt, sagt euch die Kommune; fragt früh nach dem Merkblatt.', 'vereinsplugin' ),
		'ablauf'      => array(
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Gestattung nach § 12 GastG beim Ordnungs-/Gewerbeamt beantragen', 'vereinsplugin' ), 'tage' => -42, 'zeit' => '18:00', 'text' => __( 'Bearbeitungszeit und Gebühr sind je Kommune verschieden – früh anfragen. Oft wird die verantwortliche Person namentlich eingetragen.', 'vereinsplugin' ) ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Getränke bestellen – mit Rückgaberecht für Unverkauftes', 'vereinsplugin' ), 'tage' => -14, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Ausschankteam einteilen: nur Volljährige, Namen vorher festhalten', 'vereinsplugin' ), 'tage' => -10, 'zeit' => '18:00' ),
			array( 'phase' => 'durchfuehrung', 'titel' => __( 'Jugendschutz-Aushang anbringen, Ausweiskontrolle/Bändchen starten', 'vereinsplugin' ), 'tage' => 0, 'zeit' => '', 'versatz' => '-1 hour' ),
			array( 'phase' => 'nachbereitung', 'titel' => __( 'Leergut zurückbringen, Ausschank abrechnen', 'vereinsplugin' ), 'tage' => 3, 'zeit' => '18:00' ),
		),
		'kalkulation' => array(
			array( 'titel' => __( 'Getränkeeinkauf', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Pfand (Fässer, Kisten, Becher)', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Gebühr für die Gestattung', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Kühlung, Zapfanlage, Eis', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Mehrwegbecher', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Getränkeverkauf', 'vereinsplugin' ), 'richtung' => 'einnahme' ),
			array( 'titel' => __( 'Pfandrückgabe', 'vereinsplugin' ), 'richtung' => 'einnahme' ),
		),
		'todos'       => array(
			array( 'titel' => __( 'Mehrwegbecher organisieren, Pfandsystem festlegen', 'vereinsplugin' ), 'tage' => -14 ),
			array( 'titel' => __( 'Preise festlegen und Preisliste gut sichtbar aushängen', 'vereinsplugin' ), 'tage' => -7 ),
			array( 'titel' => __( 'Mindestens ein alkoholfreies Getränk nicht teurer als das billigste alkoholische anbieten', 'vereinsplugin' ), 'tage' => -7 ),
			array( 'titel' => __( 'Team zum Jugendschutz briefen: Bier/Wein ab 16, Spirituosen ab 18, im Zweifel Ausweis', 'vereinsplugin' ), 'tage' => -3 ),
		),
	);

	$b['essen'] = array(
		'label'       => __( 'Essen & Lebensmittelhygiene', 'vereinsplugin' ),
		'kurz'        => __( 'Belehrung, Allergene, Kühlkette, Spülen.', 'vereinsplugin' ),
		'gruppe'      => 'recht',
		'arten'       => array( 'veranstaltung', 'aktion', 'fahrt' ),
		'hinweis'     => __( 'Wer Lebensmittel zubereitet oder ausgibt, braucht meist eine Erstbelehrung nach § 43 Infektionsschutzgesetz (Gesundheitsamt), und die 14 Hauptallergene müssen gekennzeichnet sein. Für ein reines Kuchenbuffet gelten oft erleichterte Regeln. Ein Anruf beim Gesundheitsamt klärt, was in eurem Fall nötig ist.', 'vereinsplugin' ),
		'ablauf'      => array(
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Belehrung nach § 43 IfSG für alle, die mit Lebensmitteln arbeiten', 'vereinsplugin' ), 'tage' => -21, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Einkauf planen, Kühlkette und Lagerung klären', 'vereinsplugin' ), 'tage' => -7, 'zeit' => '18:00' ),
			array( 'phase' => 'durchfuehrung', 'titel' => __( 'Allergenkennzeichnung aushängen, Handwaschmöglichkeit einrichten', 'vereinsplugin' ), 'tage' => 0, 'zeit' => '', 'versatz' => '-1 hour' ),
			array( 'phase' => 'nachbereitung', 'titel' => __( 'Spülen, Reste verwerten oder abgeben, Reinigung', 'vereinsplugin' ), 'tage' => 1, 'zeit' => '18:00' ),
		),
		'kalkulation' => array(
			array( 'titel' => __( 'Lebensmitteleinkauf', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Geschirr, Verpackung, Servietten', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Essensverkauf', 'vereinsplugin' ), 'richtung' => 'einnahme' ),
		),
		'todos'       => array(
			array( 'titel' => __( 'Beim Gesundheitsamt nachfragen, was für euren Rahmen nötig ist', 'vereinsplugin' ), 'tage' => -28 ),
			array( 'titel' => __( 'Vegetarische und vegane Option einplanen', 'vereinsplugin' ), 'tage' => -14 ),
			array( 'titel' => __( 'Zutaten und Allergene für jedes Gericht notieren', 'vereinsplugin' ), 'tage' => -3 ),
		),
	);

	$b['behoerden'] = array(
		'label'       => __( 'Flächen, Lärm & Anmeldungen', 'vereinsplugin' ),
		'kurz'        => __( 'Sondernutzung, Sperrzeit, Versammlung, Anwohnende.', 'vereinsplugin' ),
		'gruppe'      => 'recht',
		'arten'       => array( 'veranstaltung', 'aktion' ),
		'hinweis'     => __( 'Genehmigungen laufen je nach Kommune über Ordnungsamt, Gewerbeamt oder Bauhof, die Vorlaufzeiten schwanken stark (oft vier bis acht Wochen). Ein früher Anruf beim Ordnungsamt spart später viel Zeit – fragt nach dem Merkblatt für Veranstaltungen und nach einer festen Ansprechperson.', 'vereinsplugin' ),
		'ablauf'      => array(
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Sondernutzungserlaubnis für öffentliche Flächen beantragen (Platz, Straße, Gehweg)', 'vereinsplugin' ), 'tage' => -49, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Ausnahme vom Lärmschutz / Sperrzeitverkürzung beantragen', 'vereinsplugin' ), 'tage' => -42, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Bei Kundgebung/Demonstration: Versammlung bei der Versammlungsbehörde anmelden', 'vereinsplugin' ), 'tage' => -14, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Anwohnende informieren (Handzettel, Aushang)', 'vereinsplugin' ), 'tage' => -10, 'zeit' => '18:00' ),
		),
		'kalkulation' => array(
			array( 'titel' => __( 'Verwaltungsgebühren', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
		),
		'todos'       => array(
			array( 'titel' => __( 'Ansprechperson bei der Kommune festhalten: Name, Telefon, E-Mail', 'vereinsplugin' ), 'tage' => -49 ),
			array( 'titel' => __( 'Alle Bescheide sammeln und am Veranstaltungstag dabeihaben', 'vereinsplugin' ), 'tage' => -1 ),
		),
	);

	$b['sicherheit'] = array(
		'label'       => __( 'Sicherheit, Sanitätsdienst & Brandschutz', 'vereinsplugin' ),
		'kurz'        => __( 'Sanitäter:innen, Fluchtwege, Aufsicht, Notfallplan.', 'vereinsplugin' ),
		'gruppe'      => 'recht',
		'arten'       => array( 'veranstaltung', 'aktion', 'fahrt' ),
		'hinweis'     => __( 'Ab einer gewissen Größe – oft bei mehreren hundert Personen, bei Bühnen, Zelten oder Feuer – verlangt die Kommune ein Sicherheitskonzept und einen Sanitätsdienst. Die Hilfsorganisationen beraten kostenlos, was für eure Größe angemessen ist. Bei Minderjährigen gehört ein Betreuungsschlüssel dazu.', 'vereinsplugin' ),
		'ablauf'      => array(
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Bei größeren Veranstaltungen: Sicherheitskonzept schreiben und abstimmen', 'vereinsplugin' ), 'tage' => -49, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Sanitätsdienst anfragen (DRK, Malteser, Johanniter, ASB)', 'vereinsplugin' ), 'tage' => -42, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Brandschutz klären: Fluchtwege, Feuerlöscher, Rettungsweg freihalten', 'vereinsplugin' ), 'tage' => -21, 'zeit' => '18:00' ),
			array( 'phase' => 'durchfuehrung', 'titel' => __( 'Sicherheits-Briefing: Fluchtwege, Erste Hilfe, Notruf, wer entscheidet', 'vereinsplugin' ), 'tage' => 0, 'zeit' => '', 'versatz' => '-45 minutes' ),
		),
		'kalkulation' => array(
			array( 'titel' => __( 'Sanitätsdienst', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Ordner-/Sicherheitsdienst', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Feuerlöscher, Absperrungen, Beschilderung', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
		),
		'todos'       => array(
			array( 'titel' => __( 'Aufsichtspersonen festlegen, bei Minderjährigen Betreuungsschlüssel klären', 'vereinsplugin' ), 'tage' => -14 ),
			array( 'titel' => __( 'Notfallnummern und die genaue Adresse des Orts auf einen Zettel – für alle im Team', 'vereinsplugin' ), 'tage' => -3 ),
		),
	);

	$b['versicherung'] = array(
		'label'       => __( 'Versicherung, Datenschutz & Fotos', 'vereinsplugin' ),
		'kurz'        => __( 'Haftpflicht prüfen, Fotoeinwilligung, Umgang mit Daten.', 'vereinsplugin' ),
		'gruppe'      => 'recht',
		'arten'       => array(),
		'hinweis'     => __( 'Viele Vereine sind über den Dachverband oder eine Sammelversicherung des Landes haftpflichtversichert – aber nicht automatisch für jede Veranstaltungsform. Einmal nachfragen kostet nichts. Für Fotos gilt: Hinweis am Eingang, bei Minderjährigen die Einwilligung der Eltern, und im Zweifel keine Nahaufnahme veröffentlichen.', 'vereinsplugin' ),
		'ablauf'      => array(
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Veranstalterhaftpflicht prüfen: deckt die Vereinsversicherung diese Veranstaltung?', 'vereinsplugin' ), 'tage' => -42, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Bei geliehener Technik, Zelt oder Bühne: Sachversicherung klären', 'vereinsplugin' ), 'tage' => -14, 'zeit' => '18:00' ),
		),
		'kalkulation' => array(
			array( 'titel' => __( 'Versicherungsbeitrag', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
		),
		'todos'       => array(
			array( 'titel' => __( 'Fotoeinwilligung vorbereiten: Aushang am Eingang, bei Minderjährigen Einwilligung der Eltern', 'vereinsplugin' ), 'tage' => -14 ),
			array( 'titel' => __( 'Datenschutz klären: Wer bekommt die Anmeldedaten, wie lange werden sie aufbewahrt?', 'vereinsplugin' ), 'tage' => -14 ),
		),
	);

	/* ---- Organisation & Programm ---------------------------------------- */

	$b['technik'] = array(
		'label'       => __( 'Technik, Strom & Ausstattung', 'vereinsplugin' ),
		'kurz'        => __( 'Ton, Licht, Bühne, Zelt, Tische, Transport, Regenplan.', 'vereinsplugin' ),
		'gruppe'      => 'organisation',
		'arten'       => array( 'veranstaltung', 'aktion', 'workshop' ),
		'ablauf'      => array(
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Technik buchen: Tonanlage, Mikrofone, Licht, Bühne', 'vereinsplugin' ), 'tage' => -35, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Strombedarf ermitteln, Anschlüsse und Verteiler organisieren', 'vereinsplugin' ), 'tage' => -28, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Zelt/Pavillons, Tische, Bänke und Stühle organisieren', 'vereinsplugin' ), 'tage' => -28, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Transport klären: Wer holt was, mit welchem Fahrzeug, wann?', 'vereinsplugin' ), 'tage' => -7, 'zeit' => '18:00' ),
			array( 'phase' => 'durchfuehrung', 'titel' => __( 'Soundcheck und Technikprobe', 'vereinsplugin' ), 'tage' => 0, 'zeit' => '', 'versatz' => '-90 minutes' ),
		),
		'kalkulation' => array(
			array( 'titel' => __( 'Technikmiete', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Strom / Generator', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Zelt- und Mobiliarmiete', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Transport, Sprit, Anhänger', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
		),
		'todos'       => array(
			array( 'titel' => __( 'Regenplan: Was passiert bei schlechtem Wetter?', 'vereinsplugin' ), 'tage' => -14 ),
			array( 'titel' => __( 'Verlängerungskabel, Mehrfachstecker und Kabelbrücken einpacken', 'vereinsplugin' ), 'tage' => -3 ),
		),
	);

	$b['anmeldung'] = array(
		'label'           => __( 'Anmeldung & Teilnehmende', 'vereinsplugin' ),
		'kurz'            => __( 'Formular, Anmeldeschluss, Bestätigungen, Liste, Feedback.', 'vereinsplugin' ),
		'gruppe'          => 'organisation',
		'arten'           => array( 'workshop', 'fahrt', 'veranstaltung' ),
		'hinweis'         => __( 'Das Anmeldeformular legt ihr direkt im Projekt an: Übersicht → Verknüpft mit → Anmeldung. Die Zahl der Anmeldungen erscheint dann als Kennzahl auf der Übersicht.', 'vereinsplugin' ),
		'ablauf'          => array(
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Anmeldeformular bauen und veröffentlichen', 'vereinsplugin' ), 'tage' => -42, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Anmeldeschluss setzen und kommunizieren', 'vereinsplugin' ), 'tage' => -14, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Teilnehmendenliste ausdrucken, Namensschilder vorbereiten', 'vereinsplugin' ), 'tage' => -2, 'zeit' => '18:00' ),
		),
		'oeffentlichkeit' => array(
			array( 'kanal' => 'website', 'titel' => __( 'Anmeldelink auf Website, in Newsletter und Social Media setzen', 'vereinsplugin' ), 'tage' => -28 ),
		),
		'kalkulation'     => array(
			array( 'titel' => __( 'Teilnahmebeitrag', 'vereinsplugin' ), 'richtung' => 'einnahme' ),
		),
		'todos'           => array(
			array( 'titel' => __( 'Bestätigungsmail und Infomail vor der Veranstaltung schreiben', 'vereinsplugin' ), 'tage' => -21 ),
			array( 'titel' => __( 'Bei Minderjährigen: Einverständnis der Eltern einholen', 'vereinsplugin' ), 'tage' => -14 ),
			array( 'titel' => __( 'Absagen und Warteliste regeln', 'vereinsplugin' ), 'tage' => -10 ),
			array( 'titel' => __( 'Nach der Veranstaltung Feedback einholen', 'vereinsplugin' ), 'tage' => 3 ),
		),
	);

	$b['referent'] = array(
		'label'       => __( 'Referent:innen & Programm', 'vereinsplugin' ),
		'kurz'        => __( 'Anfrage, Honorar, Technikwünsche, Bescheinigungen.', 'vereinsplugin' ),
		'gruppe'      => 'organisation',
		'arten'       => array( 'workshop', 'veranstaltung' ),
		'hinweis'     => __( 'Honorare können steuerliche Folgen haben (Übungsleiter- oder Ehrenamtspauschale, Künstlersozialabgabe) – das kurz mit der Kassier:in klären, bevor ihr zusagt.', 'vereinsplugin' ),
		'ablauf'      => array(
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Referent:innen anfragen, Honorar und Fahrtkosten vereinbaren', 'vereinsplugin' ), 'tage' => -56, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Technik- und Materialwünsche der Referent:innen abfragen', 'vereinsplugin' ), 'tage' => -21, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Raum herrichten: Bestuhlung, Beamer, Flipchart, Verpflegung', 'vereinsplugin' ), 'tage' => -7, 'zeit' => '18:00' ),
			array( 'phase' => 'nachbereitung', 'titel' => __( 'Teilnahmebescheinigungen verschicken', 'vereinsplugin' ), 'tage' => 7, 'zeit' => '18:00' ),
			array( 'phase' => 'nachbereitung', 'titel' => __( 'Honorar abrechnen, Fahrtkosten erstatten', 'vereinsplugin' ), 'tage' => 14, 'zeit' => '18:00' ),
		),
		'kalkulation' => array(
			array( 'titel' => __( 'Honorar', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Fahrtkosten Referent:innen', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Seminarmaterial', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
		),
		'todos'       => array(
			array( 'titel' => __( 'Agenda/Zeitplan an die Teilnehmenden schicken', 'vereinsplugin' ), 'tage' => -3 ),
			array( 'titel' => __( 'Feedbackbogen vorbereiten', 'vereinsplugin' ), 'tage' => -7 ),
		),
	);

	$b['fahrt'] = array(
		'label'       => __( 'Fahrt, Transport & Unterkunft', 'vereinsplugin' ),
		'kurz'        => __( 'Bus, Unterkunft, Aufsicht, Notfallkontakte, Packliste.', 'vereinsplugin' ),
		'gruppe'      => 'organisation',
		'arten'       => array( 'fahrt' ),
		'ablauf'      => array(
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Transport buchen: Bus, Gruppenticket oder Fahrgemeinschaften', 'vereinsplugin' ), 'tage' => -56, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Unterkunft buchen und anzahlen', 'vereinsplugin' ), 'tage' => -56, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Programm und Zeitplan der Fahrt schreiben', 'vereinsplugin' ), 'tage' => -21, 'zeit' => '18:00' ),
			array( 'phase' => 'durchfuehrung', 'titel' => __( 'Anwesenheit prüfen – bei Abfahrt und vor der Rückfahrt', 'vereinsplugin' ), 'tage' => 0, 'zeit' => '' ),
			array( 'phase' => 'nachbereitung', 'titel' => __( 'Abrechnung: Belege, Restzahlungen, Rückerstattungen', 'vereinsplugin' ), 'tage' => 14, 'zeit' => '18:00' ),
		),
		'kalkulation' => array(
			array( 'titel' => __( 'Fahrtkosten', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Unterkunft', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Verpflegung', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
			array( 'titel' => __( 'Eintritte und Programm', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
		),
		'todos'       => array(
			array( 'titel' => __( 'Betreuungsschlüssel klären, Juleica-Nachweise prüfen', 'vereinsplugin' ), 'tage' => -21 ),
			array( 'titel' => __( 'Reise- und Rücktrittsversicherung prüfen', 'vereinsplugin' ), 'tage' => -30 ),
			array( 'titel' => __( 'Notfallkontakte und Gesundheitsbögen einsammeln', 'vereinsplugin' ), 'tage' => -14 ),
			array( 'titel' => __( 'Packliste verschicken', 'vereinsplugin' ), 'tage' => -10 ),
		),
	);

	$b['foerderung'] = array(
		'label'           => __( 'Förderung & Verwendungsnachweis', 'vereinsplugin' ),
		'kurz'            => __( 'Antrag vor Beginn, Logos, Belege, Nachweis.', 'vereinsplugin' ),
		'gruppe'          => 'organisation',
		'arten'           => array(),
		'hinweis'         => __( 'Förderanträge müssen fast immer VOR Beginn der Maßnahme gestellt sein – wer vorher einkauft, riskiert die Förderung. Grundlage für den Kosten- und Finanzierungsplan ist die Kalkulation aus dem Reiter Finanzen.', 'vereinsplugin' ),
		'ablauf'          => array(
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Fördermöglichkeiten recherchieren, Antragsfristen notieren', 'vereinsplugin' ), 'tage' => -120, 'zeit' => '18:00' ),
			array( 'phase' => 'vorbereitung', 'titel' => __( 'Förderantrag stellen (Kostenplan aus der Kalkulation)', 'vereinsplugin' ), 'tage' => -90, 'zeit' => '18:00' ),
			array( 'phase' => 'nachbereitung', 'titel' => __( 'Verwendungsnachweis erstellen und einreichen', 'vereinsplugin' ), 'tage' => 45, 'zeit' => '18:00' ),
		),
		'oeffentlichkeit' => array(
			array( 'kanal' => 'sonstiges', 'titel' => __( 'Logos der Fördernden auf Plakate, Website und Social Media setzen', 'vereinsplugin' ), 'tage' => -21 ),
		),
		'kalkulation'     => array(
			array( 'titel' => __( 'Zuschuss / Förderung', 'vereinsplugin' ), 'richtung' => 'einnahme' ),
			array( 'titel' => __( 'Eigenanteil des Vereins', 'vereinsplugin' ), 'richtung' => 'ausgabe' ),
		),
		'todos'           => array(
			array( 'titel' => __( 'Teilnehmendenliste und Nachweise für den Förderer führen', 'vereinsplugin' ), 'tage' => 0 ),
			array( 'titel' => __( 'Alle Belege sammeln und dem Projekt zuordnen', 'vereinsplugin' ), 'tage' => 7 ),
		),
	);

	$cache = apply_filters( 'vp_projekt_bausteine', $b );
	return $cache;
}

function vp_projekt_baustein( $key ) {
	$alle = vp_projekt_bausteine();
	return $alle[ $key ] ?? null;
}

/** Benannte Kombinationen – der Schnellstart. */
function vp_projekt_vorlagen() {
	return apply_filters( 'vp_projekt_vorlagen', array(
		'fest'      => array(
			'label'     => __( 'Fest mit Ausschank & Musik', 'vereinsplugin' ),
			'kurz'      => __( 'Sommerfest, Hoffest, Jubiläum: alles inklusive Gestattung, GEMA und Sicherheit.', 'vereinsplugin' ),
			'arten'     => array( 'veranstaltung' ),
			'bausteine' => array( 'ablauf_basis', 'orga_basis', 'kalkulation_basis', 'pr_basis', 'pr_texte', 'pr_bilder', 'pr_presse', 'gema', 'ausschank', 'essen', 'behoerden', 'sicherheit', 'technik', 'versicherung' ),
		),
		'konzert'   => array(
			'label'     => __( 'Konzert / Musikveranstaltung', 'vereinsplugin' ),
			'kurz'      => __( 'Bühne, Technik, GEMA und Gagen im Blick.', 'vereinsplugin' ),
			'arten'     => array( 'veranstaltung' ),
			'bausteine' => array( 'ablauf_basis', 'orga_basis', 'kalkulation_basis', 'pr_basis', 'pr_texte', 'pr_bilder', 'gema', 'technik', 'ausschank', 'sicherheit', 'versicherung' ),
		),
		'aktion'    => array(
			'label'     => __( 'Aktion / Infostand im öffentlichen Raum', 'vereinsplugin' ),
			'kurz'      => __( 'Standfläche anmelden, Material, Öffentlichkeitsarbeit.', 'vereinsplugin' ),
			'arten'     => array( 'aktion', 'veranstaltung' ),
			'bausteine' => array( 'ablauf_basis', 'orga_basis', 'kalkulation_basis', 'pr_basis', 'pr_texte', 'pr_bilder', 'pr_presse', 'behoerden', 'versicherung' ),
		),
		'workshop'  => array(
			'label'     => __( 'Workshop / Seminar', 'vereinsplugin' ),
			'kurz'      => __( 'Referent:innen, Anmeldung, Verpflegung, Förderung.', 'vereinsplugin' ),
			'arten'     => array( 'workshop' ),
			'bausteine' => array( 'ablauf_basis', 'orga_basis', 'kalkulation_basis', 'pr_texte', 'pr_bilder', 'anmeldung', 'referent', 'essen', 'foerderung' ),
		),
		'fahrt'     => array(
			'label'     => __( 'Fahrt / Ausflug', 'vereinsplugin' ),
			'kurz'      => __( 'Transport, Unterkunft, Aufsicht, Anmeldung, Abrechnung.', 'vereinsplugin' ),
			'arten'     => array( 'fahrt' ),
			'bausteine' => array( 'ablauf_basis', 'orga_basis', 'kalkulation_basis', 'anmeldung', 'fahrt', 'sicherheit', 'versicherung', 'foerderung' ),
		),
		'kampagne'  => array(
			'label'     => __( 'Reine Öffentlichkeitsarbeit', 'vereinsplugin' ),
			'kurz'      => __( 'Kampagne ohne Veranstaltung: Texte, Bilder, Presse, Zeitplan.', 'vereinsplugin' ),
			'arten'     => array( 'aktion', 'projekt' ),
			'bausteine' => array( 'pr_basis', 'pr_texte', 'pr_bilder', 'pr_presse' ),
		),
		'schlicht'  => array(
			'label'     => __( 'Nur das Nötigste', 'vereinsplugin' ),
			'kurz'      => __( 'Ablauf, Orga-ToDos und Kalkulation – den Rest nehmt ihr später dazu.', 'vereinsplugin' ),
			'arten'     => array(),
			'bausteine' => array( 'ablauf_basis', 'orga_basis', 'kalkulation_basis' ),
		),
	) );
}

/* -------------------------------------------------------------------------
 * Anwenden & Entfernen
 * ---------------------------------------------------------------------- */

/** Nur bekannte Schlüssel, ohne Dopplungen, in Katalogreihenfolge. */
function vp_projekt_baustein_keys( $keys ) {
	$keys = array_map( 'strval', array_filter( (array) $keys, 'is_scalar' ) );
	$keys = array_map( 'sanitize_key', $keys );
	return array_values( array_intersect( array_keys( vp_projekt_bausteine() ), $keys ) );
}

/** Die bereits angewendeten Bausteine eines Projekts. */
function vp_projekt_bausteine_von( $p ) {
	$roh = json_decode( (string) ( $p->bausteine ?? '' ), true );
	return is_array( $roh ) ? vp_projekt_baustein_keys( $roh ) : array();
}

function vp_projekt_bausteine_merken( $p, $keys ) {
	global $wpdb;
	$wpdb->update(
		vp_projekt_table(),
		array( 'bausteine' => wp_json_encode( array_values( $keys ) ), 'geaendert_am' => current_time( 'mysql' ) ),
		array( 'id' => (int) $p->id )
	);
	$p->bausteine = wp_json_encode( array_values( $keys ) );
}

/**
 * Zeitpunkt eines Baustein-Eintrags relativ zum Projektbeginn.
 * Ohne Beginn: null – die Checkliste steht, die Termine tragt ihr nach.
 */
/**
 * Längster Vorlauf aller Bausteine in Tagen (z. B. 90 bei Förderanträgen) –
 * das ist der „normale“ Planungshorizont, für den die Vorlagen gedacht sind.
 */
function vp_projekt_baustein_horizont() {
	static $h = null;
	if ( null === $h ) {
		$h = 1;
		foreach ( vp_projekt_bausteine() as $bs ) {
			foreach ( array( 'ablauf', 'oeffentlichkeit', 'todos' ) as $liste ) {
				foreach ( (array) ( $bs[ $liste ] ?? array() ) as $item ) {
					if ( empty( $item['versatz'] ) && empty( $item['ende'] ) && (int) ( $item['tage'] ?? 0 ) < 0 ) {
						$h = max( $h, abs( (int) $item['tage'] ) );
					}
				}
			}
		}
	}
	return (int) apply_filters( 'vp_projekt_baustein_horizont', $h );
}

/**
 * Vorlauf eines Bausteins an die tatsächlich verbleibende Zeit anpassen.
 *
 * Die Vorlagen rechnen mit einem normalen Horizont (vp_projekt_baustein_horizont).
 * Ist die Veranstaltung näher (spontan in 2 Wochen) oder viel weiter weg (in 12
 * Monaten), wird der Zeitplan auf „heute bis Veranstaltung“ verteilt: Die
 * frühesten Punkte beginnen heute, die kurzfristigen der letzten Tage (bis
 * 14 Tage, höchstens die halbe verbleibende Zeit) bleiben, wie sie sind.
 * Nach der Veranstaltung (positive Tage) ändert sich nichts.
 *
 * @return int Tage relativ zum Beginn (negativ = vorher)
 */
function vp_projekt_tage_skaliert( $p, $tage ) {
	$tage = (int) $tage;
	if ( $tage >= 0 || empty( $p->beginn ) || ! apply_filters( 'vp_projekt_zeitplan_skalieren', true, $p ) ) {
		return $tage;
	}
	$heute = strtotime( current_time( 'Y-m-d' ) );
	$v     = (int) floor( ( strtotime( gmdate( 'Y-m-d', strtotime( $p->beginn ) ) ) - $heute ) / DAY_IN_SECONDS );
	if ( $v <= 0 ) {
		return $tage; // Veranstaltung ist heute oder vorbei – nichts zu verteilen.
	}
	$r = max( 1, vp_projekt_baustein_horizont() );
	$k = min( (int) apply_filters( 'vp_projekt_zeitplan_kurzfristig', 14 ), $v / 2 );
	$a = abs( $tage );
	if ( $a <= $k ) {
		return $tage;
	}
	$neu = $r > $k ? $k + ( min( $a, $r ) - $k ) * ( $v - $k ) / ( $r - $k ) : $v;
	return -1 * (int) round( min( $neu, $v ) );
}

function vp_projekt_baustein_zeitpunkt( $p, $item ) {
	if ( ! $p->beginn ) {
		return null;
	}
	if ( ! empty( $item['ende'] ) ) {
		return $p->ende ?: gmdate( 'Y-m-d H:i:s', strtotime( $p->beginn . ' +4 hours' ) );
	}
	if ( ! empty( $item['versatz'] ) ) {
		return gmdate( 'Y-m-d H:i:s', strtotime( $p->beginn . ' ' . $item['versatz'] ) );
	}
	return vp_projekt_relativ( $p, vp_projekt_tage_skaliert( $p, (int) ( $item['tage'] ?? 0 ) ), ! empty( $item['zeit'] ) ? $item['zeit'] : null );
}

/**
 * Einen Baustein auf ein Projekt anwenden – additiv und wiederholbar.
 *
 * @return array Zähler je Bereich plus `doppelt` (übersprungene Einträge).
 */
function vp_projekt_baustein_anwenden( $p, $key ) {
	global $wpdb;
	$zahl = array( 'ablauf' => 0, 'oeffentlichkeit' => 0, 'kalkulation' => 0, 'todos' => 0, 'doppelt' => 0 );
	$bs   = vp_projekt_baustein( $key );
	if ( ! $bs ) {
		return $zahl;
	}

	// Was steht schon drin? Titel-Vergleich, damit weder ein zweites Anwenden
	// noch ein selbst getippter Eintrag doppelt auftaucht.
	$vorhanden = array( 'ablauf' => array(), 'oeffentlichkeit' => array(), 'kalkulation' => array() );
	$sortierung = array( 'ablauf' => 0, 'oeffentlichkeit' => 0, 'kalkulation' => 0 );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT bereich, titel, sortierung FROM ' . vp_projekt_punkte_table() . ' WHERE projekt_id = %d', (int) $p->id ) );
	foreach ( $rows as $r ) {
		if ( ! isset( $vorhanden[ $r->bereich ] ) ) {
			continue;
		}
		$vorhanden[ $r->bereich ][ vp_projekt_titel_schluessel( $r->titel ) ] = true;
		$sortierung[ $r->bereich ] = max( $sortierung[ $r->bereich ], (int) $r->sortierung );
	}

	$einfuegen = function ( $bereich, $row, $titel ) use ( &$vorhanden, &$sortierung, &$zahl, $wpdb, $p, $key ) {
		$schluessel = vp_projekt_titel_schluessel( $titel );
		if ( isset( $vorhanden[ $bereich ][ $schluessel ] ) ) {
			$zahl['doppelt']++;
			return;
		}
		$vorhanden[ $bereich ][ $schluessel ] = true;
		$wpdb->insert( vp_projekt_punkte_table(), $row + array(
			'projekt_id'  => (int) $p->id,
			'bereich'     => $bereich,
			'titel'       => $titel,
			'quelle'      => $key,
			'status'      => 'offen',
			'sortierung'  => ++$sortierung[ $bereich ],
			'erstellt_am' => current_time( 'mysql' ),
		) );
		$zahl[ $bereich ]++;
	};

	foreach ( (array) ( $bs['ablauf'] ?? array() ) as $item ) {
		$einfuegen( 'ablauf', array(
			'phase'        => $item['phase'] ?? 'vorbereitung',
			'beschreibung' => (string) ( $item['text'] ?? '' ),
			'zeitpunkt'    => vp_projekt_baustein_zeitpunkt( $p, $item ),
		), $item['titel'] );
	}

	foreach ( (array) ( $bs['oeffentlichkeit'] ?? array() ) as $item ) {
		$einfuegen( 'oeffentlichkeit', array(
			'kanal'        => $item['kanal'] ?? 'sonstiges',
			'beschreibung' => (string) ( $item['text'] ?? '' ),
			'zeitpunkt'    => vp_projekt_baustein_zeitpunkt( $p, $item + array( 'zeit' => '12:00' ) ),
		), $item['titel'] );
	}

	foreach ( (array) ( $bs['kalkulation'] ?? array() ) as $item ) {
		// Posten ohne Betrag: `kanal` merkt sich, in welche Spalte er gehört.
		$einfuegen( 'kalkulation', array(
			'kanal'        => 'einnahme' === ( $item['richtung'] ?? 'ausgabe' ) ? 'einnahme' : 'ausgabe',
			'betrag'       => null,
			'beschreibung' => (string) ( $item['text'] ?? '' ),
		), $item['titel'] );
	}

	// Erst die ToDos anlegen (zählt `doppelt` per Referenz hoch), dann ablegen.
	$neue_todos    = vp_projekt_baustein_todos( $p, $key, (array) ( $bs['todos'] ?? array() ), $zahl );
	$zahl['todos'] = $neue_todos;

	return $zahl;
}

/** ToDos eines Bausteins als ganz normale Aufgaben anlegen. */
function vp_projekt_baustein_todos( $p, $key, $todos, &$zahl ) {
	global $wpdb;
	$t = $wpdb->prefix . 'pp_aufgaben';
	if ( ! $todos || ! function_exists( 'vp_kreis_col_exists' ) || ! vp_kreis_col_exists( $t, 'projekt_id' ) ) {
		return 0;
	}
	$vorhanden = array();
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT titel FROM $t WHERE projekt_id = %d", (int) $p->id ) ) as $titel ) {
		$vorhanden[ vp_projekt_titel_schluessel( $titel ) ] = true;
	}
	$n = 0;
	foreach ( $todos as $item ) {
		if ( isset( $vorhanden[ vp_projekt_titel_schluessel( $item['titel'] ) ] ) ) {
			$zahl['doppelt']++;
			continue;
		}
		$vorhanden[ vp_projekt_titel_schluessel( $item['titel'] ) ] = true;
		$wpdb->insert( $t, array(
			'titel'                       => $item['titel'],
			'beschreibung'                => (string) ( $item['text'] ?? '' ),
			'verantwortliches_gremium_id' => $p->gremium_id ?: null,
			'faelligkeitsdatum'           => vp_projekt_todo_faellig( $p, $item ),
			'quelle_termin_id'            => $p->termin_id ?: null,
			'projekt_id'                  => (int) $p->id,
		) );
		$n++;
	}
	return $n;
}

function vp_projekt_todo_faellig( $p, $item ) {
	if ( ! $p->beginn ) {
		return null;
	}
	$t = vp_projekt_tage_skaliert( $p, (int) ( $item['tage'] ?? 0 ) );
	return gmdate( 'Y-m-d', strtotime( $p->beginn . ' ' . ( $t >= 0 ? '+' : '' ) . $t . ' days' ) );
}

/**
 * Termine aller noch offenen Baustein-Einträge (Ablauf, Öffentlichkeitsarbeit,
 * ToDos) neu berechnen – ab heute, auf die verbleibende Zeit verteilt. Für
 * bestehende Projekte oder wenn sich das Datum der Veranstaltung ändert.
 *
 * @return int Anzahl angepasster Einträge
 */
function vp_projekt_zeitplan_neu( $p ) {
	global $wpdb;
	if ( ! $p->beginn ) {
		return 0;
	}
	$n = 0;
	$t = $wpdb->prefix . 'pp_aufgaben';
	$mit_todos = function_exists( 'vp_kreis_col_exists' ) && vp_kreis_col_exists( $t, 'projekt_id' );
	foreach ( vp_projekt_bausteine_von( $p ) as $key ) {
		$bs = vp_projekt_baustein( $key );
		if ( ! $bs ) {
			continue;
		}
		foreach ( array( 'ablauf', 'oeffentlichkeit' ) as $bereich ) {
			foreach ( (array) ( $bs[ $bereich ] ?? array() ) as $item ) {
				$zeit = vp_projekt_baustein_zeitpunkt( $p, 'oeffentlichkeit' === $bereich ? $item + array( 'zeit' => '12:00' ) : $item );
				$n   += (int) $wpdb->query( $wpdb->prepare(
					'UPDATE ' . vp_projekt_punkte_table() . " SET zeitpunkt = %s WHERE projekt_id = %d AND bereich = %s AND quelle = %s AND titel = %s AND status <> 'erledigt'",
					$zeit, (int) $p->id, $bereich, $key, $item['titel']
				) );
			}
		}
		if ( $mit_todos ) {
			foreach ( (array) ( $bs['todos'] ?? array() ) as $item ) {
				$n += (int) $wpdb->query( $wpdb->prepare(
					"UPDATE $t SET faelligkeitsdatum = %s WHERE projekt_id = %d AND titel = %s AND status = 'offen'",
					vp_projekt_todo_faellig( $p, $item ), (int) $p->id, $item['titel']
				) );
			}
		}
	}
	return $n;
}

add_action( 'admin_post_vp_projekt_zeitplan_neu', function () {
	vp_kreis_check( 'vp_projekt_zeitplan_neu' );
	$p = vp_projekt_oder_fehler( (int) $_POST['projekt_id'] );
	$n = vp_projekt_zeitplan_neu( $p );
	vp_projekt_zurueck( $p, 'bausteine', array( 'vp_zeitplan_neu' => $n ) );
} );

/** Titel-Vergleich für den Dopplungsschutz: Groß-/Kleinschreibung egal. */
function vp_projekt_titel_schluessel( $titel ) {
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $titel ) ) : strtolower( trim( (string) $titel ) );
}

/** Mehrere Bausteine anwenden und am Projekt vermerken. */
function vp_projekt_bausteine_anwenden( $p, $keys ) {
	$summe    = array( 'ablauf' => 0, 'oeffentlichkeit' => 0, 'kalkulation' => 0, 'todos' => 0, 'doppelt' => 0 );
	$gemerkt  = vp_projekt_bausteine_von( $p );
	foreach ( vp_projekt_baustein_keys( $keys ) as $key ) {
		foreach ( vp_projekt_baustein_anwenden( $p, $key ) as $bereich => $n ) {
			$summe[ $bereich ] += $n;
		}
		$gemerkt[] = $key;
	}
	vp_projekt_bausteine_merken( $p, array_unique( $gemerkt ) );
	return $summe;
}

/**
 * Baustein wieder entfernen: löscht nur, was aus ihm stammt und noch offen
 * ist. Bearbeitete, begonnene und erledigte Einträge bleiben – wer etwas
 * angefasst hat, hat sich etwas dabei gedacht.
 */
function vp_projekt_baustein_entfernen( $p, $key ) {
	global $wpdb;
	$key = sanitize_key( $key );
	$n   = 0;
	if ( vp_projekt_baustein( $key ) ) {
		$n = (int) $wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . vp_projekt_punkte_table() . " WHERE projekt_id = %d AND quelle = %s AND status = 'offen'",
			(int) $p->id,
			$key
		) );
		$bs = vp_projekt_baustein( $key );
		$t  = $wpdb->prefix . 'pp_aufgaben';
		if ( ! empty( $bs['todos'] ) && vp_kreis_col_exists( $t, 'projekt_id' ) ) {
			foreach ( $bs['todos'] as $item ) {
				$n += (int) $wpdb->query( $wpdb->prepare(
					"DELETE FROM $t WHERE projekt_id = %d AND titel = %s AND status != 'erledigt'",
					(int) $p->id,
					$item['titel']
				) );
			}
		}
	}
	vp_projekt_bausteine_merken( $p, array_diff( vp_projekt_bausteine_von( $p ), array( $key ) ) );
	return $n;
}

/** Wie viele Einträge eines Bausteins liegen noch im Projekt? */
function vp_projekt_baustein_bestand( $p, $key ) {
	global $wpdb;
	$bestand = array( 'punkte' => 0, 'offen' => 0, 'todos' => 0 );
	$row     = $wpdb->get_row( $wpdb->prepare(
		'SELECT COUNT(*) AS n, SUM(status = %s) AS offen FROM ' . vp_projekt_punkte_table() . ' WHERE projekt_id = %d AND quelle = %s',
		'offen',
		(int) $p->id,
		$key
	) );
	$bestand['punkte'] = (int) ( $row->n ?? 0 );
	$bestand['offen']  = (int) ( $row->offen ?? 0 );

	$bs = vp_projekt_baustein( $key );
	$t  = $wpdb->prefix . 'pp_aufgaben';
	if ( ! empty( $bs['todos'] ) && function_exists( 'vp_kreis_col_exists' ) && vp_kreis_col_exists( $t, 'projekt_id' ) ) {
		$titel = wp_list_pluck( $bs['todos'], 'titel' );
		$platz = implode( ',', array_fill( 0, count( $titel ), '%s' ) );
		$bestand['todos'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $t WHERE projekt_id = %d AND titel IN ($platz)", // phpcs:ignore WordPress.DB.PreparedSQL
			array_merge( array( (int) $p->id ), $titel )
		) );
	}
	return $bestand;
}

/* -------------------------------------------------------------------------
 * Ansicht: Reiter „Bausteine"
 * ---------------------------------------------------------------------- */

/** Zusammenfassung „3 Ablauf · 12 Öffentlichkeitsarbeit · …" nach dem Anwenden. */
function vp_projekt_baustein_bilanz( $zahl ) {
	$teile = array();
	$namen = array(
		'ablauf'          => __( '%d Ablaufpunkte', 'vereinsplugin' ),
		'oeffentlichkeit' => __( '%d Maßnahmen', 'vereinsplugin' ),
		'kalkulation'     => __( '%d Posten', 'vereinsplugin' ),
		'todos'           => __( '%d ToDos', 'vereinsplugin' ),
	);
	foreach ( $namen as $k => $muster ) {
		if ( ! empty( $zahl[ $k ] ) ) {
			$teile[] = sprintf( $muster, (int) $zahl[ $k ] );
		}
	}
	if ( ! $teile ) {
		return __( 'Nichts hinzugefügt – alles war schon vorhanden.', 'vereinsplugin' );
	}
	$text = sprintf( __( 'Hinzugefügt: %s.', 'vereinsplugin' ), implode( ' · ', $teile ) );
	if ( ! empty( $zahl['doppelt'] ) ) {
		$text .= ' ' . sprintf( __( '%d Einträge gab es schon und wurden übersprungen.', 'vereinsplugin' ), (int) $zahl['doppelt'] );
	}
	return $text;
}

/** Was ein Baustein mitbringt – als aufklappbare Vorschau. */
function vp_projekt_baustein_vorschau( $bs ) {
	$listen = array(
		'ablauf'          => __( 'Ablauf', 'vereinsplugin' ),
		'oeffentlichkeit' => __( 'Öffentlichkeitsarbeit', 'vereinsplugin' ),
		'kalkulation'     => __( 'Kalkulation', 'vereinsplugin' ),
		'todos'           => __( 'ToDos', 'vereinsplugin' ),
	);
	foreach ( $listen as $key => $label ) {
		if ( empty( $bs[ $key ] ) ) {
			continue;
		}
		echo '<p class="pp-meta"><strong>' . esc_html( $label ) . '</strong></p><ul class="pp-list pp-baustein-vorschau">';
		foreach ( $bs[ $key ] as $item ) {
			$zusatz = '';
			if ( 'kalkulation' === $key ) {
				$zusatz = 'einnahme' === ( $item['richtung'] ?? '' ) ? __( 'Einnahme', 'vereinsplugin' ) : __( 'Ausgabe', 'vereinsplugin' );
			} elseif ( isset( $item['tage'] ) && (int) $item['tage'] ) {
				$tage   = (int) $item['tage'];
				$zusatz = $tage < 0
					? sprintf( _n( '%d Tag vorher', '%d Tage vorher', abs( $tage ), 'vereinsplugin' ), abs( $tage ) )
					: sprintf( _n( '%d Tag danach', '%d Tage danach', $tage, 'vereinsplugin' ), $tage );
			}
			echo '<li>' . esc_html( $item['titel'] ) . ( $zusatz ? ' <span class="pp-meta">' . esc_html( $zusatz ) . '</span>' : '' ) . '</li>';
		}
		echo '</ul>';
	}
}

/** Auswahlkästchen aller Bausteine, gruppiert – auch im „Neu"-Formular genutzt. */
function vp_projekt_baustein_auswahl( $art = '', $bereits = array(), $mit_vorschau = true ) {
	$gruppen = vp_projekt_baustein_gruppen();
	$nach    = array();
	foreach ( vp_projekt_bausteine() as $key => $bs ) {
		$nach[ $bs['gruppe'] ?? 'organisation' ][ $key ] = $bs;
	}
	foreach ( $gruppen as $gruppe => $titel ) {
		if ( empty( $nach[ $gruppe ] ) ) {
			continue;
		}
		echo '<fieldset class="pp-baustein-gruppe"><legend>' . esc_html( $titel ) . '</legend>';
		foreach ( $nach[ $gruppe ] as $key => $bs ) {
			$drin  = in_array( $key, (array) $bereits, true );
			$arten = (array) ( $bs['arten'] ?? array() );
			$passt = ! $art || ! $arten || in_array( $art, $arten, true );
			echo '<div class="pp-baustein' . ( $drin ? ' is-drin' : '' ) . ( $passt ? '' : ' is-fremd' ) . '">';
			echo '<label class="pp-baustein-kopf"><input type="checkbox" name="bausteine[]" value="' . esc_attr( $key ) . '"' . disabled( $drin, true, false ) . '> ';
			echo '<span><strong>' . esc_html( $bs['label'] ?? $key ) . '</strong>';
			if ( ! empty( $bs['kurz'] ) ) {
				echo '<span class="pp-meta">' . esc_html( $bs['kurz'] ) . '</span>';
			}
			if ( $drin ) {
				echo '<span class="pp-badge pp-punkt-erledigt">' . esc_html__( 'schon angewendet', 'vereinsplugin' ) . '</span>';
			}
			echo '</span></label>';
			if ( $mit_vorschau ) {
				echo '<details class="pp-baustein-details"><summary class="pp-meta">' . esc_html__( 'Was steckt drin?', 'vereinsplugin' ) . '</summary>';
				if ( ! empty( $bs['hinweis'] ) ) {
					echo '<p class="pp-hint">' . esc_html( $bs['hinweis'] ) . '</p>';
				}
				vp_projekt_baustein_vorschau( $bs );
				echo '</details>';
			}
			echo '</div>';
		}
		echo '</fieldset>';
	}
}

/** Vorlagen-Auswahl als Radioliste (Schnellstart beim Anlegen). */
function vp_projekt_vorlagen_auswahl( $art = '' ) {
	echo '<div class="pp-vorlagen">';
	echo '<label class="pp-vorlage"><input type="radio" name="vorlage" value="" checked> <span><strong>' . esc_html__( 'Keine Vorlage', 'vereinsplugin' ) . '</strong><span class="pp-meta">' . esc_html__( 'Leeres Projekt – Bausteine später einzeln dazunehmen.', 'vereinsplugin' ) . '</span></span></label>';
	foreach ( vp_projekt_vorlagen() as $key => $v ) {
		$arten = (array) ( $v['arten'] ?? array() );
		$passt = ! $art || ! $arten || in_array( $art, $arten, true );
		echo '<label class="pp-vorlage' . ( $passt ? '' : ' is-fremd' ) . '"><input type="radio" name="vorlage" value="' . esc_attr( $key ) . '"> <span><strong>' . esc_html( $v['label'] ) . '</strong>';
		echo '<span class="pp-meta">' . esc_html( $v['kurz'] ) . '</span>';
		echo '<span class="pp-meta">' . esc_html( sprintf( _n( '%d Baustein', '%d Bausteine', count( $v['bausteine'] ), 'vereinsplugin' ), count( $v['bausteine'] ) ) . ': ' . vp_projekt_baustein_namen( $v['bausteine'] ) ) . '</span>';
		echo '</span></label>';
	}
	echo '</div>';
}

function vp_projekt_baustein_namen( $keys ) {
	$namen = array();
	foreach ( vp_projekt_baustein_keys( $keys ) as $key ) {
		$namen[] = vp_projekt_baustein( $key )['label'] ?? $key;
	}
	return implode( ', ', $namen );
}

function vp_projekt_tab_bausteine( $p ) {
	$drin = vp_projekt_bausteine_von( $p );
	?>
	<p class="pp-meta"><?php esc_html_e( 'Bausteine sind fertige Checklisten für einzelne Themen. Sie füllen Ablauf, Öffentlichkeitsarbeit, Kalkulation und ToDos – kombiniert, so wie diese Veranstaltung es braucht. Nichts wird überschrieben: Einträge, die es schon gibt, werden übersprungen.', 'vereinsplugin' ); ?></p>

	<?php if ( ! $p->beginn ) : ?>
		<p class="pp-hint"><?php esc_html_e( 'Dieses Projekt hat noch keinen Beginn. Die Bausteine lassen sich trotzdem anwenden – die Einträge werden dann ohne Datum angelegt. Mit einem Beginn setzt das Plugin alle Fristen automatisch – verteilt auf die Zeit von heute bis zur Veranstaltung.', 'vereinsplugin' ); ?></p>
	<?php else :
		$bis = (int) floor( ( strtotime( gmdate( 'Y-m-d', strtotime( $p->beginn ) ) ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ); ?>
		<p class="pp-meta vp-zeitplan-info">
			<?php
			if ( $bis > 0 ) {
				echo esc_html( sprintf(
					/* translators: 1: Tage bis zur Veranstaltung, 2: normaler Vorlauf */
					__( 'Zeitplan ab heute: noch %1$d Tage bis zur Veranstaltung. Die Fristen der Bausteine (gedacht für rund %2$d Tage Vorlauf) werden auf diese Zeit verteilt – bei spontanen Veranstaltungen enger, bei langer Planung großzügiger. Die letzten Handgriffe kurz vorher bleiben, wie sie sind.', 'vereinsplugin' ),
					$bis,
					vp_projekt_baustein_horizont()
				) );
			}
			?>
		</p>
		<?php if ( isset( $_GET['vp_zeitplan_neu'] ) ) : ?>
			<div class="pp-front-notice pp-front-notice-success"><?php echo esc_html( sprintf( /* translators: %d = count */ _n( '%d Termin neu berechnet.', '%d Termine neu berechnet.', (int) $_GET['vp_zeitplan_neu'], 'vereinsplugin' ), (int) $_GET['vp_zeitplan_neu'] ) ); ?></div>
		<?php endif; ?>
		<?php if ( $drin && $bis > 0 ) : ?>
			<?php vp_kreis_form( 'vp_projekt_zeitplan_neu', 'pp-inline-form' ); ?>
				<input type="hidden" name="projekt_id" value="<?php echo (int) $p->id; ?>">
				<button type="submit" class="pp-btn pp-btn-small" onclick="return confirm('<?php echo esc_js( __( 'Termine aller noch offenen Baustein-Einträge (Ablauf, Öffentlichkeitsarbeit, ToDos) ab heute neu verteilen? Erledigte und selbst angelegte Einträge bleiben unverändert.', 'vereinsplugin' ) ); ?>')"><?php esc_html_e( 'Zeitplan ab heute neu verteilen', 'vereinsplugin' ); ?></button>
				<span class="pp-meta"><?php esc_html_e( 'z. B. nach einer Datumsänderung oder bei Projekten, die vorher angelegt wurden.', 'vereinsplugin' ); ?></span>
			</form>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( isset( $_GET['vp_bs_neu'] ) ) : ?>
		<div class="pp-front-notice pp-front-notice-success"><?php
			echo esc_html( vp_projekt_baustein_bilanz( array(
				'ablauf'          => (int) ( $_GET['vp_bs_ablauf'] ?? 0 ),
				'oeffentlichkeit' => (int) ( $_GET['vp_bs_pr'] ?? 0 ),
				'kalkulation'     => (int) ( $_GET['vp_bs_kalk'] ?? 0 ),
				'todos'           => (int) ( $_GET['vp_bs_todos'] ?? 0 ),
				'doppelt'         => (int) ( $_GET['vp_bs_doppelt'] ?? 0 ),
			) ) );
		?></div>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Angewendet', 'vereinsplugin' ); ?></h3>
	<?php if ( ! $drin ) : ?>
		<p class="pp-empty"><?php esc_html_e( 'Noch kein Baustein angewendet.', 'vereinsplugin' ); ?></p>
	<?php else : ?>
		<ul class="pp-list pp-baustein-liste">
			<?php foreach ( $drin as $key ) :
				$bs      = vp_projekt_baustein( $key );
				$bestand = vp_projekt_baustein_bestand( $p, $key );
				?>
				<li>
					<div class="pp-baustein-kopf">
						<span><strong><?php echo esc_html( $bs['label'] ?? $key ); ?></strong>
						<span class="pp-meta"><?php
							$teile = array();
							if ( $bestand['punkte'] ) {
								$teile[] = sprintf( __( '%1$d Einträge, davon %2$d offen', 'vereinsplugin' ), $bestand['punkte'], $bestand['offen'] );
							}
							if ( $bestand['todos'] ) {
								$teile[] = sprintf( _n( '%d ToDo', '%d ToDos', $bestand['todos'], 'vereinsplugin' ), $bestand['todos'] );
							}
							echo esc_html( $teile ? implode( ' · ', $teile ) : __( 'keine Einträge mehr im Projekt', 'vereinsplugin' ) );
						?></span></span>
						<span class="pp-baustein-aktionen">
							<?php
							vp_kreis_form( 'vp_projekt_baustein', 'pp-inline' );
							echo '<input type="hidden" name="projekt_id" value="' . (int) $p->id . '"><input type="hidden" name="was" value="anwenden"><input type="hidden" name="bausteine[]" value="' . esc_attr( $key ) . '">';
							echo '<button type="submit" class="pp-btn pp-btn-small" title="' . esc_attr__( 'Fehlende Einträge des Bausteins nachtragen', 'vereinsplugin' ) . '">' . esc_html__( 'auffrischen', 'vereinsplugin' ) . '</button></form> ';
							vp_kreis_form( 'vp_projekt_baustein', 'pp-inline', 'onsubmit="return confirm(\'' . esc_js( __( 'Offene Einträge dieses Bausteins entfernen? Erledigte und begonnene bleiben.', 'vereinsplugin' ) ) . '\')"' );
							echo '<input type="hidden" name="projekt_id" value="' . (int) $p->id . '"><input type="hidden" name="was" value="entfernen"><input type="hidden" name="baustein" value="' . esc_attr( $key ) . '">';
							echo '<button type="submit" class="pp-link-danger">' . esc_html__( 'entfernen', 'vereinsplugin' ) . '</button></form>';
							?>
						</span>
					</div>
					<?php if ( ! empty( $bs['hinweis'] ) ) : ?>
						<details class="pp-baustein-details"><summary class="pp-meta"><?php esc_html_e( 'Merkblatt', 'vereinsplugin' ); ?></summary><p class="pp-hint"><?php echo esc_html( $bs['hinweis'] ); ?></p></details>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Bausteine hinzufügen', 'vereinsplugin' ); ?></h3>
	<?php vp_kreis_form( 'vp_projekt_baustein', 'pp-form' ); ?>
		<input type="hidden" name="projekt_id" value="<?php echo (int) $p->id; ?>">
		<input type="hidden" name="was" value="anwenden">
		<?php vp_projekt_baustein_auswahl( $p->art, $drin ); ?>
		<div class="pp-form-actions"><button type="submit" class="pp-btn pp-btn-primary"><?php esc_html_e( 'Ausgewählte Bausteine übernehmen', 'vereinsplugin' ); ?></button></div>
	</form>

	<h3><?php esc_html_e( 'Ganze Vorlage übernehmen', 'vereinsplugin' ); ?></h3>
	<p class="pp-meta"><?php esc_html_e( 'Eine Vorlage ist nur eine Kombination mehrerer Bausteine – praktisch, wenn ihr bei null anfangt.', 'vereinsplugin' ); ?></p>
	<ul class="pp-list pp-baustein-liste">
		<?php foreach ( vp_projekt_vorlagen() as $key => $v ) : ?>
			<li>
				<div class="pp-baustein-kopf">
					<span><strong><?php echo esc_html( $v['label'] ); ?></strong>
						<span class="pp-meta"><?php echo esc_html( $v['kurz'] ); ?></span>
						<span class="pp-meta"><?php echo esc_html( vp_projekt_baustein_namen( $v['bausteine'] ) ); ?></span>
					</span>
					<span class="pp-baustein-aktionen">
						<?php
						vp_kreis_form( 'vp_projekt_baustein', 'pp-inline' );
						echo '<input type="hidden" name="projekt_id" value="' . (int) $p->id . '"><input type="hidden" name="was" value="anwenden"><input type="hidden" name="vorlage" value="' . esc_attr( $key ) . '">';
						echo '<button type="submit" class="pp-btn pp-btn-small">' . esc_html__( 'übernehmen', 'vereinsplugin' ) . '</button></form>';
						?>
					</span>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
}

/** Spickzettel: welches Bild in welchem Format. */
function vp_projekt_bild_formate_tabelle() {
	?>
	<details class="pp-werkzeug">
		<summary class="pp-details-summary"><?php esc_html_e( 'Spickzettel: Bildformate je Plattform', 'vereinsplugin' ); ?></summary>
		<p class="pp-meta"><?php esc_html_e( 'Ein Grundmotiv, daraus alle Zuschnitte. Die Maße sind Richtwerte – die Plattformen ändern sie gelegentlich.', 'vereinsplugin' ); ?></p>
		<table class="pp-table">
			<thead><tr><th><?php esc_html_e( 'Format', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Wofür', 'vereinsplugin' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( vp_projekt_bild_formate() as $f ) : ?>
				<tr><td><strong><?php echo esc_html( $f['titel'] ); ?></strong></td><td><?php echo esc_html( $f['plattformen'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</details>
	<?php
}

/* -------------------------------------------------------------------------
 * Formular-Handler
 * ---------------------------------------------------------------------- */

add_action( 'admin_post_vp_projekt_baustein', 'vp_projekt_handle_baustein' );
function vp_projekt_handle_baustein() {
	vp_kreis_check( 'vp_projekt_baustein' );
	$p   = vp_projekt_oder_fehler( (int) ( $_POST['projekt_id'] ?? 0 ) );
	$was = sanitize_key( $_POST['was'] ?? '' );

	if ( 'entfernen' === $was ) {
		vp_projekt_baustein_entfernen( $p, sanitize_key( $_POST['baustein'] ?? '' ) );
		vp_projekt_zurueck( $p, 'bausteine' );
	}

	$keys    = vp_projekt_baustein_keys( (array) ( $_POST['bausteine'] ?? array() ) );
	$vorlage = sanitize_key( $_POST['vorlage'] ?? '' );
	if ( $vorlage && isset( vp_projekt_vorlagen()[ $vorlage ] ) ) {
		$keys = array_unique( array_merge( vp_projekt_vorlagen()[ $vorlage ]['bausteine'], $keys ) );
	}
	if ( ! $keys ) {
		vp_kreis_fehler( __( 'Kein Baustein ausgewählt.', 'vereinsplugin' ) );
	}
	$zahl = vp_projekt_bausteine_anwenden( $p, $keys );
	vp_projekt_zurueck( $p, 'bausteine', array(
		'pp_saved'      => false,
		'vp_bs_neu'     => 1,
		'vp_bs_ablauf'  => (int) $zahl['ablauf'],
		'vp_bs_pr'      => (int) $zahl['oeffentlichkeit'],
		'vp_bs_kalk'    => (int) $zahl['kalkulation'],
		'vp_bs_todos'   => (int) $zahl['todos'],
		'vp_bs_doppelt' => (int) $zahl['doppelt'],
	) );
}
