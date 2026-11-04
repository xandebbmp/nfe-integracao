# Monitor Fiscal (NFS-e e NF-e) — página do painel

Página pronta para acompanhar, em tempo real, cada NFS-e **e NF-e** recebida,
enviada, autorizada, rejeitada, denegada, consultada ou cancelada.

- Endereço: `https://<servidor>/monitor/`
- Arquivos: `public/monitor/index.html`, `monitor.css`, `monitor.js`
  (HTML/CSS/JS puros: sem build, sem biblioteca externa).
- Acesso: token do painel (`monitor_token` em `config/nfse.local.php`).
  Uso previsto: **rede interna/VPN** (decisão C3).

---

## O que a tela mostra

| Área | Conteúdo |
|---|---|
| Topo | Ambiente (Homologação/Produção), situação da conexão em tempo real ("Ao vivo", "Reconectando…"), botão Sair. |
| Contadores | Total por status. Clique num contador para filtrar (clique de novo para limpar). |
| Filtros | Status, padrão (NF-e, NFS-e Nacional, NFS-e Salvador), emitente (multiempresa) e busca por referência, nº da NFS-e ou chave. |
| Tabela | Uma linha por nota, ordenada pela última atualização. Notas novas ou alteradas piscam ao chegar. |
| ⚠ na linha | Nota **rejeitada**, **denegada** (NF-e) ou com **erro**: passe o mouse para ver o motivo e a sugestão de correção. |
| Modal (clique na linha) | Motivo da rejeição com **"Como corrigir"** para cada mensagem, dados da nota, eventos (inclusive cancelamento registrado manualmente), linha do tempo completa e botões **Baixar XML** / **Abrir PDF**. |

### De onde vem a sugestão de correção
1. **Erro de entrada** (dados enviados pelo sistema de origem): a própria API
   gera a sugestão (`src/Nfse/Servico/ValidadorPayload.php`).
2. **Rejeição do fisco**: usamos o "complemento"/"correção" que o Sefin ou
   Salvador devolvem.
3. Se o fisco não manda sugestão, a página usa um texto padrão por código
   (`SUGESTOES_PADRAO` em `monitor.js`). Para melhorar a orientação de um
   código frequente (ex.: `E0312`), basta incluir o código nesse objeto.

---

## Como funciona por dentro

```
abre a página ─► token ─► GET capacidades.php (ambiente) e emitentes.php (filtro)
                       ─► EventSource monitor.php?desde=agora   (tempo real)
                       ─► GET documentos.php?limite=500          (estado atual)

evento "nfse" chega ─► GET documento.php?id=...&xml=0 ─► redesenha a linha
                                                     └► atualiza o modal, se aberto
```

- O stream é aberto **antes** da carga da lista: nenhuma mudança escapa.
- Se a conexão cair, o navegador reconecta sozinho enviando `Last-Event-ID`;
  após uma queda, a lista é recarregada para garantir consistência.
- O servidor fecha o stream a cada ~5 min (libera o processo PHP); a
  reconexão é automática e invisível.
- Todo texto da API é inserido com `textContent` (função `el()`), nunca com
  `innerHTML`: mensagens vindas do fisco não conseguem injetar HTML.
- O token fica em `sessionStorage` (ou `localStorage` se marcar "Lembrar").

---

## Implantação

1. Defina `monitor_token` em `config/nfse.local.php`.
2. A pasta `public/` já é a raiz do site: a página fica em `/monitor/`.
3. nginx: desligue o buffer do SSE (ver `docs/nfse-arquitetura.md`, seção 10).
4. Se o painel for aberto por muita gente ao mesmo tempo, reserve um pool
   PHP-FPM para `monitor.php` (cada aba aberta usa um processo).

### Testar localmente

```bash
PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8099 -t public
```

Abra `http://127.0.0.1:8099/monitor/`. `PHP_CLI_SERVER_WORKERS` é
necessário porque o servidor embutido do PHP atende uma requisição por vez,
e o stream SSE ficaria ocupando o único processo.

---

## Como alterar

| Quero… | Onde |
|---|---|
| Mudar cores/tema | bloco `:root` em `monitor.css` (o modo escuro segue o sistema) |
| Nome/cor de um status | objeto `STATUS` em `monitor.js` |
| Nova coluna na tabela | `<thead>` em `index.html` + função `linhaDocumento()` |
| Novo campo no modal | lista `campos` em `conteudoModal()` |
| Sugestão para um código de rejeição | objeto `SUGESTOES_PADRAO` |
| Quantidade de notas carregadas | constante `LIMITE_NOTAS` (máx. 500 na API) |
