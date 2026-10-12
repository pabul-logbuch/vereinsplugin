# Vereinsplugin

Alles-in-einem-Vereinsverwaltung für WordPress. Bündelt vier bisher getrennte
Plugins zu einem – mit gemeinsamem Kern, einem Mitgliederbereich und **genau
einer** zusätzlichen Dashboard-Seite: der Shortcode-Übersicht.

## Enthaltene Module

- **Wunschliste & Spenden** – öffentliche Wunschliste mit Spenden-Modal,
  Abstimmung, Schichtpläne für Veranstaltungen, Mitglieder-Import.
  Schichtplanverwaltung: Veranstaltungen **kopieren** (neues Datum, Zeiten
  wandern mit), als **Vorlage** speichern und daraus neue Veranstaltungen
  anlegen oder Stationen in bestehende einfügen; einzelne **Stationen und
  Schichten kopieren & einfügen** (persönliche Zwischenablage, auch in andere
  Veranstaltungen, Zeiten um n Tage verschiebbar). Eintragungen werden nie
  mitkopiert.
- **Sitzungen & Protokolle** – Gremien, Konsent-Protokolle, TOPs, Themenspeicher,
  Aufgaben, Termine, Organigramm, PWA/App.
- **Buchhaltung & Auslagen** – EÜR, Auslagen-Erstattung mit Beleg, Budgets,
  Getränkekasse, Nextcloud-Belegablage. Beim Einreichen wählt man das Konto
  für die Rückzahlung (aus dem Profil, neu eingegeben oder bar); nach der
  Genehmigung zeigt der Vorstand einen **GiroCode** (EPC-QR) mit dem
  Verwendungszweck „Rückzahlung Einkauf bei [Händler] am [Datum], Zweck
  [Kostenstelle, Budget, Konto]“ zum Scannen in der Banking-App.
  **Z-Bon** (Mitgliederbereich → Buchhaltung → „Z-Bon“, wie in der
  Desktop-App): Zettle-Tagesabschluss eintippen (Bar, Karte, Trinkgeld,
  Produkt „Spende“) – daraus werden bis zu vier Buchungen auf Barkasse bzw.
  PayPal/Zettle, mit Live-Vorschau und Schutz vor doppeltem Buchen.
  Der Z-Bon selbst (PDF aus Zettle oder Foto) wird beim Buchen als **Beleg**
  in Nextcloud abgelegt (`Belege/<Jahr>/Z-Bon/`, nie überschrieben) und an
  alle Buchungen des Bons gehängt; für ältere Bons lässt er sich in der Liste
  „Zuletzt gebucht“ nachreichen. Spenden werden getrennt nach bar/Karte erfasst.
  **Umsatzsteuer-Rücklage** (Buchhaltung → Rücklagen): Ist der Verein in
  einem Jahr umsatzsteuerpflichtig (Einstellung je Geschäftsjahr), wird aus
  den Buchungen im Zweckbetrieb (Vorgabe 7 %) und im wirtschaftlichen
  Geschäftsbetrieb (19 %) die Umsatzsteuer und Vorsteuer herausgerechnet –
  je Voranmeldungszeitraum, wahlweise mit pauschaler Vorsteuer nach § 23a
  UStG. Abzüglich der Zahlungen ans Finanzamt (Steuerkonto, Vorgabe 7600)
  steht der offene Betrag als eigene Zeile im Rücklagenbedarf. Steuersatz je
  Konto einstellbar (0 % für steuerfreie Umsätze). Im **Journal** steht bei
  jeder betroffenen Buchung, wie viel USt darin steckt (ans Finanzamt zu
  zahlen), wie viel Vorsteuer erstattet bzw. verrechnet wird, ob das Konto
  steuerfrei ist oder ob es eine Zahlung an / Erstattung vom Finanzamt ist –
  mit Voranmeldungszeitraum, auch in der Bearbeiten-Ansicht.
  **Bank-Import mit Abgleich** (Buchhaltung → „Bank-Import“, auch in der
  Desktop-App): Zeilen, die schon im Journal stehen, werden übersprungen.
  Erstattungen genehmigter Auslagen werden erkannt (Kennung `AUSLAGE-12` im
  Verwendungszweck des GiroCodes, sonst IBAN oder Name plus Betrag) und nur
  als Ausgleich des Auslagen-Kontos (1600) gebucht – die Ausgabe zählt einmal,
  die Auslage steht danach auf „ausgezahlt“. Belege aus „nur Beleg“ werden
  bei passendem Betrag und Datum (±10 Tage) an die Bankbuchung gehängt.
  Über dem Import steht je Geldkonto der **letzte Import** (Zeitraum, letzte
  Zeile, wer/wann) und ab welchem Datum der nächste Export beginnen sollte –
  bewusst mit einem Tag Überschneidung, die der Abgleich überspringt. Beginnt
  ein Kontoauszug später, warnt der Import vor einer Lücke. Bei der Bank nur
  **vorgemerkte** Umsätze (Sparkasse, Spalte „Info“) werden nicht importiert.
  **Schon gebucht:** Steht eine Buchung mit gleichem Betrag auf demselben
  Geldkonto schon im Journal (z. B. ein eingebuchter Beleg oder von Hand
  erfasst, ±14 Tage), schlägt der Import vor, die Bankzeile ihr nur
  **zuzuordnen** – es entsteht keine zweite Buchung, und spätere Importe mit
  Überschneidung erkennen die Zeile wieder. In der Vorschau lässt sich jede
  Zeile unter **Verknüpfung** frei wählen: neue Buchung, schon gebuchte
  Buchung (±45 Tage), Erstattung einer offenen Auslage oder ein beliebiger
  eingereichter Beleg. Dazu je Zeile **Buchungstext, Rücklage, Budget und
  Kostenstelle** (bei „schon gebucht“ werden gesetzte Felder an der
  vorhandenen Buchung ergänzt).
