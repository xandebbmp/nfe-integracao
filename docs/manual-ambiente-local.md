# Manual — Montar no seu computador e depois levar para a VPS

Este manual ensina a montar o sistema **no seu computador** (Windows, Linux
ou macOS) para testar e homologar com calma, e depois **levar para uma VPS**
(servidor na nuvem) para produção.

> **Ideia principal:** nos três sistemas você vai rodar um **Ubuntu 24.04**,
> o mesmo da VPS. Assim, os comandos do [Manual de Implantação](manual-implantacao.md)
> valem para os dois, e o que funcionar no seu computador funciona igual na VPS.

| Seu computador | Como ter o Ubuntu 24.04 | Tempo |
|---|---|---|
| **Windows 10/11** | **WSL2** (recurso gratuito do próprio Windows) | ~20 min |
| **macOS** (Intel ou Apple M1/M2/M3/M4) | **Multipass** (máquina virtual leve e gratuita) | ~20 min |
| **Linux Ubuntu 24.04** | Instala **direto** no computador | ~5 min |
| **Outro Linux** (Fedora, Mint antigo, Arch...) | **Multipass** | ~20 min |

Depois disso, a instalação do sistema leva mais ~1h.

---

## Sumário

1. [Etapa 1 — Criar o Ubuntu no seu computador](#etapa-1--criar-o-ubuntu-no-seu-computador)
   - [Windows (WSL2)](#windows-wsl2)
   - [macOS (Multipass)](#macos-multipass)
   - [Linux](#linux)
2. [Etapa 2 — Instalar o sistema (com as diferenças do ambiente local)](#etapa-2--instalar-o-sistema)
3. [Etapa 3 — Usar e homologar no computador](#etapa-3--usar-e-homologar-no-computador)
4. [Etapa 4 — Levar para a VPS](#etapa-4--levar-para-a-vps)
5. [Problemas comuns do ambiente local](#problemas-comuns-do-ambiente-local)

> Leia antes a seção **"Como ler este manual"** do [Manual de Implantação](manual-implantacao.md#como-ler-este-manual):
> as mesmas regras valem aqui (blocos cinza, trocar o que está em MAIÚSCULAS, ✅ "Deve aparecer").

---

# Etapa 1 — Criar o Ubuntu no seu computador

Faça **só a parte do seu sistema** e depois vá para a [Etapa 2](#etapa-2--instalar-o-sistema).

## Windows (WSL2)

O WSL2 roda um Ubuntu de verdade dentro do Windows. Ele **não atrapalha** o
WAMP nem outros programas.

### 1.1 Instalar o Ubuntu

1. Clique no menu Iniciar, digite **PowerShell**, clique com o botão direito
   e escolha **"Executar como administrador"**.
2. Rode:

```bash
wsl --install -d Ubuntu-24.04
```

3. **Reinicie o computador** se ele pedir.
4. Depois de reiniciar, abra o menu Iniciar e clique em **"Ubuntu 24.04"**
   (na primeira vez pode demorar alguns minutos).
5. Ele pede um **nome de usuário** (letras minúsculas, sem espaço, ex.:
   `fiscal`) e uma **senha**. Anote a senha: ela é a senha do `sudo`.

✅ **Deve aparecer** algo como `fiscal@NOME-DO-PC:~$`.

> Se aparecer erro de **virtualização** ("Please enable the Virtual Machine
> Platform..."), a virtualização está desligada na BIOS. Veja
> [Problemas comuns](#problemas-comuns-do-ambiente-local).

### 1.2 Conferir se o Ubuntu está com o "systemd" ligado

O sistema usa o `systemd` para manter o worker rodando. Na janela do Ubuntu:

```bash
ps -p 1 -o comm=
```

✅ **Deve aparecer:** `systemd`.

Se aparecer outra coisa (ex.: `init`), ligue o systemd:

```bash
printf '[boot]\nsystemd=true\n' | sudo tee /etc/wsl.conf
```

Feche a janela do Ubuntu. No **PowerShell** (não precisa ser administrador):

```bash
wsl --shutdown
```

Abra o **Ubuntu 24.04** de novo e repita o `ps -p 1 -o comm=`: agora deve aparecer `systemd`.

### 1.3 Como o Windows e o Ubuntu conversam

| Para... | Faça assim |
|---|---|
| Abrir o terminal do Ubuntu | Menu Iniciar → **Ubuntu 24.04** |
| Ver arquivos do Windows de dentro do Ubuntu | O disco `C:` fica em `/mnt/c/` (ex.: Downloads = `/mnt/c/Users/SEU_USUARIO/Downloads/`) |
| Ver arquivos do Ubuntu pelo Windows Explorer | Digite `\\wsl$\Ubuntu-24.04\` na barra de endereço |
| Abrir o sistema no navegador do Windows | `http://localhost:8080` (porta **8080**, ver Etapa 2) |

> ⚠️ **Deixe a janela do Ubuntu aberta** enquanto estiver testando. Quando
> todas as janelas do Ubuntu são fechadas, o Windows desliga o Ubuntu depois
> de alguns segundos, e o worker para junto. As notas não se perdem:
> continuam na fila e são enviadas quando você abrir o Ubuntu de novo.

**Pronto.** Vá para a [Etapa 2](#etapa-2--instalar-o-sistema).

---

## macOS (Multipass)

O Multipass cria uma máquina virtual Ubuntu com um comando. Funciona em Macs
Intel e Apple Silicon (M1/M2/M3/M4).

### 1.1 Instalar o Multipass

1. Baixe o instalador em **https://canonical.com/multipass/install** (botão
   "Download Multipass for macOS") e instale normalmente (abrir o `.pkg`,
   Continuar, Instalar).
2. Abra o **Terminal** do Mac (Cmd+Espaço, digite "Terminal").

Confira:

```bash
multipass version
```

✅ **Deve aparecer** algo como `multipass 1.x.x`.

### 1.2 Criar o Ubuntu

Cria uma máquina chamada `nfse` com 2 processadores, 2 GB de memória e 20 GB de disco:

```bash
multipass launch 24.04 --name nfse --cpus 2 --memory 2G --disk 20G
```

✅ **Deve aparecer:** `Launched: nfse` (leva alguns minutos na primeira vez).

### 1.3 Entrar no Ubuntu

```bash
multipass shell nfse
```

✅ **Deve aparecer** algo como `ubuntu@nfse:~$`. Todos os comandos da Etapa 2
são digitados **aqui**. Para sair, digite `exit`.

> No Multipass o usuário é `ubuntu` e o `sudo` **não pede senha**.

### 1.4 Descobrir o endereço da máquina

Em **outra** janela do Terminal do Mac (fora do Ubuntu):

```bash
multipass info nfse
```

Anote o número em **IPv4** (ex.: `192.168.64.5`). O sistema vai abrir no
navegador do Mac em `http://ESSE_IP:8080`.

### 1.5 Ligar e desligar

| Para... | Comando (no Terminal do Mac) |
|---|---|
| Desligar a máquina | `multipass stop nfse` |
| Ligar de novo | `multipass start nfse` |
| Ver se está ligada | `multipass list` |
| Apagar tudo (cuidado!) | `multipass delete --purge nfse` |

O worker volta sozinho quando a máquina liga.

**Pronto.** Vá para a [Etapa 2](#etapa-2--instalar-o-sistema).

---

## Linux

### Se o seu computador já usa Ubuntu 24.04

Não precisa criar nada: abra o **Terminal** (Ctrl+Alt+T) e vá para a
[Etapa 2](#etapa-2--instalar-o-sistema). O sistema abre em `http://localhost:8080`.

Para conferir a versão:

```bash
grep PRETTY_NAME /etc/os-release
```

✅ Deve aparecer `Ubuntu 24.04...`. Se for outra versão ou outro Linux, use o Multipass (abaixo).

### Se for outro Linux (ou se não quiser instalar no seu computador)

Instale o Multipass (na maioria das distribuições, pelo Snap):

```bash
sudo snap install multipass
```

Depois siga **os mesmos passos do macOS**, a partir do
[1.2 Criar o Ubuntu](#12-criar-o-ubuntu) (os comandos `multipass` são iguais).

---

# Etapa 2 — Instalar o sistema

Agora, **dentro do Ubuntu** (janela do WSL, `multipass shell nfse` ou o
terminal do Linux), siga o [Manual de Implantação — Parte A](manual-implantacao.md#parte-a--instalação),
**com as diferenças abaixo**. Os passos que não aparecem na tabela são feitos exatamente
como estão no manual.

| Passo do manual | No computador local |
|---|---|
| **1 — Entrar no servidor** | **Pule.** Você já está no Ubuntu. |
| **2 — Baixar o sistema** | Igual. ⚠️ Baixe para `/var/www/nfe-integracao`, **nunca** para `/mnt/c/...` (no Windows, a pasta do Windows deixa tudo lento e bagunça as permissões). |
| **3 a 5** | Iguais. |
| **6 — Enviar o certificado** | **Não use `scp`.** Veja [Certificado no computador local](#certificado-no-computador-local), abaixo. |
| **7 e 8** | Iguais. |
| **9 — Colocar o site no ar** | Igual, **mas use a porta 8080**. Veja [Porta 8080](#porta-8080), abaixo. |
| **10 — HTTPS** | **Pule.** Não existe domínio no computador local. |
| **11 — Worker** | Igual. |
| **12 — Teste final** | Use os endereços de [Porta 8080](#porta-8080), com `http://` em vez de `https://`. |

### Certificado no computador local

Copie o `.pfx` para dentro do Ubuntu. Escolha o seu caso e **troque o caminho
e o nome do arquivo**:

**Windows (WSL2)** — o arquivo está, por exemplo, nos Downloads do Windows:

```bash
sudo cp "/mnt/c/Users/SEU_USUARIO/Downloads/CERTIFICADO.pfx" /var/www/nfe-integracao/storage/certs/empresa.pfx
```

**macOS (Multipass)** — rode no **Terminal do Mac** (fora do Ubuntu):

```bash
multipass transfer "/Users/SEU_USUARIO/Downloads/CERTIFICADO.pfx" nfse:/tmp/empresa.pfx
```

e depois **dentro do Ubuntu**:

```bash
sudo mv /tmp/empresa.pfx /var/www/nfe-integracao/storage/certs/empresa.pfx
```

**Linux direto** — o arquivo está na sua pasta Downloads:

```bash
sudo cp ~/Downloads/CERTIFICADO.pfx /var/www/nfe-integracao/storage/certs/empresa.pfx
```

Em todos os casos, termine protegendo o arquivo (dentro do Ubuntu):

```bash
sudo chown root:www-data /var/www/nfe-integracao/storage/certs/empresa.pfx && sudo chmod 640 /var/www/nfe-integracao/storage/certs/empresa.pfx
```

### Porta 8080

No computador, a porta 80 costuma estar ocupada (no Windows, pelo **WAMP**).
Por isso, no **passo 9.2**, depois de copiar o arquivo do site, troque o
domínio por `localhost` e a porta por `8080`:

```bash
sudo sed -i 's/SEU_DOMINIO_AQUI/localhost/; s/^    listen 80;/    listen 8080;/' /etc/nginx/sites-available/nfe-integracao
```

O resto do passo 9 (ativar o site, `nginx -t`, `reload`) segue igual.

Endereços para usar no navegador do seu computador:

| Sistema | Monitor | API |
|---|---|---|
| Windows (WSL2) e Linux direto | `http://localhost:8080/monitor/` | `http://localhost:8080/v1/nfse/...` |
| macOS e Multipass | `http://IP_DO_PASSO_1.4:8080/monitor/` | `http://IP_DO_PASSO_1.4:8080/v1/nfse/...` |

Teste da API (passo 12), **dentro do Ubuntu**:

```bash
curl -s -H "X-NFE-API-TOKEN: TOKEN_DO_ERP" http://localhost:8080/v1/nfse/capacidades.php
```

✅ **Deve aparecer** um texto começando com `{"ambiente":"HOMOLOGACAO"`.

> 🔒 Sem HTTPS, o token viaja sem proteção. No computador local isso é
> aceitável (o tráfego não sai da sua máquina). **Na VPS, o HTTPS é obrigatório.**

---

# Etapa 3 — Usar e homologar no computador

Tudo igual ao manual: siga a [Parte B — Homologação](manual-implantacao.md#parte-b--homologação).
A homologação funciona do seu computador, porque ele acessa a internet.
Antes, rode o verificador com o teste de rede:

```bash
cd /var/www/nfe-integracao && sudo -u www-data php bin/nfse_verificar.php --rede
```

Se aparecer `[ERRO] ... inacessivel`, a rede da empresa (proxy/firewall) está
bloqueando o site do governo. Peça ao TI a liberação de **saída HTTPS
(porta 443)** para os endereços mostrados, ou teste de outra rede.

> ⚠️ **Não mude para produção (`tpAmb = 1`) no computador local.** Produção
> só na VPS, que fica ligada 24h, tem HTTPS e backup.

### Guardar o registro da homologação

Quando a homologação der certo, copie a pasta de resultados para fora do
Ubuntu (ela é a prova do teste). Dentro do Ubuntu:

**Windows (WSL2)** — copia para os Documentos do Windows:

```bash
cp -r /var/www/nfe-integracao/storage/logs/homologacao "/mnt/c/Users/SEU_USUARIO/Documents/homologacao-nfse"
```

**macOS (Multipass)** — no **Terminal do Mac**:

```bash
multipass transfer --recursive nfse:/var/www/nfe-integracao/storage/logs/homologacao ~/Documents/homologacao-nfse
```

**Linux direto:**

```bash
cp -r /var/www/nfe-integracao/storage/logs/homologacao ~/homologacao-nfse
```

> Se o comando reclamar de permissão, coloque `sudo` na frente.

---

# Etapa 4 — Levar para a VPS

### 4.1 Escolher a VPS

Qualquer provedor serve, desde que ofereça:

- [ ] **Ubuntu 24.04 LTS**;
- [ ] **2 GB de memória** ou mais, **20 GB de disco** ou mais;
- [ ] **IP fixo (IPv4)**;
- [ ] de preferência **datacenter no Brasil** (respostas mais rápidas do governo);
- [ ] **backup/snapshot** do servidor (recomendado).

Também é preciso um **domínio** (ex.: `fiscal.suaempresa.com.br`) apontando
para o IP da VPS (registro tipo **A**), para o HTTPS funcionar.

### 4.2 O que levar e o que NÃO levar

| Item | Levar? | Como |
|---|---|---|
| **Código do sistema** | Sim | Baixado de novo do GitHub no passo 2 (não copie a pasta do seu computador). |
| **Certificado `.pfx`** | Sim | Pegue o **arquivo original** (guardado em local seguro) e envie pelo passo 6 do manual (`scp`). |
| **Senha do certificado** | Sim | Mesma de antes, no passo 7.1. |
| **Configuração da empresa** (`nfse.local.php`) | Sim, **refazendo** | Preencha de novo no passo 7.3. Pode usar o arquivo do computador como modelo, mas **gere tokens novos** (passo 7.2). |
| **Senha do banco** | **Não** | Gere uma **nova** na VPS (passo 5.1). |
| **Banco de dados** | **Não** | As notas de homologação não valem nada. A VPS começa com o banco **vazio**. |
| **Pasta `storage/logs`** | **Não** | Guarde só o registro da homologação ([Etapa 3](#guardar-o-registro-da-homologação)). |
| **Pasta `vendor`** | **Não** | É recriada pelo `composer install` (passo 4). |

> Por que tokens novos? Os tokens do computador ficaram em arquivos e
> testes locais. Na VPS, que fica na internet, use tokens que só os sistemas
> de produção conhecem.

### 4.3 Passo a passo na VPS

1. Siga o [Manual de Implantação](manual-implantacao.md) **inteiro**, do
   passo 1 ao 12, **com HTTPS** (passo 10) e na **porta 80** (sem a troca
   para 8080).
2. Ainda em **homologação** (`tpAmb = 2`), rode o verificador com rede:

```bash
cd /var/www/nfe-integracao && sudo -u www-data php bin/nfse_verificar.php --rede
```

   A VPS sai para a internet por **outro IP e outro firewall**: é preciso
   confirmar que ela alcança o governo.

3. Faça **um ciclo curto de homologação** na VPS:

```bash
sudo -u www-data php bin/nfse_homologar.php --padrao=NACIONAL
```

4. Configure o **backup automático** (manual, Parte D).
5. Atualize os **sistemas consumidores** (ERP etc.) com o **endereço da VPS**
   (`https://fiscal.suaempresa.com.br/v1/nfse/...`) e os **tokens novos**.
6. Só então siga a [Parte C — Virar para produção](manual-implantacao.md#parte-c--virar-para-produção).

### 4.4 Desligar o ambiente local

Depois que a VPS estiver em produção, o ambiente do computador pode ficar
para testes, ou ser apagado:

| Sistema | Como apagar |
|---|---|
| Windows (WSL2) | PowerShell: `wsl --unregister Ubuntu-24.04` (apaga o Ubuntu inteiro) |
| macOS / Multipass | Terminal: `multipass delete --purge nfse` |
| Linux direto | Peça ajuda ao desenvolvedor (remove nginx, PHP e PostgreSQL do computador) |

> ⚠️ Antes de apagar, confira que o **certificado original** está guardado em
> local seguro e que o registro da homologação foi copiado.

---

# Problemas comuns do ambiente local

| O que aparece | O que fazer |
|---|---|
| **Windows:** `Please enable the Virtual Machine Platform` ou erro `0x80370102` | A virtualização está desligada. Reinicie o PC, entre na BIOS (geralmente F2, F10 ou Del ao ligar) e ative **"Intel VT-x"** ou **"AMD-V / SVM"**. Peça ajuda ao TI se não souber. |
| **Windows:** `wsl` não é reconhecido | O Windows está desatualizado. Rode o Windows Update e tente de novo. |
| **Windows:** `ps -p 1 -o comm=` não mostra `systemd` | Faça o item [1.2 do Windows](#12-conferir-se-o-ubuntu-está-com-o-systemd-ligado). |
| **Windows:** o worker parou sozinho | Todas as janelas do Ubuntu foram fechadas. Abra o Ubuntu de novo; o worker volta sozinho. |
| **Windows:** `http://localhost:8080` não abre | Confira se o passo 9 foi feito com a porta 8080 e se a janela do Ubuntu está aberta. Rode `sudo systemctl status nginx` dentro do Ubuntu. |
| **Windows:** abre a página do **WAMP** | Você usou `http://localhost` sem `:8080`. |
| **macOS:** `multipass launch` falha ou demora muito | Feche e abra o Terminal e tente de novo. Em Macs antigos (Intel), confira se há 4 GB de memória livre. |
| **macOS:** o navegador não abre `http://IP:8080` | Confira o IP com `multipass info nfse` (ele pode mudar depois de reiniciar o Mac). |
| **macOS:** "Permission denied" no `multipass transfer` | Confira o caminho do arquivo no Mac e use `sudo mv` dentro do Ubuntu, como no manual. |
| **Todos:** verificador mostra `inacessivel` no teste de rede | Rede da empresa bloqueando o governo: peça ao TI a liberação da saída HTTPS (porta 443). |
| **Todos:** o computador ficou lento | Desligue o ambiente quando não estiver usando (Windows: feche as janelas do Ubuntu; macOS/Multipass: `multipass stop nfse`). |

Para os demais problemas, veja a [Parte E do Manual de Implantação](manual-implantacao.md#parte-e--problemas-comuns).
