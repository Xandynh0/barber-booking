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
| react-router-dom | 7.x | rotas `/`, `/admin/login`, `/admin/agenda`, `/admin/servicos`, `/admin/profissionais`, `/admin/expediente`, `/admin/bloqueios` |

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

## Expediente semanal e bloqueios

Contrato completo em `docs/planejamento-barbearia-mvp.md`, seção 14. Aqui fica o que é específico da implementação.

### Expediente (`working_hours`)

- `PUT /api/v1/admin/professionals/{id}/working-hours` é uma **substituição completa** da semana: o corpo sempre traz os 7 dias (`days`, um objeto por `weekday`, cada um com `periods`). Não existe PATCH parcial por dia — enviar só um dia não é suportado, e omitir um dia é rejeitado (`422`, `size:7` na validação). Essa escolha é o que torna a estratégia de salvamento simples e atômica: dentro de uma única transação (travando `business_settings` primeiro), o controller apaga todos os `working_hours` daquele profissional e recria a partir do payload — como a validação completa (formato, sobreposição, início < fim) já rodou antes de abrir a transação, uma entrada inválida nunca chega a essa parte, então o expediente anterior nunca fica parcialmente apagado.
- Validação de sobreposição/ordem roda em `UpdateWorkingHoursRequest::withValidator()` — é validação de formato sobre o próprio payload (não uma leitura de banco), então roda antes da transação, diferente da checagem de `service_ids` em profissionais. Períodos são comparados por string `"HH:MM"` (comparação lexicográfica funciona para horários de 2 dígitos); adjacentes (`fim` de um == `início` do seguinte) são aceitos.
- Sem componente de data no formato (`start_time`/`end_time` são só `"HH:MM"`), um período atravessando a meia-noite não é representável — a validação de `início < fim` já recusa isso automaticamente, sem checagem extra.
- `GET` sempre retorna os 7 dias, mesmo para um profissional sem nenhum expediente salvo (todos com `periods: []` — folga), para o frontend não ter que tratar "dia ausente" como um caso especial.
- Armazenamento: coluna `TIME` do MySQL (`start_time`, `end_time`); a API trafega e recebe `"HH:MM"` (sem segundos) — o controller corta os dois últimos caracteres do valor bruto do banco (`"09:00:00"` → `"09:00"`) ao montar a resposta.

### Bloqueios (`schedule_blocks`)

- `starts_at`/`ends_at` seguem a convenção de instantes da seção 11 (ISO 8601 com offset na entrada, UTC na resposta) — igual ao resto da API, mas é o **primeiro lugar** onde isso é exercitado de ponta a ponta nesta aplicação. Achado na implementação: o cast `datetime` do Eloquent **não converte para o timezone da aplicação ao gravar** — ele só formata o valor usando o timezone que a instância `Carbon` já carrega no momento da atribuição. Passar a string ISO com offset (`"-03:00"`) direto pro `create()` gravava o literal `"18:00:00"` no banco (hora local, sem reinterpretar para UTC) em vez de `"21:00:00"`. Corrigido convertendo explicitamente no controller: `CarbonImmutable::parse($validated['starts_at'])->utc()` antes do `create()`.
- Bloqueios podem atravessar dias (sem limite de duração) — validado só como `ends_at` após `starts_at` (`after:starts_at` do Laravel, que compara instantes reais, não strings).
- Sobreposição entre bloqueios é **permitida de propósito** — a especificação não define uma regra de conflito entre bloqueios (só entre bloqueio e reserva, que não existe ainda), então nenhuma validação de overlap foi adicionada aqui.
- `professional_id` (quando `scope: "professional"`) é validado quanto ao formato no Form Request (`required_if`/`prohibited_if`/`integer`), mas a **existência** é checada dentro da transação travada, depois do lock — mesmo padrão de `assertServicesExist` em profissionais, pelo mesmo motivo (é uma leitura de negócio, não um formato).

#### Fechamento da barbearia — agrupamento por `group_id` (revisão desta entrega)

