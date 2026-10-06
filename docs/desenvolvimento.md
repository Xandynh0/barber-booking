# Guia de desenvolvimento

Fundação técnica do Barber Booking: monorepo com frontend (React + Vite), backend (Laravel) e MySQL, orquestrados via Docker Compose atrás de um proxy que serve tudo na mesma origem.

## Versões

| Componente | Versão | Observação |
| --- | --- | --- |
| Node.js | 24 (Active LTS) | usado no container do frontend e no CI |
| Vite | 8.x | React 19, template `react` (JavaScript) |
| PHP | 8.4 | `composer.json` exige `^8.4`: os `symfony/*` travados no `composer.lock` (dependências do Laravel 13) não instalam em PHP 8.3, só a partir de 8.4.1 |
| Laravel | 13.x | framework do backend |
| MySQL | 8.4 (LTS) | suporte estendido até 2032 |
| Mailpit | v1.31.0 (fixado) | captura e-mails locais (SMTP + interface web) |

PHP, Docker e CI usam a mesma versão (8.4), conferida contra o `composer.lock` real — ver "Limitações" para o detalhe de como isso foi descoberto.

## Pré-requisitos

- Docker e Docker Compose (plugin `docker compose`).
- Nenhuma instalação local de Node/PHP/MySQL é necessária para rodar via Docker. Para rodar o backend ou o frontend fora do Docker, use as versões da tabela acima.

## Estrutura do monorepo

```
backend/    Laravel (API)
frontend/   React + Vite (SPA)
docker/     configuração do proxy (nginx) e do init do MySQL
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

O Compose cria dois bancos no MySQL: `barber_booking` (desenvolvimento) e `barber_booking_test` (testes automatizados — ver seção seguinte). Migre os dois:

```sh
docker compose exec backend php artisan migrate
docker compose exec -e DB_TEST_DATABASE=barber_booking_test backend php artisan migrate --no-interaction
```

> O segundo banco só é criado automaticamente em um volume novo (via `docker/mysql/init/`, executado pelo MySQL apenas na primeira inicialização). Se você já tinha o volume `mysql_data` de antes desta mudança, crie-o manualmente uma vez:
> ```sh
> docker compose exec mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS barber_booking_test; GRANT ALL PRIVILEGES ON barber_booking_test.* TO 'barber_booking'@'%'; FLUSH PRIVILEGES;"
> ```

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

### Isolamento entre banco de desenvolvimento e banco de testes

Dentro do Compose, as variáveis de ambiente do backend (`backend/.env`) são injetadas pelo Docker diretamente como variáveis de ambiente do container (mecanismo `env_file`). Isso faz com que uma diretiva `<env>` do `phpunit.xml` sem `force="true"` seja silenciosamente ignorada — o valor do container sempre prevalece. Foi exatamente isso que fazia os testes rodarem contra o banco `barber_booking` (de desenvolvimento) em vez de um banco isolado.

A correção:

- `phpunit.xml` força (`force="true"`) a variável `DB_TEST_DATABASE=barber_booking_test`, que **sempre** vence, mesmo com `DB_DATABASE` já definido no ambiente do container.
- `backend/config/database.php` faz a conexão `mysql` usar `env('DB_TEST_DATABASE', env('DB_DATABASE', 'laravel'))` — ou seja, fora de um test run, nada muda; durante o PHPUnit, o banco é sempre `barber_booking_test`.

Verificado nesta correção: rodar a suíte completa não altera nenhuma linha do banco `barber_booking` (contagem de linhas comparada antes/depois via `information_schema`).

### Isolamento de conexão com o MySQL

`backend/tests/Feature/MySqlConnectionIsolationTest.php` abre duas conexões PDO distintas para o mesmo MySQL e comprova que uma escrita não commitada em uma conexão não é visível na outra, e passa a ser visível após o commit. Esse teste é pulado automaticamente sob SQLite (configuração padrão do `phpunit.xml`) e só é exercido quando `DB_CONNECTION=mysql`, como acontece dentro do container do backend e no CI.

**O que esse teste prova e o que não prova:** ele confirma que duas sessões MySQL distintas não compartilham uma transação em aberto — um comportamento básico de isolamento de conexão. Ele **não** prova um nível de isolamento específico (o teste passaria igualmente sob `READ COMMITTED`) e **não** é um teste de concorrência de reservas (não há duas transações disputando a mesma linha/horário). Nível de isolamento realmente observado nesta entrega, consultado via `SELECT @@SESSION.transaction_isolation` nas conexões da aplicação e dos testes:

```
REPEATABLE-READ
```

(valor padrão do MySQL 8.4, igual em ambas as conexões verificadas). Testes de concorrência de reservas (duas transações disputando o mesmo horário) ficam para a etapa de disponibilidade, quando essa funcionalidade existir.

## Mailpit

Qualquer e-mail enviado pelo backend (`MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`) fica disponível em http://localhost:8025, sem sair da rede local.

## Verificação manual no navegador

Esta verificação ainda não foi feita com uma ferramenta de automação de navegador (nenhuma disponível neste ambiente). Roteiro para quem for validar manualmente:

1. Suba o ambiente (`docker compose up -d --build`) e confirme `docker compose ps` com tudo `healthy`.
2. Abra `http://localhost:8080` em um navegador desktop.
   - Confirme que a página mostra "Barber Booking", a frase de efeito e o indicador de status.
   - Abra o DevTools (console + aba Network) e confirme: sem erros no console; uma chamada `GET /api/health` com `200` e corpo `{"status":"ok"}`; o indicador muda de "Verificando..." para "Conectado".
