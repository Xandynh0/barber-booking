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
| `proxy` | http://localhost:8080 | ponto único de entrada: `/` → frontend, `/api/*`, `/up` e `/sanctum/*` → backend |
| `mysql` | localhost:3307 (host) | banco de dados; porta interna 3306 |
| `mailpit` | http://localhost:8025 | interface web dos e-mails capturados |

Verifique o status dos containers:

```sh
docker compose ps
```

Todos devem aparecer como `healthy` (frontend e backend podem levar alguns segundos até o healthcheck passar).

## Atualizando dependências

**`docker compose build` sozinho não basta** quando se adiciona/remove uma dependência do frontend. O serviço `frontend` monta `./frontend:/app` e também um volume nomeado `frontend_node_modules:/app/node_modules` — esse segundo volume existe para isolar o `node_modules` do host (evita conflito de binários entre SO), mas ele **persiste entre `docker compose up`/`build`/`restart`**, exatamente como o volume do MySQL. Isso significa que o `node_modules` gerado dentro da imagem no `build` fica **sombreado** pelo conteúdo antigo do volume assim que o container sobe — um `npm install` que só rodou durante o build nunca chega a ser visto em runtime.

Foi exatamente isso que causou `Failed to resolve import "react-router-dom"` no navegador depois que a dependência foi adicionada nesta entrega: o volume `frontend_node_modules` já existia de uma subida anterior (sem `react-router-dom`), e continuou sendo montado por cima do `node_modules` recém-buildado.

Procedimento correto ao adicionar/atualizar uma dependência do frontend:

```sh
cd frontend && npm install <pacote>   # atualiza package.json/package-lock.json no host
docker compose build frontend          # reconstrói a imagem (opcional se só for sincronizar o volume)
docker compose exec frontend npm install   # sincroniza o volume frontend_node_modules com o lockfile atual
docker compose restart frontend        # Vite precisa reiniciar para enxergar o pacote novo
```

O terceiro passo é o que resolve de fato — ele roda `npm install` dentro do container, escrevendo no mesmo volume que fica montado em runtime. Não é necessário (nem recomendado) remover o volume com `docker compose down -v`: isso apagaria o volume do MySQL junto.

Para o backend, não há esse problema: `composer install`/`require` já é executado diretamente dentro do container via bind mount (`./backend:/var/www/html`), sem um volume extra sombreando `vendor/`.

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

`php artisan migrate` nunca reseta dados — ele só aplica as migrations ainda não rodadas (controladas pela tabela `migrations`). As quatro migrations desta entrega (`business_settings`, `services`, `professionals`, `professional_service`) são aditivas; rodar o comando de novo em um banco já migrado não faz nada (`Nothing to migrate.`). **Nunca use `migrate:fresh` ou `migrate:refresh`** no banco de desenvolvimento — ambos apagam todas as tabelas antes de recriar.

> **O banco `barber_booking` é compartilhado entre quem estiver testando manualmente e qualquer verificação automatizada rodada no mesmo ambiente.** Um `\App\Models\User::query()->delete()` (ou equivalente) feito via `tinker` para "limpar" dados de teste apaga **qualquer** usuário existente, inclusive um administrador que outra pessoa tenha acabado de criar para seus próprios testes — não existe isolamento por quem criou o quê. Ao validar algo manualmente neste banco, prefira criar registros com nomes/e-mails claramente de teste e não rodar limpezas em massa (`::query()->delete()`, `truncate`) nas tabelas `users`, `services` ou `professionals` sem confirmar antes que ninguém mais depende do que está lá.

### Inicializando `business_settings`

O registro único de configuração (seção 4 do planejamento) precisa existir antes de criar serviços/profissionais, porque toda escrita nesses cadastros trava essa linha (`SELECT ... FOR UPDATE`) antes de validar o resto. Ele é criado pelo seeder, não por uma migration (migrations criam estrutura; seeders populam dados) — e o seeder é idempotente, seguro para rodar quantas vezes quiser:

```sh
docker compose exec backend php artisan db:seed
```

`BusinessSettingsSeeder` usa `firstOrCreate([], [...])` — se já existir qualquer linha em `business_settings`, nada é alterado; só cria se a tabela estiver vazia. Rodar `db:seed` de novo depois que um admin editar essas configurações (quando essa tela existir) não vai sobrescrever nada.

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

## Cadastros administrativos: serviços e profissionais

Contrato completo (rotas, formato de `price`, semântica de `service_ids` no `PATCH`) está em `docs/planejamento-barbearia-mvp.md`, seção 13. Aqui fica o que é específico da implementação.

