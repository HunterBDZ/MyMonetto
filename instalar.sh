#!/usr/bin/env bash
# Instala o Financeiro com um comando, sem nenhuma pergunta, em Ubuntu 24.04 ou mais novo, Debian 13 ou derivados:
#
#   sudo bash instalar.sh
#
# Instala o que falta (Apache e PHP), cria o banco (um arquivo SQLite em /var/lib/financeiro), publica o site em
# HTTPS e ativa o backup diário. A conta da pessoa é criada depois, no navegador, com o código que aparece no fim.
# Se parar no meio, é só rodar de novo. Não mexe num Financeiro que já esteja instalado, nem no firewall.
#
# Variáveis opcionais: FIN_REPO (de onde baixar), FIN_DIR (onde guardar a cópia do repositório) e
# FIN_VERSAO (o que instalar; o padrão é a última versão publicada).
set -uo pipefail

REPO_URL=${FIN_REPO:-https://github.com/HunterBDZ/MyMonetto.git}
CLONE=${FIN_DIR:-/opt/financeiro}
DEST=/var/www/financeiro
DADOS=/var/lib/financeiro
ANDAMENTO=/var/lib/financeiro-instalando
LOG=/var/log/financeiro-instalacao.log
PACOTES=(apache2 php libapache2-mod-php php-mbstring php-curl php-xml php-sqlite3 git rsync openssl cron sudo iproute2 ca-certificates curl)
export DEBIAN_FRONTEND=noninteractive

TOTAL=5
ETAPA=""
etapa() { ETAPA=$2; printf '[%s/%s] %s...\n' "$1" "$TOTAL" "$2"; }
para() { printf '\nNão deu para instalar: %s\n' "$*" >&2; exit 1; }
falhou() {
    printf '\nA instalação parou em "%s".\nDetalhes: %s\nCorrija o problema e rode o mesmo comando de novo: ele continua de onde parou.\n' "$ETAPA" "$LOG" >&2
    exit 1
}
# roda em silêncio, com a saída no log; se falhar, mostra o fim do log
rode() {
    echo "+ $*" >> "$LOG"
    if ! "$@" >> "$LOG" 2>&1; then
        tail -n 8 "$LOG" >&2
        falhou
    fi
}
candidato() { apt-cache policy "$1" | sed -n 's/ *Candidato: *//p; s/ *Candidate: *//p' | head -1 | sed 's/^[0-9]*://; s/+.*//'; }
tem_versao() { [ -n "$1" ] && [ "$1" != "(none)" ] && [ "$1" != "(nenhum)" ]; }
endereco() {
    local ip
    ip=$(ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p')
    echo "${ip:-$(hostname -I 2>/dev/null | awk '{print $1}')}"
}
fim() {
    local ip cod; ip=$(endereco)
    cod=$(cat "$DADOS/primeira-conta.txt" 2>/dev/null)
    cat <<MSG

Pronto! O Financeiro está no ar.

  1. Abra no navegador:  https://${ip:-IP_DESTE_SERVIDOR}
  2. O navegador vai avisar que a conexão não é segura. Isso é esperado na primeira vez:
     clique em Avançado e depois em Continuar.
  3. Crie a sua conta na tela que aparecer${cod:+, com este código de instalação:  $cod}.

Dica: reserve o IP ${ip:-deste servidor} para esta máquina no roteador, para o endereço não mudar.
MSG
    if command -v financeiro-atualizar >/dev/null; then echo "Para atualizar no futuro: sudo financeiro-atualizar"; fi
}

# ---- antes de mexer em qualquer coisa
[ "$(id -u)" = 0 ] || para "rode com sudo: sudo bash instalar.sh"
command -v apt-get >/dev/null || para "este instalador funciona em Ubuntu 24.04 ou mais novo, Debian 13 e derivados."

if [ -f "$DEST/config/.env" ] && [ ! -f "$ANDAMENTO" ]; then
    printf 'O Financeiro já está instalado aqui: %s\n' "$DEST"
    exit 0
fi

: >> "$LOG"; chmod 600 "$LOG"
echo "==== $(date '+%F %T')" >> "$LOG"

apache_outro=$(ss -ltnpH 2>/dev/null | grep -E ':(80|443)\s' | grep -v apache2 | head -1)
[ -z "$apache_outro" ] || para "as portas 80 ou 443 já estão em uso por outro programa. Libere essas portas e rode de novo."

etapa 1 "Instalando Apache e PHP (pode levar alguns minutos)"
rode apt-get update -q
# o pacote php do Ubuntu 24.04 é o 8.3: o Financeiro precisa do 8.4 ou mais novo
versao_php=$(candidato php)
tem_versao "$versao_php" || para "este sistema não tem o pacote do PHP. Use Ubuntu 24.04 ou mais novo, ou Debian 13."
if ! dpkg --compare-versions "$versao_php" ge 8.4; then
    . /etc/os-release
    # derivados do Ubuntu, como o Mint, informam a base em UBUNTU_CODENAME e usam o mesmo repositório
    [ "${ID:-}" = ubuntu ] || [ -n "${UBUNTU_CODENAME:-}" ] || para "este sistema só oferece PHP $versao_php, e o Financeiro precisa do 8.4 ou mais novo."
    command -v add-apt-repository >/dev/null || rode apt-get install -y -q software-properties-common
    rode add-apt-repository -y ppa:ondrej/php
    rode apt-get update -q
fi
rode apt-get install -y -q "${PACOTES[@]}"
php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' || para "o PHP instalado é anterior ao 8.4."
php -r 'exit(class_exists("PDO") && in_array("sqlite", PDO::getAvailableDrivers(), true) ? 0 : 1);' || para "o PHP instalado não tem o SQLite (pacote php-sqlite3)."
mkdir -p "$(dirname "$ANDAMENTO")"; touch "$ANDAMENTO"

etapa 2 "Baixando o Financeiro"
# rodando de dentro de um clone, usa ele mesmo; se veio por curl, baixa
AQUI=$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd)
PACOTE=0
if [ -n "$AQUI" ] && [ -d "$AQUI/.git" ] && [ -f "$AQUI/database/schema.sqlite.sql" ]; then
    CLONE=$AQUI
elif [ -n "$AQUI" ] && [ -f "$AQUI/database/schema.sqlite.sql" ]; then
    PACOTE=1 # veio de um pacote, sem o repositório: copia direto, e as atualizações vêm em outro pacote
elif [ ! -d "$CLONE/.git" ]; then
    rode git clone -q "$REPO_URL" "$CLONE"
fi
if [ ! -f "$DEST/VERSION" ]; then
    mkdir -p "$DEST"
    if [ "$PACOTE" = 1 ]; then
        rode rsync -a --exclude instalar.sh --exclude LEIA-ME.txt "$AQUI/" "$DEST/"
    else
        dono=$(stat -c %U "$CLONE")
        git_c() { sudo -H -u "$dono" git -C "$CLONE" "$@"; }
        versao=${FIN_VERSAO:-$(git_c tag --list 'v[0-9]*' --sort=-v:refname | head -1)}
        if [ -z "$versao" ]; then
            printf 'Aviso: o repositório ainda não tem versão publicada. Instalando o commit atual: o financeiro-atualizar só funciona depois da 1ª tag.\n' >&2
        fi
        git_c archive "${versao:-HEAD}" | tar -x -C "$DEST" || falhou
    fi
fi

etapa 3 "Criando o banco de dados"
ENV_ARQ="$DEST/config/.env"
install -d -m 700 -o www-data -g www-data "$DADOS"
if [ ! -f "$ENV_ARQ" ]; then
    cat > "$ENV_ARQ" <<ENV
# Criado pelo instalar.sh. O banco é o arquivo $DADOS/financeiro.sqlite, com as cópias em $DADOS/backups.
APP_ENV=production
DB_DRIVER=sqlite
DB_FILE=financeiro.sqlite
PASTA_DADOS=$DADOS
FIN_MODO=servidor
SESSION_LIFETIME=28800
MOTOR_V2=1
# 1 = atrás de um proxy seu com HTTPS: confia em X-Forwarded-Proto
CONFIAR_PROXY=0
# nomes aceitos no endereço, separados por vírgula; vazio aceita qualquer um
HOSTS_PERMITIDOS=
ENV
fi
chown -R www-data:www-data "$DEST"
chmod 640 "$ENV_ARQ"
rode sudo -u www-data php "$DEST/database/instalar.php"

etapa 4 "Publicando o site em HTTPS"
IP=$(endereco); NOME=$(hostname)
if [ ! -f /etc/ssl/private/financeiro.key ]; then
    rode openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
        -keyout /etc/ssl/private/financeiro.key -out /etc/ssl/certs/financeiro.crt \
        -subj "/CN=${IP:-$NOME}" -addext "subjectAltName=${IP:+IP:$IP,}DNS:$NOME,DNS:localhost,IP:127.0.0.1" \
        -addext "basicConstraints=critical,CA:FALSE"
    chmod 600 /etc/ssl/private/financeiro.key
fi
cat > /etc/apache2/sites-available/financeiro.conf <<APACHE
<VirtualHost *:80>
    RewriteEngine On
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</VirtualHost>

<VirtualHost *:443>
    DocumentRoot $DEST/public

    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/financeiro.crt
    SSLCertificateKeyFile /etc/ssl/private/financeiro.key

    <Directory $DEST/public>
        Options -Indexes -MultiViews
        AllowOverride All
        Require all granted
    </Directory>

    Header always set Strict-Transport-Security "max-age=31536000"

    ErrorLog \${APACHE_LOG_DIR}/financeiro-error.log
    CustomLog \${APACHE_LOG_DIR}/financeiro-access.log combined
</VirtualHost>
APACHE
rode a2enmod -q rewrite ssl headers
rode a2dissite -q 000-default
rode a2ensite -q financeiro
rode apache2ctl configtest
if [ -d /run/systemd/system ]; then rode systemctl restart apache2; else rode service apache2 restart; fi

etapa 5 "Ativando o backup diário e as atualizações"
rode install -m 755 "$DEST/tools/financeiro-backup.sh" /usr/local/bin/financeiro-backup.sh
echo '30 3 * * * root /usr/local/bin/financeiro-backup.sh' > /etc/cron.d/financeiro-backup
if [ "$PACOTE" = 0 ]; then
    rode install -m 755 "$DEST/tools/financeiro-atualizar.sh" /usr/local/bin/financeiro-atualizar
    echo "REPO=$CLONE" > /etc/financeiro-atualizar.conf
fi

codigo=000
for _ in $(seq 1 15); do
    codigo=$(curl -sk -m 5 -o /dev/null -w '%{http_code}' https://127.0.0.1/criar-conta)
    case "$codigo" in 200|302) break ;; esac
    sleep 1
done
case "$codigo" in
    200|302) ;;
    *) ETAPA="conferir o site (resposta $codigo)"; tail -n 8 /var/log/apache2/financeiro-error.log >> "$LOG" 2>/dev/null; falhou ;;
esac

rm -f "$ANDAMENTO"
fim
