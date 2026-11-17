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
- PHPMailer (envio de e-mail por SMTP, usado na recuperação de senha). Justificativa: biblioteca pequena e mantida; um cliente SMTP próprio (STARTTLS, autenticação) seria mais arriscado.
- Composer: apenas autoload PSR-4 (`App\` → `src/`) e as bibliotecas citadas acima
- Testes: PHPUnit (dev dependency)

Bibliotecas adicionais permitidas somente se pequenas, mantidas e realmente necessárias (ex.: cliente HTTP simples, geração de QR code). Prefira a biblioteca padrão do PHP.

## 4. Estrutura e responsabilidades

```
/public            index.php (front controller), assets (app.css, tv.css, tv.js), tv/sw.js (service worker da TV)
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
- Senhas **nunca** são guardadas em texto puro: somente o hash Argon2id (`PasswordHasher`: `password_hash(..., PASSWORD_ARGON2ID)` com 19 MiB de memória, 2 iterações e 1 thread, conforme a OWASP), `password_verify` e `password_needs_rehash` (o hash é refeito, sem invalidar a sessão, no login seguinte quando os parâmetros mudarem). Senha: mínimo 10 e máximo 128 caracteres; sem `trim` (espaços fazem parte). Todo cadastro de usuário passa por `UsuarioService`, que faz a validação e o hash.
- Quando o e-mail não existe, o login ainda executa uma verificação de senha com hash falso, para o tempo de resposta não revelar quais e-mails estão cadastrados.
- O `AuthMiddleware` recarrega o usuário do banco a cada requisição: conta desativada/excluída perde o acesso na hora, e a sessão iniciada antes de `usuarios.senha_alterada_em` deixa de valer (a troca de senha derruba os logins antigos).
- No login: `session_regenerate_id(true)`. No logout: destruir sessão e cookie.
- Cookies de sessão: `HttpOnly`, `Secure`, `SameSite=Lax`, nome definido em `.env`.
- Limite de tentativas de login por IP e por e-mail, com bloqueio temporário (valores na seção abaixo).
- Mensagens de login genéricas ("credenciais inválidas"), sem revelar se o e-mail existe.
- Recuperação de senha ("esqueci minha senha") para gerentes e superadmins: token aleatório (`random_bytes`), armazenado **apenas com hash** na tabela `password_resets`, de uso único e com expiração curta (ex.: 30 min). Ao concluir a troca, o token e os demais tokens pendentes são invalidados (a marcação como usado é atômica, então o link vale uma só vez) e as sessões anteriores deixam de valer. O token tem 256 bits (`bin2hex(random_bytes(32))`), vai na URL `/redefinir-senha/{token}` (resposta com `Referrer-Policy: no-referrer`) e o e-mail nunca é logado com ele.
- A resposta do pedido de recuperação é sempre a mesma ("se o e-mail existir, enviaremos instruções"), exista ou não a conta.
- **Rate limit anti-bruteforce** (middleware `RateLimit`, contadores em tabela do PostgreSQL, sem Redis): aplica-se ao login (por IP **e** por e-mail), ao pedido de recuperação de senha (por IP e por e-mail) e à troca de senha por token (por IP). Excedido o limite: bloqueio temporário com resposta `429`, cabeçalho `Retry-After` e mensagem genérica. Nunca logar a senha tentada.
- **Limites atuais** (alterar só com justificativa): login, 5 falhas por e-mail em 15 min bloqueiam o e-mail por 15 min (vale também para e-mails inexistentes) e 30 requisições por IP em 15 min bloqueiam o IP por 15 min; recuperação de senha, 3 pedidos por e-mail e 5 por IP por hora, além de 20 requisições por IP por hora nas rotas; troca de senha por token, 10 tentativas por IP por hora. Chaves com e-mail usam o SHA-256 dele (`RateLimiter::hashDe`).
- **CSRF:** `Csrf` guarda um token de 256 bits na sessão (refeito após o login); o `CsrfMiddleware` o exige em POST/PUT/PATCH/DELETE (campo `_csrf` ou cabeçalho `X-CSRF-Token`). Formulários usam `{{ csrf_token() }}`; a `View` falha se essa função não estiver configurada.
- **Middlewares:** cada rota declara explicitamente os seus (`Pipeline::envolver`), na ordem limite → CSRF → login → papel. As rotas são montadas em `AppFactory`, usada também pelos testes de integração.
- **E-mail:** sem `MAIL_HOST` no `.env`, nada é enviado e o motivo vai para o log, sem o token; o usuário vê sempre a mesma resposta genérica.
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
- **Todo upload passa pelo `UploadService`**: erro do PHP, tamanho (`UPLOAD_MAX_MB`), extensão do nome enviado (só a última), MIME real (`finfo`) coerente com a extensão, categoria permitida (logo: só imagem) e, nas imagens, `getimagesize` coerente e com no máximo 10.000 px por lado. O nome gravado é `bin2hex(random_bytes(16))` + extensão; o enviado é descartado. O `move_uploaded_file` só aceita arquivos realmente enviados por HTTP (caminho forjado é recusado); os testes injetam outro "mover".
- O arquivo só é guardado **depois** de validar o resto do formulário e do escopo do condomínio, e é apagado se a gravação no banco falhar. Trocar ou remover mídia apaga o arquivo antigo só depois de gravar; excluir o aviso apaga o arquivo. `UploadService::remover` só apaga nomes no padrão gerado por nós (sem `../`).
- Mídia e logo são entregues por rotas protegidas (`/gerente/condominios/{id}/avisos/{id}/midia` e `/logo`) com `Content-Type` decidido pela extensão que gravamos, `nosniff` e `Cache-Control: private`; com `MEDIA_ACCEL` o Nginx entrega o arquivo (e reaplica `nosniff` e um CSP restritivo no `location /media/`).
- Envios maiores que o limite recebem 413 (`TamanhoMiddleware`) em vez de um falso "sessão expirada" (o PHP descarta o corpo quando passa de `post_max_size`).

### 6.7 Integrações e financeiro
- Não há integração de cobrança (Asaas ou outra) e não há webhooks: a cobrança é feita em um sistema à parte. O módulo financeiro deste projeto só **exibe** a situação do condomínio e permite **bloquear/desbloquear manualmente** a exibição.
- O módulo financeiro é restrito ao `superadmin`; gerentes não têm acesso a dados financeiros. Todo bloqueio/desbloqueio e toda edição financeira são registrados na auditoria (com motivo).
- Se uma integração externa for adicionada no futuro, registre a decisão no `ROADMAP.md` e siga as regras abaixo.
- Segredos somente em `.env` (nunca no repositório, nunca em log).
- Chamadas externas com timeout definido.

### 6.8 Tela da TV (`/tv/{token}`)
- O "login" da TV/mini PC é feito **somente pela URL com token** (`/tv/{token}`), sem usuário, senha, **sessão nem cookies**. O token tem **12 caracteres** sorteados com `random_int` de um alfabeto de **31 símbolos** sem ambíguos (`23456789ABCDEFGHJKMNPQRSTUVWXYZ`, sem `0 O 1 I L`; ~59 bits), é único por dispositivo e revogável. É guardado **em maiúsculas** (constraint `dispositivos_token_maiusculo`) e **aceito em qualquer caixa** na URL, porque o teclado do controle remoto começa em minúsculas (`TokenDispositivo::normalizar`). Por ser curto, a segurança depende também do rate limit abaixo.
- Rotas (todas só leitura, GET): `/tv/{token}` (página), `/tv/{token}/state` (JSON), `/tv/{token}/media/{arquivo}`, `/tv/{token}/logo?v=...`, `/tv/{token}/tema.css`. Token fora do padrão (12 caracteres `A-Za-z0-9`) nem casa com a rota (404 sem tocar no banco).
- **Rate limit por IP para tokens inválidos** (`TvAcesso`): 30 falhas em 10 min bloqueiam o IP por 15 min; a 31ª falha ainda responde 404 e liga o bloqueio, e daí em diante **todas** as rotas `/tv/*` desse IP respondem `429` com `Retry-After` (senão o atacante continuaria adivinhando). Só falhas contam; consultas válidas e tokens malformados não. O limite é por IP (várias TVs atrás do mesmo NAT compartilham o contador; uma TV com token revogado falha cerca de 1 vez por minuto).
- **Token inválido, inexistente ou de dispositivo revogado: sempre o mesmo `404`** sem detalhes (indistinguíveis entre si, sem enumeração), inclusive com `Referrer-Policy: no-referrer`.
- O `/state` expõe **somente** o necessário para exibir: `bloqueado`, `logo`, `tema`, `avisos`, `version`, `server_time`, `server_epoch`, `intervalo` e `app`. Os avisos são os **ativos do condomínio do dispositivo, que ainda não terminaram e que já começaram ou começam nas próximas 24 h** (`TvService::ANTECEDENCIA_SEGUNDOS`): a janela à frente existe para a TV conseguir exibi-los na hora certa mesmo sem internet; quem decide o que está no ar a cada segundo é a TV, pelo relógio corrigido. Nenhum dado de gerentes, financeiro, endereço ou outros condomínios (`AvisoRepository` filtra por condomínio permitido, que aqui é o do dispositivo).
- **Mídias da TV**: pelo **nome do arquivo** (aleatório, imutável), e não pelo id do aviso: `/tv/{token}/media/{arquivo}`. O controller valida o token, confere que o arquivo pertence a um aviso **visível** (mesmo critério do `/state`) do condomínio do dispositivo e entrega com `X-Accel-Redirect` (Nginx `internal`) ou pelo PHP. Cache `private, max-age=86400`.
- **Token em logs e Referrer**: o token é uma credencial na URL. (a) O Nginx mascara o token no `access_log` (`conf.d/avisos-log.conf`: `/tv/xxxxxxxxxxxx`); o `error_log` do Nginx pode registrar a URL em erros raros. (b) A aplicação nunca registra o token em log. (c) Todas as respostas da TV (inclusive 404 e 429) enviam `Referrer-Policy: no-referrer`.
- **Condomínio desativado ou bloqueado manualmente** (`condominios.ativo = false`; `bloqueado` na Fase 3): o `/state` vem com `bloqueado: true` e sem avisos, e a TV mostra **somente o relógio com o horário atual**, sem avisos e sem anúncios. As mídias passam a dar 404.
- **Aparência**: a TV usa a logo e as cores do condomínio (`/state` traz `tema`, que o JS aplica por CSSOM; `/tema.css` serve a primeira pintura); sem personalização, o padrão da Embracore.
- **Cadastro**: dispositivos são criados em `/admin/dispositivos` (somente superadmin). O token só aparece na página do dispositivo; trocar o token invalida o antigo na hora e limpa o último contato. O gerente **nunca** vê tokens.

### 6.9 Proxy reverso e HTTPS
- O TLS termina em um **Nginx proxy reverso externo** (compartilhado, fora da VM). A VM recebe HTTP puro somente do IP do proxy (firewall); a aplicação nunca é exposta direto à internet.
- O Nginx da VM deve usar `set_real_ip_from <IP do proxy>`, `real_ip_header X-Forwarded-For` e `real_ip_recursive on`. Todo rate limit (login, recuperação de senha, token de TV inválido) usa o IP real; sem isso, todos os clientes pareceriam o mesmo IP e um bloqueio atingiria todo mundo.
- Nunca confie em `X-Forwarded-For`/`X-Forwarded-Proto` vindos de outra origem que não o proxy. A aplicação não lê esses cabeçalhos diretamente: o Nginx já entrega o IP real em `REMOTE_ADDR` e `HTTPS=on` via FastCGI.
- Cookies de sessão seguem `Secure`; HSTS fica no proxy. Mantenha `client_max_body_size` igual nos dois Nginx e acima de `UPLOAD_MAX_MB`.
- Não use Apache: a mídia é servida com `X-Accel-Redirect` (Nginx).

### 6.10 Painel /admin e identidade visual
- Todas as rotas `/admin/*` passam por `Auth` → `Role(superadmin)` e, nas escritas, `Csrf`. Os testes (`AdminTest`) percorrem **todas** as rotas e exigem: visitante → redirecionado ao login; gerente → 403 e nenhum dado alterado. Rota nova em `/admin` entra nessa lista de testes.
- Recurso inexistente: o Service lança `NaoEncontradoException` e a `Application` responde `404` sem registrar como erro (também é o padrão para recurso fora do escopo do gerente).
- O administrador não pode alterar o próprio papel, desativar a própria conta nem se excluir. Exclusões pedem confirmação em página própria (mostrando o que será apagado em cascata), sem JavaScript. Alterações de várias tabelas (ex.: usuário + vínculos) rodam em `Transaction`.
- O hash da senha nunca sai do Service para telas ou listagens, e campos de senha nunca voltam preenchidos.
- **Visual:** a marca vem do `DESIGN.md` (verde #00A67E e grafite #202124), e a linguagem visual atual é: barra superior **branca**, fundo claro quente (#F6F5F2), cartões brancos arredondados com sombra leve, fonte Inter hospedada em `public/assets/fonts` (licença OFL junto), ícones em traço (macro `macros/ui.html.twig`), avatares com inicial, saudação por horário (`Saudacao`), formulários em seções com rótulo acima do campo, estados vazios com ícone e texto amigável, sem gradientes e sem emojis. Layouts: `layouts/base.html.twig` (com login) e `layouts/acesso.html.twig` (entrar, recuperar senha e erros). No celular (≤ 768px) as tabelas viram lista de cartões. Todo CSS fica em `public/assets/app.css` (o CSP proíbe `style` inline e `<script>` inline): cores dinâmicas vão em atributos SVG (`fill`). Botões e links usam o verde escuro `--marca-700` por acessibilidade (contraste 4,5:1). Não use `{% set mensagem %}` ou outros nomes genéricos no layout, para não sobrescrever variáveis das páginas. Para conferir mudanças visuais, o Chromium está instalado na VM: renderize o HTML e tire capturas com `chromium --headless --no-sandbox --screenshot=... --virtual-time-budget=2500` (o tempo virtual evita fotografar a animação de entrada pela metade).

### 6.11 Painel /gerente (multi-tenant)
- As rotas são **escopadas pela URL**: `/gerente/condominios/{cid}/...`. Pipeline: `Auth` → `Role(gerente)` → `EscopoGerenteMiddleware` → (upload) `TamanhoMiddleware` → (escrita) `Csrf`.
- `EscopoGerenteMiddleware` lê **do banco, a cada requisição**, os condomínios **ativos** vinculados ao gerente. Condomínio desativado pelo admin ou vínculo removido deixam de valer na hora. O superadmin recebe 403 nessas rotas (modo suporte fica para a Fase 2).
- Todo controller começa com `EscopoGerente::exigir($request, $cid)`: condomínio fora da lista → `NaoEncontradoException` → **404** (nunca 403, para não revelar que ele existe).
- Todo método de `AvisoRepository` recebe a lista de condomínios permitidos e filtra por **ela e pelo condomínio pedido** (`condominio_id = :cid AND condominio_id = ANY(:permitidos)`); aviso de outro condomínio pedido por ID resulta em `null`/`false`. Criação usa `INSERT ... SELECT ... WHERE :cid = ANY(:permitidos)`.
- O `condominio_id` de um formulário é **ignorado**: o aviso nasce no condomínio da URL validada, e a edição nunca muda o dono do aviso.
- Qualquer rota nova por condomínio entra em `GerenteIsolamentoTest` (gerente A × condomínio B → 404, sem alteração no banco nem nos arquivos, sem vazar dados) e ganha teste de repositório. Os testes foram validados com mutações (removendo os filtros, os testes falham).
- Datas: o formulário usa `datetime-local` em horário de Brasília; `DataHora` converte de/para UTC (epoch). O texto do aviso é puro (escapado pelo Twig; quebras de linha mantidas por CSS `white-space: pre-line`).
- As cores do condomínio chegam à tela por `/gerente/condominios/{cid}/tema.css` (variáveis `--tv-*`, validadas como `#RRGGBB`), porque o CSP não permite estilo inline. A cor do texto sobre a cor do condomínio é escolhida pelo maior contraste (`AparenciaService::corDoTexto`).

## 7. Banco de dados e migrations

- Migrations em `/migrations/NNN_descricao.sql`, numeradas com 3 dígitos, em ordem.
- **Nunca edite uma migration já aplicada.** Para corrigir, crie uma nova.
- `bin/migrate.php` registra versões e o checksum de cada arquivo na tabela `schema_migrations` e executa cada arquivo numa transação, com lock para evitar execuções simultâneas. Se uma migration já aplicada for alterada, a execução é interrompida. Para usar um usuário com permissão de DDL, defina `MIGRATE_DB_USER` e `MIGRATE_DB_PASS` no `.env`.
- Testes que usam o banco herdam de `Tests\DatabaseTestCase`, que recria o schema do banco `*_test` antes de cada teste e se recusa a rodar em banco que não termine em `_test`.
- Chaves primárias `bigint generated always as identity`. Chaves estrangeiras com `ON DELETE` explícito. Índices em colunas de filtro (`condominio_id`, `inicio_em`, `fim_em`, `token`).
- Constraints no banco (NOT NULL, UNIQUE, CHECK) além da validação na aplicação.

## 8. Comportamento da tela da TV

- A página carrega e consulta `/tv/{token}/state` a cada 30–60 s (`TV_POLL_SECONDS` no `.env`, padrão 45; o servidor limita a 30–60). Em falha, a TV espera mais (até 5 min) e volta ao normal quando a conexão volta; há uma variação de ±10% no intervalo para as TVs não consultarem todas no mesmo segundo.
- O endpoint retorna JSON com `version` (hash de tudo que muda a exibição: avisos, mídias, logo, cores e modo bloqueado; o horário do servidor e o intervalo **não** entram) e a lista de avisos. O JS só redesenha quando `version` muda. O campo `app` é a versão do `tv.js`/`tv.css`: quando muda (deploy), a TV se recarrega sozinha; além disso ela se recarrega a cada 6 h, para liberar memória em aparelhos fracos.
- Cada consulta atualiza `dispositivos.ultimo_ping`; isso é o heartbeat.
- Service worker em **`/public/tv/sw.js`** (escopo `/tv/`, para não controlar o painel nem o login): página e `/state` com rede primeiro (cópia só se a rede falhar), mídias com cache primeiro e atendimento de `Range` a partir da cópia (o `<video>` precisa), `/assets/` com cópia primeiro e atualização em segundo plano. **Respostas de erro nunca entram no cache e um 404 da rede nunca é trocado pela cópia antiga**, para um dispositivo revogado parar de exibir. O JS pré-carrega as mídias dos avisos atuais e futuros e apaga do cache as que não existem mais. A página também guarda o último estado e o deslocamento do relógio em `localStorage`. **O Service Worker só existe em HTTPS (ou localhost)**: em HTTP puro a TV funciona online, mas sem cache offline.
- **Offline e relógio do dispositivo:** sem internet, a TV não consegue perguntar ao servidor o que está ativo; ela usa a lista em cache e decide o que exibir pelo relógio local. Por isso o JSON de `/tv/{token}/state` deve incluir, em cada aviso, `inicio_em` e `fim_em` (UTC, ISO 8601) e, no topo, `server_time`. O JS calcula `deslocamento = server_time - Date.now()` a cada resposta bem-sucedida e usa `Date.now() + deslocamento` para filtrar os avisos: o que venceu durante a queda sai da tela e o agendado entra no horário certo. Isso protege contra mini PCs com relógio errado (sem NTP/bateria de BIOS). Se nunca houve resposta do servidor, usa o relógio local sem correção.
- **Faixa de avisos fixos e urgentes + carrossel.** Os tipos `urgente` e `fixo` (`AvisoService::TIPOS_FIXOS`) **não entram no carrossel**: ficam numa faixa reservada no **topo da TV (sempre 40% da altura da tela, largura total, logo abaixo da barra do topo)**, mostrando **todos ao mesmo tempo** (urgentes primeiro, depois fixos, pelo início). A faixa **não cresce**: com mais avisos, cada cartão fica menor e a letra encolhe (1 aviso = 1 coluna; 2 e 3 = lado a lado; 4 = 2x2; 5 e 6 = 3x2; 7 ou mais = 2 linhas com até 5 colunas; classes `tv-fixos-cols-N`/`tv-fixos-rows-N` definidas pelo JS, textos limitados por linhas com `-webkit-line-clamp`). Os demais tipos (`comum`, `manutencao`, `assembleia`) continuam girando no espaço de baixo, em versão compacta. **A faixa mostra só texto**: foto e vídeo só aparecem no carrossel (o painel avisa o gerente). Urgente é vermelho; fixo usa a cor secundária do condomínio. Só a faixa no ar: o espaço de baixo mostra o relógio ("Sem outros avisos no momento"); só o carrossel: tela inteira como antes; nada no ar: logo e relógio grande ("Nenhum aviso no momento"). Mudar o tamanho da faixa = trocar `flex: 0 0 40%` em `.tv-fixos` (`tv.css`); `TvArquivosTest` e o teste no Chromium medem esse valor.
- **Visual da TV** (mesmo estilo dos painéis): fundo único quente (`#F6F5F2`) para a faixa e para o carrossel; **tudo são cartões** brancos arredondados com sombra suave, **sem moldura lateral, sem fundo próprio na faixa e sem linhas divisórias**; os cartões da faixa alinham exatamente com as bordas do cartão do carrossel (margens fixas: só a letra encolhe, dentro de `.tv-fixo-conteudo`); etiquetas em pílula com ícone (urgente = vermelho, fixo = cor secundária do condomínio); barra do topo na cor do condomínio, enxuta (~4,5em de altura), com a **hora sempre branca**; nos modos "só fixos" e "nenhum aviso" aparece só o relógio grande (o da barra fica escondido). `TvArquivosTest` guarda essas regras e `tests` no Chromium medem alinhamento, altura e cores.
- Rotação do carrossel: cada aviso fica 12 s mais 40 ms por caractere do texto (até +13 s); vídeo toca até o fim (limite de 60 s). Avisos da faixa não rodam: ficam enquanto estiverem no período. O relógio mostrado é o de Brasília (UTC-3, sem horário de verão desde 2019) pelo horário corrigido do servidor.
- Não use WebSocket/SSE no PHP-FPM: ele prende workers. Polling é a decisão de arquitetura.

### Dispositivo principal: Fire Stick (Fire OS)
A tela da TV deve funcionar bem no navegador de um Fire Stick (Silk ou Fully Kiosk Browser), que tem pouca memória e processamento. Regras para o front da TV:
- **Leve:** poucas dependências de JS, sem animações pesadas, sem recursos que cresçam indefinidamente na memória. HTMX/Alpine só onde forem realmente necessários na tela da TV.
- **Compatibilidade:** não use recursos de JS/CSS muito recentes sem fallback; a versão do motor do navegador varia conforme o modelo e a versão do Fire OS. Valide em um Fire Stick real.
- **Layout:** pensado para 1920×1080 (16:9), tipografia grande e margens de segurança, pois algumas TVs cortam as bordas (overscan).
- **Vídeo:** MP4 com H.264 + AAC, até 1080p e 30 fps, sem depender de recursos como Picture-in-Picture. Vídeos curtos e leves; o gerente deve ser orientado sobre isso no upload.
- **Sem interação:** a TV não depende de clique ou toque. A única interação é o gerente digitar a URL com o controle remoto na instalação, por isso o token curto (seção 6.8).
- **Service worker** e cache offline (seção 8) devem ser testados no navegador do Fire Stick, não só no Chromium do desktop.
- **ES5 obrigatório em `tv.js` e `sw.js`** (sem setas, `let`/`const`, template string, `fetch` na página, etc.) e **CSS da TV sem `aspect-ratio`, grid, `gap`, `clamp`, container queries**: `tests/TvArquivosTest` barra isso. Texto do servidor entra na tela **só com `textContent`** (nunca `innerHTML`).
- **Como testar a TV de verdade:** o Chromium da VM aceita `localhost` como contexto seguro, então dá para exercitar rotação, Service Worker e modo offline num navegador real (derrubar o servidor = TV sem internet) com um cliente do protocolo DevTools. A lógica pura do `tv.js` (`window.TvLogica`) tem testes em `tests/js/logica.html`, executados pelo PHPUnit (`TvJsLogicaTest`).
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
