# Avisos-Cond

Sistema de **mural digital de avisos para condomínios**. O gerente cadastra os avisos em um painel web e eles são exibidos automaticamente em uma TV instalada no elevador (ou na portaria) de cada condomínio. O dispositivo principal é o **Amazon Fire TV Stick (Fire OS)** ligado à TV; mini PC com Chromium e outros dispositivos também são suportados.

Desenvolvido e operado pela **Embracore** como serviço para gerentes que administram um ou vários condomínios.

---

## Visão geral

Existem três "superfícies" no sistema:

1. **Painel Embracore** (`/admin`): uso interno do fornecedor. Gestão de gerentes, condomínios, dispositivos e situação financeira (somente visualização e bloqueio manual).
2. **Painel do Gerente** (`/gerente`): o gerente vê apenas os condomínios aos quais está vinculado e gerencia os avisos de cada um.
3. **Tela de exibição (TV)** (`/tv/{token}`): página pública, sem login, identificada por um token único por dispositivo. Mostra os avisos ativos em tela cheia, em rotação.

```
 Gerente ──► Painel /gerente ─┐
                              ├──► PostgreSQL ◄── cron.php (agendador)
 Embracore ─► Painel /admin ──┘        ▲
                                       │ polling (version)
 TV + Fire Stick (Fire OS) ou mini PC ──► /tv/{token}
```

## Funcionalidades

### Painel do Gerente
- Lista dos condomínios vinculados (1 ou N) e seletor de condomínio ativo.
- CRUD de avisos com título, texto, imagem ou vídeo curto opcional (sem PDF) e tipo (comum, urgente, manutenção, assembleia).
- Cada aviso é criado individualmente para um condomínio (não há publicação em lote para vários).
- Agendamento: data/hora de início e fim de exibição, com opção de recorrência.
- Pré-visualização de como o aviso aparece na TV.
- Modelos prontos de aviso (falta de água, manutenção de elevador, dedetização, assembleia).
- Personalização visual de cada condomínio: logo e esquema de cores. Sem seleção, a TV usa a logo e as cores padrão da Embracore.
- Recuperação de senha por e-mail.

### Painel Embracore
- CRUD de usuários gerentes e de usuários administradores.
- CRUD de condomínios e vínculo gerente ↔ condomínios (N:N).
- Gestão de dispositivos (Fire Sticks, mini PCs e outras TVs): geração de token e último ping (MVP); código de pareamento e painel de status online/offline (Fase 2).
- Situação financeira por condomínio: **somente visualização** (situação, valor, vencimento, observações cadastrados manualmente) e **bloqueio/desbloqueio manual** da exibição (a TV passa a mostrar somente o relógio). A cobrança em si (boletos, Pix, faturas) é feita em um sistema à parte, fora deste projeto.
- Log de auditoria (quem fez o quê) e acesso de suporte como gerente (com registro).

### Tela de exibição
- Fullscreen, rotação automática, destaque em tela cheia para avisos urgentes.
- Relógio, logo e cores do condomínio; widget opcional de clima.
- Exibe somente fotos e vídeos (sem PDF).
- Acesso por URL com token curto (12 caracteres), fácil de digitar no dispositivo.
- QR code opcional no aviso para abrir mais detalhes no celular.
- Funciona offline (service worker com cache dos avisos e mídias). Offline, a TV decide o que exibir pelo horário (`inicio_em`/`fim_em`), corrigindo o relógio do aparelho com a hora do servidor recebida na última sincronização.
- Cada consulta ao servidor funciona também como **heartbeat** do dispositivo.

## Stack

- **PHP 8.3+** (8.4 na VM; puro, sem framework full-stack) com **PHP-FPM**
- **Nginx**
- **PostgreSQL 16+** via **PDO** (queries em SQL explícito, sem ORM)
- **FastRoute** (roteamento) e **Twig** (templates com auto-escape)
- **HTMX + Alpine.js** no front, sem build step e sem Node
- **Composer** apenas para autoload PSR-4 e as poucas bibliotecas acima
- **Migrations** em arquivos `.sql` numerados, aplicados por `bin/migrate.php`
- **Cron** do sistema chamando `bin/cron.php` a cada minuto

Filosofia: poucas dependências, código explícito e fácil de auditar.

## Estrutura do repositório

