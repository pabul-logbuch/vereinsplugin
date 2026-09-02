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

    fputcsv($out, ['Datum', 'Betrag', 'Einnahme/Ausgabe', 'Kategorie', 'Beschreibung', 'Quelle', 'Beleg'], ';');

    foreach ($entries as $e) {
        fputcsv($out, [
            $e['buchung_datum'],
            number_format((float)$e['betrag'], 2, ',', '.'),
            (float)$e['betrag'] >= 0 ? 'Einnahme' : 'Ausgabe',
            $e['kategorie'],
            $e['beschreibung'],
            $e['quelle'],
            $e['beleg_referenz'] ?: $e['beleg_pfad'],
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

    foreach ($entries as $e) {
        $betrag  = abs((float)$e['betrag']);
        $is_ein  = (float)$e['betrag'] >= 0;
        $sh      = 'S';
        $konten  = $konto_map[$e['kategorie']] ?? ($is_ein ? ['1200','4800'] : ['5900','1200']);
        $kto     = $konten[0];
        $gkto    = $konten[1];
        $beleg   = date('dm', strtotime($e['buchung_datum']));
        $text    = substr(str_replace(['"',';'], '', $e['beschreibung']), 0, 60);

        fwrite($out, number_format($betrag, 2, ',', '.') . ";$sh;EUR;;;;$kto;$gkto;;$beleg;;;" . "\"$text\"\n");
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
