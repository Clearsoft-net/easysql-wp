# EasySQL WordPress Plugin

Integrate [EasySQL](https://easysql.net) into WordPress — run queries, manage data, and build reports seamlessly.

## Requirements

- PHP 7.4 or later
- WordPress 5.6 or later
- [EasySQL SDK for PHP](https://github.com/Clearsoft-net/easysql-sdk-php)

## Installation

1. Download the plugin and place it in `/wp-content/plugins/easysql-wp/`.
2. Run `composer install` inside the plugin directory.
3. Activate **EasySQL** from the WordPress **Plugins** screen.
4. Go to **Settings → EasySQL** and enter your API credentials.

## Usage

### Settings

Navigate to **Settings → EasySQL** to configure:

| Field         | Description                                      |
|---------------|--------------------------------------------------|
| API Key       | Your EasySQL API key.                            |
| Endpoint URL  | Read-only. The EasySQL API endpoint URL.         |
| Timeout       | Request timeout in seconds (1–300, default: 30). |

The **Endpoint URL** field is shown disabled and cannot be edited from this screen. To change it, set the `EASYSQL_ENDPOINT` constant in `wp-config.php` (or `easysql-wp.php`); defaults to `https://api.easysql.net/v1`.

```php
define( 'EASYSQL_ENDPOINT', 'https://api.easysql.net/v1' );
```

Use the **Test Connection** button to verify that the credentials work.

### REST API

The plugin exposes the following endpoints (all require `manage_options` capability). Paths below assume the default WordPress REST prefix `wp-json` and **pretty permalinks enabled**; if you've customized the prefix via the `rest_url_prefix` filter or are running with permalinks set to "Plain", adjust the URL accordingly (e.g. `?rest_route=/easysql/v1/query`):

| Method | Route                              | Description                |
|--------|------------------------------------|----------------------------|
| `POST` | `/wp-json/easysql/v1/query`        | Ask the API a question against a connector. Returns a `QueryResponse`. |
| `GET`  | `/wp-json/easysql/v1/test-connection` | Ping the EasySQL API.   |

The plugin is a thin wrapper over the [EasySQL SDK](https://github.com/Clearsoft-net/easysql-sdk-php). Queries are **natural-language → SQL**: you send a `connector_id` and a `question`, the API generates (and runs) the SQL and returns the answer, the generated SQL, and the result data. This is the model the underlying API supports — raw SQL execution is not part of the API surface.

#### Example: asking a question

```bash
curl -X POST https://example.com/wp-json/easysql/v1/query \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"connector_id": "f0e8...-uuid", "question": "How many users signed up last month?"}'
```

Response (200, trimmed):

```json
{
  "id": "qry_abc",
  "question": "How many users signed up last month?",
  "sql_generated": "SELECT COUNT(*) AS n FROM users WHERE created_at >= NOW() - INTERVAL '1 month'",
  "answer": "There were 128 new users last month.",
  "chart_config": null,
  "error": null,
  "result_data": [{"n": 128}],
  "created_at": "2025-01-15T10:32:00Z"
}
```

On error, the response is `{"error": "<message>"}` with HTTP 400 (e.g. invalid `connector_id`, auth failure, or a server-side query error returned by the API).

### WP-CLI (planned)

WP-CLI commands will be available in a future release.

## Development

```bash
git clone https://github.com/Clearsoft-net/easysql-wp.git
cd easysql-wp
composer install
```

Run PHPCS to check code style:

```bash
composer lint
```

### Coding standards

This plugin follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/) with PSR-4 autoloading for the `EasySQL\` namespace.

## Changelog

### 0.1.0

- Initial release.
- Admin settings page (API key, read-only endpoint, timeout).
- REST endpoint for natural-language queries (NL → SQL via the EasySQL API).
- Connection test utility.

## License

MIT — see [LICENSE](LICENSE).
