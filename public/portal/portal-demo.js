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
    { cpf_cnpj: '00394460005887', nome: 'Clínica Vida Plena Ltda', email: 'financeiro@vidaplena.com.br', cep: '41820020', logradouro: 'Avenida Tancredo Neves', numero: '1000', bairro: 'Caminho das Árvores', cidade: 'Salvador', uf: 'BA', codigo_municipio: '2927408' },
    { cpf_cnpj: '52998224725', nome: 'Ana Paula Souza', email: 'ana.souza@email.com', cep: '', logradouro: '', numero: '', bairro: '', cidade: '', uf: '', codigo_municipio: '' }
  ];
  var notas = [
    { id: 'a', numero: '124', tomador: 'Clínica Vida Plena Ltda', valor: 1500, status: 'PROCESSANDO', criado_em: '2026-11-05T10:00:00', links: {} },
    { id: 'b', numero: '123', tomador: 'Ana Paula Souza', valor: 320, status: 'AUTORIZADO', criado_em: '2026-11-05T09:00:00', links: { pdf: '/#', xml: '/#' } },
    { id: 'c', numero: null, dps_rps: 122, tomador: 'Construtora Atlântico', valor: 7800, status: 'REJEITADO', motivo: 'CEP do tomador não encontrado.', criado_em: '2026-11-04T09:00:00', links: {} },
    { id: 'd', numero: '121', tomador: 'Escola Pequeno Saber', valor: 2150, status: 'AUTORIZADO', criado_em: '2026-11-03T09:00:00', links: { pdf: '/#', xml: '/#' } }
  ];
  var numero = 125;

  function ok(dados, atraso) {
    return new Promise(function (resolve) { setTimeout(function () { resolve(dados); }, atraso || 150); });
  }
  function falha(status, codigo, mensagem) {
    return new Promise(function (resolve, reject) {
      setTimeout(function () { reject({ status: status, codigo: codigo, message: mensagem, erros: [] }); }, 150);
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
            avisos: [{ tipo: 'NOTA_REJEITADA', titulo: 'Nota para Construtora Atlântico não foi autorizada', texto: 'CEP do tomador não encontrado. Corrija e emita de novo.' }] },
          notas: notas.slice(0, 5)
        });
      }
      if (rota === 'servicos.php') { return ok({ servicos: servicos }); }
      if (rota === 'tomadores.php') { return ok({ tomadores: tomadores }); }
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
        notas.unshift({ id: n, numero: n, tomador: corpo.tomador.nome, valor: corpo.valores.valor_servicos, status: 'AUTORIZADO', criado_em: new Date().toISOString(), links: { pdf: '/#', xml: '/#' } });
        return ok({ situacao_recebimento: 'NOVO', nota: { id: n, status: 'AUTORIZADO', nfse: { numero: n }, dps_rps: { numero: Number(n) }, mensagens: [], links: { pdf: '/#', xml: '/#' } } }, 1200);
      }
      return falha(404, 'NAO_ENCONTRADO', 'Rota de demonstração inexistente.');
    }
  };
})();
