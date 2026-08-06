# MEMORY.md

> Memória persistente do projeto — fatos autoritativos e aprendizados que devem sobreviver entre sessões.
> Atualizar sempre que uma decisão durável for tomada ou um fato novo for confirmado pelo usuário.

## Identidade do projeto

- **Nome do plugin:** EasySQL (slug: `easysql`, text-domain: `easysql`)
- **Site oficial:** https://easysql.net
- **Organização no GitHub:** `Clearsoft-net` (não `clearsoft`, não `easysql`)
- **Repositório do SDK PHP:** https://github.com/Clearsoft-net/easysql-sdk-php
- **Namespace PHP do SDK (instalado):** `Clearsoft\EasySQL\SDK` (com `Client` como entrypoint)
- **Namespace do plugin:** `EasySQL\` (PSR-4 → `src/`)
- **Licença:** MIT
- **Versão atual:** 0.1.0

## Domínios e URLs

| Recurso | URL | Status |
|---|---|---|
| Marketing | `https://easysql.net` | **Autoritativo** — confirmado pelo usuário. NÃO usar `.io`. |
| API endpoint (default) | `https://api.easysql.net/v1` | Default do plugin, confirmado em uso. |
| Documentação | — | Não documentada publicamente ainda |

> Correção ativa: o usuário já apontou que `easysql.io` é incorreto como site oficial. Tratar `.net` como verdade e `.io` como erro de domínio antigo, exceto para a API que pode estar em host separado.

## Decisões técnicas registradas

- **Settings armazenadas** em `wp_options` com chave `easysql_settings`, shape `{api_key, timeout}`. Não usar post meta nem tabela custom.
- **Endpoint** é constante-only: `EASYSQL_ENDPOINT` em `wp-config.php`/`easysql-wp.php` (default `https://api.easysql.net/v1`). É **exibido** na UI de settings como campo `disabled` (read-only) para referência, mas **não** é gravado — a constante é a única fonte de verdade. O `QueryService::get_config()` ignora qualquer `endpoint` armazenado no `wp_options` por retrocompatibilidade.
- **API Key** tem fallback para a constante `EASYSQL_API_KEY` (mesmo padrão do endpoint). O `QueryService::get_config()` usa `EASYSQL_API_KEY` quando a `easysql_settings['api_key']` está vazia — isso permite o ambiente local subir já funcional sem digitar a key. A `SettingsPage` também exibe o valor da constante enquanto não houver key salva.
- **Endpoint aceito** apenas `https://` (convenção operacional — `esc_url_raw` com protocolo `https` foi removido junto com o campo).
- **Timeout clampado** entre 1 e 300 segundos, default 30.
- **Capability padrão** para todos os endpoints REST e UI: `manage_options`.
- **Erros do SDK** mapeados para `{error: string}` com HTTP 400 — nunca vazar stacktrace. `QueryService` captura `\Throwable` (não só `GuzzleException`) para que `RuntimeException` de "API key ausente" **e** `Error` de método inexistente (`Call to undefined method Clearsoft\…\Client::ping()`) também retornem erro útil, em vez de virar 500.
- **Mapeamento config plugin → SDK real:** `api_key` (settings) → `access_token` (SDK, Bearer); `endpoint` (constante) → `base_url` (SDK). O `QueryService::client()` faz essa ponte.
- **SDK não tem `ping`/`query`/`transaction`.** O plugin usa `getHttpClient()->request(...)` direto (o transporte Guzzle do SDK) em vez dos helpers gerados. `test_connection` faz `GET /v1/health`; `query` faz `POST /v1/queries` com `{connector_id, question}`. Sem `transaction` — não há atomic multi-statement na API real.
- **Modelo da API é NL→SQL:** o plugin não envia SQL cru, envia uma pergunta em linguagem natural + `connector_id`. A API devolve `QueryResponse` com SQL gerado, resposta em texto, dados de resultado e timestamp. `query()` do plugin devolve esse shape cru (sem normalização).
- **JS admin** usa `wpApiSettings` injetado via `wp_localize_script` próprio — **não** depender do handle `wp-api`/`wp-api-request` do core (foi removido no WP 5.5+ e silenciosamente quebra o endpoint).
- **Cache em memória** do `Client` e da config no `QueryService` (escopo de request).
- **Singleton** exposto como função `easysql_container()` retornando `\EasySQL\Plugin`.
- **WP-CLI** não implementado ainda — placeholder no README.
- **Testes** rodam via `composer test` (PHPUnit 13). Cobertura atual: `QueryService::test_connection()` em 5 cenários (sucesso, fallback de msg, `GuzzleException`, `RuntimeException` de API key ausente, `Error` de método inexistente).

## Convenções editoriais

- Idioma dos comentários no código: **inglês** (PHPDoc, section descriptions).
- Idioma da UI (labels, mensagens) e i18n strings: **inglês** por padrão, com text-domain `easysql`.
- Idioma do README: **inglês**.
- Idioma do AGENTS.md / MEMORY.md: **português (PT-BR)** para comunicação com o time; atualizar se o time decidir diferente.
- Aspas em strings PHP: respeitar o estilo WPCS do arquivo sendo editado (misto `'` e `"` no código atual, sem unificar).
- Comentários PHPDoc em todas as classes e métodos públicos.

## Armadilhas e coisas para NÃO fazer

- **Não** trocar `easysql.net` por `easysql.io` em lugar nenhum — `.io` é erro antigo.
- **Não** mudar o `repositories` do `composer.json` para apontar para `clearsoft/...` ou `easysql/...` — a org no GitHub é `Clearsoft-net`.
- **Não** retornar exceções cruas nos endpoints REST — sempre normalizar para `{error: string}`.
- **Não** aceitar endpoints `http://` — convenção operacional, mesmo sem o sanitize atual bloqueando (campo é read-only).
- **Não** criar tabela custom no banco — settings em `wp_options` é a decisão.
- **Não** adicionar dependência nova sem atualizar AGENTS.md e este MEMORY.md.
- **Não** commitar secrets/API keys, mesmo de exemplo.

