# AGENTS.md

> Contexto para agentes de IA trabalhando neste repositório.

## Visão geral

Plugin WordPress para consultar em linguagem natural **somente o banco de dados WordPress do próprio site**. A API EasySQL recebe perguntas e o schema, gera SQL seguro; a execução acontece localmente pelo conector MySQL do SDK (`clearsoft/easysql-connectors`, PDO). Credenciais do banco e linhas resultantes nunca são enviadas nem armazenadas na API.

- Versão do plugin: `1.0.0` (`EASYSQL_VERSION` em `easysql-wp.php`)
- PHP mínimo: 8.2, com a extensão `pdo_mysql`
- WordPress mínimo: 5.6
- SDK via Packagist (v4.1.0): `clearsoft/easysql-client` (HTTP), `clearsoft/easysql-common` (validador read-only), `clearsoft/easysql-schema-generation` (payload canônico) e `clearsoft/easysql-connectors` (introspecção/execução local MySQL via PDO; `^4.1` pelo suporte a socket)
- O plugin requer os sub-pacotes direto, não o meta-pacote `clearsoft/easysql-sdk`: cada um é usado no código e o meta não inclui o `connectors` (apenas sugere)
- A API base é `https://api.easysql.net`; o SDK acrescenta `/v1` às rotas.

## Estrutura

```
easysql-wp/
├── easysql-wp.php
├── composer.json / composer.lock
├── AGENTS.md
├── assets/
│   ├── ask-v4.js                  # Consulta, resultados e histórico recente
│   ├── history.js                 # Histórico cursor-paginado
│   └── ...
├── scripts/
│   ├── enable-rewrites.sh         # Habilita pretty permalinks no wp-env
│   └── enable-pdo-mysql.sh        # Compila pdo_mysql no container do wp-env
├── src/
│   ├── LocalDatabase.php           # Conexão com o banco do site via SDK connectors (PDO)
│   ├── QueryService.php           # Geração SQL, validação e execução local
│   ├── ConnectorService.php       # Criação/cache/sync do schema da conexão WordPress
│   ├── Plugin.php                 # Bootstrap/admin e injeção do connection_id
│   ├── Api/Router.php
│   ├── Api/QueryController.php    # REST WP para query/histórico/teste
│   ├── Api/ConnectorController.php# REST WP para connection/sync
│   └── Admin/                     # Ask, histórico e settings
└── tests/
    ├── bootstrap.php
    ├── Support/                   # Servidor HTTP real + FakeConnector para os testes
    ├── Unit/                      # Testes dos serviços (banco fake)
    └── Integration/               # Connector real contra MySQL (pulado sem servidor)
```

## Fluxo e segurança

- `LocalDatabase` constrói o conector MySQL do SDK a partir das constantes `DB_HOST/DB_USER/DB_PASSWORD/DB_NAME`, sem escrever nada em disco; `parse_db_host()` espelha a semântica de `wpdb::parse_db_host()` (`host`, `host:porta`, `[ipv6]:porta`, socket) e sockets Unix são repassados ao parâmetro `socket` do `ConnectionConfig`.
- `ConnectorService::get_or_create()` registra uma conexão por site em `/v1/connections` com `type: wp`, nome identificador do site e somente schema introspectado localmente; cache local em `easysql_connection` (migra a opção antiga `easysql_connector`).
- `ConnectorService` introspecta via `ConnectorInterface::introspect()` e usa `SchemaGenerator` do SDK para gerar o payload canônico + `db_version` (`$wpdb->db_version()`) — mesmo formato que o easysql-cli envia.
- `ConnectorService::sync()` atualiza o schema via `POST /v1/connections/{id}/sync`.
- `QueryService::query()` chama `POST /v1/queries` com `{connection_id, question}`, valida SQL via `SqlValidator`, executa localmente com `ConnectorInterface::execute()` (prepared statements, SELECT-only) e retorna as linhas ao admin do próprio WordPress. A resposta/linhas nunca são reenviadas à API.
- Execução read-only; SQL cross-database qualificado é rejeitado, exceto quando referencia explicitamente o banco configurado no WordPress.
- Nenhum host, usuário, senha ou configuração de conexão do banco é enviado à API. Dados resultantes ficam apenas na resposta da requisição REST e no navegador do usuário autorizado.
- Limitação conhecida: sem a extensão `pdo_mysql` o plugin falha com erro claro. `DB_HOST` com socket Unix explícito (`host:/caminho`) é suportado desde o SDK 4.1.
- A API guarda o schema da conexão e histórico de pergunta/SQL; histórico é cursor-paginado via `GET /v1/queries?connection_id=&cursor=&limit=`.
- REST do plugin sob `easysql/v1`; permissões administrativas exigem `manage_options` e nonce WP.

## Endpoints do plugin

| Método | Rota | Body/query | Resposta |
|---|---|---|---|
| `POST` | `/wp-json/easysql/v1/query` | `{connection_id?, question}` | SQL, dados executados localmente e resumo |
| `GET` | `/wp-json/easysql/v1/queries` | `connection_id?, cursor?, limit?` | `{items, next_cursor}` |
| `GET` | `/wp-json/easysql/v1/test-connection` | — | `{success, message}` |
| `GET` | `/wp-json/easysql/v1/connection` | — | Conexão WordPress ou `{error}` |
| `POST` | `/wp-json/easysql/v1/connection/sync` | — | `{success, connection?}` ou `{success, message}` |

## Configuração e desenvolvimento

- Settings em `wp_options.easysql_settings`: API key e timeout. API key pode vir de `EASYSQL_API_KEY`.
- Endpoint configurável em `EASYSQL_ENDPOINT` (base sem `/v1`; default `https://api.easysql.net`).
- Composer: `composer install`; lint WPCS: `composer lint` (o repositório ainda tem violações pré-existentes de naming/docblocks); testes: `composer test`.
- Testes de integração (connector real contra MySQL em `127.0.0.1:3307`, com socket exposto em `/tmp/easysql-wp-mysql-sock/mysqld.sock`): `make db-up` e `make test-integration`; `make db-down` derruba o banco de teste. Sem servidor, os testes de integração são pulados.
- WP local via `make start`/`make stop`; `make start` habilita rewrites e compila `pdo_mysql` no container (a imagem `wordpress:php*` só traz mysqli).
- Fluxo manual sem a API real: `php -S 0.0.0.0:8010 tests/Support/HealthServerRouter.php` serve respostas canned (`/v1/health`, `/v1/connections*`, `/v1/queries`) e grava os corpos recebidos em `/tmp/easysql_test_capture.json`; suba o WP com `make start EASYSQL_ENDPOINT=http://host.containers.internal:8010`.
- Não adicione comentários ao código sem solicitação.