- **Veranstaltungs-Publisher** – Veranstaltungen an Mastodon, Bluesky, Telegram,
  Presse, Signal u. a. verteilen. Im Mitgliederbereich unter
  **Veranstaltungen** in drei Schritten: (1) Daten (Titel, Beginn, Ort,
  Beschreibung, Website-Seite, Kanäle), (2) **Material-Checkliste** – je
  gewähltem Kanal die nötigen Texte und Bildformate (Feed 1080×1350, Story
  1080×1920, Facebook-Veranstaltung 1200×628, Plakat A3 …) mit Upload,
  Formatprüfung, „In Canva gestalten“ im passenden Format, Canva-Link und
  Ablage in einem Nextcloud-Ordner je Veranstaltung, (3) **Kanal-Checkliste** –
  automatische Kanäle einzeln oder gesammelt senden (Vorstand), manuelle
  Kanäle (WhatsApp, Facebook-Veranstaltung, Website, Newsletter, Aushang)
  abhaken, mit Link und Versandprotokoll.
  **Veranstaltungsreihen:** beliebig viele Termine (je mit eigenem Ort und
  Hinweis, Serien-Generator wöchentlich / 14-tägig / monatlich). Die Reihe
  wird entweder einmal beworben (Terminliste wird auf Wunsch an die Texte
  angehängt) oder jeder Termin einzeln – mit eigener Kanal-Checkliste und
  eigenem Versand, Text beginnt mit dem Termin. Mitglieder-Kalender und
  Veranstaltungsseite zeigen alle Termine.
  **Bilder je Termin:** Bei Reihen steht in der Material-Checkliste erst
  „Allgemein (ganze Reihe)“ (Texte, Reel, Plakat, Bilder für die
  Reihen-Werbung), darunter je Termin Feed-Bild, Story und
  Facebook-Veranstaltungsbild. Beim Versand je Termin gehen dessen Bilder
  raus, sonst die allgemeinen. Der Canva-Link gilt je Format für alle
  Termine (ein Design, eine Seite je Termin).
  **Alles im Mitgliederbereich, ohne WP-Backend:** (4) **Zeitplan** –
  zeitversetzte Kampagne mit Schritten relativ zum (ersten) Termin, Vorlage
  −14/−3/−1/+2 Tage, einplanen und stoppen (Vorstand), Versandprotokoll je
  Schritt. **Löschen** in den Papierkorb (stoppt eingeplante Schritte), dort
  wiederherstellen oder endgültig löschen. Kurzbeschreibung und
  Beitragsbild für die Website, Freigabe- und Kampagnen-Status in der
  Liste, Instagram-Reel-Status. Administrator:innen pflegen unter
  „Zugänge für Social Media und Presse“ die Zugangsdaten (Tokens werden nicht
  angezeigt; leer lassen = unverändert).