- Todas as rotas (`/api/v1/admin/services`, `/api/v1/admin/professionals`) exigem sessão (`auth:sanctum`) e, nas mutações, o mesmo CSRF já descrito acima — nenhum mecanismo novo.
- `price` trafega como **string decimal** (`"45.00"`, nunca `45.0` número) em request e response — o model `Service` usa o cast `decimal:2` do Eloquent, que já serializa como string tanto no PHP quanto no JSON. O frontend converte o formato brasileiro (`45,90`) para esse formato antes de enviar (`frontend/src/utils/money.js`) e de volta para exibir.
- Ativação/desativação é só o campo `is_active` num `PATCH` normal — não existe rota de exclusão.
- `service_ids` no `PATCH` de profissional: **omitir preserva os vínculos atuais; enviar `[]` remove todos.** Isso é tratado olhando se a chave existe no payload validado (`array_key_exists`), não o valor — por isso a validação usa `sometimes` (não `nullable`) no `UpdateProfessionalRequest`.
- A existência dos `service_ids` é verificada **dentro** da transação que trava `business_settings`, depois do lock — não no Form Request (que só valida formato: é array, são inteiros, sem duplicados). Isso é deliberado: a checagem de negócio ("esse serviço existe?") precisa estar sob o mesmo lock que serializa as demais escritas de cadastro, não antes dela.
- O lock em si é uma única linha por ação, sem abstração: `BusinessSettings::query()->lockForUpdate()->first();` como primeira instrução dentro de cada `DB::transaction()` em `ServiceController`/`ProfessionalController`. Nenhum lock por profissional nesta entrega (a especificação só pede isso para reservas, que não existem ainda).

### Roteiro rápido para testar pelo terminal

```sh
# depois de logado (ver seção de autenticação acima para csrf-cookie + login)
curl -s -b cookies.txt -c cookies.txt -H "X-XSRF-TOKEN: $XSRF" -H "Content-Type: application/json" \
  -X POST http://localhost:8080/api/v1/admin/services \
  -d '{"name":"Corte masculino","duration_minutes":30,"price":"45.00"}'

curl -s -b cookies.txt -c cookies.txt -H "X-XSRF-TOKEN: $XSRF" -H "Content-Type: application/json" \
  -X POST http://localhost:8080/api/v1/admin/professionals \
  -d '{"name":"Lucas","service_ids":[1]}'
```

## Testes

Frontend (Vitest):

```sh
cd frontend
npm install
npm run test
npm run build   # verificação de build de produção
```

43 testes no total (Vitest + Testing Library): `Home` (3), cliente HTTP/CSRF (`api/client.test.js`, 6), conversão de moeda (`utils/money.test.js`, 6), `Login` (7), `ProtectedRoute` (4), `Agenda` (3), `Services` (6 — carregando/lista, vazio, erro com retry, criar convertendo `45,90` → `"45.90"`, validação preservando os campos, editar pré-preenchendo e enviando `PATCH`) e `Professionals` (8 — lista com serviços vinculados, vínculo com serviço desde então inativo mostrando "(inativo)", vazio, criar com serviços selecionados, criar sem nenhum serviço, validação preservando valores, editar pré-marcando os checkboxes certos, desativar). Resultado desta entrega: **43 passed**, build de produção OK.

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

`backend/tests/Feature/Admin/AuthTest.php` exercita o fluxo real de SPA auth do Sanctum (sessão + cookie + CSRF) de ponta a ponta — sem `Sanctum::actingAs()` e sem middleware desabilitado. 11 testes, incluindo:

- login válido (identidade retornada, sessão regenerada — comparado o ID de sessão decriptado antes/depois, não o cookie cru, que muda a cada resposta só pela criptografia usar IV aleatório);
- normalização de e-mail (maiúsculas/espaços) no login;
- senha errada e e-mail inexistente retornando o **mesmo** `401 INVALID_CREDENTIALS`;
- validação de campos (`422`);
- `/me` sem sessão (`401 UNAUTHENTICATED`) e após login (`200`);
- logout: `204`, sessão anterior rejeitada numa chamada seguinte a `/me`;
- os dois limites de tentativas (por e-mail e por IP), incluindo o header `Retry-After`.

