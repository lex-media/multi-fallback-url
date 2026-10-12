<?php
/*
Plugin Name: Multi Fallback URL
Plugin URI: https://github.com/lex-media/multi-fallback-url
Description: Leitet bei einem unbekannten Short-Link je nach aufgerufener Domain auf eine eigene Fehlerseite um.
Version: 1.0
Author: Aron Lex
Author URI: https://lex-server.eu
*/

// Kein direkter Aufruf erlaubt
if ( !defined( 'YOURLS_ABSPATH' ) ) {
	die();
}

// Textdomain für Übersetzungen (Dateien: languages/multi-fallback-url-<Locale>.mo).
// Die Originaltexte im Code sind deutsch (Standardsprache), Englisch liegt als Übersetzung bei.
if ( !defined( 'FUM_TEXTDOMAIN' ) ) {
	define( 'FUM_TEXTDOMAIN', 'multi-fallback-url' );
}

/*
 * Hooks
 *
 * Priorität 1 = wird VOR anderen Plugins ausgeführt, die denselben Hook nutzen
 * (z. B. das Original-Plugin "Fallback URL"), die sonst zuerst weiterleiten würden.
 *
 * redirect_keyword_not_found: Keyword sieht gültig aus, ist aber nicht in der DB
 * loader_failed:              Aufruf passt auf kein Keyword-Muster (z. B. /foo/bar)
 */
yourls_add_action( 'redirect_keyword_not_found', 'fum_handle_not_found', 1 );
yourls_add_action( 'loader_failed',              'fum_handle_not_found', 1 );
yourls_add_action( 'plugins_loaded',             'fum_load_textdomain', 1 ); // vor der Seitenregistrierung
yourls_add_action( 'plugins_loaded',             'fum_register_page' );

/**
 * Übersetzungen laden. Die Sprache folgt der Einstellung YOURLS_LANG in der config.php.
 *
 * - Leer oder ohne passende Übersetzungsdatei: deutsche Originaltexte (Standard)
 * - en_US, en_GB usw.: englische Übersetzung (languages/fallback-url-multi-domain-en_US.mo)
 */
function fum_load_textdomain() {
	$locale = (string) yourls_get_locale();
	if ( $locale === '' ) {
		return;
	}

	$dir  = dirname( __FILE__ ) . '/languages/';
	$file = $dir . FUM_TEXTDOMAIN . '-' . $locale . '.mo';

	// Alle englischen Varianten (en_GB, en_AU, ...) nutzen die vorhandene englische Übersetzung
	if ( !is_readable( $file ) && preg_match( '/^en([_-]|$)/i', $locale ) ) {
		$file = $dir . FUM_TEXTDOMAIN . '-en_US.mo';
	}

	if ( is_readable( $file ) ) {
		yourls_load_textdomain( FUM_TEXTDOMAIN, $file );
	}
}

/**
 * Text übersetzen (Kurzform mit Textdomain des Plugins)
 */
function fum__( $text ) {
	return yourls__( $text, FUM_TEXTDOMAIN );
}

/**
 * Hauptfunktion: Ziel für die aufgerufene Domain bestimmen und weiterleiten
 */
function fum_handle_not_found() {

	$host   = fum_get_current_host();
	$target = fum_resolve_target( $host );

	// Diagnosemodus: zeigt an, was erkannt wurde, statt weiterzuleiten
	if ( fum_get_flag( 'fum_debug' ) ) {
		fum_debug_output( $host, $target );
	}

	// Kein Ziel konfiguriert -> YOURLS-Standardverhalten laufen lassen
	if ( $target === '' ) {
		return;
	}

	// Schutz vor Endlosschleifen (Ziel = aktuell aufgerufene Seite)
	if ( fum_is_self_redirect( $target ) ) {
		return;
	}

	// 302 = temporär, damit Browser die Weiterleitung nicht dauerhaft cachen
	yourls_redirect( $target, 302 );
	exit;
}

/**
 * Aktuell aufgerufene Domain ermitteln (normalisiert)
 * Berücksichtigt auch Reverse-Proxys (X-Forwarded-Host)
 */
