/**
 * Ajuda guiada (estilo Bootstrap Tour), JavaScript puro, sem biblioteca.
 * Usada pelo portal e pelo painel admin.
 *
 * A pessoa escolhe um processo no menu Ajuda (ex.: "Emitir uma NFS-e") e o
 * guia leva campo a campo na TELA DE VERDADE: destaca o campo, explica, espera
 * a pessoa preencher e so libera o "Proximo" quando o campo esta certo. No fim
 * a acao esta feita (nota emitida, tomador salvo...).
 *
 * Como usar:
 *   Tour.iniciar({ id: 'portal-emitir', passos: [ ...passos... ], aoTerminar: fn });
 *
 * Cada passo (so "titulo" e "texto" sao obrigatorios):
 *   pagina   '#emitir'  abre esta pagina do portal antes do passo
 *   alvo     seletor CSS ou funcao que devolve o elemento destacado
 *            (null = caixa no meio da tela). A pessoa pode clicar e digitar
 *            dentro do destaque; o resto da tela fica bloqueado.
 *   titulo, texto   texto simples (ou funcao que devolve o texto)
 *   acao     'ler'       (padrao) so le e clica em Proximo
 *            'preencher' Proximo so libera quando pronto() for verdade
 *                        (padrao: o campo destacado nao esta vazio)
 *            'clicar'    avanca sozinho quando a pessoa clica no destaque
 *            'esperar'   avanca sozinho quando pronto() for verdade (ex.: resposta do servidor)
 *   pronto   funcao -> true/false (para 'preencher' e 'esperar')
 *   falta    texto mostrado quando a pessoa tenta avancar sem terminar
 *   opcional true: mostra "Pular" e libera o Proximo mesmo vazio
 *   pular    funcao -> true quando o passo nao se aplica (ele e pulado)
 *   antes    funcao chamada antes de mostrar o passo
 *   aguarde  texto do aviso nos passos 'clicar' e 'esperar'
 *            (ex.: 'Clique na empresa na lista.')
 *
 * Teclado: Enter avanca (quando o campo esta pronto), Esc encerra.
 * Telas em janela (<dialog>): o guia entra na janela aberta, para continuar clicavel.
 */
