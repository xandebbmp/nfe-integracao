/* ==========================================================================
   Painel Administrativo - empresas clientes da API fiscal

   Tudo o que esta tela faz passa pelas rotas /v1/admin/*.php com o
   cabecalho X-ADMIN-TOKEN:
     clientes.php     lista (GET) e cadastro/alteracao (POST)
     cliente.php      detalhe: uso 12 meses, status do mes, historico
     certificado.php  envio do .pfx (multipart)
     token.php        gera token novo (aparece uma unica vez)
     uso.php          relatorio de cobranca do mes (JSON ou CSV)

   Sem build, sem biblioteca: HTML/CSS/JS puros (igual ao monitor).
   ========================================================================== */

const API = new URL('../v1/admin/', window.location.href).href;
const API_NFSE = new URL('../v1/nfse/', window.location.href).href;
const CHAVE_TOKEN = 'admin_token';

const SITUACOES = {
  LIBERADA: { nome: 'Liberada', cor: 'var(--sit-liberada)' },
  BLOQUEADA: { nome: 'Bloqueada', cor: 'var(--sit-bloqueada)' },
  VENCIDA: { nome: 'Vencida', cor: 'var(--sit-vencida)' },
  LIMITE_ATINGIDO: { nome: 'Limite atingido', cor: 'var(--sit-limite)' },
};

const TIPOS = [
  { padrao: 'NFE', nome: 'NF-e (produtos)', campo: 'nfe' },
  { padrao: 'NACIONAL', nome: 'NFS-e Nacional', campo: 'nfse_nacional' },
  { padrao: 'SALVADOR', nome: 'NFS-e Salvador', campo: 'nfse_salvador' },
];

const ACOES_HISTORICO = {
  CADASTRO: 'Cadastro', ALTERACAO: 'Alteração', CERTIFICADO: 'Certificado enviado', TOKEN: 'Token gerado',
  NUMERACAO: 'Numeração ajustada',
  EMISSOR_USUARIO: 'Acesso ao emissor', EMISSOR_SERVICO: 'Serviço do emissor',
};

const estado = { token: '', clientes: [], ambiente: '', mes: '', filtroSituacao: '' };

/** Senha do emissor recem-gerada, mostrada uma vez ao redesenhar a empresa. */
let senhaEmissorPendente = null;

// ==========================================================================
// Utilitarios
// ==========================================================================

function el(tag, atributos = {}, ...filhos) {
  const no = document.createElement(tag);
  for (const [nome, valor] of Object.entries(atributos)) {
    if (valor === null || valor === undefined || valor === false) continue;
    if (nome === 'style') no.setAttribute('style', valor);
    else if (nome.startsWith('on')) no.addEventListener(nome.slice(2), valor);
    else no.setAttribute(nome, valor === true ? '' : valor);
  }
  for (const filho of filhos.flat()) {
    if (filho === null || filho === undefined || filho === false) continue;
    no.append(filho instanceof Node ? filho : document.createTextNode(String(filho)));
  }
  return no;
}

const $ = (id) => document.getElementById(id);

function cnpj(valor) {
  const v = String(valor || '');
  return /^\d{14}$/.test(v) ? v.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5') : v;
}

function dataCurta(texto) {
  if (!texto) return '—';
  const [a, m, d] = String(texto).slice(0, 10).split('-');
  return d ? `${d}/${m}/${a}` : texto;
}

function dataHora(texto) {
  if (!texto) return '—';
  const d = new Date(String(texto).replace(' ', 'T').replace(/\+00$/, 'Z'));
  return isNaN(d) ? texto : d.toLocaleString('pt-BR');
}

function seloSituacao(situacao) {
  const s = SITUACOES[situacao] || { nome: situacao, cor: 'var(--suave)' };
  return el('span', { class: 'situacao', style: `--cor:${s.cor}` }, s.nome);
}

// ==========================================================================
// Comunicacao com a API
// ==========================================================================

async function api(caminho, opcoes = {}) {
  const cabecalhos = { 'X-ADMIN-TOKEN': estado.token };
  let corpo = opcoes.corpo;
  if (corpo && !(corpo instanceof FormData)) {
    cabecalhos['Content-Type'] = 'application/json';
    corpo = JSON.stringify(corpo);
  }
  const resposta = await fetch(API + caminho, { method: opcoes.metodo || 'GET', headers: cabecalhos, body: corpo });
  if (resposta.status === 401) {
    sair('Token de administrador inválido.');
    throw new Error('nao autorizado');
  }
  if (opcoes.blob) {
    if (!resposta.ok) throw new Error(`HTTP ${resposta.status}`);
    return resposta.blob();
  }
  const json = await resposta.json().catch(() => ({}));
  if (!resposta.ok) {
    const erro = new Error(json.erro?.mensagem || `HTTP ${resposta.status}`);
    erro.detalhes = json.erro?.erros || [];
    throw erro;
  }
  return json;
}

// ==========================================================================
// Acesso
// ==========================================================================

function lerTokenSalvo() {
  try { return sessionStorage.getItem(CHAVE_TOKEN) || localStorage.getItem(CHAVE_TOKEN) || ''; } catch { return ''; }
}

function salvarToken(token, lembrar) {
  try {
    sessionStorage.setItem(CHAVE_TOKEN, token);
    if (lembrar) localStorage.setItem(CHAVE_TOKEN, token);
  } catch { /* navegador sem armazenamento: so vale nesta aba */ }
}

