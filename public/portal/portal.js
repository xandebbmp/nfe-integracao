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

    document.querySelectorAll('.menu__item').forEach(function (link) {
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

  // ------------------------------------------------------------------
  // Inicio
  // ------------------------------------------------------------------
  aplicarTema(lerPreferencia('portal_tema'));
  if (lerPreferencia('portal_logado') === '1') { entrarNoSistema(); }
})();