```
/public              front controller (index.php), assets, service worker
/src
  /Controllers       Admin, Gerente, Tv
  /Repositories      acesso ao banco (todo o SQL fica aqui)
  /Services          Auth, Scheduler, Upload, Audit
  /Middleware        Auth, Role, Csrf, RateLimit
  /Support           helpers (Config, Db, Session, Response)
/templates           Twig (admin, gerente, tv, layouts)
/migrations          001_init.sql, 002_...sql
/bin                 migrate.php, cron.php, create-admin.php
/storage             uploads (fora do webroot), logs
/tests               testes automatizados
.env.example
AGENTS.md            instruções para agentes de código
ROADMAP.md           progresso do projeto, do início à conclusão (mantido junto com o código)
README.md
```

## Perfis de usuário

- **superadmin**: equipe Embracore, acesso total ao `/admin`.
- **gerente**: acesso ao `/gerente`, restrito aos condomínios vinculados.
- **zelador** (opcional, fase futura): só cria/edita avisos de um condomínio.

## Modelo de dados (resumo)

- `usuarios` (id, nome, email, senha_hash, papel, ativo)
- `condominios` (id, nome, endereco, logo, cor_primaria, cor_secundaria, ativo; `bloqueado` e `bloqueio_motivo` na Fase 3); logo e cores nulas = padrão Embracore. `bloqueado` é definido manualmente pelo admin.
- `usuario_condominio` (usuario_id, condominio_id), relação N:N
- `avisos` (id, condominio_id, titulo, corpo, tipo, midia, midia_tipo, inicio_em, fim_em, ativo, criado_por; `recorrencia` na Fase 2); cada aviso pertence a um único condomínio
- `modelos_aviso` (modelos prontos de aviso)
- `dispositivos` (id, condominio_id, nome, token, ultimo_ping, ativo; `codigo_pareamento` na Fase 2)
- `condominio_financeiro` (condominio_id, situacao, valor_mensal em centavos, dia_vencimento, observacoes): informação financeira cadastrada manualmente, só para consulta
- `password_resets` (usuario_id, token_hash, expira_em, usado_em)
- `rate_limits` (contadores de tentativas de login, recuperação de senha e token de TV inválido)
- `auditoria` (usuario_id, acao, entidade, entidade_id, dados, criado_em)
- `schema_migrations` (versões de migrations aplicadas)

O esquema completo fica em `/migrations`.

## Requisitos

- Linux (Debian/Ubuntu recomendado)
- PHP 8.3 ou superior (a VM usa 8.4) com extensões: `pdo_pgsql`, `mbstring`, `fileinfo`, `curl`, `intl`
- PostgreSQL 16+
- Nginx + PHP-FPM
- Composer 2

## Instalação (desenvolvimento)

```bash
git clone <repo> avisos-cond && cd avisos-cond
composer install
cp .env.example .env          # ajuste as variáveis
createdb avisos_cond          # ou crie o banco/usuário manualmente
php bin/migrate.php           # aplica as migrations pendentes (idempotente)
php bin/create-admin.php      # cria o primeiro superadmin
php -S 127.0.0.1:8080 -t public public/index.php
```

## Configuração (`.env`)

```
APP_ENV=production
APP_URL=https://avisos.exemplo.com.br
APP_KEY=                      # string aleatória longa

DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=avisos_cond
DB_USER=avisos
DB_PASS=

SESSION_NAME=avisos_sess
UPLOAD_DIR=/var/www/avisos-cond/storage/uploads
UPLOAD_MAX_MB=10

MAIL_HOST=                    # SMTP para recuperação de senha
MAIL_PORT=587
MAIL_USER=
MAIL_PASS=
MAIL_FROM=

# Opcional: usuário com permissão de DDL só para as migrations (em produção,
# o usuário da aplicação não deve ter DDL).
MIGRATE_DB_USER=
MIGRATE_DB_PASS=

```

## Deploy (produção)

### Arquitetura de rede

O **TLS (HTTPS) e o roteamento ficam em um Nginx proxy reverso externo**, compartilhado com outros serviços e fora desta VM. A VM roda seu próprio Nginx + PHP-FPM, recebendo **HTTP puro somente do proxy** (libere a porta da VM apenas para o IP do proxy no firewall).

```
Cliente/TV ─HTTPS─► Nginx proxy (externo, TLS) ─HTTP─► Nginx da VM ─FastCGI─► PHP-FPM
```

**Nginx da VM** (trecho essencial):

