/* ==========================================================================
   Monitor Fiscal (NFS-e e NF-e)
   --------------------------------------------------------------------------
   Como funciona:
     1. O usuario informa o token do painel (monitor_token).
     2. Abrimos o stream em tempo real (SSE) em /v1/nfse/monitor.php.
     3. Carregamos as notas mais recentes em /v1/nfse/documentos.php.
     4. A cada evento do stream, buscamos a nota atualizada e redesenhamos
        a linha. O navegador reconecta o stream sozinho se cair.
     5. Clique numa linha abre o modal com dados, rejeicao + sugestao de
        correcao, eventos e linha do tempo.

   Seguranca: todo texto vindo da API e inserido com textContent (funcao el),
   nunca com innerHTML, para evitar injecao de HTML.
   ========================================================================== */
'use strict';

const API = new URL('../v1/nfse/', window.location.href).href;
const LIMITE_NOTAS = 500;

/** Nome e cor de cada status padronizado da API. */
const STATUS = {
  RECEBIDO: { nome: 'Recebido', cor: 'var(--st-recebido)' },
  PROCESSANDO: { nome: 'Processando', cor: 'var(--st-processando)' },
  AUTORIZADO: { nome: 'Autorizado', cor: 'var(--st-autorizado)' },
  REJEITADO: { nome: 'Rejeitado', cor: 'var(--st-rejeitado)' },
  DENEGADO: { nome: 'Denegado', cor: 'var(--st-denegado)' },
  ERRO: { nome: 'Erro', cor: 'var(--st-erro)' },
  CANCELADO: { nome: 'Cancelado', cor: 'var(--st-cancelado)' },
  SUBSTITUIDO: { nome: 'Substituído', cor: 'var(--st-substituido)' },
};

/** Nome amigavel de cada padrao (tipo de documento). */
const PADROES = {
  NFE: 'NF-e',
  NACIONAL: 'NFS-e Nacional',
  SALVADOR: 'NFS-e Salvador',
};

const OPERACOES = {
  RECEBIMENTO: 'Recebimento',
  EMISSAO: 'Emissão',
  CONSULTA: 'Consulta',
  EVENTO: 'Evento',
};

/**
 * Sugestoes para mensagens que nao trazem "correcao".
 * A chave e o codigo da mensagem (vindo da API ou do fisco).
 */
const SUGESTOES_PADRAO = {
  XSD: 'O XML não passou no schema oficial. Normalmente é um campo com tamanho ou formato fora do padrão: confira o campo citado na mensagem no cadastro do sistema de origem.',
  FALHA_TEMPORARIA: 'Falha técnica de comunicação. A API tenta de novo sozinha. Se persistir, verifique o certificado digital do emitente e se o serviço do fisco está no ar.',
  DPS_NAO_LOCALIZADA: 'O Sefin não recebeu esta DPS. É seguro reenviar com a mesma referência.',
  RPS_NAO_LOCALIZADO: 'A prefeitura não localizou este RPS. É seguro reenviar com a mesma referência.',
  NAO_SUPORTADO_PELO_PADRAO: 'Esta operação não existe neste padrão. Veja a orientação na mensagem.',
};
const SUGESTAO_GENERICA = 'O fisco não informou sugestão. Confira no cadastro do sistema de origem o dado citado na mensagem e reenvie com a mesma referência.';

const estado = {
  token: '',
  documentos: new Map(), // id -> documento (formato da API)
  emitentes: new Map(), // cnpj -> razao social
  recentes: new Set(), // ids atualizados agora (para destacar a linha)
  ultimaAcao: new Map(), // id -> ultima linha do monitor recebida ao vivo
  filtro: { status: '', padrao: '', emitente: '', busca: '' },
  fonte: null, // EventSource
  teveErro: false,
  modalId: null,
};

const $ = (id) => document.getElementById(id);

// ==========================================================================
// Utilitarios
// ==========================================================================

/** Cria elemento de forma segura: el('td', { class: 'x' }, 'texto', outroEl) */
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