function sair(mensagem) {
  try { sessionStorage.removeItem(CHAVE_TOKEN); localStorage.removeItem(CHAVE_TOKEN); } catch { /* ignora */ }
  estado.token = '';
  $('tela-painel').hidden = true;
  $('tela-acesso').hidden = false;
  $('erro-acesso').hidden = !mensagem;
  $('erro-acesso').textContent = mensagem || '';
}

async function entrar(token) {
  estado.token = token;
  await carregar();
  $('tela-acesso').hidden = true;
  $('tela-painel').hidden = false;
}

// ==========================================================================
// Lista de empresas
// ==========================================================================

async function carregar() {
  const dados = await api('clientes.php');
  estado.clientes = dados.clientes;
  estado.ambiente = dados.ambiente;
  estado.mes = dados.mes;
  const producao = dados.ambiente === 'PRODUCAO';
  $('selo-ambiente').textContent = producao ? 'PRODUÇÃO' : 'HOMOLOGAÇÃO';
  $('selo-ambiente').className = `selo selo--${producao ? 'producao' : 'homologacao'}`;
  const [ano, mes] = dados.mes.split('-');
  $('mes-atual').textContent = `Uso de ${mes}/${ano}`;
  desenhar();
}

function filtrados() {
  const busca = $('filtro-busca').value.trim().toLowerCase().replace(/[^\w]/g, '');
  return estado.clientes.filter((c) => {
    if (estado.filtroSituacao && c.situacao !== estado.filtroSituacao) return false;
    if (!busca) return true;
    const texto = `${c.razao_social} ${c.nome_fantasia || ''} ${c.cnpj}`.toLowerCase().replace(/[^\w]/g, '');
    return texto.includes(busca);
  });
}

function desenhar() {
  const todos = estado.clientes;
  const conta = (s) => todos.filter((c) => c.situacao === s).length;
  const totalNotas = todos.reduce((soma, c) => soma + c.autorizadas_mes.total, 0);
  const contadores = [
    ['', 'Empresas', todos.length, 'var(--destaque)'],
    ['LIBERADA', 'Liberadas', conta('LIBERADA'), SITUACOES.LIBERADA.cor],
    ['BLOQUEADA', 'Bloqueadas', conta('BLOQUEADA'), SITUACOES.BLOQUEADA.cor],
    ['VENCIDA', 'Vencidas', conta('VENCIDA'), SITUACOES.VENCIDA.cor],
    ['LIMITE_ATINGIDO', 'Limite atingido', conta('LIMITE_ATINGIDO'), SITUACOES.LIMITE_ATINGIDO.cor],
  ];
  $('contadores').replaceChildren(
    ...contadores.map(([situacao, nome, valor, cor]) => el('button', {
      class: `contador cartao${estado.filtroSituacao === situacao && situacao ? ' contador--ativo' : ''}`,
      style: `--cor:${cor}`,
      onclick: () => { estado.filtroSituacao = estado.filtroSituacao === situacao ? '' : situacao; $('filtro-situacao').value = estado.filtroSituacao; desenhar(); },
    }, el('span', { class: 'contador__valor' }, valor), el('span', { class: 'contador__nome' }, nome))),
    el('div', { class: 'contador cartao', style: '--cor:var(--st-autorizado)' },
      el('span', { class: 'contador__valor' }, totalNotas), el('span', { class: 'contador__nome' }, 'Notas autorizadas no mês')),
  );

  const lista = filtrados();
  $('vazio').hidden = lista.length > 0;
  $('linhas').replaceChildren(...lista.map(linhaCliente));
}

function linhaCliente(c) {
  const limite = c.limite_mensal;
  const pct = limite ? Math.min(100, Math.round((c.consumo_mes / limite) * 100)) : 0;
  const corBarra = pct >= 100 ? 'var(--st-rejeitado)' : pct >= 80 ? 'var(--st-erro)' : 'var(--st-autorizado)';

  let certificado;
  if (!c.tem_certificado) certificado = el('span', { class: 'ruim' }, 'Sem certificado');
  else if (c.certificado_dias_restantes < 0) certificado = el('span', { class: 'ruim' }, 'Vencido');
  else if (c.certificado_dias_restantes < 30) certificado = el('span', { class: 'aviso' }, `${dataCurta(c.certificado_validade)} (${c.certificado_dias_restantes} dias)`);
  else certificado = dataCurta(c.certificado_validade);

  const vencido = c.liberado_ate && c.situacao === 'VENCIDA';
  return el('tr', { onclick: () => abrirCliente(c.cnpj) },
    el('td', {}, el('div', { class: 'empresa__nome' }, c.nome_fantasia || c.razao_social), el('div', { class: 'empresa__cnpj mono' }, cnpj(c.cnpj))),
    el('td', {}, c.plano || '—'),
    el('td', { title: c.situacao_mensagem }, seloSituacao(c.situacao)),
    ...TIPOS.map((t) => el('td', { class: 'mono' }, c.padroes_permitidos.includes(t.padrao) ? c.autorizadas_mes[t.campo] : el('span', { class: 'suave', title: 'Tipo não contratado' }, '—'))),
    el('td', {}, limite
      ? el('div', {}, `${c.consumo_mes} / ${limite}`, el('div', { class: 'barra' }, el('div', { class: 'barra__cheio', style: `width:${pct}%;--cor:${corBarra}` })))
      : el('span', { class: 'suave' }, `${c.consumo_mes} (sem limite)`)),
    el('td', {}, c.liberado_ate ? el('span', { class: vencido ? 'ruim' : null }, dataCurta(c.liberado_ate)) : el('span', { class: 'suave' }, 'sem vencimento')),
    el('td', {}, certificado),
    el('td', { class: 'mono' }, c.tem_token ? `••••${c.token_final}` : el('span', { class: 'ruim' }, 'Sem token')),
  );
}

