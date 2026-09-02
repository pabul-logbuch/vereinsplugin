# Vereins-Wunschliste – WordPress Plugin

## Installation

1. **Plugin hochladen**
   - Diesen Ordner `wunschliste-plugin` in `/wp-content/plugins/` kopieren
   - Alternativ: als ZIP verpacken → WordPress Admin → Plugins → Neu hinzufügen → Plugin hochladen

2. **Plugin aktivieren**
   - WordPress Admin → Plugins → „Vereins-Wunschliste" → Aktivieren
   - Beim Aktivieren werden Beispieldaten und die Datenbanktabelle automatisch angelegt

---

## Einstellungen (einmalig)

WordPress Admin → **Wunschliste → Einstellungen**:
- Kontoinhaber (z.B. „Sportverein Muster e.V.")
- IBAN
- BIC
- E-Mail-Adresse für Spenden-Anfragen

---

## Seiten anlegen

### Öffentliche Spender-Seite
Neue WordPress-Seite erstellen, Shortcode einfügen:
```
[wunschliste]
```

### Mitglieder-Verwaltung
Neue Seite erstellen (z.B. „Mitgliederbereich"):
```
[wunschliste_login]
[wunschliste_verwaltung]
```

---

## Mitglieder anlegen

WordPress Admin → **Wunschliste → Mitglied anlegen**

- Mitglieder erhalten die Rolle `Vereinsmitglied`
- Sie können sich auf der Mitglieder-Seite einloggen
- Sie sehen kein WordPress-Dashboard – nur die Wunschlisten-Verwaltung
- Admins & Editoren haben automatisch auch Zugriff

---

## Shortcodes

| Shortcode | Beschreibung |
|-----------|-------------|
| `[wunschliste]` | Öffentliche Ansicht mit Filter + Spende-Modal |
| `[wunschliste_verwaltung]` | Mitglieder-Bereich: Wünsche anlegen/bearbeiten/löschen |
| `[wunschliste_login]` | Login-Formular für Mitglieder |
| `[wunschliste kategorie="Sport"]` | Nur Wünsche einer Kategorie zeigen |
| `[wunschliste status="offen"]` | Nur offene Wünsche zeigen |

---

## Funktionsweise

**Für Spender (öffentlich):**
- Wünsche mit Kategorie-Filter durchstöbern
- Auf „Jetzt spenden" klicken
- Kontodaten für Überweisung sehen
- Optional: Kurze Nachricht mit Name + E-Mail absenden
- Verein erhält E-Mail, Spender bekommt Bestätigung

**Für Mitglieder (eingeloggt):**
- Neue Wünsche anlegen
- Bestehende bearbeiten (Titel, Beschreibung, Betrag, Kategorie, Status, Priorität, Bild)
- Wünsche als „erfüllt" markieren oder löschen

**Status-Optionen:**
- 🟡 Offen
- 🔵 In Bearbeitung
- 🟢 Erfüllt

**Prioritäten:**
- ⭐ 1 – Dringend (wird als Badge hervorgehoben)
- 2 – Normal
- 3 – Niedrig

---

## Datenbankstruktur

Tabelle: `wp_wunschliste`

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| id | BIGINT | Auto-Increment |
| titel | VARCHAR(255) | Name des Wunsches |
| beschreibung | TEXT | Kurzbeschreibung |
| begruendung | TEXT | Warum wird das gebraucht? (aufklappbar auf Spenderseite) |
| betrag | DECIMAL(10,2) | Fester Betrag in € |
| preis_von / preis_bis | DECIMAL(10,2) | Preisspanne statt Festbetrag |
| kategorie | VARCHAR(100) | Frei wählbar |
| status | ENUM | offen / in_bearbeitung / erfuellt |
| prioritaet | TINYINT | 1/2/3 |
| bild_url | VARCHAR(500) | Optionales Bild |
| erstellt_am | DATETIME | Automatisch |
| erstellt_von | BIGINT | WordPress User ID |

Tabelle: `wp_wl_links` (Produktlinks, mehrere pro Wunsch)

| Spalte | Beschreibung |
|--------|-------------|
| wunsch_id | Verweist auf wp_wunschliste.id |
| label | Anbietername (z.B. "Amazon") |
| url | Produktlink |
| preis | Preis bei diesem Anbieter |

---

## Mitglieder per CSV/XML importieren

**Wunschliste → Mitglieder Import**

- Vorlage herunterladen (CSV oder XML), ausfüllen, hochladen
- Pflichtfelder: `name`, `email`
- `username` ist optional — wird sonst automatisch aus dem Namen generiert (Kollisionen werden durch angehängte Zahlen vermieden)
- Passwörter werden automatisch zufällig generiert
- Checkbox „Zugangsdaten automatisch per E-Mail verschicken" — aktiviert, verschickt jedes neue Mitglied eine Mail mit Benutzername + Passwort + Login-Link
- Nach dem Import erscheint zusätzlich eine Tabelle mit allen neuen Zugangsdaten (Passwörter werden nur einmal angezeigt — am besten gleich sichern, falls der Mail-Versand fehlschlägt)
- Bereits registrierte E-Mail-Adressen werden übersprungen, nicht überschrieben
- Neue Mitglieder erhalten automatisch die Rolle `Vereinsmitglied`



**Wunschliste → CSV/XML Import**

- Vorlagen herunterladen (CSV oder XML), ausfüllen, wieder hochladen
- Pflichtfeld: nur `titel`
- Unterstützt: Beschreibung, Begründung, Festbetrag ODER Preisspanne, Kategorie, Status, Priorität, Bild, **beliebig viele Produktlinks pro Wunsch**
- CSV: Trennzeichen Komma oder Semikolon wird automatisch erkannt; Mehrfach-Links über Spalten `link1_label/link1_url/link1_preis`, `link2_*`, usw.
- XML: Links über verschachteltes `<links><link>...</link></links>`-Element
- Import ist additiv (überschreibt keine bestehenden Wünsche, legt neue an)

---

## Begründung & Produktlinks (Frontend)

Auf der öffentlichen Spendenseite:
- Begründung erscheint als aufklappbarer Bereich ("Warum brauchen wir das?")
- Produktlinks erscheinen als anklickbare Chips mit Anbietername und Preis, öffnen in neuem Tab
- Preisanzeige zeigt automatisch Festbetrag oder Spanne ("80,00 – 120,00 €"), je nachdem was gepflegt wurde

Im Mitgliederbereich:
- Umschalter zwischen Festbetrag und Preisspanne beim Anlegen/Bearbeiten
- Dynamische Liste zum Hinzufügen/Entfernen von Produktlinks (+ Link hinzufügen)


---

## Schichtpläne für Veranstaltungen

Eigenständiges Modul für Schichtpläne bei Veranstaltungen (z.B. Stadtfestival, Jugendforum-Events) — öffentlich einsehbar und eintragbar, von Mitgliedern verwaltbar.

### Shortcodes

| Shortcode | Beschreibung |
|-----------|-------------|
| `[schichtplan event="stadtfestival"]` | Zeigt den Schichtplan einer bestimmten Veranstaltung (Slug) |
| `[schichtplan]` | Zeigt eine Übersicht aller aktiven Veranstaltungen zur Auswahl |
| `[schichtplan_verwaltung]` | Mitgliederbereich: Veranstaltungen, Stationen und Schichten anlegen/bearbeiten |

### Aufbau

- **Veranstaltung** (z.B. "Stadtfestival 2026") → hat eigenen Slug für die URL
- **Station** (z.B. "Hauptausschank", "Kasse", "Aufbau Freitag") → mit Erklärung, Treffpunkt, 1-2 Ansprechpersonen
- **Schicht** (z.B. "1. Schicht, 18:00–21:30") → mit individueller max. Personenzahl
- **Eintragung** → Name, E-Mail, Telefon (optional); kein Account nötig

### So legst du einen Schichtplan an

**Variante A — manuell im Mitgliederbereich:**
1. Seite mit `[schichtplan_verwaltung]` öffnen (eingeloggt als Mitglied)
2. „+ Neue Veranstaltung" → Titel + Datum
3. In der Veranstaltung: „+ Neue Station" für jede Schicht-Kategorie (z.B. Bar, Kasse, Aufbau)
4. Pro Station: „+ Schicht hinzufügen" für jeden Zeitblock mit eigener Personenzahl

**Variante B — CSV/XML Import:**
1. WordPress-Admin → **Wunschliste → Schichtplan Import**
2. Vorlage herunterladen, ausfüllen (eine Zeile = eine Schicht; gleicher Stations-Name fasst automatisch zusammen)
3. Bestehende oder neue Veranstaltung als Ziel wählen
4. Hochladen

### Einbettung auf einer Veranstaltungsseite

Auf `juforiedlingen.org/programm/stadtfestival` einfach den Shortcode einfügen:
```
[schichtplan event="stadtfestival"]
```
Der Slug wird beim Anlegen automatisch aus dem Titel generiert (z.B. "Stadtfestival 2026" → `stadtfestival-2026`) und ist im Mitgliederbereich und unter **Wunschliste → Schichtpläne** einsehbar.

### Eintragung & Austragung

- Jeder (Mitglied oder Gast) kann sich mit Name, E-Mail und optional Telefonnummer eintragen — kein Account nötig
- Eingeloggte Mitglieder bekommen Name/E-Mail vorausgefüllt
- Nach der Eintragung gibt's eine Bestätigungs-E-Mail mit allen Details (Station, Zeit, Treffpunkt, Ansprechperson) **und einem persönlichen Austragungs-Link**
- Über diesen Link kann sich jeder jederzeit selbst wieder austragen — ohne Login
- Mitglieder können im Verwaltungsbereich zusätzlich jede Eintragung manuell entfernen (z.B. bei Absagen per Telefon)
- Ist eine Schicht voll, verschwindet der "Eintragen"-Button automatisch

### Tabellarische Tagesansicht

Die öffentliche Schichtplan-Seite zeigt die Schichten als **Kalenderansicht**: Tage stehen **nebeneinander** (mit gemeinsamer horizontaler Scroll-Leiste, falls mehr Tage da sind als auf den Bildschirm passen), innerhalb jedes Tages laufen die Stationen als Spalten mit einer proportionalen Zeitachse. Jeder Schicht-Block ist proportional zu seiner Dauer hoch positioniert — wie ein klassischer Tageskalender (Outlook/Google Calendar-Stil). Die Seite selbst scrollt ganz normal vertikal; es gibt keine eingeschränkten Scroll-Bereiche mehr innerhalb einzelner Tage oder Stationen.

**Überlappungen:** Wenn bei derselben Station zwei Schichten sich zeitlich überschneiden, bekommen sie automatisch eigene Spuren nebeneinander statt sich zu überlappen. Schichten ohne Überschneidung teilen sich weiterhin dieselbe Spalte.

**Tagesgrenze:** Pro Veranstaltung einstellbar (z.B. 4 Uhr), unter „⚙️ Einstellungen ändern" im Verwaltungsbereich der Veranstaltung. Schichten, die vor dieser Uhrzeit beginnen, werden noch dem Vortag zugeordnet — eine Nachtschicht von 01:00–04:00 Uhr Samstag erscheint bei Tagesgrenze 4 Uhr also noch im Freitag-Block, nicht in einem eigenen Samstag-Block. So bleiben zusammengehörige Nachtschichten visuell beim richtigen "Veranstaltungstag".

Schichten ganz ohne Startzeit erscheinen unterhalb der Kalenderblöcke als einfache Liste „Ohne festen Termin".

### Mobile Ansicht

Unterhalb von 680px Bildschirmbreite schaltet die Schichtplan-Seite automatisch von der Kalenderansicht auf eine **gestapelte Listenansicht** um: pro Tag eine Liste aller Schichten, chronologisch sortiert, mit Station, Zeit, Treffpunkt und Eintragen-Button als Karte. Kein horizontales Scrollen oder Quetschen mehrerer Stationen-Spalten auf kleinen Screens.

---

## Änderungen v4.0

**Schichten bearbeiten repariert:** Alle Aktions-Buttons (Bearbeiten, Löschen, Modal-Schließen) im Schichtplan-Bereich haben jetzt explizit `type="button"`. Vorher konnte ein Klick auf "Bearbeiten" versehentlich das umgebende Formular auslösen, statt das Bearbeiten-Modal zu öffnen.

**Mindestplätze pro Schicht:** Neben der maximalen Personenzahl kann jetzt auch eine Mindestanzahl festgelegt werden. Ist eine Schicht unterbesetzt (weniger Eintragungen als Minimum), erscheint ein ⚠️-Warnhinweis sowohl im Mitgliederbereich als auch auf der öffentlichen Seite.

**Kategorie-Filter lesbar:** Die Filter-Buttons auf der Wunschlisten-Seite hatten keine explizite Textfarbe und konnten je nach Theme unleserlich hell erscheinen. Jetzt mit fest definierter, kontrastreicher Schriftfarbe.

**Voting + Verwaltung zusammengelegt:** Die Abstimmungsseite (`[wunschliste_voting]`) zeigt jetzt für jeden Wunsch alle Infos auf einen Blick — Bild, Beschreibung, Begründung (aufklappbar), Produktlinks, Preis/Preisspanne, Status und Priorität. Zusätzlich gibt es eine Kategorie-Filterleiste wie auf der Spenderseite. Eingeloggte Mitglieder sehen direkt auf der Voting-Seite zusätzlich „+ Neuer Wunsch" sowie Bearbeiten-/Löschen-Buttons pro Karte — ein Wechsel zur separaten Verwaltungsseite ist für die meisten Aufgaben nicht mehr nötig.

---

## Änderungen v4.1

**Cache-Busting für CSS/JS:** Die Dateien `style.css` und `script.js` werden jetzt mit einer automatisch generierten Versionsnummer (basierend auf dem Änderungsdatum der Datei) ausgeliefert statt einer festen Plugin-Versionsnummer. Das verhindert, dass Browser-Caches, CDNs (z.B. Cloudflare) oder Cache-Plugins (z.B. WP Super Cache) nach einem Plugin-Update weiterhin eine veraltete Version ausliefern. Falls Buttons nach einem Update nicht reagieren, hilft zusätzlich ein hartes Neuladen der Seite (Strg+Shift+R bzw. Cmd+Shift+R).

**Zeitlücken im Kalender werden komprimiert:** Gibt es an einem Tag Pausen zwischen Schichten (z.B. Nachtschicht 4–8 Uhr, Mittagsschicht 12–15 Uhr, Abendschicht 19–23 Uhr), nehmen diese Lücken nicht mehr unnötig viel Platz auf der Zeitachse ein. Belegte Zeiträume behalten ihre echte, proportionale Länge; Pausen dazwischen werden auf eine kleine Fixbreite zusammengestaucht und mit einer schraffierten Markierung („⋯ 4h Pause ⋯") sichtbar gemacht, damit klar bleibt, dass dort Zeit übersprungen wurde.

---

## Änderungen v4.2

**Hauptverantwortliche und Aufgabenbeschreibung jetzt überall sichtbar:** Beim Anlegen einer Station konnten Erklärung, Treffpunkt und bis zu zwei Ansprechpersonen (inkl. Kontakt) schon immer eingegeben werden — sie wurden auf der öffentlichen Seite aber nur unvollständig angezeigt. Jetzt zeigt:
- **Desktop-Kalender:** Beide Ansprechpersonen samt Kontakt im Spaltenkopf, plus ein ℹ️-Info-Button, der bei Klick die Aufgabenbeschreibung als Popup einblendet (damit die schmalen Stationen-Spalten nicht durch langen Text gesprengt werden)
- **Mobile-Ansicht:** Aufgabenbeschreibung direkt auf der Karte sichtbar, beide Ansprechpersonen mit Kontaktdaten
- **„Ohne festen Termin"-Liste:** ebenfalls vollständige Stations-Infos

---

## Änderungen v4.3 — Kritischer Bugfix

**Ursache des "Bearbeiten funktioniert nicht"-Problems gefunden:** Das Skript `script.js` lädt auf jeder Seite des Plugins (Wunschliste, Voting, Schichtplan), enthält aber Code-Stellen, die direkt auf Formulare zugriffen, die nur auf bestimmten Seiten existieren — z.B. `$('#wl-form-panel').offset()` (Wunschlisten-Formular). Auf der Schichtplan-Seite existiert dieses Element nicht, wodurch `.offset()` auf ein leeres jQuery-Set traf und einen JavaScript-Fehler warf:

```
Uncaught TypeError: can't access property "top", $(...).offset() is undefined
```

Dieser Fehler brach die **komplette restliche Skriptausführung ab** — alle danach im Code folgenden `$(document).on(...)`-Bindungen (inklusive der Schicht-Bearbeiten-Buttons) wurden dadurch nie registriert, weshalb Klicks darauf wirkungslos blieben.

**Behoben durch zwei globale Helper-Funktionen** (`wlScrollToPanel()`, `wlResetForm()`), die zuerst prüfen, ob das jeweilige Element auf der aktuellen Seite überhaupt existiert, bevor sie darauf zugreifen. Alle betroffenen Stellen (Wunschliste, Schichtplan-Stationen, Voting-Formular) nutzen jetzt diese sicheren Varianten.

---

## Änderungen v5.0 — Kalendereintrag & Erinnerungsmail

**Kalenderdatei (.ics):** Jede Bestätigungsmail nach einer Schicht-Eintragung enthält jetzt einen Link „📅 Zum Kalender hinzufügen". Die heruntergeladene `.ics`-Datei lässt sich mit einem Klick in Google Kalender, Apple Kalender, Outlook oder jede andere gängige Kalender-App importieren — mit Titel, Uhrzeit, Treffpunkt, Aufgabenbeschreibung und Ansprechperson. Die Datei enthält außerdem eine eingebaute 1-Tag-vorher-Erinnerung, falls der jeweilige Kalender das unterstützt.

Mitglieder sehen im Verwaltungsbereich zusätzlich ein 📅-Symbol neben jeder Eintragung, falls sie den Link für jemanden manuell nachschicken wollen.

**Automatische Erinnerungsmail (24h vorher):** Ein WP-Cron-Job prüft stündlich, ob Schichten in den nächsten 23–25 Stunden beginnen, und verschickt dafür automatisch eine Erinnerungsmail mit allen Details (Zeit, Treffpunkt, Ansprechperson, Aufgabe) sowie erneutem Kalenderlink und Austragungslink. Jede Eintragung bekommt die Erinnerung nur einmal — das wird in der Datenbank vermerkt.

**Technischer Hinweis:** Die Cron-Aufgabe läuft nur, wenn die Website regelmäßig besucht wird (WP-Cron ist besucherbasiert, kein echter Server-Cronjob). Bei sehr wenig Traffic kann es sinnvoll sein, stattdessen einen echten Server-Cronjob auf `wp-cron.php` einzurichten — frag im Zweifel deinen Hoster, ob „WP-Cron deaktivieren + echten Cronjob einrichten" möglich ist, falls Erinnerungen unzuverlässig ankommen.

---

## Änderungen v5.0.1 — Bugfix: Mehrfach-Eintragung ohne Neuladen

**Problem:** Nach erfolgreicher Eintragung für eine Schicht blieb der „Verbindlich eintragen"-Button im Zustand „Wird eingetragen…" hängen. Öffnete man danach das Modal für eine zweite Schicht (ohne die Seite neu zu laden), war der Button weiterhin deaktiviert und reagierte nicht.

**Ursache:** Der Button wurde nach erfolgreicher Eintragung nie wieder aktiviert — das war nur für den Fehlerfall vorgesehen, da das Modal im Erfolgsfall automatisch nach 4 Sekunden schließt. Öffnete man es aber vorher erneut für eine andere Schicht, war derselbe (deaktivierte) Button-Zustand noch da.

**Behoben:** Der Button wird jetzt sowohl beim Öffnen des Modals (für jede neue Schicht) als auch direkt nach erfolgreicher Eintragung zuverlässig zurückgesetzt.

---

## Änderungen v5.1 — Druck-/PDF-Export

**Im Verwaltungsbereich** (`[schichtplan_verwaltung]`, beim Bearbeiten einer Veranstaltung):

- **„🖨️ Gesamtplan drucken"** (oben im Header) — eine druckoptimierte Übersicht aller Stationen mit allen Schichten, Zeiten, Plätzen und den Namen der bereits Eingetragenen
- **„🖨️ Drucken"** pro Station (in jeder Stations-Karte) — ein Einzelblatt nur für diese eine Station, gedacht zum Aushängen direkt an Ort und Stelle (z.B. an der Hauptausschank-Theke)

Beide öffnen eine schlanke Druckansicht ohne Menü/Sidebar in einem neuen Tab. Über **„Drucken / Als PDF speichern"** (oder Strg/Cmd+P) öffnet sich der Browser-Druckdialog — dort kann man entweder direkt auf einem angeschlossenen Drucker ausdrucken oder als Ziel „Als PDF speichern" wählen, um eine PDF-Datei zu erzeugen.

**Enthält:**
- Stationstitel, Aufgabenbeschreibung, Treffpunkt, Ansprechperson(en) mit Kontakt
- Alle Schichten mit Zeit, Min./Max.-Plätzen (Warnsymbol bei Unterbesetzung)
- Namen aller bereits Eingetragenen
- Beim Einzelblatt zusätzlich eine Unterschriftszeile „Ausgehängt am: ___" für die Dokumentation vor Ort

Nur für eingeloggte Mitglieder mit Verwaltungsrechten zugänglich.

---

## Änderungen v5.2 — Zusammengefasste Erinnerung pro Person

**Vorher:** Wer sich für mehrere Schichten eintrug, bekam pro Schicht eine eigene Erinnerungsmail (jeweils 24h vor dem individuellen Schicht-Start) und einen eigenen, einzelnen Kalendertermin.

**Jetzt:**
- **Eine einzige Erinnerungsmail pro Person**, verschickt **24 Stunden vor dem Veranstaltungsdatum** (nicht mehr pro einzelner Schicht-Uhrzeit). Sie listet **alle** Schichten dieser Person bei der Veranstaltung chronologisch auf — egal ob die Schichten am ersten, zweiten oder dritten Veranstaltungstag liegen.
- **Eine Sammel-Kalenderdatei** (.ics mit mehreren Terminen) statt einzelner Dateien — ein Klick importiert alle Schichten der Person auf einmal in Google/Apple/Outlook Kalender.
- Personen werden anhand ihrer E-Mail-Adresse zusammengefasst (Groß-/Kleinschreibung und Leerzeichen werden ignoriert).
- Der Sammel-Link ist mit einer Signatur gesichert (HMAC), sodass niemand durch Erraten von E-Mail-Adressen an fremde Kalenderdaten kommen kann.

**Bestätigungsmail beim Eintragen:** Hat die Person zum Zeitpunkt der Eintragung schon weitere Schichten bei derselben Veranstaltung, weist die Bestätigungsmail zusätzlich auf den Sammel-Kalenderlink hin („Du bist jetzt für insgesamt X Schichten eingetragen").

**Wichtig:** Die Erinnerung wird pro Veranstaltung nur einmal pro Person verschickt — nicht erneut bei jeder weiteren Schicht, die später noch dazukommt, solange für mindestens eine ihrer bisherigen Eintragungen schon eine Erinnerung lief. Trägt sich jemand z.B. erst kurz vor knapp für eine zusätzliche Schicht ein, nachdem die Sammel-Erinnerung schon raus ist, bekommt er für diese neue Schicht die normale Eintragungsbestätigung inkl. Hinweis auf den (jetzt aktualisierten) Sammel-Kalenderlink, aber keine zweite Erinnerungsmail.
