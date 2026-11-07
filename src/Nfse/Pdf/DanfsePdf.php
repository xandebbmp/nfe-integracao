<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Pdf;

use Com\Tecnick\Barcode\Barcode;
use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * DANFSe v2.0 (PDF da NFS-e do Padrao Nacional), gerado a partir do XML autorizado.
 *
 * Por que existe: a API oficial que gerava o DANFSe (ADN) foi desligada em
 * 03/08/2026. Desde entao cada sistema gera o proprio PDF, seguindo a
 * Nota Tecnica SE/CGNFS-e 008/2026 v1.02 (layout no Anexo I).
 *
 * Como ler este codigo:
 *  - A pagina e uma grade de 4 colunas (posicoes em mm, como na NT).
 *  - Cada bloco (Prestador, Tomador...) e uma lista de linhas; cada linha
 *    tem ate 4 campos [rotulo, valor, quantas colunas ocupa].
 *  - Os valores vem SEMPRE do XML da NFS-e (a NT proibe imprimir outra coisa).
 *    Campo vazio no XML = traco (-), como manda a NT (nota 12).
 *
 * Limitacao conhecida: o XML so traz o NOME dos municipios de emissao,
 * prestacao e incidencia. Para outros municipios (ex.: do tomador)
 * imprimimos o codigo IBGE.
 */
final class DanfsePdf
{
    private const NS = 'http://www.sped.fazenda.gov.br/nfse';
    private const URL_CONSULTA = 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=';

    // Grade da NT 008 (em mm): margem 3 mm, 4 colunas de 50,9 mm, linha de 6,3 mm.
    private const X = [3.0, 54.1, 105.1, 156.2];
    private const COLUNA = 50.9;
    private const LARGURA = 204.0;
    private const LINHA = 6.3;
    private const CINZA = 242; // cinza claro (~5%) dos titulos

    private PdfComMarcaDagua $pdf;
    private DOMXPath $xp;
    private float $y = 0;

