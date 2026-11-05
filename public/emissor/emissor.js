/* ==========================================================================
   Emissor simples - NFS-e Nacional em 3 passos (valor, tomador, descricao)

   Rotas usadas (/v1/emissor/*.php), sempre com o cabecalho X-EMISSOR-SESSAO:
     entrar, sair, painel, tomador, emitir, nota, pdf, xml, cancelar, senha

   O resto da nota (codigo de tributacao, aliquota, ISS...) vem do cadastro
   da empresa feito no painel admin. Sem build, sem biblioteca.
   ========================================================================== */

const API = new URL('../v1/emissor/', window.location.href).href;
const CHAVE_SESSAO = 'emissor_sessao';

const STATUS = {
  RECEBIDO: { nome: 'Na fila', cor: 'var(--st-recebido)' },
  PROCESSANDO: { nome: 'Enviando', cor: 'var(--st-processando)' },
  AUTORIZADO: { nome: 'Emitida', cor: 'var(--st-autorizado)' },
  REJEITADO: { nome: 'Recusada', cor: 'var(--st-rejeitado)' },
  ERRO: { nome: 'Com erro', cor: 'var(--st-erro)' },
  CANCELADO: { nome: 'Cancelada', cor: 'var(--st-cancelado)' },
  SUBSTITUIDO: { nome: 'Substituída', cor: 'var(--st-substituido)' },
};

const estado = {
  sessao: '',
  painel: null,
  chave: '',          // identifica o formulario: nova tentativa nao duplica a nota
  tomador: null,      // endereco etc. vindos da busca
  buscaAtual: '',
};

// ==========================================================================
// Utilitarios
// ==========================================================================

const $ = (id) => document.getElementById(id);

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

const reais = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

function documentoFormatado(v) {
  const d = String(v || '').replace(/\D/g, '');
  if (d.length === 11) return d.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
  if (d.length === 14) return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
  return v || '';
}

function dataCurta(texto) {
  if (!texto) return '';
  const d = new Date(String(texto).replace(' ', 'T').replace(/\+00$/, 'Z'));
  return isNaN(d) ? texto : d.toLocaleDateString('pt-BR');
}

