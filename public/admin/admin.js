/* ==========================================================================
   Painel Administrativo - empresas clientes da API fiscal

   Tudo o que esta tela faz passa pelas rotas /v1/admin/*.php com o
   cabecalho X-ADMIN-TOKEN:
     clientes.php     lista (GET) e cadastro/alteracao (POST)
     cliente.php      detalhe: uso 12 meses, status do mes, historico
     portal_usuarios.php  quem da empresa entra no portal do cliente
     certificado.php  envio do .pfx (multipart)
     token.php        gera token novo (aparece uma unica vez)
     uso.php          relatorio de cobranca do mes (JSON ou CSV)
     sistema.php      tela Sistema: worker ligado? fila; pausar/retomar/reiniciar
     banco.php        tela Sistema: scripts do banco pendentes; botao Atualizar banco
     diagnostico.php  tela Sistema: botao Diagnostico (confere tudo, nao altera nada)
     email.php        tela Sistema: bloco E-mail (situacao e e-mail de teste)
     configuracoes.php tela Sistema: configuracoes editaveis (portal, envio, API)
     fechamento.php   fechamento do mes: receita por empresa para o DAS (JSON ou CSV)
     guia_das.php     guia DAS do Simples: anexar/trocar/remover o PDF do mes, abrir o PDF, marcar paga
     serpro_das.php   gerar o DAS pela Receita (SERPRO), por enquanto so no modo teste

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
  NUMERACAO: 'Numeração ajustada', PORTAL_USUARIO_CRIADO: 'Acesso ao portal criado', PORTAL_SENHA_REDEFINIDA: 'Nova senha do portal',
  PORTAL_ACESSO_ENVIADO: 'Acesso ao portal enviado por e-mail', PORTAL_USUARIO_ATIVADO: 'Acesso ao portal reativado',
  PORTAL_USUARIO_DESATIVADO: 'Acesso ao portal desativado', PORTAL_ADMIN_CONCEDIDO: 'Virou responsável da empresa no portal',
  PORTAL_ADMIN_RETIRADO: 'Deixou de ser responsável da empresa no portal',
  GUIA_DAS_ANEXADA: 'Guia DAS anexada', GUIA_DAS_TROCADA: 'Guia DAS trocada', GUIA_DAS_REMOVIDA: 'Guia DAS removida',
  SERPRO_DAS_TESTE: 'Teste de guia na Receita (SERPRO)', SERPRO_DAS: 'DAS gerado pela Receita (SERPRO)',
  SERPRO_DECLARACAO: 'Declaração do Simples transmitida (SERPRO)', SERPRO_DECLARACAO_TESTE: 'Teste de declaração do Simples (SERPRO)',
  GUIA_DAS_PAGA: 'Guia DAS marcada como paga', GUIA_DAS_PAGAMENTO_DESFEITO: 'Guia DAS: "paga" desfeito',
};

const ACOES_SISTEMA = {
  WORKER_PAUSADO: 'Envios pausados', WORKER_RETOMADO: 'Envios retomados', WORKER_REINICIADO: 'Worker reiniciado',
  WORKER_LIGADO: 'Worker ligado', AMBIENTE_MODELO_ALTERADO: 'Modelo da nota na produção trocado', WORKER_DESLIGADO: 'Worker desligado',
  BANCO_ATUALIZADO: 'Banco atualizado', BANCO_ERRO: 'Erro ao atualizar o banco', CONFIG_ALTERADA: 'Configurações alteradas',
  PAINEL_PESSOA_CRIADA: 'Pessoa do painel cadastrada', PAINEL_SENHA_REDEFINIDA: 'Senha nova de pessoa do painel',
  PAINEL_PESSOA_ATIVADA: 'Pessoa do painel reativada', PAINEL_PESSOA_DESATIVADA: 'Pessoa do painel desativada',
  PAINEL_ACESSO_ENVIADO: 'Acesso ao painel enviado por e-mail', AMBIENTE_ALTERADO: 'Ambiente de emissão trocado',
};

const ESTADOS_WORKER = { TRABALHANDO: 'Enviando notas', AGUARDANDO: 'Esperando notas', PAUSADO: 'Pausado' };

const estado = { token: '', pessoa: null, senhaDigitada: '', modoChave: false, clientes: [], ambiente: '', mes: '', filtroSituacao: '' };

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
    sair('Sua sessão terminou. Entre de novo.');
    throw new Error('nao autorizado');
  }
  if (resposta.status === 403 && !opcoes.blob) {
    const j = await resposta.clone().json().catch(() => ({}));
    if (j.erro?.codigo === 'TROCAR_SENHA') { pedirNovaSenha(estado.pessoa); throw new Error('nao autorizado'); }
  }
  if (opcoes.blob) {
    if (!resposta.ok) throw new Error(`HTTP ${resposta.status}`);
    return resposta.blob();
  }
  const json = await resposta.json().catch(() => ({}));
  if (!resposta.ok) {
    const erro = new Error(json.erro?.mensagem || `HTTP ${resposta.status}`);
    erro.detalhes = json.erro?.erros || [];
    erro.codigo = json.erro?.codigo || '';
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
  // Encerra o login no servidor (se havia um). O monitor usa o mesmo login: sai de la tambem.
  if (estado.token && !mensagem) fetch(API + 'entrar.php', { method: 'DELETE', headers: { 'X-ADMIN-TOKEN': estado.token } }).catch(() => {});
  try {
    for (const chave of [CHAVE_TOKEN, 'nfse_monitor_token']) { sessionStorage.removeItem(chave); localStorage.removeItem(chave); }
  } catch { /* ignora */ }
  estado.token = '';
  estado.pessoa = null;
  $('tela-painel').hidden = true;
  $('tela-acesso').hidden = false;
  $('form-acesso').hidden = false;
  $('form-nova-senha').hidden = true;
  $('campo-senha').value = '';
  $('campo-token').value = '';
  if (estado.modoChave) $('trocar-modo-acesso').click(); // volta para e-mail e senha
  $('erro-acesso').hidden = !mensagem;
  $('erro-acesso').textContent = mensagem || '';
  abrirChaveSeNinguemCadastrado();
}

// Painel sem nenhuma pessoa cadastrada: o login ja abre na chave de emergencia.
async function abrirChaveSeNinguemCadastrado() {
  try {
    const r = await fetch(API + 'entrar.php?situacao=1');
    const json = await r.json();
    if (json.tem_pessoas) return;
    if (!estado.modoChave) $('trocar-modo-acesso').click();
    $('acesso-explica').textContent = 'Ninguém foi cadastrado no painel ainda. Entre com a chave de emergência (o admin_token) e cadastre você em Sistema > Pessoas do painel.';
  } catch { /* sem resposta: fica no e-mail e senha */ }
}

// Entrou (pelo e-mail ou pela chave): confere quem e e abre o painel.
async function entrar(token) {
  estado.token = token;
  const eu = await api('entrar.php');
  estado.pessoa = eu.pessoa;
  if (eu.pessoa?.trocar_senha) { pedirNovaSenha(eu.pessoa); return false; }
  await carregar();
  $('tela-acesso').hidden = true;
  $('tela-painel').hidden = false;
  $('botao-eu').hidden = !eu.pessoa;
  $('botao-eu').textContent = eu.pessoa ? '👤 ' + eu.pessoa.nome.split(' ')[0] : '';
  mostrarAvisoChave(eu);
  atualizarPontoSistema().catch(() => {});
  return true;
}

// Quem entrou com a chave de emergencia ve um aviso para cadastrar as pessoas.
function mostrarAvisoChave(eu) {
  const aviso = $('aviso-chave');
  aviso.hidden = !eu.chave_emergencia;
  if (!eu.chave_emergencia) return;
  aviso.replaceChildren(
    el('strong', {}, 'Você entrou com a chave de emergência.'),
    el('span', {}, !eu.instalado ? 'Primeiro clique em "Atualizar banco" (em Sistema). Depois cadastre quem vai usar o painel.'
      : (eu.tem_pessoas ? 'O histórico vai mostrar "Chave de emergência". Prefira entrar com o seu e-mail.'
        : 'Cadastre você e a sua equipe: cada um entra com o próprio e-mail e senha.')),
    el('button', { class: 'botao botao--forte', type: 'button', onclick: () => abrirSistema({ focar: eu.instalado ? 'pessoas' : 'banco' }) },
      eu.instalado ? 'Cadastrar pessoas do painel' : 'Abrir Sistema'));
}

// 1o acesso (senha provisoria) ou "Minha senha": tela para criar a senha.
function pedirNovaSenha(pessoa) {
  $('tela-painel').hidden = true;
  $('tela-acesso').hidden = false;
  $('form-acesso').hidden = true;
  $('form-nova-senha').hidden = false;
  $('nova-senha-quem').textContent = pessoa ? `${pessoa.nome} (${pessoa.email}): no primeiro acesso você cria a sua própria senha.` : '';
  // Se acabou de digitar a provisoria no login, nao pede de novo.
  $('nova-senha-atual').hidden = Boolean(estado.senhaDigitada);
  $('campo-senha-atual').value = estado.senhaDigitada || '';
  $('erro-nova-senha').hidden = true;
  ($('nova-senha-atual').hidden ? $('campo-senha-nova') : $('campo-senha-atual')).focus();
}

// Bolinha do botao "Sistema" sempre atualizada.
setInterval(() => { if (estado.token) atualizarPontoSistema().catch(() => {}); }, 15000);

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
  $('aviso-producao').hidden = !producao;
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

/**
 * Abre a janela. opcoes.abas: barra de abas (ficha da empresa);
 * opcoes.rodape: conteudo da barra fixa de baixo (ex.: botao Salvar).
 */
function abrirModal(titulo, subtitulo, conteudo, opcoes = {}) {
  $('modal-titulo').textContent = titulo;
  $('modal-subtitulo').textContent = subtitulo || '';
  // Se o navegador guardou um index.html antigo (sem abas/rodape), segue sem eles.
  const abas = $('modal-abas');
  const rodape = $('modal-rodape');
  if (abas) { abas.replaceChildren(...(opcoes.abas ? [opcoes.abas] : [])); abas.hidden = !opcoes.abas; }
  if (rodape) { rodape.replaceChildren(...(opcoes.rodape || [])); rodape.hidden = !opcoes.rodape; }
  else if (opcoes.rodape) conteudo = [...conteudo, el('div', { class: 'acoes' }, ...opcoes.rodape)];
  $('modal-corpo').replaceChildren(...conteudo.filter((item) => item !== null && item !== undefined && item !== false)); // blocos que nao se aplicam (null) nao aparecem
  $('modal-corpo').scrollTop = 0;
  delete $('modal').dataset.sujo;
  if (!$('modal').open) $('modal').showModal();
}

// Fecha a janela pelo X, pelo Esc ou clicando fora dela. Se houver alteracao
// nao salva (ou uma senha provisoria na tela), pergunta antes.
function podeFecharModal() {
  const modal = $('modal');
  if (modal.dataset.sujo === '1' && !confirm('Há alterações não salvas. Fechar mesmo assim?')) return false;
  if (modal.querySelector('.acesso-box') && !confirm('A senha provisória na tela some ao fechar (para mandar depois, só gerando outra). Fechar mesmo assim?')) return false;
  return true;
}
function fecharModal() { if (podeFecharModal()) $('modal').close(); }