    /**
     * @param string $xmlNfse XML da NFS-e autorizada (como veio do Sefin)
     * @param string $status  status atual do documento (CANCELADO / SUBSTITUIDO imprimem marca d'agua)
     */
    public function gerar(string $xmlNfse, string $status = ''): string
    {
        if (trim($xmlNfse) === '') {
            throw new RuntimeException('NFS-e sem XML autorizado: DANFSe indisponivel.');
        }
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xmlNfse)) {
            throw new RuntimeException('XML da NFS-e invalido: DANFSe indisponivel.');
        }
        $this->xp = new DOMXPath($dom);
        $this->xp->registerNamespace('n', self::NS);
        if ($this->v('//n:infNFSe/@Id') === '') {
            throw new RuntimeException('XML nao e de uma NFS-e do Padrao Nacional.');
        }

        $this->pdf = new PdfComMarcaDagua('P', 'mm', 'A4');
        $this->pdf->setMargins(0, 0, 0);
        $this->pdf->setAutoPageBreak(false);
        $this->pdf->addPage();
        $this->pdf->setLineWidth(0.18); // 0,5 ponto

        $this->cabecalho();
        $this->dadosDaNfse();
        $this->blocoPessoa('PRESTADOR / FORNECEDOR', 'prest', true, '');
        $this->blocoPessoa('TOMADOR / ADQUIRENTE', 'toma', false, 'TOMADOR/ADQUIRENTE DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
        $this->blocoDestinatario();
        $this->blocoPessoa('INTERMEDIÁRIO DA OPERAÇÃO', 'interm', false, 'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
        $this->servico();
        $this->tributacaoMunicipal();
        $this->tributacaoFederal();
        $this->tributacaoIbsCbs();
        $this->valorTotal();
        $this->informacoesComplementares();

        // Marca d'agua por ULTIMO (NT 2.5). Ela usa o modo multiplicar, entao
        // os campos continuam legiveis e as faixas cinza nao cortam as letras.
        if ($status === 'CANCELADO') {
            $this->marcaDagua('CANCELADA');
        } elseif ($status === 'SUBSTITUIDO') {
            $this->marcaDagua('SUBSTITUÍDA');
        }

        // Borda da pagina (1 ponto).
        $this->pdf->setLineWidth(0.35);
        $this->pdf->rect(1.5, 1.5, 207, 294);

        return $this->pdf->output('', 'S');
    }

    // =================================================================
    // Blocos
    // =================================================================

    private function cabecalho(): void
    {
        $p = $this->pdf;
        $p->setFillColor(self::CINZA);
        $p->rect(3, 3, self::LARGURA, 11.6, 'F');
        $p->image(__DIR__ . '/danfse-logo.png', 4.9, 4.4, 40);

        $p->setFont('Arial', 'B', 9);
        $p->setXY(54.1, 3.4);
        $p->cell(101.9, 3.6, $this->t('DANFSe v2.0'), 0, 2, 'C');
        $p->cell(101.9, 3.6, $this->t('Documento Auxiliar da NFS-e'), 0, 2, 'C');
        if ($this->v('//n:infDPS/n:tpAmb') === '2') {
            $p->settextColor(255, 0, 0);
            $p->cell(101.9, 3.6, $this->t('NFS-e SEM VALIDADE JURÍDICA'), 0, 2, 'C');
            $p->settextColor(0);
        }

        $uf = self::ufDoIbge($this->v('//n:infDPS/n:cLocEmi'));
        $p->setXY(156.2, 3.6);
        $p->setFont('Arial', '', 8);
        $p->cell(50.9, 3.6, $this->t($this->cortar('Município: ' . $this->v('//n:infNFSe/n:xLocEmi') . ' / ' . $uf, 37)), 0, 2);
        $p->setFont('Arial', '', 6);
        $ambGer = ['1' => 'Prefeitura', '2' => 'Sistema Nacional da NFS-e'];
        $tpAmb = ['1' => 'Produção', '2' => 'Homologação'];
        $p->cell(50.9, 2.6, $this->t('Ambiente Gerador: ' . ($ambGer[$this->v('//n:infNFSe/n:ambGer')] ?? '-')), 0, 2);
        $p->cell(50.9, 2.6, $this->t('Tipo de Ambiente: ' . ($tpAmb[$this->v('//n:infDPS/n:tpAmb')] ?? '-')), 0, 2);

        $this->y = 14.8;
    }

    private function dadosDaNfse(): void
    {
        $this->divisoria();
        $tpEmit = ['1' => 'Prestador', '2' => 'Tomador', '3' => 'Intermediário'];
        $cStat = ['100' => 'NFS-e Gerada', '101' => 'NFS-e de Substituição Gerada', '102' => 'NFS-e de Decisão Judicial', '103' => 'NFS-e Avulsa'];
        $finNFSe = ['0' => 'NFS-e regular'];

        // Nesta area os rotulos sao 7 pt em caixa alta (NT 2.4.2).
        $linhas = [
            [['CHAVE DE ACESSO DA NFS-E', $this->chave(), 3]],
            [['NÚMERO DA NFS-E', $this->v('//n:infNFSe/n:nNFSe')], ['COMPETÊNCIA DA NFS-E', self::data($this->v('//n:infDPS/n:dCompet'))], ['DATA E HORA DA EMISSÃO DA NFS-E', self::dataHora($this->v('//n:infNFSe/n:dhProc'))]],
            [['NÚMERO DA DPS', $this->v('//n:infDPS/n:nDPS')], ['SÉRIE DA DPS', $this->v('//n:infDPS/n:serie')], ['DATA E HORA DA EMISSÃO DA DPS', self::dataHora($this->v('//n:infDPS/n:dhEmi'))]],
            [['EMITENTE DA NFS-E', $tpEmit[$this->v('//n:infDPS/n:tpEmit')] ?? '-'], ['SITUAÇÃO DA NFS-E', $this->cortar($cStat[$this->v('//n:infNFSe/n:cStat')] ?? '-', 37)], ['FINALIDADE', $finNFSe[$this->v('//n:infDPS/n:finNFSe')] ?? '-']],
        ];
        $inicio = $this->y;
        $this->pdf->setFillColor(self::CINZA);
        $this->pdf->rect(self::X[0], $inicio + 3 * self::LINHA, self::COLUNA, self::LINHA, 'F'); // "Emitente" com fundo cinza
        foreach ($linhas as $linha) {
            $this->linhaDeCampos($linha, 7);
        }

        $this->qrCode(174.8, $inicio + 1.0, 15.2);
        $this->pdf->setFont('Arial', '', 6);
        $this->pdf->setXY(156.6, $inicio + 16.8);
        $this->pdf->multicell(50.0, 2.3, $this->t('A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no portal nacional da NFS-e'), 0, 'L');
    }

    /** Prestador, Tomador e Intermediario tem o mesmo desenho. */
    private function blocoPessoa(string $titulo, string $grupo, bool $ehPrestador, string $semDados): void
    {
        $this->divisoria();
        $base = '//n:infDPS/n:' . $grupo;
        if (!$this->existe($base)) {
            $this->mensagemDoBloco($semDados);
            return;
        }

        // O prestador tem nome e endereco no grupo <emit> da NFS-e (a DPS pode nao trazer).
        $dados = $ehPrestador ? '//n:infNFSe/n:emit' : $base;
        $end = $ehPrestador ? $dados . '/n:enderNac' : $base . '/n:end/n:endNac';
        $endExt = $base . '/n:end/n:endExt';

        $linhas = [
            [['CNPJ / CPF / NIF', $this->documento($base)], ['Indicador Municipal (Inscrição)', $this->primeiro([$base . '/n:IM', $dados . '/n:IM'])], ['Telefone', $this->primeiro([$base . '/n:fone', $dados . '/n:fone'])]],
            [['Nome / Nome Empresarial', $this->cortar($this->primeiro([$dados . '/n:xNome', $base . '/n:xNome']), 77), 2, 0], ['Município / Sigla UF', $this->municipio($end, $endExt)], ['Código IBGE / CEP', $this->ibgeCep($end, $endExt)]],
            [['Endereço', $this->cortar($this->endereco($ehPrestador ? $end : $base . '/n:end'), 77), 2, 0], ['E-mail', $this->cortar($this->primeiro([$base . '/n:email', $dados . '/n:email']), 77), 2]],
        ];
        if ($ehPrestador) {
            $opSimpNac = ['1' => 'Não Optante', '2' => 'Optante - MEI', '3' => 'Optante - ME/EPP'];
            $regAp = [
                '1' => 'Regime de apuração dos tributos federais e municipal pelo SN',
                '2' => 'Tributos federais pelo SN e ISSQN por fora do SN',
                '3' => 'Tributos federais e municipal por fora do SN',
            ];
            $linhas[] = [
                ['Simples Nacional na Data de Competência', $opSimpNac[$this->v($base . '/n:regTrib/n:opSimpNac')] ?? '-', 1, 0],
                ['Regime de Apuração Tributária pelo SN', $regAp[$this->v($base . '/n:regTrib/n:regApTribSN')] ?? '-', 3],
            ];
        }
        $this->tituloDoBloco($titulo);
        $primeira = true;
        foreach ($linhas as $linha) {
            if ($primeira) {
                array_unshift($linha, null); // a coluna 1 da primeira linha e o titulo do bloco
                $primeira = false;
            }
            $this->linhaDeCampos($linha);
        }
    }

    private function blocoDestinatario(): void
    {
        $this->divisoria();
        $base = '//n:infDPS/n:IBSCBS/n:dest';
        if (!$this->existe($base)) {
            $this->mensagemDoBloco('O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO');
            return;
        }
        $end = $base . '/n:end/n:endNac';
        $endExt = $base . '/n:end/n:endExt';
        $this->tituloDoBloco('DESTINATÁRIO DA OPERAÇÃO');
        $this->linhaDeCampos([null, ['CNPJ / CPF / NIF', $this->documento($base)], null, ['Telefone', $this->v($base . '/n:fone')]]);
        $this->linhaDeCampos([['Nome / Nome Empresarial', $this->cortar($this->v($base . '/n:xNome'), 77), 2], ['Município / Sigla UF', $this->municipio($end, $endExt)], ['Código IBGE / CEP', $this->ibgeCep($end, $endExt)]]);
        $this->linhaDeCampos([['Endereço', $this->cortar($this->endereco($base . '/n:end'), 77), 2], ['E-mail', $this->cortar($this->v($base . '/n:email'), 77), 2]]);
    }

    private function servico(): void
    {
        $this->divisoria();
        $s = '//n:infDPS/n:serv';
        $codigo = self::codigoTributacao($this->v($s . '/n:cServ/n:cTribNac')) . ' / ' . $this->traco($this->v($s . '/n:cServ/n:cTribMun'));
        $local = $this->v('//n:infNFSe/n:xLocPrestacao');
        $local = $local !== ''
            ? $local . ' / ' . self::ufDoIbge($this->v($s . '/n:locPrest/n:cLocPrestacao')) . ' / BR'
            : $this->traco($this->v($s . '/n:locPrest/n:cPaisPrestacao'));

        $this->tituloDoBloco('SERVIÇO PRESTADO');
        $this->linhaDeCampos([null, ['Código de Tributação Nacional / Municipal', $codigo], ['Código da NBS', $this->traco($this->v($s . '/n:cServ/n:cNBS'))], ['Local da Prestação / Sigla UF / País', $this->cortar($local, 42)]]);

        // Descricao do codigo (sem rotulo): a municipal, se existir; senao a nacional.
        $descricaoCodigo = $this->primeiro(['//n:infNFSe/n:xTribMun', '//n:infNFSe/n:xTribNac']);
        $this->pdf->setFont('Arial', '', 7);
        $this->pdf->setXY(self::X[0], $this->y + 0.6);
        $this->pdf->cell(self::LARGURA, 3, $this->t($this->cortar($descricaoCodigo, 167)), 0, 0);
        $this->y += 3.8;

        $this->pdf->setFont('Arial', 'B', 6);
        $this->pdf->setXY(self::X[0], $this->y + 0.4);
        $this->pdf->cell(self::LARGURA, 2.6, $this->t('Descrição do Serviço'), 0, 2);
        $this->pdf->setFont('Arial', '', 7);
        $this->pdf->setX(self::X[0]);
        $antes = $this->pdf->getY();
        $this->pdf->multicell(self::LARGURA, 3, $this->t($this->cortar($this->v($s . '/n:cServ/n:xDescServ'), 1297)), 0, 'L');
        $this->y = max($this->pdf->getY(), $antes + 12) + 1;
    }

    private function tributacaoMunicipal(): void
    {
        $this->divisoria();
        $m = '//n:infDPS/n:valores/n:trib/n:tribMun';
        $tribISSQN = ['1' => 'Operação Tributável', '2' => 'Imunidade', '3' => 'Exportação de Serviço', '4' => 'Não Incidência'];
        $regEsp = ['0' => 'Nenhum', '1' => 'Ato Cooperado (Cooperativa)', '2' => 'Estimativa', '3' => 'Microempresa Municipal', '4' => 'Notário ou Registrador', '5' => 'Profissional Autônomo', '6' => 'Sociedade de Profissionais', '9' => 'Outros'];
        $tpRet = ['1' => 'Não Retido', '2' => 'Retido pelo Tomador', '3' => 'Retido pelo Intermediário'];
        $tpSusp = ['1' => 'Exigibilidade Suspensa por Decisão Judicial', '2' => 'Exigibilidade Suspensa por Processo Administrativo'];

        $incidencia = $this->v('//n:infNFSe/n:xLocIncid');
        $incidencia = $incidencia !== '' ? $incidencia . ' / ' . self::ufDoIbge($this->v('//n:infNFSe/n:cLocIncid')) . ' / BR' : '-';

        $this->tituloDoBloco('TRIBUTAÇÃO MUNICIPAL (ISSQN)');
        $this->linhaDeCampos([null, ['Tipo de Tributação do ISSQN', $tribISSQN[$this->v($m . '/n:tribISSQN')] ?? '-'], ['Município / Sigla UF / País de Incidência do ISSQN', $this->cortar($incidencia, 42), 2]]);
        $this->linhaDeCampos([
            ['Regime Especial de Tributação do ISSQN', $regEsp[$this->v('//n:infDPS/n:prest/n:regTrib/n:regEspTrib')] ?? '-'],
            ['Tipo de Imunidade do ISSQN', $this->cortar($this->traco($this->v($m . '/n:tpImunidade')), 37)],
            ['Suspensão da Exigibilidade do ISSQN', $this->cortar($tpSusp[$this->v($m . '/n:exigSusp/n:tpSusp')] ?? '-', 37)],
            ['Número Processo Suspensão', $this->traco($this->v($m . '/n:exigSusp/n:nProcesso'))],
        ]);
        // Linha opcional (NT nota 5): so aparece se houver algum dado.
        $bm = $this->v($m . '/n:BM/n:tpBM');
        $calcBm = $this->primeiro(['//n:infNFSe/n:valores/n:vCalcBM', '//n:infNFSe/n:valores/n:vRedBCBM']);
        $deducoes = $this->primeiro(['//n:infDPS/n:valores/n:vDedRed/n:vDR', '//n:infNFSe/n:valores/n:vCalcDR']);
        $descIncond = $this->v('//n:infDPS/n:valores/n:vDescCondIncond/n:vDescIncond');
        if ($bm . $calcBm . $deducoes . $descIncond !== '') {
            $this->linhaDeCampos([['Benefício Municipal', $this->traco($bm)], ['Cálculo do BM', $this->moeda($calcBm)], ['Total Deduções/Reduções', $this->moeda($deducoes)], ['Desconto Incondicionado', $this->moeda($descIncond)]]);
        }
        $aliquota = $this->v('//n:infNFSe/n:valores/n:pAliqAplic');
        $this->linhaDeCampos([
            ['BC ISSQN', $this->moeda($this->v('//n:infNFSe/n:valores/n:vBC'))],
            ['Alíquota Aplicada', $aliquota === '' ? '-' : self::numero($aliquota) . '%'],
            ['Retenção do ISSQN', $tpRet[$this->v($m . '/n:tpRetISSQN')] ?? '-'],
            ['ISSQN Apurado', $this->moeda($this->v('//n:infNFSe/n:valores/n:vISSQN'))],
        ]);
    }

    private function tributacaoFederal(): void
    {
        $this->divisoria();
        $f = '//n:infDPS/n:valores/n:trib/n:tribFed';
        $tpRetPisCofins = $this->v($f . '/n:piscofins/n:tpRetPisCofins');
        $csll = (float)$this->v($f . '/n:vRetCSLL');
        $pis = (float)$this->v($f . '/n:piscofins/n:vPis');
        $cofins = (float)$this->v($f . '/n:piscofins/n:vCofins');
        $retidas = $tpRetPisCofins === '1' ? $csll + $pis + $cofins : $csll;
        $descricoes = ['0' => 'PIS/COFINS/CSLL Não Retidos', '1' => 'PIS/COFINS Retidos', '2' => 'PIS/COFINS Não Retidos', '3' => 'PIS/COFINS/CSLL Retidos', '4' => 'PIS/COFINS Retidos, CSLL Não Retido', '5' => 'PIS Retido, COFINS/CSLL Não Retido', '6' => 'COFINS Retido, PIS/CSLL Não Retido', '7' => 'PIS Não Retido, COFINS/CSLL Retidos', '8' => 'PIS/COFINS Não Retidos, CSLL Retido', '9' => 'COFINS Não Retido, PIS/CSLL Retidos'];

        $this->tituloDoBloco('TRIBUTAÇÃO FEDERAL (EXCETO CBS)');
        $this->linhaDeCampos([null, ['IRRF', $this->moeda($this->v($f . '/n:vRetIRRF'))], ['Contribuição Previdenciária - Retida', $this->moeda($this->v($f . '/n:vRetCP'))], ['Contribuições Sociais - Retidas', $retidas > 0 ? $this->moeda((string)$retidas) : '-']]);
        // Esta linha so vale para competencia ate 2026 (NT nota 6).
        if ((int)substr($this->v('//n:infDPS/n:dCompet'), 0, 4) <= 2026) {
            $this->linhaDeCampos([
                ['PIS - Débito Apuração Própria', $tpRetPisCofins === '1' ? 'R$ 0,00' : $this->moeda($this->v($f . '/n:piscofins/n:vPis'))],
                ['COFINS - Débito Apuração Própria', $tpRetPisCofins === '1' ? 'R$ 0,00' : $this->moeda($this->v($f . '/n:piscofins/n:vCofins'))],
                ['Descrição Contrib. Sociais - Retidas', $descricoes[$tpRetPisCofins] ?? '-', 2],
            ]);
        }
    }

    private function tributacaoIbsCbs(): void
    {
        $this->divisoria();
        $i = '//n:infNFSe/n:IBSCBS';
        $d = '//n:infDPS/n:IBSCBS';
        $cst = $this->v($d . '/n:valores/n:trib/n:gIBSCBS/n:CST');
        $classTrib = $this->v($d . '/n:valores/n:trib/n:gIBSCBS/n:cClassTrib');
        $indOp = $this->v($d . '/n:cIndOp');
        $locIncid = $this->v($i . '/n:cLocalidadeIncid');
        $operacao = $indOp . $locIncid === '' ? '-' : implode(' / ', array_filter([$indOp, $locIncid, $this->v($i . '/n:xLocalidadeIncid'), self::ufDoIbge($locIncid)]));
        $percentual = fn(string $caminho) => $this->v($caminho) === '' ? '-' : self::numero($this->v($caminho)) . '%';

        $this->tituloDoBloco('TRIBUTAÇÃO IBS / CBS');
        $this->linhaDeCampos([null, ['CST / cClassTrib', $cst . $classTrib === '' ? '-' : $cst . ' / ' . $classTrib], ['Indicador de Operação / Código IBGE Incidência / Município Incidência / Sigla UF', $this->cortar($operacao, 56), 2]]);
        $this->linhaDeCampos([
            ['Exclusões e Reduções da Base de Cálculo', $this->moeda($this->v($i . '/n:valores/n:vCalcReeRepRes'))],
            ['Base de Cálculo Após Exclusões e Reduções', $this->moeda($this->v($i . '/n:valores/n:vBC'))],
            ['Red. Alíquota IBS / Red. Alíquota CBS', $percentual($i . '/n:valores/n:uf/n:pRedAliqUF') . ' / ' . $percentual($i . '/n:valores/n:mun/n:pRedAliqMun') . ' / ' . $percentual($i . '/n:valores/n:fed/n:pRedAliqCBS')],
            ['Alíquota - IBS UF / IBS Mun', $percentual($i . '/n:valores/n:uf/n:pIBSUF') . ' / ' . $percentual($i . '/n:valores/n:mun/n:pIBSMun')],
        ]);
        $this->linhaDeCampos([
            ['Alíq. Efetiva Municipal - IBS', $percentual($i . '/n:valores/n:mun/n:pAliqEfetMun')],
            ['Valor Apurado Municipal - IBS', $this->moeda($this->v($i . '/n:totCIBS/n:gIBS/n:gIBSMunTot/n:vIBSMun'))],
            ['Alíq. Efetiva Estadual - IBS', $percentual($i . '/n:valores/n:uf/n:pAliqEfetUF')],
            ['Valor Apurado Estadual - IBS', $this->moeda($this->v($i . '/n:totCIBS/n:gIBS/n:gIBSUFTot/n:vIBSUF'))],
        ]);
        $this->linhaDeCampos([
            ['Valor Total Apurado - IBS', $this->moeda($this->v($i . '/n:totCIBS/n:gIBS/n:vIBSTot'))],
            ['Alíquota - CBS', $percentual($i . '/n:valores/n:fed/n:pCBS')],
            ['Alíquota Efetiva - CBS', $percentual($i . '/n:valores/n:fed/n:pAliqEfetCBS')],
            ['Valor Total Apurado - CBS', $this->moeda($this->v($i . '/n:totCIBS/n:gCBS/n:vCBS'))],
        ]);
    }

    private function valorTotal(): void
    {
        $this->divisoria();
        $ibs = $this->v('//n:infNFSe/n:IBSCBS/n:totCIBS/n:gIBS/n:vIBSTot');
        $cbs = $this->v('//n:infNFSe/n:IBSCBS/n:totCIBS/n:gCBS/n:vCBS');
        $totalIbsCbs = $ibs . $cbs === '' ? '' : (string)((float)$ibs + (float)$cbs);
        $liquido = $this->v('//n:infNFSe/n:valores/n:vLiq');
        $liquidoMaisIbsCbs = $this->primeiro(['//n:infNFSe/n:IBSCBS/n:totCIBS/n:vTotNF']);
        if ($liquidoMaisIbsCbs === '-') {
            $liquidoMaisIbsCbs = (string)((float)$liquido + (float)$totalIbsCbs);
        }

        $inicio = $this->y;
        $this->pdf->setFillColor(self::CINZA);
        $this->pdf->rect(self::X[3], $inicio + self::LINHA, self::COLUNA, self::LINHA, 'F'); // valor liquido + IBS/CBS com fundo cinza
        $this->tituloDoBloco('VALOR TOTAL DA NFS-E');
        $this->linhaDeCampos([null, ['VALOR DA OPERAÇÃO / SERVIÇO', $this->moeda($this->v('//n:infDPS/n:valores/n:vServPrest/n:vServ')), 1, 1, 7], ['Desconto Incondicionado', $this->moeda($this->v('//n:infDPS/n:valores/n:vDescCondIncond/n:vDescIncond'))], ['Desconto Condicionado', $this->moeda($this->v('//n:infDPS/n:valores/n:vDescCondIncond/n:vDescCond'))]]);
        $this->linhaDeCampos([['Total das Retenções (ISSQN / Federais)', $this->moeda($this->v('//n:infNFSe/n:valores/n:vTotalRet'))], ['VALOR LÍQUIDO DA NFS-E', $this->moeda($liquido), 1, 1, 7], ['Total do IBS/CBS', $this->moeda($totalIbsCbs)], ['VALOR LÍQUIDO DA NFS-E + IBS/CBS', $this->moeda($liquidoMaisIbsCbs), 1, 3, 7]]);
    }

    private function informacoesComplementares(): void
    {
        $this->divisoria();
        $partes = [];
        $rotulos = [
            'Inf. Cont.: ' => '//n:infDPS/n:serv/n:infoCompl/n:xInfComp',
            'NFS-e Subst.: ' => '//n:infDPS/n:subst/n:chSubstda',
            'Doc. Ref.: ' => '//n:infDPS/n:serv/n:infoCompl/n:docRef',
            'Cod. Obra: ' => '//n:infDPS/n:serv/n:obra/n:cObra',
            'Insc. Imob.: ' => '//n:infDPS/n:IBSCBS/n:imovel/n:inscImobFisc',
            'Cod. Evt.: ' => '//n:infDPS/n:serv/n:atvEvento/n:idAtvEvt',
            'Doc. Tec.: ' => '//n:infDPS/n:serv/n:infoCompl/n:idDocTec',
            'Núm. Ped.: ' => '//n:infDPS/n:serv/n:infoCompl/n:gItemPed/n:xPed',
            'Item Ped.: ' => '//n:infDPS/n:serv/n:infoCompl/n:gItemPed/n:xItemPed',
            'Inf. A. T. Mun.: ' => '//n:infNFSe/n:xOutInf',
        ];
        foreach ($rotulos as $rotulo => $caminho) {
            if ($this->v($caminho) !== '') {
                $partes[] = $rotulo . $this->v($caminho);
            }
        }
        $texto = $this->cortar(implode(' | ', $partes), 1997);

        $this->tituloDoBloco('INFORMAÇÕES COMPLEMENTARES', self::LARGURA);
        $this->pdf->setFont('Arial', '', 7);
        $this->pdf->setXY(self::X[0], $this->y);
        if ($texto !== '') {
            $this->pdf->multicell(self::LARGURA, 3, $this->t($texto), 0, 'L');
            $this->pdf->setX(self::X[0]);
        }
        // Linha fixa e obrigatoria (NT nota 10).
        $this->pdf->multicell(self::LARGURA, 3, $this->t($this->totaisAproximados()), 0, 'L');
    }

    // =================================================================
    // Pecas de desenho
    // =================================================================

    /** Linha horizontal que separa os blocos. */
    private function divisoria(): void
    {
        $this->pdf->line(self::X[0], $this->y, self::X[0] + self::LARGURA, $this->y);
    }

    /** Titulo do bloco: 7 pt, negrito, caixa alta, fundo cinza, na coluna 1. */
    private function tituloDoBloco(string $titulo, float $largura = self::COLUNA): void
    {
        $this->pdf->setFillColor(self::CINZA);
        $this->pdf->rect(self::X[0], $this->y, $largura, self::LINHA, 'F');
        $this->pdf->setFont('Arial', 'B', 7);
        $this->pdf->setXY(self::X[0] + 0.8, $this->y + 0.6);
        $this->pdf->cell($largura - 1, 3, $this->t($titulo), 0, 0);
        if ($largura === self::LARGURA) {
            $this->y += 4;
        }
    }

    /** Bloco sem dados: so o titulo e uma frase (NT 2.3.1 e 2.3.2). */
    private function mensagemDoBloco(string $mensagem): void
    {
        $this->pdf->setFont('Arial', 'B', 7);
        $this->pdf->setXY(self::X[0] + 0.8, $this->y + 0.6);
        $this->pdf->cell(self::LARGURA, 3, $this->t($mensagem), 0, 0);
        $this->y += 4;
    }

    /**
     * Desenha uma linha da grade. Cada campo: [rotulo, valor, colunas, coluna inicial, tamanho do rotulo].
     * null = coluna vazia (ex.: onde fica o titulo do bloco).
     */
    private function linhaDeCampos(array $campos, int $tamanhoRotulo = 6): void
    {
        $coluna = 0;
        foreach ($campos as $campo) {
            if ($campo === null) {
                $coluna++;
                continue;
            }
            $colunas = $campo[2] ?? 1;
            $coluna = $campo[3] ?? $coluna;
            $tamanho = $campo[4] ?? $tamanhoRotulo;
            $largura = self::COLUNA * $colunas - 1;
            $x = self::X[$coluna] + 0.8;

            $this->pdf->setFont('Arial', 'B', $tamanho);
            $this->pdf->setXY($x, $this->y + 0.6);
            $this->pdf->cell($largura, 2.6, $this->t($tamanho === 6 ? $campo[0] : mb_strtoupper($campo[0])), 0, 0);
            $this->pdf->setFont('Arial', '', 7);
            $this->pdf->setXY($x, $this->y + 3.2);
            $this->pdf->cell($largura, 2.8, $this->t($this->traco((string)$campo[1])), 0, 0);
            $coluna += $colunas;
        }
        $this->y += self::LINHA;
    }

    /** QR Code desenhado com quadradinhos (vetorial, sem depender de GD). */
    private function qrCode(float $x, float $y, float $tamanho): void
    {
        $qr = (new Barcode())->getBarcodeObj('QRCODE,M', self::URL_CONSULTA . $this->chave(), -1, -1, 'black', [0, 0, 0, 0]);
        $grade = explode("\n", trim($qr->getGrid('0', '1')));
        $modulo = $tamanho / count($grade);
        $this->pdf->setFillColor(0);
        foreach ($grade as $linha => $bits) {
            foreach (str_split($bits) as $col => $bit) {
                if ($bit === '1') {
                    $this->pdf->rect($x + $col * $modulo, $y + $linha * $modulo, $modulo, $modulo, 'F');
                }
            }
        }
    }

    /**
     * "CANCELADA" / "SUBSTITUÍDA" na diagonal, Arial 60 pt, cinza (NT 2.5).
     *
     * E desenhada depois de tudo, no modo multiplicar (ver PdfComMarcaDagua):
     * o texto preto continua visivel por cima e nenhuma letra fica cortada.
     */
    private function marcaDagua(string $texto): void
    {
        $this->pdf->setFont('Arial', 'B', 60);
        $this->pdf->modoMultiplicar(true);
        $this->pdf->settextColor(166, 166, 166);
        $largura = $this->pdf->getStringWidth($this->t($texto));
        $this->pdf->rotate(45, 105, 148);
        $this->pdf->text(105 - $largura / 2, 148, $this->t($texto));
        $this->pdf->rotate(0);
        $this->pdf->settextColor(0);
        $this->pdf->modoMultiplicar(false);
    }

    // =================================================================
    // Leitura e formatacao dos dados
    // =================================================================

    /** Valor de um caminho XPath ('' se nao existir). */
    private function v(string $caminho): string
    {
        $no = $this->xp->query($caminho)->item(0);
        return $no === null ? '' : trim($no->nodeValue ?? '');
    }

    private function existe(string $caminho): bool
    {
        return $this->xp->query($caminho)->length > 0;
    }

    /** Primeiro caminho com valor, ou traco. */
    private function primeiro(array $caminhos): string
    {
        foreach ($caminhos as $caminho) {
            if ($this->v($caminho) !== '') {
                return $this->v($caminho);
            }
        }
        return '-';
    }

    private function chave(): string
    {
        return substr($this->v('//n:infNFSe/@Id'), 3); // tira o prefixo "NFS"
    }

    private function documento(string $base): string
    {
        $cnpj = $this->v($base . '/n:CNPJ');
        if ($cnpj !== '') {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpj) ?? $cnpj;
        }
        $cpf = $this->v($base . '/n:CPF');
        if ($cpf !== '') {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf) ?? $cpf;
        }
        return $this->traco($this->v($base . '/n:NIF'));
    }

    private function municipio(string $endNac, string $endExt): string
    {
        $codigo = $this->v($endNac . '/n:cMun');
        if ($codigo === '') {
            return $this->traco($this->v($endExt . '/n:xCidade'));
        }
        // O XML so traz o nome destes municipios; para os demais fica o codigo IBGE.
        $nomes = [
            $this->v('//n:infDPS/n:cLocEmi') => $this->v('//n:infNFSe/n:xLocEmi'),
            $this->v('//n:infDPS/n:serv/n:locPrest/n:cLocPrestacao') => $this->v('//n:infNFSe/n:xLocPrestacao'),
            $this->v('//n:infNFSe/n:cLocIncid') => $this->v('//n:infNFSe/n:xLocIncid'),
        ];
        $nome = ($nomes[$codigo] ?? '') !== '' ? $nomes[$codigo] : 'IBGE ' . $codigo;
        return $this->cortar($nome . ' / ' . self::ufDoIbge($codigo), 37);
    }

    private function ibgeCep(string $endNac, string $endExt): string
    {
        $codigo = $this->v($endNac . '/n:cMun');
        if ($codigo === '') {
            return $this->traco($this->v($endExt . '/n:cEndPost'));
        }
        $cep = $this->v($endNac . '/n:CEP');
        $cep = $cep !== '' ? (preg_replace('/(\d{2})(\d{3})(\d{3})/', '$1.$2-$3', $cep) ?? $cep) : '-';
        return $codigo . ' / ' . $cep;
    }

    private function endereco(string $base): string
    {
        $partes = [];
        foreach (['xLgr', 'nro', 'xCpl', 'xBairro'] as $campo) {
            if ($this->v($base . '/n:' . $campo) !== '') {
                $partes[] = $this->v($base . '/n:' . $campo);
            }
        }
        return $partes === [] ? '-' : implode(', ', $partes);
    }

    /** Lei 12.741: valores ou percentuais aproximados (NT nota 10). */
    private function totaisAproximados(): string
    {
        $t = '//n:infDPS/n:valores/n:trib/n:totTrib';
        $inicio = 'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: ';
        if ($this->existe($t . '/n:vTotTrib')) {
            return $inicio . sprintf(
                'Federais: %s; Estaduais: %s; Municipais: %s',
                $this->moeda($this->v($t . '/n:vTotTrib/n:vTotTribFed')),
                $this->moeda($this->v($t . '/n:vTotTrib/n:vTotTribEst')),
                $this->moeda($this->v($t . '/n:vTotTrib/n:vTotTribMun'))
            );
        }
        if ($this->existe($t . '/n:pTotTrib')) {
            $p = fn(string $campo) => self::numero($this->v($t . '/n:pTotTrib/n:' . $campo)) . '%';
            return $inicio . sprintf('Federais: %s; Estaduais: %s; Municipais: %s', $p('pTotTribFed'), $p('pTotTribEst'), $p('pTotTribMun'));
        }
        if ($this->v($t . '/n:pTotTribSN') !== '') {
            return $inicio . 'Simples Nacional: ' . self::numero($this->v($t . '/n:pTotTribSN')) . '%';
        }
        return $inicio . 'não informados';
    }

    private function moeda(string $valor): string
    {
        return ($valor === '' || $valor === '-') ? '-' : 'R$ ' . self::numero($valor);
    }

    private static function numero(string $valor): string
    {
        return number_format((float)$valor, 2, ',', '.');
    }

    private static function data(string $data): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $data, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : '-';
    }

    private static function dataHora(string $dataHora): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2}:\d{2})/', $dataHora, $m) ? "{$m[3]}/{$m[2]}/{$m[1]} {$m[4]}" : '-';
    }

    /** 141302 -> 14.13.02 */
    private static function codigoTributacao(string $codigo): string
    {
        return strlen($codigo) === 6 ? substr($codigo, 0, 2) . '.' . substr($codigo, 2, 2) . '.' . substr($codigo, 4, 2) : ($codigo ?: '-');
    }

    /** Sigla da UF pelos 2 primeiros digitos do codigo IBGE do municipio. */
    private static function ufDoIbge(string $codigo): string
    {
        $ufs = ['11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR', '15' => 'PA', '16' => 'AP', '17' => 'TO', '21' => 'MA', '22' => 'PI', '23' => 'CE', '24' => 'RN', '25' => 'PB', '26' => 'PE', '27' => 'AL', '28' => 'SE', '29' => 'BA', '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP', '41' => 'PR', '42' => 'SC', '43' => 'RS', '50' => 'MS', '51' => 'MT', '52' => 'GO', '53' => 'DF'];
        return $ufs[substr($codigo, 0, 2)] ?? '-';
    }

    private function traco(string $valor): string
    {
        return $valor === '' ? '-' : $valor;
    }

    /** Corta textos longos com reticencias, como pede a NT. */
    private function cortar(string $texto, int $maximo): string
    {
        return mb_strlen($texto) > $maximo ? mb_substr($texto, 0, $maximo) . '...' : $texto;
    }

    /** As fontes padrao do PDF usam ISO-8859-1. */
    private function t(string $texto): string
    {
        return (string)mb_convert_encoding($texto, 'ISO-8859-1', 'UTF-8');
    }
}
