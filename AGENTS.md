# AGENTS.md

Instruções para agentes de código que trabalham neste repositório. Leia este arquivo inteiro antes de qualquer alteração e leia também o `README.md` para o contexto do produto e o `ROADMAP.md` para saber o que já foi feito e o que falta.

## 1. Contexto do projeto

**Avisos-Cond** é um mural digital de avisos para condomínios. O gerente cadastra avisos; uma TV no elevador os exibe via navegador. O dispositivo principal é o **Amazon Fire TV Stick (Fire OS)**; mini PC com Chromium e outros dispositivos também são suportados. A Embracore (fornecedor) administra gerentes, condomínios, dispositivos e a situação financeira (apenas visualização e bloqueio manual; a cobrança é feita em um sistema à parte).

Três superfícies: `/admin` (Embracore), `/gerente` (gerente) e `/tv/{token}` (exibição pública por token).

## 2. Princípios inegociáveis

1. **Simplicidade e transparência.** Código explícito, sem "mágica". O responsável pelo projeto precisa conseguir ler e entender tudo. Prefira o óbvio ao engenhoso.
2. **Poucas dependências.** Não adicione pacotes sem necessidade clara. Antes de rodar `composer require`, pare e justifique em comentário no PR/commit. Proibido instalar frameworks full-stack (Laravel, Symfony, etc.) e ORMs.
3. **Isolamento entre condomínios é a regra de segurança nº 1.** Um gerente nunca pode ler ou alterar dados de condomínio ao qual não está vinculado. Veja a seção 6.
4. **Não invente requisitos.** Se algo estiver ambíguo, implemente o mínimo coerente com este documento e o README, e registre a dúvida no final da sua resposta.
5. **Mudanças pequenas e verificáveis.** Um assunto por vez, com testes.
   **Toda feature deve vir acompanhada de testes**, no mesmo commit ou no mesmo conjunto de commits que a entrega. Feature sem teste não é considerada pronta, não vai para o push e não pode ser marcada como concluída no `ROADMAP.md`.
6. **Commits frequentes e push por feature concluída.** Faça commit sempre que houver alterações (feature, correção ou atualização de código/documentação), com mensagem em português explicando o que foi feito. Faça `git push` sempre que uma feature for finalizada (testes passando).
7. **`ROADMAP.md` sempre fiel ao código.** Ele é a fonte de verdade do progresso. Toda mudança que conclua, inicie, adie, adicione ou remova um item do projeto deve atualizar o `ROADMAP.md` **no mesmo commit** do código. Só marque `[x]` o que estiver implementado, testado e commitado; use `[~]` para em andamento. Antes de começar uma tarefa, confira no `ROADMAP.md` onde ela se encaixa; se não estiver listada, não invente: registre a dúvida (princípio 4) e, se o escopo realmente mudar, atualize `README.md`, `AGENTS.md` e `ROADMAP.md` juntos.

## 3. Stack e versões

- PHP 8.3 ou superior (`declare(strict_types=1);` em todo arquivo PHP). A VM usa o PHP 8.4 do Debian 13, que não traz o 8.3; o código deve rodar em ambos.
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
  /Services        regras de negócio (Auth, Scheduler, Upload, Audit)
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
- No contexto do gerente, a lista de condomínios permitidos é lida **do banco** (tabela `usuario_condominio`) a **cada requisição** em `/gerente/*`, nunca do cliente e nunca de cache na sessão. Assim, um vínculo removido pelo admin vale imediatamente. O custo é uma consulta indexada por chave, desprezível.
- Cada aviso pertence a **um único** condomínio. O gerente cria avisos individualmente para cada condomínio; não existe publicação em lote para vários condomínios.
- Todo método de Repository que acessa dados de condomínio recebe os IDs permitidos e usa `WHERE condominio_id = ANY(:ids)` (ou equivalente). Não existe consulta de `avisos`, `dispositivos`, `condominio_financeiro` etc. sem esse filtro no contexto do gerente.
- Ao receber um ID de recurso (`/gerente/avisos/42/editar`), verifique que o recurso pertence a um condomínio permitido **antes** de qualquer leitura ou escrita. Resposta para recurso fora do escopo: `404`.
- Toda funcionalidade nova que toque dados por condomínio **precisa** de teste de isolamento (veja seção 9).

