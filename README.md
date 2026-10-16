<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/Clearsoft-net/easysql-brand/main/logo/01-dark-horizontal.svg">
    <source media="(prefers-color-scheme: light)" srcset="https://raw.githubusercontent.com/Clearsoft-net/easysql-brand/main/logo/02-light-horizontal.svg">
    <img alt="EasySQL Logo" src="https://raw.githubusercontent.com/Clearsoft-net/easysql-brand/main/logo/01-dark-horizontal.svg">
  </picture>
</p>

<h1 align="center">EasySQL WordPress Plugin</h1>

<p align="center">
  <strong>Official WordPress Plugin for <a href="https://easysql.net">EasySQL</a> · A <a href="https://clearsoft.net">Clearsoft</a> Product</strong>
</p>

<p align="center">
  <a href="https://github.com/Clearsoft-net/easysql-wp/actions"><img src="https://img.shields.io/github/actions/workflow/status/Clearsoft-net/easysql-wp/update-sdk.yml?branch=main&style=flat-square" alt="CI Status"></a>
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-%3E%3D%205.6-blue?style=flat-square&logo=wordpress" alt="WordPress Version"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-%3E%3D%208.2-777BB4?style=flat-square&logo=php" alt="PHP Version"></a>
  <a href="https://github.com/Clearsoft-net/easysql-wp/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square" alt="License"></a>
  <a href="https://easysql.net"><img src="https://img.shields.io/badge/Product-easysql.net-F97316?style=flat-square" alt="Website"></a>
  <a href="https://clearsoft.net"><img src="https://img.shields.io/badge/Company-clearsoft.net-0F2B3D?style=flat-square" alt="Company"></a>
</p>

---

Bring the power of **natural-language database queries** directly into your WordPress admin dashboard. Ask questions about WooCommerce orders, user registrations, custom post types, or analytics in plain English (or Portuguese, Spanish, etc.). EasySQL generates safe SQL, and the plugin runs it only against the site's own WordPress database.

## Key Features

- 💬 **Ask in Natural Language**: Type questions like *"How many WooCommerce orders were completed last week?"* or *"Top 5 most viewed posts this month"*.
- 📊 **Interactive Data Visualizations**: Automatic bar charts, line graphs, and pie charts rendered with Chart.js.
- 🔍 **SQL Transparency**: Inspect the exact SQL query generated before or alongside the results.
- 🛡️ **Safe Local Execution**: Only the WordPress schema is synced; SELECT queries execute locally through the official PHP SDK's MySQL connector (PDO). Database credentials and result rows never go to EasySQL.
- ⚡ **History & Submenu Navigation**: Quickly access past questions, copy generated SQL, or adjust API settings.

---

## Requirements

- **WordPress**: 5.6 or later
- **PHP**: 8.2 or later with the `pdo_mysql` extension
- **EasySQL Account & API Key**: Get one at [easysql.net](https://easysql.net)

---

## Installation

### Option 1: Manual Installation (Release ZIP)

1. Download the latest release from the [Releases](https://github.com/Clearsoft-net/easysql-wp/releases) page.
2. Unzip and upload the `easysql-wp` folder to your `/wp-content/plugins/` directory.
3. Activate **EasySQL** from the **Plugins** screen in your WordPress admin dashboard.
4. Go to **EasySQL → Settings** and enter your API Key.

### Option 2: Composer (Bedrock / Roots)

If you manage your WordPress site with Composer:

```bash
composer require clearsoft/easysql-wp
```

---

## Configuration

Navigate to **EasySQL → Settings** in your WordPress admin panel:

| Field | Description |
|---|---|
| **API Key** | Your EasySQL API key from [easysql.net](https://easysql.net). |
| **Endpoint URL** | Read-only by default (`https://api.easysql.net`). |
| **Timeout** | Request timeout in seconds (1–300, default: 30). |

To configure the endpoint programmatically via `wp-config.php`:

```php
define( 'EASYSQL_ENDPOINT', 'https://api.easysql.net' );
define( 'EASYSQL_API_KEY', 'your-api-key-here' ); // Optional fallback
```

Use the **Test Connection** button on the Settings screen to verify your API credentials. The plugin sends only WordPress schema metadata to EasySQL; database credentials and query result rows remain on your site.

---

## REST API Reference

The plugin provides authenticated REST endpoints (requires `manage_options` capability and a valid `X-WP-Nonce` header):

| Method | Route | Description |
|---|---|---|
| `POST` | `/wp-json/easysql/v1/query` | Generate SQL and execute it locally against the WordPress database. |
| `GET` | `/wp-json/easysql/v1/queries` | Cursor-paginated history for the WordPress database connection. |
| `GET` | `/wp-json/easysql/v1/connection` | Get or register this site's schema-only connection. |
| `POST` | `/wp-json/easysql/v1/connection/sync` | Push the current WordPress schema to EasySQL. |
| `GET` | `/wp-json/easysql/v1/test-connection` | Ping the EasySQL API to verify credentials. |

### Example Request

```bash
curl -X POST https://example.com/wp-json/easysql/v1/query \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{
    "connection_id": "00000000-0000-4000-8000-000000000000",
    "question": "How many users registered in the last 30 days?"
  }'
```

---

## Development & Contributing

Contributions are welcome! Please review our **[Contributing Guidelines](https://github.com/Clearsoft-net/easysql-wp/blob/main/CONTRIBUTING.md)** for setup instructions and code standards.

```bash
git clone https://github.com/Clearsoft-net/easysql-wp.git
cd easysql-wp
composer install
composer test       # run PHPUnit tests
composer lint       # check WordPress Coding Standards (WPCS)
composer lint:fix   # auto-fix style issues
```

Local WordPress via `wp-env`: `make start` (installs `pdo_mysql` inside the container) and `make stop`. Integration tests against a real MySQL: `make db-up`, `make test-integration`, `make db-down`.

---

## License

This project is open source and licensed under the [MIT License](./LICENSE).

Maintained by **[Clearsoft](https://clearsoft.net)**.
