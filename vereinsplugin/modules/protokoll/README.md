# ProtokollPro — WordPress-Plugin

Digitale Sitzungsverwaltung für das Jugendforum Riedlingen e.V., abgeleitet aus der ursprünglichen Base44-App und angepasst an den Satzungs-/SVO-Entwurf (Gremien: MV, Vorstand, Leitungskreis, Kreise inkl. Beirat; Konsent-Verfahren als Standard-Entscheidungsprozess).

## Synergie mit der Vereins-Wunschliste

ProtokollPro legt **keine eigene Mitgliederverwaltung** an, sondern nutzt dieselben WordPress-User wie das `wunschliste-plugin`:

- Ist die Wunschliste aktiv, erhält deren Rolle `wl_mitglied` automatisch auch die ProtokollPro-Capability `pp_manage` (siehe `includes/roles.php`).
- Ist die Wunschliste nicht aktiv, legt ProtokollPro ersatzweise eine eigene, gleichwertige Rolle `pp_mitglied` an.
- Rollen wie Sprecher:in oder Kassier:in werden in ProtokollPro einem bestehenden WP-User zugeordnet (Dropdown mit `get_users()`) — es werden keine neuen Logins erzeugt.

## Installation

1. Ordner `protokollpro` nach `wp-content/plugins/` hochladen.
2. Im WordPress-Backend unter „Plugins" aktivieren.
3. Unter **ProtokollPro → Gremien** zuerst MV, Vorstand, Leitungskreis und eure Kreise (inkl. Beirat) anlegen.
4. Rollen (Sprecher:in, Kassier:in, Kreisleitung …) je Gremium eintragen.
5. Unter **ProtokollPro → Protokolle** das erste Protokoll erstellen und TOPs anlegen.

## Was V1.1 zusätzlich abdeckt