```nginx
server {
    listen 80;
    server_name avisos.exemplo.com.br;
    root /var/www/avisos-cond/public;
    index index.php;

    # IP real do cliente vindo do proxy (obrigatório: o rate limit usa o IP).
    # Troque pelo IP do proxy reverso.
    set_real_ip_from IP_DO_PROXY;
    real_ip_header X-Forwarded-For;
    real_ip_recursive on;

    # Maior que UPLOAD_MAX_MB; alinhar com o proxy externo.
    client_max_body_size 12m;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;  # ajuste para a versão instalada
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        # Todo acesso real é HTTPS (TLS termina no proxy); sem isso os cookies
        # Secure e as URLs geradas pela aplicação ficam incorretos.
        fastcgi_param HTTPS on;
    }

    # Mídias: só acessíveis via X-Accel-Redirect, depois que a aplicação
    # valida o token (/tv/{token}/media/{id}) ou a sessão do gerente.
    location /media/ {
        internal;
        alias /var/www/avisos-cond/storage/uploads/;
    }

    # O access_log das rotas /tv/ deve mascarar o token (ver AGENTS.md 6.8).
}
```

**Nginx proxy externo** (o que esta aplicação exige dele, no `server`/`location` do domínio):

```nginx
proxy_pass http://IP_DA_VM;
proxy_set_header Host $host;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
client_max_body_size 12m;      # igual ao da VM
proxy_read_timeout 120s;       # uploads de vídeo
# HSTS e demais headers de segurança podem ficar aqui.
# O access_log das rotas /tv/ também deve mascarar o token.
```

**Cron** (agendador):

```
* * * * * www-data php /var/www/avisos-cond/bin/cron.php >> /var/www/avisos-cond/storage/logs/cron.log 2>&1
```

O `cron.php` publica/expira avisos agendados e, na Fase 2, marca dispositivos offline. Não pode haver duas execuções simultâneas (usa lock) e todas as tarefas são idempotentes.

**Backup**: `pg_dump` diário do banco e cópia de `/storage/uploads`.

## Instalando a tela no condomínio

1. No painel Embracore, cadastre o dispositivo e copie a URL `https://avisos.exemplo.com.br/tv/{token}`.
2. **Fire Stick, Amazon Fire TV (Fire OS), opção principal**:
   1. Conecte o Fire Stick à TV e à rede, e entre com a conta Amazon da Embracore.
   2. Instale um navegador pela Appstore da Amazon. Duas opções:
      - **Fully Kiosk Browser** (recomendado para produção): permite definir a URL inicial, manter a tela sempre ligada e abrir sozinho ao ligar o aparelho. Alguns recursos de quiosque exigem licença paga.
      - **Amazon Silk** (navegador padrão, gratuito): abra a URL e deixe em tela cheia. Não tem modo quiosque de verdade, então não abre sozinho após reiniciar.
   3. Digite a URL `https://avisos.exemplo.com.br/tv/{token}` com o controle remoto. O token tem só 12 caracteres justamente para facilitar essa digitação. Salve como página inicial (Fully Kiosk: "Start URL").
   4. Em **Configurações → Preferências → Proteção de tela**, desative a proteção de tela e o modo ambiente; em **Tela e sons**, desative o desligamento automático da tela. Desative também as atualizações automáticas de apps no horário de exibição.
   5. Se possível, ligue o Fire Stick numa tomada fixa (não na USB da TV, que desliga junto) e use um cabo HDMI estável.
3. **Mini PC (Linux)**: Chromium em modo kiosk iniciado no boot:
   ```bash
   chromium --kiosk --noerrdialogs --disable-infobars \
            --autoplay-policy=no-user-gesture-required \
            "https://avisos.exemplo.com.br/tv/{token}"
   ```
4. **Smart TV**: abra a URL no navegador da TV. O navegador nativo costuma ser menos confiável que um Fire Stick ou mini PC; use só se não houver alternativa.
5. Em qualquer dispositivo, desative suspensão de tela e economia de energia.

> A configuração do Fire Stick acima ainda precisa ser validada em hardware real (ver `ROADMAP.md`, item 1.8).

## Roadmap

Resumo abaixo. O detalhamento item a item e o status real de cada um ficam em [`ROADMAP.md`](ROADMAP.md), atualizado a cada commit.

- **MVP**: login com rate limit, recuperação de senha, CRUD de condomínios, gerentes e avisos, personalização visual (logo/cores), dispositivos (cadastro, token e heartbeat), tela da TV com rotação e cache offline.
- **Fase 2**: painel de status online/offline dos dispositivos, agendamento avançado, auditoria, pareamento por código.
- **Fase 3**: situação financeira (visualização e bloqueio manual; TV bloqueada mostra só o relógio), widget de clima, notificações externas (WhatsApp/e-mail), perfil zelador.

## Segurança

Resumo das regras (detalhes em `AGENTS.md`): isolamento rigoroso entre condomínios (tenants), Argon2id para senhas, CSRF em todo POST, escape de saída por padrão, validação de uploads por MIME real (somente imagens e vídeo), rate limit em login, recuperação de senha e token de TV.

## Licença

Software proprietário da Embracore. Todos os direitos reservados.
