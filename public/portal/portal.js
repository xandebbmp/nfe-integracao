/* ==========================================================================
   Portal do Cliente NFS-e - comportamento da tela
   JavaScript puro, sem framework e sem build.

   Por enquanto e so o MODELO DE LAYOUT:
     - o login aceita qualquer e-mail/senha (ainda nao chama a API);
     - o menu troca de tela pelo endereco (#inicio, #emitir, #notas...);
     - o botao de tema alterna claro/escuro e lembra a escolha.
   Onde a API vai entrar esta marcado com "TODO API".
   ========================================================================== */

(function () {
  'use strict';

  var telaLogin = document.getElementById('tela-login');
  var telaSistema = document.getElementById('tela-sistema');
  var formLogin = document.getElementById('form-login');
  var erroLogin = document.getElementById('login-erro');

  var PAGINA_INICIAL = 'inicio';

  // ------------------------------------------------------------------
  // Guardar pequenas preferencias no navegador (pode falhar: modo anonimo).
  // ------------------------------------------------------------------
  function lerPreferencia(chave) {
    try { return window.localStorage.getItem(chave); } catch (e) { return null; }
  }
  function gravarPreferencia(chave, valor) {
    try {
      if (valor === null) { window.localStorage.removeItem(chave); }
      else { window.localStorage.setItem(chave, valor); }
    } catch (e) { /* sem armazenamento: segue sem lembrar */ }
  }

  // ------------------------------------------------------------------
  // Login
  // ------------------------------------------------------------------
  function mostrarErro(texto) {
    erroLogin.textContent = texto;
    erroLogin.hidden = !texto;
  }

  formLogin.addEventListener('submit', function (evento) {
    evento.preventDefault();
    var email = document.getElementById('login-email').value.trim();
    var senha = document.getElementById('login-senha').value;

    if (email.indexOf('@') < 1) { mostrarErro('Informe um e-mail válido.'); return; }
    if (senha.length === 0) { mostrarErro('Informe a sua senha.'); return; }
    mostrarErro('');

    // TODO API: POST /v1/portal/login.php { email, senha } -> cookie de sessao.
    if (document.getElementById('login-lembrar').checked) {
      gravarPreferencia('portal_logado', '1');
    }
    entrarNoSistema();
  });

  document.getElementById('link-esqueci').addEventListener('click', function (evento) {
    evento.preventDefault();
    // TODO API: POST /v1/portal/esqueci_senha.php { email }
    mostrarErro('');
    erroLogin.hidden = false;
    erroLogin.textContent = 'No sistema real enviaremos um link para redefinir a senha por e-mail.';
  });

  function entrarNoSistema() {
    telaLogin.hidden = true;
    telaSistema.hidden = false;
    if (!window.location.hash || window.location.hash === '#sair') {
      window.location.hash = PAGINA_INICIAL;
    }
    mostrarPagina();
  }

  function sair() {
    // TODO API: POST /v1/portal/sair.php (apaga a sessao)
    gravarPreferencia('portal_logado', null);
    telaSistema.hidden = true;
    telaLogin.hidden = false;
    document.getElementById('login-senha').value = '';
  }

  // ------------------------------------------------------------------
  // Menu: mostra a <section data-pagina> do endereco atual
  // ------------------------------------------------------------------
  function mostrarPagina() {
    var nome = window.location.hash.replace('#', '') || PAGINA_INICIAL;

    if (nome === 'sair') { sair(); return; }
    if (telaSistema.hidden) { return; }

    var secoes = document.querySelectorAll('[data-pagina]');
    var achou = false;
    secoes.forEach(function (secao) {
      var ativa = secao.getAttribute('data-pagina') === nome;
      secao.hidden = !ativa;
      if (ativa) {
        achou = true;
        document.title = secao.getAttribute('data-titulo') + ' · Portal Gestão Fiscal NFS-e';
      }
    });
    if (!achou) { window.location.hash = PAGINA_INICIAL; return; }

    document.querySelectorAll('.menu__item, .menu__cta').forEach(function (link) {
      if (link.getAttribute('href') === '#' + nome) { link.setAttribute('aria-current', 'page'); }
      else { link.removeAttribute('aria-current'); }
    });

    fecharMenuCelular();
    window.scrollTo(0, 0);
  }

  window.addEventListener('hashchange', mostrarPagina);

  // ------------------------------------------------------------------
  // Menu no celular (gaveta)
  // ------------------------------------------------------------------
  function fecharMenuCelular() { telaSistema.classList.remove('menu-aberto'); }
  document.getElementById('botao-menu').addEventListener('click', function () {
    telaSistema.classList.toggle('menu-aberto');
  });
  document.getElementById('cortina').addEventListener('click', fecharMenuCelular);

  // ------------------------------------------------------------------
  // Tema claro/escuro
  // ------------------------------------------------------------------
  var iconeTema = document.getElementById('icone-tema');

  function temaAtualEscuro() {
    var escolhido = document.documentElement.getAttribute('data-theme');
    if (escolhido) { return escolhido === 'dark'; }
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }
  function aplicarTema(tema) {
    if (tema) { document.documentElement.setAttribute('data-theme', tema); }
    iconeTema.textContent = temaAtualEscuro() ? 'light_mode' : 'dark_mode';
  }
  document.getElementById('botao-tema').addEventListener('click', function () {
    var novo = temaAtualEscuro() ? 'light' : 'dark';
    gravarPreferencia('portal_tema', novo);
    aplicarTema(novo);
  });

  // ==================================================================
  // TELA "EMITIR NOTA"
  // Dados de exemplo no lugar das consultas. Onde a API entra: "TODO API".
  // ==================================================================
  var formEmitir = document.getElementById('form-emitir');
  function el(id) { return document.getElementById(id); }

  // ---------- Exemplos (no sistema real vem da API) ----------
  var EXEMPLO_CNPJ = {
    '00394460005887': { nome: 'Clínica Vida Plena Ltda', email: 'financeiro@vidaplena.com.br', cep: '41820020', numero: '1000' },
    '11222333000181': { nome: 'Construtora Atlântico S.A.', email: 'notas@atlantico.com.br', cep: '40015970', numero: '25' }
  };
  var EXEMPLO_CPF = { '52998224725': { nome: 'Ana Paula Souza', email: 'ana.souza@email.com', cep: '', numero: '' } };
  var EXEMPLO_CEP = {
    '41820020': { logradouro: 'Avenida Tancredo Neves', bairro: 'Caminho das Árvores', cidade: 'Salvador / BA' },
    '40015970': { logradouro: 'Avenida Estados Unidos', bairro: 'Comércio', cidade: 'Salvador / BA' }
  };
  var SERVICOS = {
    consultoria: { descricao: 'Consultoria em gestão financeira referente a {mes}.', nacional: '170101', item: '17.01', aliquota: 5 },
    software: { descricao: 'Desenvolvimento de software sob encomenda referente a {mes}.', nacional: '010101', item: '01.01', aliquota: 2 },
    treinamento: { descricao: 'Treinamento profissional em gestão, carga de 8 horas.', nacional: '080201', item: '08.02', aliquota: 3 }
  };

  // ---------- CPF / CNPJ ----------
  function soNumeros(texto) { return (texto || '').replace(/\D/g, ''); }

  function cpfValido(cpf) {
    if (cpf.length !== 11 || /^(\d)\1+$/.test(cpf)) { return false; }
    for (var t = 9; t < 11; t++) {
      var soma = 0;
      for (var i = 0; i < t; i++) { soma += Number(cpf[i]) * (t + 1 - i); }
      if (((soma * 10) % 11) % 10 !== Number(cpf[t])) { return false; }
    }
    return true;
  }

  function cnpjValido(cnpj) {
    if (cnpj.length !== 14 || /^(\d)\1+$/.test(cnpj)) { return false; }
    function digito(base) {
      var pesos = base.length === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
      var soma = 0;
      for (var i = 0; i < base.length; i++) { soma += Number(base[i]) * pesos[i]; }
      var resto = soma % 11;
      return resto < 2 ? 0 : 11 - resto;
    }
    var d1 = digito(cnpj.slice(0, 12));
    var d2 = digito(cnpj.slice(0, 12) + d1);
    return cnpj.slice(12) === String(d1) + String(d2);
  }

  function formatarDoc(numeros) {
    if (numeros.length === 11) { return numeros.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4'); }
    if (numeros.length === 14) { return numeros.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5'); }
    return numeros;
  }

  // ---------- Dinheiro (formato brasileiro) ----------
  function lerValor(texto) {
    var limpo = (texto || '').replace(/[^\d,]/g, '').replace(',', '.');
    var numero = parseFloat(limpo);
    return isNaN(numero) ? 0 : numero;
  }
  function formatarValor(numero) {
    return numero.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function reais(numero) { return 'R$ ' + formatarValor(numero); }

  // ---------- Mensagens de ajuda debaixo do campo ----------
  function dica(id, texto, tipo) {
    var alvo = el(id);
    alvo.textContent = texto;
    alvo.className = 'dica' + (tipo ? ' ' + tipo : '');
  }
  function marcarAuto(ids) {
    ids.forEach(function (id) {
      var campo = el(id).closest('.campo');
      if (el(id).value) { campo.classList.add('preenchido-auto'); }
    });
  }
  function limparAuto() {
    document.querySelectorAll('.preenchido-auto').forEach(function (c) { c.classList.remove('preenchido-auto'); });
  }

  // ---------- Tomador ----------
  function preencherTomador(dados) {
    el('tom-nome').value = dados.nome || '';
    el('tom-email').value = dados.email || '';
    el('tom-cep').value = dados.cep ? dados.cep.replace(/(\d{5})(\d{3})/, '$1-$2') : '';
    el('tom-numero').value = dados.numero || '';
    buscarCep();
    marcarAuto(['tom-nome', 'tom-email']);
  }

  function buscarDocumento() {
    var numeros = soNumeros(el('tom-doc').value);
    var campo = el('tom-doc').closest('.campo');
    limparAuto();
    campo.classList.remove('invalido');
    if (numeros.length === 0) { dica('tom-doc-info', 'Com CNPJ, nome e endereço são preenchidos sozinhos.'); atualizar(); return; }

    el('tom-doc').value = formatarDoc(numeros);

    if (numeros.length === 14 && cnpjValido(numeros)) {
      // TODO API: GET /v1/portal/cnpj.php?cnpj=... (servidor consulta a Receita, com cache)
      var achado = EXEMPLO_CNPJ[numeros] || { nome: 'Empresa Exemplo Ltda', email: '', cep: '41820020', numero: 's/n' };
      preencherTomador(achado);
      dica('tom-doc-info', 'Dados da Receita Federal preenchidos. Confira o e-mail.', 'ok');
      el('tom-email').focus();
    } else if (numeros.length === 11 && cpfValido(numeros)) {
      var pessoa = EXEMPLO_CPF[numeros];
      if (pessoa) { preencherTomador(pessoa); dica('tom-doc-info', 'Tomador encontrado nos seus cadastros.', 'ok'); }
      else { dica('tom-doc-info', 'CPF válido. Para pessoa física, digite o nome.', 'ok'); el('tom-nome').focus(); }
    } else {
      campo.classList.add('invalido');
      dica('tom-doc-info', numeros.length === 11 || numeros.length === 14
        ? 'Os dígitos verificadores não conferem. Confira o número.'
        : 'Digite 11 números (CPF) ou 14 números (CNPJ).', 'erro');
    }
    atualizar();
  }

  function buscarCep() {
    var cep = soNumeros(el('tom-cep').value);
    if (cep.length !== 8) { return; }
    el('tom-cep').value = cep.replace(/(\d{5})(\d{3})/, '$1-$2');
    // TODO API: GET /v1/portal/cep.php?cep=... (ViaCEP no servidor, traz o codigo IBGE)
    var end = EXEMPLO_CEP[cep] || { logradouro: 'Rua Exemplo', bairro: 'Centro', cidade: 'Salvador / BA' };
    el('tom-logradouro').value = end.logradouro;
    el('tom-bairro').value = end.bairro;
    el('tom-cidade').value = end.cidade;
    marcarAuto(['tom-cep', 'tom-logradouro', 'tom-bairro', 'tom-cidade']);
    el('tom-endereco-resumo').textContent = end.logradouro + ', ' + (el('tom-numero').value || 's/n') + ' · ' + end.cidade;
  }

  el('tom-doc').addEventListener('blur', buscarDocumento);
  el('botao-buscar-doc').addEventListener('click', buscarDocumento);
  el('tom-cep').addEventListener('blur', buscarCep);
  el('tom-salvo').addEventListener('change', function () {
    if (!this.value) { return; }
    el('tom-doc').value = this.value;
    buscarDocumento();
  });

  // ---------- Servico ----------
  function mesAtualPorExtenso() {
    var valor = el('val-competencia').value || '2026-11';
    var partes = valor.split('-');
    var nomes = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    return nomes[Number(partes[1]) - 1] + '/' + partes[0];
  }

  function aplicarServico() {
    var srv = SERVICOS[el('srv-salvo').value];
    el('srv-codigos').hidden = !srv;
    if (!srv) { atualizar(); return; }
    el('srv-descricao').value = srv.descricao.replace('{mes}', mesAtualPorExtenso());
    el('srv-cod-nacional').textContent = srv.nacional;
    el('srv-item').textContent = srv.item;
    el('srv-aliquota-txt').textContent = formatarValor(srv.aliquota) + '%';
    atualizar();
  }
  el('srv-salvo').addEventListener('change', aplicarServico);
  el('srv-descricao').addEventListener('input', atualizar);

  // ---------- Valores ----------
  ['val-servico', 'val-desconto', 'val-ir', 'val-pcc'].forEach(function (id) {
    el(id).addEventListener('input', atualizar);
    el(id).addEventListener('blur', function () {
      if (this.value.trim() !== '') { this.value = formatarValor(lerValor(this.value)); }
    });
  });
  el('val-iss-retido').addEventListener('change', atualizar);
  el('val-competencia').addEventListener('change', aplicarServico);
  el('tom-nome').addEventListener('input', atualizar);

  // ---------- Resumo ao vivo + o que falta ----------
  function atualizar() {
    var srv = SERVICOS[el('srv-salvo').value];
    var aliquota = srv ? srv.aliquota : 0;
    var bruto = lerValor(el('val-servico').value);
    var desconto = lerValor(el('val-desconto').value);
    var base = Math.max(bruto - desconto, 0);
    var iss = Math.round(base * aliquota) / 100;
    var retido = el('val-iss-retido').checked;
    var retencoes = lerValor(el('val-ir').value) + lerValor(el('val-pcc').value) + (retido ? iss : 0);

    el('res-bruto').textContent = reais(bruto);
    el('res-desconto').textContent = desconto ? '− ' + reais(desconto) : reais(0);
    el('res-aliquota').textContent = formatarValor(aliquota).replace(',00', '') + '%';
    el('res-iss').textContent = reais(iss);
    el('res-iss-situacao').textContent = retido ? '(retido)' : '(você recolhe)';
    el('res-retencoes').textContent = retencoes ? '− ' + reais(retencoes) : reais(0);
    el('res-liquido').textContent = reais(Math.max(base - retencoes, 0));
    el('srv-contador').textContent = el('srv-descricao').value.length;

    var doc = soNumeros(el('tom-doc').value);
    var docOk = (doc.length === 11 && cpfValido(doc)) || (doc.length === 14 && cnpjValido(doc));
    var itens = [
      { ok: docOk, texto: 'CPF ou CNPJ do tomador válido' },
      { ok: el('tom-nome').value.trim().length > 1, texto: 'Nome do tomador' },
      { ok: !!srv && el('srv-descricao').value.trim().length > 4, texto: 'Serviço e descrição' },
      { ok: bruto > 0, texto: 'Valor maior que zero' }
    ];

    el('checagem').innerHTML = itens.map(function (item) {
      return '<li class="' + (item.ok ? 'ok' : '') + '">' + item.texto + '</li>';
    }).join('');

    var etapas = formEmitir.querySelectorAll('.etapa');
    etapas[0].classList.toggle('completa', itens[0].ok && itens[1].ok);
    etapas[1].classList.toggle('completa', itens[2].ok);
    etapas[2].classList.toggle('completa', itens[3].ok);

    el('botao-emitir').disabled = !itens.every(function (i) { return i.ok; });
  }

  // Enter num campo nao envia a nota sem querer
  formEmitir.addEventListener('keydown', function (evento) {
    if (evento.key === 'Enter' && evento.target.tagName === 'INPUT') { evento.preventDefault(); }
  });

  // ---------- Emitir ----------
  var avisoTimer = null;
  function avisar(html) {
    var caixa = document.querySelector('.aviso-flutuante');
    if (!caixa) {
      caixa = document.createElement('div');
      caixa.className = 'aviso-flutuante';
      caixa.setAttribute('role', 'status');
      document.body.appendChild(caixa);
    }
    caixa.innerHTML = '<i class="icone">check_circle</i><span>' + html + '</span>';
    caixa.hidden = false;
    clearTimeout(avisoTimer);
    avisoTimer = setTimeout(function () { caixa.hidden = true; }, 6000);
  }

  formEmitir.addEventListener('submit', function (evento) {
    evento.preventDefault();
    var botao = el('botao-emitir');
    if (botao.disabled) { return; }
    botao.disabled = true;
    botao.innerHTML = '<i class="icone icone--p">hourglass_top</i> Enviando…';

    // TODO API: POST /v1/portal/emitir.php -> monta o JSON e chama a emissao da API.
    setTimeout(function () {
      botao.innerHTML = '<i class="icone icone--p">send</i> Emitir nota';
      atualizar();
      avisar('Nota enviada para a prefeitura. Acompanhe em <a href="#notas">Minhas notas</a>.');
    }, 900);
  });

  aplicarServico();

  // ------------------------------------------------------------------
  // Inicio
  // ------------------------------------------------------------------
  aplicarTema(lerPreferencia('portal_tema'));
  if (lerPreferencia('portal_logado') === '1') { entrarNoSistema(); }
})();
