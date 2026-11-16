# AGENTS.md

Instruções para agentes de código que trabalham neste repositório. Leia este arquivo inteiro antes de qualquer alteração e leia também o `README.md` para o contexto do produto.

## 1. Contexto do projeto

**Avisos-Cond** é um mural digital de avisos para condomínios. O gerente cadastra avisos; uma TV/mini PC no elevador os exibe via navegador. A Embracore (fornecedor) administra gerentes, condomínios, dispositivos e faturamento.

Três superfícies: `/admin` (Embracore), `/gerente` (gerente) e `/tv/{token}` (exibição pública por token).

## 2. Princípios inegociáveis

1. **Simplicidade e transparência.** Código explícito, sem "mágica". O responsável pelo projeto precisa conseguir ler e entender tudo. Prefira o óbvio ao engenhoso.
2. **Poucas dependências.** Não adicione pacotes sem necessidade clara. Antes de rodar `composer require`, pare e justifique em comentário no PR/commit. Proibido instalar frameworks full-stack (Laravel, Symfony, etc.) e ORMs.
3. **Isolamento entre condomínios é a regra de segurança nº 1.** Um gerente nunca pode ler ou alterar dados de condomínio ao qual não está vinculado. Veja a seção 6.
4. **Não invente requisitos.** Se algo estiver ambíguo, implemente o mínimo coerente com este documento e o README, e registre a dúvida no final da sua resposta.
5. **Mudanças pequenas e verificáveis.** Um assunto por vez, com testes.

## 3. Stack e versões

