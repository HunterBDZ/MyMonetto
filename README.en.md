<div align="center">

<img src="public/assets/img/marca.svg" alt="" width="96">

# MyMonetto

<sub>Formerly called Financeiro. Commands and folders keep the old name.</sub>

**Track your household money on your own computer: add expenses in seconds, see how the month will end, and plan purchases, debts and investments.**

No subscription, no cloud, and it never asks for your bank password.

![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white) ![SQLite](https://img.shields.io/badge/SQLite-003B57?logo=sqlite&logoColor=white) ![No dependencies](https://img.shields.io/badge/dependencies-none-success)

[Português](README.md)

<br>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/img/pc-inicio-escuro.webp">
  <img src="docs/img/pc-inicio-claro.webp" alt="Home screen: account balance, month summary, latest entries and where the money went" width="860">
</picture>

<sub>All screenshots use made-up data from the demo mode. The app also runs in English and Spanish; the screenshots are in Portuguese.</sub>

</div>

## What it does

- **See your account's future:** month by month, see how much you will have and try "what if" without saving anything.
- **Find the best month to buy:** paste a product link, in any currency, and the app shows when the purchase fits your plan.
- **Find out if paying a loan early is worth it:** type an amount and see what is left, when it ends and how much interest you save.
- **Know your next card bills:** add installment purchases and see each bill before it arrives.
- **Get a report that explains your month:** download a PDF, by month or year, with charts, what went well, what went badly and what can improve.
- **Pick your look:** 8 palettes with preview, each in light, dark or automatic, including high-contrast and color-blind options.

## Install

On Windows, Mac and Linux there is no database or other program to install.

### Windows

64-bit Windows 10 or 11. It does not ask for administrator rights.

1. Download `financeiro-…-windows-x86_64.exe` from [Releases](https://github.com/HunterBDZ/MyMonetto/releases/latest).
2. Double-click the file.
3. If **"Windows protected your PC"** appears, click **More info** and then **Run anyway**.
4. At the end, leave **Open MyMonetto** checked and create your account in the browser.

After that, open it from the **Financeiro** icon on the desktop. It starts by itself when you sign in to Windows.

### Mac

A Mac with an Apple chip and macOS 12 or newer. Intel Macs are not supported yet.

1. Download `financeiro-…-macos-arm64.dmg` from [Releases](https://github.com/HunterBDZ/MyMonetto/releases/latest) and open it.
2. Drag **Financeiro** to **Applications** and open it from there.
3. If the Mac says it cannot verify the app, open **System Settings → Privacy & Security** and click **Open Anyway**.
4. Create your account in the browser.

After that, open Financeiro from Applications. It starts by itself when you sign in to the Mac.

> [!WARNING]
> The Windows and Mac installers are new: the Windows one has been installed on a real computer, and the Mac one has not been tried on a real Mac yet. The warnings in step 3 appear because they do not have a paid signature yet, not because a problem was found.

### Linux

Download the package from [Releases](https://github.com/HunterBDZ/MyMonetto/releases/latest) and run, without `sudo`:

```sh
tar xzf financeiro-*-linux-*.tar.gz
cd financeiro-*/
./instalar
```

Open `http://localhost:8484` and create your account.

### Ubuntu or Debian server

```sh
git clone https://github.com/HunterBDZ/MyMonetto.git
sudo bash Financeiro/instalar.sh
```

### Docker

`docker compose up -d`, in the repository folder.

To use it on your phone, open **Settings → Use on your phone** and scan the code with the camera. The [installation manual](docs/INSTALACAO.md) is in Portuguese.

## Update and back up

In **Settings → Backup and updates**, you can look for a new version, update, download a copy of your data and restore a backup, without a terminal. The app also makes one copy a day.

Nothing updates by itself. Before applying a version, the app checks its signature and tests it on a copy of your data. If something fails, it goes back by itself. On a server, the same steps run with `sudo financeiro-atualizar`.

## Documentation

The detailed documentation is in Portuguese:

- [Installation manual](docs/INSTALACAO.md)
- [Inside the app](docs/POR-DENTRO.md)
- [Security](docs/SEGURANCA.md), including what leaves your computer
- [Privacy and notice](docs/PRIVACIDADE.md) (in Portuguese): cookies, outbound connections and the license
- [Decisions](docs/decisoes.md)
- [Contributing](CONTRIBUTING.md) and [reporting a vulnerability](SECURITY.md)

## License

© 2026 Hunter. Released under the [AGPL-3.0-or-later](LICENSE).

Third-party components: Manrope, Sora and JetBrains Mono fonts under the OFL. See the [third-party notices](THIRD_PARTY_NOTICES.md).