## Installation

1. Ordner `vereinsplugin/` nach `wp-content/plugins/` kopieren (oder als ZIP
   hochladen).
2. Aktivieren. Tabellen, Rollen und Cron-Jobs der Module werden dabei angelegt.
3. Falls die alten Einzel-Plugins (`wunschliste-plugin`, `protokollpro`,
   `jufo-buchhaltung-v2`, `jufobleibt-event-publisher`) noch aktiv sind: jetzt
   deaktivieren. Daten bleiben erhalten – dieselben Tabellen werden weitergenutzt.
4. Seiten anlegen (Shortcodes siehe **Verein → Shortcodes** im Dashboard):
   - öffentliche Seite mit `[wunschliste]`
   - Seite „Mitgliederbereich“ mit `[verein_mitgliederbereich]`
   - Login-Seite mit `[verein_login]` (oder Login in den Mitgliederbereich einbetten)

## Dashboard

Dieses Plugin fügt bewusst nur **eine** Menüseite hinzu: **Verein**.

- **Verein → Shortcodes** – vollständige Liste aller Shortcodes mit Beschreibung,
  Zielgruppe und Kopier-Button. Unten Direktlinks zu den (aus dem Menü
  ausgeblendeten) Rest-Admin-Funktionen wie CSV-Import oder Event-Editor.
- **Verein → Einstellungen** – Bankverbindung/Spendenkontakt, Modul-An/Aus,
  Zugriffsschalter. Umfangreiche technische Zugänge (Social-APIs, Nextcloud,
  PWA) sind bis Stage 2 noch auf den ursprünglichen Modul-Seiten – von hier aus
  direkt verlinkt.

Die alten Menüs der vier Module sind ausgeblendet, ihre Seiten bleiben per URL
erreichbar. Zum Debugging lassen sie sich unter **Einstellungen → „Modul-Menüs
wieder einblenden“** reaktivieren.

## Mitglieder

- Mitglieder haben die Rolle **Vereinsmitglied** (`wl_mitglied`, unverändert
  kompatibel zur bisherigen Wunschliste).
- Reine Mitglieder werden aus `wp-admin` auf den Mitgliederbereich umgeleitet und
  sehen keine Admin-Bar. Abschaltbar in den Einstellungen.
- Vorstand = WordPress-Rollen **Administrator/Redakteur**: zusätzlich Kassen-
  und Versand-Rechte (`jbf_send_external`, `jb_approve_auslagen` …).
  Mitglieder anlegen und der Mitglieder-CSV-Import stehen dem ganzen Vorstand
  offen (Recht `vp_manage_members`).
- **Rechte im Vorstand** (Verein → Einstellungen, nur Administrator:innen):
  - *Nur Kassenwart:innen dürfen buchen* – aus (Standard) darf der ganze
    Vorstand buchen; an behalten nur Administrator:innen und die gewählten
    Kassenwart:innen die schreibenden Kassenrechte (Journal, Bank-Import,
    Z-Bon, Auslagen genehmigen/auszahlen, Rechnungen, SEPA-Läufe, Spenden,
    Buchhaltungs-Einstellungen – auch in der Desktop-App). Der übrige Vorstand
    sieht alles weiter. Kassenwart:innen können auch einfache Mitglieder sein.
  - *Eigene Auslagen selbst genehmigen erlauben* – aus = Vier-Augen-Prinzip:
    Wer eine Auslage eingereicht hat, kann sie nicht selbst genehmigen oder
    ablehnen (Vorstand, Kreis-Kassen, Desktop-App).
- Im Mitgliederbereich unter **Mitglieder** (Recht `vp_manage_members`) lassen
  sich Konten suchen, filtern und bearbeiten: Stammdaten, Anschrift, Beitrag,
  SEPA-Angaben, Konto für Erstattungen, interne Notiz sowie ein Link „Passwort-Link per E-Mail senden“.
  Die **Rolle** ändert nur eine Administrator:in (Recht `promote_users`), nie
  die eigene; Konten mit Admin-Rechten sind für den Vorstand gesperrt. Ämter in
  Kreisen werden dort nur angezeigt – gepflegt werden sie im Kreis unter
  „Mitglieder & Rollen“.