function fum_get_current_host() {
	$host = '';

	if ( !empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
		$parts = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_HOST'] );
		$host  = trim( $parts[0] );
	} elseif ( !empty( $_SERVER['HTTP_HOST'] ) ) {
		$host = (string) $_SERVER['HTTP_HOST'];
	} elseif ( !empty( $_SERVER['SERVER_NAME'] ) ) {
		$host = (string) $_SERVER['SERVER_NAME'];
	}

	return fum_normalize_host( $host );
}

/**
 * Domain normalisieren: Kleinbuchstaben, ohne Schema, Pfad, Port, Endpunkt und "www."
 */
function fum_normalize_host( $host ) {
	$host = strtolower( trim( (string) $host ) );
	$host = preg_replace( '~^[a-z][a-z0-9+.-]*://~', '', $host ); // Schema entfernen
	$host = preg_replace( '~[/?#].*$~', '', $host );              // Pfad/Query entfernen
	$host = preg_replace( '/:\d+$/', '', $host );                 // Port entfernen
	$host = rtrim( $host, '.' );
	$host = preg_replace( '/^www\./', '', $host );                // "www." entfernen
	return $host;
}

/**
 * Ziel-URL für eine Domain bestimmen.
 * Reihenfolge: exakter Treffer -> übergeordnete Domain (sub.example.com -> example.com) -> Standard-URL
 */
function fum_resolve_target( $host ) {
	$map       = fum_get_map();
	$candidate = $host;

	while ( $candidate !== '' ) {
		if ( isset( $map[ $candidate ] ) ) {
			return $map[ $candidate ];
		}
		$pos = strpos( $candidate, '.' );
		if ( $pos === false ) {
			break;
		}
		$candidate = substr( $candidate, $pos + 1 );
	}

	return fum_normalize_url( (string) yourls_get_option( 'fum_default_url' ) );
}

/**
 * Gespeicherte Zeilen lesen: Liste aus [ 'domain' => ..., 'url' => ..., 'note' => ... ]
 *
 * Neues Format: Option "fum_rows" (JSON).
 * Gibt es diese Option noch nicht, werden die Einträge der alten Option
 * "fum_domain_map" (Zeilen "domain|url") übernommen.
 */
function fum_get_rows() {
	$raw  = yourls_get_option( 'fum_rows' );
	$rows = array();

	if ( $raw !== false && $raw !== null ) {
		$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( is_array( $data ) ) {
			foreach ( $data as $r ) {
				if ( !is_array( $r ) ) {
					continue;
				}
				$rows[] = array(
					'domain' => isset( $r['domain'] ) ? (string) $r['domain'] : '',
					'url'    => isset( $r['url'] )    ? (string) $r['url']    : '',
					'note'   => isset( $r['note'] )   ? (string) $r['note']   : '',
				);
			}
		}
		return $rows;
	}

	// Migration aus dem alten Textformat
	$legacy = (string) yourls_get_option( 'fum_domain_map' );
	foreach ( preg_split( '/\R/', $legacy ) as $line ) {
		$line = trim( $line );
		if ( $line === '' || strpos( $line, '|' ) === false ) {
			continue;
		}
		list( $domain, $url ) = array_map( 'trim', explode( '|', $line, 2 ) );
		$rows[] = array( 'domain' => $domain, 'url' => $url, 'note' => '' );
	}
	return $rows;
}

/**
 * Zuordnung Domain -> URL (nur gültige Zeilen)
 *
 * @return array [ domain => url ]
 */
function fum_get_map() {
	$map = array();

	foreach ( fum_get_rows() as $row ) {
		$domain = fum_normalize_host( $row['domain'] );
		$url    = fum_normalize_url( $row['url'] );

		if ( $domain === '' || $url === '' || isset( $map[ $domain ] ) ) {
			continue;
		}
		$map[ $domain ] = $url;
	}

	return $map;
}

/**
 * URL prüfen und ggf. https:// ergänzen. Gibt '' zurück, wenn ungültig.
 */
function fum_normalize_url( $url ) {
	$url = trim( (string) $url );
	if ( $url === '' ) {
		return '';
	}
	if ( !preg_match( '~^https?://~i', $url ) ) {
		$url = 'https://' . $url;
	}
	$parts = parse_url( $url );
	if ( $parts === false || empty( $parts['host'] ) ) {
		return '';
	}
	return $url;
}

