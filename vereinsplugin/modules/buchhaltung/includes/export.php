<?php
defined('ABSPATH') || exit;

function jb_export_euer_csv(int $year): void {
    if (!jb_can_export()) wp_die('Keine Berechtigung.');
    $entries = jb_journal_get(['year' => $year]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="EÜR_JuFo_' . $year . '_' . date('Ymd') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

    fputcsv($out, ['Datum', 'Beleg', 'Art', 'Betrag', 'Geldkonto', 'Konto', 'Kontobezeichnung', 'Sphäre', 'Gegenpartei', 'Beschreibung'], ';');

    $arten = ['einnahme' => 'Einnahme', 'ausgabe' => 'Ausgabe', 'umbuchung' => 'Umbuchung'];
    foreach (array_reverse($entries) as $e) {
        // Art aus dem Buchungssatz, nicht aus dem Vorzeichen – sonst stünden
        // Bareinzahlungen und Wechselgeld als Einnahme/Ausgabe in der EÜR.
        $v = function_exists('vp_bh_euer_sicht') ? vp_bh_euer_sicht($e) : null;
        if ($v && $v['art'] === 'umbuchung') {
            $geld = $v['von'] . ' → ' . $v['nach'];
            $konto = '';
        } elseif ($v) {
            $geld = $v['geldkonto'];
            $konto = $v['konto'];
        } else {
            $s = function_exists('vp_doppik_satz') ? vp_doppik_satz($e) : ['soll' => $e['konto'], 'haben' => ''];
            $geld = '';
            $konto = $s['soll'] . ' an ' . $s['haben'];
        }
        fputcsv($out, [
            $e['buchung_datum'],
            ($e['beleg_nr'] ?? '') ?: $e['beleg_referenz'],
            $v ? $arten[$v['art']] : 'Buchungssatz',
            number_format(abs((float) $e['betrag']), 2, ',', '.'),
            $geld,
            $konto,
            ($konto && function_exists('vp_bh_konto_name')) ? vp_bh_konto_name($konto) : ($e['kategorie'] ?? ''),
            $e['sphaere'] ?? '',
            $e['gegenpartei'] ?? '',
            $e['beschreibung'],
        ], ';');
    }
    fclose($out);
    exit;
}

function jb_export_datev(int $year): void {
    if (!jb_can_export()) wp_die('Keine Berechtigung.');
    $entries = jb_journal_get(['year' => $year]);
    $vereinsname = get_bloginfo('name');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="DATEV_JuFo_' . $year . '_' . date('Ymd') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    // DATEV EXTF Header
    $ts  = date('YmdHis') . '000';
    $von = $year . '0101';
    $bis = $year . '1231';
    fwrite($out, '"EXTF";700;21;"Buchungsstapel";7;' . $ts . ';"";"";"";"";"0";"0";' . $von . ';' . $bis . ';"' . $vereinsname . '";"";"";"";"";"";"EUR";"";"";"";"";"";"";"";"";"";' . "\n");
    fwrite($out, "Umsatz (ohne Soll/Haben-Kz);Soll/Haben-Kennzeichen;WKZ Umsatz;Kurs;Basis-Umsatz;WKZ Basis-Umsatz;Konto;Gegenkonto (ohne BU-Schlüssel);BU-Schlüssel;Belegdatum;Belegfeld 1;Belegfeld 2;Skonto;Buchungstext\n");

    $konto_map = [
        'Getränkeumsatz Bar (Zettle)'  => ['1000', '4710'],
        'Getränkeumsatz Karte (Zettle)'=> ['1210', '4710'],
        'Spenden'                       => ['1200', '4500'],
        'Sponsoring/Einnahmen'          => ['1200', '4800'],
        'Förderung/Zuschüsse'           => ['1200', '4300'],
        'Mitgliedsbeiträge'             => ['1200', '4400'],
        'Getränke-Einkauf'              => ['5200', '1200'],
        'Versicherungen'                => ['5800', '1200'],
        'Internet/Telefon'              => ['5610', '1200'],
        'GEMA'                          => ['5630', '1200'],
        'Software/Webling'              => ['5640', '1200'],
        'Steuerberatung'                => ['5900', '1200'],
        'Veranstaltungskosten'          => ['5300', '1200'],
        'Material/Einkäufe'            => ['5500', '1200'],
        'Bankgebühren'                  => ['5700', '1200'],
    ];

    foreach (array_reverse($entries) as $e) {
        $betrag  = abs((float)$e['betrag']);
        $is_ein  = (float)$e['betrag'] >= 0;
        $sh      = 'S';
        if (function_exists('vp_doppik_satz')) {
            // Echte Konten aus dem Buchungssatz: Konto = Soll, Gegenkonto = Haben.
            $s    = vp_doppik_satz($e);
            $kto  = $s['soll'];
            $gkto = $s['haben'];
        } else {
            $konten = $konto_map[$e['kategorie']] ?? ($is_ein ? ['1200','4800'] : ['5900','1200']);
            $kto    = $konten[0];
            $gkto   = $konten[1];
        }
        $beleg   = date('dm', strtotime($e['buchung_datum']));
        $text    = substr(str_replace(['"',';'], '', $e['beschreibung']), 0, 60);

        $belegnr = substr(str_replace(['"', ';'], '', (string) (($e['beleg_nr'] ?? '') ?: $e['beleg_referenz'])), 0, 36);
        fwrite($out, number_format($betrag, 2, ',', '.') . ";$sh;EUR;;;;$kto;$gkto;;$beleg;$belegnr;;" . "\"$text\"\n");
    }
    fclose($out);
    exit;
}

function jb_export_auslagen_csv(int $year): void {
    if (!jb_can_export()) wp_die('Keine Berechtigung.');
    $auslagen = jb_get_auslagen(['year' => $year]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Auslagen_JuFo_' . $year . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($out, ['ID','Datum','Mitglied','Betrag','Kategorie','Beschreibung','Status','Eingereicht','Entschieden','Ausgezahlt'], ';');
    foreach ($auslagen as $a) {
        fputcsv($out, [
            $a['id'], $a['ausgabe_datum'], $a['user_name'],
            number_format((float)$a['betrag'], 2, ',', '.'),
            $a['kategorie'], $a['beschreibung'], $a['status'],
            $a['eingereicht_am'], $a['entschieden_am'] ?? '', $a['ausgezahlt_am'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}