**Importante sobre a prova de CSRF — ela é de UM teste específico, não da suíte inteira.** Por padrão, o middleware de CSRF do próprio Laravel (`PreventRequestForgery::handle()`, por trás do `ValidateCsrfToken` do Sanctum) **pula a verificação inteira** sempre que `$app->runningUnitTests()` é verdadeiro — ou seja, sempre que `APP_ENV=testing`, que é o ambiente desta suíte inteira (forçado em `phpunit.xml`, ver abaixo). Isso significa que, nos outros 10 testes, uma requisição sem `X-XSRF-TOKEN` passaria mesmo assim — não porque o CSRF esteja "ativo e tolerante", mas porque o Laravel simplesmente não o executa em modo de teste. Isso é esperado e não é um problema: esses 10 testes não afirmam nada sobre CSRF, só sobre login/sessão/validação/throttle.

Só `test_csrf_protection_rejects_a_missing_token_and_accepts_a_valid_one` prova algo sobre CSRF, e só porque ele desliga esse atalho explicitamente: `$this->app->instance('env', 'production')` força o ambiente a deixar de ser `'testing'` só para aquele teste, obrigando o middleware a rodar de verdade. Com isso ligado, o teste verifica as duas direções: uma requisição sem token recebe `419` e a mesma sessão com o token válido (emitido por `/sanctum/csrf-cookie`) recebe `200`. Sem esse override, o teste passaria de qualquer forma — e foi exatamente isso que aconteceu inicialmente: ele passava rodando dentro do Compose "pela razão errada", porque o `env_file` do Docker já injetava `APP_ENV=local` como variável real do container, o que (mesmo mecanismo de "Isolamento entre banco de desenvolvimento e banco de testes") fazia a diretiva não-forçada `<env name="APP_ENV" value="testing"/>` do `phpunit.xml` ser ignorada — então o CSRF ficava ativo por acidente em todo o resto da suíte também, mascarando o atalho do Laravel. Rodando nativamente (mais perto do que o CI faz), `APP_ENV` virava `testing` de verdade, o atalho entrava em ação, e a mesma requisição sem token passava com `200` em vez de `419`.

Corrigido em duas frentes, para que o comportamento seja o mesmo em qualquer ambiente:

- `phpunit.xml` agora força (`force="true"`) `APP_ENV=testing` — a suíte roda sob o ambiente de teste padrão do Laravel em qualquer lugar (Docker, nativo, CI), em vez de depender do que o `env_file` do Compose injeta por acaso.
- O teste de CSRF continua desligando esse ambiente explicitamente (`$this->app->instance('env', 'production')`) só para si mesmo — é o único jeito de testar o middleware de verdade dado que o padrão do Laravel é não rodá-lo em teste.

Uma segunda pegadinha de teste que valeu registrar: `AuthManager`/`SessionGuard` cacheiam o guard resolvido e o usuário autenticado pela duração do container. Como várias chamadas HTTP simuladas num mesmo método de teste compartilham esse container, uma chamada feita **depois** do logout continuava "autenticada" nos testes — não porque a sessão real continuasse válida (o stack real via `curl`, com requisições HTTP de verdade, sempre rejeitou corretamente), mas porque o guard em memória nunca era invalidado entre as chamadas simuladas. A correção foi chamar `Auth::forgetGuards()` entre requisições simuladas sempre que o estado de autenticação muda (login/logout) dentro do mesmo teste.

### Testes de serviços e profissionais

`backend/tests/Feature/Admin/ServiceTest.php` (14 — inclui 4 casos de duração inválida via `#[DataProvider]`) e `ProfessionalTest.php` (10) usam `$this->actingAs($user)` — não `Sanctum::actingAs()`, que exige o trait `HasApiTokens` no model `User`; não adicionamos esse trait de propósito (não emitimos tokens de API nesta aplicação, só sessão). `$this->actingAs()` autentica no guard `web` diretamente, que é o que o `auth:sanctum` desta aplicação realmente verifica — mais simples que repetir a dança de CSRF/cookie do `AuthTest.php` em todo teste de CRUD, cuja prova já está feita separadamente.

Cobertura: acesso sem sessão (`401`), listagem estável incluindo inativos, criação com validação (campos obrigatórios, duração inválida — zero/negativa/não-inteira/grande demais via `#[DataProvider]`, preço negativo recusado, **preço zero aceito**), edição parcial, ativação/desativação, `404` para id inexistente, vínculos duplicados/inexistentes recusados, semântica de `service_ids` no `PATCH` (omitir preserva, `[]` limpa), vínculo com serviço desativado preservado e sinalizado, e **atomicidade**: uma alteração com `service_ids` inválido não aplica a mudança de nome enviada junto (`assertDatabaseHas` confirma o valor antigo).