// ==========================================================================
// Modal da empresa
// ==========================================================================

function abrirModal(titulo, subtitulo, conteudo) {
  $('modal-titulo').textContent = titulo;
  $('modal-subtitulo').textContent = subtitulo || '';
  $('modal-corpo').replaceChildren(...conteudo);
  if (!$('modal').open) $('modal').showModal();
}

async function abrirCliente(numeroCnpj) {
  try {
    const { cliente } = await api(`cliente.php?cnpj=${encodeURIComponent(numeroCnpj)}`);
    desenharCliente(cliente);
  } catch (e) {
    alert(`Não foi possível abrir: ${e.message}`);
  }
}

function novaEmpresa() {
  desenharCliente(null);
}

/** Campo de formulario com rotulo. */
function campo(rotulo, nome, valor, extra = {}) {
  return el('label', { class: extra.largo ? 'largo' : null }, rotulo,
    el('input', { name: nome, value: valor ?? '', type: extra.tipo || 'text', placeholder: extra.placeholder, maxlength: extra.max, min: extra.min, required: extra.obrigatorio }));
}

function selecao(rotulo, nome, valor, opcoes) {
  return el('label', {}, rotulo,
    el('select', { name: nome }, ...opcoes.map(([v, texto]) => el('option', { value: v, selected: String(valor ?? '') === String(v) }, texto))));
}

function desenharCliente(c) {
  const novo = c === null;
  c = c || { padroes_permitidos: TIPOS.map((t) => t.padrao), emissao_liberada: true, codigo_municipio: '2927408', opcao_simples_nacional: 1, regime_especial_tributacao: 0 };
  const partes = [];

  if (!novo) {
    partes.push(el('div', { class: 'acoes' }, seloSituacao(c.situacao), el('span', {}, c.situacao_mensagem)));
  }

  // ---- 1. Controle de emissao (cobranca) ----
  const formControle = el('form', { class: 'secao' },
    el('h3', {}, 'Controle de emissão'),
    el('div', { class: 'campos' },
      el('label', { class: 'linha-check' }, el('input', { type: 'checkbox', name: 'emissao_liberada', checked: c.emissao_liberada }), 'Emissão liberada'),
      campo('Motivo do bloqueio (aparece para o cliente)', 'motivo_bloqueio', c.motivo_bloqueio, { max: 300 }),
      campo('Liberado até (vencimento)', 'liberado_ate', c.liberado_ate, { tipo: 'date' }),
      campo('Limite de notas por mês (vazio = sem limite)', 'limite_mensal', c.limite_mensal, { tipo: 'number', min: 1 }),
      campo('Plano', 'plano', c.plano, { max: 60, placeholder: 'Ex.: Básico, Pro' }),
      el('div', { class: 'largo' },
        el('span', { class: 'suave' }, 'Tipos de nota contratados: '),
        ...TIPOS.map((t) => el('label', { class: 'linha-check', style: 'display:inline-flex;margin-right:16px' },
          el('input', { type: 'checkbox', name: 'padroes_permitidos', value: t.padrao, checked: (c.padroes_permitidos || []).includes(t.padrao) }), t.nome))),
    ),
  );

  // ---- 2. Dados cadastrais ----
  const formDados = el('form', { class: 'secao' },
    el('h3', {}, 'Dados da empresa'),
    el('div', { class: 'campos' },
      campo('CNPJ', 'cnpj', novo ? '' : cnpj(c.cnpj), { obrigatorio: true, placeholder: '00.000.000/0000-00' }),
      campo('Razão social', 'razao_social', c.razao_social, { obrigatorio: true, max: 150 }),
      campo('Nome fantasia', 'nome_fantasia', c.nome_fantasia, { max: 150 }),
      campo('E-mail', 'email', c.email, { tipo: 'email', max: 120 }),
      campo('Telefone', 'telefone', c.telefone, { max: 20 }),
      campo('UF (NF-e)', 'uf', c.uf, { max: 2, placeholder: 'BA' }),
      campo('Inscrição estadual (NF-e)', 'ie', c.ie, { max: 20 }),
      campo('Inscrição municipal (NFS-e)', 'inscricao_municipal', c.inscricao_municipal, { max: 20 }),
      campo('Código IBGE do município', 'codigo_municipio', c.codigo_municipio, { max: 7 }),
      selecao('Simples Nacional', 'opcao_simples_nacional', c.opcao_simples_nacional, [[1, 'Não optante'], [2, 'MEI'], [3, 'ME/EPP']]),
      selecao('Regime de apuração (ME/EPP)', 'regime_apuracao_simples', c.regime_apuracao_simples, [['', '—'], [1, '1'], [2, '2'], [3, '3']]),
      selecao('Regime especial (NFS-e Nacional)', 'regime_especial_tributacao', c.regime_especial_tributacao, [0, 1, 2, 3, 4, 5, 6].map((v) => [v, v === 0 ? '0 - Nenhum' : String(v)])),
      selecao('Regime especial (Salvador)', 'regime_especial_abrasf', c.regime_especial_abrasf, [['', '—'], 1, 2, 3, 4, 5, 6].map((v) => (Array.isArray(v) ? v : [v, String(v)]))),
      el('label', { class: 'linha-check' }, el('input', { type: 'checkbox', name: 'incentivador_cultural', checked: c.incentivador_cultural }), 'Incentivador cultural'),
      campo('Webhook (URL que recebe os avisos)', 'webhook_url', c.webhook_url, { largo: true, max: 500, placeholder: 'https://seu-sistema/webhook' }),
      campo(c.tem_webhook_segredo ? 'Segredo do webhook (preenchido — digite para trocar)' : 'Segredo do webhook', 'webhook_segredo', '', { max: 200 }),
      campo('Observação interna', 'observacao', c.observacao, { largo: true, max: 2000 }),
    ),
  );
  if (!novo) formDados.querySelector('[name=cnpj]').readOnly = true;

  const mensagemSalvar = el('span', {});
  const botaoSalvar = el('button', { class: 'botao botao--forte', type: 'button' }, novo ? 'Cadastrar empresa' : 'Salvar alterações');
  botaoSalvar.addEventListener('click', async () => {
    const corpo = lerFormulario(formDados, formControle);
    if (!corpo) return;
    botaoSalvar.disabled = true;
    mensagemSalvar.className = 'suave';
    mensagemSalvar.textContent = 'Salvando...';
    try {
      const { cliente } = await api('clientes.php', { metodo: 'POST', corpo });
      await carregar();
      desenharCliente(cliente);
    } catch (e) {
      mensagemSalvar.className = 'erro';
      mensagemSalvar.textContent = [e.message, ...(e.detalhes || [])].filter((t, i, a) => a.indexOf(t) === i).join(' ');
      botaoSalvar.disabled = false;
    }
  });
  partes.push(formControle, formDados, el('div', { class: 'acoes' }, botaoSalvar, mensagemSalvar));

  if (!novo) {
    partes.push(secaoCertificado(c), secaoEmissor(c), secaoToken(c), secaoNumeracao(c), secaoUso(c), secaoHistorico(c));
  } else {
    partes.push(el('p', { class: 'suave' }, 'Depois de cadastrar, envie o certificado e gere o token de acesso.'));
  }

  abrirModal(novo ? 'Nova empresa' : (c.nome_fantasia || c.razao_social), novo ? '' : cnpj(c.cnpj), partes);
}