const formatoData = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'medium' });
function data(texto) {
  if (!texto) return '—';
  const d = new Date(String(texto).replace(' ', 'T').replace(/\+00$/, 'Z'));
  return isNaN(d) ? texto : formatoData.format(d);
}

function cnpj(valor) {
  const v = String(valor || '');
  return /^\d{14}$/.test(v) ? v.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5') : v;
}

function nomeEmitente(valor) {
  return estado.emitentes.get(String(valor)) || cnpj(valor);
}

function seloStatus(status) {
  const s = STATUS[status] || { nome: status, cor: 'var(--suave)' };
  return el('span', { class: `status status--${status}`, style: `--cor:${s.cor}` }, s.nome);
}

function temProblema(doc) {
  return ['REJEITADO', 'ERRO', 'DENEGADO'].includes(doc.status) && (doc.mensagens || []).length > 0;
}

// ==========================================================================
// Comunicacao com a API
// ==========================================================================

async function api(caminho, formato = 'json') {
  const resposta = await fetch(API + caminho, { headers: { 'X-NFE-API-TOKEN': estado.token } });
  if (resposta.status === 401) {
    sair('Token inválido ou expirado.');
    throw new Error('nao autorizado');
  }
  if (formato === 'blob') {
    if (!resposta.ok) throw new Error((await resposta.json().catch(() => ({}))).erro?.mensagem || `HTTP ${resposta.status}`);
    return resposta.blob();
  }
  const corpo = await resposta.json().catch(() => ({}));
  if (!resposta.ok) throw new Error(corpo.erro?.mensagem || `HTTP ${resposta.status}`);
  return corpo;
}

async function carregarDocumentos() {
  const { documentos } = await api(`documentos.php?limite=${LIMITE_NOTAS}`);
  estado.documentos.clear();
  for (const doc of documentos) estado.documentos.set(doc.id, doc);
  renderizar();
}

/** Busca uma nota (chamado a cada evento do stream). */
const buscando = new Map();
function atualizarDocumento(id) {
  if (buscando.has(id)) return; // ja existe busca em andamento para esta nota
  buscando.set(id, setTimeout(async () => {
    try {
      const { documento } = await api(`documento.php?id=${encodeURIComponent(id)}&xml=0`);
      estado.documentos.set(id, documento);
      estado.recentes.add(id);
      renderizar();
      if (estado.modalId === id) abrirModal(id, false);
    } catch (e) {
      console.warn('Falha ao atualizar nota', id, e);
    } finally {
      buscando.delete(id);
    }
  }, 250));
}

// ==========================================================================
// Tempo real (SSE)
// ==========================================================================

function conectarStream() {
  if (estado.fonte) estado.fonte.close();

  // desde=agora: so eventos novos (o estado atual vem de documentos.php).
  // Em reconexoes o navegador envia Last-Event-ID e nada se perde.
  const url = `${API}monitor.php?desde=agora&token=${encodeURIComponent(estado.token)}`;
  const fonte = new EventSource(url);
  estado.fonte = fonte;

  fonte.addEventListener('open', () => {
    mostrarConexao('ligada', 'Ao vivo');
    if (estado.teveErro) {
      // Voltou depois de queda longa: recarrega para garantir consistencia.
      estado.teveErro = false;
      carregarDocumentos().catch(() => {});
    }
  });

  fonte.addEventListener('nfse', (evento) => {
    const linha = JSON.parse(evento.data);
    estado.ultimaAcao.set(linha.documento_id, linha);
    atualizarDocumento(linha.documento_id);
  });

  fonte.addEventListener('error', () => {
    estado.teveErro = true;
    if (fonte.readyState === EventSource.CLOSED) mostrarConexao('desligada', 'Desconectado');
    else mostrarConexao('reconectando', 'Reconectando…');
  });
}

function mostrarConexao(tipo, texto) {
  $('conexao').className = `conexao conexao--${tipo}`;
  $('conexao-texto').textContent = texto;
}

// ==========================================================================
// Lista e contadores
// ==========================================================================

