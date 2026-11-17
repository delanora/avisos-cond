# Roadmap — Avisos-Cond

Caminho do início à conclusão do projeto. **Este arquivo é a fonte de verdade do progresso**: deve refletir fielmente o estado do código (veja a regra em `AGENTS.md`, seções 2.7 e 11).

## Como manter este arquivo

- Marque `[x]` **somente** quando o item estiver implementado, **com testes** (`vendor/bin/phpunit` passando) e commitado. Toda feature deve vir acompanhada de testes; item sem teste fica `[~]`.
- Use `[~]` para item em andamento e `[ ]` para item não iniciado.
- Toda tarefa que conclui, inicia, adia, cria ou remove um item **atualiza este arquivo no mesmo commit** da mudança de código.
- Ao terminar um item, anote a data (AAAA-MM-DD) ao final da linha. Ex.: `- [x] Migration 001_init.sql (2026-12-01)`.
- Decisões que mudam o escopo vão para "Decisões e pendências" ao final, com data.
- Não invente itens: o escopo vem do `README.md` e do `AGENTS.md`. Se o escopo mudar, atualize os três arquivos juntos.

**Status atual:** Fase 1 em andamento. Blocos 1.1 a 1.7 concluídos e a 1.8 em andamento (infra no ar em https://avisos.embracore.com.br; faltam os testes em hardware real e a cópia externa dos backups) (estrutura base, banco, autenticação, painéis `/admin` e `/gerente`, tela da TV, agendador).

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
- [x] ~~Logo/cores padrão da Embracore por condomínio~~ substituído em 2026-11-17: a cor é do gerente (`AparenciaService`)
- [ ] Trocar `public/assets/logo-embracore.svg` (provisória) pela logo oficial da Embracore (hoje o arquivo não é usado pela TV; a marca do painel é o SVG de `templates/partials/logo.html.twig`)
- [x] Hospedar a fonte Inter localmente em `public/assets/fonts` (licença OFL incluída) (2026-11-16)
- [x] Redesenho visual dos painéis e das telas de acesso (barra branca, cartões, ícones, avatares, saudação, login em duas colunas), conferido por capturas de tela no Chromium (2026-11-16)
- [ ] Você revisar o novo visual em um navegador real e pedir ajustes (cores, textos, ilustração)

### 1.5 Painel `/gerente`
- [x] Lista de condomínios vinculados (lidos do banco a cada requisição) e seletor para trocar de condomínio (2026-11-16)
- [x] CRUD de avisos (título, texto, tipo, imagem/vídeo, início/fim), com situação (no ar, agendado, encerrado, desativado) (2026-11-16)
- [x] Upload seguro (JPEG/PNG/WebP/MP4, MIME real, nome aleatório, fora do webroot, entrega por X-Accel-Redirect) (2026-11-16)
- [x] ~~Personalização visual do condomínio (logo e cores)~~ substituída em 2026-11-17 pela cor de destaque do gerente (`/gerente/aparencia`)
- [x] Pré-visualização do aviso na TV (com tema do condomínio; urgente em destaque) (2026-11-16)
- [x] Testes de isolamento de tenant para avisos e personalização (gerente A × condomínio B → 404), validados por mutação (2026-11-16)
- [ ] Testar o upload e a prévia de **vídeo MP4** real em um Fire Stick e no navegador (os testes automáticos usam um MP4 mínimo)

### 1.6 Dispositivos e tela da TV
- [x] Cadastro de dispositivo (`/admin/dispositivos`) e geração de token (12 caracteres, 31 símbolos sem ambíguos), revogável, com troca de token e exclusão (2026-11-17)
- [x] `/tv/{token}`: página com rotação e relógio (sem logo nem cores personalizadas desde 2026-11-17); modos sem avisos e só relógio (2026-11-17)
- [x] `/tv/{token}/state` (JSON com `version`, avisos, `inicio_em`/`fim_em`, `server_time`) e heartbeat (`ultimo_ping`) (2026-11-17)
- [x] `/tv/{token}/media/{arquivo}` com validação de token, só mídias de avisos visíveis, e `X-Accel-Redirect` (2026-11-17)
- [x] Avisos `urgente` e novo tipo `fixo` em uma faixa fixa no topo da TV (40% da altura, dividida entre todos eles), com o carrossel dos demais avisos abaixo; substitui a regra "urgente esconde os outros" (migration 005) (2026-11-17)
- [x] Rate limit de tokens inválidos em `/tv/*`; token inválido → 404 (2026-11-17)
- [x] Service worker (cache de HTML, assets, estado e mídias, com Range) e ajuste de relógio offline, verificados no Chromium real com o servidor derrubado (2026-11-17)
- [x] Mascaramento do token no log do Nginx e `Referrer-Policy: no-referrer` (2026-11-17)
- [x] Testes: endpoint da TV (só avisos ativos e no período; token inválido → 404), isolamento entre condomínios, rate limit, painel de dispositivos, lógica do JS no Chromium e guardas de compatibilidade (ES5/CSS); validados por mutação (2026-11-17)
- [ ] Testar a TV num **Fire Stick real** (Fully Kiosk e Silk): rotação, relógio, vídeo MP4, memória após horas, reinício do aparelho e se o navegador libera Service Worker em HTTPS
- [ ] Colocar HTTPS no domínio (proxy) e confirmar o modo offline da TV em produção

### 1.7 Agendador
- [x] `bin/cron.php` com lock no PostgreSQL (sem execução simultânea; solto sozinho se o processo morrer), intervalos por tarefa, histórico em `tarefas_agendadas`, falha isolada com espera antes de repetir, `--forcar` e `--status`; instalado em `/etc/cron.d/avisos-cond` e com logrotate (2026-11-17)
- [x] Publicação e expiração de avisos agendados: **não precisa de tarefa**, o que está no ar é calculado em tempo real por `inicio_em`/`fim_em` (TV, listas e prévia). No lugar, o agendador faz as limpezas que o sistema precisa: contadores de rate limit, tokens de recuperação de senha e arquivos órfãos de upload (com disjuntor) (2026-11-17)
- [x] Testes: lock entre conexões, vencimento e espera após falha, falha isolada, tarefas de limpeza (inclusive disjuntor) e o `bin/cron.php` de verdade como subprocesso (2026-11-17)

### 1.8 Entrega do MVP
- [x] Configuração de Nginx/PHP-FPM e cron documentada e testada em servidor (2026-11-17)
- [x] Proxy reverso externo configurado (TLS, `X-Forwarded-*`, upload) e Nginx da VM com IP real (`set_real_ip_from`) e `HTTPS on` (2026-11-17; em https://avisos.embracore.com.br; ver pendência dos cabeçalhos duplicados do proxy)
- [x] Firewall da VM liberando a porta HTTP apenas para o IP do proxy (nftables, 2026-11-17; falta você confirmar de fora que `http://172.16.31.116` não responde mais)
- [x] Teste: rate limit enxerga o IP real do cliente (não o do proxy) (2026-11-17: chave `login:ip:<IP real>`, `X-Forwarded-For` forjado ignorado)
- [x] Rotina de backup (pg_dump + `/storage/uploads`): `bin/backup.sh` + cron diário 03:30, retenção de 14 dias, restauração testada, `BackupCliTest` (2026-11-17)
- [ ] Copiar os backups para fora da VM (outro servidor/NAS/nuvem) — depende de você escolher o destino
- [ ] Teste em hardware real: **Fire Stick (Fire OS) com Fully Kiosk Browser e com Silk** (dispositivo principal), incluindo service worker offline, vídeo MP4 H.264, abertura após reinício e proteção de tela desativada
- [ ] Teste em hardware real: mini PC com Chromium kiosk (opção secundária)
- [ ] Validar o passo a passo de instalação do Fire Stick no README e ajustá-lo conforme o que for observado
- [~] README atualizado com o que foi entregue (deploy, proxy, firewall e backup documentados em 2026-11-17; falta o ajuste do passo a passo do Fire Stick depois do teste em hardware)

## Fase 2 — Operação e gestão

- [x] Painel de status online/offline dos dispositivos: situação (online, offline, nunca conectou, revogado) na lista com contagem e filtro, no detalhe com histórico de quedas e na página inicial do admin; offline = sem contato há mais de 3 min (2026-11-17)
- [x] Detecção de dispositivos offline no cron (`detectar_dispositivos_offline`, a cada 1 min, grava quedas e retornos em `dispositivo_eventos`, idempotente) (2026-11-17)
- [ ] Pareamento por código (`codigo_pareamento`)
- [x] Agendamento avançado (recorrência): dias da semana e janela de horário dentro do período do aviso, valendo na TV (offline), no painel e na prévia; regra única em `Recorrencia` (PHP) e `recorrenciaAtiva` (JS), verificada num Chromium real (2026-11-18)
- [x] Tipos de aviso criados pelo gerente (por condomínio): dropdown na tela do aviso, criação na própria tela ou em "Tipos de aviso", nome + cor principal (cartão em tom claro calculado) + fixo ou não; os 5 antigos viram tipos do sistema; gatilho de isolamento no banco (2026-11-18)
- [x] Modelos prontos de aviso (falta de água, elevador, dedetização, assembleia), que o gerente também cadastra, edita e apaga; "Começar de um modelo" e "Salvar como modelo" no formulário do aviso (2026-11-18)
- [ ] Auditoria (tabela `auditoria`, registro de ações) e modo suporte do superadmin como gerente

### 2.x Autenticação de dois fatores (2FA, TOTP)

Segundo fator por **código de 6 dígitos de aplicativo autenticador** (Google Authenticator, Microsoft Authenticator, Authy etc.), sem SMS. Vale para quem entra nos painéis (`superadmin` e `gerente`); a TV continua só com o token na URL, sem 2FA.

Decisões já tomadas (2026-11-17):
- Os **dois perfis** (`superadmin` e `gerente`) usam o 2FA.
- **Lembrar dispositivo no login: sim.** Prazo e regras ainda a definir [confirmar].
- (Plano abaixo **confirmado pelo responsável em 2026-11-17**.) **TOTP: código próprio** (RFC 6238 com `hash_hmac`, ~50 linhas, sem dependência, testado com os vetores do RFC). **QR code: biblioteca de terceiros pequena** (QR é complexo demais para escrever à mão), com saída em SVG para não depender da extensão GD e para o CSP aceitar; candidata: `bacon/bacon-qr-code`; só o 2FA usa QR code (o QR code em avisos foi removido do projeto). Conferir versão, licença e manutenção e justificar o `composer require` no commit (AGENTS.md 2.2). Alternativa sem dependência (mostrar só a chave para digitar no app) fica como plano B.
- **Lembrar dispositivo: 30 dias**, cancelado ao trocar a senha, desativar ou redefinir o 2FA, ou pelo botão "esquecer meus dispositivos".
- Quem ativa o 2FA e **não confirma o código** na ativação continua sem 2FA; quem tem 2FA ativo e **não confirma o código no login, não entra**.
- O 2FA é **opcional**, mas o **lembrete é obrigatório**: quem não ativou vê o pop-up em **todo login** (não há "não mostrar mais"); quem não ativa continua entrando só com a senha.
- O superadmin **redefine e desativa** o 2FA de outros usuários; e há um comando no terminal do servidor para desativar em caso de problema.

Nada pendente de decisão do produto; só conferir a versão e a licença da biblioteca de QR code na hora de implementar.

Banco e segurança do segredo:
- [ ] Migration: em `usuarios`, `totp_segredo` (cifrado), `totp_ativado_em` e `totp_ultimo_passo` (impede reaproveitar o mesmo código); tabela `codigos_recuperacao` (usuário, hash, usado_em)
- [ ] Cifrar o segredo TOTP em repouso com uma chave do `.env` (`APP_KEY`, hoje vazia e ainda sem uso); nunca em log; chave ausente impede ativar o 2FA em vez de gravar em texto puro
- [ ] `TotpService`: gerar segredo (160 bits, base32), validar código com tolerância de ±1 passo (30 s), comparação em tempo constante e rejeição de passo já usado

Interface (decidida em 2026-11-17):
- [ ] Na barra superior, o ícone com a inicial do usuário (ao lado de "Sair") é **substituído por uma engrenagem**; ao clicar abre um menu (dropdown) com **Segurança** e **Aparência** (sem JavaScript inline: o CSP proíbe; usar `<details>`, como o seletor de condomínio)
- [ ] **Aparência sai do menu principal** (hoje é um item do menu do gerente) e passa para o dropdown da engrenagem
- [ ] Quem **não ativou** o 2FA vê, **a cada login**, um aviso em pop-up lembrando de ativar, com botão que leva à página de ativação (e opção de fechar/continuar); aparece uma vez por login, não a cada página

Ativação e gestão pelo próprio usuário:
- [ ] Página "Segurança" no painel (gerente e admin): ativar, ver situação, desativar, gerar novos códigos de recuperação e "esquecer meus dispositivos" 
- [ ] Ativar: mostrar QR code e chave em texto, e só ativar depois de o usuário digitar um código válido; depois mostrar uma única vez os códigos de recuperação
- [ ] Códigos de recuperação: 10 códigos aleatórios de uso único, guardados só como hash; regenerar invalida os anteriores
- [ ] Desativar ou regenerar exige senha atual e um código válido (ou de recuperação)

Login em duas etapas:
- [ ] "Lembrar este dispositivo": caixa na tela do código; guarda um token aleatório (256 bits) em cookie `HttpOnly`/`Secure`/`SameSite=Lax` e só o **hash** no banco (tabela `dispositivos_confiaveis`: usuário, hash, criado em, expira em, último uso); com o cookie válido o código não é pedido (a senha continua sendo); expira no prazo definido e é apagado ao trocar a senha, desativar ou redefinir o 2FA, ou em "esquecer meus dispositivos"
- [ ] Senha correta com 2FA ativo não abre a sessão: fica "pendente de 2FA" (sem acesso a `/admin`, `/gerente` nem a dados) e vai para a tela do código; ao validar, `session_regenerate_id(true)` e sessão completa
- [ ] Tela do código com opção "usar código de recuperação"; mensagem de erro genérica; expiração curta da etapa pendente (ex.: 5 min)
- [ ] Rate limit na verificação do código (por usuário e por IP, `429` com `Retry-After`) e bloqueio temporário após falhas seguidas
- [ ] O `AuthMiddleware` trata a sessão pendente como não autenticada; qualquer rota protegida redireciona para a tela do código
- [ ] Recuperação de senha **não** contorna o 2FA: redefinir a senha não desativa o segundo fator (a troca de senha já derruba as sessões antigas)

Administração e operação:
- [ ] Superadmin pode **redefinir** (apagar o segredo e exigir nova configuração) e **desativar** o 2FA de **qualquer outro usuário** (gerente ou outro admin) que perdeu o aparelho e os códigos; exige a senha do próprio admin e confirmação em página própria; apaga também os dispositivos lembrados; o admin não pode ver segredo nem códigos de ninguém. Risco registrado: uma conta de admin invadida poderia desativar o 2FA dos demais, por isso a exigência de senha e a auditoria
- [ ] Lista de usuários do `/admin` mostra quem tem 2FA ativo
- [ ] Ações de ativar, desativar, redefinir e códigos de recuperação usados entram na auditoria (depende do item de auditoria acima)
- [ ] Comando no terminal do servidor para **desativar o 2FA de qualquer usuário** (ex.: `php bin/reset-2fa.php email@exemplo.com`), usado quando o painel não resolve (inclusive o superadmin que perdeu o aparelho); registra em log (sem segredo), apaga os dispositivos lembrados e pede confirmação

Testes (obrigatórios, AGENTS.md 9):
- [ ] `TotpService`: vetores do RFC 6238, janela ±1, código repetido recusado, código malformado
- [ ] Fluxo completo: ativar, sair, entrar com senha + código, entrar com código de recuperação (uso único), desativar
- [ ] Ativação abandonada (código não confirmado) não liga o 2FA; com 2FA ligado, sem código válido não há login
- [ ] "Lembrar dispositivo": cookie válido pula o código; expirado, de outro usuário, adulterado ou depois de trocar a senha/desativar/redefinir não pula; só o hash fica no banco
- [ ] Pop-up de lembrete: aparece uma vez por login para quem não ativou e não aparece para quem ativou
- [ ] Menu da engrenagem (Segurança e Aparência) e Aparência fora do menu principal
- [ ] Admin redefine/desativa o 2FA de outro usuário (gerente é negado, exige a senha do admin) e `bin/reset-2fa.php` desativa o de um usuário sem tocar nos demais
- [ ] Sessão pendente de 2FA não acessa nenhuma rota de `/admin` ou `/gerente` (percorrer todas), e CSRF vale na tela do código
- [ ] Rate limit da verificação e expiração da etapa pendente
- [ ] Isolamento: o 2FA e os códigos de um usuário nunca afetam outro; gerente não redefine 2FA de ninguém
- [ ] Segredo gravado cifrado (nunca igual ao valor em texto puro) e ausente de logs e de respostas
- [ ] Teste manual com pelo menos dois aplicativos autenticadores reais

Documentação:
- [ ] README (como ativar, o que fazer se perder o aparelho) e AGENTS.md seção 6.2 (regras do 2FA), no mesmo commit da implementação

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
- 2026-11-16: Nginx da VM no ar (`/etc/nginx/sites-available/avisos-cond`, porta 80, PHP-FPM 8.4), servindo `public/` e bloqueando `.env`, `.git`, `src`, `vendor`, `storage` etc. O PHP-FPM roda como `www-data`: `.env` fica `root:www-data` modo 640 e `storage/` pertence a `www-data`. (Resolvido em 2026-11-17: IP real, HTTPS e firewall.)
- 2026-11-17: proxy reverso em 172.16.31.29, HTTPS em https://avisos.embracore.com.br (`APP_URL` atualizado). Na VM: `set_real_ip_from`, mapa `$avisos_https` (só o proxy diz que é https), firewall nftables (porta 80 só do proxy, SSH intocado) e Service Worker da TV verificado offline por HTTPS num Chromium real. Pendente no proxy: ele adiciona cabeçalhos próprios (`Referrer-Policy: same-origin`, `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options`, `X-XSS-Protection`) que duplicam e conflitam com os da aplicação (que pede `no-referrer` e `DENY`); remova esses do vhost do proxy e mantenha só o HSTS.
- Pendente: definir os campos exatos do financeiro e se os dados serão digitados à mão ou importados do sistema de cobrança no futuro.
- 2026-11-16: PHPMailer adicionado (e-mail SMTP da recuperação de senha); justificativa no `AGENTS.md` seção 3.
- 2026-11-16: política de senha: 10 a 128 caracteres, Argon2id (19 MiB, 2 iterações, 1 thread). Sem `MAIL_HOST` o e-mail não é enviado e o motivo vai para o log, sem o token.
- 2026-11-16: `migrations/003_usuarios_senha_alterada.sql` adiciona `usuarios.senha_alterada_em`, usado para derrubar sessões antigas na troca de senha.
- 2026-11-16: identidade visual dos painéis baseada no `DESIGN.md` (verde #00A67E, grafite #202124, cantos de 8px). Desvios: botões e links usam o verde escuro #007A5C (o branco sobre #00A67E tem contraste de só 3,1:1) e o verde elétrico #00FF00 não é usado (viola o teto de saturação do próprio DESIGN.md).
- 2026-11-16: o administrador não pode alterar o próprio papel, desativar a própria conta nem se excluir (evita ficar sem acesso); a exclusão de usuário e de condomínio pede confirmação em página própria (sem JavaScript).
- 2026-11-16: redesenho visual a pedido: barra superior branca, visual mais humano (Inter local, cartões arredondados, avatares, ícones, saudação por horário, login em duas colunas, telas de erro amigáveis). O Chromium foi instalado na VM só para conferir o visual por capturas de tela (pode ser removido com `apt remove chromium`).
- 2026-11-16: bloco 1.5 decidiu: rotas do gerente escopadas pela URL (`/gerente/condominios/{id}/...`); gerente só enxerga condomínios **ativos** (admin desativa = some para o gerente); limite de upload único (`UPLOAD_MAX_MB`, hoje 10) para imagem e vídeo; avisos novos começam no próximo quarto de hora e duram 7 dias por padrão.
- 2026-11-16: VM: `99-avisos.ini` no PHP-FPM (upload 12M/post 14M) e `location /media/` interno no Nginx com `MEDIA_ACCEL=/media/` no `.env`.
- 2026-11-17: bloco 1.6 decidiu: token de 31 símbolos (o AGENTS dizia 32, mas 26 letras - I, L, O + 8 dígitos = 31), guardado em maiúsculas e aceito em qualquer caixa (migration 004); mídia da TV pelo **nome do arquivo** (e não pelo id do aviso); o `/state` inclui avisos que começam em até 24 h (para exibir na hora certa offline); com urgente no ar, só os urgentes são exibidos; condomínio desativado = só o relógio; o Service Worker fica em `/tv/sw.js` (escopo `/tv/`, não cobre o painel); a TV se recarrega a cada 6 h e quando o JS/CSS muda; `APP_URL` do `.env` passou a ser `http://172.16.31.116` (é o endereço mostrado para digitar na TV).
- 2026-11-17: VM: `/etc/nginx/conf.d/avisos-log.conf` (log sem o token da TV) e `access_log` no site. Em HTTP puro o Service Worker não existe, então o modo offline só vale em HTTPS.
- 2026-11-17: a pedido, avisos urgentes e fixos passaram a ocupar uma faixa reservada de 40% da altura no topo da TV, mostrados todos juntos (a letra encolhe com a quantidade), com o carrossel abaixo. Decisões minhas a confirmar: tamanho da faixa 40%; faixa só com texto (foto/vídeo só no carrossel); "fixo" é um tipo novo (e não uma opção de qualquer tipo); com 7 ou mais avisos a faixa fica em 2 linhas de até 5 colunas.
- 2026-11-17: redesenho visual da tela da TV a pedido: cartões sem moldura lateral e com sombra suave, faixa de fixos sem fundo nem linha divisória (mesmo fundo do carrossel), barra do topo ~20% menor com a hora sempre branca, e o conjunto no estilo dos painéis (fundo quente, cartões brancos, etiquetas em pílula com ícone). Observação: hora branca sobre cor de condomínio muito clara (ex.: amarelo) tem pouco contraste; se aparecer um caso assim, escurecer a barra automaticamente.
- 2026-11-17: ajuste de aproveitamento dos cartões da TV a pedido: etiqueta do tipo à direita, na mesma linha do título (faixa e carrossel); texto curto no carrossel ganha letra maior (`tv-corpo-curto` até 110 caracteres, `tv-corpo-medio` até 220); cartões da faixa crescem para a última linha incompleta não deixar buraco; com uma só linha de cartões o texto aceita até 6 linhas. Residencial Exemplo do ambiente de desenvolvimento recebeu avisos de demonstração (1 urgente, 4 fixos, 9 rotativos).
- 2026-11-17: barra do topo da TV removida a pedido (só cartões; logo do condomínio passou a aparecer no modo "nenhum aviso"; a hora só aparece nesse modo). Modos: só fixos/urgentes = faixa em tela cheia; só carrossel = tela cheia; os dois = faixa de 40% + carrossel (mesmo layout de antes). Interpretei "o modo segue o mesmo" assim [confirmar]. Sem barra, a hora some enquanto há avisos no ar [confirmar se querem a hora em algum canto].
- 2026-11-17: a aparência saiu do condomínio e foi para o gerente, a pedido. Migration 007: `usuarios.cor_primaria` (única cor; tons claros e escuros calculados) e remoção de `condominios.logo/cor_primaria/cor_secundaria` (as logos já enviadas ficam órfãs e a tarefa `limpar_arquivos_orfaos` as apaga). A cor vale só no painel do próprio gerente (rota `/gerente/aparencia`, folha `/gerente/tema.css`); a TV não tem mais logo nem cores personalizadas; o painel do superadmin segue no verde. Decisão minha: os verdes semânticos de "sucesso" (etiqueta Ativo, alerta de salvo) e os avatares coloridos não mudam com a cor. Títulos dos cartões da TV passaram a ocupar sempre 1 linha (reticências se não couber).
- 2026-11-17: bloco 1.7 decidiu: sem tarefa "publicar/expirar" (tudo é calculado na leitura; mudar `ativo` em massa apagaria a intenção do gerente e atrapalharia estender um aviso); o lock é `pg_try_advisory_lock` (vale entre servidores e some se o processo morrer); `limpar_arquivos_orfaos` tem disjuntor; `LOG_DIR` opcional no `.env` (padrão `storage/logs`). Mais tarefas entram na Fase 2 (dispositivos offline) pela interface `Tarefa`.
- Pendente: servidor SMTP e remetente (`MAIL_*`) para a recuperação de senha funcionar fora dos testes.
- 2026-11-17: 2FA por TOTP adicionado à Fase 2 (seção 2.x) a pedido; sem SMS. Quem é obrigado a usar, o lembrar dispositivo e a biblioteca de QR code ficam como [confirmar] antes de implementar.
- 2026-11-17: decisões do 2FA registradas (ver seção 2.x): os dois perfis usam, lembrar dispositivo no login, engrenagem com Segurança/Aparência, pop-up de lembrete, admin redefine/desativa e comando no terminal. Decidido depois: 2FA opcional com lembrete obrigatório a cada login. Fechado em seguida: lembrar dispositivo por 30 dias (cancelado ao trocar a senha, desativar ou redefinir), TOTP com código próprio e QR code com biblioteca de terceiros (SVG, usada só na ativação do 2FA).
- 2026-11-17: o responsável confirmou o plano do 2FA (TOTP próprio, QR code de terceiros em SVG, plano B só com a chave em texto). Pode ser implementado quando for priorizado.
- 2026-11-17: removido do roadmap o "QR code opcional no aviso" (decisão do responsável). Ordem da Fase 2 combinada: status online/offline + detecção offline, modelos de aviso, recorrência, auditoria + modo suporte, pareamento por código; o 2FA fica por último.
- 2026-11-17: bloco 2.1 (status online/offline). Decisões minhas a confirmar: limite de 3 minutos sem contato para considerar a TV offline (a TV consulta a cada 30-60 s); o painel mostra a situação calculada na hora, e a tarefa do cron só alimenta o histórico (migration 008); só o admin vê a situação (o gerente não). Notificação ao cair (e-mail/WhatsApp) continua na Fase 3.
- 2026-11-18: bloco 2.2a (tipos de aviso). Decisões minhas a confirmar: tipos pertencem ao **condomínio** (todos os gerentes dele compartilham; o isolamento segue a regra dos avisos); os cinco antigos viraram tipos do sistema, não editáveis; com isso os cartões Fixo/Manutenção/Assembleia da TV ganharam cor (antes eram todos grafite) e o Urgente deixou de ser um caso especial no CSS; apagar tipo em uso é bloqueado; o tipo é escolhido por dropdown e há uma seção "Criar um tipo novo" no mesmo formulário (sem JavaScript).
- 2026-11-18: bloco 2.2b (modelos de aviso). Decisões minhas a confirmar: modelos pertencem ao condomínio (como os tipos); os quatro do sistema não são editáveis; os textos deles têm trechos entre colchetes ([data], [hora]) para o gerente preencher; "salvar como modelo" usa o título do aviso como nome (com sufixo "(2)" se repetir).
- 2026-11-18: bloco 2.2c (recorrência). Decisões minhas a confirmar: a repetição é "dias da semana + janela de horário" dentro do período (não cobre "todo dia 1º do mês" nem intervalos como "a cada 2 semanas"); a janela não atravessa a meia-noite (para 22h–02h, crie dois avisos); marcar os sete dias equivale a não restringir; horário sempre de Brasília.
