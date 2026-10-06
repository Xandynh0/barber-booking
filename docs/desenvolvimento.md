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
| Laravel Sanctum | 4.3.x | autenticação de sessão/cookie do SPA, sem tokens |
| react-router-dom | 7.x | rotas `/`, `/admin/login`, `/admin/agenda` |

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

## Autenticação administrativa

SPA auth com [Laravel Sanctum](https://laravel.com/docs/13.x/sanctum#spa-authentication) em modo sessão/cookie — **sem tokens**, nada em `localStorage`. Funciona porque frontend e API estão na mesma origem (`localhost:8080`, via proxy); isso dispensa configuração de CORS.

### Criando o primeiro administrador

Não há cadastro público. Todo usuário deste MVP é administrador, criado via comando interativo (senha oculta, hash automático, e-mail único e normalizado):

```sh
docker compose exec backend php artisan admin:create
```

O comando pede nome, e-mail (valida formato e unicidade — reage a variações de maiúsculas/espaços porque o e-mail é normalizado antes de comparar) e senha com confirmação (mínimo 8 caracteres). Não existe senha padrão nem seed de admin — `database/seeders/DatabaseSeeder.php` não cria nenhum usuário, de propósito.

### Como a sessão e o CSRF funcionam aqui

1. O frontend chama `GET /sanctum/csrf-cookie` (rota registrada pelo próprio Sanctum, fora do prefixo `/api`) antes de logar. Isso grava os cookies `XSRF-TOKEN` e `barber-booking-session`.
2. Toda requisição de mutação (`POST`) lê o cookie `XSRF-TOKEN` e o reenvia no header `X-XSRF-TOKEN` (`frontend/src/api/client.js`). O servidor decripta esse header e compara com o token guardado na sessão — é assim que o CSRF é verificado de fato, não apenas "configurado".
3. `bootstrap/app.php` ativa `$middleware->statefulApi()`, que injeta sessão + verificação de CSRF em qualquer request de `/api/*` cuja origem (`Referer`/`Origin`) esteja em `SANCTUM_STATEFUL_DOMAINS` (`localhost:8080,127.0.0.1:8080` por padrão). Fora dessa lista, a rota cairia para autenticação por token Bearer — que não usamos.
4. Login bem-sucedido chama `$request->session()->regenerate()` (troca o ID da sessão, mitigando session fixation). Logout chama `invalidate()` (destrói a sessão atual no servidor) + `regenerateToken()` (novo CSRF token).

### Endpoints

| Método / rota | Autenticação | Descrição |
| --- | --- | --- |
| `GET /sanctum/csrf-cookie` | nenhuma | inicializa cookies de sessão/CSRF |
| `POST /api/v1/admin/login` | nenhuma (login em si) | autentica; `200` com identidade mínima, `401` em credenciais inválidas |
| `GET /api/v1/admin/me` | sessão (`auth:sanctum`) | identidade do admin autenticado |
| `POST /api/v1/admin/logout` | sessão (`auth:sanctum`) | encerra a sessão; `204` |

### Contrato de erros — adição a esta entrega

A especificação original (`docs/planejamento-barbearia-mvp.md`, seção 11) documentava `401 UNAUTHENTICATED` para "encaminhar admin ao login". Esta entrega introduz login propriamente dito, o que exige distinguir dois casos que antes não existiam separadamente:

| HTTP | Código | Quando | Observação |
| --- | --- | --- | --- |
| 401 | `UNAUTHENTICATED` | não há sessão válida (ex.: `GET /me` sem cookie) | comportamento já previsto na especificação original |
| 401 | `INVALID_CREDENTIALS` | `POST /login` com e-mail ou senha incorretos | **novo nesta entrega** — mesma mensagem genérica para "senha errada" e "e-mail não existe", para não revelar quais e-mails têm conta |
| 422 | `VALIDATION_ERROR` | campos ausentes/mal formados | `fields` traz o detalhe por campo |
| 429 | `RATE_LIMITED` | limite de tentativas de login excedido | inclui header `Retry-After` |

### Limites de tentativas de login

Dois limites independentes, configuráveis por variável de ambiente (baixos nos testes para não depender de esperar um minuto real):

| Variável | Padrão | Teste |
| --- | --- | --- |
| `ADMIN_LOGIN_THROTTLE_PER_EMAIL` | 5/min por IP + e-mail normalizado | forçado a 2 no `phpunit.xml` |
| `ADMIN_LOGIN_THROTTLE_PER_IP` | 20/min por IP | forçado a 3 no `phpunit.xml` |

Ambos contam tentativas **inválidas** (o middleware `throttle` incrementa a cada request, antes do controller decidir se a credencial é válida). `config/admin_auth.php` lê essas variáveis; nada fica hardcoded nos limiters.

## Testes

Frontend (Vitest):

```sh
cd frontend
npm install
npm run test
npm run build   # verificação de build de produção
```

23 testes no total (Vitest + Testing Library), cobrindo: `Home` (3, pré-existente), cliente HTTP/CSRF (`api/client.test.js`, 6), `Login` (7 — carregamento inicial, login com sucesso e redirecionamento, credenciais inválidas, validação de campo, limite de tentativas, falha de conexão, redirecionamento automático se já autenticado), `ProtectedRoute` (4 — carregando, autenticado, sem sessão, sessão expirada/419) e `Agenda` (3 — identidade exibida sem dados fictícios, logout limpa a sessão e volta ao login, logout não trava em retry mesmo se a chamada falhar). Resultado desta entrega: **23 passed**, build de produção OK.

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

### Testes de autenticação administrativa

`backend/tests/Feature/Admin/AuthTest.php` exercita o fluxo real de SPA auth do Sanctum (sessão + cookie + CSRF) de ponta a ponta — sem `Sanctum::actingAs()` e sem middleware desabilitado, então um teste passando aqui prova que o CSRF está ativo de verdade, não apenas presumido. 11 testes, incluindo:

- login válido (identidade retornada, sessão regenerada — comparado o ID de sessão decriptado antes/depois, não o cookie cru, que muda a cada resposta só pela criptografia usar IV aleatório);
- normalização de e-mail (maiúsculas/espaços) no login;
- senha errada e e-mail inexistente retornando o **mesmo** `401 INVALID_CREDENTIALS`;
- validação de campos (`422`);
- `/me` sem sessão (`401 UNAUTHENTICATED`) e após login (`200`);
- logout: `204`, sessão anterior rejeitada numa chamada seguinte a `/me`;
- **prova direta de que o CSRF está ativo**: uma requisição com cookie de sessão válido mas sem `X-XSRF-TOKEN` recebe `419`, nunca chega ao controller;
- os dois limites de tentativas (por e-mail e por IP), incluindo o header `Retry-After`.

Duas pegadinhas de teste que valeram registrar:

1. **`AuthManager`/`SessionGuard` cacheiam o guard resolvido e o usuário autenticado pela duração do container.** Como várias chamadas HTTP simuladas num mesmo método de teste compartilham esse container, uma chamada feita **depois** do logout continuava "autenticada" nos testes — não porque a sessão real continuasse válida (o stack real via `curl`, com requisições HTTP de verdade, sempre rejeitou corretamente), mas porque o guard em memória nunca era invalidado entre as chamadas simuladas. A correção foi chamar `Auth::forgetGuards()` entre requisições simuladas sempre que o estado de autenticação muda (login/logout) dentro do mesmo teste.

2. **O CSRF do Laravel se desliga sozinho quando `APP_ENV=testing`.** `PreventRequestForgery::handle()` (a classe por trás do `ValidateCsrfToken` do Sanctum) pula a verificação inteira quando `$app->runningUnitTests()` é verdadeiro — ou seja, sempre que o ambiente resolvido é `testing`. O teste que prova a rejeição por CSRF (`test_csrf_protection_is_actually_enforced_without_a_valid_token`) **passava "pela razão errada" rodando dentro do Compose**: lá, o `env_file` do Docker já injeta `APP_ENV=local` como variável real do container, o que (pelo mesmo mecanismo descrito em "Isolamento entre banco de desenvolvimento e banco de testes") faz a diretiva não-forçada `<env name="APP_ENV" value="testing"/>` do `phpunit.xml` ser ignorada — então o CSRF continuava ativo por acidente. Rodando com PHP nativo (sem Docker, mais perto do que o CI faz), `APP_ENV` vira `testing` de verdade, o atalho do Laravel entra em ação, e a mesma requisição que deveria ser rejeitada passava com `200`. É exatamente assim que o problema apareceu: a suíte passava localmente e falhou no CI. Corrigido fazendo esse único teste forçar `$this->app->instance('env', 'production')` antes da requisição sem token — isso desliga o atalho de conveniência do Laravel só ali, obrigando o middleware a rodar de verdade e provando a rejeição pelo motivo certo.

Resultado desta entrega: suíte completa com **15 passed** (11 de autenticação + 4 pré-existentes da fundação técnica), rodando 3× seguidas sem flakiness tanto em Docker quanto via PHP nativo contra o MySQL exposto em `localhost:3307`. Confirmado por contagem de linhas que o banco `barber_booking` (dev) não foi alterado por nenhuma execução.

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
5. Crie um admin (`docker compose exec backend php artisan admin:create`) e abra `http://localhost:8080/admin/login`.
   - Confirme labels visíveis, foco visível ao navegar por Tab, e que dá para enviar o formulário só pelo teclado (Tab até o botão, Enter).
   - Tente um login errado: confirme mensagem genérica de credenciais inválidas (não deve indicar se o e-mail existe).
   - Deixe campos vazios e envie: confirme erros de validação por campo.
   - Logue com as credenciais corretas: confirme redirecionamento para `/admin/agenda`, nome/e-mail do admin exibidos, e a mensagem "a agenda será implementada em uma próxima etapa" (sem dados fictícios de reserva).
   - Recarregue a página em `/admin/agenda`: confirme um estado de carregamento breve ("Verificando sessão...") antes de mostrar o conteúdo — prova que a checagem usa `/me`, não um estado só local.
   - Clique "Sair": confirme volta para `/admin/login`. Tente recarregar `/admin/agenda` diretamente: confirme que volta para o login (sessão realmente encerrada, não só escondida na UI).

Os passos 1–4 (renderização real em navegador, console, responsivo, indisponibilidade visual) e o passo 5 (fluxo de login real em navegador) seguem pendentes de validação visual — sem ferramenta de automação de navegador disponível neste ambiente. O que dava para confirmar sem navegador foi validado via `curl`/PHPUnit e está documentado nas seções acima: resposta de indisponibilidade (`503` genérico, detalhe só em log), e o fluxo completo de login/sessão/logout/CSRF contra o proxy real.

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

- Sem cadastros de domínio (serviços, profissionais, expediente), disponibilidade, agendamento ou cancelamento. Autenticação administrativa (esta entrega) está implementada; o restante aguarda as próximas etapas.
- CI builda as imagens Docker (`docker compose build`) para validar os Dockerfiles, mas não executa a stack completa via Compose; os testes de frontend e backend rodam nativamente nos runners do GitHub Actions.
- Testes de concorrência (duas reservas disputando o mesmo horário) serão adicionados junto da funcionalidade de disponibilidade.
- Verificação visual em navegador real (desktop/mobile, console) ainda pendente — ver roteiro manual acima.
- `backend/composer.json` originalmente declarava `"php": "^8.3"`, mas o `composer.lock` resolvido trava `symfony/*` em versões que exigem PHP ≥8.4.1; `composer install` só falha ao rodar de fato em PHP 8.3 (o `platform` do lock não é validado contra o interpretador real até o install). Corrigido para `^8.4`, que é o que a imagem Docker e o CI já usavam.