function passaNoFiltro(doc, ignorarStatus = false) {
  const f = estado.filtro;
  if (!ignorarStatus && f.status && doc.status !== f.status) return false;
  if (f.padrao && doc.padrao !== f.padrao) return false;
  if (f.emitente && doc.emitente_cnpj !== f.emitente) return false;
  if (f.busca) {
    const alvo = [doc.referencia, doc.nfse?.numero, doc.nfse?.chave_acesso, doc.dps_rps?.numero]
      .filter(Boolean).join(' ').toLowerCase();
    if (!alvo.includes(f.busca.toLowerCase())) return false;
  }
  return true;
}

function renderizar() {
  const todos = [...estado.documentos.values()]
    .sort((a, b) => String(b.atualizado_em).localeCompare(String(a.atualizado_em)));

  renderizarContadores(todos.filter((d) => passaNoFiltro(d, true)));

  const visiveis = todos.filter((d) => passaNoFiltro(d));
  const corpo = $('linhas');
  corpo.replaceChildren(...visiveis.map(linhaDocumento));
  $('vazio').hidden = visiveis.length > 0;
  estado.recentes.clear();
}

function linhaDocumento(doc) {
  const problema = temProblema(doc);
  const ultima = (doc.mensagens || [])[0];
  const classes = [problema && 'linha--problema', estado.recentes.has(doc.id) && 'linha--nova'].filter(Boolean).join(' ');

  return el('tr', {
    class: classes || null,
    'data-id': doc.id,
    onclick: () => abrirModal(doc.id),
    onmousemove: problema ? (e) => mostrarDica(e, doc) : null,
    onmouseleave: problema ? esconderDica : null,
  },
    el('td', {}, data(doc.atualizado_em)),
    el('td', { class: 'mono' }, doc.referencia),
    el('td', {}, PADROES[doc.padrao] || doc.padrao),
    el('td', {}, nomeEmitente(doc.emitente_cnpj)),
    el('td', { class: 'mono' }, doc.dps_rps ? `${doc.dps_rps.serie}/${doc.dps_rps.numero}` : '—'),
    el('td', { class: 'mono' }, doc.nfse?.numero || '—'),
    el('td', {}, seloStatus(doc.status), problema ? el('span', { class: 'alerta', title: 'Passe o mouse para ver o motivo' }, '⚠') : null),
    el('td', { class: 'acao' }, textoUltimaAcao(doc, ultima)),
  );
}

/** Problema (rejeicao/erro) tem prioridade; senao, o ultimo evento ao vivo. */
function textoUltimaAcao(doc, ultimaMensagem) {
  if (temProblema(doc) || (ultimaMensagem && !estado.ultimaAcao.has(doc.id))) {
    return `${ultimaMensagem.codigo ? ultimaMensagem.codigo + ' – ' : ''}${ultimaMensagem.mensagem}`;
  }
  const aoVivo = estado.ultimaAcao.get(doc.id);
  if (aoVivo) {
    return `${OPERACOES[aoVivo.operacao] || aoVivo.operacao}${aoVivo.mensagem ? ': ' + aoVivo.mensagem : ''}`;
  }
  if (doc.status === 'AUTORIZADO') return `${doc.padrao === 'NFE' ? 'NF-e' : 'NFS-e'} ${doc.nfse?.numero || ''} autorizada`.replace('  ', ' ');
  return '—';
}

function renderizarContadores(documentos) {
  const totais = {};
  for (const doc of documentos) totais[doc.status] = (totais[doc.status] || 0) + 1;

  const cartoes = Object.entries(STATUS).map(([codigo, s]) => el('button', {
    class: `contador${estado.filtro.status === codigo ? ' contador--ativo' : ''}`,
    style: `--cor:${s.cor}`,
    title: 'Clique para filtrar',
    onclick: () => {
      estado.filtro.status = estado.filtro.status === codigo ? '' : codigo;
      $('filtro-status').value = estado.filtro.status;
      renderizar();
    },
  }, el('span', { class: 'contador__valor' }, totais[codigo] || 0), el('span', { class: 'contador__nome' }, s.nome)));

  $('contadores').replaceChildren(...cartoes);
}

