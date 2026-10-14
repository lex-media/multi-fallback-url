# Multi Fallback for YOURLS

### **Deutsche Version:**  Die deutsche Dokumentation finden unter: [`README-de.md`](README-de.md).


A [YOURLS](https://yourls.org) plugin that redirects visitors who access an **unknown short URL** to a custom error page based on the **domain they accessed**.

This is useful when multiple domains point to a **single** YOURLS instance and you want visitors who mistype a URL or follow an expired link to land on the appropriate website for that domain instead of the YOURLS homepage.

## Example

| Requested URL                          | Redirects to                          |
| -------------------------------------- | ------------------------------------- |
| `https://short-a.example/doesnotexist` | `https://www.project-a.example/error` |
| `https://short-b.example/doesnotexist` | `https://www.project-b.example`       |
| `https://other.example/doesnotexist`   | Default URL (if configured)           |

## Features

- Custom fallback URL **for each domain**
- **Default URL** for all domains without their own entry
- **Subdomain inheritance:** `go.short-a.example` automatically uses the entry for `short-a.example` if no specific entry exists
- `www.`, letter case, ports, and the scheme (`https://`) in the domain field are ignored
- Handles both missing keywords **and** requests that do not match any keyword pattern (e.g. `/foo/bar`)
- Supports reverse proxies via the `X-Forwarded-Host` header
- **Redirect loop protection:** no redirect occurs if the target has the same domain and path as the request
- **Diagnostic mode:** displays the detected domain and target as text instead of redirecting
- Clear configuration in the YOURLS admin area: a table with fields for domain, redirect URL, and an optional note; rows can be added and removed using buttons
- CSRF protection (nonce) and URL validation when saving
- **Multilingual:** German is the default language, and an English translation is included. The plugin follows the YOURLS language setting; additional languages can be added using standard `.po`/`.mo` files
- If no target is configured, normal YOURLS behavior continues

## Requirements

- YOURLS 1.10.6 (latest version recommended)
- PHP 7 or later (tested with PHP 8.4)
- All domains must point to the server via DNS (A/AAAA or CNAME record), and the web server (vHost/alias) must serve the same YOURLS instance for each domain

> **Note:** A “URL forwarding” setting at your domain registrar is not sufficient. In that setup, YOURLS only receives the main domain and cannot identify the domain originally requested.

## Installation

1. Copy the `multi-fallback-url` folder into `user/plugins/` in your YOURLS installation, so that the files are located here:

   ```
   user/plugins/multi-fallback-url/plugin.php
   user/plugins/multi-fallback-url/languages/
   ```

   The folder name must not contain spaces, and the file must be named exactly `plugin.php`. The `languages` folder contains the translations and must also be uploaded.

2. In the YOURLS admin area, go to **Manage Plugins** and activate **Multi Fallback URL**.
3. If another plugin that redirects unknown keywords is active, **deactivate it**. Otherwise, conflicts may occur because that plugin may redirect first.

## Configuration

After activation, the **Multi Fallback URL** menu item appears in the admin area.

### Domains and redirects

Manage the mappings in a table. Each row has three fields:

| Field        | Description                                                                                   | Example                               |
| ------------ | --------------------------------------------------------------------------------------------- | ------------------------------------- |
| **Domain**   | The domain accessed by the visitor                                                            | `short-a.example`                     |
| **Redirect** | The page to redirect to when a short URL is unknown                                           | `https://www.project-a.example/error` |
| **Note**     | Optional free text for your own reference (up to 200 characters); not processed by the plugin | `Club, error page`                    |

- Click **+ Add row** to add a row, or **Remove** to delete one. Changes are only saved when you click **Save**.
- Empty rows are removed when saving.
- If the redirect URL has no scheme, `https://` is added.
- Rows with a missing domain or invalid URL are not saved, and the admin page displays a notice. If a domain appears more than once, the first row takes precedence.
- Without JavaScript, an empty row is always available at the bottom of the table and reappears after saving.

### Default fallback

Used when the requested domain is not listed. If this field is left empty, YOURLS behaves as usual.

### Lookup order

1. Exact match for the requested domain (e.g. `go.short-c.example`)
2. Parent domains (e.g. `short-c.example`)
3. Default URL
4. No target: normal YOURLS behavior

Redirects use HTTP status **302** (temporary), so browsers do not cache them permanently in case the short URL is created later.

## Language and translations

**The default language is German.** The plugin uses the YOURLS translation system. Without any additional configuration, the admin page and diagnostic output are displayed in German. To use another language, set `YOURLS_LANG` in your YOURLS installation's `config.php`:

```php
define( 'YOURLS_LANG', '' );        // German (plugin default)
define( 'YOURLS_LANG', 'de_DE' );   // German
define( 'YOURLS_LANG', 'en_US' );   // English
```

Included translations:

| Language                                            | File                                                    |
| --------------------------------------------------- | ------------------------------------------------------- |
| German                                              | Original strings in the code; no separate file required |
| English (`en_US`, also used for `en_GB` and others) | `languages/multi-fallback-url-en_US.mo`                 |

If no translation is available for the configured language (e.g. `fr_FR`), the German strings are displayed. Note: if you do not set `YOURLS_LANG`, YOURLS itself remains in English, but the plugin displays German text.

### Adding another language

The original strings in the code are **German**, so the translation template contains German source text.

1. Copy `languages/multi-fallback-url.pot` and rename it to `multi-fallback-url-<Locale>.po`, e.g. `multi-fallback-url-fr_FR.po` (use the same locale format as `YOURLS_LANG`). Use `multi-fallback-url-en_US.po` as a structural reference.
2. Edit the file using a translation program such as [Poedit](https://poedit.net) (fill in the `msgstr` fields and update the `Language:` header).
3. Compile it into a `.mo` file. Poedit does this automatically when saving; on the command line, use:

   ```bash
   msgfmt -o languages/multi-fallback-url-fr_FR.mo languages/multi-fallback-url-fr_FR.po
   ```

4. Place both files in the `languages` folder and set `YOURLS_LANG` accordingly.

After changing the code, regenerate the template with `xgettext`:

```bash
xgettext --language=PHP --from-code=UTF-8 --keyword=fum__ -o languages/multi-fallback-url.pot plugin.php
```

Pull requests with additional translations are welcome.

## Diagnostic mode

If redirects do not work as expected:

1. Enable **Diagnostic mode** in the plugin configuration and save.
2. Visit a nonexistent short URL on the affected domain, e.g. `https://short-a.example/doesnotexist`.
3. Instead of redirecting, a text output appears showing `HTTP_HOST`, `X-Forwarded-Host`, the detected domain, the target, and the number of mappings read.
4. **Disable** diagnostic mode afterwards.

## Troubleshooting

| Problem                                         | Possible cause                                                                                                                                   |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| All domains redirect to the same URL            | Another fallback plugin is still active, or the domain is not being detected correctly (registrar forwarding, proxy, or missing subdomain entry) |
| Redirects to the YOURLS homepage or admin login | The target points to the same domain (loop protection is triggered), or neither a domain entry nor a default URL is configured                   |
| Domain is detected incorrectly                  | The reverse proxy sets `Host` or `X-Forwarded-Host` differently; check the value in diagnostic mode                                              |
| The root URL (`domain.tld/`) is not redirected  | YOURLS handles the root URL itself; it is not covered by this plugin                                                                             |

>**Tip:** If a target points to the same domain (e.g. `short-a.example|https://short-a.example/error.html`), the destination should be a static file or a page served directly by the web server that is not processed by YOURLS again.

## Credits

The multi-domain version was developed by [Lex-Media](https://lex-server.eu) with the help of AI/SI.

## License

This project is licensed under the **GNU General Public License v3.0**. The full license text is available in the [`LICENSE`](LICENSE) file or at <https://www.gnu.org/licenses/gpl-3.0.html>.