/**
 * Prüft, ob das Ziel dieselbe Domain UND denselben Pfad wie die aktuelle Anfrage hat
 * (würde sonst eine Endlosschleife erzeugen)
 */
function fum_is_self_redirect( $target ) {
	$parts = parse_url( $target );
	if ( empty( $parts['host'] ) ) {
		return false;
	}
	if ( fum_normalize_host( $parts['host'] ) !== fum_get_current_host() ) {
		return false;
	}

	$target_path  = rtrim( isset( $parts['path'] ) ? $parts['path'] : '/', '/' );
	$request_path = rtrim( (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH ), '/' );

	return $target_path === $request_path;
}

/**
 * Einfache Ja/Nein-Option lesen
 */
function fum_get_flag( $name ) {
	return (string) yourls_get_option( $name ) === '1';
}

/**
 * Diagnoseausgabe (nur aktiv, wenn im Admin eingeschaltet)
 */
function fum_debug_output( $host, $target ) {
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );

	echo fum__( 'Fallback URL Multi-Domain – Diagnose' ) . "\n";
	echo "------------------------------------\n";
	echo 'HTTP_HOST: ' . ( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '-' ) . "\n";
	echo 'X-Forwarded-Host: ' . ( isset( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : '-' ) . "\n";
	echo fum__( 'Erkannte Domain' ) . ': ' . $host . "\n";
	echo fum__( 'Ziel-URL' ) . ': ' . ( $target !== '' ? $target : fum__( '(keine – YOURLS-Standardverhalten)' ) ) . "\n";
	echo fum__( 'Gelesene Zuordnungen' ) . ': ' . count( fum_get_map() ) . "\n";
	die();
}

/* ------------------------------------------------------------------
 * Admin-Seite
 * ---------------------------------------------------------------- */

function fum_register_page() {
	yourls_register_plugin_page( 'fum_config', fum__( 'Fallback URL Multi-Domain' ), 'fum_config_page' );
}

/**
 * HTML-Escaping für Ausgaben
 */
