# Guia de desenvolvimento

Fundação técnica do Barber Booking: monorepo com frontend (React + Vite), backend (Laravel) e MySQL, orquestrados via Docker Compose atrás de um proxy que serve tudo na mesma origem.

## Versões

| Componente | Versão | Observação |
| --- | --- | --- |
| Node.js | 24 (Active LTS) | usado no container do frontend e no CI |
| Vite | 8.x | React 19, template `react` (JavaScript) |
| PHP | 8.4 | Laravel 13 requer PHP 8.3+; o `composer.lock` gerado exige 8.4+ |
| Laravel | 13.x | framework do backend |
| MySQL | 8.4 (LTS) | suporte estendido até 2032 |
| Mailpit | latest | captura e-mails locais (SMTP + interface web) |

## Pré-requisitos

- Docker e Docker Compose (plugin `docker compose`).
- Nenhuma instalação local de Node/PHP/MySQL é necessária para rodar via Docker. Para rodar o backend ou o frontend fora do Docker, use as versões da tabela acima.

## Estrutura do monorepo

```
backend/    Laravel (API)
frontend/   React + Vite (SPA)
docker/     configuração do proxy (nginx)
docs/       documentação do projeto
```

## Instalação

1. Copie os arquivos de ambiente:

   ```sh
   cp .env.example .env
   cp backend/.env.example backend/.env
   ```

2. Gere a chave da aplicação Laravel (necessária antes do primeiro `up`; sem ela o Laravel não consegue criptografar sessões/cookies):

   ```sh
   # com PHP instalado localmente
   cd backend && php artisan key:generate

   # alternativa, sem PHP local: suba o backend primeiro e gere a chave dentro do container
   docker compose up -d backend
   docker compose exec backend php artisan key:generate
   ```

3. Ajuste `.env` (raiz) e `backend/.env` se necessário. Os valores padrão já funcionam entre si — as credenciais de MySQL do `.env` da raiz são injetadas no container do backend via `docker-compose.yml`, sobrepondo os valores de `backend/.env`.

## Subindo o ambiente

```sh
docker compose up -d --build
```

Serviços:

| Serviço | Acesso | Descrição |
| --- | --- | --- |
| `proxy` | http://localhost:8080 | ponto único de entrada: `/` → frontend, `/api/*` e `/up` → backend |
| `mysql` | localhost:3307 (host) | banco de dados; porta interna 3306 |
| `mailpit` | http://localhost:8025 | interface web dos e-mails capturados |

Verifique o status dos containers:

```sh
docker compose ps
```

Todos devem aparecer como `healthy` (frontend e backend podem levar alguns segundos até o healthcheck passar).

## Migrations

```sh
docker compose exec backend php artisan migrate
```

## Testes

Frontend (Vitest):

```sh
cd frontend
npm install
npm run test
npm run build   # verificação de build de produção
```

Backend (PHPUnit, dentro do container, usando o MySQL real do Compose):

```sh
docker compose exec backend php artisan test
```

> Isso roda contra o mesmo banco usado em desenvolvimento (`barber_booking`). Aceitável nesta etapa, sem dados reais ainda; o CI usa um banco MySQL efêmero e isolado por execução.

### Isolamento de conexão com o MySQL

`backend/tests/Feature/MySqlConnectionIsolationTest.php` abre duas conexões PDO distintas para o mesmo MySQL e comprova que uma escrita não commitada em uma conexão não é visível na outra, e passa a ser visível após o commit. Esse teste é pulado automaticamente sob SQLite (configuração padrão do `phpunit.xml`) e só é exercido quando `DB_CONNECTION=mysql`, como acontece dentro do container do backend e no CI.

Resultado confirmado nesta entrega: **passou** contra o MySQL 8.4 real (ver seção de verificação abaixo). Testes de concorrência de reservas (duas transações disputando o mesmo horário) ficam para a etapa de disponibilidade, quando essa funcionalidade existir.

## Mailpit

Qualquer e-mail enviado pelo backend (`MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`) fica disponível em http://localhost:8025, sem sair da rede local.

## Solução de problemas comuns

| Sintoma | Causa provável | Solução |
| --- | --- | --- |
| Backend fica `unhealthy` | `APP_KEY` vazio no `backend/.env` | gere a chave (passo 2 da instalação) e reinicie: `docker compose restart backend` |
| `/api/health` retorna 503 | MySQL ainda subindo ou credenciais divergentes entre `.env` (raiz) e `backend/.env` | aguarde o healthcheck do `mysql` ficar `healthy`; confira se `MYSQL_*` no `.env` raiz bate com o esperado |
| Frontend não atualiza (HMR) ao editar código | variável `VITE_DEV_SERVER_PROXIED` não aplicada | confirme que está acessando via `http://localhost:8080` (porta do proxy) e não diretamente por `5173` |
| Porta 8080/3307/8025 já em uso | outro serviço local ocupando a porta | ajuste `APP_PORT`, `MYSQL_HOST_PORT` ou `MAILPIT_WEB_PORT` no `.env` da raiz |
| `composer install` falha por versão do PHP | imagem Docker desatualizada em cache | `docker compose build --no-cache backend` |

## Limitações desta etapa

- Sem cadastros de domínio, autenticação administrativa, disponibilidade, agendamento ou cancelamento — isso é fundação técnica apenas.
- CI builda as imagens Docker (`docker compose build`) para validar os Dockerfiles, mas não executa a stack completa via Compose; os testes de frontend e backend rodam nativamente nos runners do GitHub Actions.
- Testes de concorrência (duas reservas disputando o mesmo horário) serão adicionados junto da funcionalidade de disponibilidade.