function lerFormulario(formDados, formControle) {
  if (!formDados.reportValidity()) return null;
  const valor = (form, nome) => form.querySelector(`[name=${nome}]`);
  const corpo = {};
  for (const input of formDados.querySelectorAll('input, select')) {
    if (input.type === 'checkbox') corpo[input.name] = input.checked;
    else if (input.name === 'webhook_segredo' && input.value === '') continue; // vazio = manter o atual
    else corpo[input.name] = input.value;
  }
  corpo.emissao_liberada = valor(formControle, 'emissao_liberada').checked;
  corpo.motivo_bloqueio = valor(formControle, 'motivo_bloqueio').value;
  corpo.liberado_ate = valor(formControle, 'liberado_ate').value;
  corpo.limite_mensal = valor(formControle, 'limite_mensal').value;
  corpo.plano = valor(formControle, 'plano').value;
  corpo.padroes_permitidos = [...formControle.querySelectorAll('[name=padroes_permitidos]:checked')].map((i) => i.value);
  if (!corpo.emissao_liberada && !corpo.motivo_bloqueio.trim()
      && !confirm('Bloquear sem informar o motivo? O cliente verá só "Emissão bloqueada".')) {
    return null;
  }
  return corpo;
}

// ---- Certificado ----
function secaoCertificado(c) {
  const arquivo = el('input', { type: 'file', accept: '.pfx,.p12' });
  const senha = el('input', { type: 'password', autocomplete: 'new-password' });
  const mensagem = el('span', {});
  const botao = el('button', { class: 'botao', type: 'button' }, 'Enviar certificado');
  botao.addEventListener('click', async () => {
    if (!arquivo.files[0]) { mensagem.className = 'erro'; mensagem.textContent = 'Escolha o arquivo .pfx.'; return; }
    const dados = new FormData();
    dados.append('cnpj', c.cnpj);
    dados.append('senha', senha.value);
    dados.append('arquivo', arquivo.files[0]);
    botao.disabled = true;
    mensagem.className = 'suave';
    mensagem.textContent = 'Conferindo...';
    try {
      const r = await api('certificado.php', { metodo: 'POST', corpo: dados });
      await carregar();
      desenharCliente(r.cliente);
    } catch (e) {
      mensagem.className = 'erro';
      mensagem.textContent = e.message;
      botao.disabled = false;
    }
  });

  let situacao;
  if (!c.tem_certificado) situacao = el('p', { class: 'ruim' }, 'Nenhum certificado enviado: a empresa não consegue emitir.');
  else situacao = el('p', { class: c.certificado_dias_restantes < 30 ? 'aviso' : 'ok-texto' },
    `${c.certificado_titular} — válido até ${dataCurta(c.certificado_validade)} (${c.certificado_dias_restantes} dias). Enviado em ${dataHora(c.certificado_atualizado_em)}.`);

  return el('section', { class: 'secao' },
    el('h3', {}, 'Certificado digital (A1)'),
    situacao,
    el('div', { class: 'campos' }, el('label', {}, 'Arquivo .pfx', arquivo), el('label', {}, 'Senha do certificado', senha)),
    el('div', { class: 'acoes' }, botao, mensagem),
    el('p', { class: 'suave' }, 'A API confere a senha, se é o e-CNPJ desta empresa e a validade, e guarda criptografado. O arquivo não fica em disco.'),
  );
}

