/**
 * Painel admin - AJUDA GUIADA (botao "Ajuda" no topo).
 *
 * O botao "Ajuda" abre a lista de processos. A pessoa escolhe um (ex.:
 * "Cadastrar uma empresa") e o guia (ajuda/tour.js) leva campo a campo na
 * tela de verdade, ate a acao ficar feita.
 *
 * Para criar um guia novo:
 *   1. Acrescente um item em GUIAS abaixo ({ titulo, resumo, icone, passos }).
 *   2. Ele aparece sozinho na lista do botao "Ajuda".
 *   Os passos seguem o formato descrito no topo de ajuda/tour.js.
 * Os campos tem id fixo em admin.js (ex.: cert-arquivo, acesso-nome,
 * guia-valor). Mudou um id la? Ajuste o seletor aqui.
 */
(function () {
  'use strict';

  function $(id) { return document.getElementById(id); }
  function q(seletor) { return document.querySelector(seletor); }
  function visivel(no) { return !!no && no.getBoundingClientRect().width > 0; }
  function texto(id) { var no = $(id); return no ? no.textContent.trim() : ''; }
  function temErro(id) { var no = $(id); return !!no && no.classList.contains('erro') && no.textContent.trim() !== ''; }
  function janelaAberta() { return $('modal').open; }
  function tituloJanela() { return texto('modal-titulo'); }

  /** Campo da ficha da empresa pelo "name" (devolve o rotulo inteiro). */
  function campoEmpresa(nome) {
    return function () { var c = q('#modal-corpo [name=' + nome + ']'); return c && c.closest('label'); };
  }
  function escondido(nome) { return function () { return !visivel(campoEmpresa(nome)()); }; }
  function valorEmpresa(nome) { var c = q('#modal-corpo [name=' + nome + ']'); return c ? String(c.value || '').trim() : ''; }
  function digitosEmpresa(nome) { return valorEmpresa(nome).replace(/\D/g, ''); }

  /** Elemento dentro de um bloco recolhido da ficha: abre o bloco antes de destacar. */
  function dentroDaFicha(id, subir) {
    return function () {
      var no = $(id);
      if (!no) { return null; }
      for (var d = no.closest('details'); d; d = d.parentElement.closest('details')) { d.open = true; }
      return subir ? no.closest(subir) : no;
    };
  }

  /** Passos para escolher a empresa na lista (abre a ficha dela). */
  function escolherEmpresa(oQue) {
    return [
      { alvo: '.filtros--admin', acao: 'preencher', opcional: true,
        antes: function () { if (janelaAberta()) { $('modal').close(); } },
        titulo: 'Ache a empresa',
        texto: 'Muitas empresas? Digite parte do nome ou do CNPJ em "Buscar". Se ela já aparece na lista, clique em Próximo.' },
      { alvo: '.tabela-cartao', acao: 'esperar', aguarde: 'Clique na linha da empresa.',
        titulo: 'Abra a ficha da empresa',
        texto: 'Clique na empresa. Abre a ficha dela, onde fica ' + oQue + '.',
        pronto: function () { return janelaAberta() && !!$('cert-arquivo'); } }
    ];
  }

  /** Passo "esperar" depois de clicar num botao que salva: acaba quando o botao volta ou some. */
  function esperarBotao(id, titulo) {
    return {
      acao: 'esperar', alvo: null, titulo: titulo || 'Salvando…', texto: 'Só um instante.',
      pronto: function () { var b = $(id); return !b || !b.disabled; }
    };
  }

  // =====================================================================
  // Os guias
  // =====================================================================
  var GUIAS = {
    empresa: {
      titulo: 'Cadastrar uma empresa', icone: '🏢',
      resumo: 'Dados, inscrições e impostos, até a empresa ficar salva.',
      passos: [
        { alvo: '#botao-nova', acao: 'clicar', titulo: 'Nova empresa',
          antes: function () { if (janelaAberta()) { $('modal').close(); } },
          texto: 'Clique em "+ Nova empresa".' },
        { alvo: function () { return q('#modal-corpo .grupo-campos'); }, acao: 'preencher',
          titulo: 'O que a empresa emite',
          texto: 'Deixe marcado só o que a empresa vai emitir: NF-e (produtos) e/ou NFS-e (serviços). Os campos de baixo mudam conforme o que estiver marcado.',
          pronto: function () { return !!q('#modal-corpo [name=padroes_permitidos]:checked'); },
          falta: 'Marque pelo menos um tipo de nota.' },
        { alvo: campoEmpresa('cnpj'), acao: 'preencher', titulo: 'CNPJ',
          texto: 'Digite o CNPJ da empresa (com ou sem pontos).',
          pronto: function () { return digitosEmpresa('cnpj').length === 14; },
          falta: 'O CNPJ tem 14 números.' },
        { alvo: campoEmpresa('razao_social'), acao: 'preencher', titulo: 'Razão social',
          texto: 'O nome oficial, como está no cartão CNPJ.',
          pronto: function () { return valorEmpresa('razao_social').length > 1; } },
        { alvo: campoEmpresa('nome_fantasia'), acao: 'preencher', opcional: true, titulo: 'Nome fantasia (opcional)',
          texto: 'O nome conhecido. Aparece na lista do painel.' },
        { alvo: campoEmpresa('email'), acao: 'preencher', opcional: true, titulo: 'E-mail da empresa',
          texto: 'Para avisos do sistema. Recomendado.' },
        { alvo: campoEmpresa('codigo_municipio'), acao: 'preencher', pular: escondido('codigo_municipio'),
          titulo: 'Código IBGE do município',
          texto: 'Cidade onde a empresa fica (7 números). Salvador = 2927408.',
          pronto: function () { return digitosEmpresa('codigo_municipio').length === 7; },
          falta: 'O código IBGE tem 7 números.' },
        { alvo: campoEmpresa('inscricao_municipal'), acao: 'preencher', pular: escondido('inscricao_municipal'),
          titulo: 'Inscrição municipal',
          texto: 'Número de cadastro da empresa na prefeitura (para NFS-e). Está no alvará ou com o contador.' },
        { alvo: campoEmpresa('uf'), acao: 'preencher', pular: escondido('uf'), titulo: 'UF (NF-e)',
          texto: 'Sigla do estado, ex.: BA.',
          pronto: function () { return valorEmpresa('uf').length === 2; }, falta: 'Use a sigla com 2 letras.' },
        { alvo: campoEmpresa('ie'), acao: 'preencher', pular: escondido('ie'), titulo: 'Inscrição estadual (NF-e)',
          texto: 'Número de cadastro na Secretaria da Fazenda do estado.' },
        { alvo: campoEmpresa('opcao_simples_nacional'), titulo: 'Simples Nacional',
          texto: 'Escolha a situação da empresa no Simples. ME/EPP (Simples) mostra mais dois campos.' },
        { alvo: campoEmpresa('regime_apuracao_simples'), acao: 'preencher', pular: escondido('regime_apuracao_simples'),
          titulo: 'Regime de apuração',
          texto: 'Como os impostos são pagos. O mais comum é o 1. Confirme com o contador.',
          falta: 'Escolha uma opção da lista.' },
        { alvo: campoEmpresa('percentual_tributos_simples'), acao: 'preencher', pular: escondido('percentual_tributos_simples'),
          titulo: '% tributos do Simples',
          texto: 'Percentual aproximado de impostos que vai na nota (ex.: 2). O contador informa.' },
        { alvo: function () { return q('#modal-corpo .recolhivel'); }, titulo: 'Mais opções (se precisar)',
          texto: 'Plano, limite de notas, data de vencimento, webhook e anotações ficam aqui. Pode deixar como está e mudar depois.' },
        { alvo: '#botao-salvar-empresa', acao: 'clicar', titulo: 'Cadastrar',
          texto: 'Clique em "Cadastrar empresa".' },
        esperarBotao('botao-salvar-empresa'),
        { alvo: function () {
            if (tituloJanela() !== 'Nova empresa') { return q('#modal-corpo .situacao-linha'); }
            return temErro('empresa-mensagem') ? $('empresa-mensagem') : q('#modal-corpo :invalid');
          },
          titulo: function () { return tituloJanela() !== 'Nova empresa' ? 'Empresa cadastrada!' : 'Falta corrigir uma coisa'; },
          texto: function () {
            if (tituloJanela() !== 'Nova empresa') {
              return 'A ficha da empresa está aberta. Agora envie o certificado e dê o acesso ao portal (guias "Enviar o certificado" e "Dar acesso ao portal" na Ajuda).';
            }
            if (temErro('empresa-mensagem')) { return texto('empresa-mensagem') + ' Corrija e clique em "Cadastrar empresa" de novo.'; }
            return 'O campo destacado está vazio ou errado. Corrija e clique em "Cadastrar empresa" de novo.';
          } }
      ]
    },

    certificado: {
      titulo: 'Enviar o certificado digital', icone: '🔐',
      resumo: 'Arquivo .pfx e senha. Sem ele a empresa não emite.',
      passos: escolherEmpresa('o certificado').concat([
        { alvo: dentroDaFicha('cert-arquivo', 'label'), acao: 'preencher', titulo: 'Arquivo do certificado',
          texto: 'Clique e escolha o arquivo .pfx (ou .p12) do certificado A1 da empresa.',
          pronto: function () { return !!$('cert-arquivo').files.length; },
          falta: 'Escolha o arquivo .pfx.' },
        { alvo: dentroDaFicha('cert-senha', 'label'), acao: 'preencher', titulo: 'Senha do certificado',
          texto: 'A senha que veio com o certificado. O sistema confere antes de guardar.' },
        { alvo: '#botao-enviar-certificado', acao: 'clicar', titulo: 'Enviar',
          texto: 'Clique em "Enviar certificado".' },
        esperarBotao('botao-enviar-certificado', 'Conferindo o certificado…'),
        { alvo: function () { return temErro('cert-mensagem') ? $('cert-mensagem') : dentroDaFicha('cert-arquivo', '.secao')(); },
          titulo: function () { return temErro('cert-mensagem') ? 'O certificado não foi aceito' : 'Certificado enviado!'; },
          texto: function () {
            return temErro('cert-mensagem')
              ? texto('cert-mensagem') + ' Confira o arquivo e a senha e clique em "Enviar certificado" de novo.'
              : 'Confira a validade aqui em cima. O sistema avisa quando estiver perto de vencer.';
          } }
      ])
    },

    acesso: {
      titulo: 'Dar acesso ao portal', icone: '👤',
      resumo: 'Cria o login de quem emite as notas pela empresa.',
      passos: escolherEmpresa('o acesso ao portal').concat([
        { alvo: dentroDaFicha('acesso-nome', 'label'), acao: 'preencher', titulo: 'Nome da pessoa',
          texto: 'Quem vai entrar no portal para emitir as notas.',
          pronto: function () { return $('acesso-nome').value.trim().length > 1; } },
        { alvo: dentroDaFicha('acesso-email', 'label'), acao: 'preencher', titulo: 'E-mail da pessoa',
          texto: 'Ela entra no portal com este e-mail.',
          pronto: function () { var e = $('acesso-email'); return e.value !== '' && e.checkValidity(); },
          falta: 'Digite um e-mail válido.' },
        { alvo: dentroDaFicha('acesso-por-email', 'label'), titulo: 'Mandar por e-mail',
          texto: 'Marcado: o sistema manda o endereço do portal e a senha provisória para a pessoa. Desmarque se preferir copiar e mandar pelo WhatsApp.' },
        { alvo: '#botao-dar-acesso', acao: 'clicar', titulo: 'Dar acesso',
          texto: 'Clique em "Dar acesso ao portal".' },
        { acao: 'esperar', alvo: null, titulo: 'Criando o acesso…', texto: 'Só um instante.',
          pronto: function () {
            return !!q('#acesso-area .acesso-box') || !!q('#acesso-area .erro') || temErro('acesso-mensagem');
          } },
        { alvo: function () { return q('#acesso-area .acesso-box') || q('#acesso-area .erro') || $('acesso-mensagem'); },
          titulo: function () { return q('#acesso-area .acesso-box') ? 'Acesso criado!' : 'Falta corrigir uma coisa'; },
          texto: function () {
            if (q('#acesso-area .acesso-box')) {
              return 'Os dados de acesso estão aqui. Se o e-mail do sistema estiver ligado, eles já foram enviados; senão, clique em "Copiar dados de acesso" e mande para a pessoa. A senha só aparece agora.';
            }
            var erro = q('#acesso-area .erro') || $('acesso-mensagem');
            return erro.textContent.trim() + ' Corrija e clique em "Dar acesso ao portal" de novo.';
          } }
      ])
    },

    guia: {
      titulo: 'Anexar a guia DAS do mês', icone: '🧾',
      resumo: 'PDF, valor e vencimento. O cliente vê no portal.',
      passos: [
        { alvo: '#botao-fechamento', acao: 'clicar', titulo: 'Fechamento do mês',
          antes: function () { if (janelaAberta()) { $('modal').close(); } },
          texto: 'Clique em "Fechamento do mês". Ali está a receita de cada empresa.' },
        { acao: 'esperar', alvo: null, titulo: 'Carregando o fechamento…', texto: 'Só um instante.',
          pronto: function () { return !!$('fechamento-tabela') || !!q('#modal-corpo .erro') || /Nenhuma empresa/.test(texto('modal-corpo')); } },
        { alvo: function () { return q('#modal-corpo input[type=month]'); }, titulo: 'Confira o mês',
          pular: function () { return !$('fechamento-tabela'); },
          texto: 'O mês da receita. A guia de outubro, por exemplo, vence em 20 de novembro. Mudou o mês? A lista recarrega.' },
        { alvo: '#fechamento-tabela', acao: 'esperar', aguarde: 'Clique em "Anexar guia" na linha da empresa.',
          pular: function () { return !$('fechamento-tabela'); },
          titulo: 'Escolha a empresa',
          texto: 'Faça a declaração no PGDAS-D e gere o DAS. Depois clique em "Anexar guia" na linha da empresa (ou "Trocar", se já tiver uma).',
          pronto: function () { return !!$('form-guia'); } },
        { alvo: dentroDaFicha('guia-arquivo', 'label'), acao: 'preencher', titulo: 'PDF da guia',
          pular: function () { return !$('form-guia'); },
          texto: 'Clique e escolha o PDF do DAS que você baixou do site do Simples.',
          pronto: function () { return !!$('guia-arquivo').files.length; },
          falta: 'Escolha o PDF da guia.' },
        { alvo: dentroDaFicha('guia-valor', 'label'), acao: 'preencher', titulo: 'Valor total',
          pular: function () { return !$('form-guia'); },
          texto: 'O valor total que está no DAS (ex.: 152,30).',
          pronto: function () { return parseFloat($('guia-valor').value.replace(/\./g, '').replace(',', '.')) > 0; },
          falta: 'Digite um valor maior que zero.' },
        { alvo: dentroDaFicha('guia-vencimento', 'label'), acao: 'preencher', titulo: 'Vencimento',
          pular: function () { return !$('form-guia'); },
          texto: 'Já vem o dia 20 do mês seguinte. Confira com a data da guia.' },
        { alvo: dentroDaFicha('guia-linha', 'label'), acao: 'preencher', opcional: true, titulo: 'Código de barras (opcional)',
          pular: function () { return !$('form-guia'); },
          texto: 'Os 48 números do código de barras. Com eles, o cliente copia e paga pelo app do banco.' },
        { alvo: dentroDaFicha('guia-avisar', 'label'), titulo: 'Avisar o cliente',
          pular: function () { return !$('form-guia'); },
          texto: 'Marcado: todas as pessoas da empresa no portal recebem um e-mail avisando da guia.' },
        { alvo: '#botao-salvar-guia', acao: 'clicar', titulo: 'Anexar',
          pular: function () { return !$('form-guia'); },
          texto: 'Clique em "Anexar guia".' },
        { acao: 'esperar', alvo: null, titulo: 'Enviando a guia…', texto: 'Só um instante.',
          pular: function () { return !$('form-guia'); },
          pronto: function () {
            var b = $('botao-salvar-guia');
            if (b) { return !b.disabled; }
            return !!$('fechamento-tabela') || !!q('#modal-corpo .erro'); // salvou: espera a lista recarregar
          } },
        { alvo: function () {
            if ($('form-guia')) { return temErro('guia-mensagem') ? $('guia-mensagem') : q('#form-guia :invalid'); }
            return $('fechamento-tabela');
          },
          titulo: function () {
            if (!$('fechamento-tabela') && !$('form-guia')) { return 'Nenhuma empresa para anexar'; }
            return $('form-guia') ? 'Falta corrigir uma coisa' : 'Guia anexada!';
          },
          texto: function () {
            if (!$('fechamento-tabela') && !$('form-guia')) { return 'Neste mês não há empresa com notas ou no Simples (ME/EPP). Troque o mês no Fechamento e tente de novo.'; }
            if ($('form-guia')) {
              return (temErro('guia-mensagem') ? texto('guia-mensagem') + ' ' : 'O campo destacado está vazio ou errado. ')
                + 'Corrija e clique em "Anexar guia" de novo.';
            }
            return 'A guia aparece na linha da empresa, com valor e vencimento. O cliente já vê em "Guias do Simples" no portal.';
          } }
      ]
    },

    conhecer: {
      titulo: 'Conhecer o painel', icone: '🧭',
      resumo: 'Onde fica cada coisa nesta tela (1 minuto).',
      passos: [
        { alvo: '#botao-sistema', titulo: 'Sistema',
          antes: function () { if (janelaAberta()) { $('modal').close(); } },
          texto: 'Worker (o robô que envia as notas), fila, atualizar banco, diagnóstico, e-mail, pessoas do painel e ambiente. Bolinha verde = tudo rodando.' },
        { alvo: '#contadores', titulo: 'Resumo',
          texto: 'Empresas liberadas, bloqueadas, vencidas ou no limite, e as notas autorizadas no mês.' },
        { alvo: '.tabela-cartao', titulo: 'Empresas',
          texto: 'Clique numa empresa para abrir a ficha: dados, certificado, plano, token da API e acesso ao portal.' },
        { alvo: 'a[href="../monitor/"]', titulo: 'Notas (monitor)',
          texto: 'Todas as notas de todas as empresas, em tempo real, com XML e PDF.' },
        { alvo: '#botao-fechamento', titulo: 'Fechamento do mês',
          texto: 'Receita de cada empresa no mês. Aqui você anexa a guia DAS e o cliente vê no portal.' },
        { alvo: '#botao-relatorio', titulo: 'Relatório de cobrança',
          texto: 'Quantas notas cada empresa emitiu no mês, para cobrar o plano.' },
        { alvo: '#botao-ajuda', titulo: 'Ajuda',
          texto: 'Volte aqui quando quiser: escolha uma tarefa e o guia leva campo a campo até terminar.' }
      ]
    }
  };

  function iniciarGuia(chave) {
    var g = GUIAS[chave];
    if (!g || !window.Tour) { return; }
    if (janelaAberta()) { $('modal').close(); }
    window.Tour.iniciar({ id: 'painel-' + chave, passos: g.passos });
  }

  /** Janela "Ajuda": lista de processos + link para a Central de Ajuda. */
  function abrirAjuda() {
    var lista = document.createElement('div');
    lista.className = 'guias-ajuda';
    Object.keys(GUIAS).forEach(function (chave) {
      var g = GUIAS[chave];
      var botao = document.createElement('button');
      botao.type = 'button';
      botao.className = 'guia-ajuda';
      botao.setAttribute('data-guia', chave);
      var icone = document.createElement('span');
      icone.className = 'guia-ajuda__icone';
      icone.setAttribute('aria-hidden', 'true');
      icone.textContent = g.icone;
      var textos = document.createElement('span');
      textos.className = 'guia-ajuda__textos';
      var titulo = document.createElement('b');
      titulo.textContent = g.titulo;
      var resumo = document.createElement('small');
      resumo.textContent = g.resumo;
      textos.append(titulo, resumo);
      var seta = document.createElement('span');
      seta.className = 'guia-ajuda__seta';
      seta.setAttribute('aria-hidden', 'true');
      seta.textContent = '▶';
      botao.append(icone, textos, seta);
      botao.addEventListener('click', function () { iniciarGuia(chave); });
      lista.appendChild(botao);
    });

    var explica = document.createElement('p');
    explica.className = 'suave';
    explica.textContent = 'Escolha o que quer fazer. O guia mostra cada campo na tela, espera você preencher e só termina quando a tarefa estiver feita. Pode sair a qualquer hora no ✕ (ou Esc).';
    var central = document.createElement('p');
    var link = document.createElement('a');
    link.href = '../ajuda/#painel';
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'Central de Ajuda: passo a passo com imagens (abre em outra aba)';
    central.appendChild(link);

    $('modal-titulo').textContent = 'Ajuda';
    $('modal-subtitulo').textContent = 'Ajuda guiada: escolha uma tarefa';
    if ($('modal-abas')) { $('modal-abas').hidden = true; }
    if ($('modal-rodape')) { $('modal-rodape').replaceChildren(); $('modal-rodape').hidden = true; }
    $('modal-corpo').replaceChildren(explica, lista, central);
    delete $('modal').dataset.sujo;
    if (!$('modal').open) { $('modal').showModal(); }
  }

  if ($('botao-ajuda')) { $('botao-ajuda').addEventListener('click', abrirAjuda); }
  window.PainelGuias = { iniciar: iniciarGuia, abrir: abrirAjuda, lista: Object.keys(GUIAS) };
})();