### 6.2 Autenticação e sessão
- Senhas com `password_hash($senha, PASSWORD_ARGON2ID)` e `password_verify`. Use `password_needs_rehash`.
- No login: `session_regenerate_id(true)`. No logout: destruir sessão e cookie.
- Cookies de sessão: `HttpOnly`, `Secure`, `SameSite=Lax`, nome definido em `.env`.
- Limite de tentativas de login por IP e por usuário (tabela ou cache), com atraso/bloqueio temporário.
- Mensagens de login genéricas ("credenciais inválidas"), sem revelar se o e-mail existe.
- Recuperação de senha ("esqueci minha senha") para gerentes e superadmins: token aleatório (`random_bytes`), armazenado **apenas com hash** na tabela `password_resets`, de uso único e com expiração curta (ex.: 30 min). Ao concluir a troca, invalide o token e as demais sessões do usuário.
- A resposta do pedido de recuperação é sempre a mesma ("se o e-mail existir, enviaremos instruções"), exista ou não a conta.
- **Rate limit anti-bruteforce** (middleware `RateLimit`, contadores em tabela do PostgreSQL, sem Redis): aplica-se ao login (por IP **e** por e-mail), ao pedido de recuperação de senha (por IP e por e-mail) e à troca de senha por token (por IP). Excedido o limite: bloqueio temporário com resposta `429` e mensagem genérica. Nunca logar a senha tentada.
- O envio de e-mail (recuperação de senha) usa SMTP configurado no `.env`. Se for necessária uma biblioteca (ex.: PHPMailer), justifique conforme a regra de dependências (seção 2.2).

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
- Valide tamanho, extensão **e** MIME real com `finfo`. Tipos permitidos: **imagens** (JPEG, PNG, WebP) e **vídeo curto** (MP4). **PDF não é permitido**: a TV/mini PC exibe somente fotos e vídeos. SVG também não (risco de XSS).
- A logo do condomínio segue as mesmas regras (somente JPEG, PNG ou WebP).
- Nome do arquivo gerado aleatoriamente; nunca use o nome enviado.
- Armazene em `/storage/uploads`, **fora** do webroot. Sirva por rota autenticada/controlada ou por `X-Accel-Redirect` (Nginx `internal`). Para a TV, a mídia é servida por `/tv/{token}/media/{id}` (ver 6.8).
- Nunca execute nem inclua arquivo enviado.

### 6.7 Integrações e financeiro
- Não há integração de cobrança (Asaas ou outra) e não há webhooks: a cobrança é feita em um sistema à parte. O módulo financeiro deste projeto só **exibe** a situação do condomínio e permite **bloquear/desbloquear manualmente** a exibição.
- O módulo financeiro é restrito ao `superadmin`; gerentes não têm acesso a dados financeiros. Todo bloqueio/desbloqueio e toda edição financeira são registrados na auditoria (com motivo).
- Se uma integração externa for adicionada no futuro, registre a decisão no `ROADMAP.md` e siga as regras abaixo.
- Segredos somente em `.env` (nunca no repositório, nunca em log).
- Chamadas externas com timeout definido.

### 6.8 Tela da TV (`/tv/{token}`)
- O "login" da TV/mini PC é feito **somente pela URL com token** (`/tv/{token}`), sem usuário e senha. O token precisa ser curto o bastante para ser digitado no dispositivo: **12 caracteres** aleatórios (~60 bits), gerados com `random_int` a partir de um alfabeto de 32 símbolos sem caracteres ambíguos (sem `0 O 1 I L`), único por dispositivo, revogável. Por ser mais curto que o ideal, a segurança depende também do rate limit abaixo.
- Rate limit por IP para tokens inválidos em `/tv/*` (bloqueio temporário com `429`), para impedir adivinhação por força bruta.
- A rota expõe **somente** os avisos ativos do condomínio do dispositivo. Nenhum dado de gerentes, financeiro ou outros condomínios.
- Token inválido ou dispositivo inativo: resposta `404` sem detalhes.
- **Mídias da TV**: o Nginx serve `/media/` como `internal`, então a TV nunca acessa o arquivo direto. Ela pede `/tv/{token}/media/{id}`; o controller valida o token, confere que a mídia pertence a um aviso ativo do condomínio do dispositivo e responde com `X-Accel-Redirect`.
- **Token em logs e Referrer**: o token aparece na URL, então (a) configure o Nginx para mascarar o token no `access_log` das rotas `/tv/`, (b) nunca registre o token em logs da aplicação, e (c) envie `Referrer-Policy: no-referrer` nessas rotas.
- **Condomínio bloqueado manualmente** pelo superadmin (`condominios.bloqueado`): a TV mostra **somente o relógio com o horário atual**, sem avisos e sem anúncios.
- **Aparência**: a TV usa a logo e o esquema de cores definidos pelo gerente para o condomínio; se não houver seleção, usa a logo e as cores padrão da Embracore.