// ---- Token de acesso ----
function secaoToken(c) {
  const area = el('div', {});
  const botao = el('button', { class: 'botao', type: 'button' }, c.tem_token ? 'Gerar token novo (o atual para de funcionar)' : 'Gerar token');
  botao.addEventListener('click', async () => {
    if (c.tem_token && !confirm('Gerar um token novo? O token atual para de funcionar NA HORA e o sistema do cliente precisa ser atualizado.')) return;
    try {
      const r = await api('token.php', { metodo: 'POST', corpo: { cnpj: c.cnpj } });
      const copiar = el('button', { class: 'botao', type: 'button', onclick: async () => {
        try { await navigator.clipboard.writeText(r.token); copiar.textContent = 'Copiado!'; } catch { copiar.textContent = 'Selecione e copie'; }
      } }, 'Copiar');
      area.replaceChildren(el('div', { class: 'token-box' },
        el('strong', {}, 'Token novo — copie agora, ele não será mostrado de novo:'), el('br'),
        el('code', { class: 'mono' }, r.token), el('div', { class: 'acoes' }, copiar)));
      await carregar();
    } catch (e) {
      area.replaceChildren(el('p', { class: 'erro' }, e.message));
    }
  });

  const exemplo = `curl -X POST ${API_NFSE}emitir.php \\
  -H "X-NFE-API-TOKEN: <token da empresa>" \\
  -H "Content-Type: application/json" \\
  -d '{"padrao":"NFE","documentos":[{ "referencia":"PEDIDO-1", ... }]}'`;

  return el('section', { class: 'secao' },
    el('h3', {}, 'Acesso à API'),
    el('p', {}, c.tem_token
      ? `Token ativo terminando em ••••${c.token_final} (gerado em ${dataHora(c.token_gerado_em)}).`
      : el('span', { class: 'ruim' }, 'Sem token: o sistema do cliente ainda não consegue chamar a API.')),
    el('div', { class: 'acoes' }, botao),
    area,
    el('p', { class: 'suave' }, 'O sistema do cliente envia o token em todas as chamadas. Ele só consegue emitir pelo CNPJ desta empresa:'),
    el('pre', { class: 'exemplo mono' }, exemplo),
  );
}