- PHP 8.3 (`declare(strict_types=1);` em todo arquivo PHP)
- PHP-FPM + Nginx
- PostgreSQL 16+ via **PDO** (`pdo_pgsql`), com *prepared statements* sempre
- FastRoute (roteamento), Twig (templates, auto-escape ligado)
- HTMX + Alpine.js servidos como arquivos estáticos locais em `/public/assets` (sem CDN em produção, sem build step, sem Node)
- Composer: apenas autoload PSR-4 (`App\` → `src/`) e as bibliotecas citadas acima
- Testes: PHPUnit (dev dependency)

Bibliotecas adicionais permitidas somente se pequenas, mantidas e realmente necessárias (ex.: cliente HTTP simples, geração de QR code). Prefira a biblioteca padrão do PHP.

## 4. Estrutura e responsabilidades

```
/public            index.php (front controller), assets, sw.js (service worker)
/src
  /Controllers     recebem a requisição, validam entrada, chamam Services/Repositories, devolvem resposta
  /Repositories    TODO o SQL do sistema mora aqui, e só aqui
  /Services        regras de negócio (Auth, Billing, Scheduler, Upload, Audit)
  /Middleware      Auth, Role, Csrf, RateLimit
  /Support         Config, Db, Session, Response, Validator
/templates         Twig
/migrations        NNN_descricao.sql (numerados, imutáveis depois de aplicados)
/bin               migrate.php, cron.php, create-admin.php
/storage           uploads e logs (fora do webroot)
/tests
```

Regras de camada:
- Controllers **não** contêm SQL.
- Repositories **não** conhecem HTTP, sessão ou Twig.
- Templates **não** contêm regra de negócio nem consulta ao banco.

## 5. Convenções de código

- Nomes do domínio em **português** (`condominio`, `aviso`, `gerente`, `dispositivo`) e termos técnicos em inglês (`Repository`, `Controller`, `Middleware`). Seja consistente com o que já existe.
- Interface do usuário, mensagens de erro e e-mails em **português do Brasil**.
- PSR-12 para estilo. Tipos explícitos em parâmetros, retornos e propriedades.
- Sem variáveis globais. Dependências injetadas por construtor, montadas no `public/index.php` (ou num container mínimo escrito à mão).
- Datas armazenadas em `timestamptz` (UTC) e exibidas em `America/Sao_Paulo`.
- Dinheiro em centavos (`integer`) ou `numeric(12,2)`, nunca `float`.
- Erros: lance exceções específicas, registre em log (`storage/logs`), mostre mensagem genérica ao usuário. Nunca exiba stack trace em produção.
- Comentários explicam o *porquê*, não o *quê*.

## 6. Segurança (leia com atenção)

### 6.1 Isolamento de tenants (crítico)
- O `condominio_id` **nunca** é confiável quando vem do request (form, query, JSON).
- No contexto do gerente, a lista de condomínios permitidos vem **da sessão/banco** (tabela `usuario_condominio`), nunca do cliente.
- Todo método de Repository que acessa dados de condomínio recebe os IDs permitidos e usa `WHERE condominio_id = ANY(:ids)` (ou equivalente). Não existe consulta de `avisos`, `dispositivos`, `faturas` etc. sem esse filtro no contexto do gerente.
- Ao receber um ID de recurso (`/gerente/avisos/42/editar`), verifique que o recurso pertence a um condomínio permitido **antes** de qualquer leitura ou escrita. Resposta para recurso fora do escopo: `404`.
- Toda funcionalidade nova que toque dados por condomínio **precisa** de teste de isolamento (veja seção 9).

### 6.2 Autenticação e sessão
- Senhas com `password_hash($senha, PASSWORD_ARGON2ID)` e `password_verify`. Use `password_needs_rehash`.
- No login: `session_regenerate_id(true)`. No logout: destruir sessão e cookie.
- Cookies de sessão: `HttpOnly`, `Secure`, `SameSite=Lax`, nome definido em `.env`.
- Limite de tentativas de login por IP e por usuário (tabela ou cache), com atraso/bloqueio temporário.
- Mensagens de login genéricas ("credenciais inválidas"), sem revelar se o e-mail existe.
- Reset de senha com token aleatório (`random_bytes`), armazenado com hash, de uso único e com expiração curta.

### 6.3 Autorização
- Middleware `Role` protege rotas por papel (`superadmin`, `gerente`). `/admin/*` só `superadmin`; `/gerente/*` só `gerente` (ou superadmin em modo suporte, com registro em auditoria).
- Negar por padrão: rota sem regra explícita não é pública.

### 6.4 CSRF e XSS
- Token CSRF em **todo** POST/PUT/PATCH/DELETE (middleware). HTMX deve enviar o token por header.
- Twig com auto-escape sempre ligado. Proibido usar `|raw` em conteúdo vindo de usuário. Se precisar de formatação rica nos avisos, use um subconjunto seguro (ex.: texto puro com quebras de linha; ou Markdown limitado com sanitização).
- Cabeçalhos: `Content-Security-Policy` restritiva, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `X-Frame-Options` (exceto onde a TV precisar).

### 6.5 Banco de dados
- Somente *prepared statements* com parâmetros nomeados. Proibido concatenar entrada do usuário em SQL.
- `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES => false`.
- Usuário do banco da aplicação sem privilégios de DDL em produção (migrations rodam com outro usuário).

### 6.6 Uploads
- Valide tamanho, extensão **e** MIME real com `finfo`. Tipos permitidos: JPEG, PNG, WebP, PDF (e vídeo curto MP4, se habilitado).
- Nome do arquivo gerado aleatoriamente; nunca use o nome enviado.
- Armazene em `/storage/uploads`, **fora** do webroot. Sirva por rota autenticada/controlada ou por `X-Accel-Redirect` (Nginx `internal`).
- Nunca execute nem inclua arquivo enviado.

### 6.7 Webhooks e integrações
- Webhook do Asaas: valide o token configurado, trate de forma **idempotente** (guarde o ID do evento processado) e responda rápido.
- Segredos somente em `.env` (nunca no repositório, nunca em log).
- Chamadas externas com timeout definido.

### 6.8 Tela da TV (`/tv/{token}`)
- Token com no mínimo 32 caracteres aleatórios (`bin2hex(random_bytes(24))`), único por dispositivo, revogável.
- A rota expõe **somente** os avisos ativos do condomínio do dispositivo. Nenhum dado de gerentes, faturas ou outros condomínios.
- Token inválido ou dispositivo inativo: resposta `404` sem detalhes.

## 7. Banco de dados e migrations

- Migrations em `/migrations/NNN_descricao.sql`, numeradas com 3 dígitos, em ordem.
- **Nunca edite uma migration já aplicada.** Para corrigir, crie uma nova.
- `bin/migrate.php` registra versões aplicadas na tabela `schema_migrations` e executa cada arquivo numa transação.
- Chaves primárias `bigint generated always as identity`. Chaves estrangeiras com `ON DELETE` explícito. Índices em colunas de filtro (`condominio_id`, `inicio_em`, `fim_em`, `token`).
- Constraints no banco (NOT NULL, UNIQUE, CHECK) além da validação na aplicação.

## 8. Comportamento da tela da TV

- A página carrega e consulta `/tv/{token}/state` a cada 30–60 s (valor configurável).
- O endpoint retorna JSON com `version` (hash do conjunto de avisos ativos e suas mídias) e a lista de avisos. O JS só atualiza a exibição quando `version` muda.
- Cada consulta atualiza `dispositivos.ultimo_ping`; isso é o heartbeat.
- Service worker (`/public/sw.js`) faz cache do HTML, dos assets, dos avisos e das mídias para funcionar offline. Ao voltar a conexão, ressincroniza.
- Avisos do tipo `urgente` têm prioridade e destaque em tela cheia.
- Não use WebSocket/SSE no PHP-FPM: ele prende workers. Polling é a decisão de arquitetura.

## 9. Testes

- Todo código novo de regra de negócio, Repository e autorização deve vir com teste.
- **Testes de isolamento de tenant são obrigatórios** para qualquer recurso por condomínio. Cenário padrão: usuário gerente A tenta ler/editar/excluir recurso do condomínio de B (por ID direto) e deve receber `404`/negado, sem alteração no banco.
- Teste de autorização por papel nas rotas `/admin` e `/gerente`.
- Teste do endpoint da TV: token válido retorna só avisos ativos e dentro do período; token inválido retorna `404`.
- Use um banco PostgreSQL de teste separado; limpe o estado entre testes.

## 10. Comandos

```bash
composer install
php bin/migrate.php                      # aplica migrations pendentes
php bin/create-admin.php                 # cria superadmin
php -S 127.0.0.1:8080 -t public public/index.php   # servidor de desenvolvimento
vendor/bin/phpunit                       # roda os testes
php bin/cron.php                         # executa o agendador uma vez
```

## 11. Definição de pronto

Uma tarefa só está concluída quando:
1. O código segue as convenções e a separação de camadas deste documento.
2. Há testes cobrindo a mudança, incluindo isolamento de tenant quando aplicável.
3. `vendor/bin/phpunit` passa.
4. Migrations novas são aditivas e aplicam do zero e sobre o estado anterior.
5. Nenhum segredo, token ou senha foi commitado.
6. O `README.md` foi atualizado se houve mudança de configuração, comando ou arquitetura.
7. Dúvidas e decisões assumidas estão listadas na resposta final.

## 12. O que NÃO fazer

- Não adicionar frameworks full-stack, ORMs ou ferramentas de build de front (Node, webpack, etc.).
- Não colocar SQL fora de `/src/Repositories`.
- Não confiar em `condominio_id` vindo do cliente.
- Não usar `|raw` no Twig com conteúdo de usuário.
- Não editar migrations já aplicadas.
- Não armazenar uploads dentro de `/public`.
- Não logar senhas, tokens, chaves de API ou dados sensíveis.
- Não implementar SSE/WebSocket no lado PHP.
- Não refatorar partes não relacionadas à tarefa.

## 13. Ordem sugerida de implementação (MVP)

1. Estrutura base: front controller, roteador, `Config`, `Db`, `Session`, `Response`, Twig.
2. Migration `001_init.sql` e `bin/migrate.php`.
3. Autenticação (login/logout, papéis, CSRF, rate limit) + `bin/create-admin.php`.
4. Painel `/admin`: CRUD de gerentes, condomínios e vínculos.
5. Painel `/gerente`: seletor de condomínio e CRUD de avisos (com testes de isolamento).
6. Tela `/tv/{token}`: página, endpoint de estado, rotação, service worker.
7. Dispositivos: cadastro, token, heartbeat e status.
8. `bin/cron.php`: publicação/expiração de avisos e detecção de dispositivos offline.
9. Auditoria, faturamento (Asaas) e demais itens das fases 2 e 3.