### 6.9 Proxy reverso e HTTPS
- O TLS termina em um **Nginx proxy reverso externo** (compartilhado, fora da VM). A VM recebe HTTP puro somente do IP do proxy (firewall); a aplicação nunca é exposta direto à internet.
- O Nginx da VM deve usar `set_real_ip_from <IP do proxy>`, `real_ip_header X-Forwarded-For` e `real_ip_recursive on`. Todo rate limit (login, recuperação de senha, token de TV inválido) usa o IP real; sem isso, todos os clientes pareceriam o mesmo IP e um bloqueio atingiria todo mundo.
- Nunca confie em `X-Forwarded-For`/`X-Forwarded-Proto` vindos de outra origem que não o proxy. A aplicação não lê esses cabeçalhos diretamente: o Nginx já entrega o IP real em `REMOTE_ADDR` e `HTTPS=on` via FastCGI.
- Cookies de sessão seguem `Secure`; HSTS fica no proxy. Mantenha `client_max_body_size` igual nos dois Nginx e acima de `UPLOAD_MAX_MB`.
- Não use Apache: a mídia é servida com `X-Accel-Redirect` (Nginx).

## 7. Banco de dados e migrations

- Migrations em `/migrations/NNN_descricao.sql`, numeradas com 3 dígitos, em ordem.
- **Nunca edite uma migration já aplicada.** Para corrigir, crie uma nova.
- `bin/migrate.php` registra versões e o checksum de cada arquivo na tabela `schema_migrations` e executa cada arquivo numa transação, com lock para evitar execuções simultâneas. Se uma migration já aplicada for alterada, a execução é interrompida. Para usar um usuário com permissão de DDL, defina `MIGRATE_DB_USER` e `MIGRATE_DB_PASS` no `.env`.
- Testes que usam o banco herdam de `Tests\DatabaseTestCase`, que recria o schema do banco `*_test` antes de cada teste e se recusa a rodar em banco que não termine em `_test`.
- Chaves primárias `bigint generated always as identity`. Chaves estrangeiras com `ON DELETE` explícito. Índices em colunas de filtro (`condominio_id`, `inicio_em`, `fim_em`, `token`).
- Constraints no banco (NOT NULL, UNIQUE, CHECK) além da validação na aplicação.

## 8. Comportamento da tela da TV

- A página carrega e consulta `/tv/{token}/state` a cada 30–60 s (valor configurável).
- O endpoint retorna JSON com `version` (hash do conjunto de avisos ativos e suas mídias) e a lista de avisos. O JS só atualiza a exibição quando `version` muda.
- Cada consulta atualiza `dispositivos.ultimo_ping`; isso é o heartbeat.
- Service worker (`/public/sw.js`) faz cache do HTML, dos assets, dos avisos e das mídias para funcionar offline. Ao voltar a conexão, ressincroniza.
- **Offline e relógio do dispositivo:** sem internet, a TV não consegue perguntar ao servidor o que está ativo; ela usa a lista em cache e decide o que exibir pelo relógio local. Por isso o JSON de `/tv/{token}/state` deve incluir, em cada aviso, `inicio_em` e `fim_em` (UTC, ISO 8601) e, no topo, `server_time`. O JS calcula `deslocamento = server_time - Date.now()` a cada resposta bem-sucedida e usa `Date.now() + deslocamento` para filtrar os avisos: o que venceu durante a queda sai da tela e o agendado entra no horário certo. Isso protege contra mini PCs com relógio errado (sem NTP/bateria de BIOS). Se nunca houve resposta do servidor, usa o relógio local sem correção.
- Avisos do tipo `urgente` têm prioridade e destaque em tela cheia.
- Não use WebSocket/SSE no PHP-FPM: ele prende workers. Polling é a decisão de arquitetura.