// ==========================================================================
// Dica (passar o mouse em nota rejeitada)
// ==========================================================================

function mostrarDica(evento, doc) {
  const m = doc.mensagens[0];
  const dica = $('dica');
  dica.replaceChildren(
    el('strong', {}, { ERRO: 'Falha técnica', DENEGADO: 'Motivo da denegação' }[doc.status] || 'Motivo da rejeição'),
    `${m.codigo ? m.codigo + ' – ' : ''}${m.mensagem}`,
    el('em', {}, '💡 ', m.correcao || SUGESTOES_PADRAO[m.codigo] || SUGESTAO_GENERICA),
    doc.mensagens.length > 1 ? el('em', {}, `+ ${doc.mensagens.length - 1} mensagem(ns). Clique para ver tudo.`) : null,
  );
  dica.hidden = false;
  const x = Math.min(evento.clientX + 14, window.innerWidth - dica.offsetWidth - 10);
  const y = Math.min(evento.clientY + 14, window.innerHeight - dica.offsetHeight - 10);
  dica.style.left = `${x}px`;
  dica.style.top = `${y}px`;
}

function esconderDica() {
  $('dica').hidden = true;
}

// ==========================================================================
// Modal de detalhes
// ==========================================================================

async function abrirModal(id, mostrarCarregando = true) {
  estado.modalId = id;
  esconderDica();
  const modal = $('modal');
  if (mostrarCarregando) {
    $('modal-titulo').textContent = 'Carregando…';
    $('modal-status').replaceChildren();
    $('modal-corpo').replaceChildren();
    if (!modal.open) modal.showModal();
  }

  try {
    const { documento: doc } = await api(`documento.php?id=${encodeURIComponent(id)}&historico=1&xml=0`);
    if (estado.modalId !== id) return;
    $('modal-titulo').textContent = `Nota ${doc.referencia}`;
    $('modal-status').replaceChildren(seloStatus(doc.status));
    $('modal-corpo').replaceChildren(...conteudoModal(doc));
  } catch (e) {
    $('modal-corpo').replaceChildren(el('p', { class: 'erro' }, `Não foi possível carregar: ${e.message}`));
  }
}

