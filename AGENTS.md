# AGENTS.md

> Contexto para agentes de IA (opencode, Claude Code, Cursor, etc.) trabalhando neste repositório.
> Última sincronização: ver git log.

## Visão geral

Plugin WordPress que integra o serviço externo **EasySQL** ao WP, permitindo fazer perguntas em linguagem natural sobre o banco de dados WordPress. É uma fina camada sobre o [SDK PHP do EasySQL](https://github.com/Clearsoft-net/easysql-sdk-php) (`easysql/sdk: ^1.1.0`, instalado via repositório `path: ../easysql-sdk-php/` com `symlink: false`, ou fallback `vcs: GitHub`).

- **Site oficial:** https://easysql.net
- **Versão do plugin:** `0.1.0` (constante `EASYSQL_VERSION` em `easysql-wp.php`)
- **Namespace PSR-4:** `EasySQL\` → `src/`
- **PHP mínimo:** 7.4
- **WP mínimo:** 5.6
- **Licença:** MIT

## Stack e dependências

- PHP 7.4+ com `declare(strict_types=1)` em todos os arquivos `src/`
- WordPress 5.6+ (usa `WP_REST_Controller`, Settings API, `add_options_page`)
- Composer com autoload PSR-4
- SDK externo: `easysql/sdk: 0.1.0` (namespace `Clearsoft\EasySQL\SDK`)
- Guzzle (dependência transitiva do SDK, acessado via `getHttpClient()->request(...)`)

## Estrutura

```
easysql-wp/
├── easysql-wp.php             # Bootstrap: autoload, constantes, container, hooks de lifecycle
├── uninstall.php              # Cleanup ao desinstalar
├── composer.json              # PSR-4 + repositórios (path local + VCS GitHub)
├── README.md                  # Docs para usuários finais
├── AGENTS.md                  # Contexto para agentes de IA
├── PLAN.md                    # Roadmap MVP
├── LICENSE                    # MIT
├── Makefile                   # Atalhos de dev (lint, wp-env, etc.)
├── .wp-env.json               # Config do @wordpress/env para dev local
├── assets/
│   ├── admin.css              # Estilos da tela de settings
│   └── admin.js               # Test Connection + Connector status + Sync
├── languages/                 # Text-domain "easysql" (vazio por enquanto)
└── src/
    ├── Plugin.php             # Container principal: boot, activate, deactivate, hooks
    ├── QueryService.php       # Wrapper WP-aware do SDK: config, client, query, test_connection
    ├── ConnectorService.php   # Gerencia o connector "wp" (cria, sync, cache)
    ├── Api/
    │   ├── Router.php         # Registra rotas no hook `rest_api_init`
    │   ├── QueryController.php# Endpoints REST: /query, /test-connection
    │   └── ConnectorController.php # Endpoints REST: /connector, /connector/sync
    └── Admin/
        └── SettingsPage.php   # Página em Settings → EasySQL (API key, endpoint, timeout, connector status)
```

## Pontos de entrada críticos

| Arquivo | Responsabilidade |
|---|---|
| `easysql-wp.php` | Define constantes (`EASYSQL_VERSION`, `EASYSQL_FILE`, `EASYSQL_DIR`, `EASYSQL_URL`), expõe `easysql_container()` (singleton estático), registra `plugins_loaded`, `register_activation_hook`, `register_deactivation_hook`. |
| `src/Plugin.php` | `boot()` chama `init_services()` + `register_hooks()` e, se `is_admin()`, `init_admin()`. Instancia `Api\Router` passando `QueryService` e `ConnectorService`. |
| `src/QueryService.php` | Cacheia config (`get_option('easysql_settings')`) e instância do `Client` do SDK. Mapeia config do plugin → chaves do SDK: `api_key` → `access_token` (Bearer), `endpoint` → `base_url`. Usa `getHttpClient()->request(...)` direto para ter acesso ao status HTTP. `test_connection()` faz `GET /v1/health`; `query($connector_id, $question)` faz `POST /v1/queries`. Captura `\Throwable` em todos os métodos. |
| `src/ConnectorService.php` | Gerencia o ciclo de vida do connector "wp". `get_or_create()` verifica cache em `wp_options`, procura connector existente na API ou cria um novo com as credenciais do `wp-config.php`. `sync()` chama `POST /v1/connectors/{id}/sync`. |
| `src/Api/QueryController.php` | 2 endpoints sob namespace `easysql/v1`: `POST /query` (aceita `{connector_id, question}`), `GET /test-connection`. Todos exigem `manage_options`. |
| `src/Api/ConnectorController.php` | 2 endpoints sob namespace `easysql/v1`: `GET /connector` (info do connector "wp"), `POST /connector/sync` (sincronizar schema). Todos exigem `manage_options`. |
| `src/Admin/SettingsPage.php` | Settings API (`register_setting` + `add_settings_field`). Sanitiza API key. Timeout clampado a 1–300s. Endpoint exibido como campo `disabled` (read-only) — via constante `EASYSQL_ENDPOINT`. Exibe status do connector "wp" e botão "Sync Schema Now". |

## Endpoints REST (todos `permission_callback = manage_options`)

> Caminho assume prefixo padrão `wp-json` e pretty permalinks ativos.

| Método | Rota | Body | Resposta |
|---|---|---|---|
| `POST` | `/wp-json/easysql/v1/query` | `{connector_id, question}` | `QueryResponse` ou `{error}` |
| `GET` | `/wp-json/easysql/v1/test-connection` | — | `{success, message}` |
| `GET` | `/wp-json/easysql/v1/connector` | — | `ConnectorResponse` ou `{error}` |
| `POST` | `/wp-json/easysql/v1/connector/sync` | — | `{success}` ou `{success, message}` |

O modelo é **NL→SQL** (a API recebe uma pergunta em linguagem natural e devolve SQL gerado + resposta). O plugin usa apenas o connector "wp", que representa o próprio banco WordPress.

## Convenções e padrões

- **WordPress Coding Standards** (WPCS) — rodar `composer lint` (PHPCS).
- **PSR-4** com namespace `EasySQL\`.
- **String quotes:** WPCS (aspas duplas por padrão) — respeitar o que já está no arquivo ao editar.
- **i18n:** text-domain `easysql` em todos os `__()` / `esc_html__()` / `_e()`.
- **Sanitização:** sempre usar `sanitize_text_field`, `esc_url_raw`, `absint`. Endpoints só HTTPS.
- **Capabilities:** `manage_options` para qualquer ação administrativa ou REST.
- **Nonces:** `wp_localize_script` em `src/Plugin.php` injeta `wpApiSettings.{root,nonce}`. admin.js usa `X-WP-Nonce`.
- **Erros do SDK:** capturados como `\Throwable` nos services e devolvidos como `{error: string}` nos responses REST (status 400).
- **Não** criar novas tabelas no banco — settings vão em `wp_options` com a chave `easysql_settings`. Connector "wp" cacheado em `easysql_connector`.

## Workflow de desenvolvimento

```bash
composer install        # instala SDK + deps
composer lint           # PHPCS (WordPress Coding Standards)
composer test           # PHPUnit
make start              # Sobe wp-env (localhost:8888)
make stop               # Para wp-env
```

Local WP via `@wordpress/env` (config em `.wp-env.json`).

## Onde mexer para tarefas comuns

- **Adicionar novo endpoint REST:** criar controller em `src/Api/`, registrar em `src/Api/Router.php`.
- **Adicionar campo nas settings:** `src/Admin/SettingsPage.php` (`register_settings`, `field_*`, `sanitize`).
- **Mudar extração de erros:** `QueryService::extract_error_from_body()`.
- **Mudar lógica do connector "wp":** `src/ConnectorService.php`.
- **Mudar permissões REST:** `QueryController::admin_permission_check()`.
- **Adicionar texto/asset no admin:** `src/Plugin.php::register_admin_assets()` + `assets/admin.{css,js}`.
- **Mexer no lifecycle (activate/deactivate):** `src/Plugin.php::activate()` / `deactivate()`.

## Pendências conhecidas

- **WP-CLI** marcado como "planned" no README — não implementado.
- **Páginas admin** (Ask, History, Dashboard, Billing) — pendentes (ver PLAN.md).

## Testes

- PHPUnit 13 (dev dep) + stubs de WP em `tests/bootstrap.php`.
- **Zero mocks/fakes.** Os testes sobem um PHP built-in server real numa porta livre, definem `EASYSQL_ENDPOINT` apontando pra ele, e o SDK real faz chamadas HTTP reais.
- Cobertura atual: `tests/Unit/QueryServiceTest.php` — 9 casos para `test_connection()` e `query()`.
- Comando: `composer test` ou `vendor/bin/phpunit --testdox`.
- SDK referenciado em `composer.json` com `repositories` apontando para `path: ../easysql-sdk-php/` (irmão) com fallback VCS.
- `admin.js` ainda depende de jQuery — avaliar dispensar em iteração futura.
- API key armazenada em texto puro no `wp_options` (padrão WP).

## Como manter este arquivo atualizado

Atualizar sempre que:
- Entrar/sair arquivo em `src/`.
- Adicionar/remover endpoint REST.
- Adicionar/remover campo de setting.
- Mudar padrão de normalização de resultados.
- Alterar capability padrão.
- Adicionar dependência nova (composer).
- Mudar comando de dev (Makefile, scripts composer).
