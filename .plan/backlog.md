# EasySQL WP Plugin — Backlog

> Backlog tático do plugin WordPress, organizado em batches por afinidade.
> Visão geral do produto em `~/Documents/Clearsoft/EasySQL/sprint-tracker.md`.

**Versão atual:** 0.1.0
**Última revisão:** 2026-06-17

---

## Batch A — Housekeeping (paralelizável)

Tarefas rápidas, sem dependência entre si, tocam arquivos diferentes.

- ~~**A1. Commits pendentes**~~ ✅ Feito (`59fc9c1`)
- [ ] **A2. Fix PHPCS lint** — [#1](https://github.com/Clearsoft-net/easysql-wp/issues/1)
- [ ] **A3. .editorconfig** — [#2](https://github.com/Clearsoft-net/easysql-wp/issues/2)
- [ ] **A4. Gerar .pot de tradução** — [#3](https://github.com/Clearsoft-net/easysql-wp/issues/3)

---

## Batch B — Documentação e WP.org (paralelizável)

- [ ] **B1. Atualizar README.md** — [#4](https://github.com/Clearsoft-net/easysql-wp/issues/4)
- [ ] **B2. readme.txt WP.org** — [#5](https://github.com/Clearsoft-net/easysql-wp/issues/5)
- [ ] **B3. Assets de submissão WP.org** — [#6](https://github.com/Clearsoft-net/easysql-wp/issues/6)

---

## Batch C — Shortcode + Cache (C2 depende de C1)

- [ ] **C1. Shortcode `[easysql-query]`** — [#7](https://github.com/Clearsoft-net/easysql-wp/issues/7)
- [ ] **C2. Query Cache** — [#8](https://github.com/Clearsoft-net/easysql-wp/issues/8)

---

## Batch D — Admin Notices (paralelizável)

- [ ] **D1. Admin Notices Contextuais** — [#9](https://github.com/Clearsoft-net/easysql-wp/issues/9)
- [ ] **D2. Setup Onboarding** — [#10](https://github.com/Clearsoft-net/easysql-wp/issues/10)

---

## Batch E — Admin UX Features (sequencial ideal)

- [ ] **E1. Saved Queries / Query Library** — [#11](https://github.com/Clearsoft-net/easysql-wp/issues/11)
- [ ] **E2. Admin Bar Quick Ask** — [#12](https://github.com/Clearsoft-net/easysql-wp/issues/12)
- [ ] **E3. Dashboard Widget** — [#13](https://github.com/Clearsoft-net/easysql-wp/issues/13)
- [ ] **E4. Schema Viewer** — [#14](https://github.com/Clearsoft-net/easysql-wp/issues/14)

---

## Batch F — Ask Page Improvements (sequencial obrigatório)

- [ ] **F1. Sugestões de Perguntas** — [#15](https://github.com/Clearsoft-net/easysql-wp/issues/15)
- [ ] **F2. Autocomplete na Pergunta** — [#16](https://github.com/Clearsoft-net/easysql-wp/issues/16)
- [ ] **F3. Select Connector no Ask** — [#17](https://github.com/Clearsoft-net/easysql-wp/issues/17)

---

## Batch G — History & Export (mesmo JS)

- [ ] **G1. Export CSV** — [#18](https://github.com/Clearsoft-net/easysql-wp/issues/18)
- [ ] **G2. History search & filter** — [#19](https://github.com/Clearsoft-net/easysql-wp/issues/19)

---

## Batch H — Infra e Qualidade (paralelizável)

- [ ] **H1. Expandir test coverage** — [#20](https://github.com/Clearsoft-net/easysql-wp/issues/20)
- [ ] **H2. CI/CD GitHub Actions** — [#21](https://github.com/Clearsoft-net/easysql-wp/issues/21)
- [ ] **H3. Sanitizar API key** — [#22](https://github.com/Clearsoft-net/easysql-wp/issues/22)
- [ ] **H4. Remover jQuery** — [#23](https://github.com/Clearsoft-net/easysql-wp/issues/23)

---

## Batch I — Features Complexas (independentes)

- [ ] **I1. Schema Change Detection** — [#24](https://github.com/Clearsoft-net/easysql-wp/issues/24)
- [ ] **I2. Ask About This — Contextual Hooks** — [#25](https://github.com/Clearsoft-net/easysql-wp/issues/25)
- [ ] **I3. Email Reports (wp_cron)** — [#26](https://github.com/Clearsoft-net/easysql-wp/issues/26)
- [ ] **I4. Public Share** — [#27](https://github.com/Clearsoft-net/easysql-wp/issues/27)
- [ ] **I5. WP-CLI commands** — [#28](https://github.com/Clearsoft-net/easysql-wp/issues/28)
- [ ] **I6. Dashboard admin page** — [#29](https://github.com/Clearsoft-net/easysql-wp/issues/29)

---

## ✅ Já implementado (v0.1.0)

- Bootstrap, autoload, constantes
- Plugin container com lifecycle (activate, deactivate, boot)
- QueryService (config, test_connection, query, list_queries)
- ConnectorService (get_or_create connector "wp", sync, reset)
- REST API: POST /query, GET /queries, GET /test-connection, GET /connector, POST /connector/sync
- Settings page (API key, timeout, connector status, test, sync)
- Ask page (textarea, resultados, Chart.js)
- History page (tabela paginada, ask again)
- 9 testes unitários (QueryService)
- assets/admin.css, admin.js, ask.js, history.js
- composer.json + vendor (SDK easysql/sdk ^1.1.0)
- .wp-env.json (wp-env pra dev local)
- AGENTS.md, README.md, MEMORY.md