### Dispositivo principal: Fire Stick (Fire OS)
A tela da TV deve funcionar bem no navegador de um Fire Stick (Silk ou Fully Kiosk Browser), que tem pouca memória e processamento. Regras para o front da TV:
- **Leve:** poucas dependências de JS, sem animações pesadas, sem recursos que cresçam indefinidamente na memória. HTMX/Alpine só onde forem realmente necessários na tela da TV.
- **Compatibilidade:** não use recursos de JS/CSS muito recentes sem fallback; a versão do motor do navegador varia conforme o modelo e a versão do Fire OS. Valide em um Fire Stick real.
- **Layout:** pensado para 1920×1080 (16:9), tipografia grande e margens de segurança, pois algumas TVs cortam as bordas (overscan).
- **Vídeo:** MP4 com H.264 + AAC, até 1080p e 30 fps, sem depender de recursos como Picture-in-Picture. Vídeos curtos e leves; o gerente deve ser orientado sobre isso no upload.
- **Sem interação:** a TV não depende de clique ou toque. A única interação é o gerente digitar a URL com o controle remoto na instalação, por isso o token curto (seção 6.8).
- **Service worker** e cache offline (seção 8) devem ser testados no navegador do Fire Stick, não só no Chromium do desktop.
- O comportamento em mini PC (Chromium kiosk) continua suportado e não deve ser quebrado.

## 9. Testes

- **Toda feature nova vem acompanhada de testes**, sem exceção: regra de negócio, Repository, Controller/rota, autorização, validação de entrada, upload, endpoint da TV e comando de `bin/`. Correção de bug também ganha um teste que falha antes da correção e passa depois.
- Os testes cobrem o caminho feliz **e** os casos de erro e de borda (entrada inválida, permissão negada, recurso inexistente, período expirado, etc.).
- Se algo for realmente impossível de testar automaticamente (ex.: comportamento no Fire Stick físico), registre no `ROADMAP.md` como item de teste manual e descreva o que foi verificado, em vez de omitir o teste.
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
2. A feature tem testes automatizados cobrindo a mudança (caminho feliz e erros), incluindo isolamento de tenant quando aplicável. Sem testes, a tarefa não está pronta.
3. `vendor/bin/phpunit` passa.
4. Migrations novas são aditivas e aplicam do zero e sobre o estado anterior.
5. Nenhum segredo, token ou senha foi commitado.
6. O `README.md` foi atualizado se houve mudança de configuração, comando ou arquitetura.
7. O `ROADMAP.md` foi atualizado no mesmo commit (itens concluídos marcados com data; novas decisões/pendências registradas).
8. Dúvidas e decisões assumidas estão listadas na resposta final.

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
- Não aceitar PDF nem SVG em uploads; a TV exibe somente fotos e vídeos.
- Não implementar integração com n8n (decisão adiada); não implementar widget de aniversariantes (removido por questões de LGPD).

## 13. Ordem sugerida de implementação (MVP)

1. Estrutura base: front controller, roteador, `Config`, `Db`, `Session`, `Response`, Twig.
2. Migration `001_init.sql` e `bin/migrate.php`.
3. Autenticação (login/logout, papéis, CSRF, rate limit) + `bin/create-admin.php`.
4. Painel `/admin`: CRUD de gerentes, condomínios e vínculos.
5. Painel `/gerente`: seletor de condomínio e CRUD de avisos (com testes de isolamento).
6. Tela `/tv/{token}`: página, endpoint de estado, rotação, service worker.
7. Dispositivos: cadastro, token e heartbeat (`ultimo_ping`). O painel de status online/offline fica para a Fase 2.
8. `bin/cron.php`: publicação/expiração de avisos. A detecção de dispositivos offline entra na Fase 2.
9. Recuperação de senha e personalização visual do condomínio (logo e cores) podem ser feitas junto dos passos 3 e 5.
10. Auditoria, situação financeira (visualização e bloqueio manual) e demais itens das fases 2 e 3.

### Cron (`bin/cron.php`)
- Roda a cada minuto, então **nunca pode haver duas execuções simultâneas**: use `flock` num arquivo de lock ou `pg_try_advisory_lock` e encerre silenciosamente se não obtiver o lock.
- Todas as tarefas devem ser **idempotentes** (ex.: publicar/expirar aviso já tratado não faz nada; marcar um dispositivo como offline mais de uma vez não duplica registros).
- Falha em uma tarefa é registrada em log e não impede as demais.