- **Mitglieder-Import** (Mitgliederbereich → „CSV-Import“): liest einfache
  Listen (`name;email`) ebenso wie den Voll-Export der alten Vereinsverwaltung
  (Mitglieds-ID, Anschrift, Beitrag, Bankverbindung, Status, Gruppen). In der
  Vorschau wird jede Zeile mit einem bestehenden WordPress-Konto verbunden
  (Vorschlag über Mitglieds-Nr., E-Mail oder Namen, frei änderbar), neu angelegt
  oder übersprungen; Status-/Gruppenwerte werden einmal zugeordnet (Mitgliedsart,
  ausgetreten, Kreis). Ausgetretene landen in der rechtelosen Rolle
  **Ehemaliges Mitglied** (`vp_ehemalig`) und tauchen in der Liste nur im
  eigenen Filter auf. Optional: SEPA-Mandate anlegen, Einladung mit
  Passwort-Link verschicken. Ein erneuter Import erkennt die Personen über die
  Mitglieds-Nr. wieder.

## Seitenleiste im Mitgliederbereich

- **Start** – das Nötigste aus allen Bereichen. Oben „Auf einen Blick“:
  offene/überfällige Aufgaben, eigene Auslagen, Auslagen zu prüfen,
  Mitgliedsanträge, neue Themen, freie Schichtplätze, Kassenstand – rot, wenn
  etwas ansteht; jede Kachel führt in den Bereich. Darunter Karten: nächste
  Termine, geplante Sitzungen, meine Aufgaben, meine Kreise, meine Projekte,
  meine Schichten, Wunschliste (vorne), Kasse (Konten + Projekte über der
  Kalkulation), Kreis-Chats. Jede Person sieht nur, was sie auch öffnen darf.
  Erweiterbar über die Filter `vp_start_kacheln` / `vp_start_karten`.
- **Kalender** – siehe unten.
- **Aufgaben** – offene Aufgaben (mit Zähler), **Rollenaufgaben** (was zu jeder
  Rolle regelmäßig bzw. vor Veranstaltungen gehört, eigene Rollen zuerst) und
  **Aufgaben-Sets**.
- **Sitzungen & Protokolle** – nur noch: Geplante Sitzungen, Protokolle,
  Entscheide, Themenspeicher.
- **Kreise & Rollen**, **Projekte & Veranstaltungen**, **Dokumente & Vorlagen**
  (Dokumente + Ablauf-Vorlagen) – eigene Einträge in der Hauptleiste.
- **Rollen-Vorlagen** (Kreise & Rollen → Kreis → Mitglieder & Rollen →
  „Rolle aus Vorlage anlegen“): Kassier:in (führt die Kreiskasse),
  Sprecher:in, Amt für Inneres (Einkäufe, Lager, Material), Hausmeister:in und
  Amt für Öffentlichkeitsarbeit – je mit Aufgaben, Skills sowie regelmäßigen
  und veranstaltungsbezogenen Rollen-Aufgaben. Mehrere auf einmal wählbar,
  vorhandene Rollen werden nicht doppelt angelegt, danach frei änderbar.
  Eigene Vorlagen per Filter `pp_rollen_katalog`.

## Kalender

Im Mitgliederbereich gibt es in der Seitenleiste den Punkt **Kalender**
(direkt unter „Start“). Er führt alle Termine des Vereins zusammen – die Daten
bleiben dabei in ihren Modulen, es wird nichts doppelt gepflegt:

- **Veranstaltungen** aus dem Veranstaltungs-Publisher (Entwürfe nur für
  Leute mit Veranstaltungsrechten),
- **Sitzungen** aus „Sitzungen & Protokolle“ (Sitzungen „nur Gremium“ nur für
  dessen Mitglieder) und **Termine aus Beschlüssen**,
- **Schichtpläne** je Veranstaltungstag mit freien Plätzen – plus die eigenen
  Schichten hervorgehoben,
- **Öffnungszeiten** (wöchentlich wiederkehrend) mit **Schließtagen/Ferien**,
- **Weitere Termine**, frei eingetragen (für alle oder nur den Vorstand),
- **Nextcloud-Kalender** (siehe unten).

