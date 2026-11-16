# Roadmap — Avisos-Cond

Caminho do início à conclusão do projeto. **Este arquivo é a fonte de verdade do progresso**: deve refletir fielmente o estado do código (veja a regra em `AGENTS.md`, seções 2.7 e 11).

## Como manter este arquivo

- Marque `[x]` **somente** quando o item estiver implementado, **com testes** (`vendor/bin/phpunit` passando) e commitado. Toda feature deve vir acompanhada de testes; item sem teste fica `[~]`.
- Use `[~]` para item em andamento e `[ ]` para item não iniciado.
- Toda tarefa que conclui, inicia, adia, cria ou remove um item **atualiza este arquivo no mesmo commit** da mudança de código.
- Ao terminar um item, anote a data (AAAA-MM-DD) ao final da linha. Ex.: `- [x] Migration 001_init.sql (2026-12-01)`.
- Decisões que mudam o escopo vão para "Decisões e pendências" ao final, com data.
- Não invente itens: o escopo vem do `README.md` e do `AGENTS.md`. Se o escopo mudar, atualize os três arquivos juntos.

**Status atual:** Fase 1 em andamento. Blocos 1.1 a 1.4 concluídos (estrutura base, banco, autenticação, painel `/admin`); próximo: 1.5 (painel `/gerente`).

---

## Fase 0 — Definição e documentação

- [x] README.md com visão geral, stack, estrutura e modelo de dados
- [x] AGENTS.md com regras para agentes de código
- [x] Decisões de escopo fechadas (avisos individuais por condomínio, token curto de TV, sem PDF, sem n8n, sem aniversariantes)
- [x] ROADMAP.md criado
- [x] Repositório com remote configurado (2026-11-16)

## Fase 1 — MVP

Objetivo: um gerente cadastra avisos e a TV do condomínio os exibe, com rotação e cache offline.

### 1.1 Estrutura base
- [x] `composer.json` (autoload PSR-4, FastRoute, Twig, PHPUnit dev) (2026-11-16)
- [x] `public/index.php` (front controller) e roteador (2026-11-16)
- [x] `Support`: Config (.env), Db (PDO), Session, Response, Validator (+ Request, Logger, View) (2026-11-16)
- [x] Twig configurado com auto-escape e layouts base (2026-11-16)
- [x] Tratamento global de erros (log em `storage/logs`, mensagem genérica) (2026-11-16)
- [x] Cabeçalhos de segurança (CSP, nosniff, Referrer-Policy, X-Frame-Options) (2026-11-16)
- [x] `.env.example`, `phpunit.xml` e banco PostgreSQL de teste (2026-11-16)

### 1.2 Banco de dados
- [x] `bin/migrate.php` com `schema_migrations` e transação por arquivo (+ lock, checksum que barra edição de migration aplicada, usuário de DDL opcional) (2026-11-16)
- [x] `001_init.sql`: usuarios, condominios, usuario_condominio, avisos, dispositivos (2026-11-16)
- [x] Tabelas de apoio: password_resets, rate_limits (`002_tabelas_apoio.sql`) (2026-11-16)
- [x] Índices e constraints (condominio_id, inicio_em, fim_em, token) (2026-11-16)

### 1.3 Autenticação e segurança
- [x] Login/logout (Argon2id, `session_regenerate_id`, cookies seguros) (2026-11-16)
- [x] Middleware Auth, Role (`superadmin`, `gerente`) e Csrf (2026-11-16)
- [x] Middleware RateLimit (login por IP e por e-mail) (2026-11-16)
- [x] Recuperação de senha por e-mail (token com hash, uso único, expiração) + rate limit (2026-11-16)
- [x] `bin/create-admin.php` (2026-11-16)
- [x] Testes: autorização por papel em `/admin` e `/gerente` (2026-11-16)
- [ ] Validar o envio real do e-mail de recuperação com um servidor SMTP configurado (os testes usam um mailer falso)