3. Repita em uma viewport mobile (DevTools → modo responsivo, ou um celular real na mesma rede apontando para o IP da máquina na porta 8080) e confirme que o layout não quebra e o indicador também chega a "Conectado".
4. Teste o estado de indisponibilidade: `docker compose stop mysql`, recarregue a página e confirme que o indicador muda para "Indisponível" com uma mensagem genérica (sem detalhes de conexão, host ou driver). Depois rode `docker compose start mysql` para restaurar.

O passo 4 foi validado via `curl` nesta entrega (resposta `503` com `{"error":{"code":"SERVICE_UNAVAILABLE","message":"Serviço indisponível no momento."}}`, erro completo com stack trace confirmado apenas em `storage/logs/laravel.log`, nunca na resposta HTTP). Os passos 1–3 (renderização real em navegador, console, responsivo) seguem pendentes de validação visual.

## Solução de problemas comuns

| Sintoma | Causa provável | Solução |
| --- | --- | --- |
| Backend fica `unhealthy` | `APP_KEY` vazio no `backend/.env` | gere a chave (passo 2 da instalação) e reinicie: `docker compose restart backend` |
| `/api/health` retorna 503 | MySQL ainda subindo ou credenciais divergentes entre `.env` (raiz) e `backend/.env` | aguarde o healthcheck do `mysql` ficar `healthy`; confira se `MYSQL_*` no `.env` raiz bate com o esperado |
| Testes falham com "Unknown database 'barber_booking_test'" | volume do MySQL já existia antes do banco de testes ser criado | rode o `CREATE DATABASE`/`GRANT` manual da seção Migrations, depois migre o banco de testes |
| Frontend não atualiza (HMR) ao editar código | variável `VITE_DEV_SERVER_PROXIED` não aplicada | confirme que está acessando via `http://localhost:8080` (porta do proxy) e não diretamente por `5173` |
| Porta 8080/3307/8025 já em uso | outro serviço local ocupando a porta | ajuste `APP_PORT`, `MYSQL_HOST_PORT` ou `MAILPIT_WEB_PORT` no `.env` da raiz |
| `composer install` falha por versão do PHP | imagem Docker desatualizada em cache | `docker compose build --no-cache backend` |

## Limitações desta etapa

- Sem cadastros de domínio, autenticação administrativa, disponibilidade, agendamento ou cancelamento — isso é fundação técnica apenas.
- CI builda as imagens Docker (`docker compose build`) para validar os Dockerfiles, mas não executa a stack completa via Compose; os testes de frontend e backend rodam nativamente nos runners do GitHub Actions.
- Testes de concorrência (duas reservas disputando o mesmo horário) serão adicionados junto da funcionalidade de disponibilidade.
- Verificação visual em navegador real (desktop/mobile, console) ainda pendente — ver roteiro manual acima.
- `backend/composer.json` originalmente declarava `"php": "^8.3"`, mas o `composer.lock` resolvido trava `symfony/*` em versões que exigem PHP ≥8.4.1; `composer install` só falha ao rodar de fato em PHP 8.3 (o `platform` do lock não é validado contra o interpretador real até o install). Corrigido para `^8.4`, que é o que a imagem Docker e o CI já usavam.