- **Meine Aufgaben-Fristen** (offene Aufgaben mit Fälligkeitsdatum).

Kreis-Termine gehören ebenfalls in den Kalender (der frühere Reiter „Termine“
unter Sitzungen & Protokolle ist entfallen): Unter **Verwalten** beim Termin
einen Kreis auswählen; darunter stehen alle anstehenden Kreis-Termine und
geplanten Sitzungen mit **Aufgaben-Set anwenden** und **Rollenaufgaben
erzeugen**. Der persönliche Abo-Link ersetzt den alten „Kalender-Sync“ (alte
Links funktionieren weiter).

Ansichten: Monat (auf dem Handy automatisch als Liste), Liste; Kategorien
lassen sich per Filter ein-/ausblenden. Auf der Startseite erscheinen die
nächsten Termine. Öffnungszeiten, weitere Termine und Nextcloud-Kalender
pflegt der Vorstand unter **Kalender → Verwalten**.

**Nextcloud – beide Richtungen:**

- *Nextcloud → Vereinskalender:* Unter „Verwalten“ werden die Kalender des in
  den Einstellungen hinterlegten Nextcloud-Kontos zum Anklicken angeboten.
  Alternativ lässt sich jeder Freigabe-/Abo-Link (oder jede andere ICS-Adresse)
  einfügen. Die Termine werden alle 15 Minuten gelesen (nur lesend,
  Wiederholungen und Ausnahmen werden aufgelöst). Zugangsdaten gehen nur an
  den eigenen Nextcloud-Host.
- *Vereinskalender → Nextcloud:* Unter „Abonnieren / Nextcloud“ bekommt jede
  Person einen persönlichen ICS-Link. In Nextcloud: Kalender → „+ Neuer
  Kalender“ → „Neues Abonnement aus Link“. Funktioniert genauso mit Google,
  Apple, Outlook und Thunderbird. Der Link enthält nur, was die Person auch im
  Mitgliederbereich sieht, und lässt sich jederzeit neu erzeugen.

Weitere Quellen können sich über den Filter `vp_kalender_termine` anhängen.

## Projekte: Kalkulation ↔ Kasse, Kostenstellen, Zeitplan ab heute

- **Kostenstelle je Projekt** (Reiter Finanzen; Vorschlag aus dem Titel, z. B.
  `SOMMERFEST27`). Ein Projektbudget übernimmt sie, in der Buchhaltung steht sie
  in der Kostenstellen-Auswahl.
- **Ist** = Buchungen mit dieser Kostenstelle oder auf dem Projektbudget +
  Auslagen auf dem Projektbudget (ohne Doppelzählung der Auslagen-Buchung).
  Funktioniert auch ohne eigenes Budget.
- **Plan/Ist je Posten**: Die Kalkulation zeigt Plan, Ist und Abweichung. Jede
  tatsächliche Buchung lässt sich einem Posten zuordnen oder mit
  „+ als neuen Posten übernehmen“ in die Kalkulation übertragen.
- **In der Kasse**: Beim Buchen im Journal kann direkt ein
  Projekt-Kalkulationsposten gewählt werden (die Kostenstelle wird ergänzt).
  „Budgets & Kostenstellen“ und die Kreiskasse zeigen je Projekt Plan, Ist und
  Abweichung.
- **Beträge** wie „1.234,56“ werden jetzt richtig gelesen.
- **Bausteine: Zeitplan ab heute** – die Fristen verteilen sich auf die Zeit
  von heute bis zur Veranstaltung: spontan in 2 Wochen wird enger, in 12
  Monaten großzügiger; die letzten Handgriffe (bis 14 Tage vorher) bleiben.
  Für bestehende Projekte oder nach einer Datumsänderung: „Zeitplan ab heute
  neu verteilen“ im Reiter Bausteine (nur offene Baustein-Einträge).

## Formulare: Vorlagen, Auswertung, Mail an Ausgewählte

- **Vorlagen** unter „+ Neues Formular“: Anmeldung zu einer Veranstaltung,
  Umfrage, Feedback, Helfer:innen-Anmeldung, Kurs/Workshop (mit Einverständnis
  der Erziehungsberechtigten nur bei „unter 18“), Terminumfrage, Kontakt. Die
  Vorlage füllt Felder, Texte und Bedingungen vor; gespeichert wird erst beim
  Klick auf „Speichern“. Viele Vorlagen haben eine Newsletter-Zustimmung.