### 1.4 Painel `/admin` (Embracore)
- [x] CRUD de gerentes e administradores (criar, editar, ativar/desativar, redefinir senha, excluir com confirmação) (2026-11-16)
- [x] CRUD de condomínios (exclusão mostra o que será apagado em cascata) (2026-11-16)
- [x] Vínculo gerente ↔ condomínios (N:N), editável pelo usuário e pelo condomínio (2026-11-16)
- [x] Logo/cores padrão da Embracore (usadas quando o condomínio não personaliza) (`AparenciaService`) (2026-11-16)
- [ ] Trocar `public/assets/logo-embracore.svg` (provisória) pela logo oficial da Embracore
- [ ] Hospedar a fonte Inter localmente em `public/assets` (o CSP não permite CDN; hoje cai na fonte do sistema)
- [ ] Revisar o visual do painel em um navegador real (a VM não tem navegador; só o HTML foi testado)

### 1.5 Painel `/gerente`
- [ ] Lista de condomínios vinculados (lidos do banco a cada requisição) e seletor ativo
- [ ] CRUD de avisos (título, texto, tipo, imagem/vídeo, início/fim)
- [ ] Upload seguro (JPEG/PNG/WebP/MP4, MIME real, nome aleatório, fora do webroot)
- [ ] Personalização visual do condomínio (logo e cores)
- [ ] Pré-visualização do aviso na TV
- [ ] Testes de isolamento de tenant para avisos e personalização (gerente A × condomínio B → 404)

### 1.6 Dispositivos e tela da TV
- [ ] Cadastro de dispositivo e geração de token (12 caracteres, alfabeto sem ambíguos), revogável
- [ ] `/tv/{token}`: página com rotação, relógio, logo e cores
- [ ] `/tv/{token}/state` (JSON com `version`, avisos, `inicio_em`/`fim_em`, `server_time`) e heartbeat (`ultimo_ping`)
- [ ] `/tv/{token}/media/{id}` com validação de token e `X-Accel-Redirect`
- [ ] Avisos `urgente` com destaque em tela cheia
- [ ] Rate limit de tokens inválidos em `/tv/*`; token inválido → 404
- [ ] Service worker (cache de HTML, assets, avisos e mídias) e ajuste de relógio offline
- [ ] Mascaramento do token no log do Nginx e `Referrer-Policy: no-referrer`
- [ ] Testes: endpoint da TV (só avisos ativos e no período; token inválido → 404)

### 1.7 Agendador
- [ ] `bin/cron.php` com lock (sem execução simultânea), tarefas idempotentes
- [ ] Publicação e expiração de avisos agendados

### 1.8 Entrega do MVP
- [ ] Configuração de Nginx/PHP-FPM e cron documentada e testada em servidor
- [ ] Proxy reverso externo configurado (TLS, `X-Forwarded-*`, upload) e Nginx da VM com IP real (`set_real_ip_from`) e `HTTPS on`
- [ ] Firewall da VM liberando a porta HTTP apenas para o IP do proxy
- [ ] Teste: rate limit enxerga o IP real do cliente (não o do proxy)
- [ ] Rotina de backup (pg_dump + `/storage/uploads`)
- [ ] Teste em hardware real: **Fire Stick (Fire OS) com Fully Kiosk Browser e com Silk** (dispositivo principal), incluindo service worker offline, vídeo MP4 H.264, abertura após reinício e proteção de tela desativada
- [ ] Teste em hardware real: mini PC com Chromium kiosk (opção secundária)
- [ ] Validar o passo a passo de instalação do Fire Stick no README e ajustá-lo conforme o que for observado
- [ ] README atualizado com o que foi entregue

## Fase 2 — Operação e gestão

- [ ] Painel de status online/offline dos dispositivos
- [ ] Detecção de dispositivos offline no cron
- [ ] Pareamento por código (`codigo_pareamento`)
- [ ] Agendamento avançado (recorrência)
- [ ] Modelos prontos de aviso (falta de água, elevador, dedetização, assembleia)
- [ ] QR code opcional no aviso
- [ ] Auditoria (tabela `auditoria`, registro de ações) e modo suporte do superadmin como gerente

## Fase 3 — Financeiro (visualização e bloqueio manual) e extras

A cobrança é feita em um sistema à parte; aqui o financeiro serve apenas para consulta e bloqueio manual.