function conteudoModal(doc) {
  const partes = [];

  // --- Mensagens (rejeicao / erro / alertas) ---
  const mensagens = doc.mensagens || [];
  if (mensagens.length) {
    const titulo = { REJEITADO: 'Motivo da rejeição', ERRO: 'Falha técnica', DENEGADO: 'Motivo da denegação' }[doc.status] || 'Mensagens';
    partes.push(el('h3', {}, titulo));
    for (const m of mensagens) {
      const problema = ['REJEITADO', 'ERRO', 'DENEGADO'].includes(doc.status);
      partes.push(el('div', { class: `mensagem${problema ? '' : ' mensagem--info'}` },
        el('div', { class: 'mensagem__topo' },
          m.codigo ? el('span', { class: 'mensagem__codigo mono' }, m.codigo) : null,
          m.campo ? el('span', { class: 'mensagem__campo' }, `campo: ${m.campo}`) : null),
        el('p', { class: 'mensagem__texto' }, m.mensagem),
        problema ? el('div', { class: 'correcao' },
          el('strong', {}, 'Como corrigir: '),
          m.correcao || SUGESTOES_PADRAO[m.codigo] || SUGESTAO_GENERICA) : null,
      ));
    }
    if (doc.pode_reenviar) {
      partes.push(el('p', { class: 'suave' },
        `Depois de corrigir no sistema de origem, reenvie com a mesma referência (${doc.referencia}). `
        + (doc.padrao === 'NFE'
          ? (doc.dps_rps ? 'O número da NF-e será reaproveitado.' : 'O número da NF-e será reservado no reenvio.')
          : (doc.dps_rps ? 'O número da DPS/RPS será reaproveitado.' : 'O número da DPS/RPS será reservado no reenvio.'))));
    }
  }

  // --- Dados ---
  partes.push(el('h3', {}, 'Dados'));
  const campos = [
    ['Referência', doc.referencia],
    ['Padrão', PADROES[doc.padrao] || doc.padrao],
    ['Emitente', `${nomeEmitente(doc.emitente_cnpj)} (${cnpj(doc.emitente_cnpj)})`],
    ['Ambiente', doc.ambiente === 'PRODUCAO' ? 'Produção' : 'Homologação'],
    [doc.padrao === 'NFE' ? 'Série / número' : 'DPS/RPS', doc.dps_rps ? `Série ${doc.dps_rps.serie} – Nº ${doc.dps_rps.numero}` : 'Sem número (rejeitado na entrada)'],
    [doc.padrao === 'NFE' ? 'NF-e nº' : 'NFS-e nº', doc.nfse?.numero],
    ['Protocolo', doc.nfse?.protocolo],
    ['Chave de acesso', doc.nfse?.chave_acesso],
    ['Código de verificação', doc.nfse?.codigo_verificacao],
    ['Autorizada em', doc.nfse?.data_autorizacao ? data(doc.nfse.data_autorizacao) : null],
    ['Recebida em', data(doc.criado_em)],
    ['Atualizada em', data(doc.atualizado_em)],
    ['Lote', doc.lote_id],
  ].filter(([, v]) => v);
  partes.push(el('dl', { class: 'grade' }, campos.map(([n, v]) => el('div', {}, el('dt', {}, n), el('dd', {}, v)))));

  // --- Eventos ---
  if ((doc.eventos || []).length) {
    partes.push(el('h3', {}, 'Eventos'));
    for (const ev of doc.eventos) {
      partes.push(el('div', { class: 'mensagem mensagem--info' },
        el('div', { class: 'mensagem__topo' },
          el('span', { class: 'mensagem__codigo mono' }, ev.tipo_evento),
          el('span', {}, ev.status),
          ev.origem === 'EXTERNO' ? el('span', { class: 'mensagem__campo' }, `registrado manualmente – processo ${ev.numero_processo}`) : null),
        el('p', { class: 'mensagem__texto' }, ev.motivo_texto || ''),
        ...(ev.mensagens || []).map((m) => el('p', { class: 'suave' }, `${m.codigo || ''} ${m.mensagem}`)),
      ));
    }
  }

  // --- Linha do tempo ---
  if ((doc.historico || []).length) {
    partes.push(el('h3', {}, 'Linha do tempo'));
    partes.push(el('ul', { class: 'linha-tempo' }, [...doc.historico].reverse().map((h) => el('li', {
      style: `--cor:${(STATUS[h.status] || {}).cor || 'var(--borda)'}`,
    },
      el('time', {}, data(h.criado_em)), ' · ',
      el('strong', {}, OPERACOES[h.operacao] || h.operacao), ' → ', STATUS[h.status]?.nome || h.status,
      h.mensagem ? el('div', { class: 'suave' }, h.mensagem) : null,
    ))));
  }

  // --- Acoes ---
  const temNfse = ['AUTORIZADO', 'CANCELADO', 'SUBSTITUIDO', 'DENEGADO'].includes(doc.status);
  if (temNfse) {
    const prefixo = doc.padrao === 'NFE' ? 'nfe' : 'nfse';
    partes.push(el('div', { class: 'botoes' },
      el('button', { class: 'botao', onclick: () => baixar(`documento.php?id=${doc.id}&formato=xml`, `${prefixo}-${doc.nfse?.numero || doc.id}.xml`) }, 'Baixar XML'),
      // Nota denegada nao tem DANFE valido.
      doc.status !== 'DENEGADO' ? el('button', { class: 'botao', onclick: () => abrirPdf(doc) }, 'Abrir PDF') : null,
    ));
  }

  return partes;
}

