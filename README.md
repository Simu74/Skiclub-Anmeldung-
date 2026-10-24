# Skiklub Nachwuchs – Anmeldung Training & Rennen

Einfache Web-App, mit der Eltern ihre Kinder für Trainings und Rennen anmelden und Leiter daraus Gruppen nach Können bilden. Optimiert fürs Handy.

## Was die App kann

**Eltern** (persönlicher Link, kein Passwort, keine App-Installation)
- Kinder sind vorgewählt, Häkchen setzen und eine Anmeldung pro Familie senden
- «Ich kann fahren» mit Anzahl freier Plätze
- Abmelden oder krankmelden, Bemerkung an die Leiter
- Notfallnummer und Gesundheitshinweise pflegen (sehen nur Leiter)

**Leiter** (Login mit Benutzername und Passwort)
- Events aus Vorlagen erstellen (Off Snow Sa, On Snow Sa/Mi, Rennen), als Entwurf vorbereiten und freischalten, absagen
- Mindeststufe pro Event (z.B. On Snow im November nur ab Stufe Rot)
- Anmeldungen, Abmeldungen, Fahrer und Familien ohne Antwort sehen
- Gruppen automatisch nach Stufe einteilen, alle gleich gross; Kinder verschieben, Leiter zuweisen
- WhatsApp-Vorlagen: Einladung, Erinnerung, Gruppenliste, Indoor, Findet statt, Absage. Gruppenlisten direkt an die Gruppenleiter (mit Notfallnummern)
- Familien und Kinder erfassen, Stufe setzen, persönlichen Link per WhatsApp senden
- Rennkategorie (U10–U16) wird aus dem Jahrgang berechnet

Stufen: Blau 1–2, Rot 3–4, Schwarz 5.

## Installation auf dem Webhosting

Voraussetzung: PHP 8.1 oder neuer mit SQLite (bei Hostpoint, cyon, Infomaniak usw. Standard). Eine MySQL-Datenbank ist nicht nötig.

1. Alle Dateien per FTP in einen Ordner des Webhostings hochladen, z.B. auf eine Subdomain `anmeldung.skiklub-xy.ch`.
2. Prüfen, dass der Ordner `data/` beschreibbar ist (Rechte 750 oder 755). Dort legt die App die Datenbank an.
3. Im Browser `https://…/admin/` öffnen. Beim ersten Mal erscheint «Admin anlegen». Name, Benutzername und Passwort eingeben.
4. Unter **Leiter** die anderen Leiter erfassen, unter **Familien** die Familien mit Kindern. Danach jeder Familie den Link per WhatsApp senden.
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

## Lokal ausprobieren

```
php -S localhost:8000
```
Dann `http://localhost:8000/admin/` öffnen.
