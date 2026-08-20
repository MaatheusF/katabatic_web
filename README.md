# Katabatic Web

Portal web da Katabatic — companhia aérea virtual (MSFS 2024 + VATSIM) com
operação de carga, transporte de pessoal e pesquisa meteorológica em área
extrema. Duas bases em hemisférios opostos: **PAFA** (Fairbanks, Alasca) e
**SCCI** (Punta Arenas, Chile).

Este repositório é a implementação real (Symfony) do que antes existia só
como mockups em HTML autocontido. O pacote original de mockups e o contrato
de telemetria do ACARS ficam arquivados em `docs/` como referência enquanto
convertemos tela por tela.

## Stack

- **PHP 8.2+** / **Symfony 7.2**
- **Twig** para templates
- **Postgres 16 + PostGIS** (via Docker) — ainda não conectado ao app; entra
  quando o primeiro mockup que depende de dados reais (Portal/Logbook) for
  implementado
- Sem build step de frontend por enquanto: CSS/JS servidos como arquivos
  estáticos em `public/assets/`, para não depender de Node/npm

## Como rodar

Pré-requisitos: PHP 8.2+, Composer, Docker (opcional, só quando o banco
entrar em uso).

```bash
composer install
docker compose up -d          # sobe o Postgres+PostGIS (quando necessário)
symfony serve                 # ou: php -S localhost:8000 -t public
```

Acesse `http://localhost:8000`.

> Este scaffold foi escrito manualmente (sem `composer create-project`)
> porque o ambiente onde ele foi criado não tinha acesso à internet liberado
> para o Packagist. Rode `composer install` na sua máquina para baixar as
> dependências de verdade — a estrutura de arquivos segue exatamente o que o
> `symfony/skeleton` + `webapp` pack gerariam.

## Estrutura

```
katabatic_web/
├── bin/console              CLI do Symfony
├── config/                  bundles, rotas, packages
├── docs/                    mockups originais + contrato ACARS (referência)
├── public/
│   ├── index.php             front controller
│   └── assets/
│       ├── css/base.css      tokens de design + componentes compartilhados
│       ├── css/pages/        CSS específico de cada tela
│       ├── js/theme-toggle.js  tema claro/escuro (compartilhado)
│       └── js/pages/         JS específico de cada tela
├── src/
│   ├── Controller/
│   ├── Entity/               (a partir do schema do banco)
│   └── Kernel.php
├── templates/
│   ├── base.html.twig        layout com topbar/footer compartilhados
│   └── home/                 uma pasta por tela
└── docker-compose.yml         Postgres + PostGIS
```

## Progresso (mockup → tela real)

- [x] `katabatic-home.html` → `/` (Home institucional)
- [ ] `katabatic-portal.html` → área logada (Logbook, Frota, Bases)
- [ ] `katabatic-voo.html` → relatório de voo
- [ ] `katabatic-novo-voo.html` → registro de voo
- [ ] `katabatic-nova-aeronave.html` → cadastro de aeronave

## Próximos passos (da fase de mockup)

Ver `docs/mockups-originais/README-mockups-original.md` para o histórico
completo de decisões de produto. Resumo do que vem depois das telas:

1. Schema do banco (Postgres + PostGIS + TimescaleDB)
2. Fork do cliente ACARS usando `docs/payload-telemetria-acars.md`
3. Servidor de ingestão dos endpoints do contrato ACARS
4. Importador dos dados do `katabatic_capture.py`
5. Job de recorte GRIB
6. Ligar todas as telas ao backend real