async function baixar(caminho, nome) {
  try {
    const blob = await api(caminho, 'blob');
    const link = el('a', { href: URL.createObjectURL(blob), download: nome });
    document.body.append(link);
    link.click();
    link.remove();
  } catch (e) {
    alert(`Não foi possível baixar: ${e.message}`);
  }
}

async function abrirPdf(doc) {
  const janela = window.open('', '_blank');
  try {
    const blob = await api(`pdf.php?id=${doc.id}`, 'blob');
    janela.location.href = URL.createObjectURL(blob);
  } catch (e) {
    janela.close();
    alert(`PDF indisponível: ${e.message}`);
  }
}

// ==========================================================================
// Acesso, filtros e inicializacao
// ==========================================================================

function lerTokenSalvo() {
  try {
    return sessionStorage.getItem('nfse_monitor_token') || localStorage.getItem('nfse_monitor_token') || '';
  } catch {
    return '';
  }
}

function salvarToken(token, lembrar) {
  try {
    sessionStorage.setItem('nfse_monitor_token', token);
    if (lembrar) localStorage.setItem('nfse_monitor_token', token);
  } catch { /* navegador sem storage: segue so em memoria */ }
}

function sair(mensagem = '') {
  try {
    sessionStorage.removeItem('nfse_monitor_token');
    localStorage.removeItem('nfse_monitor_token');
  } catch { /* ignora */ }
  if (estado.fonte) estado.fonte.close();
  estado.token = '';
  $('tela-painel').hidden = true;
  $('tela-acesso').hidden = false;
  $('erro-acesso').hidden = !mensagem;
  $('erro-acesso').textContent = mensagem;
}

async function entrar(token) {
  estado.token = token;
  try {
    const capacidades = await api('capacidades.php');
    const selo = $('selo-ambiente');
    selo.textContent = capacidades.ambiente === 'PRODUCAO' ? 'PRODUÇÃO' : 'HOMOLOGAÇÃO';
    selo.className = `selo selo--${capacidades.ambiente === 'PRODUCAO' ? 'producao' : 'homologacao'}`;

    const { emitentes } = await api('emitentes.php');
    const filtroEmitente = $('filtro-emitente');
    filtroEmitente.replaceChildren(el('option', { value: '' }, 'Todos'));
    for (const e of emitentes) {
      estado.emitentes.set(String(e.cnpj), e.razao_social || cnpj(e.cnpj));
      filtroEmitente.append(el('option', { value: e.cnpj }, `${e.razao_social || ''} (${cnpj(e.cnpj)})`));
    }
  } catch (e) {
    if (estado.token) sair(`Não foi possível acessar a API: ${e.message}`);
    return false;
  }

  $('tela-acesso').hidden = true;
  $('tela-painel').hidden = false;
  conectarStream(); // primeiro o stream, depois a lista: nenhuma mudanca escapa
  await carregarDocumentos();
  return true;
}

function configurarTela() {
  const filtroStatus = $('filtro-status');
  for (const [codigo, s] of Object.entries(STATUS)) filtroStatus.append(el('option', { value: codigo }, s.nome));

  const ligarFiltro = (id, campo, eventoNome = 'change') => $(id).addEventListener(eventoNome, (e) => {
    estado.filtro[campo] = e.target.value.trim();
    renderizar();
  });
  ligarFiltro('filtro-status', 'status');
  ligarFiltro('filtro-padrao', 'padrao');
  ligarFiltro('filtro-emitente', 'emitente');
  ligarFiltro('filtro-busca', 'busca', 'input');

  $('form-acesso').addEventListener('submit', async (e) => {
    e.preventDefault();
    const token = $('campo-token').value.trim();
    if (await entrar(token)) salvarToken(token, $('campo-lembrar').checked);
  });
  $('botao-sair').addEventListener('click', () => sair());
  $('modal-fechar').addEventListener('click', () => $('modal').close());
  $('modal').addEventListener('close', () => { estado.modalId = null; });
}

configurarTela();
const tokenSalvo = lerTokenSalvo();
if (tokenSalvo) entrar(tokenSalvo);
else sair();