- **Rollenvorlagen** statt reiner Rollen-Freitextfelder: Jede Rolle (z. B. Kassier:in) wird einmal je Gremium definiert mit „Verantwortlich für", „Benötigte Fähigkeiten" und **Aufgaben**. Personen, die die Rolle übernehmen, erben diese Vorlage automatisch — auch bei jährlichem Wechsel (wichtig bei eurer versetzten Vorstandswahl).
- **Wiederkehrende Aufgaben** (z. B. „Kassier:in: Buchhaltung, monatlich") werden per täglichem WordPress-Cron automatisch als Aufgabe für die aktuelle Besetzung erzeugt. Vereinfachung: „monatlich" = alle 30 Tage, „jährlich" = alle 365 Tage (kein exaktes Kalenderdatum-Matching).
- **Event-Aufgaben** (z. B. „Wechselgeld bestellen, 14 Tage vorher") werden nicht automatisch, sondern über den Button „Event-Aufgaben erzeugen" bei einem Termin ausgelöst — das Ergebnis sind ganz normale Aufgaben-Einträge, die sich danach frei individualisieren oder löschen lassen.
- **Organigramm** (`[protokollpro_organigramm]`): radiale, mindmap-artige SVG-Grafik aller Gremien basierend auf den Eltern-Kind-Beziehungen — bewusst nicht als klassisches Top-Down-Hierarchiediagramm.
- **Kreis-Steckbrief** (`[protokollpro_kreis id="5"]`): ein flexibler, parametrisierter Shortcode statt eines eigenen Shortcode-Namens je Kreis — die passende ID steht auf der Gremien-Seite im Backend direkt zum Kopieren.

## Was V1.7 zusätzlich abdeckt

- **Kreismitgliedschaft:** Mitglieder können Kreisen selbst beitreten und sie wieder verlassen; in der Kreisversammlung lassen sich auch andere eintragen. Die Mitarbeit ist bewusst unabhängig von einer Rolle — man kann in einem Kreis mitarbeiten, ohne dort ein Amt zu haben. Austritte werden datiert statt gelöscht, die Mitarbeitshistorie bleibt erhalten. Auf der Übersichtsseite sieht jede Person ihre Kreise.
- **Regelmäßige Rollen-Aufgaben im Mitgliederbereich:** Je Rolle lassen sich jetzt direkt im Frontend Aufgaben hinterlegen — entweder *regelmäßig* (z. B. „Buchhaltung machen", monatlich; landet per täglichem Cron automatisch bei der aktuellen Besetzung) oder *vor Veranstaltungen* mit Vorlauf. Bisher ging das nur im WordPress-Backend.
- **Aufgaben-Sets:** Ein benanntes Bündel von Aufgaben rund um einen Anlass, z. B. „Veranstaltung": Kostenkalkulation (Kassier:in, 21 Tage vorher), Wechselgeld abheben (Kassier:in, 2 Tage vorher), Schichtplan erstellen (Kreisleitung, 14 Tage vorher). In der Terminliste lässt sich ein Set per Klick auf einen Termin anwenden; daraus entstehen alle Aufgaben mit aus dem Termindatum berechneter Frist, zugewiesen an die aktuellen Rolleninhaber:innen. Sets können vereinsweit oder kreisspezifisch gelten.
- **Doppelbesetzung berücksichtigt:** Da Sprecher:in und Kassier:in nach der neuen Satzung doppelt besetzt sind, würde sonst jede Aufgabe zweimal entstehen. Je Set-Eintrag ist deshalb wählbar, ob *eine Person* (Standard) oder *alle Personen* der Rolle die Aufgabe bekommen.
- Mehrfaches Anwenden desselben Sets auf denselben Termin erzeugt keine Dubletten; bereits vorhandene Aufgaben werden übersprungen und gemeldet. Ist eine Rolle unbesetzt oder keine hinterlegt, entsteht die Aufgabe trotzdem — ohne Zuständige, damit sie nicht untergeht.

## Was V1.6 zusätzlich abdeckt: die Vereins-App (PWA)

Der Mitgliederbereich lässt sich jetzt als App auf dem Handy installieren — ohne App Store, ohne Entwicklerkonto, ohne laufende Gebühren.

- **Web App Manifest** (`/?pp_manifest=1`) mit Name, Themenfarbe, Icons (192px, 512px, maskable für Android, Apple-Touch-Icon für iOS) und Schnellzugriffen beim langen Antippen des App-Icons (Protokolle / Aufgaben / Termine).
- **Service Worker** (`/?pp_sw=1`) mit bewusst zurückhaltendem Caching: **Seiteninhalte werden nie zwischengespeichert**, weil sie personenbezogen sind — gecacht werden ausschließlich statische Dateien (CSS, JS, Icons). Ohne Netz erscheint eine eigene Offline-Hinweisseite statt der Browser-Fehlermeldung.
- **Installationsbanner** im Mitgliederbereich: Auf Android/Chrome erscheint ein „Installieren"-Knopf über das `beforeinstallprompt`-Ereignis; auf iOS, wo es dieses Ereignis nicht gibt, wird stattdessen der Weg über Teilen → „Zum Home-Bildschirm" eingeblendet. Einmal weggeklickt, bleibt der Hinweis verschwunden.
- **Nextcloud-Apps per Deep Link** statt Nachbau: In der Seitenleiste stehen Chat (Talk), Dateien und Kalender. Das sind normale https-Links auf eure Nextcloud — sind die Nextcloud-Apps auf dem Handy installiert, öffnet das Betriebssystem sie direkt, sonst den Browser. Bewusst kein iframe: Nextcloud blockiert Einbettung per Sicherheitsheader, und Session-Cookies funktionieren in fremden Frames nicht zuverlässig.
- **Einstellungsseite** unter *ProtokollPro → App & Nextcloud*: App-Name, Themenfarbe und Nextcloud-Adresse. Aus der Basisadresse werden Talk, Dateien und Kalender automatisch abgeleitet; einzelne Ziele (z. B. ein bestimmter Talk-Raum) lassen sich überschreiben. Beim Speichern wird die Cache-Version hochgezählt, damit installierte Apps die Änderung bekommen.

**Voraussetzung:** HTTPS mit gültigem Zertifikat — ohne das lässt sich keine PWA installieren und der Service Worker wird nicht registriert.

## Was V1.5 zusätzlich abdeckt

- **Tagesordnung zeigt Uhrzeiten statt Minuten.** Aus der Startzeit der Sitzung (`uhrzeit_beginn`) werden pro TOP die geplanten Zeitfenster berechnet und als „19:10–19:35“ angezeigt — in der Sitzungsübersicht, der Detailansicht und der Live-Seitenleiste. Ohne eingetragene Startzeit fällt die Anzeige auf die Minutenangabe zurück.
- **Visuelles Zeitbudget bei der Tagesordnungserstellung.** Sind Beginn *und* Ende gesetzt, vergleicht ein Balken die Summe der geplanten TOP-Dauern mit dem verfügbaren Zeitfenster: grün mit „X Min. übrig“, bei Überschreitung ein roter Überhang-Balken mit „X Min. über dem Zeitrahmen“.
- **Tagesordnung bearbeiten.** In der Protokoll-Detailansicht lassen sich Titel und Dauer je TOP direkt ändern und die Reihenfolge per ▲/▼ verschieben. Die Sortierlogik nummeriert Altbestände ohne Sortierwerte automatisch sauber durch.
- **Tagesordnungsänderungen im Live-Modus laufen über einen Konsentbeschluss.** Statt die laufende Tagesordnung direkt zu verändern, wird ein Antrag gestellt (TOP aufnehmen / streichen / Dauer ändern). Daraus entsteht automatisch ein eigener Tagesordnungspunkt vom Typ `to_aenderung` mit **vorformuliertem Beschlussvorschlag** (`pp_to_aenderung_vorlage()`): Beschlussvorschlag, Begründung und der Hinweis, dass die Änderung erst mit dem Beschluss wirksam wird. Erst wenn dieser Punkt die Konsentrunde ohne Einwand passiert, wird die Änderung tatsächlich angewendet (`pp_apply_to_aenderung()`, einmalig über das Flag `to_aenderung_erledigt`).

## Was V1.4 zusätzlich abdeckt

**Kreise und Rollen lassen sich jetzt im Mitgliederbereich bearbeiten** (vorher nur im WordPress-Backend), aufgeteilt genau nach der Logik der Selbstverwaltungsordnung:

- **Kreis-Grunddaten = SVO Teil B, Beschluss des Leitungskreises.** Je Kreis werden Name, Typ, **Zweck/Aufgabenbereich**, **Entscheidungsfindung** (Konsent / Mehrheit / geheime Wahl), Sichtbarkeit der Protokolle und ein übergeordnetes Gremium festgelegt. Jede Einrichtung, Änderung und Auflösung erzeugt automatisch einen Eintrag in der Bestätigungs-Queue, damit die nächste Vollversammlung sie bestätigen oder revidieren kann (§ 10 der Satzung).
- **Leitung:** Bei jedem neu eingerichteten Kreis wird automatisch die Rolle „Kreisleitung" mit sinnvollen Standard-Aufgaben angelegt; die Besetzung erfolgt über die Rollenverwaltung.
- **Rollen = SVO Teil C, vom Kreis selbst festgelegt.** Je Rolle (Kreisleitung, Kassier:in, Schriftführung, …) werden **Aufgaben der Rolle** und **nötige Skills** als Listen definiert, jeweils eine Zeile pro Eintrag. Personen werden mit Amtszeit zugeordnet; die Anzeige „aktuell besetzt durch" berücksichtigt nur laufende Amtszeiten.
- **Auflösen statt löschen:** Ein aufgelöster Kreis wird deaktiviert, eine beendete Besetzung erhält ein Amtszeit-Ende auf den Vortag. Protokolle und Rollenhistorie bleiben dadurch nachvollziehbar.

## Was V1.3 zusätzlich abdeckt

Der Mitgliederbereich (`[protokollpro_mitgliederbereich]`) wurde grundlegend überarbeitet:

- **Seitenleisten-Layout über die volle Fensterbreite** statt schmaler Spalte mit Menüband oben. Der Bereich bricht per `margin-left: calc(50% - 50vw)` aus einem zentrierten Theme-Container aus; auf Mobilgeräten klappt die Seitenleiste nach oben.
- **Protokollliste integriert** — alle Protokolle als Tabelle, zusätzlich die geplanten Sitzungen mit ihrer **kompletten Tagesordnung** (vorher nicht sichtbar). TOPs lassen sich direkt in der Liste ergänzen und entfernen.
- **Einzelansicht je Protokoll** mit Kopfdaten, Tagesordnung inkl. Beschlüssen und Kommentaren.
- **Themen sind kreisspezifisch**: Jedes Thema wird einem Kreis/Gremium zugeordnet und im Themenspeicher nach Kreis gruppiert angezeigt. Beim Anlegen eines TOPs stehen die Themen des jeweiligen Kreises plus kreisübergreifende Themen zur Auswahl.
- **Geplante Sitzungen erzeugen automatisch einen Termin** (`pp_sync_termin_fuer_protokoll`) — beim Anlegen und bei jeder Änderung von Datum/Titel/Ort. Ohne Datum wird kein Termin erzeugt bzw. ein vorhandener entfernt. Diese Termine erscheinen dadurch auch im persönlichen Kalender-Feed.
- **Live-Modus** (`?pp_view=live`): Die Navigations-Seitenleiste wird ausgeblendet und durch eine Sitzungs-Seitenleiste ersetzt mit
  - laufender **Uhr** und Anzeige, wie lange die Sitzung schon läuft,
  - **Tagesordnung mit Zeitplan**: je TOP eine geplante Dauer (neues Feld `dauer_minuten`), daraus berechnet das Frontend live „in X Min.“ / „noch X Min.“ / „X Min. drüber“; bereits beschlossene TOPs werden als erledigt markiert statt als überzogen,
  - **Schnellerfassung von Aufgaben und Terminen** während der Sitzung,
  - spontanes Ergänzen weiterer TOPs.
  Rechts wird direkt protokolliert: Notizen und Beschlusstext je TOP, Konsent-Schritte (weiter / beschließen / Einwand / erneut vorlegen) und der Protokollabschluss.

## Was V1.2 zusätzlich abdeckt

- **Organigramm überarbeitet**: größere Kreise und Schrift, verständlichere Legende (inkl. Erklärung des Zentrums und der Verbindungslinien).
- **Mitgliederbereich** (`[protokollpro_mitgliederbereich]`) — ein Frontend-Bereich für eingeloggte Mitglieder (Capability `pp_manage`) mit:
  1. Neues Protokoll anlegen (Kopfdaten; die Tagesordnung mit Konsent-Ablauf wird weiterhin im Backend bearbeitet — Mitglieder haben dort automatisch Zugriff)
  2. Bestehende Protokolle einsehen und kommentieren (neue Tabelle `pp_kommentare`)
  3. Themen zum Themenspeicher hinzufügen
  4. Themen aus dem Themenspeicher als TOP zu geplanten Sitzungen (Protokolle im Entwurf) hinzufügen
  5. Eigene und alle offenen Aufgaben einsehen, eigene abhaken
  6. Anstehende Termine einsehen
  7. **Kalender-Sync**: persönlicher, geheimer iCalendar-Feed (`.ics`) zum Abonnieren in Google Kalender/Apple Kalender/Outlook — Aufgaben erscheinen als To-Dos mit Fälligkeitsdatum, Termine als Kalendereinträge. Kein OAuth/Google-API nötig, funktioniert über die Standard-„Kalender abonnieren"-Funktion. Link ist über einen Token abgesichert und lässt sich bei Bedarf zurücksetzen.

## Was V1 abdeckt

- Gremienverwaltung (MV, Vorstand, Leitungskreis, Kreise, Kreisversammlungen) mit Rollen
- Protokoll-Erstellung mit Phasen (Check-In → Organisatorisches → Tagesordnung → Check-Out)
- **Konsent-Workflow pro TOP**: Vorstellung → Verständnisfragen → Meinungsrunde → Konsentrunde, inkl. Einwand-Behandlung und erneuter Vorlage
- Alternative Verfahren pro TOP wählbar (Mehrheit, geheime Wahl) — die eigentliche Durchführung dieser Verfahren ist in V1 nur als Kennzeichnung vorhanden, nicht als eigener Workflow
- Themenspeicher mit SVO-Teil-Bezug (A/B/C)
- Aufgaben & Termine, automatisch generiert bei Protokollabschluss
- Bestätigungs-Queue für Leitungskreis-Beschlüsse (Mitgliedschaft, Kreise) zur Vorlage an die nächste MV
- Freigabe-Workflow (Vier-Augen-Prinzip) inkl. optionalem Kreisversammlungs-Konsent
- Shortcode `[protokollpro_oeffentlich]` für die Veröffentlichung von MV-Protokollen auf der Website

## Was bewusst noch offen ist (V2-Kandidaten)

- **Feingranulare Berechtigungen**: aktuell dürfen alle `pp_manage`-Nutzer:innen alles; eine Prüfung „ist diese Person Mitglied genau dieses Gremiums" fehlt noch (`pp_can_lead()` ist ein Platzhalter).
- **Geheime Wahl / Mehrheitsentscheid** als eigener, geführter Ablauf (aktuell nur als Verfahrens-Kennzeichnung am TOP).
- **SVO-Teil-A-Review-TOP**: Der TOP-Typ existiert, listet aber noch nicht automatisch die einzelnen SVO-Abschnitte auf — das müsste mit dem tatsächlichen SVO-Text aus dem Satzungsdokument befüllt werden.
- **Live-AJAX** für den Konsent-Workflow statt Seiten-Reloads (Grundgerüst liegt in `includes/ajax.php` / `assets/script.js`).
- **E-Mail-Benachrichtigungen** bei neuen Aufgaben/Terminen (könnte analog zu `wl_send_member_credentials` in der Wunschliste ergänzt werden).

## Datenbank

Alle Tabellen nutzen den WordPress-Tabellenpräfix + `pp_` (z. B. `wp_pp_gremien`), analog zum `wl_`-Präfix der Wunschliste. Schema-Änderungen laufen wie dort über `dbDelta()` und einen Versions-Check bei `plugins_loaded`.
