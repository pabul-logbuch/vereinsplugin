=== Jufobleibt Event Publisher ===
Version: 0.2.0

VORAUSSETZUNG
Dieses Plugin knüpft an das "Vereins-Wunschliste"-Plugin an und nutzt dessen
Mitglieder-Rolle "wl_mitglied". Das Wunschlisten-Plugin muss installiert und
aktiv sein, und Mitglieder müssen dort bereits als Nutzer angelegt sein
(z. B. über dessen CSV-/XML-Mitgliederimport), damit sie sich einloggen und
Veranstaltungen anlegen können.

WER DARF WAS
  - Vereinsmitglieder (Rolle "wl_mitglied"): Veranstaltungen komplett anlegen,
    bearbeiten und auf der Website veröffentlichen – auch die von anderen
    Mitgliedern (funktioniert wie ein gemeinsamer Team-Kalender). Sie können
    eine Veranstaltung "zur Freigabe einreichen", aber NICHT selbst an externe
    Kanäle (Presse, Social Media, Signal) senden.
  - Vorstand (WordPress-Rollen Administrator/Redakteur): zusätzlich der
    "Jetzt an ausgewählte Kanäle senden"-Button. In der Veranstaltungs-Übersicht
    zeigt die Spalte "Freigabe-Status" auf einen Blick, welche Veranstaltungen
    auf Freigabe warten ("Bereit zur Freigabe").
  - Nur echte Administratoren (Capability manage_options) sehen die
    Einstellungsseite mit den API-Zugangsdaten.

KAMPAGNEN (mehrere zeitversetzte Posts)
Pro Veranstaltung lässt sich in der Box "Kampagne planen" eine Abfolge von
Schritten definieren, z. B.:
  - Tag -14: Ankündigung (Mastodon, Bluesky, Telegram)
  - Tag -3:  Erinnerung (alle Social-Kanäle)
  - Tag -1:  Letzter Aufruf
  - Tag +2:  Rückblick/Dank

Jeder Schritt kann eigene Kanäle und optional einen eigenen Text haben (sonst
wird der normale Kurztext verwendet). "Standard-Vorlage einfügen" trägt die
vier Beispiel-Schritte oben automatisch ein, lässt sich aber frei anpassen.

Mitglieder können die Kampagne planen und speichern, aber erst der Vorstand
kann sie über den Button "Kampagne einplanen" (Sidebar) tatsächlich scharf
schalten. Ab dann übernimmt WordPress' eingebauter Cron (WP-Cron) den
zeitgesteuerten Versand automatisch.

WICHTIG – WP-CRON ZUVERLÄSSIG MACHEN:
WP-Cron wird standardmäßig nur bei Seitenaufrufen ausgelöst. Bei wenig
Traffic (z. B. nachts) verzögert sich der Versand dadurch spürbar. Empfohlen:
  1. In der wp-config.php die Zeile "define('DISABLE_WP_CRON', true);"
     ergänzen (deaktiviert den unzuverlässigen Auto-Trigger).
  2. Im Hosting-Kundenpanel (meist unter "Cron-Jobs" o. ä., ganz ohne
     SSH-Zugriff nutzbar) einen Cron-Job anlegen, der alle 5–15 Minuten
     folgende URL aufruft:
       https://eure-domain.de/wp-cron.php?doing_wp_cron
     Alternativ bieten die meisten Hoster einen "URL per Zeitplan aufrufen"-
     Dienst an, das funktioniert genauso.

ABLAUF BEIM ANLEGEN EINER VERANSTALTUNG
  1. Termin, Ort und Kanäle auswählen.
  2. Die Checkliste zeigt automatisch nur, was für die gewählten Kanäle
     gebraucht wird:
       - EIN gemeinsamer Text für Mastodon, Bluesky, X, Telegram,
         Facebook-Seite, Instagram-Caption, WhatsApp, Signal und die
         Facebook-Veranstaltung. Abweichende Texte je Kanal sind im
         Bereich "Erweitert" möglich, aber nicht Pflicht.
       - EIN Social-Bild für alle Feed-artigen Kanäle (auch Instagram-Feed).
       - Ein EIGENES Bild-Format für die Facebook-Veranstaltung
         (Querformat, da Facebook dort andere Maße braucht als im Feed).
       - Eine Instagram-Story ist rein optional, falls zusätzlich gewünscht.
       - Ein Instagram-REEL ist ebenfalls optional: Video hochladen (empfohlen
         9:16, unter 90 Sekunden), der Rest läuft automatisch – Instagram
         braucht zur Verarbeitung des Videos etwas Zeit, das Plugin prüft den
         Status danach automatisch alle 30 Sekunden im Hintergrund
         (WP-Cron) und veröffentlicht das Reel, sobald es fertig ist. Der
         Status erscheint in der "Los geht's"-Box (Seite neu laden).
       - Presse braucht keine eigenen Pflichtfelder mehr: Titel und der
         normale Website-Text (Beitragsinhalt oben) werden automatisch als
         Pressetext verwendet. Der Presse-Kontakt kommt automatisch aus den
         Einstellungen (einmal hinterlegen, gilt für alle Veranstaltungen),
         lässt sich pro Veranstaltung aber überschreiben.
  3. Im Bereich "Zeitplan" wählen: "Alles gleichzeitig" oder "Gestaffelt"
     (mehrere Posts zu unterschiedlichen Zeitpunkten, z. B. Ankündigung +
     Erinnerung für die textbasierten Kanäle/Gruppen).
  4. Rechts unten auf "🚀 Los geht's" klicken (Vorstand) bzw. "Zur Freigabe
     einreichen" (Mitglieder).

