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