- **Einträge**: Spaltenköpfe sortieren (Zahlen numerisch), Filter je
  Auswahlfeld (z. B. „Newsletter = ja“, „Essen = vegan“), Volltextsuche.
  **Auswertung** der gefilterten Einträge: Anzahl und Anteil je Antwort,
  Summe und Durchschnitt bei Zahlenfeldern (z. B. Personen gesamt).
- **Mail an diese Personen** (die gefilterten Einträge): Adressen kopieren,
  BCC im eigenen Mailprogramm oder Mail von der Website – einzeln, mit
  {name} und {feld-schluessel} als Platzhalter, doppelte Adressen nur einmal,
  Antworten an die sendende Person.
- **Bestätigungsmail** an die ausfüllende Person enthält ihre Angaben.

## Ticket-Vorverkauf (Formulare)

Ein Formular mit einem Feld vom Typ **Tickets** (oder die Vorlage
„Ticket-Vorverkauf“) wird zum Vorverkauf. Ticketarten je Zeile
`Name | Preis | Kontingent`, z. B. `Normal | 12 | 100`, `Ermäßigt | 8 | 50`,
`Soli | 20` (ohne Kontingent = unbegrenzt); das Teilnehmerlimit gilt dann für
Tickets insgesamt.

- **Bestellen:** Anzahl je Ticketart, Summe wird live angezeigt. Danach gibt es
  sofort den Link zu den Tickets und eine Mail mit Bestellnummer, Betrag,
  Bankdaten (IBAN aus den Vereinseinstellungen) und Verwendungszweck
  `TICKET-<Nr>`. Die **Ticket-Seite** zeigt GiroCode zum Bezahlen und je
  Ticket einen QR-Code. Tickets gelten sofort – nichts wird automatisch
  storniert, weil der Bank-Import nur gelegentlich läuft.
- **Zahlungseingänge prüfen** (Vorstand · Kassier:in, mit Zähler in der
  Seitenleiste): für den Wochentermin im Online-Banking. Offene Bestellungen
  mit Verwendungszweck, Betrag und Alter: **✓ eingegangen**, **✉ erinnern**,
  nach einer Erinnerung **stornieren** (mit Mail an die Person; rot markiert,
  wenn die Zahlungsfrist des Formulars, mindestens 3 Tage, seit der
  Erinnerung verstrichen ist). Darunter offene Ausgangsrechnungen zur
  Kontrolle.
- **Bank-Import:** erkennt `TICKET-<Nr>` bei Einnahmen, schlägt die Bestellung
  vor, bucht auf das im Formular eingestellte Konto und markiert sie als
  bezahlt (auch in der Desktop-App).
- **Einlass:** QR-Code des Tickets mit der normalen Handy-Kamera scannen. Die
  Seite zeigt gültig / schon eingelöst / storniert / **Zahlung noch nicht
  verbucht** (dann nach Ermessen „trotzdem einlassen“). Berechtigt sind
  Formular-Verwaltende oder wer die **Einlass-PIN** kennt (steht bei den
  Einträgen, gilt 12 Stunden auf dem Gerät). Ohne QR: Einlass-Liste mit Suche
  nach Name, Bestellnummer oder Code.
- **Einträge:** Übersicht (verkauft je Art/Kontingent, bezahlt/offen,
  eingelassen), Filter nach Zahlung, je Bestellung ✓ überwiesen /
  Ticket-Mail erneut / stornieren, Zahlungserinnerung an alle offenen.

Bezahlt wird nur per Überweisung – keine Barzahlung am Einlass.

## Teilen: Formulare und Schichtpläne per Link & QR-Code

Ohne eigene WordPress-Seite mit Shortcode: Jedes Formular hat die Adresse
`https://eure-seite.de/?vp_formular=<ID>`, jeder Schichtplan
`https://eure-seite.de/?vp_schichtplan=<ID>`. Das Plugin zeigt dort eine
schlichte Seite (Vereinsname, Formular bzw. Schichtplan) – auch auf dem Handy.
Geteilt wird die ID, damit Links und gedruckte QR-Codes beim Umbenennen
gültig bleiben.