(function () {
  'use strict';

  var ESPERA_ALVO_MS = 4000; // tempo maximo esperando o campo aparecer (janela abrindo, pagina carregando)
  var atual = null;

  function criar(tag, classe, texto) {
    var no = document.createElement(tag);
    if (classe) { no.className = classe; }
    if (texto !== undefined) { no.textContent = texto; }
    return no;
  }

  function valor(x) { return typeof x === 'function' ? x() : x; }

  function visivel(no) {
    if (!no) { return false; }
    var r = no.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  }

  function acharAlvo(passo) {
    if (!passo.alvo) { return null; }
    var no = typeof passo.alvo === 'function' ? passo.alvo() : document.querySelector(passo.alvo);
    return visivel(no) ? no : null;
  }

  /** Primeiro campo de digitar dentro do destaque (ou o proprio destaque). */
  function campoDe(alvo) {
    if (!alvo) { return null; }
    if (/^(INPUT|SELECT|TEXTAREA)$/.test(alvo.tagName)) { return alvo; }
    return alvo.querySelector('input:not([type=hidden]):not([disabled]), select, textarea');
  }

  function prontoPadrao(alvo) {
    var campo = campoDe(alvo);
    if (!campo) { return true; }
    if (campo.type === 'checkbox' || campo.type === 'radio') { return campo.checked; }
    return String(campo.value || '').trim() !== '';
  }

  function passoPronto(passo, alvo) {
    if (passo.opcional) { return true; }
    try { return typeof passo.pronto === 'function' ? !!passo.pronto(alvo) : prontoPadrao(alvo); } catch (e) { return false; }
  }

  /** Janela (<dialog>) aberta: o guia precisa estar dentro dela para ser clicavel. */
  function recipiente(alvo) {
    var janela = alvo && alvo.closest('dialog[open]');
    if (janela) { return janela; }
    var abertas = document.querySelectorAll('dialog[open]');
    return abertas.length ? abertas[abertas.length - 1] : document.body;
  }

  function encerrar(concluido) {
    if (!atual) { return; }
    var t = atual;
    atual = null;
    window.clearInterval(t.relogio);
    window.clearTimeout(t.esperaAlvo);
    window.removeEventListener('resize', t.aoMudar);
    window.removeEventListener('scroll', t.aoMudar, true);
    document.removeEventListener('keydown', t.aoTecla, true);
    document.removeEventListener('click', t.aoClicar, true);
    document.removeEventListener('input', t.aoDigitar, true);
    document.removeEventListener('change', t.aoDigitar, true);
    t.camada.remove();
    if (typeof t.opcoes.aoTerminar === 'function') { t.opcoes.aoTerminar(concluido); }
  }

  /** Destaque no alvo, 4 bloqueios em volta e a caixa embaixo ou em cima. */
  function posicionar() {
    if (!atual) { return; }
    var passo = atual.opcoes.passos[atual.indice];
    var alvo = acharAlvo(passo);
    var casa = recipiente(alvo);
    if (atual.camada.parentNode !== casa) { casa.appendChild(atual.camada); }

    var caixa = atual.caixa;
    var foco = atual.foco;
    var b = atual.bloqueios;
    var margem = 12;
    var larguraTela = document.documentElement.clientWidth;
    var alturaTela = window.innerHeight;

    caixa.classList.remove('tour__caixa--embaixo', 'tour__caixa--em-cima', 'tour__caixa--meio');
    atual.camada.classList.toggle('tour--sem-alvo', !alvo);
    if (!alvo) {
      foco.hidden = true;
      b[0].style.cssText = 'left:0;top:0;width:100vw;height:100vh';
      b[1].style.cssText = b[2].style.cssText = b[3].style.cssText = 'display:none';
      caixa.classList.add('tour__caixa--meio');
      caixa.style.left = ''; caixa.style.top = '';
      return;
    }

    var r = alvo.getBoundingClientRect();
    var folga = 6;
    var x = r.left - folga, y = r.top - folga, w = r.width + folga * 2, h = r.height + folga * 2;
    foco.hidden = false;
    foco.style.left = x + 'px'; foco.style.top = y + 'px';
    foco.style.width = w + 'px'; foco.style.height = h + 'px';
    // Bloqueios: em cima, embaixo, esquerda, direita do destaque (o destaque fica clicavel).
    b[0].style.cssText = 'left:0;top:0;width:100vw;height:' + Math.max(0, y) + 'px';
    b[1].style.cssText = 'left:0;top:' + (y + h) + 'px;width:100vw;bottom:0';
    b[2].style.cssText = 'left:0;top:' + y + 'px;width:' + Math.max(0, x) + 'px;height:' + h + 'px';
    b[3].style.cssText = 'left:' + (x + w) + 'px;top:' + y + 'px;right:0;height:' + h + 'px';

    var c = caixa.getBoundingClientRect();
    var cabeEmbaixo = r.bottom + folga + margem + c.height <= alturaTela;
    var cabeEmCima = r.top - folga - margem - c.height >= 0;
    var embaixo = cabeEmbaixo || !cabeEmCima;
    var top = embaixo ? r.bottom + folga + margem : r.top - folga - margem - c.height;
    var left = r.left + r.width / 2 - c.width / 2;
    left = Math.max(margem, Math.min(left, larguraTela - c.width - margem));
    top = Math.max(margem, Math.min(top, alturaTela - c.height - margem));
    caixa.classList.add(embaixo ? 'tour__caixa--embaixo' : 'tour__caixa--em-cima');
    caixa.style.left = left + 'px';
    caixa.style.top = top + 'px';
    var seta = Math.max(16, Math.min(r.left + r.width / 2 - left, c.width - 16));
    caixa.style.setProperty('--tour-seta', seta + 'px');
  }

  /** Liga/desliga o Proximo conforme o campo esta pronto. */
  function atualizarBotoes() {
    if (!atual) { return; }
    var passo = atual.opcoes.passos[atual.indice];
    var acao = passo.acao || 'ler';
    var proximo = atual.caixa.querySelector('[data-tour=proximo]');
    var pular = atual.caixa.querySelector('[data-tour=pular]');
    var alvo = acharAlvo(passo);
    var ultimo = atual.indice === atual.opcoes.passos.length - 1;

    pular.hidden = !passo.opcional;
    proximo.hidden = acao === 'clicar' || acao === 'esperar';
    proximo.textContent = ultimo ? 'Concluir' : 'Próximo';
    var ok = acao !== 'preencher' || passoPronto(passo, alvo);
    proximo.classList.toggle('tour__botao--parado', !ok);
    proximo.setAttribute('aria-disabled', ok ? 'false' : 'true');
    atual.caixa.querySelector('.tour__ok').hidden = !(acao === 'preencher' && ok && !passo.opcional);
    if (ok) { atual.caixa.querySelector('.tour__falta').hidden = true; }

    if (acao === 'esperar' && passoPronto(passo, alvo)) { avancar(); }
  }

  function mostrarFalta(passo) {
    var falta = atual.caixa.querySelector('.tour__falta');
    falta.textContent = valor(passo.falta) || 'Preencha o campo destacado para continuar.';
    falta.hidden = false;
    var campo = campoDe(acharAlvo(passo));
    if (campo && campo.focus) { campo.focus({ preventScroll: true }); }
  }

  /** Proximo passo que se aplica (pula os que tem pular() verdadeiro). */
  function indiceValido(inicio, direcao) {
    var passos = atual.opcoes.passos;
    for (var i = inicio; i >= 0 && i < passos.length; i += direcao) {
      var p = passos[i];
      var naoSeAplica = false;
      try { naoSeAplica = typeof p.pular === 'function' && p.pular(); } catch (e) { naoSeAplica = false; }
      if (!naoSeAplica) { return i; }
    }
    return -1;
  }

  function mostrar(indice, direcao) {
    if (!atual) { return; }
    var i = indiceValido(indice, direcao || 1);
    if (i < 0) { if ((direcao || 1) > 0) { encerrar(true); } return; }
    atual.indice = i;
    var passo = atual.opcoes.passos[i];
    window.clearTimeout(atual.esperaAlvo);

    if (passo.pagina && window.location.hash !== passo.pagina) { window.location.hash = passo.pagina; }
    if (typeof passo.antes === 'function') {
      try { passo.antes(); } catch (e) { /* segue mesmo se a preparacao falhar */ }
    }

    var caixa = atual.caixa;
    var acao = passo.acao || 'ler';
    caixa.querySelector('.tour__titulo').textContent = valor(passo.titulo) || '';
    caixa.querySelector('.tour__texto').textContent = valor(passo.texto) || '';
    caixa.querySelector('.tour__contador').textContent = 'Passo ' + (i + 1) + ' de ' + atual.opcoes.passos.length;
    caixa.querySelector('.tour__falta').hidden = true;
    var aguarde = caixa.querySelector('.tour__aguarde');
    aguarde.hidden = acao !== 'esperar' && acao !== 'clicar';
    aguarde.textContent = valor(passo.aguarde) || (acao === 'clicar' ? 'Clique no destaque para continuar.' : 'Aguarde…');
    // Voltar so para um passo de ler/preencher (voltar para um "clicar" reabriria a tela).
    var anterior = indiceValido(i - 1, -1);
    var acaoAnterior = anterior < 0 ? '' : (atual.opcoes.passos[anterior].acao || 'ler');
    caixa.querySelector('[data-tour=voltar]').hidden = anterior < 0 || acao === 'esperar'
      || acaoAnterior === 'clicar' || acaoAnterior === 'esperar';

    // Espera o campo aparecer (janela abrindo, pagina carregando) antes de destacar.
    var inicio = Date.now();
    (function quandoAparecer() {
      if (!atual || atual.indice !== i) { return; }
      var alvo = acharAlvo(passo);
      if (passo.alvo && !alvo && Date.now() - inicio < ESPERA_ALVO_MS) {
        atual.esperaAlvo = window.setTimeout(quandoAparecer, 150);
        return;
      }
      if (alvo && alvo.scrollIntoView) { alvo.scrollIntoView({ block: 'center', inline: 'nearest' }); }
      window.requestAnimationFrame(function () {
        posicionar();
        atualizarBotoes();
        if (!atual || atual.indice !== i) { return; }
        var campo = acao === 'preencher' ? campoDe(alvo) : null;
        if (campo && campo.focus) { campo.focus({ preventScroll: true }); }
        else { caixa.querySelector(acao === 'ler' ? '[data-tour=proximo]' : '[data-tour=encerrar]').focus({ preventScroll: true }); }
      });
      window.setTimeout(posicionar, 300);
    })();
  }

  function avancar() {
    if (!atual) { return; }
    var passo = atual.opcoes.passos[atual.indice];
    if ((passo.acao || 'ler') === 'preencher' && !passoPronto(passo, acharAlvo(passo))) { mostrarFalta(passo); return; }
    var proximo = indiceValido(atual.indice + 1, 1);
    if (proximo < 0) { encerrar(true); } else { mostrar(proximo, 1); }
  }

  function iniciar(opcoes) {
    if (!opcoes || !opcoes.id || !Array.isArray(opcoes.passos) || opcoes.passos.length === 0) { return; }
    encerrar(false);

    var camada = criar('div', 'tour');
    var bloqueios = [0, 1, 2, 3].map(function () { return camada.appendChild(criar('div', 'tour__bloqueio')); });
    var foco = camada.appendChild(criar('div', 'tour__foco'));
    var caixa = camada.appendChild(criar('div', 'tour__caixa'));
    caixa.setAttribute('role', 'dialog');
    caixa.setAttribute('aria-live', 'polite');
    caixa.setAttribute('aria-labelledby', 'tour-titulo');

    var fechar = criar('button', 'tour__fechar', '✕');
    fechar.type = 'button';
    fechar.setAttribute('aria-label', 'Sair do guia');
    fechar.title = 'Sair do guia (Esc)';
    fechar.dataset.tour = 'encerrar';
    var contador = criar('span', 'tour__contador');
    var titulo = criar('h2', 'tour__titulo');
    titulo.id = 'tour-titulo';
    var texto = criar('p', 'tour__texto');
    var ok = criar('p', 'tour__ok', '✓ Certo! Clique em Próximo.');
    var falta = criar('p', 'tour__falta');
    falta.setAttribute('role', 'alert');
    var aguarde = criar('p', 'tour__aguarde');
    var rodape = criar('div', 'tour__rodape');
    var voltar = criar('button', 'tour__botao', 'Voltar');
    voltar.type = 'button'; voltar.dataset.tour = 'voltar';
    var pular = criar('button', 'tour__botao', 'Pular');
    pular.type = 'button'; pular.dataset.tour = 'proximo-pular';
    pular.dataset.tour = 'pular';
    var proximo = criar('button', 'tour__botao tour__botao--forte', 'Próximo');
    proximo.type = 'button'; proximo.dataset.tour = 'proximo';
    rodape.append(voltar, criar('span', 'tour__espaco'), pular, proximo);
    caixa.append(fechar, contador, titulo, texto, ok, falta, aguarde, rodape);

    atual = {
      opcoes: opcoes,
      indice: 0,
      camada: camada,
      foco: foco,
      caixa: caixa,
      bloqueios: bloqueios,
      aoMudar: function () { posicionar(); },
      aoDigitar: function () { window.setTimeout(atualizarBotoes, 0); window.setTimeout(posicionar, 50); },
      aoTecla: function (evento) {
        if (!atual) { return; }
        if (evento.key === 'Escape') { evento.preventDefault(); evento.stopPropagation(); encerrar(false); return; }
        var passo = atual.opcoes.passos[atual.indice];
        var noCampo = /^(INPUT|SELECT|TEXTAREA)$/.test((evento.target && evento.target.tagName) || '');
        if (evento.key === 'Enter' && (passo.acao || 'ler') !== 'clicar' && (passo.acao || 'ler') !== 'esperar'
            && (noCampo ? evento.target.tagName !== 'TEXTAREA' : caixa.contains(evento.target) === false)) {
          evento.preventDefault(); evento.stopPropagation(); avancar();
        }
      },
      // Passo "clicar": avanca quando a pessoa clica dentro do destaque.
      aoClicar: function (evento) {
        if (!atual || caixa.contains(evento.target)) { return; }
        var passo = atual.opcoes.passos[atual.indice];
        if ((passo.acao || 'ler') !== 'clicar') { return; }
        var alvo = acharAlvo(passo);
        if (alvo && alvo.contains(evento.target)) {
          var indice = atual.indice;
          window.setTimeout(function () { if (atual && atual.indice === indice) { avancar(); } }, 250);
        }
      },
    };

    caixa.addEventListener('click', function (evento) {
      var botao = evento.target.closest('[data-tour]');
      if (!botao) { return; }
      var acao = botao.dataset.tour;
      if (acao === 'encerrar') { encerrar(false); }
      else if (acao === 'voltar') { mostrar(atual.indice - 1, -1); }
      else if (acao === 'pular') { var p = indiceValido(atual.indice + 1, 1); if (p < 0) { encerrar(true); } else { mostrar(p, 1); } }
      else if (acao === 'proximo') { avancar(); }
    });
    window.addEventListener('resize', atual.aoMudar);
    window.addEventListener('scroll', atual.aoMudar, true);
    document.addEventListener('keydown', atual.aoTecla, true);
    document.addEventListener('click', atual.aoClicar, true);
    document.addEventListener('input', atual.aoDigitar, true);
    document.addEventListener('change', atual.aoDigitar, true);
    // Confere de tempos em tempos: campos preenchidos sozinhos (CNPJ, CEP) e respostas do servidor.
    atual.relogio = window.setInterval(function () { atualizarBotoes(); posicionar(); }, 500);

    (recipiente(null)).appendChild(camada);
    mostrar(0, 1);
  }

  window.Tour = {
    iniciar: iniciar,
    encerrar: function () { encerrar(false); },
    ativo: function () { return atual !== null; },
  };
})();
