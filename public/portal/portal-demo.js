/* ==========================================================================
   Portal do Cliente - MODO DEMONSTRACAO (so para a previa do layout)
   Responde no lugar da API com dados de exemplo. NAO e carregado pelo
   index.html do servidor: so pela previa publicada.
   ========================================================================== */
(function () {
  'use strict';

  var logado = false;
  var servicos = [
    { id: 1, nome: 'Consultoria em gestão', descricao: 'Consultoria em gestão financeira referente a {mes}.', codigo_tributacao_nacional: '170101', item_lista_servico: '17.01', aliquota_iss: 5, valor_padrao: null },
    { id: 2, nome: 'Desenvolvimento de software', descricao: 'Desenvolvimento de software sob encomenda referente a {mes}.', codigo_tributacao_nacional: '010101', item_lista_servico: '01.01', aliquota_iss: 2, valor_padrao: null }
  ];
  var tomadores = [
    { cpf_cnpj: '00394460005887', nome: 'Clínica Vida Plena Ltda', email: 'financeiro@vidaplena.com.br', cep: '41820020', logradouro: 'Avenida Tancredo Neves', numero: '1000', bairro: 'Caminho das Árvores', cidade: 'Salvador', uf: 'BA', codigo_municipio: '2927408', ultima_emissao: '2026-11-05T10:00:00' },
    { cpf_cnpj: '52998224725', nome: 'Ana Paula Souza', email: 'ana.souza@email.com', cep: '', logradouro: '', numero: '', bairro: '', cidade: '', uf: '', codigo_municipio: '', ultima_emissao: '2026-11-05T09:00:00' }
  ];
  var notas = [
    { id: 'a', numero: null, dps_rps: 124, tomador: 'Clínica Vida Plena Ltda', tomador_documento: '00394460005887', valor: 1500, status: 'PROCESSANDO', finalizado: false, criado_em: '2026-11-05T10:00:00', links: {} },
    { id: 'b', numero: '123', dps_rps: 123, tomador: 'Ana Paula Souza', tomador_documento: '52998224725', valor: 320, status: 'AUTORIZADO', finalizado: true, criado_em: '2026-11-05T09:00:00', links: { pdf: '/#', xml: '/#' } },
    { id: 'c', numero: null, dps_rps: 122, tomador: 'Construtora Atlântico', tomador_documento: '11222333000181', valor: 7800, status: 'REJEITADO', finalizado: true, motivo: 'CEP do tomador não encontrado.', criado_em: '2026-11-04T09:00:00', links: {} },
    { id: 'd', numero: '121', dps_rps: 121, tomador: 'Escola Pequeno Saber', tomador_documento: '04252011000110', valor: 2150, status: 'AUTORIZADO', finalizado: true, criado_em: '2026-11-03T09:00:00', links: { pdf: '/#', xml: '/#' } },
    { id: 'e', numero: '118', dps_rps: 118, tomador: 'Clínica Vida Plena Ltda', tomador_documento: '00394460005887', valor: 1500, status: 'CANCELADO', finalizado: true, criado_em: '2026-10-28T09:00:00', links: { pdf: '/#', xml: '/#' } },
    { id: 'f', numero: '117', dps_rps: 117, tomador: 'Ana Paula Souza', tomador_documento: '52998224725', valor: 320, status: 'AUTORIZADO', finalizado: true, criado_em: '2026-10-05T09:00:00', links: { pdf: '/#', xml: '/#' } }
  ];
  var GRUPOS = { AUTORIZADAS: ['AUTORIZADO'], EM_ANDAMENTO: ['RECEBIDO', 'PROCESSANDO'], COM_PROBLEMA: ['REJEITADO', 'ERRO', 'DENEGADO'], CANCELADAS: ['CANCELADO', 'SUBSTITUIDO'] };

  function parametros(busca) {
    var saida = {};
    busca.split('&').forEach(function (par) {
      var partes = par.split('=');
      if (partes[0]) { saida[partes[0]] = decodeURIComponent((partes[1] || '').replace(/\+/g, ' ')); }
    });
    return saida;
  }

  /** Detalhe de uma nota de exemplo, no formato de GET /v1/portal/notas.php?id= */
  function detalheNota(n) {
    var t = tomadores.filter(function (x) { return x.cpf_cnpj === n.tomador_documento; })[0] || { cpf_cnpj: n.tomador_documento, nome: n.tomador };
    return Object.assign({}, n, {
      padrao: 'NACIONAL',
      tomador_completo: { cpf_cnpj: t.cpf_cnpj, nome: n.tomador, email: t.email, endereco: t.cep ? { logradouro: t.logradouro, numero: t.numero, bairro: t.bairro, uf: t.uf, cep: t.cep } : undefined },
      servico: { descricao: 'Consultoria em gestão financeira referente a novembro/2026.', codigo_tributacao_nacional: '170101', item_lista_servico: '17.01' },
      valores: { valor_servicos: n.valor, aliquota_iss: 5, iss_retido: false },
      competencia: n.criado_em.slice(0, 10),
      nfse: n.numero ? { numero: n.numero, codigo_verificacao: 'AB12CD34', chave_acesso: null, data_autorizacao: n.criado_em } : null,
      cancelamento: {
        forma: 'ENVIAR',
        pode: n.status === 'AUTORIZADO' && !n.pedidoCancelamento,
        evento: n.pedidoCancelamento || (n.status === 'CANCELADO'
          ? { status: 'REGISTRADO', motivo: 'Erro na emissão', texto: 'Valor errado, foi emitida outra nota.', por: 'mariana@exemplo.com.br', criado_em: '2026-10-29T10:00:00', mensagens: [] }
          : null)
      },
      mensagens: n.status === 'REJEITADO' ? [{ codigo: 'E0312', mensagem: 'CEP do tomador não encontrado.', correcao: 'Confira o CEP no cadastro do tomador e emita de novo.' }] : []
    });
  }
  var usuarios = [
    { id: 1, nome: 'Mariana Souza', email: 'mariana@exemplo.com.br', administrador: true, situacao: 'ATIVO', ultimo_acesso: '2026-11-06T09:00:00', eu: true },
    { id: 2, nome: 'Rafael Costa', email: 'rafael@exemplo.com.br', administrador: false, situacao: 'ATIVO', ultimo_acesso: '2026-11-04T14:00:00', eu: false },
    { id: 3, nome: 'Júlia Andrade', email: 'julia@exemplo.com.br', administrador: false, situacao: 'PRIMEIRO_ACESSO', ultimo_acesso: null, eu: false }
  ];
  var numero = 125;
  var recorrencias = [
    { id: 1, nome: 'Mensalidade Clínica Vida', tomador: { cpf_cnpj: '00394460005887', nome: 'Clínica Vida Plena Ltda' }, servico_id: 1, servico_nome: 'Consultoria em gestão',
      descricao: 'Consultoria em gestão financeira referente a {mes}.', valores: { valor_servicos: 1500, iss_retido: false }, dia: 5, competencia: 'ATUAL', modo: 'CONFIRMAR',
      proxima_em: '2026-11-05', fim: null, situacao: 'PENDENTE', competencia_proxima: 'novembro/2026', emitidas: 6, ultima_mensagem: null },
    { id: 2, nome: 'Suporte mensal — Escola', tomador: { cpf_cnpj: '04252011000110', nome: 'Escola Pequeno Saber' }, servico_id: 2, servico_nome: 'Desenvolvimento de software',
      descricao: 'Suporte e manutenção do sistema referente a {mes}.', valores: { valor_servicos: 2150, iss_retido: false }, dia: 10, competencia: 'ANTERIOR', modo: 'AUTOMATICA',
      proxima_em: '2026-11-10', fim: '2027-06', situacao: 'AGENDADA', competencia_proxima: 'outubro/2026', emitidas: 3, ultima_mensagem: null },
    { id: 3, nome: 'Aulas particulares — Ana', tomador: { cpf_cnpj: '52998224725', nome: 'Ana Paula Souza' }, servico_id: 1, servico_nome: 'Consultoria em gestão',
      descricao: 'Consultoria individual referente a {mes}.', valores: { valor_servicos: 320, iss_retido: false }, dia: 15, competencia: 'ATUAL', modo: 'CONFIRMAR',
      proxima_em: '2026-11-15', fim: null, situacao: 'PAUSADA', competencia_proxima: 'novembro/2026', emitidas: 2, ultima_mensagem: null }
  ];
  var MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
  function proximoMes(iso) {
    var p = iso.split('-').map(Number); var m = p[1] === 12 ? 1 : p[1] + 1; var a = p[1] === 12 ? p[0] + 1 : p[0];
    return a + '-' + String(m).padStart(2, '0') + '-' + String(p[2]).padStart(2, '0');
  }
  function competenciaTexto(r) {
    var p = r.proxima_em.split('-').map(Number); var m = p[1] - (r.competencia === 'ANTERIOR' ? 1 : 0); var a = p[0];
    if (m === 0) { m = 12; a--; }
    return MESES[m - 1] + '/' + a;
  }

  function ok(dados, atraso) {
    return new Promise(function (resolve) { setTimeout(function () { resolve(dados); }, atraso || 150); });
  }
  function falha(status, codigo, mensagem) {
    return new Promise(function (resolve, reject) {
      setTimeout(function () { reject({ status: status, codigo: codigo, message: mensagem, erros: [] }); }, 150);
    });
  }

  function falhaCampos(erros) {
    return new Promise(function (resolve, reject) {
      setTimeout(function () { reject({ status: 422, codigo: 'DADOS_INVALIDOS', message: 'Confira os campos.', erros: erros }); }, 150);
    });
  }

  window.PORTAL_DEMO = {
    chamar: function (metodo, caminho, corpo) {
      var rota = caminho.split('?')[0];
      var busca = caminho.split('?')[1] || '';
      document.getElementById('aviso-demo').hidden = false;

      if (rota === 'entrar.php') { logado = true; return ok({ usuario: {}, trocar_senha: false }, 400); }
      if (rota === 'sair.php') { logado = false; return ok({}); }
      if (!logado) { return falha(401, 'SESSAO_EXPIRADA', ''); }

      if (rota === 'sessao.php') {
        return ok({
          usuario: { nome: 'Mariana Souza', email: 'mariana@exemplo.com.br', trocar_senha: false },
          empresa: { cnpj: '41986662000160', razao_social: 'Studio Bela Forma Ltda', nome_fantasia: 'Studio Bela Forma', padrao: 'NACIONAL', ambiente: 'HOMOLOGACAO', certificado: { validade: '2027-03-12', dias_restantes: 126 } }
        });
      }
      if (rota === 'inicio.php') {
        return ok({
          resumo: { mes: '2026-11', notas_no_mes: 37, por_status: { AUTORIZADO: 35, REJEITADO: 1, CANCELADO: 1 }, em_andamento: 1, faturado_no_mes: 48320, franquia: { usado: 37, limite: 100 },
            avisos: [{ tipo: 'RECORRENCIA_PENDENTE', titulo: '1 nota recorrente esperando você', texto: 'Abra Recorrências para emitir ou pular o mês.' }, { tipo: 'NOTA_REJEITADA', titulo: 'Nota para Construtora Atlântico não foi autorizada', texto: 'CEP do tomador não encontrado. Corrija e emita de novo.' }] },
          notas: notas.slice(0, 5)
        });
      }
      var q = parametros(busca);
      if (rota === 'usuarios.php') {
        if (metodo === 'POST') {
          if (corpo.acao === 'convidar') {
            usuarios.push({ id: Date.now(), nome: corpo.nome, email: corpo.email.toLowerCase(), administrador: !!corpo.administrador, situacao: 'PRIMEIRO_ACESSO', ultimo_acesso: null, eu: false });
            return ok({ ok: true, email: corpo.email.toLowerCase(), senha_provisoria: 'Ab3dE5gH7k', mensagem: 'Acesso criado.' });
          }
          var alvo = usuarios.filter(function (u) { return u.id === corpo.id; })[0];
          if (corpo.acao === 'redefinir_senha') { alvo.situacao = 'PRIMEIRO_ACESSO'; return ok({ ok: true, email: alvo.email, senha_provisoria: 'Zx9cV8bN7m' }); }
          if (corpo.acao === 'desativar') { alvo.situacao = 'DESATIVADO'; }
          if (corpo.acao === 'ativar') { alvo.situacao = 'ATIVO'; }
          if (corpo.acao === 'tornar_admin' || corpo.acao === 'remover_admin') { alvo.administrador = corpo.acao === 'tornar_admin'; }
          return ok({ ok: true, mensagem: 'Feito.' });
        }
        return ok({ usuarios: usuarios, pode_gerenciar: true });
      }
      if (rota === 'recorrencias.php') {
        if (metodo === 'POST') {
          var rec = recorrencias.filter(function (r) { return r.id === corpo.id; })[0];
          if (corpo.acao === 'salvar') {
            var erros = [];
            if (!corpo.nome) { erros.push({ campo: 'nome', mensagem: 'Dê um nome para achar na lista (ex.: Mensalidade Clínica Vida).' }); }
            if (!corpo.tomador.cpf_cnpj) { erros.push({ campo: 'tomador.cpf_cnpj', mensagem: 'Escolha um tomador com CPF ou CNPJ válido.' }); }
            if (!corpo.servico_id) { erros.push({ campo: 'servico_id', mensagem: 'Escolha um dos seus serviços salvos.' }); }
            if (!(corpo.valores.valor_servicos > 0)) { erros.push({ campo: 'valores.valor_servicos', mensagem: 'Informe o valor do serviço (maior que zero).' }); }
            if (!rec && !corpo.primeira_em) { erros.push({ campo: 'primeira_em', mensagem: 'Escolha a data da próxima nota.' }); }
            if (erros.length) { return falhaCampos(erros); }
            var srvRec = servicos.filter(function (x) { return x.id === corpo.servico_id; })[0] || {};
            var novo = Object.assign(rec || { id: Date.now(), emitidas: 0, ultima_mensagem: null }, {
              nome: corpo.nome, tomador: corpo.tomador, servico_id: corpo.servico_id, servico_nome: srvRec.nome, descricao: corpo.descricao,
              valores: corpo.valores, competencia: corpo.competencia, modo: corpo.modo, fim: corpo.fim || null
            });
            if (corpo.primeira_em) { novo.proxima_em = corpo.primeira_em; novo.dia = Number(corpo.primeira_em.slice(8)); }
            novo.situacao = novo.proxima_em <= '2026-11-06' ? 'PENDENTE' : 'AGENDADA';
            novo.competencia_proxima = competenciaTexto(novo);
            if (!rec) { recorrencias.push(novo); }
            return ok({ ok: true, id: novo.id, mensagem: rec ? 'Nota recorrente atualizada.' : 'Nota recorrente criada.' });
          }
          if (corpo.acao === 'remover') { recorrencias = recorrencias.filter(function (r) { return r.id !== corpo.id; }); return ok({ ok: true, mensagem: 'Nota recorrente apagada. As notas já emitidas continuam guardadas.' }); }
          if (corpo.acao === 'pausar') { rec.situacao = 'PAUSADA'; return ok({ ok: true, mensagem: 'Pausada. Nenhuma nota sai até você retomar.' }); }
          var msg = '';
          if (corpo.acao === 'emitir') { msg = 'Nota de ' + rec.competencia_proxima + ' enviada para a prefeitura.'; rec.emitidas++; }
          if (corpo.acao === 'emitir' || corpo.acao === 'pular') { rec.proxima_em = proximoMes(rec.proxima_em); if (!msg) { msg = 'Mês pulado.'; } }
          if (corpo.acao === 'retomar') { msg = 'Retomada.'; }
          rec.situacao = rec.proxima_em <= '2026-11-06' ? 'PENDENTE' : 'AGENDADA';
          rec.competencia_proxima = competenciaTexto(rec);
          return ok({ ok: true, mensagem: msg }, corpo.acao === 'emitir' ? 900 : 150);
        }
        return ok({ recorrencias: recorrencias });
      }
      if (rota === 'cancelar.php') {
        var errosCanc = [];
        if (!corpo.motivo_codigo) { errosCanc.push({ campo: 'motivo_codigo', mensagem: 'Escolha o motivo do cancelamento.' }); }
        if ((corpo.motivo || '').length < 15) { errosCanc.push({ campo: 'motivo', mensagem: 'Explique em 15 a 255 letras (ex.: "Valor errado, será emitida outra nota").' }); }
        if (errosCanc.length) { return falhaCampos(errosCanc); }
        var cancelada = notas.filter(function (n) { return n.id === corpo.id; })[0];
        cancelada.pedidoCancelamento = { status: 'RECEBIDO', motivo: { 1: 'Erro na emissão', 2: 'Serviço não prestado', 9: 'Outro motivo' }[corpo.motivo_codigo], texto: corpo.motivo, por: 'mariana@exemplo.com.br', criado_em: '2026-11-06T10:00:00', mensagens: [] };
        return ok({ ok: true, forma: 'ENVIAR', mensagem: 'Pedido de cancelamento enviado. A nota muda para "Cancelada" quando a prefeitura confirmar.' }, 600);
      }
      if (rota === 'senha.php') { return ok({ mensagem: 'Senha alterada.' }); }
      if (rota === 'esqueci.php') {
        if (metodo === 'GET') { return ok({ disponivel: true }); }
        if (corpo.acao === 'pedir') { return ok({ ok: true, mensagem: 'Se este e-mail tiver acesso ao portal, enviamos um link para criar uma senha nova. Confira a caixa de entrada e o spam. O link vale 1 hora.' }, 500); }
        if (corpo.acao === 'conferir') { return ok({ ok: true, nome: 'Mariana', email: 'mariana@exemplo.com.br' }); }
        return ok({ ok: true, email: 'mariana@exemplo.com.br', mensagem: 'Senha criada. Entre com o seu e-mail e a senha nova.' });
      }
      if (rota === 'empresa.php') {
        return ok({
          cadastro: { cnpj: '41986662000160', razao_social: 'Studio Bela Forma Ltda', nome_fantasia: 'Studio Bela Forma', inscricao_municipal: '123456', inscricao_estadual: null, codigo_municipio: '2927408', municipio: 'Salvador / BA', email: 'contato@belaforma.com.br', telefone: '71999990000' },
          fiscal: { simples_nacional: 'Microempresa ou EPP', apuracao: 'Tributos federais e ISS pelo Simples Nacional', regime_especial: 'Nenhum', incentivador_cultural: false, padroes: ['NACIONAL'], padrao_portal: 'NACIONAL', ambiente: 'HOMOLOGACAO' },
          certificado: { enviado: true, titular: 'STUDIO BELA FORMA LTDA:41986662000160', validade: '2027-03-12', dias_restantes: 126, atualizado_em: '2026-03-12' },
          plano: { nome: 'Essencial', limite_mensal: 100, usado_no_mes: 37, liberado_ate: '2027-01-31', situacao: 'LIBERADA', situacao_texto: 'Liberada', motivo_bloqueio: null }
        });
      }
      if (rota === 'notas.php') {
        if (q.id) {
          var achada = notas.filter(function (n) { return n.id === q.id; })[0];
          return achada ? ok({ nota: detalheNota(achada) }) : falha(404, 'NOTA_NAO_ENCONTRADA', 'Nota não encontrada.');
        }
        var termo = (q.busca || '').toLowerCase();
        var filtradas = notas.filter(function (n) {
          return (!q.status || (GRUPOS[q.status] || []).indexOf(n.status) >= 0)
            && (!q.mes || n.criado_em.slice(0, 7) === q.mes)
            && (!termo || n.tomador.toLowerCase().indexOf(termo) >= 0 || n.tomador_documento.indexOf(termo.replace(/\D/g, '') || '-') >= 0 || String(n.numero) === termo);
        });
        return ok({ notas: filtradas, total: filtradas.length, pagina: 1, paginas: 1 });
      }
      if (rota === 'servicos.php') {
        if (metodo === 'DELETE') { servicos = servicos.filter(function (s) { return String(s.id) !== q.id; }); return ok({ ok: true }); }
        if (metodo === 'POST') {
          if (!corpo.nome || (corpo.descricao || '').length < 5) {
            return falhaCampos([!corpo.nome ? { campo: 'nome', mensagem: 'Dê um nome curto para achar o serviço na lista.' } : null,
              (corpo.descricao || '').length < 5 ? { campo: 'descricao', mensagem: 'A descrição precisa ter entre 5 e 2000 caracteres.' } : null].filter(Boolean));
          }
          var srv = Object.assign({}, corpo, { id: corpo.id || Date.now(), aliquota_iss: corpo.aliquota_iss === '' ? null : corpo.aliquota_iss, valor_padrao: corpo.valor_padrao || null });
          servicos = servicos.filter(function (s) { return s.id !== srv.id; }).concat([srv]);
          return ok({ ok: true, servico: srv });
        }
        return ok({ servicos: servicos });
      }
      if (rota === 'tomadores.php') {
        if (metodo === 'DELETE') { tomadores = tomadores.filter(function (t) { return t.cpf_cnpj !== q.cpf_cnpj; }); return ok({ ok: true, mensagem: 'Tomador removido da lista. As notas dele continuam guardadas.' }); }
        if (metodo === 'POST') {
          if (!corpo.nome || corpo.nome.length < 2) { return falhaCampos([{ campo: 'nome', mensagem: 'Informe o nome ou a razão social.' }]); }
          var antigo = tomadores.filter(function (t) { return t.cpf_cnpj === corpo.cpf_cnpj; })[0] || {};
          var tom = Object.assign({}, antigo, corpo);
          tomadores = tomadores.filter(function (t) { return t.cpf_cnpj !== tom.cpf_cnpj; }).concat([tom]);
          return ok({ ok: true, tomador: tom });
        }
        var t2 = (q.busca || '').toLowerCase();
        return ok({ tomadores: tomadores.filter(function (t) { return !t2 || t.nome.toLowerCase().indexOf(t2) >= 0 || t.cpf_cnpj.indexOf(t2) >= 0; }) });
      }
      if (rota === 'consulta.php') {
        var valor = busca.split('=')[1];
        if (busca.indexOf('cep=') === 0) {
          return ok({ cep: valor, logradouro: 'Avenida Estados Unidos', bairro: 'Comércio', cidade: 'Salvador', uf: 'BA', codigo_municipio: '2927408' });
        }
        var salvo = tomadores.filter(function (t) { return t.cpf_cnpj === valor; })[0];
        if (salvo) { return ok(Object.assign({ tipo: 'SALVO' }, salvo)); }
        if (valor.length === 11) { return falha(404, 'NAO_ENCONTRADO', 'Tomador ainda não cadastrado. Preencha o nome.'); }
        return ok({ tipo: 'CNPJ', cpf_cnpj: valor, nome: 'Empresa Exemplo Ltda', email: 'contato@exemplo.com.br', cep: '41820020', logradouro: 'Avenida Tancredo Neves', numero: '500', bairro: 'Caminho das Árvores', cidade: 'Salvador', uf: 'BA', codigo_municipio: '2927408' }, 500);
      }
      if (rota === 'emitir.php') {
        var n = String(numero++);
        notas.unshift({ id: n, numero: n, dps_rps: Number(n), tomador: corpo.tomador.nome, tomador_documento: corpo.tomador.cpf_cnpj, finalizado: true, valor: corpo.valores.valor_servicos, status: 'AUTORIZADO', criado_em: new Date().toISOString(), links: { pdf: '/#', xml: '/#' } });
        return ok({ situacao_recebimento: 'NOVO', nota: { id: n, status: 'AUTORIZADO', nfse: { numero: n }, dps_rps: { numero: Number(n) }, mensagens: [], links: { pdf: '/#', xml: '/#' } } }, 1200);
      }
      return falha(404, 'NAO_ENCONTRADO', 'Rota de demonstração inexistente.');
    }
  };
})();