function fum_esc( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

/**
 * Eine Tabellenzeile mit den drei Eingabefeldern
 */
function fum_render_row( $domain, $url, $note ) {
	return '<tr>'
		. '<td><input type="text" name="fum_domain[]" value="' . fum_esc( $domain ) . '" placeholder="kurz-a.de" autocomplete="off" /></td>'
		. '<td><input type="text" name="fum_url[]" value="' . fum_esc( $url ) . '" placeholder="https://www.projekt-a.de/fehler" autocomplete="off" /></td>'
		. '<td><input type="text" name="fum_note[]" value="' . fum_esc( $note ) . '" placeholder="' . fum_esc( fum__( 'optional' ) ) . '" maxlength="200" autocomplete="off" /></td>'
		. '<td><button type="button" class="button fum-remove" title="' . fum_esc( fum__( 'Zeile entfernen' ) ) . '">' . fum_esc( fum__( 'Entfernen' ) ) . '</button></td>'
		. '</tr>';
}

function fum_config_page() {

	$saved    = false;
	$warnings = array();

	if ( isset( $_POST['fum_submit'] ) ) {
		yourls_verify_nonce( 'fum_config' ); // bricht bei ungültigem Nonce ab
		$warnings = fum_save_options();
		$saved    = true;
	}

	$rows        = fum_get_rows();
	$default_url = fum_esc( yourls_get_option( 'fum_default_url' ) );
	$debug_on    = fum_get_flag( 'fum_debug' ) ? ' checked="checked"' : '';
	$this_host   = fum_esc( fum_get_current_host() );

	echo <<<'FUM_CSS'
<style>
#fum-rows { width: 100%; max-width: 1100px; border-collapse: collapse; margin: 10px 0; }
#fum-rows th { text-align: left; padding: 6px 8px; border-bottom: 2px solid #ccc; }
#fum-rows td { padding: 4px 8px; vertical-align: middle; }
#fum-rows th:nth-child(1) { width: 24%; }
#fum-rows th:nth-child(2) { width: 40%; }
#fum-rows th:nth-child(3) { width: 26%; }
#fum-rows th:nth-child(4) { width: 10%; }
#fum-rows input[type=text] { width: 100%; box-sizing: border-box; }
.fum-warn { background: #fff8e1; border: 1px solid #f0c36d; padding: 8px 12px; margin: 10px 0; max-width: 1100px; }
.fum-warn ul { margin: 4px 0 0 18px; }
.fum-footer { margin-top: 48px; padding-top: 12px; border-top: 1px solid #ddd; max-width: 1100px; font-size: 12px; color: #777; }
.fum-footer a { color: #555; }
</style>

FUM_CSS;

	echo '<h2>' . fum_esc( fum__( 'Fallback URL Multi-Domain' ) ) . '</h2>';

	if ( $saved ) {
		echo '<div id="feedback_positive" style="display:block;">' . fum_esc( fum__( 'Einstellungen gespeichert.' ) ) . '</div>';
	}

	if ( !empty( $warnings ) ) {
		echo '<div class="fum-warn"><strong>' . fum_esc( fum__( 'Hinweise:' ) ) . '</strong><ul>';
		foreach ( $warnings as $warning ) {
			echo '<li>' . fum_esc( $warning ) . '</li>';
		}
		echo '</ul></div>';
	}

	echo '<p>' . fum_esc( fum__( 'Legt je nach aufgerufener Domain fest, wohin Besucher weitergeleitet werden, wenn ein Short-Link nicht existiert.' ) ) . '</p>';
	echo '<p>' . sprintf( fum_esc( fum__( 'Diese Admin-Seite wurde über die Domain %s aufgerufen.' ) ), '<code>' . $this_host . '</code>' ) . '</p>';

	echo '<form method="post">';
	yourls_nonce_field( 'fum_config' );

	echo '<h3>' . fum_esc( fum__( 'Domains und Weiterleitungen' ) ) . '</h3>';
	echo '<p>' . fum_esc( fum__( 'Trage je Zeile eine Domain und die Seite ein, auf die Besucher bei einem unbekannten Short-Link weitergeleitet werden. Subdomains erben automatisch den Eintrag ihrer Hauptdomain, sofern sie keinen eigenen haben. „www.“ und das Schema (https://) im Domain-Feld werden ignoriert. Leere Zeilen werden beim Speichern entfernt.' ) ) . '</p>';

	echo '<table id="fum-rows"><thead><tr>';
	echo '<th>' . fum_esc( fum__( 'Domain' ) ) . '</th>';
	echo '<th>' . fum_esc( fum__( 'Weiterleitung (Ziel-URL)' ) ) . '</th>';
	echo '<th>' . fum_esc( fum__( 'Notiz' ) ) . '</th>';
	echo '<th></th>';
	echo '</tr></thead><tbody id="fum-rows-body">';
	foreach ( $rows as $row ) {
		echo fum_render_row( $row['domain'], $row['url'], $row['note'] );
	}
	// Immer eine freie Zeile am Ende (funktioniert auch ohne JavaScript)
	echo fum_render_row( '', '', '' );
	echo '</tbody></table>';

	echo '<template id="fum-row-template">' . fum_render_row( '', '', '' ) . '</template>';
	echo '<p><button type="button" id="fum-add-row" class="button">' . fum_esc( fum__( '+ Zeile hinzufügen' ) ) . '</button></p>';

	echo '<h3>' . fum_esc( fum__( 'Standard-Fallback' ) ) . '</h3>';
	echo '<p>' . fum_esc( fum__( 'Wird verwendet, wenn die Domain nicht in der Liste steht. Leer lassen = YOURLS-Standardverhalten.' ) ) . '</p>';
	echo '<p><input type="text" name="fum_default_url" value="' . $default_url . '" size="60" placeholder="https://www.beispiel.de" /></p>';

	echo '<h3>' . fum_esc( fum__( 'Diagnosemodus' ) ) . '</h3>';
	echo '<p><label><input type="checkbox" name="fum_debug" value="1"' . $debug_on . ' /> ';
	echo fum_esc( fum__( 'Statt weiterzuleiten die erkannte Domain und das Ziel als Text anzeigen (nach dem Test wieder ausschalten!)' ) ) . '</label></p>';

	echo '<p><input type="submit" name="fum_submit" value="' . fum_esc( fum__( 'Speichern' ) ) . '" class="button" /></p>';
	echo '</form>';

	// Fußzeile
	echo '<p class="fum-footer"><a href="https://lex-server.eu" target="_blank" rel="noopener">'
		. fum_esc( fum__( 'entwickelt von Lex-Media' ) )
		. '</a> <sub>' . fum_esc( fum__( 'mithilfe von KI' ) ) . '</sub></p>';

	echo <<<'FUM_JS'
<script>
(function () {
	var body = document.getElementById('fum-rows-body');
	var tpl  = document.getElementById('fum-row-template');
	var add  = document.getElementById('fum-add-row');
	if (!body || !tpl || !add) { return; }

	function addRow(focus) {
		body.appendChild(tpl.content.cloneNode(true));
		if (focus) {
			var inputs = body.querySelectorAll('tr:last-child input');
			if (inputs.length) { inputs[0].focus(); }
		}
	}

	add.addEventListener('click', function () { addRow(true); });

	body.addEventListener('click', function (e) {
		var t = e.target;
		if (t && t.classList && t.classList.contains('fum-remove')) {
			var tr = t.closest('tr');
			if (tr) { tr.parentNode.removeChild(tr); }
			if (!body.querySelector('tr')) { addRow(false); }
		}
	});
})();
</script>

FUM_JS;
}

/**
 * Liste von Formularwerten (name[]) als Array von Strings lesen
 */
function fum_post_list( $name ) {
	$out = array();
	if ( isset( $_POST[ $name ] ) && is_array( $_POST[ $name ] ) ) {
		foreach ( array_values( $_POST[ $name ] ) as $value ) {
			$out[] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}
	}
	return $out;
}

/**
 * Formular speichern. Gibt eine Liste von Hinweisen (Strings) zurück.
 */
function fum_save_options() {

	$domains  = fum_post_list( 'fum_domain' );
	$urls     = fum_post_list( 'fum_url' );
	$notes    = fum_post_list( 'fum_note' );
	$warnings = array();
	$rows     = array();
	$seen     = array();

	foreach ( $domains as $i => $raw_domain ) {
		$raw_url = isset( $urls[ $i ] )  ? $urls[ $i ]  : '';
		$note    = isset( $notes[ $i ] ) ? $notes[ $i ] : '';

		// Komplett leere Zeile: still ignorieren
		if ( $raw_domain === '' && $raw_url === '' ) {
			continue;
		}

		$domain = fum_normalize_host( $raw_domain );
		$url    = fum_normalize_url( $raw_url );

		if ( $domain === '' || $url === '' ) {
			$warnings[] = sprintf(
				fum__( 'Zeile %1$d nicht gespeichert: Domain oder gültige Ziel-URL fehlt ("%2$s" / "%3$s").' ),
				$i + 1,
				$raw_domain,
				$raw_url
			);
			continue;
		}

		if ( isset( $seen[ $domain ] ) ) {
			$warnings[] = sprintf( fum__( 'Die Domain "%s" kommt mehrfach vor. Es gilt nur die erste Zeile.' ), $domain );
			continue;
		}
		$seen[ $domain ] = true;

		if ( function_exists( 'mb_substr' ) ) {
			$note = mb_substr( $note, 0, 200, 'UTF-8' );
		} else {
			$note = substr( $note, 0, 200 );
		}

		$rows[] = array( 'domain' => $domain, 'url' => $url, 'note' => $note );
	}

	yourls_update_option( 'fum_rows', json_encode( $rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

	// Altes Textformat parallel mitschreiben (z. B. falls auf Version 2.0 zurückgewechselt wird)
	$legacy = array();
	foreach ( $rows as $row ) {
		$legacy[] = $row['domain'] . '|' . $row['url'];
	}
	yourls_update_option( 'fum_domain_map', implode( "\n", $legacy ) );

	$default = isset( $_POST['fum_default_url'] ) ? fum_normalize_url( (string) $_POST['fum_default_url'] ) : '';
	yourls_update_option( 'fum_default_url', $default );

	yourls_update_option( 'fum_debug', isset( $_POST['fum_debug'] ) ? '1' : '0' );

	return $warnings;
}
