# Anmeldungsportal JO SCGK – Anmeldung Training & Rennen

Einfache Web-App, mit der Eltern ihre Kinder für Trainings und Rennen anmelden und Leiter daraus Gruppen nach Können bilden. Optimiert fürs Handy.

## Was die App kann

**Eltern** (persönlicher Link, kein Passwort, keine App-Installation)
- Kinder sind vorgewählt, Häkchen setzen und eine Anmeldung pro Familie senden; abmelden oder krankmelden
- Fahrgelegenheit anbieten: Anzahl freie Plätze und Abfahrt/Treffpunkt
- Infos zum Event (formatierter Text) und Anhänge wie die Ausschreibung ansehen
- Notfallnummer und Gesundheitshinweise pflegen (sehen nur Leiter)
- Abmelden auf dem Gerät (z.B. geteiltes Handy)

**Leiter**
- Sieht freigeschaltete Events, Anmeldungen und Gruppeneinteilungen
- Trägt sich im **Leiterplan** für die ganze Saison ein («Ich leite» / «Kann nicht»), jederzeit änderbar
- Erfasst vor Ort die Präsenz (nach Gruppen sortiert)
- Gibt Rückmeldungen zu Kindern: neue Stufe vorschlagen, Verletzung, Notiz

**Hauptleiter** (alles vom Leiter, zusätzlich)
- Events aus Vorlagen erstellen, als Entwurf vorbereiten, freischalten, absagen
- Kategorien erstellen und umbenennen, Vorlagen erstellen, umbenennen und bearbeiten
- Infotext mit Formatierung (*fett*, _kursiv_, Aufzählungen, Links) und Anhänge (PDF, Bilder, Word, Excel, max. 10 MB)
- Saison vorbereiten: Termine als Serie aus einer Vorlage erstellen (Entwürfe), damit sich die Leiter früh eintragen können
- Leiterplan: sieht pro Event eingetragene / benötigte Leiter (Bedarf aus Anmeldungen, Gruppen oder fix), ergänzt oder streicht Leiter, «Leiter gesucht» per WhatsApp
- Gruppen automatisch nach Stufe einteilen (eingetragene Leiter werden den Gruppen automatisch zugeteilt), Kinder verschieben, Leiter ändern
- Familien und Kinder erfassen (übersichtliche Liste mit Suche, Details zum Aufklappen), Stufe setzen, Rückmeldungen der Leiter übernehmen
- Excel-Export und -Import für Familien/Kinder und den Leiterplan (mit Vorschau vor dem Übernehmen; ohne PHP-Erweiterung «zip» als CSV)
- WhatsApp-Vorlagen: Einladung (inkl. Infotext), Erinnerung, Gruppenliste, Indoor, Findet statt, Absage

**Alle Leiter** können ihren Leiterplan für die ganze Saison drucken oder als PDF speichern (alle Termine, nur eigene Einsätze oder Gesamtplan).

**Admin** (alles, zusätzlich)
- Team verwalten: Personen hinzufügen, Rollen vergeben, Passwörter zurücksetzen
- Teilnahmestatistik pro Saison und Kategorie mit Export (pro Kind, pro Event, Anwesenheitsliste für J+S)
- Events endgültig löschen

Jede Rolle sieht oben, wer angemeldet ist, und kann sich dort abmelden (wichtig beim Wechsel auf eine andere Person oder Rolle).

Stufen: Blau 1–2, Rot 3–4, Schwarz 5. Rennkategorien (U10–U16) werden aus dem Jahrgang berechnet.

## Installation auf dem Webhosting

Voraussetzung: PHP 8.1 oder neuer mit SQLite (bei Hostpoint, cyon, Infomaniak usw. Standard). Eine MySQL-Datenbank ist nicht nötig.

1. Alle Dateien per FTP in einen Ordner des Webhostings hochladen, z.B. auf eine Subdomain `anmeldung.skiklub-xy.ch`.
2. Prüfen, dass der Ordner `data/` beschreibbar ist (Rechte 750 oder 755). Dort legt die App die Datenbank an.
3. Im Browser `https://…/admin/` öffnen. Beim ersten Mal erscheint «Admin anlegen». Name, Benutzername und Passwort eingeben.
4. Unter **Team** die Hauptleiter und Leiter mit ihrer Rolle erfassen, unter **Familien** die Familien mit Kindern. Danach jeder Familie den Link per WhatsApp senden.
5. Unter **Events** das erste Training aus einer Vorlage erstellen und freischalten.

HTTPS (SSL-Zertifikat) im Hosting-Panel einschalten, das ist bei den meisten Anbietern gratis.

Optional: `config.sample.php` als `config.php` kopieren, um den Klubnamen zu ändern oder die Datenbank ausserhalb des Web-Ordners abzulegen (empfohlen, falls das Hosting das erlaubt).

## Sicherheit und Datenschutz

- Die Datenbank (`data/skiklub.sqlite`) enthält Kinderdaten. Sie ist per `.htaccess` gesperrt und wird nie ins Repository übernommen.
- Bitte regelmässig eine Kopie von `data/skiklub.sqlite` herunterladen (Backup).
- Eltern sehen nur ihre eigenen Kinder. Gesundheitsangaben sehen nur eingeloggte Leiter.
- Wurde ein Familienlink weitergegeben, unter **Familien** einen neuen Link erstellen.
- Keine externen Schriften, Tracker oder Cookies von Drittanbietern.
- Ausgetretene Kinder auf «inaktiv» setzen oder die Familie löschen.

## Update

Neue Version per FTP hochladen und dabei den Ordner `data/` und eine eventuelle `config.php` **nicht** überschreiben. Datenbank-Änderungen macht die App beim nächsten Aufruf selbst.

Beim Update auf die Version mit Rollen werden bestehende Admins zu **Admin** und alle anderen Leiter zu **Hauptleiter**, damit niemand Rechte verliert. Danach unter **Team** die Rollen anpassen.

Der Name der App steht in `config.php` unter `site_name`. Steht dort noch der alte Name «Skiklub Nachwuchs», zeigt die App automatisch «Anmeldungsportal JO SCGK».

Anhänge liegen in `data/uploads/` und sind wie die Datenbank vor direktem Zugriff geschützt. Erlaubt das Hosting kleinere Uploads als 10 MB, gilt dessen Grenze (`upload_max_filesize` in den PHP-Einstellungen).

## Lokal ausprobieren

```
php -S localhost:8000
```
Dann `http://localhost:8000/admin/` öffnen.