/** Clique no fundo escuro (fora da caixa) fecha a janela. */
function fecharAoClicarFora(dialogo, fechar) {
  let comecouFora = false;
  const fora = (e) => {
    const r = dialogo.getBoundingClientRect();
    return e.target === dialogo && (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom);
  };
  // So fecha se apertou E soltou fora (arrastar uma selecao de dentro para fora nao fecha).
  dialogo.addEventListener('mousedown', (e) => { comecouFora = fora(e); });
  dialogo.addEventListener('click', (e) => { if (comecouFora && fora(e)) fechar(); comecouFora = false; });
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

// ---- Ficha da empresa ----
// Uma pagina so, curta:
//   1. situacao + lista "Pronta para emitir" (so o que falta);
//   2. formulario com o essencial a mostra (tipos de nota, identificacao,
//      onde emite, impostos) e o resto recolhido em "Mais opções";
//   3. certificado, acessos, numeracao e uso em blocos recolhidos.
// Campos que so valem para um tipo de nota (ex.: inscricao estadual = NF-e)
// aparecem so quando aquele tipo esta marcado (atributo data-tipos).

/** Campo com rotulo e, se houver, uma explicacao curta embaixo. */
function campoAjuda(rotulo, nome, valor, extra = {}) {
  const label = campo(rotulo, nome, valor, extra);
  if (extra.ajuda) label.append(el('span', { class: 'campo-ajuda' }, extra.ajuda));
  if (extra.tipos) label.dataset.tipos = extra.tipos;
  if (extra.mostrarSe) label.dataset.mostrarSe = extra.mostrarSe;
  return label;
}

/** Bloco de campos com titulo curto. */
function grupoCampos(titulo, ...campos) {
  return el('fieldset', { class: 'grupo-campos' }, el('legend', {}, titulo), el('div', { class: 'campos' }, ...campos));
}

/** Bloco que abre e fecha (resumo sempre visivel). */
function recolhivel(titulo, resumo, aberto, ...conteudo) {
  return el('details', { class: 'recolhivel', open: aberto },
    el('summary', {}, el('span', { class: 'recolhivel__titulo' }, titulo), resumo ? el('span', { class: 'recolhivel__resumo' }, resumo) : null),
    el('div', { class: 'recolhivel__corpo' }, ...conteudo));
}

function formularioEmpresa(c, novo) {
  const simples = selecao('Simples Nacional', 'opcao_simples_nacional', c.opcao_simples_nacional, [[1, '1 - Não optante'], [2, '2 - MEI'], [3, '3 - ME/EPP (Simples)']]);
  const regimeApuracao = selecao('Regime de apuração (ME/EPP)', 'regime_apuracao_simples', c.regime_apuracao_simples, [['', 'Escolha'],
    [1, '1 - Tudo pelo Simples (DAS), inclusive o ISS'],
    [2, '2 - Federais pelo Simples, ISS pela prefeitura'],
    [3, '3 - Federais e ISS fora do Simples']]);
  regimeApuracao.dataset.mostrarSe = 'mepp';
  regimeApuracao.append(el('span', { class: 'campo-ajuda' }, 'Como os impostos são pagos. O mais comum é o 1. Confirme com o contador.'));
  const regimeNacional = selecao('Regime especial (NFS-e Nacional)', 'regime_especial_tributacao', c.regime_especial_tributacao,
    [[0, '0 - Nenhum'], [1, '1 - Ato cooperado (cooperativa)'], [2, '2 - Estimativa'], [3, '3 - Microempresa municipal'],
      [4, '4 - Notário ou registrador'], [5, '5 - Profissional autônomo'], [6, '6 - Sociedade de profissionais']]);
  regimeNacional.dataset.tipos = 'NACIONAL';
  const regimeSalvador = selecao('Regime especial (Salvador)', 'regime_especial_abrasf', c.regime_especial_abrasf,
    [['', 'Nenhum'], [1, '1 - Microempresa municipal'], [2, '2 - Estimativa'], [3, '3 - Sociedade de profissionais'],
      [4, '4 - Cooperativa'], [5, '5 - MEI do Simples'], [6, '6 - ME/EPP do Simples']]);
  regimeSalvador.dataset.tipos = 'SALVADOR';

  const form = el('form', { class: 'form-empresa', novalidate: false },
    el('h3', {}, 'Dados da empresa'),
    grupoCampos('O que a empresa emite',
      el('div', { class: 'largo opcoes-linha' },
        ...TIPOS.map((t) => el('label', { class: 'linha-check' },
          el('input', { type: 'checkbox', name: 'padroes_permitidos', value: t.padrao, checked: (c.padroes_permitidos || []).includes(t.padrao) }), t.nome)))),
    grupoCampos('Empresa',
      campoAjuda('CNPJ', 'cnpj', novo ? '' : cnpj(c.cnpj), { obrigatorio: true, placeholder: '00.000.000/0000-00' }),
      campoAjuda('Razão social', 'razao_social', c.razao_social, { obrigatorio: true, max: 150 }),
      campoAjuda('Nome fantasia', 'nome_fantasia', c.nome_fantasia, { max: 150 }),
      campoAjuda('E-mail', 'email', c.email, { tipo: 'email', max: 120 }),
      campoAjuda('Telefone', 'telefone', c.telefone, { max: 20 })),
    grupoCampos('Inscrições',
      campoAjuda('Código IBGE do município', 'codigo_municipio', c.codigo_municipio, { max: 7, ajuda: 'Salvador = 2927408', tipos: 'NACIONAL SALVADOR' }),
      campoAjuda('Inscrição municipal (NFS-e)', 'inscricao_municipal', c.inscricao_municipal, { max: 20, tipos: 'NACIONAL SALVADOR' }),
      campoAjuda('UF (NF-e)', 'uf', c.uf, { max: 2, placeholder: 'BA', tipos: 'NFE' }),
      campoAjuda('Inscrição estadual (NF-e)', 'ie', c.ie, { max: 20, tipos: 'NFE' })),
    grupoCampos('Impostos',
      simples, regimeApuracao,
      campoAjuda('% tributos do Simples', 'percentual_tributos_simples', c.percentual_tributos_simples ?? '',
        { max: 6, placeholder: 'ex.: 2', ajuda: 'O contador informa.', mostrarSe: 'mepp' })),
    recolhivel('Mais opções', 'Liberação, plano, regimes especiais, webhook e anotações', false,
      el('div', { class: 'campos' },
        el('label', { class: 'linha-check largo' }, el('input', { type: 'checkbox', name: 'emissao_liberada', checked: c.emissao_liberada }), 'Emissão liberada'),
        campoAjuda('Motivo do bloqueio (o cliente vê)', 'motivo_bloqueio', c.motivo_bloqueio, { max: 300, largo: true, mostrarSe: 'bloqueada' }),
        campoAjuda('Liberado até', 'liberado_ate', c.liberado_ate, { tipo: 'date', ajuda: 'Vazio = sem vencimento' }),
        campoAjuda('Plano', 'plano', c.plano, { max: 60, placeholder: 'Ex.: Básico' }),
        campoAjuda('Limite de notas por mês', 'limite_mensal', c.limite_mensal, { tipo: 'number', min: 1, ajuda: 'Vazio = sem limite' }),
        regimeNacional, regimeSalvador,
        el('label', { class: 'linha-check', 'data-tipos': 'SALVADOR' },
          el('input', { type: 'checkbox', name: 'incentivador_cultural', checked: c.incentivador_cultural }), 'Incentivador cultural (Salvador)'),
        campoAjuda('Webhook (URL)', 'webhook_url', c.webhook_url, { largo: true, max: 500, placeholder: 'https://seu-sistema/webhook' }),
        campoAjuda('Segredo do webhook', 'webhook_segredo', '', { max: 200, ajuda: c.tem_webhook_segredo ? 'Já preenchido. Digite só para trocar.' : '' }),
        el('label', { class: 'largo' }, 'Anotações internas (só a equipe vê)',
          el('textarea', { name: 'observacao', maxlength: 2000, rows: 2 }, c.observacao || '')))));
  if (!novo) form.querySelector('[name=cnpj]').readOnly = true;

  // Mostra so os campos que valem para o que esta marcado agora.
  const ajustar = () => {
    const tipos = [...form.querySelectorAll('[name=padroes_permitidos]:checked')].map((i) => i.value);
    const mepp = form.querySelector('[name=opcao_simples_nacional]').value === '3';
    const bloqueada = !form.querySelector('[name=emissao_liberada]').checked;
    for (const item of form.querySelectorAll('[data-tipos]')) item.hidden = !item.dataset.tipos.split(' ').some((t) => tipos.includes(t));
    for (const item of form.querySelectorAll('[data-mostrar-se]')) item.hidden = item.dataset.mostrarSe === 'mepp' ? !mepp : !bloqueada;
    // Bloco sem nenhum campo visivel some (ex.: Inscricoes sem tipo marcado).
    for (const grupo of form.querySelectorAll('.grupo-campos')) {
      grupo.hidden = ![...grupo.querySelectorAll('.campos > *')].some((i) => !i.hidden);
    }
  };
  form.addEventListener('change', ajustar);
  ajustar();
  return form;
}

/** Abre os blocos recolhidos que contem o elemento (usado pelo "Ir para"). */
function mostrarAbaDe(elemento) {
  for (let d = elemento.closest('details'); d; d = d.parentElement.closest('details')) d.open = true;
}

function desenharCliente(c) {
  const novo = c === null;
  c = c || { padroes_permitidos: TIPOS.map((t) => t.padrao), emissao_liberada: true, codigo_municipio: '2927408', opcao_simples_nacional: 1, regime_especial_tributacao: 0 };
  const form = formularioEmpresa(c, novo);

  // ---- Botao salvar (barra fixa embaixo da janela) ----
  const mensagemSalvar = el('span', { id: 'empresa-mensagem', class: 'rodape__mensagem', role: 'status' });
  const botaoSalvar = el('button', { id: 'botao-salvar-empresa', class: 'botao botao--forte', type: 'button' }, novo ? 'Cadastrar empresa' : 'Salvar alterações');
  form.addEventListener('input', () => { $('modal').dataset.sujo = '1'; mensagemSalvar.className = 'rodape__mensagem aviso'; mensagemSalvar.textContent = 'Alterações não salvas'; });
  botaoSalvar.addEventListener('click', async () => {
    const corpo = lerFormulario(form);
    if (!corpo) return;
    botaoSalvar.disabled = true;
    mensagemSalvar.className = 'rodape__mensagem suave';
    mensagemSalvar.textContent = 'Salvando...';
    try {
      const { cliente } = await api('clientes.php', { metodo: 'POST', corpo });
      await carregar();
      desenharCliente(cliente);
    } catch (e) {
      mensagemSalvar.className = 'rodape__mensagem erro';
      mensagemSalvar.textContent = [e.message, ...(e.detalhes || [])].filter((t, i, a) => a.indexOf(t) === i).join(' ');
      botaoSalvar.disabled = false;
    }
  });
  const rodape = [el('div', { class: 'rodape__texto' }, mensagemSalvar), botaoSalvar];

  if (novo) {
    abrirModal('Nova empresa', 'Preencha o básico. Certificado e acessos aparecem depois de cadastrar.', [form], { rodape });
    return;
  }

  const certificado = c.tem_certificado
    ? (c.certificado_dias_restantes < 0 ? 'vencido' : `válido até ${dataCurta(c.certificado_validade)}`)
    : 'não enviado';
  const series = (c.numeracao || []).length;
  abrirModal(c.nome_fantasia || c.razao_social, cnpj(c.cnpj), [
    el('div', { class: 'acoes situacao-linha' }, seloSituacao(c.situacao), el('span', {}, c.situacao_mensagem),
      el('a', { class: 'botao', href: `../monitor/?emitente=${encodeURIComponent(c.cnpj)}` }, 'Ver notas desta empresa')),
    c.prontidao ? secaoProntidao(c.prontidao) : null,
    form,
    recolhivel('Certificado digital', certificado, !c.tem_certificado, secaoCertificado(c)),
    recolhivel('Portal do cliente', 'quem entra no portal · enviar acesso por e-mail', true, secaoPortal(c)),
    recolhivel('Acesso à API (token)', c.tem_token ? 'token ativo' : 'sem token', false, secaoToken(c)),
    recolhivel('Numeração', series ? `${series} série(s) em uso` : 'nenhuma nota ainda', false, secaoNumeracao(c)),
    recolhivel('Uso e histórico', `${c.autorizadas_mes?.total ?? 0} nota(s) autorizada(s) no mês`, false, secaoUso(c), secaoHistorico(c)),
  ], { rodape });
}

// ---- Pronta para emitir ----
// Lista que o servidor monta (Servico/ProntidaoEmissao.php): o que falta para
// a empresa emitir cada tipo de nota contratado. No topo, o resultado geral e
// um selo por grupo; depois o que falta (e os avisos) com "Como resolver";
// os itens ok ficam recolhidos. Nivel sempre com icone + texto (nao so cor).
const NIVEIS_PRONTIDAO = {
  FALTA: { nome: 'Falta', icone: '✕' },
  ATENCAO: { nome: 'Atenção', icone: '!' },
  OK: { nome: 'OK', icone: '✓' },
};

// Secoes da ficha citadas nos textos de "Como resolver" (o botao "Ir para"
// leva ate elas). Campos entre aspas no texto sao procurados pelo rotulo.
const DESTINOS_PRONTIDAO = [
  ['Controle de emissão', /controle de emiss/i],
  ['Dados da empresa', /dados da empresa/i],
  ['Certificado digital', /certificado|\.pfx/i],
  ['Acesso à API', /token da api/i],
  ['Portal do cliente', /portal do cliente/i],
  ['Numeração', /numera[cç][aã]o/i],
];
const CAMPOS_PRONTIDAO = [['E-mail', /\be-mail\b/i], ['Código IBGE do município', /7 números|confira o código/i]];

function iconeProntidao(nivel) {
  const n = NIVEIS_PRONTIDAO[nivel] || NIVEIS_PRONTIDAO.ATENCAO;
  return el('span', { class: 'pront-icone', 'data-nivel': nivel, 'aria-hidden': 'true' }, n.icone);
}

/** Onde o "Ir para" deve levar: campo citado (entre aspas ou conhecido) ou a secao da ficha. */
function destinoProntidao(texto) {
  const campos = [...texto.matchAll(/"([^"]+)"/g)].map((m) => m[1]);
  for (const [rotulo, padrao] of CAMPOS_PRONTIDAO) if (padrao.test(texto)) campos.push(rotulo);
  // Secao citada entre aspas tem preferencia ("Portal do cliente" ou token da API -> Portal).
  const secao = DESTINOS_PRONTIDAO.find(([nome]) => campos.includes(nome)) || DESTINOS_PRONTIDAO.find(([, padrao]) => padrao.test(texto));
  if (!campos.length && !secao) return null;
  return { campos, secao: secao ? secao[0] : null, nome: campos[0] || secao[0] };
}

function irParaProntidao(destino) {
  const corpo = $('modal-corpo');
  const simples = (s) => s.toLowerCase().replace(/\s*\(.*$/, '').replace(/\b(de|da|do)\b/g, '').replace(/\s+/g, ' ').trim();
  let alvo = null;
  for (const nome of destino.campos) {
    const label = [...corpo.querySelectorAll('label')].find((l) => simples(l.textContent).startsWith(simples(nome)));
    alvo = label?.querySelector('input, select, textarea') || null;
    if (alvo) break;
  }
  if (!alvo && destino.secao) {
    alvo = [...corpo.querySelectorAll('h3')].find((h) => !h.closest('.prontidao') && simples(h.textContent).startsWith(simples(destino.secao))) || null;
    if (alvo) alvo.setAttribute('tabindex', '-1');
  }
  if (!alvo) return;
  // Titulo escondido de bloco recolhivel: vai para o cabecalho clicavel do bloco.
  const caixa = alvo.tagName === 'H3' ? alvo.closest('details.recolhivel') : null;
  if (caixa) alvo = caixa.querySelector('summary');
  mostrarAbaDe(alvo);
  const suave = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  alvo.scrollIntoView({ block: 'center', behavior: suave ? 'smooth' : 'auto' });
  alvo.focus({ preventScroll: true });
  // Garantia: so o corpo da janela rola; a janela inteira nunca sai do lugar.
  $('modal').scrollTop = 0;
}

function itemProntidao(i, grupo) {
  const n = NIVEIS_PRONTIDAO[i.nivel] || NIVEIS_PRONTIDAO.ATENCAO;
  const ok = i.nivel === 'OK';
  const destino = !ok && i.resolver ? destinoProntidao(i.resolver) : null;
  return el('li', { class: 'pront-item', 'data-nivel': i.nivel },
    iconeProntidao(i.nivel),
    el('div', { class: 'pront-item__corpo' },
      el('p', { class: 'pront-item__texto' },
        el('span', { class: 'visualmente-oculto' }, `${n.nome}: `),
        i.texto,
        grupo ? el('span', { class: 'pront-tag' }, grupo) : null),
      i.resolver ? el('div', { class: ok ? 'pront-dica' : 'pront-resolver' },
        el('strong', {}, ok ? 'Dica' : 'Como resolver'),
        el('p', {}, i.resolver),
        destino ? el('button', { type: 'button', class: 'link-botao pront-ir', onclick: () => irParaProntidao(destino) },
          `Ir para ${destino.campos.length ? `“${destino.nome}”` : destino.nome}`) : null) : null));
}

/** Resumo de um grupo: "Pronto", "Falta 1", "2 avisos", "Falta 1 · 1 aviso". */
function resumoGrupoProntidao(g) {
  const faltam = g.itens.filter((i) => i.nivel === 'FALTA').length;
  const avisos = g.itens.filter((i) => i.nivel === 'ATENCAO').length;
  const partes = [];
  if (faltam) partes.push(faltam === 1 ? 'falta 1' : `faltam ${faltam}`);
  if (avisos) partes.push(avisos === 1 ? '1 aviso' : `${avisos} avisos`);
  const nivel = faltam ? 'FALTA' : avisos ? 'ATENCAO' : 'OK';
  const texto = partes.length ? partes.join(' · ') : 'pronto';
  return { nivel, texto: texto[0].toUpperCase() + texto.slice(1) };
}

function secaoProntidao(p) {
  const grupos = (p.grupos || []).filter((g) => (g.itens || []).length);
  const itens = grupos.flatMap((g) => g.itens.map((i) => [i, g.titulo]));
  const de = (nivel) => itens.filter(([i]) => i.nivel === nivel);
  const problemas = [...de('FALTA'), ...de('ATENCAO')];
  const certos = de('OK');

  let titulo;
  let nivel;
  if (!p.pronta) {
    titulo = p.faltam === 1 ? 'Falta 1 item para emitir' : `Faltam ${p.faltam} itens para emitir`;
    if (p.atencao > 0) titulo += ` (e ${p.atencao === 1 ? '1 aviso' : `${p.atencao} avisos`})`;
    nivel = 'FALTA';
  } else if (p.atencao > 0) {
    titulo = `Pronta para emitir, com ${p.atencao === 1 ? '1 aviso' : `${p.atencao} avisos`}`;
    nivel = 'ATENCAO';
  } else {
    titulo = 'Pronta para emitir';
    nivel = 'OK';
  }

  const idTitulo = 'prontidao-titulo';
  const secao = el('section', { class: 'secao prontidao', 'data-nivel': nivel, 'data-pronta': String(p.pronta), 'aria-labelledby': idTitulo },
    el('h3', { id: idTitulo }, 'Pronta para emitir?'),
    el('div', { class: 'pront-resumo' },
      iconeProntidao(nivel),
      el('div', {},
        el('p', { class: 'pront-resumo__titulo' }, titulo),
        el('p', { class: 'pront-resumo__ambiente' }, p.ambiente))),
    grupos.length
      ? el('ul', { class: 'pront-grupos', 'aria-label': 'Situação por tipo de nota' },
        ...grupos.map((g) => {
          const r = resumoGrupoProntidao(g);
          return el('li', { class: 'pront-grupo', 'data-nivel': r.nivel },
            iconeProntidao(r.nivel),
            el('span', { class: 'pront-grupo__nome' }, g.titulo === 'Geral' ? 'Geral (todos os tipos)' : g.titulo),
            el('span', { class: 'pront-grupo__estado' }, r.texto));
        }))
      : el('p', { class: 'suave' }, 'Nenhuma conferência para mostrar: confira os tipos de nota contratados em Controle de emissão.'),
    problemas.length ? [
      el('h4', { class: 'pront-subtitulo' }, `O que resolver (${problemas.length})`),
      el('ul', { class: 'pront-lista' }, ...problemas.map(([i, g]) => itemProntidao(i, g))),
    ] : null,
    certos.length ? el('details', { class: 'pront-oks' },
      el('summary', {}, certos.length === 1 ? 'Ver o item ok' : `Ver os ${certos.length} itens ok`),
      ...grupos.filter((g) => g.itens.some((i) => i.nivel === 'OK')).map((g) => el('div', { class: 'pront-oks__grupo' },
        el('h4', {}, g.titulo),
        el('ul', { class: 'pront-lista pront-lista--compacta' },
          ...g.itens.filter((i) => i.nivel === 'OK').map((i) => itemProntidao(i, null)))))) : null,
    el('p', { class: 'pront-rodape suave' }, 'Corrigiu um item nos dados da empresa? Clique em "Salvar alterações" e a lista é conferida de novo.'),
    el('p', { class: 'pront-retorno', role: 'status' }));
  return secao;
}

/** Confere de novo so a lista (depois de gerar token ou mexer no portal), sem redesenhar a ficha. */
async function atualizarProntidao(numeroCnpj) {
  const atual = $('modal-corpo').querySelector('.prontidao');
  if (!atual) return;
  const retorno = atual.querySelector('.pront-retorno');
  atual.setAttribute('aria-busy', 'true');
  if (retorno) { retorno.className = 'pront-retorno suave'; retorno.textContent = 'Conferindo a lista de novo…'; }
  try {
    const { cliente } = await api(`cliente.php?cnpj=${encodeURIComponent(numeroCnpj)}`);
    if (!cliente.prontidao) throw new Error('sem lista');
    const nova = secaoProntidao(cliente.prontidao);
    // Mantem aberto o "Ver os itens ok" se a pessoa tinha aberto.
    if (atual.querySelector('.pront-oks')?.open) nova.querySelector('.pront-oks')?.setAttribute('open', '');
    atual.replaceWith(nova);
    const novoRetorno = nova.querySelector('.pront-retorno');
    if (novoRetorno) { novoRetorno.className = 'pront-retorno suave'; novoRetorno.textContent = 'Lista conferida de novo.'; }
  } catch {
    // A lista antiga continua; ao reabrir a ficha ela e conferida de novo.
    atual.removeAttribute('aria-busy');
    if (retorno) { retorno.className = 'pront-retorno erro'; retorno.textContent = 'Não foi possível conferir a lista agora. Ela pode estar desatualizada: feche e abra a ficha de novo.'; }
  }
}

function lerFormulario(form) {
  if (!form.reportValidity()) return null;
  const corpo = { padroes_permitidos: [] };
  for (const input of form.querySelectorAll('input, select, textarea')) {
    if (input.name === 'padroes_permitidos') { if (input.checked) corpo.padroes_permitidos.push(input.value); }
    else if (input.type === 'checkbox') corpo[input.name] = input.checked;
    else if (input.name === 'webhook_segredo' && input.value === '') continue; // vazio = manter o atual
    else corpo[input.name] = input.value;
  }
  if (!corpo.emissao_liberada && !corpo.motivo_bloqueio.trim()
      && !confirm('Bloquear sem informar o motivo? O cliente verá só "Emissão bloqueada".')) {
    return null;
  }
  return corpo;
}

// ---- Certificado ----
function secaoCertificado(c) {
  // ids usados pela ajuda guiada (admin-guias.js)
  const arquivo = el('input', { id: 'cert-arquivo', type: 'file', accept: '.pfx,.p12' });
  const senha = el('input', { id: 'cert-senha', type: 'password', autocomplete: 'new-password' });
  const mensagem = el('span', { id: 'cert-mensagem' });
  const botao = el('button', { id: 'botao-enviar-certificado', class: 'botao', type: 'button' }, 'Enviar certificado');
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
      atualizarProntidao(c.cnpj);
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

// ---- Portal do cliente (usuarios que entram com e-mail e senha) ----
function secaoPortal(c) {
  const lista = el('div', {}, el('p', { class: 'suave' }, 'Carregando...'));
  // ids usados pela ajuda guiada (admin-guias.js)
  const area = el('div', { id: 'acesso-area' });
  const nome = el('input', { id: 'acesso-nome', type: 'text', placeholder: 'Nome da pessoa', autocomplete: 'off' });
  const email = el('input', { id: 'acesso-email', type: 'email', placeholder: 'email@empresa.com.br', autocomplete: 'off' });
  const mensagem = el('span', { id: 'acesso-mensagem' });
  const linkPortal = new URL('../portal/', window.location.href).href;

  // Aviso quando o e-mail do sistema ainda nao foi configurado.
  const semEmail = (detalhe, comSenha = true) => el('div', { class: 'envio-aviso' },
    el('strong', {}, 'O envio de e-mail ainda não foi configurado neste sistema.'),
    el('p', {}, detalhe || 'É preciso configurar uma vez só, em Sistema > E-mail.'),
    el('p', {}, comSenha ? 'Por enquanto, clique em "Copiar dados de acesso" e mande pelo WhatsApp ou pelo seu e-mail.'
      : 'Por enquanto, use "Nova senha" e copie os dados para mandar pelo WhatsApp ou pelo seu e-mail.'),
    el('button', { class: 'botao', type: 'button', onclick: () => {
      if (!comSenha || confirm('A senha desta tela some quando você sair dela. Depois de configurar o e-mail, volte aqui e clique em "✉ Enviar acesso por e-mail" na linha da pessoa. Abrir a configuração agora?')) abrirSistema({ focar: 'email' });
    } }, 'Configurar e-mail agora'));

  // Botao da linha: gera senha provisoria nova e ja manda por e-mail (um clique so).
  async function enviarAcessoDaLinha(u) {
    area.replaceChildren(el('p', { class: 'suave' }, 'Verificando o e-mail do sistema...'));
    area.scrollIntoView({ block: 'nearest' });
    try {
      const m = await api('email.php');
      if (!m.configurado) {
        area.replaceChildren(semEmail(`Falta preencher: ${m.faltam.join(', ')}.`, false));
        return;
      }
    } catch { /* segue: o servidor confere de novo no envio */ }
    if (!confirm(`Enviar o acesso ao portal para ${u.email}?\n\nO sistema gera uma senha provisória nova e manda por e-mail. A senha atual dessa pessoa para de funcionar.`)) {
      area.replaceChildren();
      return;
    }
    try {
      const r = await api('portal_usuarios.php', { metodo: 'POST', corpo: { cnpj: c.cnpj, acao: 'redefinir_senha', id: u.id } });
      mostrarSenha(r).click();
      await recarregar();
    } catch (e) {
      area.replaceChildren(el('p', { class: 'erro' }, e.message));
    }
  }

  // Senha provisoria aparece uma vez so. Dois caminhos para a pessoa receber:
  // 1) "Enviar por e-mail" (o sistema manda na hora) ou 2) "Copiar" (manda voce mesmo).
  function mostrarSenha(r) {
    const texto = `Portal: ${linkPortal}\nE-mail: ${r.email}\nSenha provisória: ${r.senha_provisoria}`;
    const resultado = el('div', { class: 'envio-resultado', 'aria-live': 'polite' });
    const enviar = el('button', { class: 'botao botao--forte', type: 'button' }, `✉ Enviar por e-mail para ${r.email}`);
    const copiar = el('button', { class: 'botao', type: 'button', onclick: async () => {
      try { await navigator.clipboard.writeText(texto); copiar.textContent = '✓ Copiado'; } catch { copiar.textContent = 'Selecione o texto e copie'; }
    } }, 'Copiar dados de acesso');

    enviar.addEventListener('click', async () => {
      enviar.disabled = true;
      enviar.textContent = 'Enviando... (até 20 s)';
      resultado.replaceChildren();
      try {
        const e = await api('portal_usuarios.php', { metodo: 'POST', corpo: {
          acao: 'enviar_acesso', cnpj: c.cnpj, id: r.id, senha: r.senha_provisoria, link_portal: linkPortal } });
        resultado.replaceChildren(el('p', { class: 'envio-ok' }, `✓ E-mail enviado para ${e.email}. Peça para a pessoa olhar também a caixa de spam.`));
        enviar.textContent = 'Enviar de novo';
      } catch (e) {
        const falta = (e.message.match(/Falta: ([^.]+)\./) || [])[1];
        resultado.replaceChildren(e.codigo === 'EMAIL_NAO_CONFIGURADO' ? semEmail(falta ? `Falta preencher: ${falta}.` : '') : el('p', { class: 'erro' }, e.message));
        enviar.textContent = `✉ Tentar enviar de novo para ${r.email}`;
      }
      enviar.disabled = false;
    });

    area.replaceChildren(el('div', { class: 'token-box acesso-box' },
      el('strong', { class: 'acesso-box__titulo' }, `Acesso pronto para ${r.email}`),
      el('p', { class: 'suave' }, 'Agora mande estes dados para a pessoa. A senha só aparece aqui agora: depois só gerando outra.'),
      el('pre', { class: 'exemplo mono' }, texto),
      el('div', { class: 'acoes' }, enviar, copiar),
      resultado,
      el('p', { class: 'suave' }, 'No primeiro acesso o portal pede para ela criar a própria senha.')));

    // Ja avisa antes do clique quando o e-mail nao esta configurado.
    api('email.php').then((m) => {
      if (m.configurado || resultado.childNodes.length) return;
      enviar.classList.remove('botao--forte');
      copiar.classList.add('botao--forte');
      resultado.replaceChildren(semEmail(`Falta preencher: ${m.faltam.join(', ')}.`));
    }).catch(() => {});
    area.scrollIntoView({ block: 'nearest' });
    return enviar;
  }

  async function acao(corpo, confirmar) {
    if (confirmar && !confirm(confirmar)) return;
    try {
      const r = await api('portal_usuarios.php', { metodo: 'POST', corpo: { cnpj: c.cnpj, ...corpo } });
      const enviar = r.senha_provisoria ? mostrarSenha(r) : null;
      if (!enviar) area.replaceChildren(el('p', { class: 'ok-texto' }, r.mensagem));
      await recarregar();
      atualizarProntidao(c.cnpj);
      return enviar;
    } catch (e) {
      area.replaceChildren(el('p', { class: 'erro' }, e.message));
    }
  }

  async function recarregar() {
    try {
      const r = await api('portal_usuarios.php?cnpj=' + encodeURIComponent(c.cnpj));
      if (!r.usuarios.length) {
        lista.replaceChildren(el('p', { class: 'suave' }, 'Ninguém desta empresa tem acesso ao portal ainda.'));
        return;
      }
      lista.replaceChildren(el('table', { class: 'mini' },
        el('thead', {}, el('tr', {}, el('th', {}, 'Nome'), el('th', {}, 'E-mail'), el('th', {}, 'Papel'), el('th', {}, 'Último acesso'), el('th', {}, 'Situação'), el('th', {}, ''))),
        el('tbody', {}, ...r.usuarios.map((u) => el('tr', {},
          el('td', {}, u.nome),
          el('td', {}, u.email),
          // Responsavel da empresa (campo administrador) convida e desativa colegas pela tela Usuarios do portal.
          el('td', {}, u.administrador ? 'Responsável da empresa' : 'Emissor'),
          el('td', {}, u.ultimo_acesso ? dataHora(u.ultimo_acesso) : 'nunca entrou'),
          el('td', {}, !u.ativo ? el('span', { class: 'ruim' }, 'Desativado') : (u.trocar_senha ? 'Aguardando 1º acesso' : 'Ativo')),
          el('td', {},
            u.ativo ? el('button', { class: 'botao botao--forte', type: 'button', title: 'Gera uma senha provisória nova e manda por e-mail para a pessoa',
              onclick: () => enviarAcessoDaLinha(u) }, '✉ Enviar acesso por e-mail') : null, ' ',
            el('button', { class: 'botao', type: 'button', onclick: () => acao({ acao: 'redefinir_senha', id: u.id }, `Gerar senha provisória nova para ${u.email}? A senha atual para de funcionar.`) }, 'Nova senha'), ' ',
            el('button', { class: 'botao', type: 'button', onclick: () => acao({ acao: u.administrador ? 'remover_admin' : 'tornar_admin', id: u.id }) },
              u.administrador ? 'Tirar responsável' : 'Tornar responsável'), ' ',
            el('button', { class: 'botao' + (u.ativo ? ' botao--perigo' : ''), type: 'button',
              onclick: () => acao({ acao: u.ativo ? 'desativar' : 'ativar', id: u.id }, u.ativo ? `Desativar o acesso de ${u.email}?` : null) },
            u.ativo ? 'Desativar' : 'Reativar'))))),
      ));
    } catch (e) {
      lista.replaceChildren(el('p', { class: 'erro' }, e.message));
    }
  }

  const porEmail = el('input', { id: 'acesso-por-email', type: 'checkbox', checked: true });
  const botao = el('button', { id: 'botao-dar-acesso', class: 'botao botao--forte', type: 'button' }, 'Dar acesso ao portal');
  botao.addEventListener('click', async () => {
    mensagem.textContent = '';
    if (nome.value.trim().length < 2 || !email.checkValidity() || !email.value) {
      mensagem.className = 'erro';
      mensagem.textContent = 'Informe o nome e um e-mail válido.';
      return;
    }
    botao.disabled = true;
    const enviar = await acao({ acao: 'criar', nome: nome.value, email: email.value });
    if (enviar && porEmail.checked) enviar.click();
    if (area.querySelector('.token-box')) { nome.value = ''; email.value = ''; }
    botao.disabled = false;
  });

  recarregar();
  return el('section', { class: 'secao' },
    el('h3', {}, 'Portal do cliente'),
    el('p', { class: 'suave' }, `Pessoas desta empresa que emitem NFS-e pelo formulário em ${linkPortal}`),
    lista,
    el('div', { class: 'campos' }, el('label', {}, 'Nome', nome), el('label', {}, 'E-mail', email)),
    el('label', { class: 'opcao-linha' }, porEmail, ' Enviar os dados de acesso por e-mail para a pessoa'),
    el('div', { class: 'acoes' }, botao, mensagem),
    area,
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

  // So os tipos que a empresa emite (se nenhum marcado, mostra todos).
  const contratados = TIPOS.filter((t) => (c.padroes_permitidos || []).includes(t.padrao));
  const tipo = el('select', {}, ...(contratados.length ? contratados : TIPOS).map((t) => el('option', { value: t.padrao }, t.nome)));
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
/** "por Ana Paula" no historico (nome completo e e-mail ao passar o mouse). */
function autorDoRegistro(r) {
  if (!r.autor) return null;
  return el('span', { title: r.autor }, ' por ', el('strong', {}, r.autor.replace(/\s*<[^>]*>$/, '')));
}

function secaoHistorico(c) {
  const itens = (c.historico || []).map((h) => {
    let detalhe = '';
    if (h.acao === 'ALTERACAO' || h.acao === 'CADASTRO') {
      detalhe = Object.entries(h.detalhes).map(([k, v]) => (v && typeof v === 'object' && 'para' in v ? `${k}: ${JSON.stringify(v.de)} → ${JSON.stringify(v.para)}` : `${k}: ${v}`)).join('; ');
    } else if (h.acao === 'TOKEN') {
      detalhe = `final ••••${h.detalhes.final}`;
    } else if (h.acao === 'NUMERACAO') {
      const d = h.detalhes;
      const nome = (TIPOS.find((t) => t.padrao === d.padrao) || {}).nome || d.padrao;
      detalhe = `${nome}, série ${d.serie}: informado ${d.informado}; antes ${d.antes ?? '—'}, agora ${d.depois} (próxima ${d.depois + 1})`;
    } else if (h.acao === 'CERTIFICADO') {
      detalhe = `${h.detalhes.titular}, válido até ${dataCurta(h.detalhes.validade)}`;
    }
    return el('li', {}, el('time', { class: 'suave' }, dataHora(h.criado_em)), ' · ', el('strong', {}, ACOES_HISTORICO[h.acao] || h.acao),
      autorDoRegistro(h), h.ip ? el('span', { class: 'suave' }, ` (IP ${h.ip})`) : null, detalhe ? el('div', { class: 'suave' }, detalhe) : null);
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
// Fechamento do mes (contador)
//
// Receita de cada empresa com as notas do sistema, para o contador fazer a
// declaracao do Simples (PGDAS-D) e gerar o DAS. Rota: fechamento.php.
// O "DAS estimado" e so uma estimativa (receita x % do Simples cadastrado).
// ==========================================================================

function moeda(valor) {
  if (valor === null || valor === undefined) return '—';
  return Number(valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

async function abrirFechamento(mes) {
  mes = mes || estado.mes;
  const campoMes = el('input', { type: 'month', value: mes });
  const area = el('div', {}, el('p', { class: 'suave' }, 'Carregando...'));
  const baixar = el('button', { class: 'botao', type: 'button' }, 'Baixar planilha (CSV)');
  campoMes.addEventListener('change', () => abrirFechamento(campoMes.value));
  baixar.addEventListener('click', async () => {
    try {
      const blob = await api(`fechamento.php?mes=${campoMes.value}&formato=csv`, { blob: true });
      const link = el('a', { href: URL.createObjectURL(blob), download: `fechamento-${campoMes.value}.csv` });
      document.body.append(link); link.click(); link.remove();
    } catch (e) { alert(`Não foi possível baixar: ${e.message}`); }
  });

  abrirModal('Fechamento do mês', 'Receita de cada empresa com as notas emitidas no sistema', [
    el('div', { class: 'acoes' }, el('label', { class: 'suave' }, 'Mês ', campoMes), baixar),
    area,
  ]);

  try {
    const r = await api(`fechamento.php?mes=${mes}`);
    // Aparecem: quem teve nota no mes, toda ME/EPP (paga DAS mesmo sem nota no sistema) e quem ja tem guia.
    const comNotas = r.empresas.filter((l) => l.notas > 0 || l.canceladas > 0 || l.simples === 'ME/EPP' || l.guia);
    const semNotas = r.empresas.length - comNotas.length;
    // filter(Boolean): tira os blocos que nao se aplicam (null), senao aparece "null" na tela.
    area.replaceChildren(...[
      el('div', { class: 'numeros' },
        el('div', { class: 'numero' }, el('b', {}, moeda(r.totais.receita_mes)), el('span', {}, 'receita do mês (todas as empresas)')),
        el('div', { class: 'numero' }, el('b', {}, r.totais.notas), el('span', {}, 'notas autorizadas')),
        el('div', { class: 'numero' }, el('b', {}, moeda(r.totais.das_estimado)), el('span', {}, 'DAS estimado (soma)'))),
      r.ambiente !== 'PRODUCAO' ? el('p', { class: 'aviso' }, 'Ambiente de homologação: estas notas são de teste, sem valor fiscal.') : null,
      comNotas.length === 0
        ? el('p', { class: 'suave' }, 'Nenhuma empresa emitiu nota neste mês.')
        : el('div', { id: 'fechamento-tabela', class: 'tabela-rolagem' }, el('table', { class: 'mini' },
          el('thead', {}, el('tr', {},
            el('th', {}, 'Empresa'), el('th', {}, 'Simples'),
            el('th', { class: 'num' }, 'Notas'), el('th', { class: 'num', title: 'Canceladas não entram na receita' }, 'Canceladas'),
            el('th', { class: 'num' }, 'Receita do mês'), el('th', { class: 'num', title: 'Soma dos 12 meses anteriores (só notas do sistema)' }, '12 meses anteriores'),
            el('th', { class: 'num', title: 'Percentual aproximado do Simples, do cadastro da empresa' }, '% Simples'),
            el('th', { class: 'num' }, 'DAS estimado'),
            r.guias_instalado ? el('th', {}, 'Guia DAS') : null)),
          el('tbody', {}, ...comNotas.map((l) => el('tr', {},
            el('td', {}, l.razao_social, el('div', { class: 'empresa__cnpj mono' }, cnpj(l.cnpj))),
            el('td', {}, l.simples || '—'),
            el('td', { class: 'num', title: `NFS-e: ${l.nfse.quantidade} (${moeda(l.nfse.valor)}) · NF-e: ${l.nfe.quantidade} (${moeda(l.nfe.valor)})` }, l.notas),
            el('td', { class: 'num' }, l.canceladas || '—'),
            el('td', { class: 'num' }, el('strong', {}, moeda(l.receita_mes))),
            el('td', { class: 'num' }, moeda(l.receita_12_meses)),
            el('td', { class: 'num' }, l.percentual_simples === null ? '—' : `${String(l.percentual_simples).replace('.', ',')}%`),
            el('td', { class: 'num' }, l.das_estimado === null ? el('span', { class: 'suave', title: dicaSemEstimativa(l) }, '—') : moeda(l.das_estimado)),
            r.guias_instalado ? el('td', {}, celulaGuia(l, mes, r.pagamento_instalado),
              (r.serpro_modo === 'TESTE' || r.serpro_modo === 'PRODUCAO') && l.simples === 'ME/EPP'
                ? el('button', { class: 'link-botao guia-serpro', type: 'button', 'data-declarar': l.cnpj,
                  title: r.serpro_modo === 'TESTE' ? 'Declaração e DAS no ambiente de teste do SERPRO (exemplo, sem custo)'
                    : 'Confere a receita, transmite a declaração do Simples e gera o DAS pela Receita',
                  onclick: () => abrirDeclaracao(l, mes, r.serpro_modo) }, r.serpro_modo === 'TESTE' ? 'Testar Receita' : 'Declarar e gerar DAS')
                : null) : null))))),
      semNotas > 0 ? el('p', { class: 'suave' }, `${semNotas} empresa(s) sem notas neste mês e fora do Simples (ME/EPP) não aparecem na lista (estão na planilha).`) : null,
      r.guias_instalado ? null : el('p', { class: 'aviso' }, 'Para anexar as guias DAS, falta atualizar o banco: botão Sistema > Atualizar banco.'),
      el('p', { class: 'suave' },
        'Como usar: confira a receita de cada empresa, faça a declaração no PGDAS-D (site do Simples Nacional), gere o DAS ',
        'e clique em "Anexar guia". O cliente vê a guia no portal e recebe um aviso por e-mail. ',
        'O DAS estimado é só uma referência (receita × % do Simples do cadastro). O valor certo é o do PGDAS-D, ',
        'que considera a faixa dos últimos 12 meses, o anexo da atividade e receitas que não passaram pelo sistema.'),
    ].filter(Boolean));
  } catch (e) {
    area.replaceChildren(el('p', { class: 'erro' }, e.message));
  }
}

// ---- Guia DAS (script 0015) ----

/** Celula "Guia DAS" de uma empresa: botao Anexar ou a guia ja anexada (paga ou nao). */
function celulaGuia(l, mes, pagamentoInstalado) {
  if (!l.guia) {
    return el('button', { class: 'botao botao--pequeno', type: 'button', onclick: () => abrirAnexarGuia(l, mes) }, 'Anexar guia');
  }
  const g = l.guia;
  let pagamento = null;
  if (g.pago_em) {
    pagamento = el('span', { class: 'ok-texto', title: g.pago_marcado_por ? `Marcada por ${g.pago_marcado_por}` : '' }, `✓ Paga em ${dataCurta(g.pago_em)}`);
  }
  const acoes = [
    el('button', { class: 'link-botao', type: 'button', onclick: () => abrirAnexarGuia(l, mes) }, 'Trocar'),
    el('button', { class: 'link-botao', type: 'button', onclick: () => removerGuia(g, l, mes) }, 'Remover'),
  ];
  if (pagamentoInstalado) {
    acoes.unshift(g.pago_em
      ? el('button', { class: 'link-botao', type: 'button', onclick: () => pagamentoGuia(g, mes, null) }, 'Desfazer pago')
      : el('button', { class: 'link-botao', type: 'button', onclick: () => pagamentoGuia(g, mes, true) }, 'Marcar paga'));
  }
  return el('div', { class: 'guia-celula' },
    el('button', { class: 'link-botao', type: 'button', title: 'Abrir o PDF', onclick: () => abrirPdfGuia(g.id) }, `${moeda(g.valor)} · vence ${dataCurta(g.vencimento)}`),
    pagamento,
    el('div', { class: 'guia-celula__acoes' }, ...acoes));
}

/** Marca como paga (pergunta a data; vazio = hoje) ou desfaz. O sistema nao confere na Receita. */
async function pagamentoGuia(g, mes, pagar) {
  let corpo = { acao: 'desfazer_pagamento', id: g.id };
  if (pagar) {
    const hoje = new Date();
    const padrao = `${String(hoje.getDate()).padStart(2, '0')}/${String(hoje.getMonth() + 1).padStart(2, '0')}/${hoje.getFullYear()}`;
    const resposta = prompt('Data em que a guia foi paga (dd/mm/aaaa):', padrao);
    if (resposta === null) return;
    const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(resposta.trim());
    if (!m) { alert('Use o formato dd/mm/aaaa.'); return; }
    corpo = { acao: 'pagar', id: g.id, data: `${m[3]}-${m[2]}-${m[1]}` };
  } else if (!confirm('Desfazer o "pago" desta guia? Ela volta a aparecer como a vencer ou vencida.')) {
    return;
  }
  try {
    await api('guia_das.php', { metodo: 'POST', corpo });
    abrirFechamento(mes);
  } catch (e) { alert(e.message); }
}

async function abrirPdfGuia(id) {
  // O PDF precisa do login (cabecalho): baixa e abre numa aba nova.
  const aba = window.open('', '_blank');
  try {
    const blob = await api(`guia_das.php?id=${id}`, { blob: true });
    const url = URL.createObjectURL(blob);
    if (aba) aba.location = url; else window.location = url;
  } catch (e) {
    if (aba) aba.close();
    alert(`Não foi possível abrir a guia: ${e.message}`);
  }
}

async function removerGuia(g, l, mes) {
  if (!confirm(`Remover a guia de ${l.razao_social} (${moeda(g.valor)})? O cliente deixa de ver a guia no portal.`)) return;
  try {
    await api('guia_das.php', { metodo: 'POST', corpo: { acao: 'remover', id: g.id } });
    abrirFechamento(mes);
  } catch (e) { alert(e.message); }
}

/** O DAS vence no dia 20 do mes seguinte ao da receita. */
function vencimentoPadraoDas(mes) {
  const [a, m] = mes.split('-').map(Number);
  const proximo = new Date(a, m, 20); // m ja e o mes seguinte (Date conta de 0)
  return `${proximo.getFullYear()}-${String(proximo.getMonth() + 1).padStart(2, '0')}-20`;
}

function abrirAnexarGuia(l, mes) {
  const [ano, mm] = mes.split('-');
  const g = l.guia;
  // ids usados pela ajuda guiada (admin-guias.js)
  const arquivo = el('input', { id: 'guia-arquivo', type: 'file', accept: 'application/pdf,.pdf', required: true });
  const valor = el('input', { id: 'guia-valor', type: 'text', inputmode: 'decimal', placeholder: '0,00', required: true,
    value: g ? g.valor.toFixed(2).replace('.', ',') : '' });
  const vencimento = el('input', { id: 'guia-vencimento', type: 'date', required: true, value: g ? g.vencimento : vencimentoPadraoDas(mes) });
  const linha = el('input', { id: 'guia-linha', type: 'text', inputmode: 'numeric', placeholder: 'Opcional: 48 números', value: g?.linha_digitavel || '' });
  const avisar = el('input', { id: 'guia-avisar', type: 'checkbox', checked: true });
  const mensagem = el('p', { id: 'guia-mensagem', class: 'suave' });
  const salvar = el('button', { id: 'botao-salvar-guia', class: 'botao botao--primario', type: 'submit' }, g ? 'Trocar guia' : 'Anexar guia');
  const voltar = el('button', { class: 'botao', type: 'button', onclick: () => abrirFechamento(mes) }, 'Voltar');

  const form = el('form', { id: 'form-guia', class: 'form-guia' },
    g ? el('p', { class: 'aviso' }, `Já existe guia deste mês (${moeda(g.valor)}). Ao salvar, a nova substitui a antiga.`) : null,
    el('p', { class: 'suave' }, 'Gere o DAS no PGDAS-D (site do Simples Nacional), baixe o PDF e anexe aqui com o valor e o vencimento que estão na guia.'),
    el('div', { class: 'campos' },
      el('label', { class: 'largo' }, 'PDF da guia', arquivo),
      el('label', {}, 'Valor total (R$)', valor),
      el('label', {}, 'Vencimento', vencimento),
      el('label', { class: 'largo' }, 'Código de barras (números)', linha),
      el('label', { class: 'linha-check largo' }, avisar, ' Avisar o cliente por e-mail (todas as pessoas da empresa no portal)')),
    l.das_estimado ? el('p', { class: 'suave' }, `Para conferir: o DAS estimado pelo sistema é ${moeda(l.das_estimado)}.`) : null,
    mensagem,
    el('div', { class: 'acoes' }, salvar, voltar));

  form.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (!form.reportValidity()) return;
    const dados = new FormData();
    dados.append('cnpj', l.cnpj);
    dados.append('competencia', mes);
    dados.append('valor', valor.value);
    dados.append('vencimento', vencimento.value);
    dados.append('linha_digitavel', linha.value);
    dados.append('avisar', avisar.checked ? '1' : '0');
    dados.append('arquivo', arquivo.files[0]);
    salvar.disabled = true;
    mensagem.className = 'suave';
    mensagem.textContent = 'Enviando...';
    try {
      const r = await api('guia_das.php', { metodo: 'POST', corpo: dados });
      await abrirFechamento(mes);
      if (avisar.checked) {
        alert(r.avisados > 0 ? `Guia salva. ${r.avisados} pessoa(s) da empresa vão receber o aviso por e-mail.`
          : 'Guia salva. Ninguém foi avisado por e-mail: a empresa não tem pessoas ativas no portal (ou o e-mail não está ligado).');
      }
    } catch (e) {
      mensagem.className = 'erro';
      mensagem.textContent = e.message;
      salvar.disabled = false;
    }
  });

  abrirModal(g ? 'Trocar guia DAS' : 'Anexar guia DAS', `${l.razao_social} · ${mm}/${ano}`, [form]);
}

/**
 * Integracao com a Receita (SERPRO), modo TESTE: pede um DAS ao ambiente de
 * demonstracao e mostra o que voltou. A guia e de EXEMPLO: nao e salva.
 */
async function testarSerpro(l, mes) {
  const [ano, mm] = mes.split('-');
  const area = el('div', {}, el('p', { class: 'suave' }, 'Pedindo a guia ao SERPRO (ambiente de teste)...'));
  const voltar = el('button', { class: 'botao', type: 'button', onclick: () => abrirFechamento(mes) }, 'Voltar');
  abrirModal('Testar Receita (SERPRO)', `${l.razao_social} · ${mm}/${ano}`, [area, el('div', { class: 'acoes' }, voltar)]);
  try {
    const r = await api('serpro_das.php', { metodo: 'POST', corpo: { acao: 'testar', cnpj: l.cnpj, competencia: mes } });
    const bytes = Uint8Array.from(atob(r.pdf_base64), (c) => c.charCodeAt(0));
    const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
    area.replaceChildren(
      el('p', { class: 'aviso' }, 'Funcionou! Esta é uma guia de EXEMPLO do ambiente de teste do SERPRO: não é desta empresa e não foi salva.'),
      el('dl', { class: 'grade' },
        el('div', {}, el('dt', {}, 'Valor'), el('dd', {}, moeda(r.valor))),
        el('div', {}, el('dt', {}, 'Vencimento'), el('dd', {}, dataCurta(r.vencimento))),
        el('div', {}, el('dt', {}, 'Número do documento'), el('dd', { class: 'mono' }, r.numero_documento || '—'))),
      el('p', {}, el('a', { class: 'botao', href: url, target: '_blank', rel: 'noopener' }, 'Abrir o PDF de exemplo')),
      el('p', { class: 'suave' }, 'Na produção (depois do contrato com o SERPRO), este botão vai gerar a guia de verdade e anexar sozinho para o cliente.'));
  } catch (e) {
    area.replaceChildren(el('p', { class: 'erro' }, e.message));
  }
}

// ==========================================================================
// Declaracao do Simples e DAS pela Receita (SERPRO)
// Fechamento do mes > "Declarar e gerar DAS" (no teste: "Testar Receita").
// Regras no servidor: src/Nfse/Servico/DeclaracaoSimples.php
//   1. confere a receita do mes (vem das notas) e a atividade;
//   2. "Calcular" pergunta a Receita quanto da (nada e enviado);
//   3. "Transmitir" envia a declaracao OFICIAL, gera o DAS e anexa para o cliente.
// ==========================================================================

/** Numero em reais digitado ("1.234,56") -> numero. */
function lerReais(texto) {
  const limpo = String(texto ?? '').trim().replace(/\./g, '').replace(',', '.');
  return limpo === '' ? 0 : Number(limpo);
}
function reaisCampo(valor) { return Number(valor || 0).toFixed(2).replace('.', ','); }

async function abrirDeclaracao(l, mes, modo) {
  const [ano, mm] = mes.split('-');
  const teste = modo === 'TESTE';
  const voltar = el('button', { class: 'botao', type: 'button', onclick: () => abrirFechamento(mes) }, 'Voltar');
  abrirModal(teste ? 'Testar Receita: declaração e DAS' : 'Declaração do Simples e DAS', `${l.razao_social} · ${mm}/${ano}`,
    [el('p', { class: 'suave' }, 'Carregando a receita do mês...')]);
  let p;
  try {
    p = await api(`serpro_das.php?cnpj=${encodeURIComponent(l.cnpj)}&competencia=${mes}`);
  } catch (e) {
    abrirModal('Declaração do Simples e DAS', `${l.razao_social} · ${mm}/${ano}`, [el('p', { class: 'erro' }, e.message), el('div', { class: 'acoes' }, voltar)]);
    return;
  }

  const ids = (nome) => `decl-${nome}`;
  const campoReais = (nome, valor) => el('input', { id: ids(nome), type: 'text', inputmode: 'decimal', value: reaisCampo(valor) });
  const semRet = campoReais('sem-retencao', p.receita.servicos_sem_retencao);
  const comRet = campoReais('com-retencao', p.receita.servicos_com_retencao);
  const merc = campoReais('mercadorias', p.receita.mercadorias);
  const servico = el('select', { id: ids('servico') }, ...Object.entries(p.servicos).map(([v, t]) =>
    el('option', { value: v, selected: v === p.sugestao.servico }, t)));
  const mercadoria = el('select', { id: ids('mercadoria') }, ...Object.entries(p.mercadorias).map(([v, t]) =>
    el('option', { value: v, selected: Number(v) === p.sugestao.mercadoria }, t)));
  const tipo = el('select', { id: ids('tipo') },
    el('option', { value: 1, selected: p.sugestao.tipo_declaracao === 1 }, 'Original (primeira do mês)'),
    el('option', { value: 2, selected: p.sugestao.tipo_declaracao === 2 }, 'Retificadora (corrige uma já enviada)'));
  const folhas = Object.entries(p.sugestao.folhas).map(([pa, valor]) => ({ pa, campo: el('input', { type: 'text', inputmode: 'decimal', value: reaisCampo(valor), 'aria-label': `Folha ${pa.slice(4)}/${pa.slice(0, 4)}` }) }));
  const total = el('strong', { id: ids('total') });
  const somar = () => { total.textContent = moeda(lerReais(semRet.value) + lerReais(comRet.value) + lerReais(merc.value)); };
  [semRet, comRet, merc].forEach((c) => c.addEventListener('input', () => { somar(); invalidar(); }));
  somar();

  const resultado = el('div', { id: ids('resultado'), 'aria-live': 'polite' });
  const calcular = el('button', { id: 'botao-calcular-das', class: 'botao botao--forte', type: 'button' }, 'Calcular (não envia nada)');
  const confirmo = el('input', { id: ids('confirmo'), type: 'checkbox' });
  const avisar = el('input', { id: ids('avisar'), type: 'checkbox', checked: true });
  const transmitir = el('button', { id: 'botao-transmitir-das', class: 'botao botao--forte', type: 'button', disabled: true },
    teste ? 'Transmitir (teste) e gerar DAS' : 'Transmitir declaração e gerar DAS');
  const passoEnviar = el('div', { id: ids('enviar'), class: 'secao', hidden: true },
    el('h3', {}, '3. Transmitir'),
    el('label', { class: 'linha-check' }, confirmo, teste
      ? ' Entendi: é o ambiente de teste do SERPRO. Vai o pedido de exemplo dele e volta uma guia de exemplo (não é desta empresa e não vai para o cliente).'
      : ` Conferi os valores. Transmitir a declaração OFICIAL do Simples de ${mm}/${ano} em nome de ${l.razao_social}.`),
    teste ? null : el('label', { class: 'linha-check' }, avisar, ' Avisar o cliente por e-mail quando a guia estiver pronta'),
    el('div', { class: 'acoes' }, transmitir));
  confirmo.addEventListener('change', () => { transmitir.disabled = !confirmo.checked || !conferidos; });

  let conferidos = null;
  function invalidar() { conferidos = null; passoEnviar.hidden = true; confirmo.checked = false; transmitir.disabled = true; resultado.replaceChildren(); }
  for (const c of [servico, mercadoria, tipo, ...folhas.map((f) => f.campo)]) c.addEventListener('input', invalidar);
  function entrada() {
    return {
      cnpj: l.cnpj, competencia: mes, tipo_declaracao: Number(tipo.value),
      servico: servico.value, servicos_sem_retencao: semRet.value, servicos_com_retencao: comRet.value,
      mercadoria: Number(mercadoria.value), mercadorias: merc.value,
      folhas: Object.fromEntries(folhas.map((f) => [f.pa, f.campo.value])),
    };
  }
  const tabelaTributos = (tributos, totalDevido) => el('table', { class: 'mini' },
    el('tbody', {}, ...tributos.map((t) => el('tr', {}, el('td', {}, t.nome), el('td', { class: 'num' }, moeda(t.valor)))),
      el('tr', {}, el('td', {}, el('strong', {}, 'Total do DAS')), el('td', { class: 'num' }, el('strong', {}, moeda(totalDevido))))));

  calcular.addEventListener('click', async () => {
    invalidar();
    calcular.disabled = true;
    resultado.replaceChildren(el('p', { class: 'suave' }, 'Perguntando à Receita... (até 1 minuto)'));
    try {
      const r = await api('serpro_das.php', { metodo: 'POST', corpo: { acao: 'calcular', ...entrada() } });
      conferidos = r.tributos.map((t) => ({ codigo: t.codigo, valor: t.valor }));
      resultado.replaceChildren(...[
        el('h3', {}, '2. Valores calculados pela Receita'),
        r.teste ? el('p', { class: 'aviso' }, 'Ambiente de teste: estes valores são de EXEMPLO do SERPRO, não desta empresa.') : null,
        tabelaTributos(r.tributos, r.valor_devido),
        ...(r.avisos || []).map((a) => el('p', { class: 'aviso' }, a)),
        el('p', { class: 'suave' }, 'Nada foi enviado ainda. Confira com o PGDAS-D se precisar. Mudou algum campo? Calcule de novo.')].filter(Boolean));
      passoEnviar.hidden = false;
    } catch (e) {
      resultado.replaceChildren(el('p', { class: 'erro' }, e.message));
    }
    calcular.disabled = false;
  });

  transmitir.addEventListener('click', async () => {
    if (!conferidos || !confirmo.checked) return;
    transmitir.disabled = true;
    transmitir.textContent = 'Transmitindo... (até 2 minutos)';
    try {
      const r = await api('serpro_das.php', { metodo: 'POST', corpo: { acao: 'transmitir', ...entrada(), conferidos, avisar: avisar.checked } });
      const d = r.declaracao;
      const pdf = (qual) => el('button', { class: 'botao', type: 'button', onclick: () => abrirPdfDeclaracao(d.id, qual) },
        qual === 'recibo' ? 'Recibo da declaração (PDF)' : 'Declaração (PDF)');
      let guia;
      if (!r.das.ok) guia = el('p', { class: 'erro' }, `A declaração foi transmitida, mas o DAS não saiu: ${r.das.mensagem} Tente "Declarar e gerar DAS" de novo mais tarde (escolha Retificadora só se mudar valores) ou gere no PGDAS-D e anexe.`);
      else {
        const bytes = Uint8Array.from(atob(r.das.pdf_base64), (c) => c.charCodeAt(0));
        const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
        guia = el('div', {},
          el('p', { class: r.teste ? 'aviso' : 'ok-texto' }, r.teste
            ? 'Guia de EXEMPLO do ambiente de teste: não é desta empresa e não foi anexada para o cliente.'
            : `✓ Guia anexada para o cliente${r.das.avisados ? ` (${r.das.avisados} pessoa(s) avisada(s) por e-mail)` : ''}.`),
          el('dl', { class: 'grade' },
            el('div', {}, el('dt', {}, 'Valor'), el('dd', {}, moeda(r.das.valor))),
            el('div', {}, el('dt', {}, 'Vencimento'), el('dd', {}, dataCurta(r.das.vencimento))),
            el('div', {}, el('dt', {}, 'Número do DAS'), el('dd', { class: 'mono' }, r.das.numero_documento || '—'))),
          el('p', {}, el('a', { class: 'botao', href: url, target: '_blank', rel: 'noopener' }, 'Abrir o DAS (PDF)')));
      }
      abrirModal(r.teste ? 'Testar Receita: pronto' : 'Declaração transmitida', `${l.razao_social} · ${mm}/${ano}`, [
        el('div', { id: 'declaracao-pronta' },
          el('p', { class: 'ok-texto' }, `✓ Declaração ${d.numero || ''} transmitida${r.teste ? ' (teste)' : ''}.`),
          ...(d.avisos || []).map((a) => el('p', { class: 'aviso' }, a)),
          tabelaTributos(d.tributos, d.valor_devido),
          el('div', { class: 'acoes' }, d.tem_recibo ? pdf('recibo') : null, d.tem_declaracao ? pdf('declaracao') : null),
          guia),
        el('div', { class: 'acoes' }, el('button', { class: 'botao', type: 'button', onclick: () => abrirFechamento(mes) }, 'Voltar ao Fechamento')),
      ]);
    } catch (e) {
      resultado.append(el('p', { class: 'erro' }, e.message));
      transmitir.textContent = teste ? 'Transmitir (teste) e gerar DAS' : 'Transmitir declaração e gerar DAS';
      transmitir.disabled = !confirmo.checked;
    }
  });

  const jaFeitas = (p.declaracoes_do_mes || []).filter((d) => !d.teste);
  abrirModal(teste ? 'Testar Receita: declaração e DAS' : 'Declaração do Simples e DAS', `${l.razao_social} · ${mm}/${ano}`, [
    teste ? el('p', { class: 'aviso' }, 'Modo TESTE: o SERPRO de demonstração responde sempre com dados de exemplo. Serve para ver o caminho inteiro sem custo. Nada vai para a Receita nem para o cliente.') : null,
    jaFeitas.length ? el('p', { class: 'aviso' }, `Já existe declaração deste mês enviada pelo sistema (nº ${jaFeitas[0].numero_declaracao || '—'}). Para corrigir, use "Retificadora".`) : null,
    el('div', { class: 'secao', id: ids('receita') },
      el('h3', {}, '1. Receita do mês'),
      el('p', { class: 'suave' }, `Veio das ${p.receita.notas} nota(s) autorizada(s) no sistema. Recebeu algo fora do sistema? Some aqui.`),
      el('div', { class: 'campos' },
        el('label', {}, 'Tipo da declaração', tipo),
        el('label', { class: 'largo' }, 'Atividade dos serviços (NFS-e)', servico,
          el('span', { class: 'campo-ajuda' }, 'Na dúvida, confirme com o contador. Vale para serviços com ISS devido em ' + (p.empresa.municipio === '2927408' ? 'Salvador' : 'no município da empresa') + '.')),
        el('label', {}, 'Serviços SEM retenção de ISS (R$)', semRet),
        el('label', {}, 'Serviços COM retenção de ISS (R$)', comRet),
        el('label', { class: 'largo' }, 'Atividade das mercadorias (NF-e)', mercadoria),
        el('label', {}, 'Venda de mercadorias (R$)', merc),
        el('p', { class: 'largo' }, 'Receita bruta do mês: ', total)),
      recolhivel('Folha de salários dos 12 meses anteriores', 'obrigatória a partir de 11/2026 (usada no fator "r")', false,
        el('p', { class: 'suave' }, 'Salários + pró-labore + encargos de cada mês. Sem funcionário nem pró-labore, deixe 0,00. Vem preenchida com o que foi informado na última declaração.'),
        el('div', { class: 'campos campos--folha' }, ...folhas.map((f) => el('label', {}, `${f.pa.slice(4)}/${f.pa.slice(0, 4)}`, f.campo))))),
    el('div', { class: 'acoes' }, calcular, voltar),
    resultado,
    passoEnviar,
  ]);
}

async function abrirPdfDeclaracao(id, qual) {
  const aba = window.open('', '_blank');
  try {
    const blob = await api(`serpro_das.php?id=${id}&pdf=${qual}`, { blob: true });
    const url = URL.createObjectURL(blob);
    if (aba) aba.location = url; else window.location = url;
  } catch (e) {
    if (aba) aba.close();
    alert(`Não foi possível abrir: ${e.message}`);
  }
}

/** Sistema > "Integração com a Receita (SERPRO)": chaves do contrato e certificado do escritório. */
function secaoSerpro() {
  const area = el('div', {}, el('p', { class: 'suave' }, 'Carregando...'));
  const retorno = el('div', { 'aria-live': 'polite' });

  async function carregar(mensagem) {
    let s;
    try { s = await api('serpro_acesso.php'); } catch (e) { area.replaceChildren(el('p', { class: 'erro' }, e.message)); return; }
    const key = el('input', { id: 'serpro-key', type: 'text', autocomplete: 'off', placeholder: s.chaves ? '•••••••• (já salva: digite só para trocar)' : 'Consumer Key' });
    const secret = el('input', { id: 'serpro-secret', type: 'password', autocomplete: 'new-password', placeholder: s.chaves ? '•••••••• (já salvo)' : 'Consumer Secret' });
    const arquivo = el('input', { id: 'serpro-cert', type: 'file', accept: '.pfx,.p12' });
    const senha = el('input', { id: 'serpro-cert-senha', type: 'password', autocomplete: 'new-password' });
    const salvar = el('button', { class: 'botao botao--forte', type: 'button' }, 'Salvar acesso');
    const testar = el('button', { class: 'botao', type: 'button', disabled: !s.completo }, 'Testar login no SERPRO');
    const apagar = el('button', { class: 'botao botao--perigo', type: 'button', hidden: !s.chaves && !s.certificado }, 'Apagar acesso');
    const fazer = async (botao, acao) => {
      botao.disabled = true;
      retorno.replaceChildren(el('p', { class: 'suave' }, 'Aguarde...'));
      try { const r = await acao(); await carregar(r.mensagem); } catch (e) { retorno.replaceChildren(el('p', { class: 'erro' }, e.message)); botao.disabled = false; }
    };
    salvar.addEventListener('click', () => fazer(salvar, async () => {
      let r = null;
      if (key.value || secret.value) r = await api('serpro_acesso.php', { metodo: 'POST', corpo: { acao: 'chaves', key: key.value, secret: secret.value } });
      if (arquivo.files[0]) {
        const dados = new FormData();
        dados.append('senha', senha.value);
        dados.append('arquivo', arquivo.files[0]);
        r = await api('serpro_acesso.php', { metodo: 'POST', corpo: dados });
      }
      if (!r) throw new Error('Preencha as chaves ou escolha o certificado.');
      return r;
    }));
    testar.addEventListener('click', () => fazer(testar, () => api('serpro_acesso.php', { metodo: 'POST', corpo: { acao: 'testar_login' } })));
    apagar.addEventListener('click', () => {
      if (confirm('Apagar as chaves e o certificado do SERPRO deste sistema?')) fazer(apagar, () => api('serpro_acesso.php', { metodo: 'POST', corpo: { acao: 'apagar' } }));
    });
    const c = s.certificado;
    area.replaceChildren(...[
      el('p', { class: 'suave' }, 'Só para o modo Produção (em Configurações > "Gerar DAS pela Receita"). Depois de contratar o Integra Contador na loja do SERPRO, copie as chaves da Área do Cliente e envie o certificado e-CNPJ de quem contratou. Cada cliente precisa dar procuração eletrônica para esse CNPJ no e-CAC. Tudo fica guardado criptografado.'),
      el('ul', { class: 'lista-situacao' },
        el('li', {}, s.chaves ? el('span', { class: 'ok-texto' }, '✓ Chaves salvas') : el('span', { class: 'aviso' }, 'Chaves: falta')),
        el('li', {}, c ? el('span', { class: s.certificado_vencido ? 'ruim' : 'ok-texto' },
          `${s.certificado_vencido ? '✕ Certificado vencido' : '✓ Certificado'}: ${c.titular} (${cnpj(c.cnpj)}), válido até ${dataCurta(c.validade)}`)
          : el('span', { class: 'aviso' }, 'Certificado do escritório: falta'))),
      el('div', { class: 'campos' },
        el('label', {}, 'Consumer Key', key), el('label', {}, 'Consumer Secret', secret),
        el('label', {}, 'Certificado e-CNPJ (.pfx)', arquivo), el('label', {}, 'Senha do certificado', senha)),
      el('div', { class: 'acoes' }, salvar, testar, apagar),
      mensagem ? el('p', { class: 'ok-texto' }, mensagem) : null].filter(Boolean));
    retorno.replaceChildren();
  }
  carregar();
  return el('section', { class: 'secao', id: 'secao-serpro' }, el('h3', {}, 'Integração com a Receita (SERPRO): acesso de produção'), area, retorno);
}

/** Explica por que uma empresa ficou sem "DAS estimado". */
function dicaSemEstimativa(l) {
  if (l.simples === 'MEI') return 'MEI paga um valor fixo por mês (DAS-MEI).';
  if (l.simples === 'Não optante') return 'Empresa fora do Simples: não tem DAS.';
  if (l.percentual_simples === null) return 'Cadastre o percentual aproximado do Simples na ficha da empresa para estimar.';
  return '';
}

// ==========================================================================
// Sistema: worker e fila (sem terminal)
//
// O botao "Sistema" no topo tem uma bolinha: verde = worker ligado,
// laranja = pausado/reiniciando, vermelha = parado. Ela se atualiza a cada
// 15 s. A janela se atualiza a cada 5 s enquanto estiver aberta.
// ==========================================================================

let relogioSistema = null;

async function atualizarPontoSistema() {
  const ponto = $('sistema-ponto');
  try {
    const s = await api('sistema.php');
    ponto.className = `ponto-sistema ponto-sistema--${s.situacao}`;
    $('botao-sistema').title = `${s.titulo}. ${s.fila.esperando} na fila.`;
    return s;
  } catch (e) {
    ponto.className = 'ponto-sistema';
    $('botao-sistema').title = e.message;
    throw e;
  }
}

function abrirSistema(opcoes = {}) {
  const estadoArea = el('div', {}, el('p', { class: 'suave' }, 'Carregando...'));
  const filaArea = el('div', {});
  const workersArea = el('div', {});
  const historicoArea = el('div', {});
  const bancoArea = el('div', {}, el('p', { class: 'suave' }, 'Carregando...'));
  const bancoRetorno = el('div', {});
  const diagArea = el('div', {}, el('p', { class: 'suave' },
    'Confere servidor, configuração, banco, worker, certificados das empresas e acesso à internet da prefeitura. Não altera nada e não envia nota.'));
  const diagBotao = el('button', { class: 'botao botao--forte', type: 'button' }, 'Rodar diagnóstico');
  const configArea = el('div', {}, el('p', { class: 'suave' }, 'Carregando...'));
  const emailArea = el('div', {}, el('p', { class: 'suave' }, 'Carregando...'));
  const acoesArea = el('div', { class: 'acoes' });
  const retorno = el('div', {});

  async function comandar(acao, extra = {}) {
    retorno.replaceChildren();
    try {
      const r = await api('sistema.php', { metodo: 'POST', corpo: { acao, ...extra } });
      retorno.replaceChildren(el('p', { class: 'ok-texto' }, r.mensagem));
      desenhar(r.status);
      atualizarPontoSistema().catch(() => {});
    } catch (e) {
      if (e.codigo === 'CONFIRMAR_PRODUCAO') {
        // Notas de producao na fila: so liga depois de a pessoa ler e confirmar.
        retorno.replaceChildren(el('div', { class: 'confirmar confirmar--perigo' },
          el('span', {}, e.message + ' Ligar mesmo assim?'),
          el('button', { class: 'botao botao--perigo', type: 'button', onclick: () => comandar('ligar', { confirmar_producao: true }) }, 'Ligar e enviar'),
          el('button', { class: 'botao', type: 'button', onclick: () => retorno.replaceChildren() }, 'Cancelar')));
        return;
      }
      retorno.replaceChildren(el('p', { class: 'erro' }, e.message));
    }
  }

  // Confirmacao dentro da propria tela (sem janela do navegador).
  function pedirConfirmacao(pergunta, rotulo, acao) {
    retorno.replaceChildren(el('div', { class: 'confirmar' },
      el('span', {}, pergunta),
      el('button', { class: 'botao botao--forte', type: 'button', onclick: () => comandar(acao) }, rotulo),
      el('button', { class: 'botao', type: 'button', onclick: () => retorno.replaceChildren() }, 'Cancelar')));
  }

  function desenhar(s) {
    estadoArea.replaceChildren(el('div', { class: 'sistema-estado' },
      el('span', { class: `ponto-sistema ponto-sistema--${s.situacao}` }),
      el('strong', {}, s.titulo),
      el('span', { class: 'suave' }, s.texto)));

    const parado = s.situacao === 'PARADO';
    acoesArea.replaceChildren(...[
      parado
        ? el('button', { class: 'botao botao--forte', type: 'button', onclick: () => comandar('ligar') }, 'Ligar worker')
        : null,
      parado ? null : s.pausado
        ? el('button', { class: 'botao botao--forte', type: 'button', onclick: () => comandar('retomar') }, 'Retomar envios')
        : el('button', { class: 'botao', type: 'button',
          onclick: () => pedirConfirmacao('Pausar o envio de notas? As notas novas ficam esperando na fila.', 'Pausar envios', 'pausar') }, 'Pausar envios'),
      parado ? null : el('button', { class: 'botao', type: 'button', disabled: s.situacao === 'REINICIANDO',
        onclick: () => pedirConfirmacao('Reiniciar o worker? Ele termina a nota atual e volta sozinho em alguns segundos. Use depois de atualizar o sistema.', 'Reiniciar', 'reiniciar') }, 'Reiniciar worker'),
      parado ? null : el('button', { class: 'botao', type: 'button',
        onclick: () => pedirConfirmacao('Desligar o worker? Ele termina a nota atual e para. Nada vai para a prefeitura até você ligar de novo.', 'Desligar', 'desligar') }, 'Desligar worker')].filter(Boolean));

    const f = s.fila;
    filaArea.replaceChildren(...[el('div', { class: 'numeros' },
      el('div', { class: 'numero' + (f.esperando > 0 && s.situacao !== 'LIGADO' ? ' numero--alerta' : '') }, el('b', {}, f.esperando), el('span', {}, 'esperando envio')),
      el('div', { class: 'numero' }, el('b', {}, f.executando), el('span', {}, 'enviando agora')),
      el('div', { class: 'numero' }, el('b', {}, f.agendados), el('span', {}, 'agendados (consultas)')),
      el('div', { class: 'numero' + (f.falhas_24h > 0 ? ' numero--alerta' : '') }, el('b', {}, f.falhas_24h), el('span', {}, 'falharam nas últimas 24 h'))),
    f.producao > 0 ? el('p', { class: 'erro' }, `${f.producao} nota(s) de PRODUÇÃO na fila: valem de verdade quando o worker enviar.`) : null,
    f.mais_antigo ? el('p', { class: 'suave' }, `A mais antiga esperando entrou em ${dataHora(f.mais_antigo)}.`) : null].filter(Boolean));

    workersArea.replaceChildren(s.workers.length
      ? el('div', { style: 'overflow-x:auto' }, el('table', { class: 'mini' },
        el('thead', {}, el('tr', {}, el('th', {}, 'Servidor'), el('th', {}, 'Situação'), el('th', { class: 'num' }, 'Trabalhos feitos'), el('th', {}, 'Ligado desde'), el('th', {}, 'Último sinal'))),
        el('tbody', {}, ...s.workers.map((w) => el('tr', {},
          el('td', { class: 'mono' }, `${w.servidor} · ${w.pid}`),
          el('td', {}, ESTADOS_WORKER[w.estado] || w.estado),
          el('td', { class: 'num' }, w.jobs_feitos),
          el('td', {}, dataHora(w.iniciado_em)),
          el('td', {}, dataHora(w.batimento_em)))))))
      : el('p', { class: 'suave' }, 'Nenhum worker dando sinal agora.'));

    historicoArea.replaceChildren(s.acoes_recentes.length
      ? el('ul', { class: 'linha-tempo' }, ...s.acoes_recentes.map((a) => el('li', {},
        el('time', { class: 'suave' }, dataHora(a.criado_em)), ' · ', el('strong', {}, ACOES_SISTEMA[a.acao] || a.acao),
        autorDoRegistro(a), a.ip ? el('span', { class: 'suave' }, ` (IP ${a.ip})`) : null)))
      : el('p', { class: 'suave' }, 'Nenhuma ação ainda.'));
  }

  async function recarregar() {
    try {
      desenhar(await atualizarPontoSistema());
    } catch (e) {
      estadoArea.replaceChildren(el('p', { class: 'erro' }, e.message));
    }
  }

  // ---- Diagnostico ----
  const NIVEIS = { ERRO: { nome: 'Erro', ponto: 'PARADO' }, ATENCAO: { nome: 'Atenção', ponto: 'PAUSADO' }, OK: { nome: 'OK', ponto: 'LIGADO' } };

  function itemDiagnostico(i, grupo) {
    return el('li', { class: 'diag-item' },
      el('span', { class: `ponto-sistema ponto-sistema--${NIVEIS[i.nivel].ponto}`, title: NIVEIS[i.nivel].nome }),
      el('div', {},
        el('div', {}, grupo ? el('span', { class: 'suave' }, `${grupo} · `) : null, i.texto),
        i.resolver ? el('div', { class: 'diag-resolver' }, el('strong', {}, 'Como resolver: '), i.resolver) : null));
  }

  async function rodarDiagnostico() {
    diagBotao.disabled = true;
    diagBotao.textContent = 'Conferindo... (até 10 s)';
    try {
      const d = await api('diagnostico.php');
      const problemas = [];
      const certos = [];
      for (const g of d.grupos) {
        for (const i of g.itens) (i.nivel === 'OK' ? certos : problemas).push([i, g.titulo]);
      }
      problemas.sort((a, b) => (a[0].nivel === 'ERRO' ? 0 : 1) - (b[0].nivel === 'ERRO' ? 0 : 1));
      const t = d.total;
      const resumo = d.resultado === 'OK' ? 'Tudo certo'
        : [t.ERRO ? `${t.ERRO} erro(s)` : null, t.ATENCAO ? `${t.ATENCAO} aviso(s)` : null].filter(Boolean).join(' e ');
      diagArea.replaceChildren(...[
        el('div', { class: 'sistema-estado' },
          el('span', { class: `ponto-sistema ponto-sistema--${NIVEIS[d.resultado].ponto}` }),
          el('strong', {}, resumo),
          el('span', { class: 'suave' }, `${t.OK} item(ns) ok · conferido em ${dataHora(d.feito_em)}`)),
        problemas.length ? el('ul', { class: 'diag-lista' }, ...problemas.map(([i, g]) => itemDiagnostico(i, g))) : null,
        el('details', {}, el('summary', { class: 'suave' }, `Ver os ${certos.length} itens ok`),
          el('ul', { class: 'diag-lista' }, ...certos.map(([i, g]) => itemDiagnostico(i, g)))),
      ].filter(Boolean));
    } catch (e) {
      diagArea.replaceChildren(el('p', { class: 'erro' }, e.message));
    }
    diagBotao.disabled = false;
    diagBotao.textContent = 'Rodar de novo';
  }
  diagBotao.addEventListener('click', rodarDiagnostico);

  // ---- Configuracoes ----
  // A lista de campos vem da API (src/Nfse/Dominio/AjustesPainel.php):
  // para liberar uma configuracao nova, mexa so la.
  function textoValor(c, v) {
    if (c.tipo === 'opcao') return c.opcoes[v] ?? v;
    if (v === '' || v === null) return 'vazio';
    return String(v);
  }

  // Um campo de configuracao (lista de Dominio/AjustesPainel.php).
  function campoConfig(c, entradas, erros) {
    const id = 'cfg-' + c.chave.replace('.', '-');
    let entrada;
    if (c.tipo === 'opcao') {
      entrada = el('select', { id }, ...Object.entries(c.opcoes).map(([v, nome]) => el('option', { value: v, selected: String(c.valor) === v }, nome)));
    } else if (c.tipo === 'inteiro') {
      entrada = el('input', { id, type: 'number', min: c.min, max: c.max, step: 1, value: c.valor });
    } else if (c.tipo === 'texto' || c.tipo === 'email') {
      entrada = el('input', { id, type: c.tipo === 'email' ? 'email' : 'text', value: c.valor ?? '', maxlength: 200 });
    } else {
      entrada = el('input', { id, type: 'url', value: c.valor ?? '', placeholder: 'https://...' });
    }
    entradas[c.chave] = entrada;
    erros[c.chave] = el('span', { class: 'erro' });
    const voltar = c.alterado ? el('button', { class: 'link-botao', type: 'button', onclick: () => { entrada.value = c.padrao ?? ''; } }, 'voltar ao padrão') : null;
    return el('label', { class: c.tipo === 'url' ? 'largo' : null, for: id },
      el('span', { class: 'config-rotulo' }, c.rotulo),
      entrada,
      el('span', { class: 'config-ajuda' }, c.ajuda, ' Padrão do arquivo: ', el('strong', {}, textoValor(c, c.padrao)), '. ',
        c.alterado ? el('span', { class: 'config-alterado' }, 'alterado no painel') : null, c.alterado ? ' ' : null, voltar),
      erros[c.chave]);
  }

  function desenharConfiguracoes(dados, mensagem) {
    const entradas = {};
    const erros = {};
    const grupos = {};
    // O grupo E-mail aparece no bloco "E-mail" (junto com a senha), nao aqui.
    for (const c of dados.campos) if (c.grupo !== 'E-mail') (grupos[c.grupo] ||= []).push(c);

    const blocos = Object.entries(grupos).map(([grupo, campos]) => el('fieldset', { class: 'config-grupo' },
      el('legend', {}, grupo),
      el('div', { class: 'campos' }, ...campos.map((c) => campoConfig(c, entradas, erros)))));

    const retorno = el('div', {}, mensagem ? el('p', { class: 'ok-texto' }, mensagem) : '');
    const salvar = el('button', { class: 'botao botao--forte', type: 'button' }, 'Salvar configurações');
    salvar.addEventListener('click', async () => {
      Object.values(erros).forEach((e) => { e.textContent = ''; });
      const valores = {};
      for (const [chave, entrada] of Object.entries(entradas)) valores[chave] = entrada.value;
      salvar.disabled = true;
      try {
        const r = await api('configuracoes.php', { metodo: 'POST', corpo: { valores } });
        desenharConfiguracoes(r.configuracoes, r.mensagem);
        recarregar();
      } catch (e) {
        for (const d of e.detalhes || []) if (erros[d.campo]) erros[d.campo].textContent = d.mensagem;
        retorno.replaceChildren(el('p', { class: 'erro' }, e.message));
        salvar.disabled = false;
      }
    });

    configArea.replaceChildren(
      el('p', { class: 'suave' }, `Ambiente: ${dados.ambiente === 'PRODUCAO' ? 'Produção' : 'Homologação'}. `,
        'Os tokens só mudam no arquivo config/nfse.local.php, por segurança. O ambiente e o e-mail têm blocos próprios, acima.'),
      ...blocos,
      el('div', { class: 'acoes' }, salvar),
      retorno);
  }

  async function carregarConfiguracoes() {
    try {
      desenharConfiguracoes(await api('configuracoes.php'));
    } catch (e) {
      configArea.replaceChildren(el('p', { class: 'erro' }, e.message));
    }
  }

  // ---- Banco de dados ----
  function desenharBanco(b) {
    const nomes = { APLICADO: 'aplicado', PENDENTE: 'pendente', ALTERADO: 'arquivo mudou: aplicar de novo' };
    const pendentes = b.scripts.filter((x) => x.situacao !== 'APLICADO');
    bancoArea.replaceChildren(...[
      el('div', { class: 'sistema-estado' },
        el('span', { class: `ponto-sistema ponto-sistema--${b.em_dia ? 'LIGADO' : 'PAUSADO'}` }),
        el('strong', {}, b.em_dia ? 'Banco em dia' : `${b.pendentes} atualização(ões) pendente(s)`),
        el('span', { class: 'suave' }, b.em_dia
          ? `Todos os ${b.scripts.length} scripts de database/scripts/ já foram aplicados.`
          : 'Aparece depois de atualizar o sistema. Os scripts só criam o que falta, sem apagar dados.')),
      pendentes.length ? el('ul', {}, ...pendentes.map((x) => el('li', {}, el('span', { class: 'mono' }, x.nome), ` (${nomes[x.situacao]})`))) : null,
      el('div', { class: 'acoes' },
        el('button', { class: 'botao' + (b.em_dia ? '' : ' botao--forte'), type: 'button', disabled: b.em_dia,
          onclick: () => bancoRetorno.replaceChildren(el('div', { class: 'confirmar' },
            el('span', {}, `Aplicar ${b.pendentes} script(s) no banco agora? Leva poucos segundos.`),
            el('button', { class: 'botao botao--forte', type: 'button', onclick: aplicarBanco }, 'Atualizar banco'),
            el('button', { class: 'botao', type: 'button', onclick: () => bancoRetorno.replaceChildren() }, 'Cancelar'))) },
        'Atualizar banco')),
      el('details', {}, el('summary', { class: 'suave' }, 'Ver todos os scripts'),
        el('table', { class: 'mini' }, el('tbody', {}, ...b.scripts.map((x) => el('tr', {},
          el('td', { class: 'mono' }, x.nome),
          el('td', {}, x.situacao === 'APLICADO' ? el('span', { class: 'ok-texto' }, 'aplicado') : el('span', { class: 'aviso' }, nomes[x.situacao])),
          el('td', { class: 'suave' }, x.aplicado_em ? dataHora(x.aplicado_em) : '')))))),
    ].filter(Boolean));
  }

  async function carregarBanco() {
    try {
      desenharBanco(await api('banco.php'));
    } catch (e) {
      bancoArea.replaceChildren(el('p', { class: 'erro' }, e.message));
    }
  }

  async function aplicarBanco() {
    bancoRetorno.replaceChildren(el('p', { class: 'suave' }, 'Atualizando o banco...'));
    try {
      const r = await api('banco.php', { metodo: 'POST', corpo: { acao: 'aplicar' } });
      bancoRetorno.replaceChildren(el('p', { class: 'ok-texto' }, r.mensagem));
      desenharBanco(r.banco);
      carregarConfiguracoes();
      carregarEmail();
    } catch (e) {
      bancoRetorno.replaceChildren(el('p', { class: 'erro' }, e.message));
      carregarBanco();
    }
    recarregar();
  }

  // ---- E-mail (Esqueci a senha do portal) ----
  const NOMES_EMAIL = { PENDENTE: 'na fila', ENVIADO: 'enviado', ERRO: 'falhou' };
  // Bloco completo: servidor, usuario, SENHA (criptografada), remetente,
  // endereco do portal, um botao "Salvar e-mail" e o e-mail de teste.
  function desenharEmail(m, cfg) {
    if (!m.instalado) {
      emailArea.replaceChildren(el('p', { class: 'aviso' }, 'Falta atualizar o banco (script 0012) para ligar o e-mail.'));
      return;
    }
    const situacao = !m.configurado
      ? ['PAUSADO', 'E-mail não configurado', `Falta: ${m.faltam.join(', ')}. Preencha abaixo e clique em "Salvar e-mail".`]
      : (!m.esqueci_ligado
        ? ['LIGADO', 'E-mail pronto', 'Já envia o acesso ao portal. Para ligar o "Esqueci a senha", preencha o "Endereço do portal".']
        : ['LIGADO', 'E-mail pronto', `Os e-mails saem por ${m.remetente}. O "Esqueci a senha" está ligado (quem envia é o worker).`]);

    const entradas = {};
    const erros = {};
    const campos = (cfg?.campos || []).filter((c) => c.grupo === 'E-mail');
    const porChave = Object.fromEntries(campos.map((c) => [c.chave, c]));
    const campo = (chave) => (porChave[chave] ? campoConfig(porChave[chave], entradas, erros) : null);

    const senha = el('input', { id: 'cfg-email-senha', type: 'password', autocomplete: 'new-password', maxlength: 200,
      placeholder: m.senha_definida ? '•••••••• (já salva: digite só para trocar)' : 'Senha da conta de e-mail' });
    const verSenha = el('button', { class: 'link-botao', type: 'button', onclick: () => {
      senha.type = senha.type === 'password' ? 'text' : 'password';
      verSenha.textContent = senha.type === 'password' ? 'mostrar' : 'esconder';
    } }, 'mostrar');
    const erroSenha = el('span', { class: 'erro' });
    const campoSenha = el('label', { for: 'cfg-email-senha' },
      el('span', { class: 'config-rotulo' }, 'Senha ', m.senha_definida ? el('span', { class: 'ok-texto' }, '✓ salva') : el('span', { class: 'aviso' }, 'falta')),
      senha,
      el('span', { class: 'config-ajuda' }, 'Fica guardada criptografada: ninguém consegue ver depois, nem aqui. Gmail: use uma "senha de app". ', verSenha),
      erroSenha);

    const retorno = el('div', {});
    const salvar = el('button', { class: 'botao botao--forte', type: 'button' }, 'Salvar e-mail');
    salvar.addEventListener('click', async () => {
      Object.values(erros).forEach((e) => { e.textContent = ''; });
      erroSenha.textContent = '';
      salvar.disabled = true;
      retorno.replaceChildren(el('p', { class: 'suave' }, 'Salvando...'));
      try {
        const valores = {};
        for (const [chave, entrada] of Object.entries(entradas)) valores[chave] = entrada.value;
        if (Object.keys(valores).length) await api('configuracoes.php', { metodo: 'POST', corpo: { valores } });
        if (senha.value !== '') await api('email.php', { metodo: 'POST', corpo: { acao: 'salvar_senha', senha: senha.value } });
        await carregarEmail('✓ E-mail salvo. Agora envie um e-mail de teste para conferir.');
        carregarConfiguracoes();
      } catch (e) {
        for (const d of e.detalhes || []) if (erros[d.campo]) erros[d.campo].textContent = d.mensagem;
        retorno.replaceChildren(el('p', { class: 'erro' }, e.message));
        salvar.disabled = false;
      }
    });

    const para = el('input', { type: 'email', value: m.remetente, placeholder: 'voce@empresa.com.br', 'aria-label': 'E-mail para receber o teste' });
    const resultado = el('div', {});
    const testar = el('button', { class: 'botao', type: 'button', disabled: !m.configurado }, 'Enviar e-mail de teste');
    testar.addEventListener('click', async () => {
      testar.disabled = true;
      resultado.replaceChildren(el('p', { class: 'suave' }, 'Enviando... (até 20 s)'));
      try {
        const r = await api('email.php', { metodo: 'POST', corpo: { para: para.value } });
        resultado.replaceChildren(el('p', { class: 'envio-ok' }, '✓ ' + r.mensagem));
      } catch (e) {
        resultado.replaceChildren(el('p', { class: 'erro' }, e.message));
      }
      testar.disabled = false;
    });

    emailArea.replaceChildren(...[
      el('div', { class: 'sistema-estado' },
        el('span', { class: `ponto-sistema ponto-sistema--${situacao[0]}` }), el('strong', {}, situacao[1]), el('span', { class: 'suave' }, situacao[2])),
      el('p', { class: 'suave' }, 'Usado para mandar o acesso ao portal e o "Esqueci a senha". Os dados você pega com quem cuida do e-mail da empresa.'),
      el('div', { class: 'campos' }, campo('email.host'), campo('email.porta'), campo('email.seguranca'),
        campo('email.usuario'), campoSenha, campo('email.remetente'), campo('email.nome_remetente'), campo('email.url_portal')),
      el('div', { class: 'acoes' }, salvar),
      retorno,
      el('h4', { class: 'pront-subtitulo' }, 'Testar'),
      el('div', { class: 'acoes' }, para, testar),
      m.configurado ? null : el('p', { class: 'suave' }, 'O teste libera depois de salvar o e-mail completo.'),
      resultado,
      m.ultimos.length ? el('details', {}, el('summary', { class: 'suave' }, 'Últimos e-mails'),
        el('table', { class: 'mini' }, el('tbody', {}, ...m.ultimos.map((x) => el('tr', {},
          el('td', {}, x.para),
          el('td', { class: 'suave' }, x.assunto),
          el('td', {}, x.status === 'ENVIADO' ? el('span', { class: 'ok-texto' }, NOMES_EMAIL[x.status])
            : el('span', { class: x.status === 'ERRO' ? 'erro' : 'aviso', title: x.ultimo_erro || '' }, NOMES_EMAIL[x.status] + (x.ultimo_erro ? `: ${x.ultimo_erro}` : ''))),
          el('td', { class: 'suave' }, dataHora(x.enviado_em || x.criado_em))))))) : null,
    ].filter(Boolean));
    if (retornoInicial) retorno.replaceChildren(el('p', { class: 'envio-ok' }, retornoInicial));
  }

  let retornoInicial = '';
  async function carregarEmail(mensagem = '') {
    try {
      const [m, cfg] = await Promise.all([api('email.php'), api('configuracoes.php').catch(() => null)]);
      retornoInicial = mensagem;
      desenharEmail(m, cfg);
      retornoInicial = '';
    } catch (e) {
      emailArea.replaceChildren(el('p', { class: 'erro' }, e.message));
    }
  }

  abrirModal('Sistema', 'Ambiente, worker (o programa que envia as notas), banco de dados, pessoas, e-mail e configurações', [
    secaoAmbiente(),
    el('section', { class: 'secao' }, el('h3', {}, 'Worker'), estadoArea, acoesArea, retorno),
    el('section', { class: 'secao' }, el('h3', {}, 'Fila de envio'), filaArea),
    el('section', { class: 'secao' }, el('h3', {}, 'Workers ligados'), workersArea),
    el('section', { class: 'secao', id: 'secao-banco' }, el('h3', {}, 'Banco de dados'), bancoArea, bancoRetorno),
    el('section', { class: 'secao' }, el('h3', {}, 'Diagnóstico'), diagArea, el('div', { class: 'acoes' }, diagBotao)),
    secaoPessoasPainel(),
    el('section', { class: 'secao', id: 'secao-email' }, el('h3', {}, 'E-mail'), emailArea),
    el('section', { class: 'secao' }, el('h3', {}, 'Configurações'), configArea),
    secaoSerpro(),
    el('section', { class: 'secao' }, el('h3', {}, 'Últimas ações nesta tela'), historicoArea),
  ]);
  recarregar();
  carregarBanco();
  carregarConfiguracoes();
  carregarEmail();
  const focos = { email: 'secao-email', pessoas: 'secao-pessoas', banco: 'secao-banco', serpro: 'secao-serpro' };
  if (focos[opcoes.focar]) setTimeout(() => $(focos[opcoes.focar])?.scrollIntoView({ block: 'start' }), 50);
  clearInterval(relogioSistema);
  relogioSistema = setInterval(() => { if ($('modal').open) recarregar(); else clearInterval(relogioSistema); }, 5000);
}

// ==========================================================================
// Inicio
// ==========================================================================

// ==========================================================================
// Ambiente de emissao (tela Sistema): homologacao ou PRODUCAO
// So quem entra no painel admin troca (o servidor confere em ambiente.php).
// ==========================================================================

function secaoAmbiente() {
  const area = el('div', {}, el('p', { class: 'suave' }, 'Carregando...'));

  function desenhar(a, mensagem) {
    const producao = a.atual === 1;
    const retorno = el('div', { 'aria-live': 'polite' }, mensagem ? el('p', { class: 'envio-ok' }, '✓ ' + mensagem) : null);
    const filhos = [
      el('div', { class: 'ambiente-atual' },
        el('span', { class: 'suave' }, 'Agora:'),
        el('span', { class: 'ambiente-atual__nome' + (producao ? ' ambiente-atual__nome--producao' : '') },
          producao ? '1 - Produção (notas com valor fiscal)' : '2 - Homologação (testes, sem valor fiscal)')),
      el('p', { class: 'suave' }, 'Vale para o sistema todo: NF-e e NFS-e, API, portal das empresas e worker. '
        + 'Homologação e produção têm numeração separada.'),
    ];

    if (!producao) {
      // Antes de ligar: o que ainda falta nas empresas (so avisa, nao impede).
      const semCertificado = estado.clientes.filter((c) => !c.tem_certificado && c.situacao === 'LIBERADA');
      const palavra = el('input', { type: 'text', autocomplete: 'off', placeholder: 'PRODUÇÃO', 'aria-label': 'Digite PRODUÇÃO para confirmar' });
      const ligar = el('button', { class: 'botao botao--perigo', type: 'button', disabled: true }, 'Ligar produção');
      palavra.addEventListener('input', () => {
        ligar.disabled = !/^PRODU[CÇ][AÃ]O$/i.test(palavra.value.trim());
      });
      ligar.addEventListener('click', async () => {
        ligar.disabled = true;
        try {
          const r = await api('ambiente.php', { metodo: 'POST', corpo: { tpAmb: 1, confirmacao: palavra.value } });
          desenhar(r.ambiente, r.mensagem);
          carregar();
        } catch (e) {
          retorno.replaceChildren(el('p', { class: 'erro' }, e.message));
          ligar.disabled = false;
        }
      });
      filhos.push(el('div', { class: 'ambiente-confirmar' },
        el('strong', {}, 'Passar para produção'),
        el('p', {}, 'Depois disso, toda nota emitida vale de verdade (vai para a prefeitura e para a SEFAZ). Para confirmar, digite ',
          el('strong', {}, 'PRODUÇÃO'), ' abaixo.'),
        semCertificado.length ? el('p', { class: 'aviso' }, `Atenção: ${semCertificado.length} empresa(s) liberada(s) ainda sem certificado: `
          + semCertificado.map((c) => c.nome_fantasia || c.razao_social).join(', ') + '. As notas delas serão recusadas até enviar o certificado.') : null,
        el('div', { class: 'acoes' }, palavra, ligar)));
    } else {
      const voltar = el('button', { class: 'botao', type: 'button' }, 'Voltar para homologação (testes)');
      voltar.addEventListener('click', async () => {
        if (!confirm('Voltar para HOMOLOGAÇÃO? As próximas notas serão só de teste, sem valor fiscal.')) return;
        voltar.disabled = true;
        try {
          const r = await api('ambiente.php', { metodo: 'POST', corpo: { tpAmb: 2 } });
          desenhar(r.ambiente, r.mensagem);
          carregar();
        } catch (e) {
          retorno.replaceChildren(el('p', { class: 'erro' }, e.message));
          voltar.disabled = false;
        }
      });
      filhos.push(el('div', { class: 'acoes' }, voltar));
    }
    filhos.push(modeloDaReceita(a, retorno));
    filhos.push(retorno);
    area.replaceChildren(...filhos);
  }

  // Modelo da nota NFS-e Nacional na producao: a Receita troca um dia, e aqui
  // a gente avisa o sistema (antes era uma linha no config/nfse.php).
  function modeloDaReceita(a, retorno) {
    const novo = a.producao_modelo_novo;
    const trocar = el('button', { class: 'botao', type: 'button' },
      novo ? 'Voltar a produção para o modelo antigo' : 'A Receita mudou: usar o modelo novo na produção');
    trocar.addEventListener('click', async () => {
      const pergunta = novo
        ? 'Voltar a PRODUÇÃO para o modelo ANTIGO (sem finNFSe)? Use só se as notas de produção estiverem sendo recusadas por causa do finNFSe.'
        : 'Passar a PRODUÇÃO para o modelo NOVO (com finNFSe)? Faça isso quando a Receita avisar a mudança, ou quando as notas de produção voltarem com E1235 pedindo finNFSe.';
      if (!confirm(pergunta)) return;
      trocar.disabled = true;
      try {
        const r = await api('ambiente.php', { metodo: 'POST', corpo: { producao_modelo_novo: !novo } });
        desenhar(r.ambiente, r.mensagem);
      } catch (e) {
        retorno.replaceChildren(el('p', { class: 'erro' }, e.message));
        trocar.disabled = false;
      }
    });
    return el('div', { class: 'ambiente-modelo' },
      el('strong', {}, 'Modelo da nota NFS-e Nacional na produção'),
      el('p', {}, 'Agora: ', el('strong', {}, novo ? 'NOVO (de 20/10/2026, com finNFSe)' : 'ANTIGO (sem finNFSe)'),
        '. A homologação já usa o modelo novo.'),
      el('p', { class: 'suave' }, novo
        ? 'Se as notas de produção voltarem com o erro E1235 recusando o finNFSe, a produção ainda está no modelo antigo: volte pelo botão abaixo.'
        : 'Quando a Receita mudar a produção, as notas de produção voltam com o erro E1235 pedindo o finNFSe: aí é só clicar no botão abaixo e reenviar.'),
      el('div', { class: 'acoes' }, trocar));
  }

  api('ambiente.php').then((a) => desenhar(a)).catch((e) => area.replaceChildren(el('p', { class: 'erro' }, e.message)));
  return el('section', { class: 'secao', id: 'secao-ambiente' }, el('h3', {}, 'Ambiente de emissão'), area);
}

// ==========================================================================
// Pessoas do painel (tela Sistema) e "Minha senha"
// ==========================================================================

function secaoPessoasPainel() {
  const lista = el('div', {}, el('p', { class: 'suave' }, 'Carregando...'));
  const area = el('div', { 'aria-live': 'polite' });
  const nome = el('input', { type: 'text', placeholder: 'Nome da pessoa', autocomplete: 'off' });
  const email = el('input', { type: 'email', placeholder: 'email@empresa.com.br', autocomplete: 'off' });
  const porEmail = el('input', { type: 'checkbox', checked: true });
  const mensagem = el('span', {});
  const linkPainel = new URL('./', window.location.href).href;

  // Senha provisoria: aparece uma vez so, com "Enviar por e-mail" e "Copiar".
  function mostrarSenha(r, enviarJa) {
    const texto = `Painel: ${linkPainel}\nE-mail: ${r.email}\nSenha provisória: ${r.senha_provisoria}`;
    const resultado = el('div', { 'aria-live': 'polite' });
    const enviar = el('button', { class: 'botao botao--forte', type: 'button' }, `✉ Enviar por e-mail para ${r.email}`);
    const copiar = el('button', { class: 'botao', type: 'button', onclick: async () => {
      try { await navigator.clipboard.writeText(texto); copiar.textContent = '✓ Copiado'; } catch { copiar.textContent = 'Selecione o texto e copie'; }
    } }, 'Copiar dados de acesso');
    enviar.addEventListener('click', async () => {
      enviar.disabled = true;
      enviar.textContent = 'Enviando... (até 20 s)';
      try {
        const e = await api('pessoas.php', { metodo: 'POST', corpo: { acao: 'enviar_acesso', id: r.id, senha: r.senha_provisoria, link_painel: linkPainel } });
        resultado.replaceChildren(el('p', { class: 'envio-ok' }, `✓ E-mail enviado para ${e.email}. Peça para a pessoa olhar também a caixa de spam.`));
        enviar.textContent = 'Enviar de novo';
      } catch (e) {
        resultado.replaceChildren(el('div', { class: e.codigo === 'EMAIL_NAO_CONFIGURADO' ? 'envio-aviso' : '' },
          el('p', { class: e.codigo === 'EMAIL_NAO_CONFIGURADO' ? '' : 'erro' }, e.codigo === 'EMAIL_NAO_CONFIGURADO'
            ? 'O e-mail do sistema ainda não foi configurado (bloco "E-mail", abaixo). Por enquanto, copie os dados e mande pelo WhatsApp.' : e.message)));
        enviar.textContent = `✉ Tentar de novo para ${r.email}`;
      }
      enviar.disabled = false;
    });
    area.replaceChildren(el('div', { class: 'token-box acesso-box' },
      el('strong', { class: 'acesso-box__titulo' }, `Acesso pronto para ${r.email}`),
      el('p', { class: 'suave' }, 'A senha só aparece aqui agora. No primeiro login o painel pede para a pessoa criar a própria senha.'),
      el('pre', { class: 'exemplo mono' }, texto),
      el('div', { class: 'acoes' }, enviar, copiar),
      resultado));
    if (enviarJa) enviar.click();
  }

  async function acao(corpo, confirmar, enviarJa = false) {
    if (confirmar && !confirm(confirmar)) return;
    try {
      const r = await api('pessoas.php', { metodo: 'POST', corpo });
      if (r.senha_provisoria) mostrarSenha(r, enviarJa); else area.replaceChildren(el('p', { class: 'ok-texto' }, r.mensagem));
      await recarregar();
    } catch (e) {
      area.replaceChildren(el('p', { class: 'erro' }, e.message));
    }
  }

  async function recarregar() {
    try {
      const r = await api('pessoas.php');
      if (!r.pessoas.length) {
        lista.replaceChildren(el('p', { class: 'aviso' }, 'Ninguém cadastrado ainda: hoje só a chave de emergência entra. Cadastre você primeiro.'));
        return;
      }
      lista.replaceChildren(el('table', { class: 'mini' },
        el('thead', {}, el('tr', {}, el('th', {}, 'Nome'), el('th', {}, 'E-mail'), el('th', {}, 'Último acesso'), el('th', {}, 'Situação'), el('th', {}, ''))),
        el('tbody', {}, ...r.pessoas.map((p) => el('tr', {},
          el('td', {}, p.nome, p.id === r.eu ? el('span', { class: 'suave' }, ' (você)') : null),
          el('td', {}, p.email),
          el('td', {}, p.ultimo_acesso ? dataHora(p.ultimo_acesso) : 'nunca entrou'),
          el('td', {}, !p.ativo ? el('span', { class: 'ruim' }, 'Desativada') : (p.bloqueado ? el('span', { class: 'aviso' }, 'Bloqueada (senhas erradas)')
            : (p.trocar_senha ? 'Aguardando 1º acesso' : 'Ativa'))),
          el('td', {},
            p.ativo && p.id !== r.eu ? el('button', { class: 'botao botao--forte', type: 'button', title: 'Gera uma senha provisória nova e manda por e-mail',
              onclick: () => acao({ acao: 'redefinir_senha', id: p.id }, `Enviar o acesso ao painel para ${p.email}?\n\nO sistema gera uma senha provisória nova e manda por e-mail. A senha atual dessa pessoa para de funcionar.`, true) },
            '✉ Enviar acesso por e-mail') : null, ' ',
            p.id !== r.eu ? el('button', { class: 'botao' + (p.ativo ? ' botao--perigo' : ''), type: 'button',
              onclick: () => acao({ acao: p.ativo ? 'desativar' : 'ativar', id: p.id }, p.ativo ? `Desativar o acesso de ${p.email} ao painel?` : null) },
            p.ativo ? 'Desativar' : 'Reativar') : el('button', { class: 'botao', type: 'button', onclick: () => abrirMinhaSenha() }, 'Trocar minha senha')))))));
    } catch (e) {
      lista.replaceChildren(el('p', { class: e.message.includes('Atualizar banco') ? 'aviso' : 'erro' }, e.message));
    }
  }

  const botao = el('button', { class: 'botao botao--forte', type: 'button' }, 'Cadastrar pessoa');
  botao.addEventListener('click', async () => {
    mensagem.textContent = '';
    if (nome.value.trim().length < 2 || !email.checkValidity() || !email.value) {
      mensagem.className = 'erro';
      mensagem.textContent = 'Informe o nome e um e-mail válido.';
      return;
    }
    botao.disabled = true;
    await acao({ acao: 'criar', nome: nome.value, email: email.value }, null, porEmail.checked);
    if (area.querySelector('.acesso-box')) { nome.value = ''; email.value = ''; }
    botao.disabled = false;
  });

  recarregar();
  return el('section', { class: 'secao', id: 'secao-pessoas' },
    el('h3', {}, 'Pessoas do painel'),
    el('p', { class: 'suave' }, 'Quem entra neste painel. Cada pessoa usa o próprio e-mail e senha, e o histórico mostra quem fez cada mudança.'),
    lista,
    el('div', { class: 'campos' }, el('label', {}, 'Nome', nome), el('label', {}, 'E-mail', email)),
    el('label', { class: 'opcao-linha' }, porEmail, ' Enviar os dados de acesso por e-mail para a pessoa'),
    el('div', { class: 'acoes' }, botao, mensagem),
    area);
}

/** Janela "Minha senha" (botao com o nome, no topo). */
function abrirMinhaSenha() {
  const atual = el('input', { type: 'password', autocomplete: 'current-password' });
  const nova = el('input', { type: 'password', autocomplete: 'new-password' });
  const repetir = el('input', { type: 'password', autocomplete: 'new-password' });
  const retorno = el('div', {});
  const salvar = el('button', { class: 'botao botao--forte', type: 'button' }, 'Trocar senha');
  salvar.addEventListener('click', async () => {
    if (nova.value !== repetir.value) { retorno.replaceChildren(el('p', { class: 'erro' }, 'As duas senhas novas não são iguais.')); return; }
    salvar.disabled = true;
    try {
      const r = await api('minha_senha.php', { metodo: 'POST', corpo: { atual: atual.value, nova: nova.value } });
      atual.value = nova.value = repetir.value = '';
      retorno.replaceChildren(el('p', { class: 'envio-ok' }, '✓ ' + r.mensagem + ' Os seus logins em outros computadores foram encerrados.'));
    } catch (e) {
      retorno.replaceChildren(el('p', { class: 'erro' }, e.message));
    }
    salvar.disabled = false;
  });
  abrirModal('Minha senha', estado.pessoa ? `${estado.pessoa.nome} · ${estado.pessoa.email}` : '', [
    el('section', { class: 'secao' },
      el('div', { class: 'campos' },
        el('label', {}, 'Senha atual', atual), el('label', {}, 'Nova senha', nova), el('label', {}, 'Repita a nova senha', repetir)),
      el('p', { class: 'suave' }, 'Pelo menos 8 caracteres, com letras e números.'),
      el('div', { class: 'acoes' }, salvar),
      retorno),
  ]);
}

// ---- Tela de login ----
$('trocar-modo-acesso').addEventListener('click', () => {
  estado.modoChave = !estado.modoChave;
  $('acesso-pessoa').hidden = estado.modoChave;
  $('acesso-chave').hidden = !estado.modoChave;
  $('acesso-explica').textContent = estado.modoChave
    ? 'Chave de emergência: o admin_token do arquivo config/nfse.local.php. Use para cadastrar a primeira pessoa ou se ninguém conseguir entrar.'
    : 'Entre com o seu e-mail e a sua senha.';
  $('trocar-modo-acesso').textContent = estado.modoChave ? 'Entrar com e-mail e senha' : 'Entrar com a chave de emergência';
  $('erro-acesso').hidden = true;
  (estado.modoChave ? $('campo-token') : $('campo-email')).focus();
});

$('form-acesso').addEventListener('submit', async (evento) => {
  evento.preventDefault();
  const erro = $('erro-acesso');
  erro.hidden = true;
  const lembrar = $('campo-lembrar').checked;
  try {
    let token;
    if (estado.modoChave) {
      token = $('campo-token').value.trim();
      if (!token) throw new Error('Cole a chave de emergência.');
      const teste = await fetch(API + 'entrar.php', { headers: { 'X-ADMIN-TOKEN': token } });
      if (teste.status === 401) throw new Error('Chave de emergência incorreta.');
    } else {
      const email = $('campo-email').value.trim();
      const senha = $('campo-senha').value;
      if (!email || !senha) throw new Error('Informe o e-mail e a senha.');
      const resposta = await fetch(API + 'entrar.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, senha, lembrar }) });
      const json = await resposta.json().catch(() => ({}));
      if (json.erro?.codigo === 'PAINEL_SEM_PESSOAS' && !estado.modoChave) $('trocar-modo-acesso').click();
      if (!resposta.ok) throw new Error(json.erro?.mensagem || `HTTP ${resposta.status}`);
      token = json.token;
      estado.senhaDigitada = senha;
    }
    salvarToken(token, lembrar);
    await entrar(token);
  } catch (e) {
    if (e.message === 'nao autorizado') return;
    // Chave errada: api() ja voltou para esta tela; mostra o motivo.
    erro.textContent = e.message;
    erro.hidden = false;
  }
});

$('form-nova-senha').addEventListener('submit', async (evento) => {
  evento.preventDefault();
  const erro = $('erro-nova-senha');
  erro.hidden = true;
  const nova = $('campo-senha-nova').value;
  if (nova !== $('campo-senha-repetir').value) { erro.textContent = 'As duas senhas novas não são iguais.'; erro.hidden = false; return; }
  try {
    await api('minha_senha.php', { metodo: 'POST', corpo: { atual: $('campo-senha-atual').value, nova } });
    estado.senhaDigitada = '';
    for (const id of ['campo-senha-atual', 'campo-senha-nova', 'campo-senha-repetir']) $(id).value = '';
    $('form-nova-senha').hidden = true;
    $('form-acesso').hidden = false;
    await entrar(estado.token);
  } catch (e) {
    if (e.message === 'nao autorizado') return;
    // Senha provisoria errada: mostra o campo para digitar de novo.
    if (/atual/i.test(e.message)) { $('nova-senha-atual').hidden = false; estado.senhaDigitada = ''; }
    erro.textContent = e.message;
    erro.hidden = false;
  }
});
$('nova-senha-sair').addEventListener('click', () => sair());
$('botao-eu').addEventListener('click', () => abrirMinhaSenha());
$('botao-sair').addEventListener('click', () => sair());
$('botao-nova').addEventListener('click', novaEmpresa);
$('botao-relatorio').addEventListener('click', () => abrirRelatorio());
$('botao-fechamento').addEventListener('click', () => abrirFechamento());
$('botao-sistema').addEventListener('click', () => abrirSistema());
$('modal-fechar').addEventListener('click', fecharModal);
$('modal').addEventListener('cancel', (e) => { if (!podeFecharModal()) e.preventDefault(); });
fecharAoClicarFora($('modal'), fecharModal);
$('filtro-busca').addEventListener('input', desenhar);
$('filtro-situacao').addEventListener('change', (e) => { estado.filtroSituacao = e.target.value; desenhar(); });

(async () => {
  const salvo = lerTokenSalvo();
  if (!salvo) { sair(); return; }
  try { await entrar(salvo); } catch (e) { if (e.message !== 'nao autorizado') sair(e.message); }
  $('campo-email').focus();
})();
