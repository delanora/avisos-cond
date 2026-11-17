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
- Lista dos condomínios vinculados (1 ou N, só os ativos; lida do banco a cada requisição) e seletor para trocar de condomínio. Com um só condomínio, o gerente vai direto para os avisos.
- CRUD de avisos com título, texto, imagem ou vídeo curto opcional (sem PDF) e tipo. Os tipos Comum, Fixo, Urgente, Manutenção e Assembleia já vêm prontos e o gerente cria os dele (nome, cor principal e se é fixo; o tom claro do cartão é calculado), direto na tela do aviso (dropdown) ou em "Tipos de aviso". Cada aviso pode **se repetir** em dias da semana e numa janela de horário dentro do período (ex.: terças e sextas, 7h às 9h). Há também **modelos de aviso** (falta de água, elevador, dedetização e assembleia já vêm prontos; o gerente cria, edita e apaga os dele, ou salva um aviso como modelo). Os tipos fixos ficam sempre à vista em cartões só de texto (ocupam a tela toda quando são os únicos; com avisos de carrossel, ficam numa faixa no topo); os demais passam em carrossel. A TV não tem barra: só cartões. A lista mostra a situação de cada aviso: no ar, agendado, encerrado ou desativado.
- Cada aviso é criado individualmente para um condomínio (não há publicação em lote para vários).
- Agendamento: data/hora de início e fim de exibição, com opção de recorrência.
- Pré-visualização de como o aviso aparece na TV, (aviso urgente em destaque).
- Modelos prontos de aviso (falta de água, manutenção de elevador, dedetização, assembleia).
- Aparência do painel do gerente: ele escolhe uma cor de destaque (o tom claro é calculado sozinho) que troca tudo o que é verde no painel dele. A TV e os condomínios não têm cores nem logo próprios.
- Recuperação de senha por e-mail.

### Painel Embracore
- CRUD de usuários gerentes e de usuários administradores (ativar/desativar, redefinir senha, excluir com confirmação).
- CRUD de condomínios e vínculo gerente ↔ condomínios (N:N).
- Gestão de dispositivos (Fire Sticks, mini PCs e outras TVs): geração de token e último ping (MVP); painel de status online/offline com histórico de quedas (Fase 2, pronto) e código de pareamento (Fase 2).
- Situação financeira por condomínio: **somente visualização** (situação, valor, vencimento, observações cadastrados manualmente) e **bloqueio/desbloqueio manual** da exibição (a TV passa a mostrar somente o relógio). A cobrança em si (boletos, Pix, faturas) é feita em um sistema à parte, fora deste projeto.
- Log de auditoria (quem fez o quê) e acesso de suporte como gerente (com registro).

### Tela de exibição
- Fullscreen, rotação automática, destaque em tela cheia para avisos urgentes.
- Relógio; widget opcional de clima.
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
- **PHPMailer** (e-mail SMTP da recuperação de senha)
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
DESIGN.md            identidade visual (cores, tipografia, componentes) usada nos painéis
ROADMAP.md           progresso do projeto, do início à conclusão (mantido junto com o código)
README.md
```

## Perfis de usuário

- **superadmin**: equipe Embracore, acesso total ao `/admin`.
- **gerente**: acesso ao `/gerente`, restrito aos condomínios vinculados.
- **zelador** (opcional, fase futura): só cria/edita avisos de um condomínio.

## Modelo de dados (resumo)

- `usuarios` (id, nome, email, senha_hash, papel, ativo)
- `condominios` (id, nome, endereco, ativo; `bloqueado` e `bloqueio_motivo` na Fase 3). `bloqueado` é definido manualmente pelo admin.
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
MEDIA_ACCEL=/media/           # entrega de mídia pelo Nginx (X-Accel-Redirect); vazio = o PHP envia o arquivo
TV_POLL_SECONDS=45            # de quantos em quantos segundos a TV consulta o servidor (30 a 60)

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
    # Troque pelo IP do proxy reverso (nesta instalação: 172.16.31.29).
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
        # O TLS termina no proxy: sem HTTPS "ligado" os cookies Secure e as URLs ficam incorretos.
        # $avisos_https (abaixo) só vale "on" quando a conexão vem do proxy e ele informa https.
        fastcgi_param HTTPS $avisos_https;
    }

    # Mídias: só acessíveis via X-Accel-Redirect, depois que a aplicação valida o acesso
    # (sessão do gerente em /gerente/..., token em /tv/{token}/media/{id}). Requer MEDIA_ACCEL=/media/ no .env.
    # O Nginx cuida de Range (necessário para vídeo) e cache.
    location /media/ {
        internal;
        alias /var/www/avisos-cond/storage/uploads/;
        # No X-Accel-Redirect o Nginx descarta os cabeçalhos de segurança da aplicação: reaplicamos.
        add_header X-Content-Type-Options "nosniff" always;
        add_header Content-Security-Policy "default-src 'none'; sandbox" always;
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

**Confiança no proxy** (`/etc/nginx/conf.d/avisos-proxy.conf`, no contexto `http`): só o IP do proxy pode dizer que o acesso foi HTTPS (um acesso direto à VM com `X-Forwarded-Proto: https` forjado é ignorado):

```nginx
geo $realip_remote_addr $avisos_via_proxy {
    default       0;
    172.16.31.29  1;      # IP do proxy reverso
}
map "$avisos_via_proxy:$http_x_forwarded_proto" $avisos_https {
    default  off;
    "1:https" on;
}
```

Com `APP_URL=https://...` no `.env`, o cookie de sessão sai com `Secure`. Verificado em 2026-11-17: o rate limit grava o IP real do cliente (não o do proxy) e ignora um `X-Forwarded-For` forjado.

