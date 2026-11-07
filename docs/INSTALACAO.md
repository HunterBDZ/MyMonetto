# Manual de instalação

> [!WARNING]
> Os instaladores de Windows e Mac são novos: o de Windows já foi instalado num computador de verdade, e o de Mac ainda não passou por nenhum Mac. Guarde um backup e conte o que acontecer numa [issue](https://github.com/HunterBDZ/MyMonetto/issues).

## No Windows

Funciona no Windows 10 e 11, de 64 bits. Não pede administrador, e nenhum outro programa precisa ser instalado.

1. Baixe `financeiro-…-windows-x86_64.exe` em [Versões](https://github.com/HunterBDZ/MyMonetto/releases/latest).
2. Dê dois cliques no arquivo.
3. Se aparecer **"O Windows protegeu o computador"**, clique em **Mais informações** e depois em **Executar assim mesmo**. O aviso aparece porque o instalador ainda não tem assinatura paga, e não porque foi encontrado algum problema.
4. No fim, deixe marcado **Abrir o Financeiro**. O site abre no navegador.
5. Crie a sua conta e informe a data de início e quanto você tem hoje.

Depois disso, use o ícone **Financeiro** na área de trabalho ou no menu Iniciar. O programa inicia sozinho quando você entra no Windows e não mostra nenhuma janela. O endereço é `http://localhost:8484`.

Se o Windows 11 bloquear o instalador sem oferecer **Executar assim mesmo**, o **Controle Inteligente de Aplicativos** está ligado. Hoje, ele só aceita programas assinados, e não há como liberar só este.

- **Onde fica:** o programa em `%LOCALAPPDATA%\Programs\Financeiro`, e os dados em `%LOCALAPPDATA%\Financeiro`.
- **Desinstalar:** em **Configurações → Aplicativos**, escolha Financeiro. O desinstalador pergunta se os dados também devem ser apagados. Se você não confirmar, eles ficam.
- **Atualizar:** em **Ajustes → Backup e atualização**, ou instale um pacote mais novo por cima. Os dados ficam.
- **Pelo terminal:** `"%LOCALAPPDATA%\Programs\Financeiro\financeiro.cmd" status`. Também aceita `iniciar`, `parar` e `senha LOGIN`.

## No Mac

Funciona em Macs com chip Apple e macOS 12 ou mais novo. **Mac Intel não é atendido:** o FrankenPHP, que roda o site, não tem versão oficial para ele. Nesse caso, use [Docker](#com-docker).

1. Baixe `financeiro-…-macos-arm64.dmg` em [Versões](https://github.com/HunterBDZ/MyMonetto/releases/latest) e abra o arquivo.
2. Arraste o **Financeiro** para **Applications** (Aplicativos).
3. Abra o Financeiro na pasta Aplicativos. Se abrir de dentro do disco, ele pede para arrastar antes.
4. Se o Mac disser que **não pode verificar o aplicativo**:
   1. Clique em **OK** ou **Concluído**, sem mover para o Lixo.
   2. Abra **Ajustes do Sistema** e depois **Privacidade e Segurança**.
   3. Desça até o fim e clique em **Abrir Mesmo Assim**, ao lado de Financeiro.
   4. Confirme com a senha do Mac.
5. O site abre no navegador. Crie a sua conta e informe a data de início e quanto você tem hoje.

O aviso aparece porque o aplicativo ainda não tem assinatura paga da Apple. Na primeira vez, o Mac também pode avisar que o Financeiro adicionou um item que abre em segundo plano: é ele que inicia o programa sozinho quando você entra no Mac. O endereço é `http://localhost:8484`.

- **Onde fica:** os dados e o programa em uso em `~/Library/Application Support/Financeiro`.
- **Desinstalar:** dê dois cliques em **Desinstalar o Financeiro**, dentro do `.dmg`. Ele pergunta se os dados também devem ser apagados. Se você não confirmar, eles ficam.
- **Atualizar:** em **Ajustes → Backup e atualização**, ou arraste um pacote mais novo para Aplicativos e abra. Os dados ficam.
- **Pelo Terminal:** `/Applications/Financeiro.app/Contents/Resources/financeiro status`. Também aceita `iniciar`, `parar` e `senha LOGIN`.

## No seu computador, com Linux

Funciona em qualquer distribuição de 64 bits, sem `sudo` e sem instalar banco de dados.

1. Baixe o pacote da sua máquina em [Versões](https://github.com/HunterBDZ/MyMonetto/releases/latest): `x86_64` na maioria dos computadores, `aarch64` em Raspberry Pi de 64 bits. Se quiser, confira com o `SHA256SUMS` que está junto.
2. Num terminal, na pasta do download:
   ```sh
   tar xzf financeiro-*-linux-*.tar.gz
   cd financeiro-*/
   ./instalar
   ```
3. Abra `http://localhost:8484` e crie a sua conta. Se a porta estiver ocupada, o instalador mostra outra.
4. Informe a data de início e quanto você tem hoje. Depois, é só lançar.

O programa fica em `~/.local/lib/financeiro`, e os dados, em `~/.local/share/financeiro`. Ele inicia sozinho com o computador. Para dar acesso a outra pessoa, use **Ajustes → Pessoas**.

| Comando | O que faz |
|---|---|
| `financeiro` | Abre no navegador |
| `financeiro status` | Diz se está no ar |
| `financeiro parar` e `financeiro iniciar` | Desliga e liga |
| `financeiro senha LOGIN` | Define uma senha nova para essa conta |
| `financeiro desinstalar` | Tira o programa e deixa os dados. Com `--apagar-dados`, apaga também |

### Usar no celular

Vale também para Windows e Mac. Abra **Ajustes → Usar no celular**, ligue o acesso pela rede e leia o código com a câmera do celular. O celular precisa estar na mesma rede, e o computador, ligado.

> [!WARNING]
> Na rede, a conexão não é criptografada: quem estiver nela pode ver a senha e os dados. Ligue só em rede de confiança.

Se o celular não abrir, em geral é o firewall do computador. O Financeiro não mexe nele. A tela mostra o que fazer em cada sistema. No Windows, permita o acesso quando o aviso aparecer, o que pode pedir a senha de quem administra o computador. No Mac, o firewall costuma vir desligado. No Linux, é um destes comandos:

```sh
sudo ufw allow 8484/tcp
sudo firewall-cmd --permanent --add-port=8484/tcp && sudo firewall-cmd --reload
```

### Backup e atualização

Tudo fica em **Ajustes → Backup e atualização**, com a primeira conta criada, no próprio computador:

- **Backup:** o app faz uma cópia por dia, na primeira vez que abre, e guarda as últimas 30. Baixe de vez em quando uma cópia para outro lugar.
- **Restaurar:** escolha da lista ou envie o arquivo. O que havia antes fica guardado em uma cópia.
- **Atualizar:** aperte **Procurar versão nova** e **Atualizar agora**. Se falhar, o site volta sozinho.

Instalar um pacote mais novo por cima também funciona: os dados ficam, e o banco acompanha ao abrir.

**O programa que roda o site.** O pacote traz junto o FrankenPHP, com o PHP dentro dele. **Atualizar agora** troca só o Financeiro, não esse programa. Por isso, a tela mostra numa linha discreta as versões do FrankenPHP e do PHP. Depois de **Procurar versão nova**, se houver um programa mais novo, aparece o aviso "Há uma versão nova do programa que roda o Financeiro", com o número da versão e o link para [Versões](https://github.com/HunterBDZ/MyMonetto/releases). Baixe o instalador dessa versão para o seu sistema e instale por cima, como na primeira vez. Os dados ficam. Vale a pena, porque o PHP antigo deixa de receber correções de segurança. Em servidor e em Docker, a tela não mostra nada disso: o PHP vem do sistema ou da imagem.

## Com Docker

Para quem já usa Docker, em servidor, NAS ou Raspberry Pi. Na pasta do repositório:

```sh
docker compose up -d
```

Abra `http://localhost:8484` e crie a sua conta. A tela pede um **código de instalação**: ele aparece em `docker compose logs financeiro` e também em `docker compose exec financeiro cat /data/primeira-conta.txt`. Os dados ficam no volume `financeiro-dados`.

- **Atualizar:** `docker compose pull && docker compose up -d`. O banco acompanha ao abrir, depois de uma cópia.
- **Outros aparelhos:** a porta fica só neste computador, e o app só aceita o endereço `localhost`. Para abrir, ponha um proxy com HTTPS na frente e defina, no `compose.yaml` e antes da primeira execução, `CONFIAR_PROXY: "1"` e em `HOSTS_PERMITIDOS` o nome ou o IP pelo qual os aparelhos vão chegar. Depois, esses valores ficam em `/data/config.env`.
- **Ninguém cria a conta antes de você:** sem o código de instalação, a tela não aceita a primeira conta. Depois dela, o código deixa de valer.

## Num servidor com Ubuntu ou Debian

Instala Apache e PHP, com HTTPS e o banco num arquivo SQLite, para um computador que fica sempre ligado.

Você precisa de um computador com **Ubuntu 24.04 ou mais novo, Debian 13 ou um derivado deles** (Linux Mint, LMDE), que fique ligado e na sua rede. Nele, rode:

```sh
git clone https://github.com/HunterBDZ/MyMonetto.git
sudo bash Financeiro/instalar.sh
```

Espere alguns minutos: nenhuma pergunta é feita. No fim, o instalador mostra o endereço do site e um **código de instalação**.

1. Abra esse endereço no navegador, em qualquer aparelho da sua rede.
2. O navegador avisa que a conexão não é segura. Isso é esperado na primeira vez: clique em **Avançado** e depois em **Continuar**.
3. Crie a sua conta, digitando o código de instalação. Ele impede que outra pessoa da rede crie a conta antes de você. Se perder o código, ele está em `/var/lib/financeiro/primeira-conta.txt`.
4. Informe a data de início e quanto você tem hoje. Depois, é só lançar.

Para dar acesso a outra pessoa, use **Ajustes → Pessoas**.

### O que o instalador faz

- Instala Apache e PHP, se faltarem. No Ubuntu 24.04 e no Mint, o PHP 8.4 ou mais novo vem do repositório `ppa:ondrej/php`.
- Coloca o site em `/var/www/financeiro` e cria o banco, um arquivo SQLite, em `/var/lib/financeiro`. Publica o site em HTTPS.
- Ativa o backup diário em `/var/lib/financeiro/backups` e instala o comando `financeiro-atualizar`. Os backups também aparecem em **Ajustes → Backup e atualização**.

Ele não mexe no firewall. Se parar no meio, corrija o que ele apontar e rode o mesmo comando de novo. Rodar de novo num site pronto não muda nada. O registro completo fica em `/var/log/financeiro-instalacao.log`.

> [!CAUTION]
> O app foi feito para a rede de casa. Para acessar de fora, use uma VPN, sem abrir portas no roteador.

### Dica: mantenha o mesmo endereço

Reserve o IP do servidor no roteador. Se o IP mudar, o site continua funcionando no endereço novo, mas o navegador volta a mostrar o aviso de conexão.

## Atualizar um servidor

Um comando faz tudo: baixa a última versão publicada, testa numa cópia do seu banco, faz backup, aplica as mudanças e confere o site. Se falhar antes de mexer no site, nada muda. Se falhar depois, ele volta o código e o banco sozinho.

**Para atualizar:**

```sh
sudo financeiro-atualizar --verificar   # mostra se há versão nova e o que muda
sudo financeiro-atualizar               # atualiza, depois de confirmar
```

✅ A última linha do resultado traz o backup, e **Ajustes** mostra a versão nova no fim da tela.

O próprio comando não se atualiza: se uma versão mudar `tools/financeiro-atualizar.sh`, o `CHANGELOG.md` avisa como instalar o novo.

## Restaurar um backup no servidor

Em SQLite, use a tela: **Ajustes → Backup e atualização**. Em MySQL, isto substitui os dados atuais pelos do backup:

```sh
sudo sh -c 'gunzip -c /var/backups/financeiro/ARQUIVO.sql.gz | mysql financeiro'
```

## Esqueci a senha

Outra pessoa com conta pode definir uma senha nova em **Ajustes → Pessoas**. Se não houver outra pessoa, gere uma senha nova no computador onde o Financeiro está, trocando `LOGIN`:

| Onde | Comando |
|---|---|
| Windows | `"%LOCALAPPDATA%\Programs\Financeiro\financeiro.cmd" senha LOGIN` |
| Mac | `/Applications/Financeiro.app/Contents/Resources/financeiro senha LOGIN` |
| Linux, pelo pacote | `financeiro senha LOGIN` |
| Servidor | `sudo -u www-data php /var/www/financeiro/tools/redefinir-senha.php LOGIN` |

O comando também desliga a verificação em duas etapas dessa conta, se estiver ligada. Depois de entrar, ligue de novo em **Ajustes**.

## Quando algo dá errado

| O que aparece | Causa provável | O que fazer |
|---|---|---|
| `as portas 80 ou 443 já estão em uso` | Outro programa usa as portas do site | Pare esse programa e rode o instalador de novo |
| `Erro interno. Tente novamente` | O app não conectou no banco | Confira `DB_*` em `config/.env` e veja `sudo tail /var/log/apache2/financeiro-error.log` |
| `Class "Pdo\Mysql" not found` | PHP anterior a 8.4 | Atualize o PHP |
| `Call to undefined function mb_…` | Falta a extensão `mbstring` | `sudo apt install php-mbstring` e recarregue o Apache |
| `could not find driver` | Falta a extensão do banco: `pdo_sqlite` (ou `pdo_mysql`, num site antigo em MySQL) | `sudo apt install php-sqlite3` (ou `php-mysql`) e recarregue o Apache |
| Só a página inicial abre; as outras dão 404 | `mod_rewrite` ou `AllowOverride` | Rode o instalador de novo |
| Página padrão do Apache | O site padrão continua ativo | `sudo a2dissite 000-default && sudo systemctl reload apache2` |
| `Arquivo de configuração não encontrado` | Falta o `.env`, ou o dono está errado | Rode o instalador de novo |
| `O banco está sem as tabelas` | O `schema.sql` não foi importado | Rode o instalador de novo |
| `A assinatura da atualização não confere` | O arquivo baixado não veio do projeto, ou veio alterado | O site não mudou. Não insista e [abra uma issue](https://github.com/HunterBDZ/MyMonetto/issues) |
| `Os seus dados são de uma versão mais nova` | Instalou um pacote mais velho que os dados | Instale a versão mais nova |
| `Faça isso no computador onde o Financeiro está instalado` | Backup, restauração e atualização não passam pelo celular | Abra no próprio computador |
| `Endereço não reconhecido` | O acesso pela rede está desligado, ou o endereço não é da rede de casa | Abra pelo endereço mostrado no computador. Atrás de outro nome, use `HOSTS_PERMITIDOS` em `config.env` |
| `outra atualização está rodando` | Dois comandos ao mesmo tempo | Espere o outro terminar |
| `os testes da versão … falharam no ensaio` | A versão nova não funciona no seu ambiente | O site não mudou. Não insista e [abra uma issue](https://github.com/HunterBDZ/MyMonetto/issues) |
| Bloqueado no login | 5 tentativas erradas | Espere 10 minutos |
| `O código de instalação não confere` | Num servidor ou no Docker, a primeira conta pede o código mostrado no fim da instalação | Veja em `/var/lib/financeiro/primeira-conta.txt` (servidor) ou em `docker compose logs financeiro` (Docker) |
| No Windows ou no Mac, o navegador abre uma página dizendo que o Financeiro não abriu | O programa não conseguiu iniciar | Reinicie o computador. Se continuar, a página mostra onde está o registro: envie numa [issue](https://github.com/HunterBDZ/MyMonetto/issues) |
| `Já está instalada uma versão mais nova` | O instalador é mais velho que o programa | Nada mudou. Baixe a versão mais nova |
| `Unknown collation: utf8mb4_0900_ai_ci` | O banco é anterior ao MySQL 8 ou ao MariaDB 11.4.5 | Use MySQL 8 ou MariaDB 11.4.5 ou mais novo |
| Aviso de JIT do PCRE no log | O kernel bloqueia memória JIT | Coloque `pcre.jit=0` no `php.ini` e recarregue o Apache |

## Personalizar

- **Moeda e fuso horário:** ficam em Ajustes e na primeira configuração, no site. Trocar a moeda muda só o símbolo: nada é convertido. O fuso não depende das tabelas de fuso do banco.
- **Categorias:** crie novas no Extrato. Não renomeie as de empréstimo e poupança, `Salário` e `Fatura do cartão`: o cálculo as encontra pelo nome.
