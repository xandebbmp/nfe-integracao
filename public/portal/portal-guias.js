/**
 * Portal do cliente - AJUDA GUIADA (menu "Ajuda").
 *
 * A pessoa escolhe um processo na pagina Ajuda e o guia (ajuda/tour.js) leva
 * campo a campo na tela de verdade, ate a acao ficar feita.
 *
 * Para criar um guia novo:
 *   1. Acrescente um item em GUIAS abaixo ({ titulo, resumo, icone, passos }).
 *   2. Ele aparece sozinho na pagina Ajuda (lista montada daqui).
 *   Os passos seguem o formato descrito no topo de ajuda/tour.js.
 * Mudou um campo do formulario (id)? Ajuste o seletor do passo aqui.
 */
(function () {
  'use strict';

  function $(id) { return document.getElementById(id); }
  function q(seletor) { return document.querySelector(seletor); }
  function digitos(id) { return String(($(id) && $(id).value) || '').replace(/\D/g, ''); }
  function aberta(id) { return !!($(id) && $(id).open); }
  function aparece(id) { var no = $(id); return !!no && !no.hidden && no.textContent.trim() !== ''; }
  function campoDo(id) { return function () { var no = $(id); return no && (no.closest('.campo') || no); }; }
  function opcaoEscolhida(id) { return function () { return !!$(id) && $(id).value !== ''; }; }

  // No celular o menu fica escondido: abre a gaveta nos passos que apontam para ele.
  var telaPequena = window.matchMedia('(max-width: 860px)');
  function abrirMenu() { if (telaPequena.matches) { $('tela-sistema').classList.add('menu-aberto'); } }
  function fecharMenu() { $('tela-sistema').classList.remove('menu-aberto'); }

  /** Passo "esperar": a janela fechou (salvou) ou apareceu um erro. */
  function esperarSalvar(janela, erro) {
    return {
      acao: 'esperar', alvo: null, titulo: 'Salvando…', texto: 'Só um instante.',
      pronto: function () { return !aberta(janela) || aparece(erro); }
    };
  }

  /** Ultimo passo: diz se deu certo ou o que corrigir. */
  function concluir(erro, titulo, texto) {
    return {
      alvo: function () { return aparece(erro) ? $(erro) : null; },
      titulo: function () { return aparece(erro) ? 'Falta corrigir uma coisa' : titulo; },
      texto: function () {
        return aparece(erro)
          ? $(erro).textContent.trim() + ' Corrija o campo e clique em salvar de novo.'
          : texto;
      }
    };
  }

  // =====================================================================
  // Os guias
  // =====================================================================
  var GUIAS = {
    emitir: {
      titulo: 'Emitir uma NFS-e', icone: 'post_add',
      resumo: 'Tomador, serviço e valor, até a nota sair.',
      passos: [
        { pagina: '#emitir', alvo: null, titulo: 'Vamos emitir uma NFS-e',
          texto: 'Vou mostrar cada campo. Preencha o campo destacado e clique em Próximo (ou aperte Enter). Pode sair quando quiser no ✕.' },
        { alvo: function () { return $('tom-doc').closest('.linha-campos'); }, acao: 'preencher',
          titulo: '1. Para quem é a nota',
          texto: 'Digite o CPF ou CNPJ do tomador (quem pagou pelo serviço) e saia do campo. Com CNPJ, nome e endereço vêm sozinhos. Já atendeu antes? Escolha em "Ou escolha um tomador salvo".',
          pronto: function () { var d = digitos('tom-doc'); return d.length === 11 || d.length === 14 || $('tom-salvo').value !== ''; },
          falta: 'Digite um CPF (11 números) ou CNPJ (14 números), ou escolha um tomador salvo.' },
        { alvo: campoDo('tom-nome'), acao: 'preencher', titulo: 'Nome do tomador',
          texto: 'Confira o nome ou a razão social. Com CNPJ ele já vem preenchido.',
          pronto: function () { return $('tom-nome').value.trim().length > 1; } },
        { alvo: campoDo('tom-email'), acao: 'preencher', opcional: true, titulo: 'E-mail do tomador (opcional)',
          texto: 'Se preencher, o tomador recebe o PDF e o XML da nota por e-mail.' },
        { alvo: '#tom-endereco-box', opcional: true, titulo: 'Endereço (opcional no Padrão Nacional)',
          texto: 'Abra se quiser conferir ou digitar o endereço. Pelo CEP, a cidade vem sozinha.' },
        { alvo: campoDo('srv-salvo'), acao: 'preencher', titulo: '2. O que foi feito',
          texto: 'Escolha o serviço na lista. Serviço novo? Escolha "Outro serviço" e digite os códigos.',
          pronto: opcaoEscolhida('srv-salvo'), falta: 'Escolha um serviço na lista.' },
        { alvo: '#srv-manual', acao: 'preencher', titulo: 'Códigos do serviço novo',
          pular: function () { return $('srv-salvo').value !== 'novo'; },
          texto: 'Digite o código de tributação nacional (6 números) e a alíquota do ISS. O item da LC 116 vem sozinho. Não sabe? Estão numa nota antiga ou com o seu contador.',
          pronto: function () { return digitos('srv-in-nacional').length === 6; },
          falta: 'O código de tributação nacional tem 6 números.' },
        { alvo: campoDo('srv-descricao'), acao: 'preencher', titulo: 'Descrição que vai na nota',
          texto: 'Explique o que foi feito. Ela aparece para o tomador na nota.',
          pronto: function () { return $('srv-descricao').value.trim().length > 4; },
          falta: 'Escreva pelo menos 5 letras.' },
        { alvo: function () { return $('val-servico').closest('.linha-campos'); }, acao: 'preencher',
          titulo: '3. Quanto custou',
          texto: 'Digite o valor do serviço. A competência é o mês em que o serviço foi feito (já vem o mês atual).',
          pronto: function () { return parseFloat($('val-servico').value.replace(/\./g, '').replace(',', '.')) > 0 && $('val-competencia').value !== ''; },
          falta: 'Digite um valor maior que zero.' },
        { alvo: function () { return $('val-iss-retido').closest('label'); }, titulo: 'ISS retido?',
          texto: 'Marque só se o tomador vai recolher o ISS (ele paga o valor líquido). Na dúvida, pergunte ao seu contador.' },
        { alvo: '.resumo-nota__linhas', titulo: 'Confira o resumo',
          texto: 'Aqui aparecem o valor, o ISS e quanto você recebe. A lista logo abaixo mostra se falta algo.' },
        { alvo: '#botao-emitir', acao: 'clicar', titulo: 'Emitir a nota',
          texto: 'Tudo certo? Clique em "Emitir nota". A nota vai para a prefeitura.' },
        { acao: 'esperar', alvo: null, titulo: 'Enviando para a prefeitura…',
          texto: 'Pode levar alguns segundos.',
          pronto: function () { return !$('emitir-resultado').hidden || aparece('emitir-retorno') || !!q('#form-emitir .erro-campo'); } },
        { alvo: function () { return !$('emitir-resultado').hidden ? $('emitir-resultado') : (aparece('emitir-retorno') ? $('emitir-retorno') : q('#form-emitir .campo.invalido')); },
          titulo: function () {
            if (!$('emitir-resultado').hidden) { return $('emitir-resultado').classList.contains('resultado--erro') ? 'A prefeitura não autorizou' : 'Pronto!'; }
            return 'Falta corrigir uma coisa';
          },
          texto: function () {
            var r = $('emitir-resultado');
            if (!r.hidden && r.classList.contains('resultado--ok')) { return 'A nota foi autorizada. Baixe o PDF aqui ou em Minhas notas.'; }
            if (!r.hidden && r.classList.contains('resultado--erro')) { return 'Leia o motivo no quadro destacado, corrija o campo e clique em "Emitir nota" de novo.'; }
            if (!r.hidden) { return 'A nota foi enviada. A prefeitura ainda está processando; acompanhe em Minhas notas.'; }
            if (aparece('emitir-retorno')) { return 'A nota não saiu: ' + $('emitir-retorno').textContent.trim() + ' Se não souber resolver, fale com o suporte.'; }
            return 'O campo com problema está marcado em vermelho, com a explicação. Corrija e clique em "Emitir nota" de novo.';
          } }
      ]
    },

    tomador: {
      titulo: 'Cadastrar um tomador', icone: 'person_add',
      resumo: 'Salve o cliente para escolher na lista na hora de emitir.',
      passos: [
        { pagina: '#tomadores', alvo: '#botao-novo-tomador', acao: 'clicar', titulo: 'Novo tomador',
          texto: 'Tomador é quem paga pelo serviço. Clique em "Novo tomador".' },
        { alvo: campoDo('tomf-doc'), acao: 'preencher', titulo: 'CPF ou CNPJ',
          texto: 'Digite o CPF ou CNPJ e saia do campo. Com CNPJ, nome e endereço vêm sozinhos.',
          pronto: function () { var d = digitos('tomf-doc'); return d.length === 11 || d.length === 14; },
          falta: 'CPF tem 11 números e CNPJ tem 14.' },
        { alvo: campoDo('tomf-nome'), acao: 'preencher', titulo: 'Nome / razão social',
          texto: 'Confira o nome. Com CNPJ ele já vem preenchido.' },
        { alvo: campoDo('tomf-email'), acao: 'preencher', opcional: true, titulo: 'E-mail (opcional)',
          texto: 'Para onde vão o PDF e o XML das notas deste tomador.' },
        { alvo: function () { return $('tomf-cep').closest('.linha-campos'); }, opcional: true, titulo: 'Endereço (opcional)',
          texto: 'Digite o CEP: rua, bairro e cidade vêm sozinhos. Complete o número.' },
        { alvo: '#botao-salvar-tomador', acao: 'clicar', titulo: 'Salvar',
          texto: 'Clique em "Salvar tomador".' },
        esperarSalvar('janela-tomador', 'tomador-erro'),
        concluir('tomador-erro', 'Tomador salvo!', 'Na hora de emitir, é só escolher ele na lista "Ou escolha um tomador salvo".')
      ]
    },

    servico: {
      titulo: 'Cadastrar um serviço', icone: 'design_services',
      resumo: 'Códigos e descrição salvos para não digitar de novo.',
      passos: [
        { pagina: '#servicos', alvo: '#botao-novo-servico', acao: 'clicar', titulo: 'Novo serviço',
          texto: 'Clique em "Novo serviço".' },
        { alvo: campoDo('srvf-nome'), acao: 'preencher', titulo: 'Nome na lista',
          texto: 'Um nome curto para achar o serviço depois. Ex.: "Manutenção mensal".' },
        { alvo: campoDo('srvf-descricao'), acao: 'preencher', titulo: 'Descrição que vai na nota',
          texto: 'O texto que o tomador lê na nota. Escreva {mes} para entrar o mês sozinho.',
          pronto: function () { return $('srvf-descricao').value.trim().length > 4; } },
        { alvo: function () { return $('srvf-nacional').closest('.linha-campos'); }, acao: 'preencher', titulo: 'Códigos do serviço',
          texto: 'Código de tributação nacional (6 números) e alíquota do ISS. O item da LC 116 vem sozinho. Não sabe? Estão numa nota antiga ou com o seu contador.',
          pronto: function () { return digitos('srvf-nacional').length === 6; },
          falta: 'O código de tributação nacional tem 6 números.' },
        { alvo: campoDo('srvf-valor'), acao: 'preencher', opcional: true, titulo: 'Valor (opcional)',
          texto: 'Se o valor é sempre o mesmo, deixe salvo aqui.' },
        { alvo: '#botao-salvar-servico', acao: 'clicar', titulo: 'Salvar', texto: 'Clique em "Salvar serviço".' },
        esperarSalvar('janela-servico', 'servico-erro'),
        concluir('servico-erro', 'Serviço salvo!', 'Na hora de emitir, escolha ele na lista "Serviço".')
      ]
    },

    recorrencia: {
      titulo: 'Criar uma recorrência', icone: 'event_repeat',
      resumo: 'A nota do cliente que paga todo mês.',
      passos: [
        { pagina: '#recorrencias', alvo: '#botao-nova-recorrencia', acao: 'clicar', titulo: 'Nova recorrência',
          texto: 'Recorrência é a nota que se repete todo mês. Clique em "Nova recorrência".' },
        { alvo: campoDo('recf-nome'), acao: 'preencher', titulo: 'Nome',
          texto: 'Um nome para achar na lista. Ex.: "Mensalidade Clínica Vida".' },
        { alvo: campoDo('recf-tomador'), acao: 'preencher', titulo: 'Tomador',
          texto: 'Escolha quem recebe a nota.', pronto: opcaoEscolhida('recf-tomador'),
          falta: 'Escolha um tomador. A lista está vazia? Saia do guia e use "Cadastrar um tomador" primeiro.' },
        { alvo: campoDo('recf-servico'), acao: 'preencher', titulo: 'Serviço',
          texto: 'Escolha o serviço. A descrição vem do serviço salvo.', pronto: opcaoEscolhida('recf-servico'),
          falta: 'Escolha um serviço. A lista está vazia? Use "Cadastrar um serviço" primeiro.' },
        { alvo: campoDo('recf-descricao'), acao: 'preencher', titulo: 'Descrição',
          texto: 'Confira o texto da nota. {mes} vira o mês da nota.' },
        { alvo: function () { return $('recf-valor').closest('.linha-campos'); }, acao: 'preencher', titulo: 'Valor e datas',
          texto: 'Valor da nota, o dia da próxima nota (repete todo mês neste dia, até o dia 28) e, se quiser, a última.',
          pronto: function () { return parseFloat($('recf-valor').value.replace(/\./g, '').replace(',', '.')) > 0 && $('recf-primeira').value !== ''; },
          falta: 'Preencha o valor e a data da próxima nota.' },
        { alvo: '#recf-modo', titulo: 'Quando chegar o dia',
          texto: '"Me avisar" deixa a nota pronta para você confirmar. "Emitir sozinha" manda para a prefeitura sem ninguém clicar.' },
        { alvo: '#botao-salvar-recorrencia', acao: 'clicar', titulo: 'Salvar', texto: 'Clique em "Salvar recorrência".' },
        esperarSalvar('janela-recorrencia', 'recorrencia-erro'),
        concluir('recorrencia-erro', 'Recorrência criada!', 'Ela aparece na lista. No dia, a nota sai sozinha ou espera você, como escolheu.')
      ]
    },

    cancelar: {
      titulo: 'Cancelar uma nota', icone: 'block',
      resumo: 'Motivo, confirmação e acompanhar o pedido.',
      passos: [
        { pagina: '#notas', alvo: '#notas-linhas', acao: 'clicar', titulo: 'Escolha a nota',
          texto: 'Clique na nota que quer cancelar. Só notas autorizadas podem ser canceladas.' },
        { acao: 'esperar', alvo: null, titulo: 'Abrindo a nota…', texto: 'Só um instante.',
          pronto: function () { return aberta('janela-nota') && $('nota-corpo').textContent.trim() !== ''; } },
        { alvo: '#nota-corpo', titulo: 'Esta nota não pode ser cancelada',
          pular: function () { return !!q('#nota-corpo [data-cancelar]'); },
          texto: 'Ela não está autorizada (ou já foi cancelada). Feche a janela e escolha outra nota.' },
        { alvo: '#nota-corpo [data-cancelar]', acao: 'clicar', titulo: 'Cancelar nota',
          pular: function () { return !q('#nota-corpo [data-cancelar]'); },
          texto: 'Clique em "Cancelar nota".' },
        { alvo: '#canc-motivo-codigo', acao: 'preencher', titulo: 'Motivo',
          pular: function () { return !aberta('janela-cancelar'); },
          texto: 'Escolha o motivo do cancelamento.',
          pronto: function () { return !!q('#canc-motivo-codigo input:checked'); }, falta: 'Escolha um dos motivos.' },
        { alvo: campoDo('canc-motivo'), acao: 'preencher', titulo: 'Explique',
          pular: function () { return !aberta('janela-cancelar'); },
          texto: 'Em poucas palavras, por que vai cancelar.',
          pronto: function () { return $('canc-motivo').value.trim().length >= 15; }, falta: 'Escreva pelo menos 15 letras.' },
        { alvo: campoDo('canc-processo'), acao: 'preencher', titulo: 'Protocolo da prefeitura',
          pular: function () { return !aberta('janela-cancelar') || $('canc-processo-campo').hidden; },
          texto: 'Em Salvador o pedido é feito no site da prefeitura. Depois de aceito, digite aqui o número do protocolo.' },
        { alvo: function () { return $('canc-entendi').closest('label'); }, acao: 'preencher', titulo: 'Confirme',
          pular: function () { return !aberta('janela-cancelar'); },
          texto: 'Marque: o cancelamento não pode ser desfeito.',
          pronto: function () { return $('canc-entendi').checked; }, falta: 'Marque a caixa para confirmar.' },
        { alvo: '#botao-cancelar-nota', acao: 'clicar', titulo: 'Enviar o pedido',
          pular: function () { return !aberta('janela-cancelar'); },
          texto: 'Clique no botão para enviar.' },
        { acao: 'esperar', alvo: null, titulo: 'Enviando…', texto: 'Só um instante.',
          pular: function () { return !aberta('janela-cancelar') && !aparece('cancelar-erro'); },
          pronto: function () { return !aberta('janela-cancelar') || aparece('cancelar-erro'); } },
        concluir('cancelar-erro', 'Pedido enviado!',
          'Acompanhe no detalhe da nota: ela vira "Cancelada" quando a prefeitura confirmar. Se a prefeitura recusar, o motivo aparece lá.')
      ]
    },

    guia: {
      titulo: 'Pagar a guia do Simples', icone: 'request_quote',
      resumo: 'Abrir o DAS, copiar o código e marcar como paga.',
      passos: [
        { pagina: '#guias', acao: 'esperar', alvo: null, titulo: 'Abrindo as guias…', texto: 'Só um instante.',
          pronto: function () { var c = $('guias-linhas'); return c.children.length > 0 && c.textContent.indexOf('Carregando') < 0; } },
        { alvo: null, titulo: 'Nenhuma guia para pagar',
          pular: function () { return !!q('#guias-linhas [data-pagar]'); },
          texto: 'Não há guia a pagar agora. Quando a contabilidade anexar a do mês, ela aparece aqui e você recebe um e-mail.' },
        { alvo: function () { var b = q('#guias-linhas [data-pagar]'); return b && b.closest('tr'); },
          pular: function () { return !q('#guias-linhas [data-pagar]'); },
          titulo: 'A guia do mês', texto: 'Confira o valor e o vencimento. Pague até o vencimento para não ter multa.' },
        { alvo: function () { var b = q('#guias-linhas [data-pagar]'); return b && b.closest('td'); },
          pular: function () { return !q('#guias-linhas [data-pagar]'); },
          titulo: 'Pague no banco',
          texto: '"Abrir guia" mostra o PDF; "Copiar código" copia o código de barras para colar no aplicativo do banco (pagar boleto). Pague e volte aqui.' },
        { alvo: '#guias-linhas [data-pagar]', acao: 'clicar',
          pular: function () { return !q('#guias-linhas [data-pagar]'); },
          antes: function () { var b = q('#guias-linhas [data-pagar]'); GUIAS.guia.id = b ? b.getAttribute('data-pagar') : ''; },
          titulo: 'Já pagou?', texto: 'Depois de pagar, clique em "Já paguei" e confirme.' },
        { acao: 'esperar', alvo: null, titulo: 'Salvando…', texto: 'Confirme na janelinha do navegador.',
          pular: function () { return !GUIAS.guia.id; },
          pronto: function () { return !q('#guias-linhas [data-pagar="' + GUIAS.guia.id + '"]'); } },
        { alvo: null, pular: function () { return !GUIAS.guia.id; }, titulo: 'Guia marcada como paga!',
          texto: 'Ela aparece como "Paga", com a data. Marcou sem querer? Clique em "Desfazer". Guarde o comprovante do banco.' }
      ]
    },

    convidar: {
      titulo: 'Convidar uma pessoa', icone: 'manage_accounts',
      resumo: 'Dar acesso ao portal para alguém da empresa.',
      passos: [
        { pagina: '#usuarios', acao: 'esperar', alvo: null, titulo: 'Abrindo os usuários…', texto: 'Só um instante.',
          pronto: function () { return $('usuarios-linhas').children.length > 0; } },
        { alvo: null, titulo: 'Só o responsável convida',
          pular: function () { return !$('botao-convidar').hidden; },
          texto: 'Quem convida é o responsável da empresa no portal. Peça a ele (ou à contabilidade) para dar o acesso.' },
        { alvo: '#botao-convidar', acao: 'clicar', titulo: 'Convidar pessoa',
          pular: function () { return $('botao-convidar').hidden; }, texto: 'Clique em "Convidar pessoa".' },
        { alvo: campoDo('conv-nome'), acao: 'preencher', titulo: 'Nome', pular: function () { return $('botao-convidar').hidden; },
          texto: 'Nome de quem vai usar o portal.' },
        { alvo: campoDo('conv-email'), acao: 'preencher', titulo: 'E-mail', pular: function () { return $('botao-convidar').hidden; },
          texto: 'É o login da pessoa.',
          pronto: function () { return /\S+@\S+\.\S+/.test($('conv-email').value); }, falta: 'Digite um e-mail válido.' },
        { alvo: function () { return $('conv-admin').closest('label'); }, titulo: 'Responsável?', pular: function () { return $('botao-convidar').hidden; },
          texto: 'Marque só se a pessoa também pode convidar e desativar outras. Para quem só emite notas, deixe desmarcado.' },
        { alvo: '#botao-enviar-convite', acao: 'clicar', titulo: 'Criar o acesso', pular: function () { return $('botao-convidar').hidden; },
          texto: 'Clique em "Criar acesso".' },
        { acao: 'esperar', alvo: null, titulo: 'Criando o acesso…', texto: 'Só um instante.', pular: function () { return $('botao-convidar').hidden; },
          pronto: function () { return aberta('janela-provisoria') || aparece('convite-erro'); } },
        { alvo: function () { return aparece('convite-erro') ? $('convite-erro') : $('janela-provisoria'); },
          pular: function () { return $('botao-convidar').hidden; },
          titulo: function () { return aparece('convite-erro') ? 'Falta corrigir uma coisa' : 'Acesso criado!'; },
          texto: function () {
            return aparece('convite-erro') ? $('convite-erro').textContent.trim()
              : 'Clique em "Copiar mensagem" e mande para a pessoa (WhatsApp, e-mail). No primeiro acesso ela cria a própria senha.';
          } }
      ]
    },

    senha: {
      titulo: 'Trocar a minha senha', icone: 'key',
      resumo: 'Senha atual e a nova senha.',
      passos: [
        { pagina: '#usuarios', alvo: campoDo('ms-atual'), acao: 'preencher', titulo: 'Senha atual', texto: 'Digite a senha que você usa hoje.' },
        { alvo: campoDo('ms-nova'), acao: 'preencher', titulo: 'Nova senha', texto: 'Pelo menos 8 caracteres, com letras e números.',
          pronto: function () { var s = $('ms-nova').value; return s.length >= 8 && /\d/.test(s) && /[a-z]/i.test(s); },
          falta: 'Use pelo menos 8 caracteres, com letras e números.' },
        { alvo: campoDo('ms-confirma'), acao: 'preencher', titulo: 'Repita a nova senha', texto: 'Digite a nova senha de novo.',
          pronto: function () { return $('ms-confirma').value !== '' && $('ms-confirma').value === $('ms-nova').value; },
          falta: 'As duas senhas precisam ser iguais.' },
        { alvo: '#botao-minha-senha', acao: 'clicar', titulo: 'Trocar', texto: 'Clique em "Trocar minha senha".' },
        { acao: 'esperar', alvo: null, titulo: 'Trocando…', texto: 'Só um instante.',
          pronto: function () { return $('ms-atual').value === '' || aparece('ms-erro') || !!q('#form-minha-senha .erro-campo'); } },
        { alvo: function () { return aparece('ms-erro') ? $('ms-erro') : q('#form-minha-senha .campo.invalido'); },
          titulo: function () { return $('ms-atual').value === '' ? 'Senha trocada!' : 'Falta corrigir uma coisa'; },
          texto: function () {
            return $('ms-atual').value === '' ? 'Use a nova senha no próximo acesso. Os outros aparelhos conectados saíram.'
              : 'O campo com problema está marcado, com a explicação. Corrija e clique em "Trocar minha senha" de novo.';
          } }
      ]
    },

    conhecer: {
      titulo: 'Conhecer o portal', icone: 'explore',
      resumo: 'Onde fica cada coisa, em 1 minuto.',
      passos: [
        { alvo: null, antes: fecharMenu, titulo: 'Conhecer o portal', texto: 'Um passeio rápido pelo menu. Use Próximo ou Enter.' },
        { alvo: '.menu__item[href="#inicio"]', antes: abrirMenu, titulo: 'Início', texto: 'Resumo do mês: notas, valor faturado, notas em andamento e franquia.' },
        { alvo: '.menu__item[href="#emitir"]', antes: abrirMenu, titulo: 'Emitir nota', texto: 'Tomador, serviço e valor. O sistema confere antes de enviar.' },
        { alvo: '.menu__item[href="#notas"]', antes: abrirMenu, titulo: 'Minhas notas', texto: 'Todas as notas, com situação, PDF e XML.' },
        { alvo: '.menu__item[href="#recorrencias"]', antes: abrirMenu, titulo: 'Recorrências', texto: 'A nota que se repete todo mês.' },
        { alvo: '.menu__item[href="#guias"]', antes: abrirMenu, titulo: 'Guias do Simples', texto: 'A guia DAS que a contabilidade preparou.' },
        { alvo: 'nav[aria-label="Cadastros"]', antes: abrirMenu, titulo: 'Tomadores e serviços', texto: 'Cadastre uma vez e escolha na lista.' },
        { alvo: '.menu__item[href="#ajuda"]', antes: abrirMenu, titulo: 'Ajuda', texto: 'Volte aqui sempre que precisar de um guia.' }
      ]
    }
  };

  // =====================================================================
  // Pagina "Ajuda": lista dos guias
  // =====================================================================
  function montarLista() {
    var lista = $('guias-ajuda');
    if (!lista) { return; }
    lista.innerHTML = '';
    Object.keys(GUIAS).forEach(function (chave) {
      var g = GUIAS[chave];
      var botao = document.createElement('button');
      botao.type = 'button';
      botao.className = 'guia-ajuda';
      botao.setAttribute('data-guia', chave);
      var icone = document.createElement('i');
      icone.className = 'icone';
      icone.textContent = g.icone;
      var textos = document.createElement('span');
      var titulo = document.createElement('b');
      titulo.textContent = g.titulo;
      var resumo = document.createElement('small');
      resumo.textContent = g.resumo;
      textos.append(titulo, resumo);
      var seta = document.createElement('i');
      seta.className = 'icone guia-ajuda__seta';
      seta.textContent = 'play_circle';
      botao.append(icone, textos, seta);
      lista.appendChild(botao);
    });
    lista.addEventListener('click', function (evento) {
      var botao = evento.target.closest('[data-guia]');
      if (botao) { iniciarGuia(botao.getAttribute('data-guia')); }
    });
  }

  function iniciarGuia(chave) {
    var g = GUIAS[chave];
    if (!g || !window.Tour) { return; }
    fecharMenu();
    GUIAS.guia.id = '';
    window.Tour.iniciar({ id: 'portal-' + chave, passos: g.passos, aoTerminar: fecharMenu });
  }

  montarLista();
  // Botao "Me guie" na tela Emitir nota
  if ($('botao-guia-emitir')) { $('botao-guia-emitir').addEventListener('click', function () { iniciarGuia('emitir'); }); }
  window.PortalGuias = { iniciar: iniciarGuia, lista: Object.keys(GUIAS) };
})();
