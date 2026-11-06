/* ==========================================================================
   Portal do Cliente NFS-e - comportamento das telas
   JavaScript puro, sem framework e sem build.

   Conversa com as rotas /v1/portal/*.php (sessao por cookie HttpOnly).
   Organizacao do arquivo:
     1. Utilidades (chamada da API, dinheiro, CPF/CNPJ)
     2. Login, troca de senha e saida
     3. Menu, telas e tema
     4. Tela inicial
     5. Tela "Emitir nota"
     6. Minhas notas, Recorrencias, Servicos, Tomadores, Minha empresa e Usuarios
     7. Inicio do portal

   Demonstracao sem servidor: portal-demo.js define window.PORTAL_DEMO e
   responde no lugar da API (usado so na previa do layout).
   ========================================================================== */

(function () {
  'use strict';

  // ==================================================================
  // 1. UTILIDADES
  // ==================================================================

  var API = new URL('../v1/portal/', window.location.href).href;
  var PAGINA_INICIAL = 'inicio';
  var ATUALIZAR_INICIO_MS = 20000;

  function el(id) { return document.getElementById(id); }

  /** Erro de API com codigo, mensagem e lista de erros por campo. */
  function ErroApi(status, dados) {
    var erro = (dados && dados.erro) || {};
    this.status = status;
    this.codigo = erro.codigo || 'ERRO';
    this.message = erro.mensagem || 'Não foi possível concluir. Tente de novo.';
    this.erros = erro.erros || [];
  }

  /**
   * Chama uma rota do portal e devolve o JSON.
   * Sessao expirada: volta para o login sozinho.
   */
  function api(metodo, caminho, corpo) {
    if (window.PORTAL_DEMO) { return window.PORTAL_DEMO.chamar(metodo, caminho, corpo); }

    var opcoes = { method: metodo, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (metodo !== 'GET') {
      opcoes.headers['X-Portal'] = '1'; // protecao contra outro site usar a sessao
      opcoes.headers['Content-Type'] = 'application/json';
      opcoes.body = JSON.stringify(corpo || {});
    }
    return fetch(API + caminho, opcoes).then(function (resposta) {
      return resposta.json().catch(function () { return {}; }).then(function (dados) {
        if (resposta.ok) { return dados; }
        var erro = new ErroApi(resposta.status, dados);
        if (erro.codigo === 'SESSAO_EXPIRADA' && !telaSistema.hidden) { mostrarLogin(erro.message); }
        throw erro;
      });
    }, function () {
      throw new ErroApi(0, { erro: { mensagem: 'Sem conexão com o servidor. Confira a internet e tente de novo.' } });
    });
  }

  /** Link de arquivo da API (ex.: "/v1/portal/arquivo.php?...") relativo a pasta do portal. */
  function linkApi(caminho) { return new URL('..' + caminho, window.location.href).href; }

  function soNumeros(texto) { return (texto || '').replace(/\D/g, ''); }

  function escapar(texto) {
    return String(texto === null || texto === undefined ? '' : texto)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function lerPreferencia(chave) {
    try { return window.localStorage.getItem(chave); } catch (e) { return null; }
  }
  function gravarPreferencia(chave, valor) {
    try {
      if (valor === null) { window.localStorage.removeItem(chave); } else { window.localStorage.setItem(chave, valor); }
    } catch (e) { /* sem armazenamento: segue sem lembrar */ }
  }

  // ---- Dinheiro (formato brasileiro) ----
  function lerValor(texto) {
    var limpo = String(texto || '').replace(/[^\d,]/g, '').replace(',', '.');
    var numero = parseFloat(limpo);
    return isNaN(numero) ? 0 : numero;
  }
  function formatarValor(numero) {
    return Number(numero || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function reais(numero) { return 'R$ ' + formatarValor(numero); }

  /** "2026-11-06 01:55:12+00" ou "2026-11-06" -> "06/11/2026" */
  function dataCurta(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
    return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
  }

  // ---- CPF / CNPJ (a mesma regra vale no servidor: src/Nfse/Dominio/Documentos.php) ----
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
    return cnpj.slice(12) === String(d1) + String(digito(cnpj.slice(0, 12) + d1));
  }
  function documentoValido(numero) {
    return numero.length === 11 ? cpfValido(numero) : cnpjValido(numero);
  }
  function formatarDoc(numeros) {
    if (numeros.length === 11) { return numeros.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4'); }
    if (numeros.length === 14) { return numeros.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5'); }
    return numeros;
  }
  function formatarCep(numeros) { return numeros.length === 8 ? numeros.replace(/(\d{5})(\d{3})/, '$1-$2') : numeros; }

  // ---- Aviso flutuante ----
  var avisoTimer = null;
  function avisar(html, icone) {
    var caixa = document.querySelector('.aviso-flutuante');
    if (!caixa) {
      caixa = document.createElement('div');
      caixa.className = 'aviso-flutuante';
      caixa.setAttribute('role', 'status');
      document.body.appendChild(caixa);
    }
    caixa.innerHTML = '<i class="icone">' + (icone || 'check_circle') + '</i><span>' + html + '</span>';
    caixa.hidden = false;
    clearTimeout(avisoTimer);
    avisoTimer = setTimeout(function () { caixa.hidden = true; }, 6000);
  }

  // Status da nota: texto e cor da pilula
  var STATUS = {
    RECEBIDO: ['Na fila', 'info'],
    PROCESSANDO: ['Processando', 'info'],
    AUTORIZADO: ['Autorizada', 'ok'],
    REJEITADO: ['Rejeitada', 'erro'],
    ERRO: ['Com erro', 'erro'],
    CANCELADO: ['Cancelada', 'neutro'],
    SUBSTITUIDO: ['Substituída', 'neutro'],
    DENEGADO: ['Denegada', 'erro']
  };
  function pilulaStatus(status) {
    var s = STATUS[status] || [status, 'neutro'];
    return '<span class="pilula pilula--' + s[1] + '">' + escapar(s[0]) + '</span>';
  }

  // ==================================================================
  // 2. LOGIN, TROCA DE SENHA E SAIDA
  // ==================================================================

  var telaLogin = el('tela-login');
  var telaSistema = el('tela-sistema');
  var formLogin = el('form-login');
  var formSenha = el('form-senha');
  var sessao = null; // { usuario, empresa }

  function mostrarErro(id, texto) {
    el(id).textContent = texto || '';
    el(id).hidden = !texto;
  }

  /** Mostra so um dos quadros da tela de login (entrar, trocar senha, esqueci...). */
  function mostrarQuadroLogin(id) {
    ['form-login', 'form-senha', 'form-esqueci', 'esqueci-enviado', 'form-redefinir'].forEach(function (q) { el(q).hidden = q !== id; });
  }

  function mostrarLogin(mensagem) {
    pararAtualizacao();
    telaSistema.hidden = true;
    telaLogin.hidden = false;
    mostrarQuadroLogin('form-login');
    mostrarErro('login-erro', mensagem || '');
    el('login-senha').value = '';
    el('login-email').focus();
  }

  function mostrarTrocaSenha(senhaDigitada) {
    telaSistema.hidden = true;
    telaLogin.hidden = false;
    mostrarQuadroLogin('form-senha');
    el('senha-atual').value = senhaDigitada || '';
    mostrarErro('senha-erro', '');
    el(senhaDigitada ? 'senha-nova' : 'senha-atual').focus();
  }

  formLogin.addEventListener('submit', function (evento) {
    evento.preventDefault();
    var email = el('login-email').value.trim();
    var senha = el('login-senha').value;
    if (email.indexOf('@') < 1) { mostrarErro('login-erro', 'Informe um e-mail válido.'); return; }
    if (!senha) { mostrarErro('login-erro', 'Informe a sua senha.'); return; }

    var botao = formLogin.querySelector('button[type=submit]');
    botao.disabled = true;
    mostrarErro('login-erro', '');
    api('POST', 'entrar.php', { email: email, senha: senha, lembrar: el('login-lembrar').checked })
      .then(function (r) {
        if (r.trocar_senha) { mostrarTrocaSenha(senha); return; }
        return carregarSessao();
      })
      .catch(function (erro) { mostrarErro('login-erro', erro.message); })
      .then(function () { botao.disabled = false; });
  });

  // ---- Esqueci a senha ----
  // 1) pede o link; 2) "confira o seu e-mail"; 3) o link do e-mail abre
  // #redefinir=<codigo> e a pessoa cria a senha nova.
  var formEsqueci = el('form-esqueci');
  var formRedefinir = el('form-redefinir');
  var codigoRedefinir = '';

  el('link-esqueci').addEventListener('click', function (evento) {
    evento.preventDefault();
    mostrarErro('login-erro', '');
    api('GET', 'esqueci.php').then(function (r) {
      if (!r.disponivel) {
        mostrarErro('login-erro', 'Peça ao responsável da sua empresa para gerar uma senha nova (tela Usuários). Você cria a sua no primeiro acesso.');
        return;
      }
      el('esqueci-email').value = el('login-email').value.trim();
      mostrarErro('esqueci-erro', '');
      mostrarQuadroLogin('form-esqueci');
      el('esqueci-email').focus();
    }).catch(function (erro) { mostrarErro('login-erro', erro.message); });
  });

  document.querySelectorAll('[data-voltar-login]').forEach(function (botao) {
    botao.addEventListener('click', function () {
      if (window.location.hash.indexOf('#redefinir=') === 0) { history.replaceState(null, '', window.location.pathname); }
      mostrarLogin('');
    });
  });

  formEsqueci.addEventListener('submit', function (evento) {
    evento.preventDefault();
    var email = el('esqueci-email').value.trim();
    if (email.indexOf('@') < 1) { mostrarErro('esqueci-erro', 'Digite um e-mail válido.'); return; }
    var botao = formEsqueci.querySelector('button[type=submit]');
    botao.disabled = true;
    api('POST', 'esqueci.php', { acao: 'pedir', email: email }).then(function (r) {
      el('esqueci-enviado-texto').textContent = r.mensagem;
      mostrarQuadroLogin('esqueci-enviado');
    }).catch(function (erro) { mostrarErro('esqueci-erro', erro.message); })
      .then(function () { botao.disabled = false; });
  });

  /** Abriu o link do e-mail: confere o codigo e mostra o formulario. */
  function abrirRedefinicao(codigo) {
    codigoRedefinir = codigo;
    telaSistema.hidden = true;
    telaLogin.hidden = false;
    formRedefinir.reset();
    mostrarErro('redefinir-erro', '');
    el('redefinir-para').textContent = 'Conferindo o link…';
    el('redefinir-voltar').hidden = true;
    formRedefinir.querySelector('button[type=submit]').disabled = true;
    mostrarQuadroLogin('form-redefinir');
    api('POST', 'esqueci.php', { acao: 'conferir', codigo: codigo }).then(function (r) {
      el('redefinir-para').textContent = 'Olá, ' + r.nome + '. Escolha a senha nova de ' + r.email + '.';
      formRedefinir.querySelector('button[type=submit]').disabled = false;
      el('redefinir-senha').focus();
    }).catch(function (erro) {
      el('redefinir-para').textContent = '';
      mostrarErro('redefinir-erro', erro.message);
      el('redefinir-voltar').hidden = false;
    });
  }

  el('redefinir-voltar').addEventListener('click', function () {
    history.replaceState(null, '', window.location.pathname);
    mostrarLogin('');
    el('link-esqueci').click();
  });

  formRedefinir.addEventListener('submit', function (evento) {
    evento.preventDefault();
    var nova = el('redefinir-senha').value;
    if (nova.length < 8 || !/[A-Za-z]/.test(nova) || !/\d/.test(nova)) {
      mostrarErro('redefinir-erro', 'A senha precisa ter pelo menos 8 caracteres, com letras e números.');
      return;
    }
    if (nova !== el('redefinir-confirma').value) { mostrarErro('redefinir-erro', 'As duas senhas não são iguais.'); return; }
    var botao = formRedefinir.querySelector('button[type=submit]');
    botao.disabled = true;
    api('POST', 'esqueci.php', { acao: 'redefinir', codigo: codigoRedefinir, senha: nova }).then(function (r) {
      history.replaceState(null, '', window.location.pathname);
      mostrarLogin('');
      el('login-email').value = r.email;
      el('login-senha').focus();
      avisar(escapar(r.mensagem));
    }).catch(function (erro) {
      mostrarErro('redefinir-erro', erro.message);
      if (erro.codigo === 'LINK_INVALIDO') { el('redefinir-voltar').hidden = false; } else { botao.disabled = false; }
    });
  });

  formSenha.addEventListener('submit', function (evento) {
    evento.preventDefault();
    var nova = el('senha-nova').value;
    if (nova.length < 8 || !/[A-Za-z]/.test(nova) || !/\d/.test(nova)) {
      mostrarErro('senha-erro', 'A nova senha precisa ter pelo menos 8 caracteres, com letras e números.');
      return;
    }
    if (nova !== el('senha-confirma').value) { mostrarErro('senha-erro', 'As duas senhas novas não são iguais.'); return; }

    var botao = formSenha.querySelector('button[type=submit]');
    botao.disabled = true;
    api('POST', 'senha.php', { senha_atual: el('senha-atual').value, senha_nova: nova })
      .then(function () {
        formSenha.reset();
        avisar('Senha criada. Bem-vindo(a)!');
        return carregarSessao();
      })
      .catch(function (erro) { mostrarErro('senha-erro', erro.message); })
      .then(function () { botao.disabled = false; });
  });

  function sair() {
    api('POST', 'sair.php', {}).catch(function () { /* sai mesmo sem resposta */ });
    sessao = null;
    window.location.hash = '';
    mostrarLogin('');
  }

  /** Pergunta ao servidor quem esta logado e abre o sistema (ou o login). */
  function carregarSessao() {
    return api('GET', 'sessao.php').then(function (dados) {
      sessao = dados;
      if (dados.usuario.trocar_senha) { mostrarTrocaSenha(''); return; }
      preencherTopo();
      telaLogin.hidden = true;
      telaSistema.hidden = false;
      if (!window.location.hash || window.location.hash === '#sair') { window.location.hash = PAGINA_INICIAL; }
      mostrarPagina();
    }, function () { mostrarLogin(''); });
  }

  function preencherTopo() {
    var u = sessao.usuario;
    var e = sessao.empresa || {};
    var primeiroNome = (u.nome || '').split(' ')[0];
    var iniciais = (u.nome || '?').split(' ').filter(Boolean).slice(0, 2).map(function (p) { return p[0]; }).join('').toUpperCase();

    el('usuario-nome').textContent = primeiroNome;
    el('usuario-iniciais').textContent = iniciais;
    el('saudacao-nome').textContent = primeiroNome;
    el('empresa-nome').textContent = e.nome_fantasia || e.razao_social || '';
    el('empresa-cnpj').textContent = formatarDoc(e.cnpj || '');
    el('selo-ambiente').hidden = e.ambiente !== 'HOMOLOGACAO';
    el('emitir-padrao').textContent = e.padrao === 'SALVADOR' ? 'Prefeitura de Salvador (RPS)' : 'Padrão Nacional (DPS)';

    // Certificado no rodape do menu
    var cert = e.certificado || {};
    var selo = el('selo-certificado');
    var dias = cert.dias_restantes;
    selo.classList.remove('selo-certificado--alerta');
    if (!cert.validade) {
      el('certificado-titulo').textContent = 'Sem certificado';
      el('certificado-texto').textContent = 'fale com o suporte';
      selo.classList.add('selo-certificado--alerta');
    } else {
      el('certificado-titulo').textContent = dias < 0 ? 'Certificado vencido' : 'Certificado A1 válido';
      el('certificado-texto').textContent = 'até ' + dataCurta(cert.validade);
      if (dias <= 30) { selo.classList.add('selo-certificado--alerta'); }
    }
    selo.title = el('certificado-titulo').textContent + ' ' + el('certificado-texto').textContent;
  }

  // ==================================================================
  // 3. MENU, TELAS E TEMA
  // ==================================================================

  function mostrarPagina() {
    var nome = window.location.hash.replace('#', '') || PAGINA_INICIAL;
    if (nome === 'sair') { sair(); return; }
    if (nome.indexOf('redefinir=') === 0) { abrirRedefinicao(nome.slice('redefinir='.length)); return; }
    if (telaSistema.hidden) { return; }

    var achou = false;
    document.querySelectorAll('[data-pagina]').forEach(function (secao) {
      var ativa = secao.getAttribute('data-pagina') === nome;
      secao.hidden = !ativa;
      if (ativa) {
        achou = true;
        document.title = secao.getAttribute('data-titulo') + ' · Portal Gestão Fiscal NFS-e';
      }
    });
    if (!achou) { window.location.hash = PAGINA_INICIAL; return; }

    document.querySelectorAll('.menu__item').forEach(function (link) {
      if (link.getAttribute('href') === '#' + nome) { link.setAttribute('aria-current', 'page'); } else { link.removeAttribute('aria-current'); }
    });

    pararAtualizacao();
    if (nome === 'inicio') { carregarInicio(); }
    if (nome === 'emitir') { abrirEmissao(); }
    if (nome === 'notas') { carregarNotas(); }
    if (nome === 'servicos') { carregarListaServicos(); }
    if (nome === 'tomadores') { carregarListaTomadores(); }
    if (nome === 'empresa') { carregarEmpresa(); }
    if (nome === 'usuarios') { carregarUsuarios(); }
    if (nome === 'recorrencias') { carregarRecorrencias(); }

    fecharMenuCelular();
    window.scrollTo(0, 0);
  }
  window.addEventListener('hashchange', mostrarPagina);

  // Botao hamburguer: no celular abre a gaveta; no computador recolhe para icones.
  var botaoMenu = el('botao-menu');
  var telaPequena = window.matchMedia('(max-width: 860px)');

  function fecharMenuCelular() {
    telaSistema.classList.remove('menu-aberto');
    atualizarBotaoMenu();
  }
  function atualizarBotaoMenu() {
    var visivel = telaPequena.matches
      ? telaSistema.classList.contains('menu-aberto')
      : !telaSistema.classList.contains('menu-recolhido');
    botaoMenu.setAttribute('aria-expanded', visivel ? 'true' : 'false');
  }
  botaoMenu.addEventListener('click', function () {
    if (telaPequena.matches) {
      telaSistema.classList.toggle('menu-aberto');
    } else {
      var recolhido = telaSistema.classList.toggle('menu-recolhido');
      gravarPreferencia('portal_menu_recolhido', recolhido ? '1' : null);
    }
    atualizarBotaoMenu();
  });
  el('cortina').addEventListener('click', fecharMenuCelular);
  document.addEventListener('keydown', function (evento) { if (evento.key === 'Escape') { fecharMenuCelular(); } });
  telaPequena.addEventListener('change', fecharMenuCelular);

  document.querySelectorAll('.menu__item').forEach(function (link) {
    var nome = link.querySelector('span');
    if (nome) { link.setAttribute('title', nome.textContent); }
  });
  if (lerPreferencia('portal_menu_recolhido') === '1') { telaSistema.classList.add('menu-recolhido'); }
  atualizarBotaoMenu();

  // Tema claro/escuro
  var iconeTema = el('icone-tema');
  function temaAtualEscuro() {
    var escolhido = document.documentElement.getAttribute('data-theme');
    if (escolhido) { return escolhido === 'dark'; }
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }
  function aplicarTema(tema) {
    if (tema) { document.documentElement.setAttribute('data-theme', tema); }
    iconeTema.textContent = temaAtualEscuro() ? 'light_mode' : 'dark_mode';
  }
  el('botao-tema').addEventListener('click', function () {
    var novo = temaAtualEscuro() ? 'light' : 'dark';
    gravarPreferencia('portal_tema', novo);
    aplicarTema(novo);
  });

  // ==================================================================
  // 4. TELA INICIAL
  // ==================================================================

  var timerInicio = null;
  function pararAtualizacao() { clearTimeout(timerInicio); timerInicio = null; }

  function carregarInicio() {
    api('GET', 'inicio.php').then(function (dados) {
      desenharResumo(dados.resumo);
      desenharNotas(el('inicio-notas'), dados.notas);
      // Enquanto houver nota na fila, atualiza sozinho.
      if (window.location.hash.replace('#', '') === 'inicio') {
        timerInicio = setTimeout(carregarInicio, ATUALIZAR_INICIO_MS);
      }
    }).catch(function (erro) {
      if (erro.codigo !== 'SESSAO_EXPIRADA') { avisar(escapar(erro.message), 'error_outline'); }
    });
  }

  var NOMES_MES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
  function mesPorExtenso(anoMes) {
    var partes = String(anoMes || '').split('-');
    return partes.length === 2 ? NOMES_MES[Number(partes[1]) - 1] + '/' + partes[0] : '';
  }

  function desenharResumo(r) {
    el('inicio-subtitulo').textContent = 'Resumo de ' + mesPorExtenso(r.mes) + ' da sua empresa.';
    var s = r.por_status || {};
    var rejeitadas = (s.REJEITADO || 0) + (s.ERRO || 0);
    el('kpi-notas').textContent = r.notas_no_mes;
    el('kpi-notas-detalhe').textContent = (s.AUTORIZADO || 0) + ' autorizadas · ' + rejeitadas + ' com problema · ' + (s.CANCELADO || 0) + ' canceladas';
    el('kpi-faturado').textContent = reais(r.faturado_no_mes);
    el('kpi-andamento').textContent = r.em_andamento;

    var f = r.franquia || {};
    el('kpi-franquia').textContent = f.usado;
    el('kpi-franquia-limite').textContent = f.limite ? '/ ' + f.limite : 'sem limite';
    el('kpi-franquia-barra').hidden = !f.limite;
    el('kpi-franquia-cheio').style.width = f.limite ? Math.min(100, Math.round(f.usado * 100 / f.limite)) + '%' : '0%';

    el('menu-rejeitadas').textContent = rejeitadas;
    el('menu-rejeitadas').hidden = rejeitadas === 0;

    var avisos = r.avisos || [];
    var icones = { NOTA_REJEITADA: 'error_outline', SEM_CERTIFICADO: 'gpp_maybe', CERTIFICADO_VENCENDO: 'verified_user', RECORRENCIA_PENDENTE: 'event_repeat' };
    el('inicio-avisos').innerHTML = avisos.length === 0
      ? '<div class="atalho"><i class="icone">task_alt</i><div><b>Tudo certo por aqui</b><small>Nenhuma nota com problema e certificado em dia.</small></div></div>'
      : avisos.map(function (a) {
        return '<div class="atalho"><i class="icone">' + (icones[a.tipo] || 'info') + '</i><div><b>' + escapar(a.titulo) + '</b><small>' + escapar(a.texto) + '</small></div></div>';
      }).join('');
  }

  function desenharNotas(corpoTabela, notas) {
    if (!notas || notas.length === 0) {
      corpoTabela.innerHTML = '<tr class="tabela-vazia"><td colspan="5">Nenhuma nota ainda. <a href="#emitir">Emita a primeira</a>.</td></tr>';
      return;
    }
    corpoTabela.innerHTML = notas.map(function (n) {
      var numero = n.numero || (n.dps_rps ? 'DPS ' + n.dps_rps : '—');
      var arquivos = n.links && n.links.pdf
        ? '<br><a class="acao-arquivo" href="' + escapar(linkApi(n.links.pdf)) + '" target="_blank" rel="noopener">PDF</a><a class="acao-arquivo" href="' + escapar(linkApi(n.links.xml)) + '">XML</a>'
        : '';
      return '<tr>'
        + '<td class="mono">' + escapar(numero) + '</td>'
        + '<td>' + escapar(n.tomador || '—') + '</td>'
        + '<td class="num">' + escapar(dataCurta(n.criado_em)) + '</td>'
        + '<td class="direita num">' + reais(n.valor) + '</td>'
        + '<td' + (n.motivo ? ' title="' + escapar(n.motivo) + '"' : '') + '>' + pilulaStatus(n.status) + arquivos + '</td>'
        + '</tr>';
    }).join('');
  }

  // ==================================================================
  // 5. TELA "EMITIR NOTA"
  // ==================================================================

  var formEmitir = el('form-emitir');
  var servicos = [];        // servicos salvos da empresa
  var chaveEnvio = null;    // identifica este preenchimento (evita nota em dobro)
  var dadosTomador = {};    // endereco completo vindo da consulta (codigo IBGE, UF)

  function novaChave() {
    chaveEnvio = (window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID()
      : 'p' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  }

  function abrirEmissao() {
    if (!chaveEnvio) { novaChave(); }
    if (!el('val-competencia').value) {
      var hoje = new Date();
      el('val-competencia').value = hoje.getFullYear() + '-' + String(hoje.getMonth() + 1).padStart(2, '0');
      el('val-competencia').max = el('val-competencia').value;
    }
    carregarServicos();
    carregarTomadores();
    // Veio da tela Tomadores ou do detalhe de uma nota: ja preenche o tomador.
    if (tomadorPendente) {
      el('tom-doc').value = tomadorPendente;
      tomadorPendente = null;
      buscarDocumento(true);
    }
    atualizar();
  }

  function carregarServicos() {
    return api('GET', 'servicos.php').then(function (r) {
      servicos = r.servicos || [];
      var pendente = servicoPendente;
      servicoPendente = null;
      var escolhido = pendente || el('srv-salvo').value;
      el('srv-salvo').innerHTML = (servicos.length ? '<option value="">Selecione um serviço salvo…</option>' : '')
        + servicos.map(function (s) {
          var codigo = s.item_lista_servico || s.codigo_tributacao_nacional || '';
          var aliquota = s.aliquota_iss !== null ? ' · ISS ' + formatarValor(s.aliquota_iss).replace(',00', '') + '%' : '';
          return '<option value="' + s.id + '">' + escapar(s.nome + ' — ' + codigo + aliquota) + '</option>';
        }).join('')
        + '<option value="novo">+ Outro serviço (digitar os códigos)</option>';
      if (escolhido && el('srv-salvo').querySelector('option[value="' + escolhido + '"]')) { el('srv-salvo').value = escolhido; }
      else if (!servicos.length) { el('srv-salvo').value = 'novo'; }
      else if (servicos.length === 1) { el('srv-salvo').value = String(servicos[0].id); }
      aplicarServico(!!pendente);
    }).catch(function () { /* lista vazia: segue com "Outro servico" */ });
  }

  function carregarTomadores() {
    api('GET', 'tomadores.php').then(function (r) {
      var lista = r.tomadores || [];
      el('tom-salvo').innerHTML = '<option value="">' + (lista.length ? 'Selecione…' : 'Nenhum ainda (salvo na 1ª nota)') + '</option>'
        + lista.map(function (t) {
          return '<option value="' + escapar(t.cpf_cnpj) + '">' + escapar(t.nome) + '</option>';
        }).join('');
      el('tom-salvo').disabled = lista.length === 0;
    }).catch(function () { /* sem lista */ });
  }

  // ---- Mensagens debaixo dos campos ----
  function dica(id, texto, tipo) {
    var alvo = el(id);
    alvo.textContent = texto;
    alvo.className = 'dica' + (tipo ? ' ' + tipo : '');
  }
  function marcarAuto(ids) {
    ids.forEach(function (id) { if (el(id).value) { el(id).closest('.campo').classList.add('preenchido-auto'); } });
  }
  function limparAuto() {
    document.querySelectorAll('.preenchido-auto').forEach(function (c) { c.classList.remove('preenchido-auto'); });
  }

  // ---- Tomador ----
  function preencherTomador(t) {
    dadosTomador = t;
    el('tom-nome').value = t.nome || '';
    if (t.email) { el('tom-email').value = t.email; }
    el('tom-cep').value = formatarCep(t.cep || '');
    el('tom-logradouro').value = t.logradouro || '';
    el('tom-numero').value = t.numero || '';
    el('tom-bairro').value = t.bairro || '';
    el('tom-cidade').value = t.cidade ? t.cidade + ' / ' + (t.uf || '') : '';
    marcarAuto(['tom-nome', 'tom-email', 'tom-cep', 'tom-logradouro', 'tom-numero', 'tom-bairro', 'tom-cidade']);
    resumirEndereco();
  }

  function resumirEndereco() {
    var partes = [el('tom-logradouro').value, el('tom-numero').value].filter(Boolean).join(', ');
    el('tom-endereco-resumo').textContent = partes ? partes + (el('tom-cidade').value ? ' · ' + el('tom-cidade').value : '') : 'opcional no Padrão Nacional';
  }

  var ultimoDocumentoBuscado = '';
  function buscarDocumento(forcar) {
    var numeros = soNumeros(el('tom-doc').value);
    var campo = el('tom-doc').closest('.campo');
    campo.classList.remove('invalido');
    if (!numeros) { dica('tom-doc-info', 'Com CNPJ, nome e endereço são preenchidos sozinhos.'); atualizar(); return; }
    el('tom-doc').value = formatarDoc(numeros);

    if ((numeros.length !== 11 && numeros.length !== 14) || !documentoValido(numeros)) {
      campo.classList.add('invalido');
      dica('tom-doc-info', numeros.length === 11 || numeros.length === 14
        ? 'Os dígitos verificadores não conferem. Confira o número.'
        : 'Digite 11 números (CPF) ou 14 números (CNPJ).', 'erro');
      atualizar();
      return;
    }
    if (numeros === ultimoDocumentoBuscado && !forcar) { atualizar(); return; }
    ultimoDocumentoBuscado = numeros;

    limparAuto();
    dica('tom-doc-info', 'Buscando…');
    api('GET', 'consulta.php?' + (numeros.length === 14 ? 'cnpj=' : 'cpf=') + numeros)
      .then(function (t) {
        preencherTomador(t);
        dica('tom-doc-info', (t.tipo === 'SALVO' ? 'Tomador dos seus cadastros.' : 'Dados da Receita Federal preenchidos.') + ' Confira o e-mail.', 'ok');
        el('tom-email').focus();
      })
      .catch(function (erro) {
        dadosTomador = {};
        dica('tom-doc-info', numeros.length === 11 ? 'CPF válido. Para pessoa física, digite o nome.' : erro.message, numeros.length === 11 ? 'ok' : '');
        el('tom-nome').focus();
      })
      .then(atualizar);
  }

  function buscarCep() {
    var cep = soNumeros(el('tom-cep').value);
    if (cep.length !== 8 || cep === (dadosTomador.cep || '')) { return; }
    el('tom-cep').value = formatarCep(cep);
    api('GET', 'consulta.php?cep=' + cep).then(function (end) {
      dadosTomador.cep = cep;
      dadosTomador.codigo_municipio = end.codigo_municipio;
      dadosTomador.uf = end.uf;
      el('tom-logradouro').value = end.logradouro || el('tom-logradouro').value;
      el('tom-bairro').value = end.bairro || el('tom-bairro').value;
      el('tom-cidade').value = end.cidade + ' / ' + end.uf;
      marcarAuto(['tom-logradouro', 'tom-bairro', 'tom-cidade']);
      resumirEndereco();
      el('tom-numero').focus();
    }).catch(function (erro) { avisar(escapar(erro.message), 'error_outline'); });
  }

  el('tom-doc').addEventListener('blur', function () { buscarDocumento(false); });
  el('botao-buscar-doc').addEventListener('click', function () { buscarDocumento(true); });
  el('tom-cep').addEventListener('blur', buscarCep);
  el('tom-numero').addEventListener('input', resumirEndereco);
  el('tom-salvo').addEventListener('change', function () {
    if (!this.value) { return; }
    el('tom-doc').value = this.value;
    buscarDocumento(true);
  });

  // ---- Servico ----
  function servicoEscolhido() {
    var id = el('srv-salvo').value;
    for (var i = 0; i < servicos.length; i++) { if (String(servicos[i].id) === id) { return servicos[i]; } }
    return null;
  }

  function aplicarServico(trocarDescricao) {
    var srv = servicoEscolhido();
    var novo = el('srv-salvo').value === 'novo';
    el('srv-codigos').hidden = !srv;
    el('srv-manual').hidden = !novo;
    if (srv) {
      el('srv-cod-nacional').textContent = srv.codigo_tributacao_nacional || '—';
      el('srv-item').textContent = srv.item_lista_servico || '—';
      el('srv-aliquota-txt').textContent = srv.aliquota_iss !== null ? formatarValor(srv.aliquota_iss) + '%' : '—';
      if (trocarDescricao !== false || !el('srv-descricao').value) { el('srv-descricao').value = srv.descricao; }
      if (srv.valor_padrao && !el('val-servico').value) { el('val-servico').value = formatarValor(srv.valor_padrao); }
    }
    atualizar();
  }
  el('srv-salvo').addEventListener('change', function () { aplicarServico(true); });
  el('srv-salvar').addEventListener('change', function () { el('srv-nome-campo').hidden = !this.checked; });
  ['srv-descricao', 'srv-in-nacional', 'srv-in-item', 'srv-in-aliquota', 'tom-nome'].forEach(function (id) {
    el(id).addEventListener('input', atualizar);
  });

  function aliquotaAtual() {
    var srv = servicoEscolhido();
    if (srv) { return srv.aliquota_iss || 0; }
    return el('srv-salvo').value === 'novo' ? lerValor(el('srv-in-aliquota').value) : 0;
  }

  // ---- Valores ----
  var CAMPOS_VALOR = ['val-servico', 'val-desconto', 'val-ir', 'val-inss', 'val-pis', 'val-cofins', 'val-csll'];
  CAMPOS_VALOR.forEach(function (id) {
    el(id).addEventListener('input', atualizar);
    el(id).addEventListener('blur', function () {
      if (this.value.trim() !== '') { this.value = formatarValor(lerValor(this.value)); }
    });
  });
  el('val-iss-retido').addEventListener('change', atualizar);

  // ---- Resumo ao vivo + o que falta ----
  function atualizar() {
    var aliquota = aliquotaAtual();
    var bruto = lerValor(el('val-servico').value);
    var desconto = lerValor(el('val-desconto').value);
    var base = Math.max(bruto - desconto, 0);
    var iss = Math.round(base * aliquota) / 100;
    var retido = el('val-iss-retido').checked;
    var federais = ['val-ir', 'val-inss', 'val-pis', 'val-cofins', 'val-csll'].reduce(function (soma, id) { return soma + lerValor(el(id).value); }, 0);
    var retencoes = federais + (retido ? iss : 0);

    el('res-bruto').textContent = reais(bruto);
    el('res-desconto').textContent = desconto ? '− ' + reais(desconto) : reais(0);
    el('res-aliquota').textContent = formatarValor(aliquota).replace(',00', '') + '%';
    el('res-iss').textContent = reais(iss);
    el('res-iss-situacao').textContent = retido ? '(retido)' : '(você recolhe)';
    el('res-retencoes').textContent = retencoes ? '− ' + reais(retencoes) : reais(0);
    el('res-liquido').textContent = reais(Math.max(base - retencoes, 0));
    el('srv-contador').textContent = el('srv-descricao').value.length;

    var doc = soNumeros(el('tom-doc').value);
    var srv = servicoEscolhido();
    var novo = el('srv-salvo').value === 'novo';
    var codigosNovo = soNumeros(el('srv-in-nacional').value).length === 6 || /^\d{1,2}\.\d{2}$/.test(el('srv-in-item').value.trim());
    var itens = [
      { ok: (doc.length === 11 || doc.length === 14) && documentoValido(doc), texto: 'CPF ou CNPJ do tomador válido' },
      { ok: el('tom-nome').value.trim().length > 1, texto: 'Nome do tomador' },
      { ok: (!!srv || (novo && codigosNovo)) && el('srv-descricao').value.trim().length > 4, texto: 'Serviço e descrição' },
      { ok: bruto > 0, texto: 'Valor maior que zero' }
    ];
    el('checagem').innerHTML = itens.map(function (item) {
      return '<li class="' + (item.ok ? 'ok' : '') + '">' + item.texto + '</li>';
    }).join('');

    var etapas = formEmitir.querySelectorAll('.etapa');
    etapas[0].classList.toggle('completa', itens[0].ok && itens[1].ok);
    etapas[1].classList.toggle('completa', itens[2].ok);
    etapas[2].classList.toggle('completa', itens[3].ok);

    el('botao-emitir').disabled = enviando || !itens.every(function (i) { return i.ok; });
  }

  // Enter num campo nao envia a nota sem querer
  formEmitir.addEventListener('keydown', function (evento) {
    if (evento.key === 'Enter' && evento.target.tagName === 'INPUT') { evento.preventDefault(); }
  });

  // ---- Erros devolvidos pela API, ao lado de cada campo ----
  var CAMPO_PARA_ID = {
    'tomador.cpf_cnpj': 'tom-doc', 'tomador.nome': 'tom-nome', 'tomador.email': 'tom-email',
    'tomador.endereco.cep': 'tom-cep', 'tomador.endereco.logradouro': 'tom-logradouro', 'tomador.endereco.numero': 'tom-numero',
    'tomador.endereco.bairro': 'tom-bairro', 'tomador.endereco.codigo_municipio': 'tom-cep',
    'servico.descricao': 'srv-descricao', 'servico.codigo_tributacao_nacional': 'srv-in-nacional',
    'servico.item_lista_servico': 'srv-in-item', 'valores.aliquota_iss': 'srv-in-aliquota',
    'valores.valor_servicos': 'val-servico', 'competencia': 'val-competencia'
  };

  function limparErrosCampos() {
    formEmitir.querySelectorAll('.erro-campo').forEach(function (e) { e.remove(); });
    formEmitir.querySelectorAll('.campo.invalido').forEach(function (c) { if (c.querySelector('#tom-doc') === null) { c.classList.remove('invalido'); } });
    mostrarErro('emitir-retorno', '');
  }

  function mostrarErrosCampos(erros) {
    var semCampo = [];
    erros.forEach(function (e) {
      var id = CAMPO_PARA_ID[e.campo];
      var alvo = id && el(id);
      if (!alvo || alvo.offsetParent === null) {
        // Campo escondido (ex.: codigo de um servico salvo): mostra no resumo.
        semCampo.push(e.mensagem + (e.correcao ? ' ' + e.correcao : ''));
        return;
      }
      var campo = alvo.closest('.campo');
      campo.classList.add('invalido');
      var msg = document.createElement('small');
      msg.className = 'erro-campo';
      msg.textContent = e.mensagem + (e.correcao ? ' ' + e.correcao : '');
      campo.appendChild(msg);
      var detalhes = alvo.closest('details');
      if (detalhes) { detalhes.open = true; }
    });
    if (semCampo.length) { mostrarErro('emitir-retorno', semCampo.join(' ')); }
    var primeiro = formEmitir.querySelector('.campo.invalido input, .campo.invalido select, .campo.invalido textarea');
    if (primeiro) { primeiro.focus(); }
  }

  // ---- Emitir ----
  var enviando = false;

  function montarEnvio() {
    var srv = servicoEscolhido();
    var servico = srv ? { id: srv.id } : {
      codigo_tributacao_nacional: soNumeros(el('srv-in-nacional').value),
      item_lista_servico: el('srv-in-item').value.trim(),
      aliquota_iss: el('srv-in-aliquota').value.trim() === '' ? '' : lerValor(el('srv-in-aliquota').value),
      salvar: el('srv-salvar').checked,
      nome: el('srv-in-nome').value.trim()
    };
    var cidadeUf = el('tom-cidade').value.split(' / ');
    return {
      chave_envio: chaveEnvio,
      tomador: {
        cpf_cnpj: soNumeros(el('tom-doc').value),
        nome: el('tom-nome').value.trim(),
        email: el('tom-email').value.trim(),
        cep: soNumeros(el('tom-cep').value),
        logradouro: el('tom-logradouro').value.trim(),
        numero: el('tom-numero').value.trim(),
        bairro: el('tom-bairro').value.trim(),
        cidade: cidadeUf[0] || '',
        uf: dadosTomador.uf || cidadeUf[1] || '',
        codigo_municipio: dadosTomador.codigo_municipio || ''
      },
      servico: servico,
      descricao: el('srv-descricao').value.trim(),
      competencia: el('val-competencia').value,
      valores: {
        valor_servicos: lerValor(el('val-servico').value),
        iss_retido: el('val-iss-retido').checked,
        desconto_incondicionado: lerValor(el('val-desconto').value),
        valor_ir: lerValor(el('val-ir').value),
        valor_inss: lerValor(el('val-inss').value),
        valor_pis: lerValor(el('val-pis').value),
        valor_cofins: lerValor(el('val-cofins').value),
        valor_csll: lerValor(el('val-csll').value)
      }
    };
  }

  function mostrarResultado(nota) {
    var caixa = el('emitir-resultado');
    var numero = nota.nfse && nota.nfse.numero ? 'NFS-e nº ' + nota.nfse.numero : (nota.dps_rps ? 'DPS nº ' + nota.dps_rps.numero : 'Nota');
    var msg = (nota.mensagens && nota.mensagens[0]) || {};
    var html;
    if (nota.status === 'AUTORIZADO') {
      caixa.className = 'resultado resultado--ok';
      html = '<b>' + escapar(numero) + ' autorizada</b><span>PDF e XML prontos.</span>'
        + '<div class="resultado__acoes">'
        + '<a class="btn btn--primario" href="' + escapar(linkApi(nota.links.pdf)) + '" target="_blank" rel="noopener"><i class="icone icone--p">picture_as_pdf</i> PDF</a>'
        + '<a class="btn btn--secundario" href="' + escapar(linkApi(nota.links.xml)) + '"><i class="icone icone--p">code</i> XML</a>'
        + '<button type="button" class="btn btn--secundario" data-acao="nova">Emitir outra</button></div>';
    } else if (nota.status === 'REJEITADO' || nota.status === 'ERRO') {
      caixa.className = 'resultado resultado--erro';
      html = '<b>A prefeitura não autorizou</b><span>' + escapar((msg.mensagem || 'Veja o motivo na lista de notas.') + (msg.correcao ? ' ' + msg.correcao : '')) + '</span>'
        + '<span class="dica">Corrija os dados e clique em "Emitir nota" de novo: a mesma nota é reenviada.</span>';
    } else {
      caixa.className = 'resultado resultado--andamento';
      html = '<b>' + escapar(numero) + ' enviada</b><span>A prefeitura ainda está processando. O status atualiza sozinho na tela inicial.</span>'
        + '<div class="resultado__acoes"><a class="btn btn--secundario" href="#inicio">Acompanhar</a>'
        + '<button type="button" class="btn btn--secundario" data-acao="nova">Emitir outra</button></div>';
    }
    caixa.innerHTML = html;
    caixa.hidden = false;
    var nova = caixa.querySelector('[data-acao="nova"]');
    if (nova) { nova.addEventListener('click', limparFormulario); }
  }

  function limparFormulario() {
    formEmitir.reset();
    limparAuto();
    limparErrosCampos();
    dadosTomador = {};
    ultimoDocumentoBuscado = '';
    el('emitir-resultado').hidden = true;
    el('val-competencia').value = el('val-competencia').max;
    dica('tom-doc-info', 'Com CNPJ, nome e endereço são preenchidos sozinhos.');
    resumirEndereco();
    novaChave();
    carregarServicos();
    carregarTomadores();
    el('tom-doc').focus();
  }

  formEmitir.addEventListener('submit', function (evento) {
    evento.preventDefault();
    if (el('botao-emitir').disabled) { return; }
    enviando = true;
    limparErrosCampos();
    el('emitir-resultado').hidden = true;
    var botao = el('botao-emitir');
    botao.disabled = true;
    botao.innerHTML = '<i class="icone icone--p">hourglass_top</i> Enviando para a prefeitura…';

    api('POST', 'emitir.php', montarEnvio())
      .then(function (r) {
        mostrarResultado(r.nota);
        if (r.nota.status === 'AUTORIZADO') {
          avisar('Nota autorizada.');
          novaChave(); // proximo envio e outra nota
        }
        carregarServicos();
      })
      .catch(function (erro) {
        if (erro.erros && erro.erros.length) { mostrarErrosCampos(erro.erros); }
        if (!erro.erros || !erro.erros.length || el('emitir-retorno').hidden) {
          if (!formEmitir.querySelector('.erro-campo')) { mostrarErro('emitir-retorno', erro.message); }
        }
      })
      .then(function () {
        enviando = false;
        botao.innerHTML = '<i class="icone icone--p">send</i> Emitir nota';
        atualizar();
      });
  });

  // ==================================================================
  // 6. MINHAS NOTAS, SERVICOS, TOMADORES, MINHA EMPRESA, USUARIOS E RECORRENCIAS
  // ==================================================================

  // ---- Utilidades das telas de lista ----

  /** Espera a pessoa parar de digitar antes de buscar (evita uma chamada por letra). */
  function depoisDeDigitar(funcao, ms) {
    var timer = null;
    return function () { clearTimeout(timer); timer = setTimeout(funcao, ms || 300); };
  }

  function abrirJanela(id) { var j = el(id); delete j.dataset.sujo; if (!j.open) { j.showModal(); } }
  function fecharJanela(id) { var j = el(id); if (j.open) { j.close(); } }

  // Fechar pelo X, Esc ou clique fora: se a pessoa ja digitou algo, pergunta antes.
  function podeFechar(janela) {
    return janela.dataset.sujo !== '1' || window.confirm('Você digitou dados que ainda não foram salvos. Fechar mesmo assim?');
  }
  document.querySelectorAll('dialog.janela').forEach(function (janela) {
    var comecouFora = false;
    function fora(evento) {
      var r = janela.getBoundingClientRect();
      return evento.target === janela && (evento.clientX < r.left || evento.clientX > r.right || evento.clientY < r.top || evento.clientY > r.bottom);
    }
    janela.addEventListener('input', function () { janela.dataset.sujo = '1'; });
    janela.addEventListener('cancel', function (evento) { if (!podeFechar(janela)) { evento.preventDefault(); } });
    // So fecha se apertou E soltou fora (arrastar uma selecao de dentro para fora nao fecha).
    janela.addEventListener('mousedown', function (evento) { comecouFora = fora(evento); });
    janela.addEventListener('click', function (evento) {
      if (comecouFora && fora(evento) && podeFechar(janela)) { janela.close(); }
      comecouFora = false;
    });
  });
  document.querySelectorAll('dialog [data-fechar]').forEach(function (botao) {
    botao.addEventListener('click', function () { var j = botao.closest('dialog'); if (podeFechar(j)) { j.close(); } });
  });

  /**
   * Mostra os erros da API ao lado de cada campo de um formulario.
   * mapa: nome do campo na API -> id do input. O que nao tiver campo vai para idGeral.
   */
  function mostrarErrosForm(form, erros, mapa, idGeral) {
    form.querySelectorAll('.erro-campo').forEach(function (e) { e.remove(); });
    form.querySelectorAll('.campo.invalido').forEach(function (c) { c.classList.remove('invalido'); });
    mostrarErro(idGeral, '');
    var semCampo = [];
    (erros || []).forEach(function (e) {
      var alvo = mapa[e.campo] && el(mapa[e.campo]);
      if (!alvo) { semCampo.push(e.mensagem); return; }
      var campo = alvo.closest('.campo');
      campo.classList.add('invalido');
      var msg = document.createElement('small');
      msg.className = 'erro-campo';
      msg.textContent = e.mensagem + (e.correcao ? ' ' + e.correcao : '');
      campo.appendChild(msg);
      var detalhes = alvo.closest('details');
      if (detalhes) { detalhes.open = true; }
    });
    if (semCampo.length) { mostrarErro(idGeral, semCampo.join(' ')); }
    var primeiro = form.querySelector('.campo.invalido input, .campo.invalido textarea');
    if (primeiro) { primeiro.focus(); }
  }

  /** Troca o conteudo de um lugar por "Pergunta? Remover / Nao" (sem a caixa feia do navegador). */
  function confirmarRemocao(lugar, pergunta, aoConfirmar, rotulo) {
    var antes = lugar.innerHTML;
    lugar.innerHTML = '<span class="confirmar">' + escapar(pergunta)
      + ' <button type="button" class="btn btn--perigo" data-sim>' + escapar(rotulo || 'Remover') + '</button>'
      + '<button type="button" class="btn btn--secundario" data-nao>Não</button></span>';
    lugar.querySelector('[data-nao]').addEventListener('click', function (evento) { evento.stopPropagation(); lugar.innerHTML = antes; });
    lugar.querySelector('[data-sim]').addEventListener('click', function (evento) { evento.stopPropagation(); aoConfirmar(); });
    lugar.querySelector('[data-nao]').focus();
  }

  // Ao sair de Servicos/Tomadores para emitir, a tela "Emitir nota" ja abre preenchida.
  var servicoPendente = null;
  var tomadorPendente = null;
  function emitirCom(tipo, valor) {
    if (tipo === 'servico') { servicoPendente = String(valor); } else { tomadorPendente = valor; }
    window.location.hash = 'emitir';
  }

  // ---- Minhas notas ----
  var filtroNotas = { status: '', busca: '', mes: '', pagina: 1 };

  function carregarNotas() {
    var q = 'status=' + encodeURIComponent(filtroNotas.status) + '&busca=' + encodeURIComponent(filtroNotas.busca)
      + '&mes=' + encodeURIComponent(filtroNotas.mes) + '&pagina=' + filtroNotas.pagina;
    el('notas-linhas').classList.add('carregando');
    api('GET', 'notas.php?' + q).then(function (r) {
      desenharListaNotas(r);
      // Nota ainda na prefeitura: confere de novo daqui a pouco.
      var andando = r.notas.some(function (n) { return !n.finalizado; });
      pararAtualizacao();
      if (andando && window.location.hash.replace('#', '') === 'notas') {
        timerInicio = setTimeout(carregarNotas, ATUALIZAR_INICIO_MS);
      }
    }).catch(function (erro) {
      if (erro.codigo !== 'SESSAO_EXPIRADA') { avisar(escapar(erro.message), 'error_outline'); }
    }).then(function () { el('notas-linhas').classList.remove('carregando'); });
  }

  function desenharListaNotas(r) {
    var corpo = el('notas-linhas');
    var filtrando = filtroNotas.status || filtroNotas.busca || filtroNotas.mes;
    if (!r.notas.length) {
      corpo.innerHTML = '<tr class="tabela-vazia"><td colspan="6">' + (filtrando
        ? 'Nenhuma nota com esses filtros. <a href="#notas" data-limpar>Limpar filtros</a>.'
        : 'Nenhuma nota ainda. <a href="#emitir">Emita a primeira</a>.') + '</td></tr>';
      var limpar = corpo.querySelector('[data-limpar]');
      if (limpar) { limpar.addEventListener('click', function (evento) { evento.preventDefault(); limparFiltrosNotas(); }); }
    } else {
      corpo.innerHTML = r.notas.map(function (n) {
        var numero = n.numero ? 'NFS-e ' + n.numero : (n.dps_rps ? 'DPS ' + n.dps_rps : '—');
        var arquivos = n.links && n.links.pdf
          ? '<a class="acao-arquivo" href="' + escapar(linkApi(n.links.pdf)) + '" target="_blank" rel="noopener">PDF</a><a class="acao-arquivo" href="' + escapar(linkApi(n.links.xml)) + '">XML</a>'
          : '';
        return '<tr data-id="' + escapar(n.id) + '" tabindex="0">'
          + '<td class="mono">' + escapar(numero) + '</td>'
          + '<td>' + escapar(n.tomador || '—') + '<span class="sub mono">' + escapar(formatarDoc(n.tomador_documento || '')) + '</span></td>'
          + '<td class="num">' + escapar(dataCurta(n.criado_em)) + '</td>'
          + '<td class="direita num">' + reais(n.valor) + '</td>'
          + '<td' + (n.motivo ? ' title="' + escapar(n.motivo) + '"' : '') + '>' + pilulaStatus(n.status) + '</td>'
          + '<td class="acoes">' + arquivos + '</td>'
          + '</tr>';
      }).join('');
    }
    el('notas-paginacao').hidden = r.total === 0;
    el('notas-contagem').textContent = r.total + (r.total === 1 ? ' nota' : ' notas') + (r.paginas > 1 ? ' · página ' + r.pagina + ' de ' + r.paginas : '');
    el('notas-anterior').disabled = r.pagina <= 1;
    el('notas-proxima').disabled = r.pagina >= r.paginas;
  }

  function limparFiltrosNotas() {
    filtroNotas = { status: '', busca: '', mes: '', pagina: 1 };
    el('notas-busca').value = '';
    el('notas-mes').value = '';
    marcarChip('');
    carregarNotas();
  }

  function marcarChip(status) {
    el('notas-filtro-status').querySelectorAll('.chip').forEach(function (chip) {
      chip.setAttribute('aria-pressed', chip.getAttribute('data-status') === status ? 'true' : 'false');
    });
  }

  el('notas-filtro-status').addEventListener('click', function (evento) {
    var chip = evento.target.closest('.chip');
    if (!chip) { return; }
    filtroNotas.status = chip.getAttribute('data-status');
    filtroNotas.pagina = 1;
    marcarChip(filtroNotas.status);
    carregarNotas();
  });
  el('notas-busca').addEventListener('input', depoisDeDigitar(function () {
    filtroNotas.busca = el('notas-busca').value.trim();
    filtroNotas.pagina = 1;
    carregarNotas();
  }));
  el('notas-mes').addEventListener('change', function () {
    filtroNotas.mes = this.value;
    filtroNotas.pagina = 1;
    carregarNotas();
  });
  el('notas-anterior').addEventListener('click', function () { filtroNotas.pagina--; carregarNotas(); });
  el('notas-proxima').addEventListener('click', function () { filtroNotas.pagina++; carregarNotas(); });

  // Clique (ou Enter) na linha abre o detalhe. Os links de PDF/XML seguem normais.
  function aoEscolherLinha(evento) {
    if (evento.type === 'keydown' && evento.key !== 'Enter') { return; }
    if (evento.target.closest('a, button')) { return; }
    var linha = evento.target.closest('tr[data-id]');
    if (linha) { abrirNota(linha.getAttribute('data-id')); }
  }
  el('notas-linhas').addEventListener('click', aoEscolherLinha);
  el('notas-linhas').addEventListener('keydown', aoEscolherLinha);

  function linhaDetalhe(rotulo, valor) {
    if (valor === null || valor === undefined || valor === '') { return ''; }
    return '<div><dt>' + escapar(rotulo) + '</dt><dd>' + escapar(valor) + '</dd></div>';
  }

  function abrirNota(id) {
    el('nota-titulo').textContent = 'Carregando…';
    el('nota-subtitulo').textContent = '';
    el('nota-corpo').innerHTML = '';
    abrirJanela('janela-nota');
    api('GET', 'notas.php?id=' + encodeURIComponent(id)).then(function (r) {
      desenharNota(r.nota);
    }).catch(function (erro) {
      el('nota-titulo').textContent = 'Nota';
      el('nota-corpo').innerHTML = '<p class="erro">' + escapar(erro.message) + '</p>';
    });
  }

  function desenharNota(n) {
    var t = n.tomador_completo || {};
    var end = t.endereco || {};
    var s = n.servico || {};
    var v = n.valores || {};
    var bruto = Number(v.valor_servicos || 0);
    var desconto = Number(v.desconto_incondicionado || 0);
    var aliquota = Number(v.aliquota_iss || 0);
    var iss = Math.round(Math.max(bruto - desconto, 0) * aliquota) / 100;
    var federais = ['valor_ir', 'valor_inss', 'valor_pis', 'valor_cofins', 'valor_csll'].reduce(function (soma, campo) { return soma + Number(v[campo] || 0); }, 0);
    var retencoes = federais + (v.iss_retido ? iss : 0);

    el('nota-titulo').innerHTML = escapar(n.numero ? 'NFS-e nº ' + n.numero : (n.dps_rps ? 'DPS nº ' + n.dps_rps : 'Nota')) + ' ' + pilulaStatus(n.status);
    el('nota-subtitulo').textContent = 'Enviada em ' + dataCurta(n.criado_em) + (n.padrao === 'SALVADOR' ? ' · Prefeitura de Salvador' : ' · Padrão Nacional');

    var html = '';
    // O que a prefeitura disse (rejeicao, erro) vem primeiro: e o que a pessoa precisa resolver.
    if (n.mensagens && n.mensagens.length && n.status !== 'AUTORIZADO') {
      html += '<div class="resultado resultado--' + (n.finalizado ? 'erro' : 'andamento') + '"><b>' + (n.finalizado ? 'O que a prefeitura disse' : 'Situação') + '</b>'
        + n.mensagens.map(function (m) {
          return '<span>' + escapar(m.mensagem || '') + (m.correcao ? ' <b>Como corrigir:</b> ' + escapar(m.correcao) : '') + (m.codigo ? ' <span class="apagado mono">(' + escapar(m.codigo) + ')</span>' : '') + '</span>';
        }).join('') + '</div>';
    } else if (!n.finalizado) {
      html += '<div class="resultado resultado--andamento"><b>Na prefeitura</b><span>A nota ainda está sendo processada. Esta lista se atualiza sozinha.</span></div>';
    }

    html += blocoCancelamento(n);

    var acoes = '';
    if (n.links && n.links.pdf) {
      acoes += '<a class="btn btn--primario" href="' + escapar(linkApi(n.links.pdf)) + '" target="_blank" rel="noopener"><i class="icone icone--p">picture_as_pdf</i> Baixar PDF</a>'
        + '<a class="btn btn--secundario" href="' + escapar(linkApi(n.links.xml)) + '"><i class="icone icone--p">code</i> Baixar XML</a>';
    }
    if (t.cpf_cnpj) {
      acoes += '<button type="button" class="btn btn--secundario" data-emitir-para="' + escapar(t.cpf_cnpj) + '"><i class="icone icone--p">add</i> Nova nota para este tomador</button>';
    }
    var canc = n.cancelamento || {};
    if (canc.pode) {
      acoes += '<button type="button" class="btn btn--secundario btn--perigo-leve" data-cancelar><i class="icone icone--p">block</i> Cancelar nota</button>';
    }
    if (acoes) { html += '<div class="detalhe__acoes">' + acoes + '</div>'; }

    html += '<div class="detalhe">'
      + '<section class="detalhe__bloco"><h3>Tomador</h3><dl>'
      + linhaDetalhe('Nome', t.nome) + linhaDetalhe('CPF/CNPJ', formatarDoc(t.cpf_cnpj || '')) + linhaDetalhe('E-mail', t.email)
      + linhaDetalhe('Endereço', [end.logradouro, end.numero].filter(Boolean).join(', '))
      + linhaDetalhe('Cidade', end.uf ? (end.bairro ? end.bairro + ' · ' : '') + end.uf + (end.cep ? ' · ' + formatarCep(end.cep) : '') : '')
      + '</dl></section>'
      + '<section class="detalhe__bloco"><h3>Valores</h3><dl>'
      + linhaDetalhe('Serviço', reais(bruto))
      + (desconto ? linhaDetalhe('Desconto', '− ' + reais(desconto)) : '')
      + linhaDetalhe('ISS ' + formatarValor(aliquota).replace(',00', '') + '%', reais(iss) + (v.iss_retido ? ' (retido)' : ''))
      + (retencoes ? linhaDetalhe('Retenções', '− ' + reais(retencoes)) : '')
      + '<div class="total"><dt>Líquido</dt><dd>' + reais(Math.max(bruto - desconto - retencoes, 0)) + '</dd></div>'
      + '</dl></section>'
      + '<section class="detalhe__bloco detalhe__bloco--largo"><h3>Serviço</h3>'
      + '<p class="detalhe__texto">' + escapar(s.descricao || '—') + '</p><dl>'
      + linhaDetalhe('Código nacional', s.codigo_tributacao_nacional) + linhaDetalhe('Item LC 116', s.item_lista_servico)
      + linhaDetalhe('Código municipal', s.codigo_tributacao_municipal) + linhaDetalhe('CNAE', s.codigo_cnae)
      + linhaDetalhe('Competência', mesPorExtenso(String(n.competencia || '').slice(0, 7)))
      + '</dl></section>';
    if (n.nfse) {
      html += '<section class="detalhe__bloco detalhe__bloco--largo"><h3>Autorização</h3><dl>'
        + linhaDetalhe('Número da NFS-e', n.nfse.numero) + linhaDetalhe('Autorizada em', dataCurta(n.nfse.data_autorizacao))
        + linhaDetalhe('Código de verificação', n.nfse.codigo_verificacao) + linhaDetalhe('Chave de acesso', n.nfse.chave_acesso)
        + '</dl></section>';
    }
    html += '</div>';
    el('nota-corpo').innerHTML = html;

    var botaoCancelar = el('nota-corpo').querySelector('[data-cancelar]');
    if (botaoCancelar) { botaoCancelar.addEventListener('click', function () { abrirCancelamento(n); }); }

    var botaoNova = el('nota-corpo').querySelector('[data-emitir-para]');
    if (botaoNova) {
      botaoNova.addEventListener('click', function () {
        fecharJanela('janela-nota');
        emitirCom('tomador', this.getAttribute('data-emitir-para'));
      });
    }
  }

  // ---- Cancelar nota ----
  // Padrao Nacional: o pedido vai para a fila e a prefeitura confirma depois.
  // Salvador: a pessoa pede no site da prefeitura e registra aqui o protocolo.
  function cancelamentoEmAndamento(canc) {
    return !!canc.evento && (canc.evento.status === 'RECEBIDO' || canc.evento.status === 'PROCESSANDO');
  }

  /** Quadro do detalhe: pedido em andamento, recusado ou ja cancelado. */
  function blocoCancelamento(n) {
    var ev = (n.cancelamento || {}).evento;
    if (!ev) { return ''; }
    var motivo = (ev.motivo ? ev.motivo + ': ' : '') + (ev.texto || '') + (ev.por ? ' · pedido por ' + ev.por : '');
    if (cancelamentoEmAndamento(n.cancelamento)) {
      return '<div class="resultado resultado--andamento"><b>Cancelamento pedido em ' + escapar(dataCurta(ev.criado_em)) + '</b>'
        + '<span>Esperando a prefeitura confirmar. A nota continua valendo até lá.</span><span class="apagado">' + escapar(motivo) + '</span></div>';
    }
    if (ev.status === 'REJEITADO' || ev.status === 'ERRO') {
      return '<div class="resultado resultado--erro"><b>A prefeitura não aceitou o cancelamento</b>'
        + (ev.mensagens || []).map(function (m) {
          return '<span>' + escapar(m.mensagem || '') + (m.correcao ? ' <b>Como resolver:</b> ' + escapar(m.correcao) : '') + '</span>';
        }).join('')
        + '<span class="apagado">A nota continua autorizada. Corrija o que foi pedido e tente de novo.</span></div>';
    }
    if (n.status === 'CANCELADO') {
      return '<div class="resultado"><b>Cancelada em ' + escapar(dataCurta(ev.criado_em)) + '</b><span>' + escapar(motivo) + '</span>'
        + (ev.numero_processo ? '<span class="apagado">Protocolo na prefeitura: ' + escapar(ev.numero_processo) + '</span>' : '') + '</div>';
    }
    return '';
  }

  var formCancelar = el('form-cancelar');
  var notaCancelando = null;
  var CAMPOS_CANC = { motivo_codigo: 'canc-motivo-codigo', motivo: 'canc-motivo', numero_processo: 'canc-processo' };

  function abrirCancelamento(n) {
    notaCancelando = n;
    var prefeitura = n.cancelamento.forma === 'PREFEITURA';
    formCancelar.reset();
    mostrarErrosForm(formCancelar, [], CAMPOS_CANC, 'cancelar-erro');
    el('cancelar-titulo').textContent = prefeitura ? 'Registrar cancelamento' : 'Cancelar nota';
    el('cancelar-subtitulo').textContent = (n.numero ? 'NFS-e nº ' + n.numero : 'Nota') + ' · ' + (n.tomador || '') + ' · ' + reais(n.valor);
    el('canc-prefeitura').hidden = !prefeitura;
    el('canc-processo-campo').hidden = !prefeitura;
    el('botao-cancelar-nota').innerHTML = '<i class="icone icone--p">block</i> ' + (prefeitura ? 'Registrar cancelamento' : 'Cancelar nota');
    atualizarCancelar();
    abrirJanela('janela-cancelar');
  }

  function atualizarCancelar() {
    var letras = el('canc-motivo').value.trim().length;
    el('canc-contador').textContent = letras < 15 ? 'Mínimo 15 letras (faltam ' + (15 - letras) + ').' : letras + ' de 255 letras.';
    el('botao-cancelar-nota').disabled = !el('canc-entendi').checked;
  }
  el('canc-motivo').addEventListener('input', atualizarCancelar);
  el('canc-entendi').addEventListener('change', atualizarCancelar);

  formCancelar.addEventListener('submit', function (evento) {
    evento.preventDefault();
    var escolhido = formCancelar.querySelector('input[name="canc-motivo"]:checked');
    var corpo = {
      id: notaCancelando.id,
      motivo_codigo: escolhido ? escolhido.value : '',
      motivo: el('canc-motivo').value.trim()
    };
    if (notaCancelando.cancelamento.forma === 'PREFEITURA') { corpo.numero_processo = el('canc-processo').value.trim(); }
    var botao = el('botao-cancelar-nota');
    botao.disabled = true;
    api('POST', 'cancelar.php', corpo).then(function (r) {
      fecharJanela('janela-cancelar');
      avisar(escapar(r.mensagem));
      abrirNota(notaCancelando.id);
      if (location.hash === '#notas') { carregarNotas(); }
    }).catch(function (erro) {
      mostrarErrosForm(formCancelar, erro.erros, CAMPOS_CANC, 'cancelar-erro');
      if (!erro.erros || !erro.erros.length) { mostrarErro('cancelar-erro', erro.message); }
    }).then(function () { botao.disabled = !el('canc-entendi').checked; });
  });

  // ---- Servicos ----
  var listaServicos = [];

  function carregarListaServicos() {
    api('GET', 'servicos.php').then(function (r) {
      listaServicos = r.servicos || [];
      desenharServicos();
    }).catch(function (erro) {
      if (erro.codigo !== 'SESSAO_EXPIRADA') { avisar(escapar(erro.message), 'error_outline'); }
    });
  }

  function desenharServicos() {
    var caixa = el('servicos-lista');
    if (!listaServicos.length) {
      caixa.innerHTML = '<div class="cartao vazio" style="grid-column:1/-1"><i class="icone">design_services</i><h2>Nenhum serviço salvo</h2>'
        + '<p class="secundario">Salve os serviços que você mais fatura. Na hora de emitir, é só escolher e os códigos vêm prontos.</p>'
        + '<button type="button" class="btn btn--primario" data-novo><i class="icone icone--p">add</i> Cadastrar o primeiro</button></div>';
      caixa.querySelector('[data-novo]').addEventListener('click', function () { abrirFormServico(null); });
      return;
    }
    // Busca na propria tela (a lista de servicos e pequena, nao precisa ir ao servidor).
    var termo = el('servicos-busca').value.trim().toLowerCase();
    var termoNumeros = soNumeros(termo);
    var visiveis = listaServicos.filter(function (s) {
      if (!termo) { return true; }
      var texto = [s.nome, s.descricao, s.item_lista_servico, s.codigo_tributacao_municipal].join(' ').toLowerCase();
      return texto.indexOf(termo) >= 0
        || (termoNumeros !== '' && (String(s.codigo_tributacao_nacional || '') + ' ' + String(s.codigo_cnae || '')).indexOf(termoNumeros) >= 0);
    });
    if (!visiveis.length) {
      caixa.innerHTML = '<div class="cartao vazio" style="grid-column:1/-1"><i class="icone">search_off</i>'
        + '<p class="secundario">Nenhum serviço encontrado para “' + escapar(termo) + '”.</p></div>';
      return;
    }
    caixa.innerHTML = visiveis.map(function (s) {
      var codigos = [
        s.codigo_tributacao_nacional ? 'Nacional ' + s.codigo_tributacao_nacional : '',
        s.item_lista_servico ? 'LC 116 ' + s.item_lista_servico : '',
        s.aliquota_iss !== null ? 'ISS ' + formatarValor(s.aliquota_iss).replace(',00', '') + '%' : ''
      ].filter(Boolean).map(function (c) { return '<span class="pilula pilula--neutro">' + escapar(c) + '</span>'; }).join('');
      return '<article class="cartao servico" data-id="' + s.id + '">'
        + '<div class="servico__topo"><h3>' + escapar(s.nome) + '</h3>'
        + (s.valor_padrao ? '<span class="servico__valor">' + reais(s.valor_padrao) + '</span>' : '') + '</div>'
        + '<p class="servico__descricao">' + escapar(s.descricao) + '</p>'
        + '<div class="servico__codigos">' + codigos + '</div>'
        + '<div class="servico__acoes">'
        + '<button type="button" class="btn btn--primario" data-acao="usar"><i class="icone icone--p">send</i> Usar na nota</button>'
        + '<button type="button" class="btn btn--secundario" data-acao="editar"><i class="icone icone--p">edit</i> Editar</button>'
        + '<button type="button" class="btn btn--icone" data-acao="remover" aria-label="Remover ' + escapar(s.nome) + '" title="Remover"><i class="icone">delete_outline</i></button>'
        + '</div></article>';
    }).join('');
  }

  el('servicos-lista').addEventListener('click', function (evento) {
    var botao = evento.target.closest('[data-acao]');
    if (!botao) { return; }
    var cartao = botao.closest('[data-id]');
    var id = Number(cartao.getAttribute('data-id'));
    var s = listaServicos.filter(function (x) { return x.id === id; })[0];
    var acao = botao.getAttribute('data-acao');
    if (acao === 'usar') { emitirCom('servico', id); }
    if (acao === 'editar') { abrirFormServico(s); }
    if (acao === 'remover') {
      confirmarRemocao(cartao.querySelector('.servico__acoes'), 'Remover da lista?', function () {
        api('DELETE', 'servicos.php?id=' + id).then(function () {
          avisar('Serviço removido. As notas já emitidas não mudam.');
          carregarListaServicos();
        }).catch(function (erro) { avisar(escapar(erro.message), 'error_outline'); });
      });
    }
  });

  var formServico = el('form-servico');
  var CAMPOS_SERVICO = {
    nome: 'srvf-nome', descricao: 'srvf-descricao', codigo_tributacao_nacional: 'srvf-nacional',
    item_lista_servico: 'srvf-item', aliquota_iss: 'srvf-aliquota', valor_padrao: 'srvf-valor',
    codigo_tributacao_municipal: 'srvf-municipal', codigo_cnae: 'srvf-cnae'
  };

  function abrirFormServico(s) {
    formServico.reset();
    mostrarErrosForm(formServico, [], {}, 'servico-erro');
    el('servico-titulo').textContent = s ? 'Editar serviço' : 'Novo serviço';
    el('srvf-id').value = s ? s.id : '';
    if (s) {
      el('srvf-nome').value = s.nome || '';
      el('srvf-descricao').value = s.descricao || '';
      el('srvf-nacional').value = s.codigo_tributacao_nacional || '';
      el('srvf-item').value = s.item_lista_servico || '';
      el('srvf-aliquota').value = s.aliquota_iss !== null ? formatarValor(s.aliquota_iss) : '';
      el('srvf-valor').value = s.valor_padrao ? formatarValor(s.valor_padrao) : '';
      el('srvf-municipal').value = s.codigo_tributacao_municipal || '';
      el('srvf-cnae').value = s.codigo_cnae || '';
      formServico.querySelector('details').open = !!(s.codigo_tributacao_municipal || s.codigo_cnae);
    }
    abrirJanela('janela-servico');
    el('srvf-nome').focus();
  }

  el('botao-novo-servico').addEventListener('click', function () { abrirFormServico(null); });
  el('servicos-busca').addEventListener('input', depoisDeDigitar(desenharServicos, 150));
  el('srvf-valor').addEventListener('blur', function () { if (this.value.trim()) { this.value = formatarValor(lerValor(this.value)); } });

  formServico.addEventListener('submit', function (evento) {
    evento.preventDefault();
    var botao = el('botao-salvar-servico');
    botao.disabled = true;
    var corpo = {
      nome: el('srvf-nome').value.trim(),
      descricao: el('srvf-descricao').value.trim(),
      codigo_tributacao_nacional: soNumeros(el('srvf-nacional').value),
      item_lista_servico: el('srvf-item').value.trim(),
      aliquota_iss: el('srvf-aliquota').value.trim() === '' ? '' : lerValor(el('srvf-aliquota').value),
      valor_padrao: el('srvf-valor').value.trim() === '' ? '' : lerValor(el('srvf-valor').value),
      codigo_tributacao_municipal: el('srvf-municipal').value.trim(),
      codigo_cnae: soNumeros(el('srvf-cnae').value)
    };
    if (el('srvf-id').value) { corpo.id = Number(el('srvf-id').value); }
    api('POST', 'servicos.php', corpo).then(function () {
      fecharJanela('janela-servico');
      avisar(corpo.id ? 'Serviço atualizado.' : 'Serviço salvo. Ele já aparece na tela de emitir.');
      carregarListaServicos();
    }).catch(function (erro) {
      mostrarErrosForm(formServico, erro.erros, CAMPOS_SERVICO, 'servico-erro');
      if (!erro.erros || !erro.erros.length) { mostrarErro('servico-erro', erro.message); }
    }).then(function () { botao.disabled = false; });
  });

  // ---- Tomadores ----
  var listaTomadores = [];

  function carregarListaTomadores() {
    var busca = el('tomadores-busca').value.trim();
    api('GET', 'tomadores.php?busca=' + encodeURIComponent(busca)).then(function (r) {
      listaTomadores = r.tomadores || [];
      desenharTomadores(busca);
    }).catch(function (erro) {
      if (erro.codigo !== 'SESSAO_EXPIRADA') { avisar(escapar(erro.message), 'error_outline'); }
    });
  }

  function desenharTomadores(busca) {
    var corpo = el('tomadores-linhas');
    if (!listaTomadores.length) {
      corpo.innerHTML = '<tr class="tabela-vazia"><td colspan="6">' + (busca
        ? 'Nenhum tomador encontrado para “' + escapar(busca) + '”.'
        : 'Nenhum tomador ainda. Eles são salvos sozinhos quando você emite uma nota.') + '</td></tr>';
      return;
    }
    corpo.innerHTML = listaTomadores.map(function (t) {
      return '<tr data-doc="' + escapar(t.cpf_cnpj) + '">'
        + '<td><b>' + escapar(t.nome) + '</b></td>'
        + '<td class="mono">' + escapar(formatarDoc(t.cpf_cnpj)) + '</td>'
        + '<td>' + escapar(t.email || '—') + '</td>'
        + '<td>' + escapar(t.cidade ? t.cidade + (t.uf ? ' / ' + t.uf : '') : '—') + '</td>'
        + '<td class="num">' + escapar(dataCurta(t.ultima_emissao) || '—') + '</td>'
        + '<td class="acoes">'
        + '<button type="button" class="btn btn--secundario" data-acao="emitir"><i class="icone icone--p">send</i> Emitir nota</button> '
        + '<button type="button" class="btn btn--icone" data-acao="editar" aria-label="Editar ' + escapar(t.nome) + '" title="Editar"><i class="icone">edit</i></button>'
        + '<button type="button" class="btn btn--icone" data-acao="remover" aria-label="Remover ' + escapar(t.nome) + '" title="Remover"><i class="icone">delete_outline</i></button>'
        + '</td></tr>';
    }).join('');
  }

  el('tomadores-busca').addEventListener('input', depoisDeDigitar(carregarListaTomadores));

  el('tomadores-linhas').addEventListener('click', function (evento) {
    var botao = evento.target.closest('[data-acao]');
    if (!botao) { return; }
    var linha = botao.closest('tr');
    var doc = linha.getAttribute('data-doc');
    var t = listaTomadores.filter(function (x) { return x.cpf_cnpj === doc; })[0];
    var acao = botao.getAttribute('data-acao');
    if (acao === 'emitir') { emitirCom('tomador', doc); }
    if (acao === 'editar') { abrirFormTomador(t); }
    if (acao === 'remover') {
      confirmarRemocao(botao.closest('td'), 'Tirar da lista?', function () {
        api('DELETE', 'tomadores.php?cpf_cnpj=' + doc).then(function (r) {
          avisar(escapar(r.mensagem || 'Tomador removido.'));
          carregarListaTomadores();
        }).catch(function (erro) { avisar(escapar(erro.message), 'error_outline'); });
      });
    }
  });

  var formTomador = el('form-tomador');
  var dadosTomadorForm = {};   // UF e codigo IBGE vindos da consulta do CEP
  var CAMPOS_TOMADOR = { cpf_cnpj: 'tomf-doc', nome: 'tomf-nome', email: 'tomf-email', cep: 'tomf-cep', uf: 'tomf-cidade' };

  function preencherFormTomador(t, soVazios) {
    var pares = { 'tomf-nome': t.nome, 'tomf-email': t.email, 'tomf-telefone': t.telefone, 'tomf-cep': formatarCep(t.cep || ''),
      'tomf-logradouro': t.logradouro, 'tomf-numero': t.numero, 'tomf-complemento': t.complemento, 'tomf-bairro': t.bairro,
      'tomf-cidade': t.cidade ? t.cidade + ' / ' + (t.uf || '') : '' };
    Object.keys(pares).forEach(function (id) {
      if (pares[id] && (!soVazios || !el(id).value)) { el(id).value = pares[id]; }
    });
    dadosTomadorForm = { uf: t.uf || '', codigo_municipio: t.codigo_municipio || '', cidade: t.cidade || '', cep: t.cep || '' };
  }

  function abrirFormTomador(t) {
    formTomador.reset();
    mostrarErrosForm(formTomador, [], {}, 'tomador-erro');
    dadosTomadorForm = {};
    el('tomador-titulo').textContent = t ? 'Editar tomador' : 'Novo tomador';
    // O CPF/CNPJ e a chave do cadastro: na edicao ele nao muda.
    el('tomf-doc').readOnly = !!t;
    dica('tomf-doc-info', t ? 'Para outro CPF/CNPJ, cadastre um novo tomador.' : 'Com CNPJ, nome e endereço vêm sozinhos.');
    if (t) {
      el('tomf-doc').value = formatarDoc(t.cpf_cnpj);
      preencherFormTomador(t, false);
    }
    abrirJanela('janela-tomador');
    el(t ? 'tomf-nome' : 'tomf-doc').focus();
  }

  el('botao-novo-tomador').addEventListener('click', function () { abrirFormTomador(null); });

  el('tomf-doc').addEventListener('blur', function () {
    if (this.readOnly) { return; }
    var numeros = soNumeros(this.value);
    var campo = this.closest('.campo');
    campo.classList.remove('invalido');
    if (!numeros) { return; }
    this.value = formatarDoc(numeros);
    if ((numeros.length !== 11 && numeros.length !== 14) || !documentoValido(numeros)) {
      campo.classList.add('invalido');
      dica('tomf-doc-info', 'CPF ou CNPJ inválido. Confira os números.', 'erro');
      return;
    }
    if (numeros.length === 11) { dica('tomf-doc-info', 'CPF válido. Digite o nome.', 'ok'); return; }
    dica('tomf-doc-info', 'Buscando…');
    api('GET', 'consulta.php?cnpj=' + numeros).then(function (t) {
      preencherFormTomador(t, true);
      dica('tomf-doc-info', t.tipo === 'SALVO' ? 'Este tomador já está na sua lista.' : 'Dados da Receita Federal preenchidos.', 'ok');
    }).catch(function (erro) { dica('tomf-doc-info', erro.message); });
  });

  el('tomf-cep').addEventListener('blur', function () {
    var cep = soNumeros(this.value);
    if (cep.length !== 8 || cep === dadosTomadorForm.cep) { return; }
    this.value = formatarCep(cep);
    api('GET', 'consulta.php?cep=' + cep).then(function (end) {
      dadosTomadorForm = { cep: cep, uf: end.uf, codigo_municipio: end.codigo_municipio, cidade: end.cidade };
      el('tomf-logradouro').value = end.logradouro || el('tomf-logradouro').value;
      el('tomf-bairro').value = end.bairro || el('tomf-bairro').value;
      el('tomf-cidade').value = end.cidade + ' / ' + end.uf;
      el('tomf-numero').focus();
    }).catch(function (erro) { avisar(escapar(erro.message), 'error_outline'); });
  });

  formTomador.addEventListener('submit', function (evento) {
    evento.preventDefault();
    var botao = el('botao-salvar-tomador');
    botao.disabled = true;
    var corpo = {
      cpf_cnpj: soNumeros(el('tomf-doc').value),
      nome: el('tomf-nome').value.trim(),
      email: el('tomf-email').value.trim(),
      telefone: el('tomf-telefone').value.trim(),
      cep: soNumeros(el('tomf-cep').value),
      logradouro: el('tomf-logradouro').value.trim(),
      numero: el('tomf-numero').value.trim(),
      complemento: el('tomf-complemento').value.trim(),
      bairro: el('tomf-bairro').value.trim(),
      cidade: dadosTomadorForm.cidade || '',
      uf: dadosTomadorForm.uf || '',
      codigo_municipio: dadosTomadorForm.codigo_municipio || ''
    };
    var editando = el('tomf-doc').readOnly;
    api('POST', 'tomadores.php', corpo).then(function () {
      fecharJanela('janela-tomador');
      avisar(editando ? 'Cadastro do tomador atualizado.' : 'Tomador salvo.');
      carregarListaTomadores();
    }).catch(function (erro) {
      mostrarErrosForm(formTomador, erro.erros, CAMPOS_TOMADOR, 'tomador-erro');
      if (!erro.erros || !erro.erros.length) { mostrarErro('tomador-erro', erro.message); }
    }).then(function () { botao.disabled = false; });
  });

  // ---- Minha empresa (so leitura) ----
  var NOMES_PADRAO = { NACIONAL: 'NFS-e Padrão Nacional', SALVADOR: 'NFS-e Salvador' };

  function carregarEmpresa() {
    api('GET', 'empresa.php').then(desenharEmpresa).catch(function (erro) {
      if (erro.codigo !== 'SESSAO_EXPIRADA') { avisar(escapar(erro.message), 'error_outline'); }
    });
  }

  function cartaoFicha(icone, titulo, linhas, extra) {
    return '<section class="cartao bloco"><div class="bloco__cabeca"><h2><i class="icone">' + icone + '</i>' + escapar(titulo) + '</h2></div>'
      + '<div class="bloco__corpo">' + (extra || '') + '<dl class="ficha">' + linhas.join('') + '</dl></div></section>';
  }

  function desenharEmpresa(e) {
    var c = e.cadastro, f = e.fiscal, cert = e.certificado, p = e.plano;
    var sim = function (v) { return v ? 'Sim' : 'Não'; };

    // Avisos no topo: so o que impede (ou vai impedir) de emitir.
    var avisos = [];
    if (p.situacao !== 'LIBERADA') {
      avisos.push(['erro', 'Emissão ' + p.situacao_texto.toLowerCase(), (p.motivo_bloqueio ? p.motivo_bloqueio + '. ' : '') + 'Fale com o suporte para liberar.']);
    }
    if (!cert.enviado) {
      avisos.push(['erro', 'Certificado digital não enviado', 'Sem ele as notas não são assinadas. Envie o certificado A1 ao suporte.']);
    } else if (cert.dias_restantes !== null && cert.dias_restantes < 0) {
      avisos.push(['erro', 'Certificado vencido', 'Renove o certificado A1 e envie ao suporte para voltar a emitir.']);
    } else if (cert.dias_restantes !== null && cert.dias_restantes <= 30) {
      avisos.push(['alerta', 'Certificado vence em ' + cert.dias_restantes + ' dias', 'Renove antes do vencimento para não parar de emitir.']);
    }
    el('empresa-avisos').innerHTML = avisos.map(function (a) {
      return '<div class="resultado resultado--' + a[0] + '"><b>' + escapar(a[1]) + '</b><span>' + escapar(a[2]) + '</span></div>';
    }).join('');

    var cadastro = cartaoFicha('business', 'Cadastro', [
      linhaDetalhe('Razão social', c.razao_social),
      linhaDetalhe('Nome fantasia', c.nome_fantasia),
      linhaDetalhe('CNPJ', formatarDoc(c.cnpj)),
      linhaDetalhe('Inscrição municipal', c.inscricao_municipal || 'não informada'),
      linhaDetalhe('Inscrição estadual', c.inscricao_estadual),
      linhaDetalhe('Município', c.municipio || 'IBGE ' + c.codigo_municipio),
      linhaDetalhe('E-mail', c.email),
      linhaDetalhe('Telefone', c.telefone)
    ]);

    var fiscal = cartaoFicha('account_balance', 'Dados fiscais', [
      linhaDetalhe('Simples Nacional', f.simples_nacional),
      linhaDetalhe('Apuração', f.apuracao),
      linhaDetalhe('Regime especial', f.regime_especial),
      linhaDetalhe('Incentivador cultural', sim(f.incentivador_cultural)),
      linhaDetalhe('Notas contratadas', f.padroes.length ? f.padroes.map(function (x) { return NOMES_PADRAO[x] || x; }).join(', ') : 'nenhuma'),
      linhaDetalhe('O portal emite', NOMES_PADRAO[f.padrao_portal] || f.padrao_portal),
      linhaDetalhe('Ambiente', f.ambiente === 'PRODUCAO' ? 'Produção (notas valem)' : 'Homologação (testes, sem valor fiscal)')
    ]);

    var tomCert = !cert.enviado ? 'erro' : (cert.dias_restantes !== null && cert.dias_restantes <= 30 ? (cert.dias_restantes < 0 ? 'erro' : 'alerta') : 'ok');
    var textoCert = !cert.enviado ? 'Não enviado' : (cert.dias_restantes < 0 ? 'Vencido' : (tomCert === 'alerta' ? 'Vencendo' : 'Em dia'));
    var certificado = cartaoFicha('verified_user', 'Certificado digital', [
      '<div><dt>Situação</dt><dd><span class="pilula pilula--' + tomCert + '">' + textoCert + '</span></dd></div>',
      linhaDetalhe('Titular', cert.titular),
      linhaDetalhe('Válido até', cert.validade ? dataCurta(cert.validade) + (cert.dias_restantes >= 0 ? ' (' + cert.dias_restantes + ' dias)' : '') : null),
      linhaDetalhe('Enviado em', dataCurta(cert.atualizado_em))
    ], '<p class="dica">O certificado A1 assina as suas notas. Ele fica guardado com segurança e só o suporte pode trocar.</p>');

    var uso = '';
    if (p.limite_mensal) {
      var pct = Math.min(100, Math.round(p.usado_no_mes * 100 / p.limite_mensal));
      uso = '<div class="uso"><div class="uso__numeros"><span><b>' + p.usado_no_mes + '</b> de ' + p.limite_mensal + ' notas</span><span class="secundario">' + pct + '% do mês</span></div>'
        + '<div class="barra"><div class="barra__cheio' + (pct >= 100 ? ' barra__cheio--erro' : (pct >= 80 ? ' barra__cheio--alerta' : '')) + '" style="width:' + pct + '%"></div></div></div>';
    } else {
      uso = '<div class="uso"><div class="uso__numeros"><span><b>' + p.usado_no_mes + '</b> notas neste mês</span><span class="secundario">sem limite</span></div></div>';
    }
    var plano = cartaoFicha('workspace_premium', 'Plano e uso', [
      '<div><dt>Emissão</dt><dd><span class="pilula pilula--' + (p.situacao === 'LIBERADA' ? 'ok' : 'erro') + '">' + escapar(p.situacao_texto) + '</span></dd></div>',
      linhaDetalhe('Plano', p.nome || 'não informado'),
      linhaDetalhe('Liberado até', p.liberado_ate ? dataCurta(p.liberado_ate) : 'sem data de término')
    ], uso);

    // Duas colunas: cadastro e dados fiscais à esquerda; plano e certificado à direita.
    el('empresa-conteudo').innerHTML = '<div class="pagina-lista">' + cadastro + fiscal + '</div><div class="pagina-lista">' + plano + certificado + '</div>';
  }


  // ---- Usuarios ----
  var SITUACAO_USUARIO = {
    ATIVO: ['Ativo', 'ok'],
    PRIMEIRO_ACESSO: ['Aguardando 1º acesso', 'info'],
    BLOQUEADO: ['Bloqueado por senha errada', 'alerta'],
    DESATIVADO: ['Desativado', 'neutro']
  };
  var listaUsuarios = [];

  function carregarUsuarios() {
    api('GET', 'usuarios.php').then(function (r) {
      listaUsuarios = r.usuarios || [];
      el('botao-convidar').hidden = !r.pode_gerenciar;
      el('usuarios-subtitulo').textContent = r.pode_gerenciar
        ? 'Quem da sua empresa entra no portal. Você é responsável da empresa: pode convidar, desativar e redefinir senhas.'
        : 'Quem da sua empresa entra no portal. Para convidar ou tirar alguém, fale com o responsável da empresa.';
      desenharUsuarios(r.pode_gerenciar);
    }).catch(function (erro) {
      if (erro.codigo !== 'SESSAO_EXPIRADA') { avisar(escapar(erro.message), 'error_outline'); }
    });
  }

  function desenharUsuarios(podeGerenciar) {
    el('usuarios-linhas').innerHTML = listaUsuarios.map(function (u) {
      var s = SITUACAO_USUARIO[u.situacao] || [u.situacao, 'neutro'];
      var acoes = '';
      if (podeGerenciar && !u.eu) {
        acoes = '<button type="button" class="btn btn--secundario" data-acao="redefinir_senha"><i class="icone icone--p">key</i> Nova senha</button> '
          + '<button type="button" class="btn btn--icone" data-acao="' + (u.administrador ? 'remover_admin' : 'tornar_admin') + '" title="' + (u.administrador ? 'Tirar responsável' : 'Tornar responsável') + '" aria-label="' + (u.administrador ? 'Tirar responsável: ' : 'Tornar responsável: ') + escapar(u.nome) + '"><i class="icone">' + (u.administrador ? 'remove_moderator' : 'add_moderator') + '</i></button>'
          + (u.situacao === 'DESATIVADO'
            ? '<button type="button" class="btn btn--icone" data-acao="ativar" title="Devolver o acesso" aria-label="Devolver o acesso de ' + escapar(u.nome) + '"><i class="icone">person_add_alt</i></button>'
            : '<button type="button" class="btn btn--icone" data-acao="desativar" title="Desativar" aria-label="Desativar ' + escapar(u.nome) + '"><i class="icone">person_off</i></button>');
      }
      return '<tr data-id="' + u.id + '">'
        + '<td><b>' + escapar(u.nome) + '</b>' + (u.eu ? '<span class="eu">você</span>' : '') + '<span class="sub">' + escapar(u.email) + '</span></td>'
        + '<td>' + (u.administrador ? '<span class="pilula pilula--info">Responsável</span>' : '<span class="pilula pilula--neutro">Emissor</span>') + '</td>'
        + '<td>' + '<span class="pilula pilula--' + s[1] + '">' + escapar(s[0]) + '</span></td>'
        + '<td class="num">' + escapar(dataCurta(u.ultimo_acesso) || 'nunca entrou') + '</td>'
        + '<td class="acoes">' + acoes + '</td></tr>';
    }).join('');
  }

  function acaoUsuario(corpo) {
    return api('POST', 'usuarios.php', corpo).then(function (r) {
      if (r.senha_provisoria) { mostrarProvisoria(r, corpo.acao); } else { avisar(escapar(r.mensagem)); }
      carregarUsuarios();
      return r;
    });
  }

  el('usuarios-linhas').addEventListener('click', function (evento) {
    var botao = evento.target.closest('[data-acao]');
    if (!botao) { return; }
    var id = Number(botao.closest('tr').getAttribute('data-id'));
    var u = listaUsuarios.filter(function (x) { return x.id === id; })[0];
    var acao = botao.getAttribute('data-acao');
    var falhou = function (erro) { avisar(escapar(erro.message), 'error_outline'); };
    // Acoes que tiram algo da pessoa pedem confirmacao na propria linha.
    if (acao === 'desativar' || acao === 'redefinir_senha') {
      confirmarRemocao(botao.closest('td'), acao === 'desativar' ? 'Tirar o acesso de ' + u.nome.split(' ')[0] + '?' : 'A senha atual para de funcionar.', function () {
        acaoUsuario({ acao: acao, id: id }).catch(function (erro) { falhou(erro); carregarUsuarios(); });
      }, acao === 'desativar' ? 'Desativar' : 'Gerar nova senha');
      return;
    }
    acaoUsuario({ acao: acao, id: id }).catch(falhou);
  });

  function mostrarProvisoria(r, acao) {
    var endereco = window.location.href.split('#')[0];
    el('provisoria-titulo').textContent = acao === 'convidar' ? 'Acesso criado' : 'Senha provisória nova';
    el('provisoria-texto').textContent = 'Envie esta mensagem para a pessoa por WhatsApp ou e-mail:';
    el('provisoria-mensagem').textContent = 'Seu acesso ao portal de notas fiscais\n'
      + 'Endereço: ' + endereco + '\nE-mail: ' + r.email + '\nSenha provisória: ' + r.senha_provisoria;
    abrirJanela('janela-provisoria');
  }

  el('botao-copiar-provisoria').addEventListener('click', function () {
    var texto = el('provisoria-mensagem').textContent;
    var feito = function () { avisar('Mensagem copiada.'); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(texto).then(feito, function () { selecionarTexto(el('provisoria-mensagem')); });
    } else { selecionarTexto(el('provisoria-mensagem')); }
  });
  function selecionarTexto(alvo) {
    var faixa = document.createRange();
    faixa.selectNodeContents(alvo);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(faixa);
    avisar('Texto selecionado: use Ctrl+C (ou Copiar) para copiar.', 'content_copy');
  }
  // Fechou a janela: a senha some da tela.
  el('janela-provisoria').addEventListener('close', function () { el('provisoria-mensagem').textContent = ''; });

  var formConvite = el('form-convite');
  el('botao-convidar').addEventListener('click', function () {
    formConvite.reset();
    mostrarErrosForm(formConvite, [], {}, 'convite-erro');
    abrirJanela('janela-convite');
    el('conv-nome').focus();
  });
  formConvite.addEventListener('submit', function (evento) {
    evento.preventDefault();
    var erros = [];
    if (el('conv-nome').value.trim().length < 2) { erros.push({ campo: 'nome', mensagem: 'Informe o nome da pessoa.' }); }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el('conv-email').value.trim())) { erros.push({ campo: 'email', mensagem: 'Informe um e-mail válido.' }); }
    var mapa = { nome: 'conv-nome', email: 'conv-email' };
    mostrarErrosForm(formConvite, erros, mapa, 'convite-erro');
    if (erros.length) { return; }
    var botao = el('botao-enviar-convite');
    botao.disabled = true;
    acaoUsuario({ acao: 'convidar', nome: el('conv-nome').value.trim(), email: el('conv-email').value.trim(), administrador: el('conv-admin').checked })
      .then(function () { fecharJanela('janela-convite'); })
      .catch(function (erro) {
        var campo = { EMAIL_EM_USO: 'email', EMAIL_INVALIDO: 'email', NOME_INVALIDO: 'nome' }[erro.codigo];
        if (campo) { mostrarErrosForm(formConvite, [{ campo: campo, mensagem: erro.message }], mapa, 'convite-erro'); }
        else { mostrarErro('convite-erro', erro.message); }
      })
      .then(function () { botao.disabled = false; });
  });

  // ---- Minha senha ----
  var formMinhaSenha = el('form-minha-senha');
  formMinhaSenha.addEventListener('submit', function (evento) {
    evento.preventDefault();
    var mapa = { atual: 'ms-atual', nova: 'ms-nova', confirma: 'ms-confirma' };
    var nova = el('ms-nova').value;
    var erros = [];
    if (!el('ms-atual').value) { erros.push({ campo: 'atual', mensagem: 'Digite a sua senha atual.' }); }
    if (nova.length < 8 || !/[A-Za-z]/.test(nova) || !/\d/.test(nova)) { erros.push({ campo: 'nova', mensagem: 'Use pelo menos 8 caracteres, com letras e números.' }); }
    else if (nova !== el('ms-confirma').value) { erros.push({ campo: 'confirma', mensagem: 'As duas senhas não são iguais.' }); }
    mostrarErrosForm(formMinhaSenha, erros, mapa, 'ms-erro');
    if (erros.length) { return; }
    var botao = el('botao-minha-senha');
    botao.disabled = true;
    api('POST', 'senha.php', { senha_atual: el('ms-atual').value, senha_nova: nova }).then(function () {
      formMinhaSenha.reset();
      avisar('Senha trocada. Os outros aparelhos conectados saíram.');
    }).catch(function (erro) {
      var campo = { SENHA_ATUAL_INCORRETA: 'atual', SENHA_FRACA: 'nova', SENHA_REPETIDA: 'nova' }[erro.codigo];
      if (campo) { mostrarErrosForm(formMinhaSenha, [{ campo: campo, mensagem: erro.message }], mapa, 'ms-erro'); }
      else { mostrarErro('ms-erro', erro.message); }
    }).then(function () { botao.disabled = false; });
  });

  // ---- Recorrencias ----
  var listaRecorrencias = [];
  var tomadoresRec = [];
  var servicosRec = [];
  var SITUACAO_REC = {
    PENDENTE: ['Esperando você', 'alerta'],
    AGENDADA: ['Agendada', 'info'],
    PAUSADA: ['Pausada', 'neutro'],
    ENCERRADA: ['Encerrada', 'neutro']
  };

  function hojeIso() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function carregarRecorrencias() {
    api('GET', 'recorrencias.php').then(function (r) {
      listaRecorrencias = r.recorrencias || [];
      desenharRecorrencias();
    }).catch(function (erro) {
      if (erro.codigo !== 'SESSAO_EXPIRADA') { avisar(escapar(erro.message), 'error_outline'); }
    });
  }

  function desenharRecorrencias() {
    var pendentes = listaRecorrencias.filter(function (r) { return r.situacao === 'PENDENTE'; }).length;
    el('menu-recorrencias').textContent = pendentes;
    el('menu-recorrencias').hidden = pendentes === 0;

    var caixa = el('recorrencias-lista');
    if (!listaRecorrencias.length) {
      caixa.innerHTML = '<div class="cartao vazio" style="grid-column:1/-1"><i class="icone">event_repeat</i><h2>Nenhuma nota recorrente</h2>'
        + '<p class="secundario">Cadastre uma vez a mensalidade de um cliente e ela se repete todo mês: sozinha ou esperando a sua confirmação.</p>'
        + '<button type="button" class="btn btn--primario" data-nova><i class="icone icone--p">add</i> Criar a primeira</button></div>';
      caixa.querySelector('[data-nova]').addEventListener('click', function () { abrirFormRecorrencia(null); });
      return;
    }
    caixa.innerHTML = listaRecorrencias.map(function (r) {
      var s = SITUACAO_REC[r.situacao] || [r.situacao, 'neutro'];
      var valor = Number((r.valores || {}).valor_servicos || 0);
      var quando = r.situacao === 'PENDENTE'
        ? '<b>Nota de ' + escapar(r.competencia_proxima) + ' pronta</b><span>O dia chegou em ' + escapar(dataCurta(r.proxima_em)) + '.</span>'
        : (r.situacao === 'AGENDADA'
          ? '<b>Próxima: ' + escapar(dataCurta(r.proxima_em)) + '</b><span>Competência ' + escapar(r.competencia_proxima) + ' · todo dia ' + r.dia + (r.fim ? ' até ' + escapar(mesPorExtenso(r.fim)) : '') + '</span>'
          : '<span>Todo dia ' + r.dia + (r.fim ? ' até ' + escapar(mesPorExtenso(r.fim)) : '') + '</span>');
      var acoes = '';
      if (r.situacao === 'PENDENTE') {
        acoes += '<button type="button" class="btn btn--primario" data-acao="emitir"><i class="icone icone--p">send</i> Emitir agora</button>'
          + '<button type="button" class="btn btn--secundario" data-acao="pular">Pular mês</button>';
      }
      acoes += '<button type="button" class="btn btn--secundario" data-acao="editar"><i class="icone icone--p">edit</i> Editar</button>';
      if (r.situacao === 'PAUSADA') {
        acoes += '<button type="button" class="btn btn--icone" data-acao="retomar" title="Retomar" aria-label="Retomar ' + escapar(r.nome) + '"><i class="icone">play_arrow</i></button>';
      } else if (r.situacao !== 'ENCERRADA') {
        acoes += '<button type="button" class="btn btn--icone" data-acao="pausar" title="Pausar" aria-label="Pausar ' + escapar(r.nome) + '"><i class="icone">pause</i></button>';
      }
      acoes += '<button type="button" class="btn btn--icone" data-acao="remover" title="Apagar" aria-label="Apagar ' + escapar(r.nome) + '"><i class="icone">delete_outline</i></button>';
      return '<article class="cartao servico' + (r.situacao === 'PENDENTE' ? ' recorrencia--pendente' : '') + '" data-id="' + r.id + '">'
        + '<div class="servico__topo"><div><h3>' + escapar(r.nome) + '</h3><span class="sub secundario">' + escapar((r.tomador || {}).nome || '') + ' · ' + escapar(r.servico_nome) + '</span></div>'
        + '<span class="servico__valor">' + reais(valor) + '</span></div>'
        + '<div class="servico__codigos"><span class="pilula pilula--' + s[1] + '">' + s[0] + '</span>'
        + '<span class="pilula pilula--neutro">' + (r.modo === 'AUTOMATICA' ? 'Sai sozinha' : 'Pede confirmação') + '</span>'
        + (r.emitidas ? '<span class="pilula pilula--neutro">' + r.emitidas + (r.emitidas === 1 ? ' emitida' : ' emitidas') + '</span>' : '') + '</div>'
        + '<div class="recorrencia__quando">' + quando + '</div>'
        + (r.ultima_mensagem ? '<div class="recorrencia__erro"><b>Não saiu sozinha:</b> ' + escapar(r.ultima_mensagem) + ' Corrija e clique em "Emitir agora", ou o sistema tenta de novo amanhã.</div>' : '')
        + '<div class="servico__acoes">' + acoes + '</div></article>';
    }).join('');
  }

  el('recorrencias-lista').addEventListener('click', function (evento) {
    var botao = evento.target.closest('[data-acao]');
    if (!botao) { return; }
    var cartao = botao.closest('[data-id]');
    var id = Number(cartao.getAttribute('data-id'));
    var r = listaRecorrencias.filter(function (x) { return x.id === id; })[0];
    var acao = botao.getAttribute('data-acao');
    var executar = function () {
      botao.disabled = true;
      api('POST', 'recorrencias.php', { acao: acao, id: id }).then(function (resposta) {
        avisar(escapar(resposta.mensagem));
        carregarRecorrencias();
      }).catch(function (erro) {
        avisar(escapar(erro.message), 'error_outline');
        carregarRecorrencias();
      });
    };
    if (acao === 'editar') { abrirFormRecorrencia(r); return; }
    // O que nao tem volta (nota emitida, apagar) pede confirmacao no proprio cartao.
    if (acao === 'emitir') {
      confirmarRemocao(cartao.querySelector('.servico__acoes'), 'Emitir ' + reais((r.valores || {}).valor_servicos) + ' para ' + ((r.tomador || {}).nome || 'o tomador') + '?', executar, 'Emitir');
      var sim = cartao.querySelector('[data-sim]');
      sim.classList.remove('btn--perigo');
      sim.classList.add('btn--primario');
      return;
    }
    if (acao === 'remover') {
      confirmarRemocao(cartao.querySelector('.servico__acoes'), 'Apagar esta recorrência?', executar, 'Apagar');
      return;
    }
    executar();
  });

  // ---- Formulario da recorrencia ----
  var formRecorrencia = el('form-recorrencia');
  var CAMPOS_REC = {
    nome: 'recf-nome', 'tomador.cpf_cnpj': 'recf-tomador', 'tomador.nome': 'recf-tomador', 'tomador.email': 'recf-tomador',
    'tomador.endereco.cep': 'recf-tomador', 'tomador.endereco.codigo_municipio': 'recf-tomador',
    servico_id: 'recf-servico', descricao: 'recf-descricao', 'servico.descricao': 'recf-descricao',
    'valores.valor_servicos': 'recf-valor', primeira_em: 'recf-primeira', fim: 'recf-fim', competencia: 'recf-competencia'
  };
  var valoresEditando = {};   // mantem retencoes que a tela nao mostra

  function carregarListasRecorrencia(r) {
    return Promise.all([api('GET', 'tomadores.php'), api('GET', 'servicos.php')]).then(function (res) {
      tomadoresRec = res[0].tomadores || [];
      servicosRec = res[1].servicos || [];
      var tomAtual = r && r.tomador ? r.tomador : null;
      // Tomador da recorrencia que saiu da lista continua aparecendo.
      if (tomAtual && !tomadoresRec.some(function (t) { return t.cpf_cnpj === tomAtual.cpf_cnpj; })) { tomadoresRec.push(tomAtual); }
      el('recf-tomador').innerHTML = '<option value="">' + (tomadoresRec.length ? 'Escolha um tomador…' : 'Nenhum tomador salvo ainda') + '</option>'
        + tomadoresRec.map(function (t) { return '<option value="' + escapar(t.cpf_cnpj) + '">' + escapar(t.nome + ' — ' + formatarDoc(t.cpf_cnpj)) + '</option>'; }).join('');
      el('recf-servico').innerHTML = '<option value="">' + (servicosRec.length ? 'Escolha um serviço…' : 'Nenhum serviço salvo ainda') + '</option>'
        + servicosRec.map(function (s) { return '<option value="' + s.id + '">' + escapar(s.nome) + '</option>'; }).join('');
    });
  }

  function abrirFormRecorrencia(r) {
    formRecorrencia.reset();
    mostrarErrosForm(formRecorrencia, [], {}, 'recorrencia-erro');
    el('recorrencia-titulo').textContent = r ? 'Editar recorrência' : 'Nova recorrência';
    el('recf-id').value = r ? r.id : '';
    el('recf-primeira').min = hojeIso();
    valoresEditando = r ? Object.assign({}, r.valores) : {};
    carregarListasRecorrencia(r).then(function () {
      if (r) {
        el('recf-nome').value = r.nome;
        el('recf-tomador').value = (r.tomador || {}).cpf_cnpj || '';
        el('recf-servico').value = String(r.servico_id);
        el('recf-descricao').value = r.descricao;
        el('recf-valor').value = formatarValor((r.valores || {}).valor_servicos || 0);
        el('recf-iss-retido').checked = !!(r.valores || {}).iss_retido;
        el('recf-primeira').value = r.proxima_em >= hojeIso() ? r.proxima_em : '';
        el('recf-fim').value = r.fim || '';
        el('recf-competencia').value = r.competencia;
        formRecorrencia.querySelector('input[name="recf-modo"][value="' + r.modo + '"]').checked = true;
      }
      dica('recf-primeira-dica', r && !el('recf-primeira').value ? 'Vazio = continua em ' + dataCurta(r.proxima_em) + '. Repete todo mês neste dia.' : 'Repete todo mês neste dia (até o dia 28).');
      abrirJanela('janela-recorrencia');
      el('recf-nome').focus();
    }).catch(function (erro) { avisar(escapar(erro.message), 'error_outline'); });
  }

  el('botao-nova-recorrencia').addEventListener('click', function () { abrirFormRecorrencia(null); });
  el('recf-valor').addEventListener('blur', function () { if (this.value.trim()) { this.value = formatarValor(lerValor(this.value)); } });

  // Escolheu o servico: traz descricao e valor de costume (sem apagar o que ja foi digitado).
  el('recf-servico').addEventListener('change', function () {
    var s = servicosRec.filter(function (x) { return String(x.id) === el('recf-servico').value; })[0];
    if (!s) { return; }
    if (!el('recf-descricao').value.trim()) { el('recf-descricao').value = s.descricao; }
    if (!el('recf-valor').value.trim() && s.valor_padrao) { el('recf-valor').value = formatarValor(s.valor_padrao); }
    if (!el('recf-nome').value.trim()) {
      var t = tomadoresRec.filter(function (x) { return x.cpf_cnpj === el('recf-tomador').value; })[0];
      el('recf-nome').value = s.nome + (t ? ' — ' + t.nome.split(' ')[0] : '');
    }
  });

  formRecorrencia.addEventListener('submit', function (evento) {
    evento.preventDefault();
    var t = tomadoresRec.filter(function (x) { return x.cpf_cnpj === el('recf-tomador').value; })[0] || {};
    var tomador = {};
    ['cpf_cnpj', 'nome', 'email', 'telefone', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'codigo_municipio'].forEach(function (c) {
      if (t[c]) { tomador[c] = t[c]; }
    });
    var corpo = {
      acao: 'salvar',
      nome: el('recf-nome').value.trim(),
      tomador: tomador,
      servico_id: Number(el('recf-servico').value) || 0,
      descricao: el('recf-descricao').value.trim(),
      valores: Object.assign({}, valoresEditando, { valor_servicos: lerValor(el('recf-valor').value), iss_retido: el('recf-iss-retido').checked }),
      primeira_em: el('recf-primeira').value,
      fim: el('recf-fim').value,
      competencia: el('recf-competencia').value,
      modo: formRecorrencia.querySelector('input[name="recf-modo"]:checked').value
    };
    if (el('recf-id').value) { corpo.id = Number(el('recf-id').value); }
    var botao = el('botao-salvar-recorrencia');
    botao.disabled = true;
    api('POST', 'recorrencias.php', corpo).then(function (r) {
      fecharJanela('janela-recorrencia');
      avisar(escapar(r.mensagem));
      carregarRecorrencias();
    }).catch(function (erro) {
      mostrarErrosForm(formRecorrencia, erro.erros, CAMPOS_REC, 'recorrencia-erro');
      if (!erro.erros || !erro.erros.length) { mostrarErro('recorrencia-erro', erro.message); }
    }).then(function () { botao.disabled = false; });
  });

  // ==================================================================
  // 7. INICIO DO PORTAL
  // ==================================================================
  aplicarTema(lerPreferencia('portal_tema'));
  // Link do "Esqueci a senha" (#redefinir=codigo) abre direto o formulario.
  if (window.location.hash.indexOf('#redefinir=') === 0) {
    abrirRedefinicao(window.location.hash.slice('#redefinir='.length));
  } else {
    carregarSessao();
  }
})();