// ---- Emissor simples (acessos e servicos) ----
function secaoEmissor(c) {
  const emissor = c.emissor || { disponivel: false, usuarios: [], servicos: [] };
  if (!emissor.disponivel) {
    return el('section', { class: 'secao' }, el('h3', {}, 'Emissor simples'),
      el('p', { class: 'suave' }, 'Rode "bash deploy/aplicar_banco.sh" no servidor para ativar o emissor simples.'));
  }
  const enderecoEmissor = new URL('../emissor/', window.location.href).href;
  const recarregar = () => abrirCliente(c.cnpj);

  // ---- Servicos ----
  const tabelaServicos = emissor.servicos.length
    ? el('table', { class: 'mini' },
      el('thead', {}, el('tr', {}, el('th', {}, 'Serviço'), el('th', {}, 'Cód. tributação'), el('th', { class: 'num' }, 'Alíquota'),
        el('th', {}, 'ISS retido'), el('th', {}, ''))),
      el('tbody', {}, ...emissor.servicos.map((s) => el('tr', {},
        el('td', {}, s.nome, s.padrao ? el('span', { class: 'suave' }, ' (padrão)') : null),
        el('td', { class: 'mono' }, s.codigo_tributacao_nacional),
        el('td', { class: 'num' }, s.aliquota_iss !== null ? `${String(s.aliquota_iss).replace('.', ',')}%` : '—'),
        el('td', {}, s.iss_retido ? 'Sim' : 'Não'),
        el('td', {},
          el('button', { class: 'botao', type: 'button', onclick: () => formServico(s) }, 'Editar'), ' ',
          el('button', { class: 'botao botao--perigo', type: 'button', onclick: async () => {
            if (!confirm(`Remover o serviço "${s.nome}"?`)) return;
            try { await api('emissor_servico.php', { metodo: 'POST', corpo: { acao: 'REMOVER', cnpj: c.cnpj, id: s.id } }); recarregar(); } catch (e) { alert(e.message); }
          } }, 'Remover'))))))
    : el('p', { class: 'ruim' }, 'Nenhum serviço: sem isso a empresa não consegue emitir pelo emissor.');

  const areaServico = el('div', {});
  function formServico(s = null) {
    const campos = el('div', { class: 'campos' },
      campo('Nome curto (aparece para o cliente)', 'nome', s?.nome, { max: 80, placeholder: 'Ex.: Consultoria' }),
      campo('Código de tributação nacional (6 dígitos)', 'codigo_tributacao_nacional', s?.codigo_tributacao_nacional, { placeholder: 'Ex.: 170101' }),
      campo('Alíquota do ISS em % (vazio = não informar)', 'aliquota_iss', s?.aliquota_iss ?? '', { placeholder: 'Ex.: 5' }),
      campo('Código NBS (opcional, 9 dígitos)', 'codigo_nbs', s?.codigo_nbs),
      campo('Código de tributação municipal (opcional)', 'codigo_tributacao_municipal', s?.codigo_tributacao_municipal),
      el('label', { class: 'linha-check' }, el('input', { type: 'checkbox', name: 'iss_retido', checked: s?.iss_retido }), 'ISS retido pelo tomador'),
      el('label', { class: 'linha-check' }, el('input', { type: 'checkbox', name: 'padrao', checked: s ? s.padrao : emissor.servicos.length === 0 }), 'Serviço padrão'),
      campo('Descrição sugerida (o cliente pode mudar)', 'descricao_padrao', s?.descricao_padrao, { largo: true, max: 2000 }));
    const msg = el('span', {});
    const salvar = el('button', { class: 'botao botao--forte', type: 'button' }, s ? 'Salvar serviço' : 'Adicionar serviço');
    salvar.addEventListener('click', async () => {
      const corpo = { acao: 'SALVAR', cnpj: c.cnpj, id: s?.id ?? null };
      campos.querySelectorAll('input').forEach((i) => { corpo[i.name] = i.type === 'checkbox' ? i.checked : i.value; });
      salvar.disabled = true;
      try { await api('emissor_servico.php', { metodo: 'POST', corpo }); recarregar(); } catch (e) { msg.className = 'erro'; msg.textContent = e.message; salvar.disabled = false; }
    });
    areaServico.replaceChildren(campos, el('div', { class: 'acoes' }, salvar, msg),
      el('p', { class: 'suave' }, 'O código de tributação e a alíquota vêm do contador. Ficam fixos aqui: o cliente só preenche valor, tomador e descrição.'));
  }

  // ---- Acessos ----
  const tabelaUsuarios = emissor.usuarios.length
    ? el('table', { class: 'mini' },
      el('thead', {}, el('tr', {}, el('th', {}, 'Nome'), el('th', {}, 'E-mail'), el('th', {}, 'Último acesso'), el('th', {}, ''))),
      el('tbody', {}, ...emissor.usuarios.map((u) => el('tr', {},
        el('td', {}, u.nome, u.ativo ? null : el('span', { class: 'ruim' }, ' (desativado)')),
        el('td', {}, u.email),
        el('td', {}, dataHora(u.ultimo_acesso)),
        el('td', {},
          el('button', { class: 'botao', type: 'button', onclick: () => acaoUsuario(u, 'NOVA_SENHA') }, 'Nova senha'), ' ',
          el('button', { class: `botao${u.ativo ? ' botao--perigo' : ''}`, type: 'button', onclick: () => acaoUsuario(u, u.ativo ? 'DESATIVAR' : 'ATIVAR') },
            u.ativo ? 'Desativar' : 'Reativar'))))))
    : el('p', { class: 'suave' }, 'Nenhum acesso ainda.');

  // Senha recem-gerada: aparece uma unica vez, logo apos criar o acesso ou gerar senha nova.
  const areaSenha = el('div', {});
  if (senhaEmissorPendente && senhaEmissorPendente.cnpj === c.cnpj) {
    const { email: emailSenha, senha } = senhaEmissorPendente;
    senhaEmissorPendente = null;
    areaSenha.replaceChildren(el('div', { class: 'token-box' },
      el('strong', {}, 'Entregue ao cliente agora (a senha não aparece de novo):'), el('br'),
      `Endereço: ${enderecoEmissor}`, el('br'), `E-mail: ${emailSenha}`, el('br'), 'Senha: ', el('code', { class: 'mono' }, senha)));
  }
  async function acaoUsuario(u, acao) {
    if (acao === 'NOVA_SENHA' && !confirm(`Gerar senha nova para ${u.email}? A senha atual para de funcionar.`)) return;
    try {
      const r = await api('emissor_usuario.php', { metodo: 'POST', corpo: { acao, cnpj: c.cnpj, id: u.id } });
      if (r.senha) senhaEmissorPendente = { cnpj: c.cnpj, email: u.email, senha: r.senha };
      recarregar();
    } catch (e) { alert(e.message); }
  }

  const nome = el('input', { placeholder: 'Nome da pessoa', maxlength: 120 });
  const email = el('input', { type: 'email', placeholder: 'email@empresa.com.br', maxlength: 120 });
  const criar = el('button', { class: 'botao', type: 'button' }, 'Criar acesso');
  const msgCriar = el('span', {});
  criar.addEventListener('click', async () => {
    try {
      const r = await api('emissor_usuario.php', { metodo: 'POST', corpo: { acao: 'CRIAR', cnpj: c.cnpj, nome: nome.value, email: email.value } });
      senhaEmissorPendente = { cnpj: c.cnpj, email: email.value.trim().toLowerCase(), senha: r.senha };
      recarregar();
    } catch (e) { msgCriar.className = 'erro'; msgCriar.textContent = e.message; }
  });

  return el('section', { class: 'secao' },
    el('h3', {}, 'Emissor simples (NFS-e Nacional em 3 passos)'),
    el('p', { class: 'suave' }, 'O cliente entra em ', el('a', { href: enderecoEmissor, target: '_blank' }, enderecoEmissor),
      ' e emite informando só valor, tomador e descrição. O resto vem daqui.'),
    el('h3', {}, 'Serviços da empresa'),
    tabelaServicos,
    el('div', { class: 'acoes' }, el('button', { class: 'botao', type: 'button', onclick: () => formServico() }, '+ Adicionar serviço')),
    areaServico,
    el('h3', {}, 'Quem pode entrar'),
    tabelaUsuarios,
    areaSenha,
    el('div', { class: 'campos' }, el('label', {}, 'Nome', nome), el('label', {}, 'E-mail', email)),
    el('div', { class: 'acoes' }, criar, msgCriar),
  );
}