## Aprendizados por sessão

_(a ser preenchido a cada sessão relevante)_

## Execução local de queries (opção A) — connector "wp" schema-only

**Data:** 2026-08-05

O backend **gera o SQL mas não executa** para connectors schema-only (`wp`): `POST /v1/queries` devolve `needs_local_execution: true` + `sql_generated`. O plugin (`QueryService::query`) detecta isso, executa o SQL no `$wpdb` local (`run_local_sql`), e submete o resultado em `POST /v1/queries/{id}/answer` para gerar a `answer` + chart.

- Backend: `app/services/query.py` → `ask_query` (só executa se `config_encrypted` existe) + `complete_query` (gera answer p/ resultado local). Router: `POST /v1/queries/{id}/answer`.
- Plugin: `QueryService::query` + `execute_locally_and_answer` + `run_local_sql` (usa `$wpdb->get_results($sql, ARRAY_A)`).

## php -S de teste trava após ~70 requests (não é keep-alive)

**Data:** 2026-08-05

O servidor PHP built-in (`php -S`) single-threaded para de responder depois de ~77 conexões rápidas — independente do cliente (Guzzle ou `file_get_contents`/curl) e até do tipo de request (health incluído). Sintoma: timeout "Operation timed out after ... with 0 bytes received" no último teste da suíte.

Não é keep-alive, não é pool de conexões, não é FD (limite alto). `PHP_CLI_SERVER_WORKERS` não funciona bem via `proc_open` aqui. **Solução**: reiniciar o servidor na **mesma porta** entre classes de teste (`HealthServer::restart()` + `ApiServer::clearState()` faz restart). A porta precisa ser fixa porque `EASYSQL_ENDPOINT` é constante. Se adicionar muitos testes/requests, considere re-partir a suíte em mais classes para reiniciar o servidor com mais frequência.

## Connector "wp" órfão → "Connector not found" após restart do backend

**Data:** 2026-08-05

Sintoma: após reiniciar o backend com DB novo, a pergunta no WP falha com "Connector not found". Causa: o `easysql_connector` cacheado em `wp_options` guarda um id que não existe mais no backend (o connector foi perdido no restart), e `get_or_create()` retornava o cache **sem validar**.

Fix (em `ConnectorService::get_or_create()`): quando há cache, faz `GET /v1/connectors/{id}` para validar. Se 2xx, usa o cache; se não existir, descarta e recria. Em erro de rede, mantém o cache (não derruba offline).

Testes: `tests/Unit/ConnectorServiceTest.php` → `get_or_create_recovers_when_cached_connector_is_stale` e `get_or_create_reuses_valid_cached_connector`. O router de teste ganhou a rota `GET /v1/connectors/{id}`.

**Importante:** connector `wp` é schema-only (sem credenciais). O fluxo de query (`app/services/query.py::ask_query`) faz `decrypt(connector.config_encrypted)` → quebra com `None`. Query em connector `wp` ainda não executa no backend — problema de arquitetura separado (backend espera conectar num banco real via config).

## wp-env: REST /wp-json/ 404 → "Could not load connector"

**Data:** 2026-08-05

Sintoma: o admin mostra "Could not load connector" mesmo com backend saudável e connector criado (GET `/easysql/v1/connector` → 200 via `rest_do_request`/CLI, mas 404 no browser). Causa raiz: a imagem `wordpress:php*` do Docker tem `<Directory /var/www/> AllowOverride None`, então o Apache **ignora o `.htaccess`** e o pretty permalink (`/wp-json/`) vira 404 — o AJAX do admin.js falha.

Fixes aplicados (duráveis):
- `wp-plugin/scripts/enable-rewrites.sh` — idempotente: habilita `AllowOverride All` + grava `.htaccess` padrão + `service apache2 reload`.
- `wp-plugin/Makefile` target `start` — roda o script **depois** do `wp-env start` (o container é reconstruído a cada start, então precisa reaplicar).

Diagnóstico rápido: `curl http://localhost:<porta>/wp-json/` → se 404, é routing do Apache, não o plugin. O `wp-json/` base deve responder 200 (ou 302/401), nunca 404.

## Descompasso histórico plugin ↔ backend (connector "wp" por schema)

**Data:** 2026-08-05

O plugin sempre foi projetado para criar o connector "wp" **enviando o schema local** (`{name, type, schema}`), sem credenciais de banco — ver `ConnectorService::create_from_wp_config()`. Mas o backend exigia `config` obrigatório e rodava `test_connection`, devolvendo **422 "Field required"**. Isso fazia `get_or_create()` lançar exceção → `GET /easysql/v1/connector` devolver 400 → o admin JS mostrar **"Could not load connector."**.

**Resolvido no backend** (`app/schemas/connector.py` + `app/services/connector.py`): `type=wp` agora aceita `config: None` + `schema` opcional; persiste o schema sem testar conexão. `config_encrypted` virou `nullable`. O schema é gravado em `schema_cache` no create (sem precisar de sync).

Testes: `backend/tests/test_connectors.py::test_create_wp_connector_with_schema_and_no_config` e `wp-plugin/tests/Unit/ConnectorServiceTest.php` (`get_or_create_creates_wp_connector_from_schema`, `get_or_create_returns_error_message_when_create_rejected`).

Lição: quando a UI do plugin mostra uma mensagem genérica de erro de connector, verifique primeiro o **contrato HTTP** do backend para o mesmo endpoint — o descompasso está mais provavelmente aí do que no JS.
