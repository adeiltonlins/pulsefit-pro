# PulseFit Pro

PulseFit Pro em React + PHP 8.3 + SQLite, preparado para VPS Ubuntu/Nginx.

## Segurança

O repositório **não versiona** banco SQLite, uploads, backups, `.env`, chaves ou certificados. O banco real permanece apenas na VPS em `/var/www/pulsefit/storage/pulsefit.sqlite`.

## Deploy na VPS (repositório privado)

Como o repositório é privado, autentique a VPS no GitHub primeiro:

```bash
apt update && apt install -y git gh
gh auth login
```

Escolha GitHub.com → HTTPS → Login with a web browser. Depois:

```bash
cd /var/www
gh repo clone adeiltonlins/pulsefit-pro pulsefit
cd /var/www/pulsefit
bash deploy/instalar-na-vps.sh
```

O instalador cria o banco caso ele ainda não exista e pede nome, e-mail e senha do administrador diretamente no terminal. A senha não é gravada no GitHub.

Endereço temporário atual:

`http://pulsefit.179.199.128.50.nip.io`

## Atualizações

```bash
cd /var/www/pulsefit
git pull
bash deploy/atualizar-na-vps.sh
```

O processo de atualização preserva `storage/`, portanto não apaga usuários, uploads ou o banco.