function novaChave() {
  return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function seloStatus(status) {
  const s = STATUS[status] || { nome: status, cor: 'var(--suave)' };
  return el('span', { class: `status status--${status}`, style: `--cor:${s.cor}` }, s.nome);
}

// ==========================================================================
// API
// ==========================================================================

async function api(caminho, opcoes = {}) {
  const cabecalhos = { 'X-EMISSOR-SESSAO': estado.sessao };
  if (opcoes.corpo) cabecalhos['Content-Type'] = 'application/json';
  const resposta = await fetch(API + caminho, {
    method: opcoes.metodo || 'GET', headers: cabecalhos, body: opcoes.corpo ? JSON.stringify(opcoes.corpo) : undefined,
  });
  if (resposta.status === 401 && caminho !== 'entrar.php') {
    sair('Sua sessão terminou. Entre de novo.');
    throw new Error('sessao');
  }
  if (opcoes.arquivo) {
    if (!resposta.ok) throw new Error((await resposta.json().catch(() => ({}))).erro?.mensagem || `Erro ${resposta.status}`);
    return resposta.blob();
  }
  const json = await resposta.json().catch(() => ({}));
  if (!resposta.ok) {
    const erro = new Error(json.erro?.mensagem || `Erro ${resposta.status}`);
    erro.codigo = json.erro?.codigo;
    erro.erros = json.erro?.erros || [];
    throw erro;
  }
  return json;
}

async function baixar(caminho, nome) {
  try {
    const blob = await api(caminho, { arquivo: true });
    const link = el('a', { href: URL.createObjectURL(blob), download: nome });
    document.body.append(link);
    link.click();
    link.remove();
  } catch (e) {
    if (e.message !== 'sessao') alert(e.message);
  }
}

// ==========================================================================
// Entrar / sair
// ==========================================================================

function lerSessao() {
  try { return localStorage.getItem(CHAVE_SESSAO) || ''; } catch { return ''; }
}

function guardarSessao(token) {
  try { if (token) localStorage.setItem(CHAVE_SESSAO, token); else localStorage.removeItem(CHAVE_SESSAO); } catch { /* sem armazenamento */ }
}

function sair(mensagem) {
  if (estado.sessao && !mensagem) api('sair.php', { metodo: 'POST', corpo: {} }).catch(() => {});
  estado.sessao = '';
  guardarSessao('');
  $('tela-emissor').hidden = true;
  $('tela-entrar').hidden = false;
  $('erro-entrar').hidden = !mensagem;
  $('erro-entrar').textContent = mensagem || '';
}

$('form-entrar').addEventListener('submit', async (evento) => {
  evento.preventDefault();
  $('erro-entrar').hidden = true;
  try {
    const r = await api('entrar.php', { metodo: 'POST', corpo: { email: $('campo-email').value, senha: $('campo-senha').value } });
    estado.sessao = r.token;
    guardarSessao(r.token);
    $('campo-senha').value = '';
    await abrir();
  } catch (e) {
    $('erro-entrar').hidden = false;
    $('erro-entrar').textContent = e.message;
  }
});

$('botao-sair').addEventListener('click', () => sair());

// ==========================================================================
// Tela principal
// ==========================================================================

async function abrir() {
  estado.painel = await api('painel.php');
  const p = estado.painel;
  $('tela-entrar').hidden = true;
  $('tela-emissor').hidden = false;
  $('nome-empresa').textContent = p.empresa.nome;
  $('nome-usuario').textContent = p.usuario.nome;
  const producao = p.ambiente === 'PRODUCAO';
  $('selo-ambiente').textContent = producao ? 'PRODUÇÃO' : 'TESTE (sem valor fiscal)';
  $('selo-ambiente').className = `selo selo--${producao ? 'producao' : 'homologacao'}`;

  // Pode emitir?
  let aviso = '';
  if (p.empresa.situacao !== 'LIBERADA') aviso = p.empresa.situacao_mensagem;
  else if (!p.empresa.pode_nacional) aviso = 'A nota de serviço (NFS-e Nacional) não está contratada para sua empresa. Fale com o suporte.';
  else if (!p.servicos.length) aviso = 'O serviço da sua empresa ainda não foi configurado. Fale com o suporte.';
  else if (!p.empresa.tem_certificado) aviso = 'O certificado digital da sua empresa ainda não foi enviado. Fale com o suporte.';
  $('aviso-geral').hidden = !aviso;
  $('aviso-geral').textContent = aviso;
  $('botao-emitir').disabled = Boolean(aviso);

  // Servicos (so mostra a escolha se houver mais de um)
  const servico = $('campo-servico');
  servico.replaceChildren(...p.servicos.map((s) => el('option', { value: s.id }, s.nome)));
  servico.hidden = $('rotulo-servico').hidden = p.servicos.length < 2;
  servico.onchange = preencherDescricaoPadrao;
  preencherDescricaoPadrao();

  desenharRecentes();
  desenharNotas(p.notas);
  if (!estado.chave) estado.chave = novaChave();
}

function preencherDescricaoPadrao() {
  const s = (estado.painel?.servicos || []).find((x) => String(x.id) === $('campo-servico').value) || estado.painel?.servicos?.[0];
  const campo = $('campo-descricao');
  if (!campo.value.trim() || campo.dataset.automatica === '1') {
    campo.value = s?.descricao_padrao || '';
    campo.dataset.automatica = campo.value ? '1' : '';
  }
}
$('campo-descricao').addEventListener('input', (e) => { e.target.dataset.automatica = ''; });

// ==========================================================================
// Passo 1: valor
// ==========================================================================

$('campo-valor').addEventListener('blur', (e) => {
  const numero = lerValor(e.target.value);
  if (numero) e.target.value = numero.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
});

function lerValor(texto) {
  let t = String(texto || '').replace(/[^\d,.]/g, '');
  if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
  const n = Number(t);
  return Number.isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : null;
}

// ==========================================================================
// Passo 2: tomador
// ==========================================================================

function desenharRecentes() {
  const lista = (estado.painel?.tomadores || []).slice(0, 8);
  $('recentes').replaceChildren(...(lista.length ? [el('span', { class: 'suave pequeno' }, 'Recentes:')] : []),
    ...lista.map((t) => el('button', { type: 'button', title: documentoFormatado(t.documento), onclick: () => usarTomador(t, 'SALVO') }, t.nome)));
}

function usarTomador(t, origem) {
  estado.tomador = t;
  $('campo-documento').value = documentoFormatado(t.documento);
  $('campo-nome').value = t.nome || '';
  $('campo-email-tomador').value = t.email || '';
  $('dados-tomador').hidden = false;
  const endereco = t.endereco ? `${t.endereco.logradouro}, ${t.endereco.numero} - ${t.endereco.bairro}${t.endereco.municipio ? ` - ${t.endereco.municipio}` : ''}/${t.endereco.uf || ''}` : '';
  $('origem-tomador').textContent = origem === 'RECEITA' ? `Dados da Receita Federal. ${endereco}`
    : origem === 'SALVO' ? `Cliente já usado antes. ${endereco}` : 'Não encontramos este cliente: digite o nome.';
  if (!t.nome) $('campo-nome').focus();
}

$('campo-documento').addEventListener('input', async (e) => {
  const digitos = e.target.value.replace(/\D/g, '');
  if (digitos.length !== 11 && digitos.length !== 14) {
    if (estado.tomador && estado.tomador.documento !== digitos) { estado.tomador = null; $('dados-tomador').hidden = true; }
    return;
  }
  if (estado.buscaAtual === digitos) return;
  estado.buscaAtual = digitos;
  mostrarErroCampo('tomador.documento', '');
  $('origem-tomador').textContent = 'Buscando...';
  try {
    const r = await api(`tomador.php?documento=${digitos}`);
    if (estado.buscaAtual === digitos) usarTomador(r.tomador, r.origem);
  } catch (erro) {
    if (erro.message !== 'sessao') mostrarErroCampo('tomador.documento', erro.message);
  } finally {
    estado.buscaAtual = '';
  }
});

// ==========================================================================
// Emitir
// ==========================================================================

function mostrarErroCampo(campo, mensagem) {
  const p = document.querySelector(`.erro-campo[data-campo="${campo}"]`);
  if (!p) return false;
  p.hidden = !mensagem;
  p.textContent = mensagem;
  return true;
}

function limparErros() {
  document.querySelectorAll('.erro-campo').forEach((p) => { p.hidden = true; });
  $('erro-nota').hidden = true;
}

$('form-nota').addEventListener('submit', async (evento) => {
  evento.preventDefault();
  limparErros();
  const valor = lerValor($('campo-valor').value);
  const documento = $('campo-documento').value.replace(/\D/g, '');
  const problemas = [];
  if (!valor) problemas.push(['valor', 'Informe o valor (ex.: 1.500,00).']);
  if (documento.length !== 11 && documento.length !== 14) {
    problemas.push(['tomador.documento', 'Digite o CPF (11 números) ou CNPJ (14 números).']);
  } else if (!$('campo-nome').value.trim()) {
    $('dados-tomador').hidden = false;
    problemas.push(['tomador.nome', 'Informe o nome do cliente.']);
  }
  if (!$('campo-descricao').value.trim()) problemas.push(['descricao', 'Descreva o serviço prestado.']);
  if (problemas.length) {
    problemas.forEach(([campo, mensagem]) => mostrarErroCampo(campo, mensagem));
    return;
  }

  const corpo = {
    chave: estado.chave,
    valor,
    descricao: $('campo-descricao').value.trim(),
    servico_id: $('campo-servico').value || null,
    tomador: {
      documento,
      nome: $('campo-nome').value.trim(),
      email: $('campo-email-tomador').value.trim(),
      telefone: estado.tomador?.documento === documento ? estado.tomador.telefone : null,
      endereco: estado.tomador?.documento === documento ? estado.tomador.endereco : null,
    },
  };

  $('botao-emitir').disabled = true;
  mostrarResultado({ status: 'PROCESSANDO' }, valor);
  try {
    const r = await api('emitir.php', { metodo: 'POST', corpo });
    await acompanhar(r.nota, valor);
  } catch (e) {
    $('resultado').hidden = true;
    $('form-nota').hidden = false;
    if (e.message === 'sessao') return;
    let mostrou = false;
    for (const item of e.erros || []) mostrou = mostrarErroCampo(item.campo, item.mensagem) || mostrou;
    if (!mostrou) { $('erro-nota').hidden = false; $('erro-nota').textContent = e.message; }
  } finally {
    $('botao-emitir').disabled = false;
  }
});

/** Enquanto a nota estiver na fila/enviando, consulta a cada 3 s (ate 2 min). */
async function acompanhar(nota, valor) {
  const limite = Date.now() + 120000;
  while (nota && ['RECEBIDO', 'PROCESSANDO'].includes(nota.status) && Date.now() < limite) {
    mostrarResultado(nota, valor);
    await new Promise((ok) => setTimeout(ok, 3000));
    nota = (await api(`nota.php?id=${nota.id}`)).nota;
  }
  mostrarResultado(nota, valor);
  atualizarNotas();
}

function mostrarResultado(nota, valor) {
  $('form-nota').hidden = true;
  const r = $('resultado');
  r.hidden = false;
  const novaNota = () => {
    estado.chave = novaChave();
    estado.tomador = null;
    $('form-nota').reset();
    $('dados-tomador').hidden = true;
    $('campo-descricao').dataset.automatica = '';
    preencherDescricaoPadrao();
    r.hidden = true;
    $('form-nota').hidden = false;
    $('campo-valor').focus();
  };
  const corrigir = () => { r.hidden = true; $('form-nota').hidden = false; };

  if (['RECEBIDO', 'PROCESSANDO'].includes(nota.status)) {
    r.replaceChildren(el('div', { class: 'girando' }), el('p', { class: 'resultado__titulo' }, 'Enviando a nota...'),
      el('p', { class: 'suave' }, 'Isso costuma levar alguns segundos. Você pode continuar usando o sistema: a nota aparece em "Minhas notas".'),
      nota.id ? el('div', { class: 'botoes' }, el('button', { class: 'botao', type: 'button', onclick: novaNota }, 'Emitir outra enquanto isso')) : null);
    return;
  }
  if (nota.status === 'AUTORIZADO') {
    r.replaceChildren(el('div', { class: 'resultado__icone' }, '✅'),
      el('p', { class: 'resultado__titulo' }, `Nota nº ${nota.numero} emitida!`),
      el('p', {}, `${reais.format(nota.valor ?? valor)} para ${nota.tomador_nome}`),
      el('div', { class: 'botoes' },
        el('button', { class: 'botao botao--primario', type: 'button', style: 'width:auto;margin:0', onclick: () => baixar(`pdf.php?id=${nota.id}`, `nfse-${nota.numero}.pdf`) }, 'Baixar PDF'),
        el('button', { class: 'botao', type: 'button', onclick: () => baixar(`xml.php?id=${nota.id}`, `nfse-${nota.numero}.xml`) }, 'Baixar XML'),
        el('button', { class: 'botao', type: 'button', onclick: novaNota }, 'Emitir outra nota')));
    return;
  }
  // Recusada / erro: mostra o motivo e deixa corrigir (mesma chave: reaproveita a nota)
  r.replaceChildren(el('div', { class: 'resultado__icone' }, '⚠️'),
    el('p', { class: 'resultado__titulo' }, nota.status === 'ERRO' ? 'Não foi possível enviar agora' : 'A nota foi recusada'),
    el('p', {}, nota.mensagem || 'Confira os dados e tente de novo.'),
    nota.correcao ? el('div', { class: 'correcao-box' }, el('strong', {}, 'Como corrigir: '), nota.correcao) : null,
    el('div', { class: 'botoes' },
      el('button', { class: 'botao botao--primario', type: 'button', style: 'width:auto;margin:0', onclick: corrigir }, 'Corrigir e enviar de novo'),
      el('button', { class: 'botao', type: 'button', onclick: novaNota }, 'Começar outra nota')));
}

// ==========================================================================
// Minhas notas
// ==========================================================================

async function atualizarNotas() {
  try {
    estado.painel = await api('painel.php');
    desenharNotas(estado.painel.notas);
    desenharRecentes();
  } catch { /* a proxima atualizacao tenta de novo */ }
}
$('botao-atualizar').addEventListener('click', atualizarNotas);

function desenharNotas(notas) {
  if (!notas.length) {
    $('lista-notas').replaceChildren(el('p', { class: 'suave' }, 'Nenhuma nota ainda. A primeira aparece aqui.'));
    return;
  }
  $('lista-notas').replaceChildren(...notas.map((n) => el('div', { class: 'nota' },
    el('div', {},
      el('div', { class: 'nota__titulo' }, n.tomador_nome || '—', ' ', seloStatus(n.status)),
      el('div', { class: 'nota__info' }, [dataCurta(n.data), n.numero ? `Nota nº ${n.numero}` : null, documentoFormatado(n.tomador_documento)].filter(Boolean).join(' · ')),
      el('div', { class: 'nota__info' }, n.descricao)),
    el('div', { class: 'nota__valor' }, n.valor !== null ? reais.format(n.valor) : ''),
    ['REJEITADO', 'ERRO'].includes(n.status) && n.mensagem ? el('div', { class: 'nota__erro' }, n.mensagem) : null,
    el('div', { class: 'nota__acoes' },
      n.tem_pdf ? el('button', { class: 'botao', type: 'button', onclick: () => baixar(`pdf.php?id=${n.id}`, `nfse-${n.numero}.pdf`) }, 'PDF') : null,
      n.tem_pdf ? el('button', { class: 'botao', type: 'button', onclick: () => baixar(`xml.php?id=${n.id}`, `nfse-${n.numero}.xml`) }, 'XML') : null,
      n.pode_cancelar ? el('button', { class: 'botao botao--perigo', type: 'button', onclick: () => abrirCancelamento(n) }, 'Cancelar') : null))));
}

// ==========================================================================
// Cancelar e trocar senha (janela)
// ==========================================================================

function abrirModal(titulo, conteudo) {
  $('modal-titulo').textContent = titulo;
  $('modal-corpo').replaceChildren(...conteudo);
  $('modal').showModal();
}
$('modal-fechar').addEventListener('click', () => $('modal').close());

function abrirCancelamento(n) {
  const motivo = el('select', {}, el('option', { value: '1' }, 'Erro na emissão'), el('option', { value: '2' }, 'Serviço não prestado'), el('option', { value: '9' }, 'Outro motivo'));
  const texto = el('textarea', { rows: 3, maxlength: 255, placeholder: 'Explique em poucas palavras (mínimo 15 letras)' });
  const erro = el('p', { class: 'erro', hidden: true });
  const botao = el('button', { class: 'botao botao--perigo', type: 'button' }, 'Confirmar cancelamento');
  botao.addEventListener('click', async () => {
    if (texto.value.trim().length < 15) { erro.hidden = false; erro.textContent = 'Escreva pelo menos 15 letras no motivo.'; return; }
    botao.disabled = true;
    try {
      const r = await api('cancelar.php', { metodo: 'POST', corpo: { id: n.id, motivo_codigo: motivo.value, motivo: texto.value.trim() } });
      $('modal').close();
      alert(r.mensagem);
      setTimeout(atualizarNotas, 4000);
    } catch (e) {
      erro.hidden = false; erro.textContent = e.message; botao.disabled = false;
    }
  });
  abrirModal(`Cancelar nota nº ${n.numero}`, [
    el('p', {}, `${n.tomador_nome} — ${reais.format(n.valor)}`),
    el('div', { class: 'campos-modal' }, el('label', {}, 'Motivo', motivo), el('label', {}, 'Explique', texto)),
    erro,
    el('p', { class: 'suave pequeno' }, 'O cancelamento pode ser recusado se passou do prazo do município. Nesse caso, a nota continua válida e o motivo aparece aqui.'),
    el('div', { class: 'acoes' }, botao),
  ]);
}

$('botao-senha').addEventListener('click', () => {
  const atual = el('input', { type: 'password', autocomplete: 'current-password' });
  const nova = el('input', { type: 'password', autocomplete: 'new-password', minlength: 8 });
  const erro = el('p', { class: 'erro', hidden: true });
  const botao = el('button', { class: 'botao botao--primario', type: 'button' }, 'Trocar senha');
  botao.addEventListener('click', async () => {
    try {
      const r = await api('senha.php', { metodo: 'POST', corpo: { atual: atual.value, nova: nova.value } });
      $('modal').close();
      alert(r.mensagem);
      sair('Senha alterada. Entre com a nova senha.');
    } catch (e) {
      erro.hidden = false; erro.textContent = e.message;
    }
  });
  abrirModal('Trocar senha', [el('div', { class: 'campos-modal' },
    el('label', {}, 'Senha atual', atual), el('label', {}, 'Nova senha (mínimo 8 caracteres)', nova)), erro, botao]);
});

// ==========================================================================
// Inicio
// ==========================================================================

(async () => {
  estado.sessao = lerSessao();
  if (!estado.sessao) { sair(''); $('erro-entrar').hidden = true; return; }
  try {
    await abrir();
  } catch (e) {
    if (e.message !== 'sessao') sair(e.message);
  }
})();