Achado ao longo do caminho: o `ModelNotFoundException` de um route-model-binding que falha nunca chega ao `$exceptions->render()` registrado para ele — o handler padrão do Laravel já o reembrulha em `NotFoundHttpException` antes disso. O render de `404 NOT_FOUND` está registrado para `NotFoundHttpException`, não `ModelNotFoundException`.

`backend/tests/Feature/BusinessSettingsSeederTest.php` (2) prova os defaults documentados e a idempotência (rodar o seeder duas vezes, alterar valores no meio, confirmar que a segunda chamada não sobrescreve). Achado aqui: `firstOrCreate(['id' => 1], [...])` é frágil — o `auto_increment` do MySQL **não é desfeito por rollback de transação**, então um teste anterior que criou e descartou uma linha já consome o id 1, e a segunda chamada do seeder (buscando especificamente `id=1`) não encontra nada e cria uma segunda linha. Corrigido usando `firstOrCreate([], [...])` (critério de busca vazio = "existe alguma linha?", o certo para um singleton), independente de qual id ela acabou recebendo.

Resultado desta entrega: suíte completa com **41 passed** (11 de autenticação + 14 de serviços + 10 de profissionais + 2 de `business_settings` + 4 pré-existentes da fundação técnica), rodando 3× seguidas sem flakiness em Docker. Confirmado por contagem de linhas que o banco `barber_booking` (dev) não foi alterado por nenhuma execução.

## Mailpit

Qualquer e-mail enviado pelo backend (`MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`) fica disponível em http://localhost:8025, sem sair da rede local.

## Verificação manual no navegador

Nenhuma ferramenta de automação de navegador está disponível neste ambiente de desenvolvimento assistido — o que segue é o que já foi confirmado por uma pessoa em um navegador real, e o que ainda falta.

**Já confirmado (homepage, navegador real):** `http://localhost:8080` carrega "Barber Booking", a frase de efeito e o indicador de status mudando para "Conectado". Foi nessa verificação que apareceu o erro `Failed to resolve import "react-router-dom"` corrigido nesta rodada (volume `frontend_node_modules` desatualizado — ver "Atualizando dependências"); depois da correção, a página voltou a carregar sem erros de console.

**Ainda pendente — roteiro para quem for validar o fluxo de admin:**

1. Suba o ambiente (`docker compose up -d --build`) e confirme `docker compose ps` com tudo `healthy`.
2. Repita a checagem da homepage em uma viewport mobile (DevTools → modo responsivo, ou um celular real na mesma rede apontando para o IP da máquina na porta 8080) e confirme que o layout não quebra.
3. Teste o estado de indisponibilidade: `docker compose stop mysql`, recarregue a página e confirme que o indicador muda para "Indisponível" com uma mensagem genérica (sem detalhes de conexão, host ou driver). Depois rode `docker compose start mysql` para restaurar.
4. Crie um admin (`docker compose exec backend php artisan admin:create`) e abra `http://localhost:8080/admin/login`.
   - Confirme labels visíveis, foco visível ao navegar por Tab, e que dá para enviar o formulário só pelo teclado (Tab até o botão, Enter).
   - Tente um login errado: confirme mensagem genérica de credenciais inválidas (não deve indicar se o e-mail existe).
   - Deixe campos vazios e envie: confirme erros de validação por campo.
   - Logue com as credenciais corretas: confirme redirecionamento para `/admin/agenda`, nome/e-mail do admin exibidos, e a mensagem "a agenda será implementada em uma próxima etapa" (sem dados fictícios de reserva).
   - Recarregue a página em `/admin/agenda`: confirme um estado de carregamento breve ("Verificando sessão...") antes de mostrar o conteúdo — prova que a checagem usa `/me`, não um estado só local.
   - Clique "Sair": confirme volta para `/admin/login`. Tente recarregar `/admin/agenda` diretamente: confirme que volta para o login (sessão realmente encerrada, não só escondida na UI).
5. Logado, abra `/admin/servicos`:
   - Crie um serviço com preço `45,90` e confirme que a lista mostra "R$ 45,90" (prova que a conversão BRL ↔ decimal da API funciona nos dois sentidos).
   - Clique "Editar" num serviço, confirme que o formulário vem preenchido (inclusive o preço já em `45,90`), altere e salve; confirme a mensagem de sucesso e a lista atualizada.
   - Desmarque "Ativo" num serviço e salve; confirme que a lista mostra "Inativo" por texto (não só pela cor).
   - Tente criar um serviço sem nome/duração/preço; confirme erros de validação ao lado de cada campo, e que o que você já tinha digitado nos outros campos continua lá.
