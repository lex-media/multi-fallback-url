# Multi Fallback für YOURLS

Ein [YOURLS](https://yourls.org)-Plugin, dass den Nutzer beim Aufruf eines **unbekannten Short-Links** je nach **aufgerufener Domain** auf eine eigene Fehlerseite weiterleitet.

Das ist praktisch, wenn mehrere Domains auf **eine** YOURLS-Instanz zeigen und Besucher bei einem Tippfehler oder einem abgelaufenen Link nicht bei der YOURLS-Startseite, sondern auf der passenden Website der jeweiligen Domain landen sollen.

## Beispiel

| Aufgerufene URL                 | Weiterleitung nach                |
| ------------------------------- | --------------------------------- |
| `https://kurz-a.de/gibtesnicht` | `https://www.projekt-a.de/fehler` |
| `https://kurz-b.de/gibtesnicht` | `https://www.projekt-b.de`        |
| `https://andere.de/gibtesnicht` | Standard-URL (falls konfiguriert) |

## Funktionen

- Eigene Fallback-URL **pro Domain**
- **Standard-URL** für alle Domains ohne eigenen Eintrag
- **Subdomain-Vererbung:** `go.kurz-a.de` nutzt automatisch den Eintrag von `kurz-a.de`, sofern kein eigener Eintrag existiert
- `www.`, Groß-/Kleinschreibung, Ports und Schema (`https://`) in der Domain-Spalte werden ignoriert
- Greift bei nicht vorhandenen Keywords **und** bei Aufrufen, die zu keinem Keyword-Muster passen (z. B. `/foo/bar`)
- Unterstützt Reverse-Proxys über den `X-Forwarded-Host`-Header
- **Schleifenschutz:** Zeigt das Ziel auf dieselbe Domain und denselben Pfad wie die Anfrage, wird nicht weitergeleitet
- **Diagnosemodus:** zeigt die erkannte Domain und das Ziel als Text an, statt weiterzuleiten
- Übersichtliche Konfiguration im YOURLS-Admin: eine Tabelle mit Eingabefeldern für Domain, Weiterleitung und optionale Notiz, Zeilen per Button hinzufügen und entfernen
- CSRF-Schutz (Nonce) und URL-Prüfung beim Speichern
- **Mehrsprachig:** Standardsprache ist Deutsch, Englisch liegt als Übersetzung bei. Die Sprache folgt der YOURLS-Spracheinstellung, weitere Sprachen lassen sich über Standard-`.po`/`.mo`-Dateien ergänzen
- Ist kein Ziel konfiguriert, läuft das normale YOURLS-Verhalten weiter

## Voraussetzungen

- YOURLS 1.10.6 (aktuelle Version empfohlen)
- PHP 7 oder neuer (getestet mit PHP 8.4)
- Alle Domains müssen per DNS (A-/AAAA- oder CNAME-Eintrag) auf den Server zeigen und vom Webserver (vHost/Alias) an dieselbe YOURLS-Instanz ausgeliefert werden

> **Hinweis:** Eine "URL-Weiterleitung" beim Domain-Registrar reicht nicht aus. Dabei erreicht YOURLS nur die Hauptdomain und kann die ursprünglich aufgerufene Domain nicht erkennen.

## Installation

1. Ordner `multi-fallback-url` nach `user/plugins/` deiner YOURLS-Installation kopieren, sodass die Datei hier liegt:

   ```
   user/plugins/multi-fallback-url/plugin.php
   user/plugins/multi-fallback-url/languages/
   ```

   Der Ordnername darf keine Leerzeichen enthalten, die Datei muss exakt `plugin.php` heißen. Der Ordner `languages` enthält die Übersetzungen und muss mit hochgeladen werden.

2. Im YOURLS-Admin unter **Plugins verwalten** das Plugin **Multi Fallback URL** aktivieren.
3. Falls ein anderes Plugin mit Weiterleitung bei unbekannten Keywords aktiv ist: **deaktivieren**. Da es ansonsten zu probemen kommen kann, da es zuerst weiterleit.

## Konfiguration

Nach der Aktivierung erscheint im Admin der Menüpunkt **Multi Fallback URL**.

### Domains und Weiterleitungen

Die Zuordnungen werden in einer Tabelle gepflegt. Jede Zeile hat drei Felder:

| Feld              | Bedeutung                                                                 | Beispiel                          |
| ----------------- | ------------------------------------------------------------------------- | --------------------------------- |
| **Domain**        | Die Domain, die der Besucher aufgerufen hat                               | `kurz-a.de`                       |
| **Weiterleitung** | Seite, auf die bei einem unbekannten Short-Link weitergeleitet wird       | `https://www.projekt-a.de/fehler` |
| **Notiz**         | Freitext für dich (optional, maximal 200 Zeichen), wird nicht ausgewertet | `Verein, Fehlerseite`             |

- Mit **+ Zeile hinzufügen** legst du eine neue Zeile an, mit **Entfernen** löschst du eine Zeile. Gespeichert wird erst mit **Speichern**.
- Leere Zeilen werden beim Speichern entfernt.
- Fehlt bei der Weiterleitung das Schema, wird `https://` ergänzt.
- Zeilen mit fehlender Domain oder ungültiger URL werden nicht gespeichert, und die Admin-Seite weist darauf hin. Kommt eine Domain mehrfach vor, gilt die erste Zeile.
- Ohne JavaScript steht am Ende der Tabelle immer eine freie Zeile zur Verfügung, die nach dem Speichern erneut erscheint.

### Standard-Fallback

Wird verwendet, wenn die aufgerufene Domain nicht in der Liste steht. Bleibt das Feld leer, verhält sich YOURLS wie gewohnt.

### Reihenfolge der Auswertung

1. Exakter Treffer für die aufgerufene Domain (`go.kurz-c.de`)
2. Übergeordnete Domains (`kurz-c.de`)
3. Standard-URL
4. Kein Ziel: normales YOURLS-Verhalten

Die Weiterleitung erfolgt mit HTTP-Status **302** (temporär), damit Browser sie nicht dauerhaft zwischenspeichern, falls ein Short-Link später doch angelegt wird.

## Sprache und Übersetzungen

**Standardsprache ist Deutsch.** Das Plugin nutzt das Übersetzungssystem von YOURLS. Ohne weitere Einstellung erscheinen Admin-Seite und Diagnoseausgabe auf Deutsch. Für eine andere Sprache setzt du `YOURLS_LANG` in der `config.php` deiner YOURLS-Installation:

```php
define( 'YOURLS_LANG', '' );        // Deutsch (Standard des Plugins)
define( 'YOURLS_LANG', 'de_DE' );   // Deutsch
define( 'YOURLS_LANG', 'en_US' );   // Englisch
```

Mitgeliefert sind:

| Sprache                                              | Datei                                    |
| ---------------------------------------------------- | ---------------------------------------- |
| Deutsch                                              | Originaltexte im Code, keine Datei nötig |
| Englisch (`en_US`, auch für `en_GB` u. a. verwendet) | `languages/multi-fallback-url-en_US.mo`  |

Gibt es für die eingestellte Sprache keine Übersetzung (z. B. `fr_FR`), erscheinen die deutschen Texte. Hinweis: Setzt du `YOURLS_LANG` nicht, bleibt YOURLS selbst englisch, das Plugin zeigt aber deutsche Texte.

### Eine weitere Sprache hinzufügen

Die Originaltexte im Code sind **deutsch**, die Übersetzungsvorlage enthält also deutsche Ausgangstexte.

1. `languages/multi-fallback-url.pot` kopieren und in `multi-fallback-url-<Locale>.po` umbenennen, z. B. `multi-fallback-url-fr_FR.po` (Locale wie bei `YOURLS_LANG`). Als Vorlage für den Aufbau dient `multi-fallback-url-en_US.po`.
2. Die Datei mit einem Übersetzungsprogramm wie [Poedit](https://poedit.net) bearbeiten (`msgstr`-Felder ausfüllen, Kopfzeile `Language:` anpassen).
3. Als `.mo` kompilieren, bei Poedit geschieht das beim Speichern automatisch, auf der Kommandozeile so:

   ```bash
   msgfmt -o languages/multi-fallback-url-fr_FR.mo languages/multi-fallback-url-fr_FR.po
   ```

4. Beide Dateien in den Ordner `languages` legen und `YOURLS_LANG` entsprechend setzen.

Nach Änderungen am Code lässt sich die Vorlage mit `xgettext` neu erzeugen:

```bash
xgettext --language=PHP --from-code=UTF-8 --keyword=fum__ -o languages/multi-fallback-url.pot plugin.php
```

Über Pull Requests mit weiteren Übersetzungen freue ich mich.

## Diagnosemodus

Wenn Weiterleitungen nicht wie erwartet funktionieren:

1. In der Plugin-Konfiguration den **Diagnosemodus** aktivieren und speichern.
2. Auf der betroffenen Domain einen nicht existierenden Short-Link aufrufen, z. B. `https://kurz-a.de/gibtesnicht`.
3. Statt der Weiterleitung erscheint eine Textausgabe mit `HTTP_HOST`, `X-Forwarded-Host`, der erkannten Domain, dem Ziel und der Anzahl gelesener Zuordnungen.
4. Diagnosemodus danach wieder **ausschalten**.

## Fehlersuche

| Problem                                                      | Mögliche Ursache                                                                                                                                     |
| ------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| Alle Domains landen bei derselben URL                        | Ein anderes Fallback-Plugin ist noch aktiv, oder die Domain wird nicht korrekt erkannt (Registrar-Weiterleitung, Proxy, Subdomain nicht eingetragen) |
| Weiterleitung auf die YOURLS-Startseite oder das Admin-Login | Ziel zeigt auf dieselbe Domain (Schleifenschutz greift), oder es ist weder ein Eintrag noch eine Standard-URL vorhanden                              |
| Domain wird falsch erkannt                                   | Reverse-Proxy setzt `Host` oder `X-Forwarded-Host` anders; Wert im Diagnosemodus prüfen                                                              |
| Aufruf der Root-URL (`domain.tld/`) wird nicht umgeleitet    | Die Root-URL wird von YOURLS selbst behandelt und ist nicht Teil dieses Plugins                                                                      |

**Tipp:** Zeigt ein Ziel auf dieselbe Domain (z. B. `kurz-a.de|https://kurz-a.de/fehler.html`), sollte dort eine statische Datei oder eine vom Webserver ausgelieferte Seite liegen, die nicht wieder von YOURLS verarbeitet wird.

## Credits

Multi-Domain-Version entwickelt von [Lex-Media](https://lex-server.eu) mithilfe von KI.

## Lizenz

Dieses Projekt steht unter der **GNU General Public License v3.0**. Den vollständigen Lizenztext findest du in der Datei [`LICENSE`](LICENSE) bzw. unter <https://www.gnu.org/licenses/gpl-3.0.html>.
