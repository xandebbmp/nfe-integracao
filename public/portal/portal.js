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
     6. Inicio do portal

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

  function mostrarLogin(mensagem) {
    pararAtualizacao();
    telaSistema.hidden = true;
    telaLogin.hidden = false;
    formSenha.hidden = true;
    formLogin.hidden = false;
    mostrarErro('login-erro', mensagem || '');
    el('login-senha').value = '';
    el('login-email').focus();
  }

  function mostrarTrocaSenha(senhaDigitada) {
    telaSistema.hidden = true;
    telaLogin.hidden = false;
    formLogin.hidden = true;
    formSenha.hidden = false;
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

  el('link-esqueci').addEventListener('click', function (evento) {
    evento.preventDefault();
    mostrarErro('login-erro', 'Peça ao suporte para redefinir sua senha. Você recebe uma senha provisória e cria a nova no primeiro acesso.');
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
    var icones = { NOTA_REJEITADA: 'error_outline', SEM_CERTIFICADO: 'gpp_maybe', CERTIFICADO_VENCENDO: 'verified_user' };
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
    atualizar();
  }

  function carregarServicos() {
    return api('GET', 'servicos.php').then(function (r) {
      servicos = r.servicos || [];
      var escolhido = el('srv-salvo').value;
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
      aplicarServico(false);
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
  // 6. INICIO DO PORTAL
  // ==================================================================
  aplicarTema(lerPreferencia('portal_tema'));
  carregarSessao();
})();