// ---- Numeracao inicial ----
function secaoNumeracao(c) {
  const series = c.numeracao || [];
  const nomeTipo = (padrao) => (TIPOS.find((t) => t.padrao === padrao) || {}).nome || padrao;

  const tabela = series.length
    ? el('table', { class: 'mini' },
      el('thead', {}, el('tr', {}, el('th', {}, 'Tipo'), el('th', {}, 'Série'), el('th', { class: 'num' }, 'Último usado'),
        el('th', { class: 'num' }, 'Próxima nota'), el('th', {}, 'Atualizado em'))),
      el('tbody', {}, ...series.map((n) => el('tr', {},
        el('td', {}, nomeTipo(n.padrao)), el('td', { class: 'mono' }, n.serie),
        el('td', { class: 'num mono' }, n.ultimo_numero), el('td', { class: 'num mono' }, el('strong', {}, n.ultimo_numero + 1)),
        el('td', {}, dataHora(n.atualizado_em))))))
    : el('p', { class: 'suave' }, 'Nenhuma série usada ainda neste ambiente: a primeira nota de cada série sai com o número 1.');

  const tipo = el('select', {}, ...TIPOS.map((t) => el('option', { value: t.padrao }, t.nome)));
  const serie = el('input', { type: 'text', maxlength: 5 });
  const ultimo = el('input', { type: 'number', min: 0, placeholder: 'Ex.: 1520' });
  const mensagem = el('span', {});
  const botao = el('button', { class: 'botao', type: 'button' }, 'Aplicar');

  // Serie padrao do tipo escolhido (a mesma que a API usa quando a nota nao informa).
  const atualizarSerie = () => { serie.value = (c.series_padrao || {})[tipo.value] || '1'; };
  tipo.addEventListener('change', atualizarSerie);
  atualizarSerie();

  botao.addEventListener('click', async () => {
    const informado = Number(ultimo.value);
    if (ultimo.value === '' || !Number.isInteger(informado) || informado < 0) {
      mensagem.className = 'erro'; mensagem.textContent = 'Informe o último número já usado (0 se a série nunca foi usada).'; return;
    }
    const serieNormal = tipo.value === 'NFE' ? String(Number(serie.value)) : serie.value.trim();
    const atual = series.find((n) => n.padrao === tipo.value && n.serie === serieNormal);
    const proximo = Math.max(informado, atual ? atual.ultimo_numero : 0) + 1;
    const aviso = atual && atual.ultimo_numero > informado
      ? `\n\nAtenção: a API já usou até o número ${atual.ultimo_numero} nesta série. O número NÃO volta para trás.`
      : '';
    if (!confirm(`${nomeTipo(tipo.value)}, série ${serieNormal || '?'} (${estado.ambiente === 'PRODUCAO' ? 'PRODUÇÃO' : 'HOMOLOGAÇÃO'}):\n`
      + `a próxima nota sairá com o número ${proximo}.${aviso}\n\nConfirmar?`)) return;

    botao.disabled = true;
    mensagem.className = 'suave'; mensagem.textContent = 'Aplicando...';
    try {
      const r = await api('numeracao.php', { metodo: 'POST', corpo: { cnpj: c.cnpj, padrao: tipo.value, serie: serie.value, ultimo_numero: informado } });
      desenharCliente(r.cliente);
      alert(r.mensagem);
    } catch (e) {
      mensagem.className = 'erro'; mensagem.textContent = e.message;
      botao.disabled = false;
    }
  });

  return el('section', { class: 'secao' },
    el('h3', {}, `Numeração (${estado.ambiente === 'PRODUCAO' ? 'produção' : 'homologação'})`),
    tabela,
    el('p', { class: 'suave' }, 'A empresa já emitia por outro sistema? Informe o ÚLTIMO número usado em cada série. '
      + 'A API continua do seguinte e nunca volta para trás (assim não sai nota com número repetido).'),
    el('div', { class: 'campos' },
      el('label', {}, 'Tipo de nota', tipo),
      el('label', {}, 'Série', serie),
      el('label', {}, 'Último número já usado', ultimo)),
    el('div', { class: 'acoes' }, botao, mensagem),
  );
}

// ---- Uso ----
function secaoUso(c) {
  const meses = Object.keys(c.uso_mensal || {}).sort().reverse();
  const tabela = meses.length
    ? el('table', { class: 'mini' },
      el('thead', {}, el('tr', {}, el('th', {}, 'Mês'), ...TIPOS.map((t) => el('th', { class: 'num' }, t.nome)), el('th', { class: 'num' }, 'Total'))),
      el('tbody', {}, ...meses.map((m) => {
        const uso = c.uso_mensal[m];
        const total = Object.values(uso).reduce((a, b) => a + b, 0);
        const [ano, mes] = m.split('-');
        return el('tr', {}, el('td', {}, `${mes}/${ano}`), ...TIPOS.map((t) => el('td', { class: 'num' }, uso[t.padrao] || 0)), el('td', { class: 'num' }, el('strong', {}, total)));
      })))
    : el('p', { class: 'suave' }, 'Nenhuma nota autorizada nos últimos 12 meses.');

  const nomesStatus = { RECEBIDO: 'Recebidas', PROCESSANDO: 'Processando', AUTORIZADO: 'Autorizadas', REJEITADO: 'Rejeitadas', DENEGADO: 'Denegadas', ERRO: 'Com erro', CANCELADO: 'Canceladas', SUBSTITUIDO: 'Substituídas' };
  const status = Object.entries(c.status_mes || {});

  return el('section', { class: 'secao' },
    el('h3', {}, 'Uso (notas autorizadas — base da cobrança)'),
    tabela,
    status.length ? el('p', { class: 'suave' }, 'Documentos recebidos neste mês: ',
      status.map(([s, n]) => `${nomesStatus[s] || s}: ${n}`).join(' · '), '. Só as autorizadas entram na cobrança (canceladas depois continuam contando).') : null,
  );
}

