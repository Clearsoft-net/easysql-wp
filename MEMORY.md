# MEMORY.md — Lições do projeto easysql-wp

## Pacotes do SDK: sub-pacotes direto, não o meta-pacote

**Data:** 2026-10-15

O `composer.json` requer `clearsoft/easysql-{client,common,connectors,schema-generation}: ^4.0` em vez do meta `clearsoft/easysql-sdk` — o plugin usa cada pacote no código e o meta apenas *sugere* o `connectors`. As constraints internas desalinhadas da 4.0.0 (meta exigindo `^3.0`, connectors usando `ConnectionUrl` do common 4.x) foram corrigidas na **4.0.1** (todos os sub-pacotes foram para 4.0.1, só composer.json/CI mudaram) e agora são ajustadas automaticamente no release via `scripts/update-version.php`.

## `DB_HOST` com socket Unix é suportado desde o SDK 4.1

**Data:** 2026-10-15

O SDK 4.1 (`clearsoft/easysql-connectors`, PR easysql-php#28) adicionou `?string $socket` ao `MySQL\ConnectionConfig` (e ao Postgres), espelhando o `socket` do irmão TS: socket tem precedência sobre host/port e o DSN vira `mysql:unix_socket=...`. O plugin passa o caminho em `LocalDatabase::connection_config()` (constraint `^4.1`), com teste de integração real via socket (`EASYSQL_TEST_DB_SOCKET`, exposto pelo `make db-up`). Antes: `host=/caminho` não funciona no mysqlnd (getaddrinfo) e não havia como injetar PDO — por isso a rejeição foi removida.

## E2E manual: API fake + wp-env

**Data:** 2026-10-15

Sem a API real, `php -S 0.0.0.0:8010 tests/Support/HealthServerRouter.php` responde `/v1/*` com defaults e grava os corpos em `/tmp/easysql_test_capture.json` (útil para inspecionar o schema enviado). Subir com `make start EASYSQL_ENDPOINT=http://host.containers.internal:8010`. Porta 8000 do host pode estar ocupada por outros projetos — cheque antes.