BESCHREIBUNG
Legt einen neuen Beitragstyp "Veranstaltung" an. Pro Veranstaltung könnt ihr
Termin, Ort, Bilder in drei Formaten (Social-Feed, Story, Presse) sowie
eigene Texte je Kanal hinterlegen und per Klick automatisiert verschicken an:

  - Mastodon
  - Bluesky
  - X / Twitter
  - Telegram
  - Facebook-Seite (normaler Post)
  - Instagram (Feed-Post + Story, sofern von Meta freigeschaltet)
  - Signal-Gruppe (über einen externen signal-cli-rest-api-Webhook)
  - Presseverteiler (E-Mail an eine hinterlegte Empfängerliste)

Für Kanäle ohne offene API (Facebook-VERANSTALTUNG als Event-Objekt,
WhatsApp-Gruppe, WhatsApp-Kanal) erzeugt das Plugin eine fertige
Copy-Paste-Vorlage inkl. Bild-Download unter "Copy-Vorlagen" im Beitrag.

INSTALLATION
1. Ordner "jufobleibt-event-publisher" nach /wp-content/plugins/ hochladen
   (oder das ZIP direkt über Plugins -> Installieren -> Plugin hochladen).
2. Plugin aktivieren.
3. Menüpunkt "Jufobleibt Publisher" -> Einstellungen: alle API-Zugänge eintragen.
4. Neue Veranstaltung anlegen, Kanäle auswählen, Texte/Bilder befüllen, speichern.
5. Im Feld "Veröffentlichen" (rechte Spalte) auf "Jetzt an ausgewählte Kanäle
   senden" klicken.

ZUGÄNGE BESORGEN (einmalig pro Kanal)

Mastodon:
  Eurer Account -> Einstellungen -> Entwicklung -> Neue Anwendung anlegen,
  Schreibrechte (write) aktivieren, Access Token kopieren.

Bluesky:
  Einstellungen -> Datenschutz und Sicherheit -> App-Passwörter -> neues
  App-Passwort erstellen. NICHT das normale Account-Passwort verwenden.

Telegram:
  Mit @BotFather in Telegram einen neuen Bot anlegen, Token kopieren.
  Bot als Administrator in euren Kanal einladen. Kanal-ID ist entweder
  @euerkanalname oder eine numerische ID (z. B. über @userinfobot ermittelbar).

Facebook-Seite & Instagram:
  Über developers.facebook.com eine App anlegen, Seite verknüpfen,
  langlebigen Page-Access-Token über den Graph API Explorer erzeugen.
  Für Instagram muss das Instagram-Konto als "Business"-Konto mit der
  Facebook-Seite verknüpft sein.

X / Twitter:
  Über developer.twitter.com ein Projekt/App anlegen, "Read and Write"
  Berechtigungen setzen, API Key/Secret sowie Access Token/Secret im
  User-Context erzeugen (kein Login-Flow nötig, alles statisch nutzbar).

Signal (externer Server nötig, da Standard-Hosting keinen Dauerprozess
erlaubt):
  1. Kleinen VPS oder Raspberry Pi besorgen (Docker-fähig).
  2. Docker-Image "bbernhard/signal-cli-rest-api" starten und die
     Signal-Nummer des Vereins dort registrieren/verifizieren.
  3. Gruppen-ID über den Endpunkt GET /v1/groups/{number} auslesen.
  4. Webhook-URL, Nummer und Gruppen-ID in den Plugin-Einstellungen eintragen.

Presseverteiler:
  E-Mail-Adressen der Redaktionen einfach zeilenweise in den Einstellungen
  eintragen. Für zuverlässige Zustellung empfiehlt sich zusätzlich ein
  SMTP-Plugin wie "WP Mail SMTP", da viele Hoster den PHP-Standardversand
  als Spam markieren lassen.

HINWEISE
- Instagram-Story-Veröffentlichung über die API benötigt teils eine
  erweiterte App-Prüfung durch Meta. Falls das fehlschlägt, bleibt die
  Story als Bild-Download über die Copy-Vorlagen-Seite nutzbar.
- Facebook-Veranstaltungen (Event-Objekte) lassen sich seit 2018 nicht
  mehr automatisiert per API erstellen – bewusst nur als Copy-Vorlage.
- Alle Zugangsdaten werden in der WordPress-Options-Tabelle gespeichert.
  Für höchste Sicherheit Backups/Exports der Datenbank entsprechend schützen.