**Firewall da VM** (`/etc/nftables.conf`, serviço `nftables` habilitado): a porta 80 só aceita o IP do proxy e a própria VM; o resto (SSH etc.) não é alterado.

```nft
table inet avisos_fw
delete table inet avisos_fw
table inet avisos_fw {
    set proxy_reverso { type ipv4_addr; elements = { 172.16.31.29 } }
    chain input {
        type filter hook input priority filter; policy accept;
        iifname "lo" accept
        tcp dport 80 ip saddr @proxy_reverso accept
        tcp dport 80 counter drop
    }
}
```

Se o IP do proxy mudar, atualize **os três lugares**: `set_real_ip_from`, o `geo` acima e o conjunto do nftables.

**Log do Nginx sem o token da TV** (`/etc/nginx/conf.d/avisos-log.conf`, no contexto `http`):

```nginx
map $request_uri $avisos_uri_mascarada {
    "~^/tv/[A-Za-z0-9]{12}(?<resto>.*)$"  /tv/xxxxxxxxxxxx$resto;
    default                                $request_uri;
}
log_format avisos '$remote_addr - $remote_user [$time_local] "$request_method $avisos_uri_mascarada $server_protocol" '
                  '$status $body_bytes_sent "-" "$http_user_agent"';
```

e, no `server`: `access_log /var/log/nginx/avisos.access.log avisos;`. Repita o mesmo mascaramento no log do proxy reverso externo.

**PHP-FPM** (uploads): o padrão do PHP (2 MB) é menor que `UPLOAD_MAX_MB`. Crie `/etc/php/8.4/fpm/conf.d/99-avisos.ini` com `upload_max_filesize = 12M`, `post_max_size = 14M` e `max_file_uploads = 5` (sempre acima de `UPLOAD_MAX_MB`) e reinicie o `php8.4-fpm`. A aplicação valida o limite exato e mostra uma mensagem clara; envios maiores que o corpo permitido recebem erro 413.

**Cron** (agendador):

Em `/etc/cron.d/avisos-cond` (a cada minuto; só roda o que estiver vencido):

```
* * * * * www-data /usr/bin/php /var/www/avisos-cond/bin/cron.php >> /var/www/avisos-cond/storage/logs/cron.log 2>&1
```

O agendador executa tarefas de **manutenção**, cada uma com seu intervalo:

| Tarefa | Intervalo | O que faz |
|---|---|---|
| `limpar_rate_limits` | 1 h | apaga contadores de tentativas (login, recuperação de senha, token de TV) com mais de 1 dia e sem bloqueio ativo |
| `limpar_resets_de_senha` | 1 dia | apaga tokens de recuperação já usados ou expirados há mais de 7 dias (nunca os ainda válidos) |
| `detectar_dispositivos_offline` | 1 min | registra no histórico (`dispositivo_eventos`) quando uma TV fica offline (sem contato há mais de 3 min) e quando volta; só grava mudanças |
| `limpar_arquivos_orfaos` | 1 dia | apaga de `storage/uploads` arquivos que nenhum aviso usa, com mais de 24 h (tem travas de segurança) |

Não existe tarefa de "publicar" ou "expirar" avisos: o que está no ar é calculado na hora pelo início e fim de cada aviso (na TV e no painel), então nada precisa ser mudado no banco quando o horário chega.

Duas execuções nunca se sobrepõem (lock no PostgreSQL, solto sozinho se o processo morrer), todas as tarefas são idempotentes, e a falha de uma é registrada em `storage/logs/app.log` sem impedir as outras (a tarefa que falhou só é tentada de novo depois de 5 min). Comandos: `php bin/cron.php` (executa as vencidas), `php bin/cron.php --forcar` (todas agora) e `php bin/cron.php --status` (histórico de cada tarefa, guardado na tabela `tarefas_agendadas`). Código de saída 0 = ok, 1 = alguma tarefa falhou. Para criar uma tarefa nova, implemente `App\Services\Tarefas\Tarefa` e registre em `App\CronFactory`.

**Rotação de logs** (`/etc/logrotate.d/avisos-cond`): semanal, 8 cópias, compactadas, para `storage/logs/*.log`.

**Backup** (`bin/backup.sh`): todo dia às 03:30, em `/etc/cron.d/avisos-cond-backup` (como root, para ler o `.env` e os uploads):

```
30 3 * * * root /var/www/avisos-cond/bin/backup.sh >> /var/www/avisos-cond/storage/logs/backup.log 2>&1
```