- [ ] Migration `condominio_financeiro` (situação, valor mensal, dia de vencimento, observações) e campos `bloqueado`/`bloqueio_motivo` em `condominios`
- [ ] Painel `/admin` de situação financeira por condomínio (listagem, filtros por situação, edição manual)
- [ ] Bloqueio/desbloqueio manual da exibição, com motivo e registro na auditoria
- [ ] TV de condomínio bloqueado mostra somente o relógio
- [ ] Testes: financeiro restrito ao superadmin (gerente → negado) e TV bloqueada sem avisos
- [ ] Widget de clima
- [ ] Notificações externas (WhatsApp/e-mail)
- [ ] Perfil zelador

## Fora do escopo (decidido)

- Aviso em lote para vários condomínios (cada aviso é individual)
- PDF e SVG em uploads
- Widget de aniversariantes (LGPD)
- Integração n8n (adiada; reavaliar no futuro)
- Cobrança dentro do sistema: Asaas, boletos, Pix, faturas, webhooks e suspensão automática por inadimplência (cobrança fica em sistema à parte)
- WebSocket/SSE no PHP (polling é a arquitetura)

## Decisões e pendências

- 2026-11-16: token da TV com 12 caracteres (~60 bits) compensado por rate limit por IP.
- 2026-11-16: vínculos do gerente lidos do banco a cada requisição (sem cache em sessão).
- 2026-11-16: cobrança removida do projeto (sem Asaas/webhook). Financeiro será só visualização + bloqueio manual pelo superadmin.
- 2026-11-16: TLS e roteamento em Nginx proxy reverso externo (compartilhado); VM com Nginx + PHP-FPM recebendo HTTP só do proxy. Apache descartado (mídia via `X-Accel-Redirect`).
- 2026-11-16: dispositivo principal de exibição = Amazon Fire TV Stick (Fire OS). Mini PC e smart TV continuam como alternativas.
- Pendente: confirmar no hardware real se Fully Kiosk Browser (licença paga para alguns recursos) é necessário ou se o Silk basta.
- 2026-11-16: VM com Debian 13: PHP 8.4, PostgreSQL 17, Nginx 1.26 e Composer 2.8 dos repositórios oficiais (PHP 8.3 não existe no Debian 13). Documentação passou a exigir PHP 8.3 ou superior.
- 2026-11-16: Apache removido da VM (não era usado); porta 80 livre para o Nginx. O projeto está em `/var/www/html/avisos-cond`; o README prevê `/var/www/avisos-cond` em produção.
- 2026-11-16: `001_init.sql` não tem `bloqueado`/`bloqueio_motivo`, `recorrencia` nem `codigo_pareamento`: entram em migrations aditivas das fases 2 e 3. Foi adicionado `avisos.midia_tipo` (imagem/video) para a TV saber como exibir a mídia; `atualizado_em` foi omitido (a auditoria cobrirá o histórico).
- Pendente: IP do proxy reverso (substitui `IP_DO_PROXY` na configuração).
- Pendente: definir os campos exatos do financeiro e se os dados serão digitados à mão ou importados do sistema de cobrança no futuro.
- 2026-11-16: PHPMailer adicionado (e-mail SMTP da recuperação de senha); justificativa no `AGENTS.md` seção 3.
- 2026-11-16: política de senha: 10 a 128 caracteres, Argon2id (19 MiB, 2 iterações, 1 thread). Sem `MAIL_HOST` o e-mail não é enviado e o motivo vai para o log, sem o token.
- 2026-11-16: `migrations/003_usuarios_senha_alterada.sql` adiciona `usuarios.senha_alterada_em`, usado para derrubar sessões antigas na troca de senha.
- 2026-11-16: identidade visual dos painéis baseada no `DESIGN.md` (verde #00A67E, grafite #202124, cantos de 8px). Desvios: botões e links usam o verde escuro #007A5C (o branco sobre #00A67E tem contraste de só 3,1:1) e o verde elétrico #00FF00 não é usado (viola o teto de saturação do próprio DESIGN.md).
- 2026-11-16: o administrador não pode alterar o próprio papel, desativar a própria conta nem se excluir (evita ficar sem acesso); a exclusão de usuário e de condomínio pede confirmação em página própria (sem JavaScript).
- Pendente: servidor SMTP e remetente (`MAIL_*`) para a recuperação de senha funcionar fora dos testes.