6. Abra `/admin/profissionais`:
   - Crie um profissional marcando um ou mais serviços; confirme que a lista mostra os nomes dos serviços vinculados.
   - Desative, em `/admin/servicos`, um serviço que está vinculado a esse profissional; volte para `/admin/profissionais` e confirme que o vínculo continua lá, com "(inativo)" ao lado do nome do serviço.
   - Edite o profissional sem mexer nos checkboxes de serviço; confirme que os vínculos continuam os mesmos depois de salvar (omitir `service_ids` preserva).
   - Teste a navegação só por teclado entre os checkboxes de serviço (Tab/Espaço) e confirme foco visível.

O que dava para confirmar sem navegador foi validado via `curl`/PHPUnit e está documentado nas seções acima: resposta de indisponibilidade (`503` genérico, detalhe só em log), e os fluxos completos de login/sessão/logout/CSRF e de criação/edição de serviços e profissionais contra o proxy real.

## Solução de problemas comuns

| Sintoma | Causa provável | Solução |
| --- | --- | --- |
| Backend fica `unhealthy` | `APP_KEY` vazio no `backend/.env` | gere a chave (passo 2 da instalação) e reinicie: `docker compose restart backend` |
| `/api/health` retorna 503 | MySQL ainda subindo ou credenciais divergentes entre `.env` (raiz) e `backend/.env` | aguarde o healthcheck do `mysql` ficar `healthy`; confira se `MYSQL_*` no `.env` raiz bate com o esperado |
| Testes falham com "Unknown database 'barber_booking_test'" | volume do MySQL já existia antes do banco de testes ser criado | rode o `CREATE DATABASE`/`GRANT` manual da seção Migrations, depois migre o banco de testes |
| Frontend não atualiza (HMR) ao editar código | variável `VITE_DEV_SERVER_PROXIED` não aplicada | confirme que está acessando via `http://localhost:8080` (porta do proxy) e não diretamente por `5173` |
| `Failed to resolve import "<pacote>"` no navegador, após instalar uma dependência nova do frontend | volume `frontend_node_modules` com conteúdo antigo, sombreando o `node_modules` da imagem recém-buildada | veja "Atualizando dependências" — rode `docker compose exec frontend npm install` e depois `docker compose restart frontend` |
| Porta 8080/3307/8025 já em uso | outro serviço local ocupando a porta | ajuste `APP_PORT`, `MYSQL_HOST_PORT` ou `MAILPIT_WEB_PORT` no `.env` da raiz |
| `composer install` falha por versão do PHP | imagem Docker desatualizada em cache | `docker compose build --no-cache backend` |
| Página continua com a versão antiga (ex.: menu novo não aparece) depois de um `docker compose restart frontend` | aba do navegador aberta de antes do restart — a reconexão do WebSocket de HMR do Vite nem sempre força um reload completo da página | dê um hard refresh (Ctrl+Shift+R) ou abra em aba anônima/nova; para confirmar que o servidor está servindo o código certo sem depender do navegador, use `curl http://localhost:8080/src/<arquivo>.jsx` e compare com o arquivo no host |

## Limitações desta etapa

- Serviços, profissionais e seus vínculos estão implementados (esta entrega), assim como autenticação administrativa (entrega anterior). Expediente, bloqueios, disponibilidade, catálogo público, agendamentos, cancelamento e notificações ainda não existem.
- `business_settings` só tem o registro singleton (seedado) e o lock usado pelas escritas de cadastro — sem tela nem endpoint de edição ainda.
- CI builda as imagens Docker (`docker compose build`) para validar os Dockerfiles, mas não executa a stack completa via Compose; os testes de frontend e backend rodam nativamente nos runners do GitHub Actions.
- Testes de concorrência (duas reservas disputando o mesmo horário) serão adicionados junto da funcionalidade de disponibilidade — o lock de `business_settings` usado nos cadastros desta entrega não foi testado sob concorrência real (duas escritas simultâneas), só o isolamento básico de conexão (`MySqlConnectionIsolationTest`).
- Homepage confirmada visualmente em navegador real; viewport mobile, estado de indisponibilidade e os fluxos completos de `/admin/login`, `/admin/agenda`, `/admin/servicos` e `/admin/profissionais` ainda pendentes de validação visual — ver roteiro manual acima.
- `backend/composer.json` originalmente declarava `"php": "^8.3"`, mas o `composer.lock` resolvido trava `symfony/*` em versões que exigem PHP ≥8.4.1; `composer install` só falha ao rodar de fato em PHP 8.3 (o `platform` do lock não é validado contra o interpretador real até o install). Corrigido para `^8.4`, que é o que a imagem Docker e o CI já usavam.
