#!/usr/bin/env bash
# Backup do Avisos-Cond: dump do PostgreSQL + cópia compactada de storage/uploads.
#
# Uso:  bin/backup.sh            (feito pelo cron, ver /etc/cron.d/avisos-cond-backup)
# Lê DB_* e UPLOAD_DIR do .env; BACKUP_DIR e BACKUP_KEEP_DAYS são opcionais (padrões abaixo).
# Variáveis de ambiente com o mesmo nome têm prioridade sobre o .env (usado nos testes).
# A senha do banco vai por PGPASSWORD (nunca na linha de comando nem na saída).
# Saída: 0 = backup completo e verificado; 1 = falhou (nada foi apagado nesse caso).
set -euo pipefail
umask 077

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${BACKUP_ENV_FILE:-$RAIZ/.env}"

# Lê uma chave do .env sem executar nada dele (o valor pode ter caracteres especiais).
valor() {
    local nome="$1" atual="${!1:-}"
    if [[ -n "$atual" ]]; then printf '%s' "$atual"; return; fi
    [[ -r "$ENV_FILE" ]] || return 0
    grep -E "^${nome}=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/" || true
}

DB_HOST="$(valor DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(valor DB_PORT)"; DB_PORT="${DB_PORT:-5432}"
DB_NAME="$(valor DB_NAME)"
DB_USER="$(valor DB_USER)"
DB_PASS="$(valor DB_PASS)"
UPLOAD_DIR="$(valor UPLOAD_DIR)"
BACKUP_DIR="$(valor BACKUP_DIR)"; BACKUP_DIR="${BACKUP_DIR:-/var/backups/avisos-cond}"
GUARDAR_DIAS="$(valor BACKUP_KEEP_DAYS)"; GUARDAR_DIAS="${GUARDAR_DIAS:-14}"

log() { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
falhar() { log "ERRO: $*" >&2; exit 1; }

[[ -n "$DB_NAME" && -n "$DB_USER" ]] || falhar "DB_NAME/DB_USER não definidos (.env: $ENV_FILE)"
[[ -n "$UPLOAD_DIR" ]] || falhar "UPLOAD_DIR não definido"
[[ "$GUARDAR_DIAS" =~ ^[0-9]+$ && "$GUARDAR_DIAS" -ge 1 ]] || falhar "BACKUP_KEEP_DAYS inválido: $GUARDAR_DIAS"
command -v pg_dump >/dev/null || falhar "pg_dump não encontrado (instale postgresql-client)"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

# Nunca duas execuções ao mesmo tempo.
exec 9>"$BACKUP_DIR/.lock"
flock -n 9 || { log "Outro backup em andamento; saindo."; exit 0; }

CARIMBO="$(date '+%Y%m%d-%H%M%S')"
DUMP="$BACKUP_DIR/avisos-$CARIMBO.dump"
ARQUIVOS="$BACKUP_DIR/uploads-$CARIMBO.tar.gz"
# Escreve em arquivos temporários e só renomeia no fim: um backup pela metade nunca parece completo.
trap 'rm -f "$DUMP.tmp" "$ARQUIVOS.tmp"' EXIT

log "Banco: pg_dump de $DB_NAME"
PGPASSWORD="$DB_PASS" pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Fc --no-owner -f "$DUMP.tmp" \
    || falhar "pg_dump falhou"
# Confere que o dump é legível (cabeçalho e índice) antes de aceitá-lo.
pg_restore --list "$DUMP.tmp" >/dev/null || falhar "o dump gerado não é válido"
mv "$DUMP.tmp" "$DUMP"

if [[ -d "$UPLOAD_DIR" ]]; then
    log "Arquivos: $UPLOAD_DIR"
    tar -czf "$ARQUIVOS.tmp" -C "$UPLOAD_DIR" . || falhar "tar dos uploads falhou"
    tar -tzf "$ARQUIVOS.tmp" >/dev/null || falhar "o arquivo de uploads gerado não é válido"
    mv "$ARQUIVOS.tmp" "$ARQUIVOS"
else
    log "Aviso: $UPLOAD_DIR não existe; só o banco foi copiado."
fi

# Retenção: só depois de um backup novo e válido, e só arquivos com o nosso padrão de nome.
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'avisos-*.dump' -o -name 'uploads-*.tar.gz' \) -mtime "+$GUARDAR_DIAS" -print -delete \
    | sed 's/^/Removido (antigo): /' || true

log "Pronto: $(basename "$DUMP") ($(du -h "$DUMP" | cut -f1))$([[ -f "$ARQUIVOS" ]] && echo ", $(basename "$ARQUIVOS") ($(du -h "$ARQUIVOS" | cut -f1))")"