Die **Teilen-Box** (Link kopieren, öffnen, QR-Code, QR als PNG oder SVG
herunterladen) steht in der Formular-Liste („Link & QR“), oben im
Formular-Editor, beim Schichtplan (Mitgliederbereich → Schichtpläne, für
Verwaltende) und im Projekt bei Anmeldung und Schichtplan. Formulare im
Entwurf sind über den Link nur für Verwaltende sichtbar.

## Projekte: Teilnehmenden-Szenarien, Förderungen, Sponsoring

- **Berechnung je Posten** (Reiter Finanzen): fester Betrag, **je
  Teilnehmende:r** (Eintritt, Verpflegung, Material) oder **× Anzahl**.
- **Szenarien**: erwartete Teilnehmende plus weitere Zahlen (z. B. „50, 150“).
  Ein Vergleich zeigt je Szenario Einnahmen, Ausgaben und Ergebnis, dazu den
  **Break-even** (ab wie vielen Teilnehmenden es sich trägt). Per Chip lässt
  sich die Kalkulation für jede Zahl anzeigen; Budget und Plan/Ist rechnen
  mit der erwarteten Zahl.
- **Förderungen** als Einnahme-Posten mit Status *noch nicht beantragt /
  Antrag gestellt / genehmigt / abgelehnt*. **Ergebnis sicher** zählt nur
  genehmigte Förderungen und zugesagtes Sponsoring, **erwartet** auch
  beantragte; abgelehnte zählen nie (durchgestrichen).
- **Sponsoring** als Posten mit Annahme „X Unternehmen × Betrag“.
- **Sponsoring & Mailingliste** (Projekte & Veranstaltungen → Unterpunkt, nur
  wer Kreise/Projekte verwaltet): Unternehmen mit Ansprechperson, Kontakt,
  Branche, Notiz und Haken „Mailingliste“; Anfragen je Projekt mit Status
  (ansprechen, angefragt, zugesagt, erhalten, abgesagt), Betrag und
  Sachleistung. Im Projekt-Reiter **Sponsoring**: angenommen / angefragt /
  zugesagt / fehlt noch. **Mailingliste**: Empfänger filtern (Liste, alle,
  je Projekt und Status), Adressen kopieren, BCC im eigenen Mailprogramm oder
  Rundmail von der Website – einzeln an jede:n, mit {name} und {firma}.

## „Als TOP vorschlagen“ (Themenspeicher)

In Kasse/Budgets, Kreiskassen, Wunschliste, Kreisen (inkl. neuer Kreis /
Unterkreis), Schichtplänen, Aufgaben, Kalender, Projekten und Veranstaltungen
steht oben der Knopf **„📌 Als TOP vorschlagen“**; im Kalender zusätzlich an
jedem Termin („📌 als TOP“) und bei den Kreis-Terminen.

- Titel und Kreis sind aus dem, was gerade offen ist, vorausgefüllt (z. B.
  „Kreiskasse & Budgets: AG Fest“ mit Kreis „AG Fest“).
- Ein Link zurück zur Quelle wird mitgespeichert; bei Kasse, Kreiskasse und
  Wunschliste auf Wunsch auch der **aktuelle Stand als Anlage**.
- Das Thema landet im **Themenspeicher** des Kreises. Wird es bei der
  Sitzungsplanung als TOP übernommen, stehen Quelle und Anlage direkt beim TOP
  (Live- und Detailansicht).
- Vorschlagen darf jedes Mitglied; übernommen wird bei der Sitzungsplanung.

## Live-Sitzung: weiterarbeiten und alles dokumentieren

Wer eine Sitzung **live protokolliert**, kann nebenbei überall weiterarbeiten:

- **Live-Leiste**: Solange die Sitzung läuft, steht oben in jedem Bereich des
  Mitgliederbereichs eine rote Leiste mit „Zum Protokoll“, „🎥 Online“ (Nextcloud
  Talk) und „Live verlassen“.