A primeira versão desta funcionalidade (ver histórico do PR #4) explicitamente **não** adicionava uma coluna de agrupamento para o fechamento geral — cada linha criada por `scope: "shop"` era independente, e a tela listava uma linha por profissional. Validação manual mostrou que isso dificultava reconhecer e remover o fechamento como uma única operação (um fechamento de 14 profissionais virava 14 linhas na tela, cada uma com seu próprio botão "Remover"). Revisado:

- Migration aditiva `add_group_id_to_schedule_blocks_table`: coluna `group_id` (`ulid`, nullable, indexada), **sem backfill**. Linhas existentes (de antes desta revisão) ficam com `group_id = null` para sempre — não há agrupamento retroativo por semelhança de data/motivo, e nenhum dado existente foi apagado ou recriado para introduzir a coluna. Uma linha com `group_id` nulo é, por definição, individual.
- `ScheduleBlockController::store()` com `scope: "shop"` gera um ULID (`Str::ulid()`) e grava o mesmo valor em `group_id` de cada linha criada naquela chamada — continua uma única transação atômica, com o lock de `business_settings` primeiro, exatamente como antes. `scope: "professional"` grava `group_id: null`.
- A API agora distingue dois formatos de item pelo campo `kind` (`"professional"` ou `"shop"`) — ver `docs/planejamento-barbearia-mvp.md` seção 14 para os dois formatos completos. `index()`/`present()` agrupam as linhas com `group_id` não nulo (`Collection::groupBy('group_id')`) em um item `"shop"` cada, e deixam as linhas com `group_id` nulo como itens `"professional"` individuais — depois ordenam tudo junto por `starts_at`.
- Novo endpoint `DELETE /schedule-blocks/groups/{group_id}` remove todas as linhas daquele grupo numa única transação (mesmo lock de `business_settings`), `404 NOT_FOUND` se o grupo não existir. O endpoint antigo `DELETE /schedule-blocks/{id}` continua existindo e funciona em qualquer linha por id (agrupada ou não) — não foi bloqueado deliberadamente, pois nenhum requisito pede isso e a tela só expõe essa ação para linhas sem grupo.
- **Opção "Dia inteiro":** o frontend (`ScheduleBlocks.jsx`) oferece essa opção só quando `scope: "shop"` está selecionado. O admin escolhe uma única data; o cliente calcula `starts_at` = meia-noite dessa data e `ends_at` = meia-noite do dia seguinte (`frontend/src/utils/timezone.js`, nova função `addDays` — aritmética de calendário pura, sem relação com fuso horário, usada só para somar 1 dia à string `"YYYY-MM-DD"` antes de converter cada uma via `zonedWallTimeToUtcIso`), ambos no fuso da barbearia, depois convertidos para UTC como qualquer outro envio. O backend não diferencia esse caso de um intervalo personalizado — é só mais um `starts_at`/`ends_at` válido com `início < fim`.
- **Limitação documentada (não implementada de propósito):** um fechamento criado com `scope: "shop"` abrange os profissionais que existiam no momento da criação. Um profissional cadastrado depois não entra automaticamente em fechamentos já existentes — isso exigiria algum gatilho (`created` do model `Professional`) sincronizando grupos abertos, o que não foi pedido e adicionaria complexidade não solicitada nesta entrega. Registrado também em `docs/planejamento-barbearia-mvp.md` seção 14.
- A confirmação de remoção de um fechamento (`window.confirm`) lista os nomes de todos os profissionais cobertos e explica que eles serão liberados — texto montado a partir de `item.professionals`, já disponível na resposta do `GET`/`POST`, sem round-trip adicional.

### Leitura de `business_settings`

`GET /api/v1/admin/business-settings` foi adicionado nesta entrega — antes, `business_settings` só era lido internamente (para o lock). O frontend de bloqueios precisa do `timezone` da barbearia para converter data/hora digitada pelo admin em UTC sem depender do fuso do navegador (`frontend/src/utils/timezone.js`). Ainda **só leitura** — nenhuma tela ou endpoint de edição.

### Conversão de fuso horário no frontend (sem depender do navegador)

`frontend/src/utils/timezone.js` implementa duas conversões usando só `Intl.DateTimeFormat` (sem biblioteca de datas nova):

- `zonedWallTimeToUtcIso(data, hora, timezone)`: o admin digita uma data/hora que representa horário local **da barbearia** (não do navegador); a função calcula o offset de `timezone` naquele instante (via `Intl.DateTimeFormat` com `timeZone` explícito) e devolve o ISO 8601 em UTC que a API espera.
- `utcIsoToZonedParts(iso, timezone)`: o inverso, para exibir um `starts_at`/`ends_at` já em UTC como data/hora local da barbearia, de novo via `timeZone` explícito — nunca `new Date().toLocaleString()` sem fuso, que usaria o fuso do navegador da pessoa validando.

Isso é o que permite testar esta funcionalidade de qualquer fuso horário (navegador do desenvolvedor, CI, etc.) e ainda assim ver os horários certos em `America/Sao_Paulo`.

## Motor de disponibilidade

Calcula os horários livres de um profissional para um serviço numa data. A consulta (`GET .../availability`) é uma **sugestão de leitura**: não grava nada e não usa lock. A garantia de que duas reservas simultâneas não ocupam o mesmo intervalo **está no POST de criação** — ver "Criação pública de reservas" abaixo —, que chama o mesmo motor dentro da transação, depois dos locks.

- Código: `app/Services/Availability/AvailabilityEngine.php` (motor único) e `AvailabilityContext.php` (`Public`/`Admin`). As duas rotas usam o mesmo `AvailabilityController`, cada uma presa a um método que fixa o contexto; não existe parâmetro de contexto. A rota admin fica atrás de `auth:sanctum`.
- Contrato completo: `docs/planejamento-barbearia-mvp.md`, seção 16.

### Regras

| Regra | Público | Admin |
|---|---|---|
| Serviço e profissional ativos e vinculados (senão `422`) | sim | sim |
| Data dentro do horizonte: hoje até hoje + `booking_horizon_days` − 1, pela data local da barbearia | sim | sim |
| Antecedência mínima (`min_notice_minutes`), inclusiva no limite exato | sim | **não** |
| Nunca oferece início no passado (início igual a "agora" é aceito) | sim (implícito na antecedência) | sim |
| Duração inteira contida em **um** período de expediente (não atravessa almoço nem o fim) | sim | sim |
| Sem interseção com bloqueios (individuais e fechamentos) e reservas com `status` diferente de `cancelled` | sim | sim |

- **Intervalos com fim exclusivo** `[início, fim)`: há conflito quando `existente.starts_at < novo.ends_at` e `existente.ends_at > novo.starts_at`. Um atendimento pode começar exatamente quando o anterior (ou um bloqueio) termina.
- **Grade:** quartos de hora fixos do relógio local da barbearia (`:00`, `:15`, `:30`, `:45`). O início de cada período é arredondado para o próximo quarto (expediente às 09:10 → primeiro horário 09:15); a partir dele, os candidatos avançam de 15 em 15 minutos de tempo real.
- **Fuso:** a data e o dia da semana são interpretados no fuso de `business_settings.timezone`; cada horário de expediente é convertido para UTC naquele dia específico, então dias de mudança de horário de verão ficam com a duração real correta. Hora local inexistente (adiantamento do relógio) é resolvida pelo PHP para o próximo instante válido; hora ambígua (atraso do relógio), para a primeira ocorrência. `America/Sao_Paulo` não tem horário de verão desde 2019, mas o comportamento está coberto por testes com `America/New_York`.
- **Data fora do horizonte ou no passado:** `200` com `slots: []`, não erro — a data em si é válida, só não tem horários.
- A resposta segue a convenção dos outros endpoints, com o conteúdo dentro de `data` (`{ "data": { "timezone", "date", "slots" } }`), e instantes em UTC com `toIso8601String()` (`2026-11-03T12:00:00+00:00`). Nunca inclui motivo de bloqueio nem dado de reserva.
- **Rate limit público:** 60 requisições por minuto por IP (`throttle:public-availability`), com a mesma resposta `429 RATE_LIMITED` do login.

### Tabela `appointments`

Criada nesta entrega com a estrutura aprovada (`docs/planejamento-barbearia-mvp.md`, seção 4), e gravada pelo POST de criação pública (ver "Criação pública de reservas"); o motor a lê como tempo ocupado. `public_id` é ULID (`HasUlids` apontado para a coluna, a chave primária continua numérica); FKs de profissional e serviço com `restrictOnDelete`; índices `(professional_id, starts_at)`, `(customer_email, status, starts_at)`, `(customer_phone, status, starts_at)`; `public_id` e `idempotency_key` únicos. Status usados: `confirmed` e `cancelled`.

Consequência para o importador de dados de teste: `import:test-data --remove` agora recusa remover qualquer coisa se alguma reserva usa um profissional ou serviço da importação (as FKs também impediriam, mas com um erro de banco em vez de uma explicação). Essa verificação não tem teste automatizado: o comando grava o manifesto num caminho fixo do `storage/` real, que um teste sobrescreveria.

### Testando à mão

```bash
# público (sem sessão)
curl -s "http://localhost:8080/api/v1/public/availability?service_id=1&professional_id=1&date=2026-11-03"
# admin: mesma query em /api/v1/admin/availability, com sessão (ver "Autenticação administrativa")
```

Os dados de teste importados não têm expediente (o pacote não cria). Para ver horários, cadastre expediente em `/admin/expediente` para um profissional ativo vinculado ao serviço.

## Criação pública de reservas

`POST /api/v1/public/appointments` cria uma reserva confirmada, sem conta nem login. Contrato completo: `docs/planejamento-barbearia-mvp.md`, seção 17.

- Código: `app/Services/Booking/AppointmentBooker.php` (transação e regras), `StorePublicAppointmentRequest` (formato), `PublicAppointmentController` (resposta), `ContactNormalizer` (e-mail e telefone), `AgendaConflicts` (regras da seção 7 para bloqueios e expediente) e `App\Exceptions\BusinessConflictException` (erros `409`).

### Fluxo dentro da transação (proteção contra double booking)

A proteção contra duas reservas sobrepostas acontece **no POST, dentro de uma transação MySQL**, nesta ordem fixa:

1. `SELECT ... FOR UPDATE` no registro único de `business_settings` — **primeira instrução da transação**.
2. `SELECT ... FOR UPDATE` no profissional.
3. Idempotência: mesma chave e mesmo fingerprint devolvem a reserva existente (`200`); mesma chave com outro payload, `409 IDEMPOTENCY_KEY_REUSED`.
4. Revalidação com dados atuais: serviço e profissional ativos e vinculados (`422`) e `AvailabilityEngine::isSlotAvailable()` no contexto público (`409 SLOT_UNAVAILABLE`). É o mesmo cálculo do GET — expediente, duração, grade, bloqueios, reservas, antecedência, horizonte, fuso —, sem lógica repetida.
5. Teto por contato (`409 CONTACT_LIMIT_REACHED`).
6. `INSERT` com fim, snapshots, status e origem definidos pelo servidor; commit.

Por que o lock precisa ser a primeira instrução: no InnoDB em `REPEATABLE READ`, a "foto" de leitura da transação é criada na primeira leitura comum (sem lock). Se houvesse uma leitura comum antes do lock, a transação que esperou o lock continuaria lendo a foto antiga e não veria a reserva que a outra acabou de gravar. Isso foi comprovado com o teste de concorrência (ver "Testes de criação pública de reservas").

Todos os caminhos que alteram a agenda seguem a mesma ordem: lock de `business_settings`, depois do(s) profissional(is) em ordem de ID. Isso vale para a criação de reserva, bloqueios e expediente; nesta entrega, bloqueios e expediente passaram a travar também o profissional. Com uma ordem única, duas escritas nunca seguram os locks em ordens opostas. Deadlock e timeout de lock são repetidos até 3 vezes pelo `DB::transaction()`.

O lock global serializa todas as escritas da agenda da barbearia. É a escolha documentada para o MVP (seção 5 do planejamento) e é o que garante o teto por contato mesmo entre profissionais diferentes.

### Decisões

- **Telefone em E.164 sem biblioteca nova:** caracteres de formatação são removidos; `+` ou `00` no início indicam número internacional; 10 ou 11 dígitos sem prefixo são tratados como número brasileiro com DDD e recebem `+55`. Qualquer outro formato é recusado com `422`, sem palpite. Não é uma validação completa de plano de numeração.
- **E-mail:** trim e minúsculas, sem remover pontos ou aliases (planejamento, seção 1).
- **`starts_at` exige offset explícito** (`Z` ou `±hh:mm`): uma hora local sem fuso seria ambígua.
- **Horário fora da grade, do expediente, do horizonte ou no passado** responde `409 SLOT_UNAVAILABLE`, o mesmo código de "alguém reservou antes". Para a interface, a ação é a mesma: atualizar os horários e manter os dados do cliente.
- **Fingerprint:** SHA-256 de origem (`public`), serviço, profissional, início em UTC e contatos canônicos. A chave fica presa à intenção e ao contexto, então não pode ser reaproveitada com outro payload nem entre público e admin.
- **Resposta sem e-mail e telefone**, com o serviço descrito pelos snapshots.
- **Rate limit:** 10 POSTs por minuto por IP (`throttle:public-appointments`); replays contam.
- **E-mail de confirmação e cancelamento:** ver "Confirmação por e-mail e cancelamento" abaixo. A resposta traz `notification_status` (`pending`, `sent`, `failed` ou `skipped`).

### Regras da seção 7 (agora que reservas existem)

- **Bloqueio sobre reserva:** criar um bloqueio, individual ou fechamento da barbearia, que cruze uma reserva não cancelada é recusado com `409 APPOINTMENT_CONFLICT`. A mensagem lista os conflitos com data e hora no fuso da barbearia e o array `error.conflicts` traz `public_id`, profissional, cliente e intervalo. Nada é cancelado e nenhum bloqueio é criado.
- **Redução de expediente:** um `PUT` de expediente que deixe uma reserva futura confirmada fora de qualquer período do dia é recusado com `409 APPOINTMENT_CONFLICT`, e a alteração inteira é desfeita.
- O texto da seção 7 fala em "reserva confirmada" para bloqueios e "reserva futura confirmada" para expediente. Por isso, bloqueios consideram qualquer reserva não cancelada, inclusive passada, e o expediente só considera reservas futuras.
- O cliente pode cancelar a própria reserva pelo link do e-mail; o cancelamento **pelo admin** ainda não existe, então um bloqueio sobre uma reserva só pode ser criado depois que o cliente cancelar.
- O frontend já mostra a mensagem traduzida que vem do backend (`error.message`). A lista estruturada em `error.conflicts` ainda não tem tela própria.

### Testando à mão

```bash
curl -s -X POST http://localhost:8080/api/v1/public/appointments \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"service_id":1,"professional_id":1,"starts_at":"2026-11-03T10:00:00-03:00","customer_name":"Cliente","customer_email":"cliente@example.com","customer_phone":"(11) 99999-0000"}'
```

Para conseguir um `201`, o profissional precisa ter expediente cadastrado para o dia; os dados de teste importados não têm. Repetir o mesmo comando com a mesma chave devolve `200` com a mesma reserva; com outra chave, `409 SLOT_UNAVAILABLE`.

## Confirmação por e-mail e cancelamento

Depois de criada, a reserva pública recebe um e-mail de confirmação com um link para o próprio cliente cancelar, sem login (`docs/planejamento-barbearia-mvp.md`, seções 5 e 6; contrato na seção 18).

### Fluxo pós-reserva

1. Dentro da transação da reserva, junto do `INSERT`, é gravada uma `appointment_notifications` (`kind=confirmation`, `status=pending`). Se a transação for desfeita, ela some junto: não existe confirmação sem reserva.
2. **Depois do commit**, o controller entrega o e-mail (`ConfirmationNotifier`). A entrega é registrada com `DB::afterCommit()`, que roda na hora quando não há transação aberta e esperaria o commit mais externo se a chamada um dia ficasse dentro de uma. O e-mail nunca sai antes de a reserva estar gravada: `ConfirmationAfterCommitTest` confere, por uma segunda conexão MySQL, que a reserva já está visível no momento do envio.
3. O resultado vai para a notificação: `sent` (com `sent_at`); `failed`, com só a **classe** da exceção em `last_error_code`, nunca a mensagem, que pode ter endereços; ou `skipped`, se na hora do envio a reserva não estiver mais confirmada e futura. Uma falha de e-mail **não desfaz a reserva**.
4. A varredura `php artisan appointments:send-pending-confirmations` reenvia o que ficou `pending` ou `failed`. Ela está agendada a cada minuto em `routes/console.php`.
   - Só pega notificações sem atualização há 2 minutos, para não disputar com o envio da própria requisição.
   - Tenta no máximo 5 vezes.
   - Roda no serviço `scheduler` do Compose, que sobe junto com `docker compose up -d --build` (ver "Scheduler").
5. Duas entregas da mesma notificação nunca enviam duas vezes: cada uma reivindica a linha com um `UPDATE` condicional em `attempts`, e só a que mudou a linha envia. Um provedor externo ainda pode entregar em dobro após um timeout, o que o planejamento aceita.
6. **Retry da criação** com o mesmo `Idempotency-Key` devolve a reserva existente (`200`) e **não** envia outro e-mail; a resposta mostra o status atual da notificação.

O envio é **síncrono** na requisição, depois do commit, e não por fila. O projeto não tem worker de fila no compose, e um job na fila nunca seria executado. A varredura cobre o caso de a requisição morrer entre o commit e o envio.

O SMTP tem **timeout de 5 s** (`MAIL_TIMEOUT`, padrão 5, em `config/mail.php`). Antes ele não tinha limite próprio e herdava o `default_socket_timeout` do PHP (60 s). Com um provedor lento, a requisição de reserva ficava presa e o proxy respondia `504` ao cliente, **embora a reserva já estivesse gravada e confirmada**. Reproduzido nesta entrega com o Mailpit pausado: 60 s e `504` antes da correção; `201` em 8 s com `notification_status: "failed"` depois dela, e o e-mail recuperado pela varredura.

### Garantia real de entrega do e-mail

A entrega é **pelo menos uma vez, com tentativas limitadas**, e não "exatamente uma vez". O que cada caso garante:

| Situação | O que acontece | Garantia |
|---|---|---|
| Replay idempotente da criação (mesma `Idempotency-Key`) | Devolve a reserva existente; nenhuma notificação nova, nenhum envio | Nenhum e-mail a mais (testado) |
| Duas tentativas de envio concorrentes da mesma notificação (requisição × varredura, ou duas varreduras) | Cada uma faz um `UPDATE` condicional em `attempts`; só a que muda a linha envia. A varredura só pega linhas sem atualização há 2 min, e o SMTP tem timeout de 5 s, então a requisição já terminou quando a varredura as vê | Um envio só (testado) |
| O SMTP aceitou a mensagem, mas o processo morreu antes de gravar `sent` | A notificação fica `pending` ou `failed` com a tentativa contada; a varredura reenvia depois de 2 min | **Pode duplicar.** Risco residual aceito no MVP (seção 6 do planejamento: "entrega externa pode se repetir"). Evitá-lo exigiria um protocolo com o provedor (por exemplo, chave de idempotência no envio), desproporcional agora |
| Falha de SMTP | Reserva intacta; `failed` com só a classe do erro; a varredura tenta até 5 vezes | Recuperação automática enquanto houver tentativas; depois disso, o status fica `failed` sem alerta |
| Reserva cancelada ou já iniciada antes do envio | `skipped`, nada é enviado | Nenhuma confirmação obsoleta (testado). A checagem é imediatamente antes do envio, então um cancelamento no mesmo instante ainda pode deixar sair o e-mail |

**Nenhum envio segura lock ou transação.** O envio da requisição acontece depois do commit (`DB::afterCommit`), e a varredura não abre transação. `ConfirmationAfterCommitTest` confere, nos dois caminhos e no instante do envio, que não há transação aberta e que outra conexão consegue `SELECT ... FOR UPDATE NOWAIT` em `business_settings`: o SMTP nunca trava a agenda da barbearia.

### O e-mail

- `App\Mail\AppointmentConfirmationMail`, template Markdown do Laravel em `resources/views/mail/appointment-confirmation.blade.php`, sem biblioteca externa. Em desenvolvimento chega no Mailpit (http://localhost:8025).
- Conteúdo: nome do cliente, serviço, profissional, data, horário, duração, preço, código público, endereço e telefone da barbearia (quando cadastrados) e o botão de cancelamento.
- Serviço, duração e preço vêm dos **snapshots**: editar o serviço depois não muda o que o e-mail diz. Data e hora aparecem no fuso da barbearia.
- Idioma: o da requisição que criou a reserva (`Accept-Language`). Um reenvio pela varredura não tem requisição e sai no padrão, `pt_BR`.

### Link de cancelamento

- Formato: `{APP_URL}/cancelar/{public_id}?expires={timestamp}&signature={hmac}`. É uma rota Laravel fora de `/api`; o proxy encaminha `/cancelar/` para o backend.
- `URL::temporarySignedRoute()` com a chave da aplicação, **expirando no início da reserva**. Nada do link é guardado no banco; ele é assinado de novo a cada envio.
- A assinatura é **relativa** (caminho + query), validada com `signed:relative`. Atrás do proxy, o backend não recebe o host público com a porta, e uma assinatura absoluta nunca bateria. Assinar o caminho já prende o link a esse `public_id` e a essa expiração: trocar qualquer um invalida a assinatura. O host do link vem de `APP_URL`.
- Usa o `public_id` (ULID), nunca o ID interno.

### Regras do cancelamento

- **GET** só mostra um resumo mínimo (serviço, profissional, data, horário, código), sem nome, e-mail ou telefone, e **nunca altera nada**. Um scanner de e-mail que abra o link não cancela.
- **POST** (mesmo caminho e query assinados, com CSRF) cancela e redireciona (`303`) para o mesmo GET assinado, que mostra "Reserva cancelada".
- Cancelar grava `status=cancelled`, `cancelled_at` e `cancelled_by=customer`. Não apaga a reserva nem toca em snapshots ou contatos, e o horário volta à disponibilidade. Usa a mesma ordem de locks das outras escritas (`business_settings`, profissional, reserva).
- **Idempotente:** repetir o POST não muda nada, nem `cancelled_at`, e a página mostra "já está cancelada".
- **Prazo:** só é possível cancelar enquanto `agora < início − cancel_min_notice_minutes`. Com o padrão 0, até o início. Depois do prazo, mas antes do início, o link ainda abre e explica que o prazo acabou. Depois do início, o link expira.
- **Assinatura inválida, alterada, ausente, expirada, ou o `public_id` trocado** resultam em `403`. Um link assinado para uma reserva inexistente resulta em `404`. As duas respostas mostram **a mesma página genérica** ("link inválido ou expirou"), sem revelar se a reserva existe.
- Proteções da página: `Referrer-Policy: no-referrer`, `Cache-Control: no-store`, `X-Robots-Tag: noindex` e uma CSP sem `script-src`, então nenhum script roda. O access log do nginx para `/cancelar/` grava só o caminho, sem a query, e portanto **sem a assinatura**. Rate limit: 30 por minuto por IP.
- Em desenvolvimento, o Laravel Boost, um pacote só de dev, injeta um script em toda página HTML quando `APP_ENV=local`. A CSP impede que ele rode nessas páginas.

### Testando à mão (verificado nesta entrega)

1. Cadastre expediente para um profissional ativo vinculado a um serviço ativo.
2. Faça o POST de criação (ver "Criação pública de reservas"): `notification_status` deve vir `sent` e o e-mail deve aparecer em http://localhost:8025.
3. Abra o botão "Cancelar reserva" do e-mail e confirme. A página deve mostrar "Reserva cancelada", e o horário deve voltar em `GET /api/v1/public/availability`.

## Scheduler

O serviço `scheduler` do `docker-compose.yml` roda `php artisan schedule:work` com a mesma imagem, o mesmo código montado e o mesmo ambiente do `backend`, compartilhados por uma âncora YAML. Ele sobe com `docker compose up -d --build`, depois de `mysql`, `mailpit` e `backend` saudáveis.

- **Um só:** o `container_name` fixo faz o Compose reaproveitar o mesmo container em todo `up` e `restart`, e recusar `--scale` (verificado: depois de `up`, `restart` e `--scale scheduler=2`, continua um container com um único processo `php artisan schedule:work`).
- **Tarefa sem sobreposição:** `appointments:send-pending-confirmations` roda a cada minuto, com `withoutOverlapping(10)` e `onOneServer()` (`routes/console.php`). A trava fica no cache (`CACHE_STORE=database`, tabela `cache_locks`).
  - **Defeito corrigido:** o padrão do Laravel mantém a trava de sobreposição por 1440 minutos, e ela só é liberada ao fim da execução ou por sinal. A imagem do backend **não tem `pcntl`**, então nenhum sinal a libera: um `docker compose stop`/`restart` (ou queda) no meio de uma varredura bloquearia a recuperação de e-mails por até 24 horas. Agora a trava expira em 10 minutos (`ScheduleTest`). Se uma varredura com SMTP travado passar disso e a seguinte se sobrepuser, ainda não há envio duplicado, por causa da reivindicação por `UPDATE`.
- **PID 1 é o `tini`** (`init: true`), que repassa o `SIGTERM`. O healthcheck usa `pgrep -f '^php artisan schedule:work'`, ancorado para não casar com o próprio `tini`.
- **Comandos:**
  - `docker compose ps scheduler`
  - `docker compose logs -f scheduler`, que mostra `Running ['artisan' appointments:send-pending-confirmations] ... DONE` a cada minuto
  - `docker compose exec scheduler php artisan schedule:list`
- **Verificado nesta entrega com dados identificados:**
  1. Com o Mailpit **pausado** (`docker compose pause mailpit`, que preserva as mensagens existentes), uma reserva para `scheduler-check-<timestamp>@example.com` respondeu `201` em 8 s, com `notification_status: "failed"`.
  2. O Mailpit foi retomado. A primeira varredura depois da folga de 2 minutos enviou o e-mail: tentativa 2, `sent`, exatamente 1 mensagem para o identificador.
  3. Depois só essas reservas (#3 e #4), suas notificações e suas mensagens no Mailpit foram removidas, por ID. Os dados de negócio do banco de desenvolvimento voltaram ao mesmo checksum da linha de base.
- **Não há worker de fila nem Redis:** a fila continua `database` e sem consumidor, e nada do projeto depende dela.

## Telas públicas de agendamento

A homepage (`/`) e a jornada `/agendar` usam só os endpoints públicos. Não há conta, e o cliente nunca vê serviços ou horários fictícios. O frontend não recalcula nenhuma regra de agendamento: a disponibilidade vem sempre do motor, e a criação passa sempre pelo POST com revalidação na transação.

### Endpoints de catálogo (novos)

`GET /api/v1/public/business`, `GET /api/v1/public/services` e `GET /api/v1/public/services/{id}/professionals` (`PublicCatalogController`, contrato na seção 19 do planejamento), com rate limit de 60 por minuto por IP.

As respostas são mínimas:
- **barbearia:** nome, endereço, telefone, fuso e horizonte;
- **serviços:** só os ativos que ao menos um profissional ativo oferece, para não levar o cliente a um beco sem saída;
- **profissionais:** só os ativos que oferecem aquele serviço.

Nenhuma configuração administrativa, flag interna ou contato de cliente aparece nelas.

### Homepage

Mostra o nome, o endereço e o telefone da barbearia (quando cadastrados) e os serviços agendáveis com duração e preço. Cada serviço tem um link para `/agendar?servico={id}`, que já chega com o serviço escolhido. A antiga verificação de status da API (`api/health.js`, da fundação técnica) saiu da homepage; o endpoint `/up` continua no backend.

### Jornada `/agendar`

Etapas: **serviço → profissional → dia e horário → seus dados → revisão → confirmação**. No celular, as etapas e o resumo ficam empilhados; a partir de 900 px, o resumo fica ao lado, com "A escolher" no que ainda falta.

- **Fuso:** os dias oferecidos vão de hoje até hoje + horizonte − 1, no calendário da barbearia, e os horários aparecem no fuso dela. A data e a hora nunca dependem do fuso do navegador.
- **Dependências:**
  - trocar o serviço limpa o profissional e o horário;
  - trocar o profissional ou o dia limpa o horário **na hora**, antes mesmo de a nova consulta responder, então "Continuar" fica desabilitado enquanto carrega;
  - um horário que não está na resposta atual da disponibilidade é descartado.
- **Respostas fora de ordem:** cada consulta de profissionais e de horários leva um número de sequência. Só a mais recente é aplicada, e uma resposta antiga que chegue depois é ignorada.
- **Estados:** carregando, vazio e falha com "Tentar novamente", em cada consulta. Cada resposta de profissionais e de horários fica guardada junto da consulta que ela responde; se a consulta atual é outra, a tela lê "carregando". Assim, trocar uma escolha não precisa de nenhum `setState` dentro de efeito, e o "Tentar novamente" volta ao carregamento pelo próprio clique.
- **Estado só em memória.** Voltar etapas e trocar o idioma preservam todas as escolhas e o que foi digitado. Nada pessoal vai para `localStorage`; só o idioma, como antes. Os carregamentos não dependem de `t`, para a troca de idioma não recarregar a tela.
- **Acessibilidade:**
  - cada etapa move o foco para o seu título;
  - erros saem em `role="alert"` e carregamentos em `role="status"`;
  - as escolhas são botões com `aria-pressed`, e as etapas usam `aria-current="step"`;
  - os campos têm `label`, `autocomplete`, `aria-invalid` e mensagem de erro associada;
  - todos os controles têm foco visível.

### Identidade visual

Referências em `docs/design/`:
- `old-barber.png`: identidade original;
- `2.png`: agendamento público;
- `3.png`: cancelamento;
- `1.png`: painel administrativo. Ele só orientou os componentes compartilhados; a agenda administrativa fica para outra entrega.

As referências são pranchas de apresentação: as molduras de celular, os títulos de prancha e os vários estados lado a lado **não** foram reproduzidos. Cada estado aparece só quando é real, e nomes, preços, datas e horários vêm sempre da API.

- **Tema compartilhado:** `frontend/src/styles/barber-theme.css`. Ele tem os tokens (creme, bordô, carvão, latão, superfícies, sombras e raios), a serifa expressiva para títulos e botões e uma fonte de leitura simples para textos, campos e horários. Também traz os botões primário, secundário e de perigo, o foco visível, a bolha de ícone e o cartão. Tudo fica sob a classe `.barber-theme`, sem nenhum seletor global: só as telas que adotam a classe mudam. Hoje são a homepage e `/agendar` (`.public-shell barber-theme`); a agenda administrativa pode adotá-la na sua entrega. O layout específico das telas públicas fica em `src/pages/public.css`.
- **Fontes:** nenhuma fonte web. A serifa é uma pilha do sistema (Iowan Old Style, Palatino Linotype, Palatino, Book Antiqua, Georgia), porque as páginas de cancelamento proíbem recursos externos pela CSP e o projeto não ganha dependência nova. O desenho final varia um pouco entre sistemas operacionais.
- **Cabeçalho público:** carvão, com tesoura e marca em serifa, um divisor de latão e "Agendamento online". O idioma aparece como texto creme, com sublinhado de latão no idioma ativo. Antes, o botão "English" inativo tinha texto carvão sobre fundo carvão (contraste 1,00:1, parecia vazio). Medido no Edge pelo proxy, agora tem 14,54:1 nos estados normal, ativo, hover e foco, e o foco ganha um contorno de latão de 3 px. Em telas estreitas, o cabeçalho quebra a linha em vez de empurrar o idioma para fora.
- **Jornada:**
  - etapas concluídas com check;
  - no celular, os 5 rótulos ficam sob os marcadores; abaixo de 360 px só o rótulo da etapa atual aparece, e os demais seguem no nome acessível;
  - carrossel de dias com setas e rolagem (os dias já respondidos sem horário ficam tracejados);
  - grade de horários e estado vazio em cartão tracejado;
  - resumo com ícones;
  - "Continuar" com seta.
- **Homepage:** apresentação com o poste de barbearia, o nome e "Agendar horário", e os serviços em cartões responsivos (grade no desktop, cartões compactos no celular).
- **Cancelamento (Blade):** `cancellation/layout.blade.php` repete os mesmos tokens inline, porque a CSP só permite estilo inline. Os ícones são SVG inline (`cancellation/icon.blade.php`). Os estados são:
  - **confirmar:** ícone de calendário, "Cancelar reserva?", resumo com ícones (serviço com duração e preço, profissional, data e horário com o dia da semana) e o código da reserva. Os botões são "Manter reserva" (link para `/`) e "Confirmar cancelamento" (o mesmo POST de antes);
  - **cancelada:** check verde, "Reserva cancelada", "O horário foi liberado." e "Agendar novamente" (link para `/agendar`);
  - **já cancelada** e **prazo encerrado:** resumo e mensagem própria;
  - **link inválido:** só a mensagem e um link para a página inicial.

  Os links novos são navegação simples e não mudam nada. O seletor de idioma e o lema decorativo da prancha não foram adicionados: o idioma da página continua vindo do navegador.
- **Correção encontrada nas capturas:** no celular, o carrossel de dias definia a largura da coluna da grade, e a página estourava para a direita, cortando o texto dos botões. Resolvido com `grid-template-columns: minmax(0, 1fr)`. Um e-mail longo no aviso da confirmação também estourava; agora quebra a linha.

### Confirmação e idempotência

- **Chave por tentativa:** cada tentativa lógica tem uma `Idempotency-Key` (UUID) presa ao payload exato. Um retry com o mesmo payload reutiliza a chave, inclusive depois de falha de rede, timeout ou 5xx, e o backend devolve a mesma reserva sem duplicar. Se qualquer campo mudar, a chave é nova.
- **CSRF antes do POST:** o POST sai do mesmo domínio, então o Sanctum o trata como *stateful* e exige CSRF. O frontend busca `/sanctum/csrf-cookie` antes de cada envio. Verificado pelo proxy: sem o cookie, a resposta é `419 SESSION_EXPIRED`.
- **Clique repetido:** o botão fica desabilitado durante o envio (`aria-busy`), e só uma requisição sai.
- **Respostas da API:**

| Resposta | O que a tela faz |
|---|---|
| `409 SLOT_UNAVAILABLE` | Explica o conflito, volta ao passo de horário, consulta os horários de novo e mantém os dados do cliente |
| `422` em campos do cliente | Volta a "Seus dados" com os erros nos campos |
| `422` em serviço ou profissional | Volta ao serviço e recarrega o catálogo |
| `409 CONTACT_LIMIT_REACHED` | Explica o limite por contato |
| `429` | Pede para aguardar |
| `419` | Permite tentar de novo |
| Rede, timeout ou 5xx | Avisa que não foi possível confirmar se a reserva foi registrada e oferece "Tentar confirmar novamente" com a mesma chave |

- **Reserva e e-mail são coisas separadas.** A reserva está confirmada assim que a API responde `201` ou `200`. O texto do e-mail depende de `notification_status`:
  - `sent`: "enviamos a confirmação... confira o spam", sem prometer que chegou;
  - `failed` ou `pending`: a reserva está confirmada, o e-mail ainda não pôde ser enviado e haverá nova tentativa;
  - `skipped`: nenhum e-mail foi enviado.

  Uma falha de e-mail nunca aparece como falha da reserva.

### Testes e verificação

- `frontend/src/pages/booking/Booking.test.jsx` (18): usa o `App`, o roteador e o cliente HTTP reais, e troca só o `fetch` por um servidor falso. Cobre:
  - a jornada completa, com horário no fuso da barbearia e CSRF antes do POST;
  - a dependência entre escolhas, inclusive durante o carregamento;
  - a resposta fora de ordem ignorada;
  - o conflito com atualização dos horários e dados mantidos;
  - o retry com a mesma chave e a chave nova quando o payload muda;
  - o clique repetido com um só POST;
  - os erros de validação nos campos e o limite por contato;
  - a notificação `failed` com a reserva confirmada;
  - a troca de idioma preservando tudo;
  - nada pessoal no `localStorage`;
  - o serviço pré-selecionado, o descarte de um serviço pré-selecionado que não é mais agendável e o retry do catálogo;
  - o retry dos horários, voltando a "carregando" enquanto a nova consulta não responde;
  - a preferência por English escolhida na homepage mantida ao seguir para `/agendar` (rotas reais).
- **Defeitos introduzidos de propósito no `Booking.jsx`:** aceitar resposta antiga, chave nova a cada tentativa e não limpar o horário ao trocar de dia. Os três derrubaram testes. O terceiro só passou a ser detectado depois de reforçar o teste para segurar a resposta do novo dia; antes, o horário antigo ficava selecionado durante o carregamento sem nenhum teste falhar.
- `Home.test.jsx` (3): catálogo real com links para a jornada, estado vazio e retry.
- `tests/Feature/PublicCatalogTest.php` (6): respostas exatas e mínimas, filtros de ativo e vínculo, `404` para serviço inativo ou inexistente, nenhum dado de cliente, rate limit.
- `tests/Feature/PublicCancellationTest.php`: as asserções acompanham a nova página. Cobrem o título "Cancelar reserva?", a duração e o preço do snapshot, a data sem o dia da semana mais o dia separado ("Terça-feira"), os links "Manter reserva" e "Agendar novamente" e os mesmos textos em inglês. As garantias anteriores continuam: nenhum dado de contato, GET sem efeito e nenhum `<script>`.
- **Resultados:**
  - frontend com **118 passed**;
  - `npm run lint` com 0 erros e 6 avisos. Os 5 avisos `set-state-in-effect` que esta entrega tinha criado (`Home.jsx` e `Booking.jsx`) foram resolvidos. Os 6 restantes já existiam, em `AuthContext.jsx` e nas telas administrativas de serviços, profissionais, expediente e bloqueios, que estão fora do escopo desta entrega e não foram tocadas;
  - build OK;
  - backend com **198 passed** e Pint OK.
- **Verificado pelo proxy real**, com o banco de desenvolvimento e os dados importados:
  - `/agendar` é servida pela SPA;
  - o catálogo traz 13 serviços agendáveis (os 15 importados menos os 2 inativos) e os profissionais ativos do serviço;
  - a disponibilidade de um dia com expediente funciona;
  - o POST *stateful* sem cookie CSRF dá `419`, e com o cookie dá `201` com `notification_status: sent`;
  - o retry com a mesma chave dá `200` com o mesmo `public_id`;
  - outra chave no mesmo horário dá `409 SLOT_UNAVAILABLE`;
  - exatamente 1 e-mail no Mailpit.

  Os dados criados (reserva #5, a notificação dela e as mensagens do identificador `ui-check-…`) foram removidos por ID, e as 14 tabelas de negócio voltaram ao checksum da linha de base.
- **Revisão visual em navegador real:** Microsoft Edge headless, guiado pelo `playwright-core`, contra o proxy em `localhost:8080`, em 1280×900 e 390×844, mais medições de estouro em 320 px. O script ficou fora do repositório.
  - **Homepage e jornada:** capturas de cada etapa no desktop e no celular, do serviço à confirmação. Só duas respostas foram simuladas no navegador: o dia sem horários e o POST de confirmação. Nada foi gravado no banco.
  - **Largura:** nenhum estouro horizontal em 390 px nem em 320 px.
  - **Idioma:** o contraste de "English" e "Português" foi medido nos quatro estados (14,54:1). Escolher English na homepage e seguir para `/agendar` mantém "Choose the service", tanto na navegação da SPA quanto depois de recarregar.
  - **Cancelamento:** o estado de confirmação foi capturado pelo proxy com o link assinado real de uma reserva existente, só com GET (nada muda). A CSP está presente, e a ordem do teclado é "Manter reserva" → "Confirmar cancelamento" com foco visível. O link com assinatura inválida dá `403` com "Link indisponível". Os estados cancelada, já cancelada e prazo encerrado foram renderizados, sem alterar nenhuma linha, a partir das views com os dados da mesma reserva, e capturados em PT e EN no desktop e no celular.
- **Limite das capturas:** elas comparam composição, hierarquia, cores e estados com as referências, mas o resultado não é idêntico pixel a pixel. A serifa do sistema difere da serifa da prancha, os textos vêm da aplicação e a proporção dos componentes segue o conteúdo real.

### Roteiro manual no navegador

1. Cadastre o expediente de um profissional ativo vinculado a um serviço ativo (os dados importados não têm expediente).
2. Em http://localhost:8080, confira o nome, os serviços com duração e preço e o botão "Agendar horário". Clique em "Agendar este serviço" num serviço: a jornada deve abrir com ele já escolhido.
3. Avance até os horários. Troque de dia: o horário escolhido deve sumir do resumo, e "Continuar" deve ficar desabilitado até escolher outro.
4. Preencha seus dados, troque para English e volte para Português: tudo deve continuar preenchido.
5. Confirme. A tela deve mostrar "Reserva confirmada", o código e a frase sobre o e-mail, e o e-mail deve aparecer em http://localhost:8025.
6. Em outra aba, reserve o mesmo horário: deve aparecer o aviso de conflito, a lista de horários deve ser atualizada e os dados devem continuar.
7. Abra o link de cancelamento do e-mail: confira o resumo com ícones e os dois botões. Confirme: deve aparecer "Reserva cancelada" com "Agendar novamente".
8. Repita em largura de celular (cerca de 375 px) e só com o teclado (Tab, Enter, Espaço): o foco deve ir para o título de cada etapa.

## Importação de dados de teste fictícios

Comando manual (`backend/app/Console/Commands/ImportTestData.php`) para popular o banco de desenvolvimento com um pacote fixo de 15 serviços e 12 profissionais fictícios (`dados-teste-barber-booking`, mantido fora do repositório). **Nunca roda no boot, seed padrão ou CI** — é estritamente `php artisan import:test-data`, à mão.

```sh
docker compose exec backend php artisan import:test-data --dry-run   # valida e mostra a contagem prevista, sem escrever
docker compose exec backend php artisan import:test-data             # importa de fato
docker compose exec backend php artisan import:test-data --remove    # remove só os registros criados por esta importação
```

Por padrão lê `storage/app/test-data/{servicos,profissionais}.csv` (caminho configurável via `--path`) — esses CSVs e o manifesto de idempotência (`storage/app/test-data-import-manifest.json`) ficam fora do Git (`.gitignore`), por serem fixtures locais de quem está validando, não parte do código.

- **Validação completa antes de qualquer escrita:** `test_code` único, `name` presente, `duration_minutes` inteiro 1–1440, `price` no mesmo formato decimal-string aceito pela API (`\d{1,8}(\.\d{1,2})?`), `is_active` em `{0,1}`, e todo `service_codes` de `profissionais.csv` referenciando um `test_code` existente em `servicos.csv`. Qualquer erro aborta a importação inteira (nada é escrito) e lista todos os problemas encontrados, não só o primeiro.
- **Ordem e lock:** serviços são criados antes de profissionais (para resolver `service_codes` → IDs reais), e a escrita roda dentro de `DB::transaction()` travando `business_settings` como primeira operação — mesma estratégia já usada em `ServiceController`/`ProfessionalController`, sem lock adicional por profissional.
- **Idempotência sem `test_code` no banco:** como o schema do domínio não tem (nem deveria ter) uma coluna `test_code`, o comando mantém um manifesto JSON local mapeando `test_code → id` criado, junto com uma identificação do banco de destino (conexão, nome, host, porta). Rodar o comando de novo pula qualquer `test_code` já presente no manifesto (depois de confirmar que o registro com aquele id ainda existe e tem o mesmo nome) — não cria duplicata.
- **Colisão por nome com registro preexistente:** se um `name` do CSV já existir no banco **e não estiver no manifesto** (ou seja, não foi este importador que o criou), o comando para imediatamente com uma mensagem explicando a colisão — nunca presume posse de um cadastro existente só porque o nome bate. Resolvido manualmente uma vez nesta entrega: o banco de desenvolvimento já tinha um serviço "Corte degradê" de testes manuais anteriores; o CSV local (não o pacote original) foi ajustado para `"Corte degradê — teste"` em `SRV02`, preservando o `test_code` e todas as referências de `service_codes` dos profissionais.
- **Banco de destino errado:** se o manifesto existente aponta para uma conexão/banco diferente do atual, o comando recusa reutilizá-lo (tanto para importar quanto para `--remove`) em vez de arriscar IDs de outro banco.
- **`--remove`:** apaga apenas os registros cujos IDs estão no manifesto (depois de confirmar a mesma identidade de banco), avisa quantos períodos de expediente/bloqueios de cada profissional serão removidos em cascata pela FK (nenhum nesta entrega, já que a importação não cria expediente/bloqueios), e então apaga o arquivo de manifesto.

Execução registrada nesta entrega (banco `barber_booking`, desenvolvimento): 15 serviços criados (ids 9–23) e 12 profissionais criados (ids 5–16); nenhum administrador, cadastro preexistente ou dado de outra entrega foi alterado. Re-executar o comando sem `--remove` confirma idempotência (`Serviços criados: 0`, `Profissionais criados: 0`, todos os 27 `test_code` listados como já importados).

## Testes

Frontend (Vitest):

```sh
cd frontend
npm install
npm run test
npm run build   # verificação de build de produção
```

73 testes no total (Vitest + Testing Library): `Home` (3), cliente HTTP/CSRF (`api/client.test.js`, 6), conversão de moeda (`utils/money.test.js`, 6), conversão de fuso horário e aritmética de data (`utils/timezone.test.js`, 6 — ver seção "Expediente semanal e bloqueios"), `Login` (7), `ProtectedRoute` (4), `Agenda` (3), `App.test.jsx` (5 — integração contra o `App`/router real, ver abaixo), `Services` (6 — carregando/lista, vazio, erro com retry, criar convertendo `45,90` → `"45.90"`, validação preservando os campos, editar pré-preenchendo e enviando `PATCH`), `Professionals` (8 — lista com serviços vinculados, vínculo com serviço desde então inativo mostrando "(inativo)", vazio, criar com serviços selecionados, criar sem nenhum serviço, validação preservando valores, editar pré-marcando os checkboxes certos, desativar), `WorkingHours` (5 — dia de folga, períodos de almoço já salvos exibidos corretamente, adicionar/remover período e salvar, erro de validação agrupado pelo dia afetado, erro de carregamento com retry) e `ScheduleBlocks` (14 — vazio, listagem de bloqueio individual e de fechamento geral lado a lado, criação individual com conversão local→UTC, texto explicativo e campo de profissional escondido ao selecionar "Fechamento da barbearia", criação de fechamento com intervalo personalizado, criação de fechamento "Dia inteiro" conferindo a conversão meia-noite-a-meia-noite, fechamento aparecendo como um único item com ação "Remover fechamento", validação preservando valores, confirmação/cancelamento de remoção tanto para bloqueio individual quanto para fechamento — a confirmação do fechamento é conferida pelo texto que lista os profissionais liberados —, e remoção de um fechamento não afeta um bloqueio individual não relacionado). `utils/timezone.test.js` ganhou 2 testes de `addDays` (6 no total). Esta entrega acrescenta: `money.test.js` (18 — funções locale-aware `toMoneyInput`/`parseMoneyInput`/`swapMoneySeparator`), `i18n/index.test.js` (5 — `resolveInitialLanguage`: preferência salva, fallback por idioma do navegador, idioma salvo inválido, sem idioma de navegador), `App.test.jsx` (+3 — troca de idioma na homepage sem recarregar a página, na tela de login preservando o e-mail digitado, e no painel preservando a sessão autenticada), `Services.test.jsx` (+2 — preço preservado ao trocar de idioma, incluindo uma entrada incompleta) e `ScheduleBlocks.test.jsx` (+1 — mesmo instante UTC enviado para um fechamento "dia inteiro" independente do idioma da interface). Resultado desta entrega: **96 passed**, build de produção OK. A revisão de fechamento desta entrega acrescentou: `WorkingHours.test.jsx` (+2 — trocar de idioma preserva um período digitado e ainda não salvo sem recarregar dados, e preserva o profissional selecionado), `api/client.test.js` (+2 — falha de rede e resposta de erro sem corpo `error` usam mensagem traduzida, nunca o nome cru da chave i18n) e `ScheduleBlocks.test.jsx` (+1 — rótulo do campo de profissional e aviso de "nenhum profissional cadastrado" traduzidos). Os testes de rota de `/admin/expediente` e `/admin/bloqueios` em `App.test.jsx` passaram a aguardar o carregamento da página terminar, como os de serviços/profissionais já faziam. Resultado final: **101 passed**, sem falhas em 30 execuções seguidas da suíte completa; build de produção OK; `npm run lint` com 0 erros (6 avisos `set-state-in-effect` já existentes antes desta revisão).

`frontend/src/App.test.jsx` cobre agora também `/admin/expediente` e `/admin/bloqueios` pelo router real (`BrowserRouter` + `AdminArea`), não só as páginas isoladas com `MemoryRouter` próprio — confirma que a navegação e o conteúdo de cada página aparecem quando acessadas diretamente pela URL, o que teria pego a regressão de Vite citada em "Solução de problemas comuns".

**Achado durante os testes desta entrega (bug de aplicação, não de teste):** `WorkingHours.jsx` inicializava `loading` como `false`, então o formulário chegava a renderizar por um instante com a semana vazia padrão antes do primeiro carregamento terminar — inofensivo visualmente (o carregamento é rápido), mas fazia um teste que verificava conteúdo já carregado falhar de forma intermitente, dependendo de timing. Corrigido inicializando `loading` como `true` (só mostra o formulário depois do primeiro `GET` responder).

Backend (PHPUnit, dentro do container, usando o MySQL real do Compose):

```sh
docker compose exec backend php artisan test
```

### Ambiente de testes (isolamento do desenvolvimento)

**Configuração final:** o `backend/phpunit.xml` define as variáveis da suíte com `<server>`, e não com `<env force="true">`:
- `APP_ENV=testing`;
- `DB_CONNECTION=mysql`, com `DB_DATABASE` e `DB_TEST_DATABASE` iguais a `barber_booking_test`;
- `MAIL_MAILER=array`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=null` e `SESSION_DRIVER=database`;
- limites de login 2 e 3, e `BCRYPT_ROUNDS=4`.

`tests/Feature/TestEnvironmentTest.php` confere o resultado **dentro do processo de testes**, para uma regressão falhar na hora.

**Causa da divergência (investigada nesta entrega):**
- **Como o Laravel lê:** primeiro de `$_SERVER` (`Illuminate\Support\Env`: `ServerConstAdapter`, depois `EnvConstAdapter`). O PHP preenche `$_SERVER` com o ambiente real do processo.
- **De onde vêm os valores no Docker:** do `env_file` do Compose (`backend/.env`: `APP_ENV=local`, `MAIL_MAILER=smtp`, `CACHE_STORE=database`, ...).
- **O que o `force` faz:** no PHPUnit 12, o `<env force="true">` só escreve em `putenv`/`$_ENV`. Ele **nunca** venceu uma variável que o container já tinha.
- **Por que o banco sempre ficou isolado:** `DB_TEST_DATABASE` **não existe** no container, então o valor forçado não tinha concorrente.

Não foi uma regressão. O `phpunit.xml` e o `docker-compose.yml` não mudaram desde os PRs #2 e #1, as versões de `laravel/framework` (v13.34.0), `phpunit/phpunit` (12.5.38) e `vlucas/phpdotenv` (v5.7.0) são as mesmas e não há cache de configuração (`bootstrap/cache/config.php` não existe). O comentário do PR #2 dizia que o `force` tornava o `APP_ENV=testing` consistente "em todo lugar", mas isso nunca tinha sido verificado dentro do processo.

Evidência: uma sonda temporária dentro da suíte, no Docker, mostrou 11 variáveis com `$_SERVER` vencendo. Entre elas `APP_ENV` (local), `MAIL_MAILER` (smtp), `CACHE_STORE` (database), `QUEUE_CONNECTION` (database), `BCRYPT_ROUNDS` (12) e os limites de login (5 e 20). O resultado era o mesmo com `php artisan test` e com `vendor/bin/phpunit`.

Consequências antes da correção, todas no Docker e não na CI:
- a suíte rodava como `local` (`runningUnitTests()` falso);
- um teste que não simulasse o mail enviaria mensagens de verdade ao Mailpit;
- o Laravel Boost injetava seu script nas páginas HTML;
- os testes de throttle usavam os limites de desenvolvimento, o que explica as 20 assertions a mais em relação à CI.

**Verificado depois da correção:**
- `TestEnvironmentTest` passa com `vendor/bin/phpunit` e com `php artisan test`, e falha (3 de 4) com o `phpunit.xml` antigo.
- A suíte no Docker agora faz exatamente as mesmas assertions da CI.
- Suíte completa no Docker: as 17 tabelas do banco `barber_booking` ficaram com o mesmo `CHECKSUM TABLE` antes e depois, e o Mailpit ficou com a mesma contagem (2), ou seja, nenhuma mensagem da suíte.
- O teste de CSRF (`AuthTest`) continua reativando o middleware explicitamente (`app()->instance('env', 'production')`). Sem essa linha, a requisição sem token passa e o teste falha, o que prova que ele depende do middleware ativo. O cancelamento ganhou um teste equivalente.

Os testes de concorrência e de pós-commit sobem processos e conexões próprios. Eles passam explicitamente ao processo filho as variáveis do banco de testes e drivers sem efeito colateral, porque o processo filho não lê o `phpunit.xml`.

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

Resultado da entrega de serviços/profissionais: suíte completa com **41 passed** (11 de autenticação + 14 de serviços + 10 de profissionais + 2 de `business_settings` + 4 pré-existentes da fundação técnica), rodando 3× seguidas sem flakiness em Docker. Confirmado por contagem de linhas que **a suíte automatizada em si** não alterou o banco `barber_booking` (dev) em nenhuma das execuções — a suíte usa exclusivamente `DB_TEST_DATABASE` (ver "Isolamento entre banco de desenvolvimento e banco de testes" acima).

Essa afirmação é sobre a suíte de testes, não sobre o banco de desenvolvimento como um todo: ele **foi** alterado depois, por comandos manuais de limpeza (`\App\Models\User::query()->delete()` via `tinker`) rodados entre sessões de validação — ver a nota sobre o banco compartilhado na seção "Migrations" acima. Isso é a causa comprovada de um administrador criado anteriormente ter parado de funcionar; não há evidência de exclusão pela aplicação, migrations, seeds ou troca de banco/configuração.

### Testes de expediente e bloqueios

`backend/tests/Feature/Admin/WorkingHourTest.php` (10): acesso sem sessão em `GET`/`PUT` (`401`), `GET` retornando os 7 dias com `periods: []` para um profissional sem expediente salvo, salvar almoço (dois períodos no mesmo dia) + dia de folga + períodos adjacentes num único `PUT`, rejeição de sobreposição, rejeição de início não antes do fim, rejeição de payload faltando um `weekday`, `404` para profissional inexistente, **atomicidade** (um `PUT` inválido não altera o expediente salvo anteriormente — `assertDatabaseHas` confirma os períodos antigos intactos) e substituição completa (salvar um novo dia não deixa restos do expediente anterior salvo para outro dia).

`backend/tests/Feature/Admin/ScheduleBlockTest.php` (19): acesso sem sessão nos quatro métodos (incluindo `DELETE /groups/{id}`), criação para um profissional específico, bloqueio atravessando dias (conferido o UTC resultante, não só o round-trip), rejeição de `professional_id` junto de `scope: "shop"`, rejeição de profissional inexistente, rejeição de fim antes do início, **sobreposição entre bloqueios do mesmo profissional aceita sem erro** (ambos criados), listagem com profissional embutido, remoção de um bloqueio individual e `404` para id inexistente — e, da revisão de agrupamento: fechamento "dia inteiro" convertendo meia-noite-a-meia-noite no fuso da barbearia, fechamento aparecendo como **um único item** cobrindo todos os profissionais (tanto na resposta do `POST` quanto no `GET`), remoção de um fechamento excluindo todas e só as linhas daquele grupo (um bloqueio individual e um fechamento diferente, ambos sobrepostos no mesmo período, continuam intactos), `404` para `group_id` inexistente, linhas antigas sem `group_id` permanecendo individuais na listagem, e criação de um fechamento não alterando um bloqueio individual preexistente.

`backend/tests/Feature/Admin/BusinessSettingsTest.php` (2): acesso sem sessão (`401`) e leitura do singleton seedado.

Resultado desta entrega: **72 passed** no total (41 de cadastros + 10 de expediente + 19 de bloqueios + 2 de `business_settings`), `vendor/bin/pint --format agent` sem alterações, rodado 3× seguidas sem flakiness em Docker.

Internacionalização acrescenta `tests/Unit/SetLocaleFromAcceptLanguageTest.php` (5 — sem header, idioma não suportado, `en`, `pt-BR` com hífen maiúsculo/minúsculo, lista ponderada escolhendo o primeiro idioma suportado) e `tests/Feature/LocalizationTest.php` (6 — mensagem de exception handler traduzida para inglês, português por padrão, fallback para idioma não suportado, mensagem de validação padrão do Laravel traduzida nos dois idiomas, mensagem de validação de negócio customizada traduzida nos dois idiomas, e `error.code` idêntico independente do idioma). Resultado desta entrega: **83 passed** no total, `vendor/bin/pint --format agent` sem alterações.

Achado na implementação (não um bug de teste, um bug de aplicação pego pelo teste): o cast `datetime` do Eloquent não converte timezone ao gravar — ver "Bloqueios (`schedule_blocks`)" acima para a correção (`CarbonImmutable::parse(...)->utc()` explícito no controller).

### Testes de disponibilidade

`tests/Feature/AvailabilityTest.php` (28 testes, pelas duas rotas HTTP, MySQL real, relógio controlado com `travelTo`): formato do contrato e ausência de dados internos; rota admin exigindo sessão; parâmetro `context` não muda as regras públicas; validação de data (incluindo `2026-02-30`); serviço/profissional inativo ou inexistente e profissional sem o serviço, nos dois contextos, com mensagem em pt-BR e en; dia sem expediente; duração que precisa caber inteira no período; serviço maior que o período; almoço com o exemplo da seção 5 do planejamento; arredondamento para o próximo quarto de hora; bloqueio individual com intervalos adjacentes; fechamento da barbearia; bloqueio e reserva de outro profissional sem interferência; reserva confirmada bloqueia e cancelada não; reserva de duração diferente com conflito parcial; antecedência mínima exata (08:00 → 09:00 incluído; 08:00:01 → 09:15); admin ignorando a antecedência sem receber passado; datas passadas; horizonte contando hoje (30 → até hoje+29) nos dois contextos; horizonte pela data local e não UTC (22:30 em São Paulo já é o dia seguinte em UTC); data e dia da semana no fuso da barbearia (`Asia/Tokyo`); mesmo horário local em UTC diferente antes e depois do horário de verão (`America/New_York`); período que atravessa a mudança de horário usando tempo real; rate limit público.

Verificação dos próprios testes: cinco defeitos introduzidos de propósito no motor (intervalo fechado em vez de fim exclusivo, admin aplicando antecedência, horizonte calculado em UTC, reservas canceladas ocupando horário, grade sem arredondar para o quarto de hora) foram todos detectados — cada um derrubou entre 1 e 4 testes. O motor foi restaurado e conferido byte a byte depois.

Resultado: **111 passed** no backend (83 anteriores + 28), `vendor/bin/pint --format agent` sem alterações.

**O que estes testes não provam:** prevenção de reservas simultâneas — isso é responsabilidade do POST de criação, coberto por `PublicAppointmentConcurrencyTest` (abaixo).

### Testes de criação pública de reservas

- `tests/Feature/PublicAppointmentTest.php` (29 testes, MySQL real, relógio controlado):
  - criação válida, `public_id` ULID, status `confirmed`, origem `public` e fim calculado;
  - snapshots preservados depois de editar o serviço;
  - contatos canônicos;
  - campos internos enviados pelo cliente ignorados;
  - reserva sumindo da disponibilidade;
  - idempotência (replay `200`, chave reaproveitada `409`, header obrigatório e validado);
  - validação de payload, telefone E.164 e `starts_at` com offset;
  - mesmo instante com outro offset;
  - serviço ou profissional inativo, inexistente ou sem vínculo;
  - antes da abertura, depois do fechamento, atravessando o almoço, dentro do almoço e fora da grade;
  - dia sem expediente;
  - bloqueio e suas bordas;
  - reserva existente (mesmo início, sobreposição parcial, adjacentes antes e depois);
  - reserva cancelada e reserva de outro profissional;
  - horário ocupado entre o GET e o POST;
  - antecedência exata e um segundo depois;
  - passado;
  - último dia do horizonte e o seguinte;
  - horizonte pela data local;
  - teto por contato (e-mail e telefone separados, reserva do admin contando, canceladas e passadas não contando);
  - mensagens em pt-BR e en;
  - erro sem dados internos;
  - rate limit.
- `tests/Feature/PublicAppointmentConcurrencyTest.php` (6 testes): **duas conexões MySQL independentes**, cada uma num processo PHP próprio (`tests/Support/post-public-appointment.php`) que sobe a aplicação e passa pelo kernel HTTP real.
  - Uma terceira conexão segura o lock de `business_settings`; o teste espera o MySQL mostrar as **duas** tentativas bloqueadas nesse lock (`information_schema.processlist`) e só então o solta.
  - Cenários: mesmo horário e sobreposição parcial (exatamente um `201` e um `409 SLOT_UNAVAILABLE`, uma linha no banco); adjacentes e profissionais diferentes (dois `201`); teto por contato entre profissionais diferentes (um `201` e um `409 CONTACT_LIMIT_REACHED`); duplo clique com a mesma chave (um `201` e um `200` com o mesmo `public_id`).
  - Os dados ficam gravados (`DatabaseTruncation`, não `RefreshDatabase`, porque linhas dentro de uma transação de teste seriam invisíveis aos outros processos), e as tabelas são truncadas de novo no `tearDown`.
- Verificação do próprio teste de concorrência: dois defeitos introduzidos de propósito no `AppointmentBooker`, mantendo os locks, produziram **double booking real** (dois `201` para o mesmo profissional e horário) e o teste falhou nos dois casos: (1) uma leitura comum antes do lock, a armadilha do `REPEATABLE READ` descrita acima; (2) revalidação fora da transação. O booker foi restaurado e conferido byte a byte.
- `tests/Feature/Admin/AgendaConflictTest.php` (9 testes): regras da seção 7 para bloqueios (individual, fechamento, adjacente, sobre reserva cancelada, outro profissional, mensagem em pt-BR e en) e para expediente (redução recusada e desfeita, dia inteiro removido, alteração que ainda contém a reserva, reservas passadas e canceladas não impedem).

Resultado: **155 passed** no backend (111 anteriores + 44 novos). O teste de concorrência passou em 5 rodadas seguidas. `vendor/bin/pint --format agent` passou.

### Testes de confirmação e cancelamento

- `tests/Feature/BookingConfirmationTest.php` (12):
  - exatamente um e-mail por reserva, com a notificação `sent`;
  - retry com a mesma chave sem segundo e-mail;
  - reserva recusada sem e-mail nem notificação;
  - conteúdo do e-mail (dados, código, link e rodapé traduzido);
  - link assinado para a reserva certa, expirando no início e abrindo a página dela;
  - snapshots em vez do serviço atual;
  - e-mail em inglês;
  - falha de SMTP real (porta fechada) mantendo a reserva e registrando `failed`;
  - varredura: reenvia pendentes e falhas antigas, ignora recentes e esgotadas, marca `skipped` para canceladas e passadas;
  - duas entregas da mesma notificação enviando uma vez só.
- `tests/Feature/ConfirmationAfterCommitTest.php` (1): no instante do envio, uma segunda conexão MySQL já enxerga a reserva e a notificação. Usa dados gravados de fato (`DatabaseTruncation`). Verificado: com o envio movido de propósito para dentro da transação, a segunda conexão viu 0 reservas e o teste falhou.
- `tests/Feature/PublicCancellationTest.php` (15):
  - GET com resumo mínimo e sem alterar nada;
  - cabeçalhos de privacidade e CSP sem scripts;
  - página em inglês;
  - cancelamento válido sem apagar;
  - snapshots e identidade preservados, sem reserva nova;
  - cancelamento repetido;
  - campos do formulário ignorados;
  - horário de volta à disponibilidade;
  - assinatura alterada, ausente e expiração alterada recusadas;
  - `public_id` de outra reserva com esta assinatura recusado;
  - reserva inexistente com a mesma página genérica;
  - link válido até o início e expirado depois;
  - prazo de `cancel_min_notice_minutes` exato;
  - rate limit.

Resultado: **183 passed** no backend (155 anteriores + 28 novos), também com o ambiente limpo, como na CI (`APP_ENV=testing`).

### Testes do ambiente, do scheduler e da entrega

- `TestEnvironmentTest` (4): `APP_ENV=testing`, MySQL em `barber_booking_test`, mail, cache e fila em memória, e os limites de teste ativos.
- `ScheduleTest` (2): a varredura agendada a cada minuto com `withoutOverlapping(10)` e `onOneServer`; uma trava deixada por uma execução morta expira em minutos, não em um dia.
- `ConfirmationAfterCommitTest` (+1): na varredura, assim como na requisição, o envio acontece sem transação aberta e sem o lock da agenda.
- `BookingConfirmationTest` (+1): um servidor SMTP que aceita a conexão e nunca responde não segura a requisição de reserva (`201`, `failed`, em segundos).
- `PublicCancellationTest` (+1): o formulário de cancelamento recusa o POST sem token CSRF (`419`, página própria) e aceita com token válido, com o middleware reativado.
- `AuthTest`: o teste de CSRF agora também exige `error.code: SESSION_EXPIRED`. **Defeito corrigido:** o Laravel converte `TokenMismatchException` em `HttpException(419)` antes de chamar os renderizadores registrados, então o renderizador do contrato (`SESSION_EXPIRED`) nunca era executado. A API respondia o JSON padrão do framework e, com `APP_DEBUG=true`, o stack trace. Os renderizadores de CSRF (API e página de cancelamento) agora tratam o `HttpException(419)` cuja causa é `TokenMismatchException`.

Resultado desta entrega: **192 passed** no backend.

## Internacionalização (PT-BR / English)

Requisito registrado em `docs/planejamento-barbearia-mvp.md`, seção 15. Aqui ficam as decisões de implementação.

### Frontend

- Biblioteca: `react-i18next` + `i18next`, com os recursos de tradução embutidos no bundle (`frontend/src/i18n/locales/{pt-BR,en}.json`) — sem backend de tradução remoto, sem carregamento assíncrono de idioma.
- `frontend/src/i18n/index.js` inicializa a instância única do i18next (importada uma vez em `main.jsx`, antes do `createRoot`) e exporta:
  - `resolveInitialLanguage()`: lê `localStorage` (`barber-booking-language`); se houver uma preferência salva e suportada, ela vence. Caso contrário, usa `en` se `navigator.language`/`navigator.languages` começar com "en", senão `pt-BR`.
  - `persistLanguage(language)`: grava a escolha no `localStorage`. É chamada explicitamente pelo `LanguageSwitcher` a cada troca — não há um listener automático de `languageChanged` fazendo isso, por design (mantém o ponto de persistência único e explícito).
  - Um listener de `languageChanged` mantém `document.documentElement.lang` sincronizado, para qualquer troca (inclusive futuras, fora do switcher).
- Arquivos de tradução são organizados por domínio dentro de um único namespace por idioma (`common`, `nav`, `adminLayout`, `home`, `auth`, `agenda`, `services`, `professionals`, `workingHours`, `scheduleBlocks`), com `common.actions`/`common.fields` reunindo rótulos repetidos (Salvar/Cancelar/Editar/Nome/Status/...) para não duplicar a mesma string em cada domínio.
- `LanguageSwitcher` (`frontend/src/components/LanguageSwitcher.jsx`) mostra sempre "Português" e "English" (nunca o idioma atual mascarado, nunca bandeiras) e aparece em três lugares: homepage (`Home.jsx`), tela de login (`Login.jsx`) e cabeçalho do painel (`AdminLayout.jsx`, visível em todas as telas administrativas). Trocar idioma é só `i18n.changeLanguage(...)` — sem navegação, sem remount de rota, sem efeito em sessão/CSRF/formulário em andamento.
- `frontend/src/api/client.js` envia `Accept-Language: ${i18n.language}` em toda chamada (`apiFetch`, `ensureCsrfCookie`) e em `api/health.js`; o backend decide a partir daí.
- **Preço por idioma:** `frontend/src/utils/money.js` ganhou `toMoneyInput(decimal, language)` / `parseMoneyInput(value, language)` (vírgula decimal para `pt-BR`, ponto decimal para `en`, tratando o separador oposto como agrupamento de milhar) e `swapMoneySeparator(value, fromLanguage, toLanguage)`, que reformata um valor **já digitado** (completo ou incompleto, tipo `"45,"` no meio da digitação) ao trocar de idioma, sem reinterpretar os dígitos — é uma troca de separador posicional, não um novo parse. A API continua recebendo/retornando `price` como string decimal (`"45.90"`), sempre.
  - **Achado na implementação:** `Services.jsx` originalmente lia o idioma "anterior" de uma `ref` mutável dentro do próprio callback de atualização de estado (`setForm((prev) => ...)`) — como React pode invocar esse callback depois que a linha seguinte já tinha avançado a `ref` para o idioma novo, o `swapMoneySeparator` acabava recebendo `from === to` (um no-op) na primeira troca de idioma com um preço já digitado. Corrigido capturando `fromLanguage`/`toLanguage` em constantes locais **antes** de chamar `setForm`, e usando essas constantes (não a `ref`) dentro do callback.
- **Achado na revisão de fechamento — troca de idioma recarregava as telas do painel:** as funções de carregamento de `Services`, `Professionals`, `WorkingHours` e `ScheduleBlocks` eram `useCallback(..., [t])`, e o `react-i18next` entrega uma nova referência de `t` a cada troca de idioma. Resultado: trocar o idioma refazia todas as chamadas da tela e, em `/admin/expediente`, **descartava períodos digitados e ainda não salvos e voltava a seleção para o primeiro profissional** — contrariando a regra de não perder formulário em andamento. Corrigido guardando o erro bruto no estado (`setLoadError(error)`) e traduzindo a mensagem na renderização (`loadErrorMessage`), de modo que os carregamentos não dependem mais de `t` (e a mensagem de erro de carregamento também troca de idioma na hora). O mesmo efeito fazia um teste de `App.test.jsx` falhar de forma intermitente (cerca de 1 em 6 execuções): o `afterEach` de `setupTests.js` troca o idioma depois de `vi.resetAllMocks()`, e o efeito refeito encontrava os mocks zerados.
- **Achado na revisão de fechamento — chaves ausentes:** `common.errors.network`/`common.errors.generic` (usadas por `api/client.js`) e `scheduleBlocks.professionalLabel`/`scheduleBlocks.noProfessionalsYet` (usadas por `ScheduleBlocks.jsx`) não existiam nos arquivos de tradução — a interface mostraria o nome da chave. Adicionadas nos dois idiomas. Conferência feita por script: as 131 chaves existem em `pt-BR.json` e `en.json`, nenhuma vazia, e toda chave literal usada em `t('...')` no código existe.
- Datas de bloqueios na listagem (`ScheduleBlocks.jsx`) são formatadas manualmente (`dd/mm/aaaa` para `pt-BR`, `mm/dd/aaaa` para `en`, sempre 24h) a partir da conversão de fuso já existente (`utils/timezone.js`) — o idioma só troca a ordem dia/mês exibida, nunca o instante UTC nem o fuso considerado.
- **Controles nativos de data/hora (`<input type="date">`/`<input type="time">`) não são traduzidos pela aplicação** — o formato de exibição desses widgets (calendário, separador de data, 12h/24h) segue o idioma do navegador ou do sistema operacional de quem está usando, independentemente do idioma escolhido na interface. O valor subjacente (`"YYYY-MM-DD"`, `"HH:MM"`) é sempre o mesmo formato ISO, então isso não afeta a conversão de fuso horário — é uma limitação puramente visual, documentada aqui.
- Dados cadastrados (nome de serviço/profissional, descrição, motivo de bloqueio) nunca passam por tradução — são sempre exibidos como o administrador digitou.

### Backend

- `app/Http/Middleware/SetLocaleFromAcceptLanguage.php`, adicionado ao início do grupo `api` (`$middleware->prependToGroup('api', ...)` em `bootstrap/app.php`) — roda antes de qualquer validação ou handler, para que toda a resposta já saia no idioma certo. Interpreta o header `Accept-Language` (incluindo listas ponderadas tipo `"fr;q=0.9,en;q=0.5"`, usando o primeiro idioma suportado encontrado), aceita só `pt_BR`/`en`, e usa `pt_BR` como fallback para header ausente ou nenhum idioma suportado na lista. `app()->setLocale(...)` é chamado a cada requisição — nada é persistido (sem sessão, sem coluna de usuário), exatamente como pedido.
- `backend/lang/{pt_BR,en}/errors.php`: mensagens humanas para os `error.code` já existentes (`UNAUTHENTICATED`, `INVALID_CREDENTIALS`, `VALIDATION_ERROR`, `NOT_FOUND`, `SESSION_EXPIRED`, `RATE_LIMITED`, e as mensagens de negócio customizadas como "serviço não existe"/"profissional não existe"/"fim antes do início"/"períodos sobrepostos"). `error.code` em si nunca muda — só a tradução de `error.message` passou a usar `__('errors.xxx')` em vez de string fixa, em `bootstrap/app.php`, `AppServiceProvider`, `AuthController`, `ProfessionalController`, `ScheduleBlockController`, `UpdateWorkingHoursRequest` e `HealthController`.
- `backend/lang/{pt_BR,en}/validation.php`: cobre só as regras de validação realmente usadas (`required`, `integer`, `date_format`, `after`, etc.) e os nomes amigáveis de campo (`attributes`, incluindo os caminhos com wildcard do expediente, tipo `"days.*.periods.*.start_time"`). Uma regra sem entrada aqui recai no `fallback_locale` (`en`, já configurado em `config/app.php`) — nunca aparece um placeholder quebrado tipo `validation.some_rule`. O inglês já tinha mensagens padrão corretas via o próprio arquivo interno do framework (`vendor/laravel/framework/.../lang/en/validation.php`); o arquivo da aplicação em `en` só adiciona os nomes amigáveis dos campos com wildcard, que o framework não cobre.
- **Achado na implementação:** os testes do Laravel (`$this->getJson(...)` etc.) usam `Illuminate\Http\Request::create()` por baixo dos panos, que **por padrão** preenche `Accept-Language` como `"en-us,en;q=0.5"` (um valor de conveniência do próprio Symfony HttpFoundation, não algo configurado neste projeto) quando o teste não define o header explicitamente. Sem correção, toda a suíte passaria a rodar em inglês por acidente assim que os arquivos de idioma fossem adicionados — o oposto do comportamento real (navegadores sempre mandam um header de verdade; só testes têm esse valor de conveniência). Corrigido centralizando em `tests/TestCase.php::setUp()`: `$this->withHeaders(['Accept-Language' => 'pt-BR'])` por padrão: um teste que precisa do outro idioma sobrescreve isso explicitamente (como `LocalizationTest.php` faz).

### Como adicionar tradução a uma tela nova

1. Adicione as chaves novas nos dois arquivos (`frontend/src/i18n/locales/pt-BR.json` e `en.json`), na mesma estrutura — reaproveite `common.actions`/`common.fields` quando o texto já existir em outra tela (Salvar, Cancelar, Nome, Status...) em vez de duplicar.
2. No componente, `const { t } = useTranslation()` e troque toda string fixa por `t('dominio.chave')`; para texto com variável, use interpolação (`t('dominio.chave', { nome: valor })`) em vez de concatenar strings.
3. Mensagens de erro vindas da API: decida pelo `error.code` (estável, nunca muda com idioma), nunca comparando o texto de `error.message` — o backend já manda a mensagem traduzida, mas o frontend só deve usar esse texto como fallback genérico para códigos que não tenham uma tradução própria no domínio da tela.
4. Se a tela aceitar dinheiro, data ou hora, reuse `utils/money.js`/`utils/timezone.js` — nunca formate manualmente sem considerar o idioma atual (`i18n.language`).
5. Não coloque `t` nas dependências de funções que buscam dados (`useCallback(..., [t])`): a referência de `t` muda a cada troca de idioma e a tela recarregaria, perdendo o que estiver digitado. Guarde o erro bruto no estado e traduza na renderização.
6. Se o backend precisar de uma mensagem nova (validação customizada, novo `error.code`), adicione a chave em **ambos** `backend/lang/pt_BR/errors.php` e `backend/lang/en/errors.php`, e troque a string fixa por `__('errors.chave')` no controller/request.

## Mailpit

Qualquer e-mail enviado pelo backend (`MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`) fica disponível em http://localhost:8025, sem sair da rede local. A confirmação de reserva chega aqui (ver "Confirmação por e-mail e cancelamento"). A API do Mailpit (`GET http://localhost:8025/api/v1/messages`) ajuda a conferir o conteúdo sem abrir o navegador.

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
7. Abra `/admin/expediente`:
   - Escolha um profissional no seletor; confirme que os 7 dias aparecem, com "Folga — nenhum período definido." para os dias sem expediente salvo.
   - Adicione dois períodos no mesmo dia (ex.: 09:00–12:00 e 13:00–18:00, simulando o almoço) e salve; recarregue a página e confirme que os dois períodos persistem.
   - Tente salvar dois períodos que se sobrepõem; confirme a mensagem de erro junto do dia afetado, e que o expediente salvo anteriormente não foi apagado (recarregue para conferir).
   - Troque de profissional no seletor; confirme que o expediente mostrado é o daquele profissional, não o anterior.
8. Abra `/admin/bloqueios`:
   - Crie um bloqueio para um profissional específico, com início e fim em dias diferentes (ex.: 24/12 18:00 até 26/12 08:00); confirme que a lista mostra o profissional, as datas/horas convertidas (no fuso da barbearia, não o do navegador) e o motivo.
   - Selecione "Fechamento da barbearia"; confirme que aparece o texto "Nenhum profissional atenderá durante este período." e que o campo de profissional some.
   - Marque "Dia inteiro", escolha uma data e salve; confirme que a lista mostra **um único item** "Fechamento da barbearia" (não uma linha por profissional) com o período correto (meia-noite do dia escolhido até meia-noite do dia seguinte, no fuso da barbearia).
   - Desmarque "Dia inteiro" e crie um fechamento com um intervalo personalizado atravessando dias; confirme que continua aparecendo como um único item.
   - Clique "Remover" num bloqueio individual; confirme a janela de confirmação e que cancelar não remove nada.
   - Clique "Remover fechamento" num fechamento da barbearia; confirme que o texto da confirmação lista os profissionais que serão liberados, e que cancelar não remove nada. Confirme de fato e veja que o item some da lista inteiro (não sobra nenhuma linha individual) e que outros bloqueios/fechamentos sobrepostos continuam na lista.
   - Teste a navegação só por teclado entre os campos de data/hora, o seletor de alcance e o checkbox "Dia inteiro" (Tab/Espaço) e confirme foco visível.
   - Repita os passos acima numa viewport mobile (DevTools → modo responsivo) e confirme que a tabela vira cards empilhados legíveis (mesmo padrão já usado em Serviços/Profissionais).
9. Teste os dois idiomas:
   - Na homepage (`/`), na tela de login e no painel administrativo, confirme que o seletor mostra "Português" e "English" (nunca bandeiras) e que clicar no outro idioma troca os textos **instantaneamente**, sem recarregar a página (a URL não muda, não há piscada de tela branca).
   - No painel, logado, com algo digitado em um formulário (ex.: comece a criar um serviço com nome e preço preenchidos), troque o idioma e confirme que a sessão continua ativa (não volta para o login) e que os valores digitados continuam lá — incluindo o preço, convertido para a convenção do novo idioma (`45,90` vira `45.90` e vice-versa, nunca um valor diferente).
   - Abra uma aba anônima/nova sem nenhuma preferência salva: se o idioma do navegador começar com "en", a página deve abrir em inglês; caso contrário, em português. Troque o idioma, recarregue a página (F5): a escolha deve persistir (não voltar ao padrão).
   - Tente um login com e-mail/senha errados nos dois idiomas; confirme que a mensagem de erro aparece traduzida em ambos.
   - Em `/admin/bloqueios`, crie e remova um fechamento da barbearia com o idioma em English; confirme que o texto de confirmação da remoção aparece em inglês e lista os profissionais corretamente.

O que dava para confirmar sem navegador foi validado via `curl`/PHPUnit e está documentado nas seções acima: resposta de indisponibilidade (`503` genérico, detalhe só em log), os fluxos completos de login/sessão/logout/CSRF e de criação/edição de serviços e profissionais contra o proxy real, e nesta entrega também expediente (`GET`/`PUT` com almoço, sobreposição rejeitada) e bloqueios (criação atravessando dias com conversão de fuso conferida, fechamento "dia inteiro" com conversão meia-noite-a-meia-noite conferida, fechamento aparecendo como um único item agrupado, remoção de um fechamento inteiro via `DELETE /schedule-blocks/groups/{id}` preservando um bloqueio individual sobreposto) contra o proxy real, com sessão e CSRF reais — usando profissionais e administradores temporários criados só para essa verificação e removidos por ID logo depois (nenhum dado preexistente foi tocado). Durante essa verificação, 14 linhas de `schedule_blocks` (ids 3–16, `group_id` nulo, `reason` nulo) ficaram para trás de uma rodada de verificação anterior a este PR, antes da coluna `group_id` existir — identificadas com segurança pelo padrão (mesmo timestamp, um id por profissional que existia naquele momento, nenhum `reason`) e removidas por ID junto da limpeza desta rodada; nenhum cadastro real (serviço, profissional, administrador) foi afetado. Nesta entrega, também contra o proxy real: `GET /api/v1/admin/me` sem sessão retornando `error.message` em português (`Accept-Language: pt-BR` ou ausente) e em inglês (`Accept-Language: en`), com `error.code` idêntico (`UNAUTHENTICATED`) nos dois casos; e uma mensagem de validação customizada (`service_ids` inexistente) traduzida nos dois idiomas. As telas de `/admin/expediente` e `/admin/bloqueios` em si (passos 7 e 8 acima), e a troca de idioma pelo botão (passo 9), ainda não foram confirmadas visualmente em um navegador real.

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
| Página renderiza em branco (ou só com conteúdo antigo) mesmo em aba anônima/nova, para arquivos editados depois que o container `frontend` já estava rodando | o watcher de arquivos do Vite (chokidar) depende de eventos nativos do sistema de arquivos; no Docker Desktop para Windows, esses eventos não atravessam de forma confiável a fronteira Windows → WSL2/Linux para bind mounts, então o Vite nunca percebe a mudança e continua servindo o grafo de módulos antigo da memória — sem erro nenhum, porque não é uma exceção JS, é uma rota/componente que simplesmente não existe na versão que ele está servindo. Prova: comparar `curl http://localhost:8080/src/<arquivo>.jsx` (o que o Vite está de fato servindo) com o arquivo no host/container (`docker compose exec frontend cat /app/src/<arquivo>.jsx`) — divergem mesmo com checksum idêntico em disco | corrigido na entrega de serviços/profissionais: `frontend/vite.config.js` agora define `server.watch.usePolling = true`, o que faz o Vite checar os arquivos periodicamente em vez de depender de eventos de SO. Depois de atualizar o `vite.config.js`, rode `docker compose restart frontend` uma vez para a mudança valer; edições seguintes passam a refletir sem precisar de restart |
| Mesmo sintoma acima (página renderiza conteúdo antigo), mas **só para alguns arquivos**, mesmo com o polling do item anterior já configurado e o container rodando há muito tempo | o próprio Vite reinicia sozinho quando detecta mudança no `vite.config.js` (`"vite.config.js changed, restarting server..."` no log) — e um `git checkout`/troca de branch já altera o mtime desse arquivo mesmo sem mudar o conteúdo, disparando esse auto-restart. Esse self-restart in-process, pelo menos nesta versão do Vite, às vezes recria o watcher de forma incompleta: alguns arquivos (ex.: um componente-folha como `AdminNav.jsx`) continuam sendo re-observados normalmente, enquanto outros (ex.: `App.jsx`, o arquivo de entrada das rotas) ficam presos no grafo de módulos de antes do restart — edições posteriores a eles não dão nenhum log (`hmr update`) e continuam sendo servidas como estavam no momento do restart. Prova: comparar o conteúdo servido (`curl`) de dois arquivos editados na mesma janela de tempo — um atualizado, outro não — e checar `docker compose logs frontend` por uma linha `vite.config.js changed, restarting server...` seguida de silêncio para o arquivo afetado | `docker compose restart frontend` (restart completo do container, não o self-restart do Vite) resolve — recria o processo do zero com um watcher novo. Depois de qualquer `git checkout`/troca de branch que leve a sessão de volta ao desenvolvimento no frontend, é mais seguro reiniciar o container preventivamente do que confiar no auto-restart do Vite |

## Limitações desta etapa

- Serviços, profissionais, vínculos, expediente semanal, bloqueios, autenticação administrativa, o **motor de disponibilidade**, a **criação pública de reservas** (com proteção contra double booking na transação), o **e-mail de confirmação** e o **cancelamento pelo cliente por link assinado** estão implementados. Catálogo público, telas de agendamento, reserva e cancelamento pelo admin e reenvio manual de e-mail ainda não existem.
- **Pendente:** reserva pelo admin (incluindo "Atender agora", que não envia confirmação com link já expirado), cancelamento e reenvio de e-mail pelo admin, telas públicas de agendamento, e alerta para confirmações que esgotaram as 5 tentativas.
- A jornada pública de agendamento existe no React (`/` e `/agendar`), revisada visualmente em navegador real contra as referências de `docs/design/` (ver "Telas públicas de agendamento"). A operação administrativa da agenda (agenda diária, reserva e cancelamento pelo admin) ainda não existe.
- `business_settings` tem o registro singleton (seedado) e uma rota de **leitura** (`GET /api/v1/admin/business-settings`, adicionada nesta entrega); ainda sem tela nem endpoint de **edição**.
- CI builda as imagens Docker (`docker compose build`) para validar os Dockerfiles, mas não executa a stack completa via Compose; os testes de frontend e backend rodam nativamente nos runners do GitHub Actions.
- Concorrência testada de verdade só na criação de reservas (`PublicAppointmentConcurrencyTest`, com duas conexões MySQL simultâneas). Os locks dos cadastros, do expediente e dos bloqueios seguem a mesma ordem, mas não têm um teste de corrida próprio; em particular, a corrida "reserva contra criação de bloqueio" da seção 8 do planejamento ainda não tem teste dedicado.
- **Resolvido:** a diferença de 20 assertions entre Docker e CI e a suíte rodando como `local` no Docker. Causa e correção em "Ambiente de testes".
- Homepage, login, agenda, serviços e profissionais confirmados visualmente em navegador real numa entrega anterior. Expediente e bloqueios (telas de uma entrega anterior) ainda **não** foram confirmados visualmente em navegador. A internacionalização (PT-BR/English, esta entrega) foi verificada via `curl` contra o proxy real com `Accept-Language: pt-BR`/`en` (mensagens de erro/validação traduzidas corretamente) e via teste de integração automatizado (`App.test.jsx`, troca de idioma pelo router real nas três áreas), mas a troca de idioma pelo botão **não** foi confirmada visualmente em navegador. Viewport mobile e estado de indisponibilidade também seguem pendentes de validação visual — ver roteiro manual abaixo.
- `backend/composer.json` originalmente declarava `"php": "^8.3"`, mas o `composer.lock` resolvido trava `symfony/*` em versões que exigem PHP ≥8.4.1; `composer install` só falha ao rodar de fato em PHP 8.3 (o `platform` do lock não é validado contra o interpretador real até o install). Corrigido para `^8.4`, que é o que a imagem Docker e o CI já usavam.
