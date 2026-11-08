/**
 * Tour guiado (ajuda passo a passo em cima da tela, estilo Bootstrap Tour).
 * JavaScript puro, sem biblioteca. Usado pelo painel admin e pelo portal.
 *
 * Como usar:
 *   Tour.iniciar({
 *     id: 'portal-v1',               // nome do tour (guarda "ja visto" neste navegador)
 *     passos: [
 *       { alvo: '#botao-x', titulo: 'Titulo', texto: 'Explicacao curta.' },
 *       { alvo: null, titulo: 'Sem alvo', texto: 'Aparece no meio da tela.' },
 *       { alvo: '#menu a', antes: () => abrirMenu(), titulo: '...', texto: '...' },
 *     ],
 *   });
 *   Tour.jaVisto('portal-v1')   -> true se a pessoa ja terminou ou pulou
 *
 * Cada passo:
 *   alvo   seletor CSS do elemento destacado (null = caixa no meio da tela).
 *          Se o elemento nao existir ou estiver escondido, a caixa aparece no meio.
 *   titulo / texto   texto simples (nao e HTML).
 *   antes  funcao opcional chamada antes de mostrar o passo (ex.: abrir o menu no celular).
 *
 * Teclado: setas avancam/voltam, Esc encerra.
 */
(function () {
  'use strict';

  var CHAVE = 'tour_visto_';
  var atual = null; // { opcoes, indice, camada, foco, caixa, aoMudar, aoTecla, voltarFoco }

  function lerVisto(id) {
    try { return window.localStorage.getItem(CHAVE + id) === '1'; } catch (e) { return false; }
  }
  function gravarVisto(id) {
    try { window.localStorage.setItem(CHAVE + id, '1'); } catch (e) { /* navegador sem armazenamento: tudo bem */ }
  }

  function criar(tag, classe, texto) {
    var no = document.createElement(tag);
    if (classe) { no.className = classe; }
    if (texto !== undefined) { no.textContent = texto; }
    return no;
  }

  /** Elemento do passo, so se estiver visivel na tela. */
  function acharAlvo(passo) {
    if (!passo.alvo) { return null; }
    var no = document.querySelector(passo.alvo);
    if (!no) { return null; }
    var r = no.getBoundingClientRect();
    return (r.width > 0 && r.height > 0) ? no : null;
  }

  function encerrar(concluido) {
    if (!atual) { return; }
    var t = atual;
    atual = null;
    window.removeEventListener('resize', t.aoMudar);
    window.removeEventListener('scroll', t.aoMudar, true);
    document.removeEventListener('keydown', t.aoTecla, true);
    t.camada.remove();
    gravarVisto(t.opcoes.id);
    if (t.voltarFoco && t.voltarFoco.focus) { t.voltarFoco.focus(); }
    if (typeof t.opcoes.aoTerminar === 'function') { t.opcoes.aoTerminar(concluido); }
  }

  /** Coloca o destaque em cima do alvo e a caixa ao lado (embaixo, em cima ou no meio). */
  function posicionar() {
    if (!atual) { return; }
    var passo = atual.opcoes.passos[atual.indice];
    var alvo = acharAlvo(passo);
    var caixa = atual.caixa;
    var foco = atual.foco;
    var margem = 12;
    var larguraTela = document.documentElement.clientWidth;
    var alturaTela = window.innerHeight;

    caixa.classList.remove('tour__caixa--embaixo', 'tour__caixa--em-cima', 'tour__caixa--meio');
    if (!alvo) {
      foco.hidden = true;
      caixa.classList.add('tour__caixa--meio');
      caixa.style.left = ''; caixa.style.top = '';
      return;
    }

    var r = alvo.getBoundingClientRect();
    var folga = 6;
    foco.hidden = false;
    foco.style.left = (r.left - folga) + 'px';
    foco.style.top = (r.top - folga) + 'px';
    foco.style.width = (r.width + folga * 2) + 'px';
    foco.style.height = (r.height + folga * 2) + 'px';

    var c = caixa.getBoundingClientRect();
    var embaixo = r.bottom + folga + margem + c.height <= alturaTela || r.top < c.height + margem * 2;
    var top = embaixo ? r.bottom + folga + margem : r.top - folga - margem - c.height;
    var left = r.left + r.width / 2 - c.width / 2;
    left = Math.max(margem, Math.min(left, larguraTela - c.width - margem));
    top = Math.max(margem, Math.min(top, alturaTela - c.height - margem));
    caixa.classList.add(embaixo ? 'tour__caixa--embaixo' : 'tour__caixa--em-cima');
    caixa.style.left = left + 'px';
    caixa.style.top = top + 'px';
    // Seta apontando para o meio do alvo.
    var seta = Math.max(16, Math.min(r.left + r.width / 2 - left, c.width - 16));
    caixa.style.setProperty('--tour-seta', seta + 'px');
  }

  function mostrar(indice) {
    if (!atual) { return; }
    var passos = atual.opcoes.passos;
    if (indice < 0 || indice >= passos.length) { return; }
    atual.indice = indice;
    var passo = passos[indice];
    if (typeof passo.antes === 'function') {
      try { passo.antes(); } catch (e) { /* passo segue mesmo se a preparacao falhar */ }
    }

    var caixa = atual.caixa;
    caixa.querySelector('.tour__titulo').textContent = passo.titulo || '';
    caixa.querySelector('.tour__texto').textContent = passo.texto || '';
    caixa.querySelector('.tour__contador').textContent = (indice + 1) + ' de ' + passos.length;
    var voltar = caixa.querySelector('[data-tour=voltar]');
    var proximo = caixa.querySelector('[data-tour=proximo]');
    voltar.hidden = indice === 0;
    proximo.textContent = indice === passos.length - 1 ? 'Concluir' : 'Próximo';

    var alvo = acharAlvo(passo);
    if (alvo && alvo.scrollIntoView) {
      alvo.scrollIntoView({ block: 'center', inline: 'nearest' });
    }
    // Espera o menu abrir / a rolagem terminar antes de medir.
    window.requestAnimationFrame(function () { posicionar(); proximo.focus(); });
    window.setTimeout(posicionar, 250);
  }

  function iniciar(opcoes) {
    if (!opcoes || !opcoes.id || !Array.isArray(opcoes.passos) || opcoes.passos.length === 0) { return; }
    encerrar(false);

    var camada = criar('div', 'tour');
    var foco = criar('div', 'tour__foco');
    var caixa = criar('div', 'tour__caixa');
    caixa.setAttribute('role', 'dialog');
    caixa.setAttribute('aria-modal', 'true');
    caixa.setAttribute('aria-labelledby', 'tour-titulo');

    var fechar = criar('button', 'tour__fechar', '✕');
    fechar.type = 'button';
    fechar.setAttribute('aria-label', 'Encerrar o tour');
    fechar.dataset.tour = 'encerrar';
    var titulo = criar('h2', 'tour__titulo');
    titulo.id = 'tour-titulo';
    var texto = criar('p', 'tour__texto');
    var rodape = criar('div', 'tour__rodape');
    var contador = criar('span', 'tour__contador');
    var botoes = criar('div', 'tour__botoes');
    var voltar = criar('button', 'tour__botao', 'Voltar');
    voltar.type = 'button'; voltar.dataset.tour = 'voltar';
    var proximo = criar('button', 'tour__botao tour__botao--forte', 'Próximo');
    proximo.type = 'button'; proximo.dataset.tour = 'proximo';
    botoes.append(voltar, proximo);
    rodape.append(contador, botoes);
    caixa.append(fechar, titulo, texto, rodape);
    camada.append(foco, caixa);
    document.body.appendChild(camada);

    atual = {
      opcoes: opcoes,
      indice: 0,
      camada: camada,
      foco: foco,
      caixa: caixa,
      voltarFoco: document.activeElement,
      aoMudar: function () { posicionar(); },
      aoTecla: function (evento) {
        if (evento.key === 'Escape') { evento.preventDefault(); evento.stopPropagation(); encerrar(false); }
        else if (evento.key === 'ArrowRight') { evento.preventDefault(); avancar(); }
        else if (evento.key === 'ArrowLeft') { evento.preventDefault(); mostrar(atual.indice - 1); }
      },
    };

    caixa.addEventListener('click', function (evento) {
      var acao = evento.target.closest('[data-tour]');
      if (!acao) { return; }
      if (acao.dataset.tour === 'encerrar') { encerrar(false); }
      else if (acao.dataset.tour === 'voltar') { mostrar(atual.indice - 1); }
      else if (acao.dataset.tour === 'proximo') { avancar(); }
    });
    window.addEventListener('resize', atual.aoMudar);
    window.addEventListener('scroll', atual.aoMudar, true);
    document.addEventListener('keydown', atual.aoTecla, true);

    mostrar(0);
  }

  function avancar() {
    if (!atual) { return; }
    if (atual.indice >= atual.opcoes.passos.length - 1) { encerrar(true); } else { mostrar(atual.indice + 1); }
  }

  window.Tour = {
    iniciar: iniciar,
    encerrar: function () { encerrar(false); },
    jaVisto: lerVisto,
    ativo: function () { return atual !== null; },
  };
})();