// ---- Historico ----
function secaoHistorico(c) {
  const itens = (c.historico || []).map((h) => {
    let detalhe = '';
    if (h.acao === 'ALTERACAO' || h.acao === 'CADASTRO') {
      detalhe = Object.entries(h.detalhes).map(([k, v]) => (v && typeof v === 'object' && 'para' in v ? `${k}: ${JSON.stringify(v.de)} → ${JSON.stringify(v.para)}` : `${k}: ${v}`)).join('; ');
    } else if (h.acao === 'TOKEN') {
      detalhe = `final ••••${h.detalhes.final}`;
    } else if (h.acao === 'EMISSOR_USUARIO') {
      detalhe = `${h.detalhes.acao}: ${h.detalhes.email}`;
    } else if (h.acao === 'EMISSOR_SERVICO') {
      detalhe = `${h.detalhes.acao}${h.detalhes.nome ? `: ${h.detalhes.nome} (${h.detalhes.codigo})` : ''}`;
    } else if (h.acao === 'NUMERACAO') {
      const d = h.detalhes;
      const nome = (TIPOS.find((t) => t.padrao === d.padrao) || {}).nome || d.padrao;
      detalhe = `${nome}, série ${d.serie}: informado ${d.informado}; antes ${d.antes ?? '—'}, agora ${d.depois} (próxima ${d.depois + 1})`;
    } else if (h.acao === 'CERTIFICADO') {
      detalhe = `${h.detalhes.titular}, válido até ${dataCurta(h.detalhes.validade)}`;
    }
    return el('li', {}, el('time', { class: 'suave' }, dataHora(h.criado_em)), ' · ', el('strong', {}, ACOES_HISTORICO[h.acao] || h.acao),
      h.ip ? el('span', { class: 'suave' }, ` (IP ${h.ip})`) : null, detalhe ? el('div', { class: 'suave' }, detalhe) : null);
  });
  return el('section', { class: 'secao' },
    el('h3', {}, 'Histórico de alterações'),
    itens.length ? el('ul', { class: 'linha-tempo' }, ...itens) : el('p', { class: 'suave' }, 'Sem registros.'));
}

// ==========================================================================
// Relatorio de cobranca
// ==========================================================================

async function abrirRelatorio(mes) {
  mes = mes || estado.mes;
  const campoMes = el('input', { type: 'month', value: mes });
  const area = el('div', {}, el('p', { class: 'suave' }, 'Carregando...'));
  const baixar = el('button', { class: 'botao', type: 'button' }, 'Baixar planilha (CSV)');
  campoMes.addEventListener('change', () => abrirRelatorio(campoMes.value));
  baixar.addEventListener('click', async () => {
    try {
      const blob = await api(`uso.php?mes=${campoMes.value}&formato=csv`, { blob: true });
      const link = el('a', { href: URL.createObjectURL(blob), download: `uso-${campoMes.value}.csv` });
      document.body.append(link); link.click(); link.remove();
    } catch (e) { alert(`Não foi possível baixar: ${e.message}`); }
  });

  abrirModal('Relatório de cobrança', 'Notas autorizadas no mês, por empresa e tipo', [
    el('div', { class: 'acoes' }, el('label', { class: 'suave' }, 'Mês ', campoMes), baixar),
    area,
  ]);

  try {
    const r = await api(`uso.php?mes=${mes}`);
    area.replaceChildren(
      el('p', {}, `Ambiente: ${r.ambiente === 'PRODUCAO' ? 'Produção' : 'Homologação'} · Total do mês: `, el('strong', {}, r.total), ' notas'),
      el('table', { class: 'mini' },
        el('thead', {}, el('tr', {}, el('th', {}, 'Empresa'), el('th', {}, 'Plano'), el('th', { class: 'num' }, 'Limite'),
          ...TIPOS.map((t) => el('th', { class: 'num' }, t.nome)), el('th', { class: 'num' }, 'Total'))),
        el('tbody', {}, ...r.empresas.map((l) => el('tr', {},
          el('td', {}, l.razao_social, el('div', { class: 'empresa__cnpj mono' }, cnpj(l.cnpj))),
          el('td', {}, l.plano || '—'), el('td', { class: 'num' }, l.limite_mensal || '—'),
          el('td', { class: 'num' }, l.nfe), el('td', { class: 'num' }, l.nfse_nacional), el('td', { class: 'num' }, l.nfse_salvador),
          el('td', { class: 'num' }, el('strong', {}, l.total)))))),
    );
  } catch (e) {
    area.replaceChildren(el('p', { class: 'erro' }, e.message));
  }
}

// ==========================================================================
// Inicio
// ==========================================================================

$('form-acesso').addEventListener('submit', async (evento) => {
  evento.preventDefault();
  const token = $('campo-token').value.trim();
  try {
    await entrar(token);
    salvarToken(token, $('campo-lembrar').checked);
  } catch (e) {
    if (e.message !== 'nao autorizado') sair(e.message);
  }
});
$('botao-sair').addEventListener('click', () => sair());
$('botao-nova').addEventListener('click', novaEmpresa);
$('botao-relatorio').addEventListener('click', () => abrirRelatorio());
$('modal-fechar').addEventListener('click', () => $('modal').close());
$('filtro-busca').addEventListener('input', desenhar);
$('filtro-situacao').addEventListener('change', (e) => { estado.filtroSituacao = e.target.value; desenhar(); });

(async () => {
  const salvo = lerTokenSalvo();
  if (!salvo) { sair(); return; }
  try { await entrar(salvo); } catch (e) { if (e.message !== 'nao autorizado') sair(e.message); }
})();