- **Automatische Dokumentation**: Was Teilnehmende währenddessen ändern – Kasse
  und Budgets (auch Kreiskassen), Wunschliste, Kreise (bearbeiten, Unterkreise,
  Mitglieder, Rollen), Schichtpläne, Aufgaben, Kalender, Projekte,
  Veranstaltungen –, erscheint im Protokoll unter **„Während der Sitzung
  erledigt“** (Uhrzeit, Bereich, was, wer). Einzelne Einträge lassen sich bis
  zum Abschluss wieder entfernen.
- **In der Live-Seitenleiste**:
  - *Online-Teilnahme*: Talk-Link bzw. Raum anlegen (Kreis-Chat oder eigener
    Raum mit Gast-Link).
  - *Weiterarbeiten an …*: Kassenbericht, Budgets, Kreiskasse und Wunschliste des
    Kreises, Kreis bearbeiten, Unterkreis anlegen, Schichtpläne, Aufgaben,
    Kalender, Projekte – öffnet in einem neuen Tab, das Protokoll bleibt offen.
  - *Bericht einfügen*: Kassenbericht des Vereins, Kreiskasse & Budgets eines
    Kreises oder die Wunschliste (alle bzw. eines Kreises) als Stand zum
    Zeitpunkt der Sitzung – optional einem TOP zugeordnet.
- Mit dem **Abschluss** des Protokolls endet die Live-Sitzung für alle.

## Nextcloud Talk: Kreis-Chats & Online-Besprechungen

Voraussetzung: In Nextcloud ist die App **Talk** installiert und unter „Verein →
Einstellungen“ der Nextcloud-Zugang eingetragen (derselbe wie für den
Benutzer-Sync). Dieses Konto legt die Unterhaltungen an und ist darin Moderator:in.

- **Chats & Besprechungen** (Seitenleiste, unter Kalender): die eigenen
  Kreis-Chats und die nächsten Online-Besprechungen, jeweils mit Link, der
  Nextcloud Talk (Browser oder Talk-App) öffnet. Eingebettet wird Talk nicht,
  weil Nextcloud das Einbetten in fremde Seiten standardmäßig blockiert.
- **Kreis-Chats**: Der Vorstand hakt dort die Kreise an; je Kreis entsteht eine
  Talk-Unterhaltung, deren Teilnehmende mit den Kreismitgliedern abgeglichen
  werden (neu rein, ausgetreten raus; auf Wunsch stündlich). Die
  Mitgliederversammlung umfasst alle Mitglieder.
- **Sitzungen**: In „Geplante Sitzungen“ gibt es „🎥 Online (Talk)“ – die
  Besprechung läuft im Kreis-Chat. Alternativ „Eigener Raum mit Gast-Link“ für
  Sitzungen mit Externen. Der Link steht auch im Kalender und im Abo.
- **Benutzer**: Die Zuordnung WordPress → Nextcloud übernimmt der bestehende
  **Nextcloud-Sync** (legt fehlende Konten auf beiden Seiten an, abgeglichen
  über die E-Mail-Adresse).

## Updates über GitHub

Das Plugin bringt einen Update-Checker mit (`vendor/plugin-update-checker/`,
YahnisElsts, MIT-Lizenz). Ist in `vereinsplugin.php` bzw. der `wp-config.php`
das GitHub-Repo gesetzt (`VP_GITHUB_REPO`), erscheinen neue Versionen ganz normal
unter **Plugins → Aktualisieren** – kein Löschen/Neu-Hochladen. Einrichtung und
Release-Ablauf: siehe [`../RELEASING.md`](../RELEASING.md).

## Aufbau / Weiterentwicklung

Siehe [`../PLAN.md`](../PLAN.md). Kurz:

- `vereinsplugin.php` – Modul-Registry & Bootstrap
- `includes/core-roles.php` – Rollenbrücke, Backend-Sperre
- `includes/shortcode-registry.php` – Katalog (single source of truth)
- `includes/admin-consolidation.php` – das eine Menü, Modul-Menüs verstecken
- `includes/member-area.php` – `[verein_mitgliederbereich]`
- `includes/settings-page.php` – Einstellungs-Hub
- `modules/*` – die vier Alt-Plugins, in Stage 1 unverändert eingebunden

Stand: **Stage 1 (Gerüst)**. Nächster Schritt: Boot-Test auf echter
WordPress-Instanz, dann Design-Vereinheitlichung und Portierung des
Event-Publishers ins Frontend.