Cada execução gera, em `/var/backups/avisos-cond` (pasta `0700`; mude com `BACKUP_DIR` no `.env`), dois arquivos com a data: `avisos-AAAAMMDD-HHMMSS.dump` (`pg_dump -Fc`, já conferido com `pg_restore --list`) e `uploads-AAAAMMDD-HHMMSS.tar.gz` (conferido com `tar -t`). Só depois de um backup novo e válido ele apaga os do nosso padrão com mais de `BACKUP_KEEP_DAYS` dias (padrão 14). Se algo falha, sai com código 1, não apaga nada e não deixa arquivo pela metade. A senha do banco só vai por `PGPASSWORD` (nunca em linha de comando nem em log).

**Importante: a cópia na própria VM não protege contra a perda da VM.** Copie `/var/backups/avisos-cond` para outro lugar (outro servidor, NAS ou nuvem), por exemplo com `rsync` agendado.

**Restaurar** (testado em 2026-11-17 num banco temporário):

```bash
# banco: crie um banco vazio e restaure o dump (troque o nome do arquivo)
sudo -u postgres createdb avisos_cond_restaurado
cat /var/backups/avisos-cond/avisos-AAAAMMDD-HHMMSS.dump | sudo -u postgres pg_restore --no-owner -d avisos_cond_restaurado
# (para voltar ao banco de produção, restaure num banco novo, confira e então aponte DB_NAME no .env para ele)

# arquivos: devolva as mídias para a pasta de uploads, dono www-data
sudo tar -xzf /var/backups/avisos-cond/uploads-AAAAMMDD-HHMMSS.tar.gz -C /var/www/avisos-cond/storage/uploads
sudo chown -R www-data:www-data /var/www/avisos-cond/storage/uploads
```

## Instalando a tela no condomínio

1. No painel Embracore (**Dispositivos → Novo dispositivo**), escolha o condomínio e dê um nome à TV (ex.: "Elevador social"). O sistema gera o endereço `https://avisos.exemplo.com.br/tv/{token}`; a página do dispositivo mostra o endereço e o passo a passo. O token tem 12 caracteres e vale em maiúsculas ou minúsculas. Para revogar uma TV, desmarque "Dispositivo ativo"; se o endereço vazar, use "Trocar token".
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

### Como a TV funciona

- A página consulta o servidor a cada 30-60 s (`TV_POLL_SECONDS`); cada consulta é o *heartbeat* do dispositivo (o painel mostra o último contato).
- A rotação é feita na própria TV pelo horário corrigido do servidor, então um relógio errado no aparelho não faz avisos entrarem ou saírem na hora errada. Avisos **urgentes** e **fixos** ficam numa faixa no topo da TV (sempre 40% da altura), todos ao mesmo tempo e dividindo o espaço (quanto mais avisos, menor cada um), só com texto; os demais avisos passam em carrossel abaixo da faixa. Sem avisos, aparece um relógio grande; se o condomínio estiver desativado, só o relógio.
- **Sem internet** a TV continua com o último conteúdo (Service Worker + armazenamento local) e decide o que mostrar pelo relógio: o que venceu sai, o agendado entra na hora certa. **O modo offline exige HTTPS** (o navegador só libera Service Worker em HTTPS); em HTTP puro a TV funciona, mas só online.
- Token inválido ou revogado: página "não encontrada" sem detalhes, e excesso de tentativas com token inválido bloqueia o IP por um tempo.
- O token fica mascarado no log de acesso do Nginx (`/etc/nginx/conf.d/avisos-log.conf`, ver abaixo).

## Roadmap

Resumo abaixo. O detalhamento item a item e o status real de cada um ficam em [`ROADMAP.md`](ROADMAP.md), atualizado a cada commit.

- **MVP**: login com rate limit, recuperação de senha, CRUD de condomínios, gerentes e avisos, cor de destaque do gerente, dispositivos (cadastro, token e heartbeat), tela da TV com rotação e cache offline.
- **Fase 2**: painel de status online/offline dos dispositivos, agendamento avançado, auditoria, pareamento por código.
- **Fase 3**: situação financeira (visualização e bloqueio manual; TV bloqueada mostra só o relógio), widget de clima, notificações externas (WhatsApp/e-mail), perfil zelador.

## Segurança

Resumo das regras (detalhes em `AGENTS.md`): isolamento rigoroso entre condomínios (tenants), Argon2id para senhas, CSRF em todo POST, escape de saída por padrão, validação de uploads por MIME real (somente imagens e vídeo), rate limit em login, recuperação de senha e token de TV.

**Autenticação**: as senhas são guardadas somente como hash **Argon2id** (nunca em texto puro; mínimo de 10 caracteres). O login limita tentativas por e-mail e por IP, a sessão é renovada no login e destruída no logout, e trocar a senha ou desativar a conta derruba os acessos abertos. A recuperação de senha usa um link de uso único, válido por 30 minutos, e o token só existe no e-mail (no banco fica o hash). Para criar o primeiro superadmin: `php bin/create-admin.php`.

## Licença

Software proprietário da Embracore. Todos os direitos reservados.
