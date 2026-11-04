<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Nfe;

use NFePHP\NFe\Make;
use stdClass;

/**
 * Monta o XML da NF-e (modelo 55) a partir do JSON da API.
 *
 * ATENCAO - CODIGO HOMOLOGADO:
 *   O corpo de montar() e uma COPIA FIEL de public/emitir_json_pl010.php
 *   (script homologado na SEFAZ-BA). As unicas diferencas sao estruturais:
 *     - os dados vem do array $data (e nao de um arquivo);
 *     - erros viram NfeXmlException (e nao encerram o processo);
 *     - o XML e devolvido (e nao gravado em disco).
 *
 *   O teste "XML identico ao do script homologado" em tests/nfse/executar.php
 *   garante isso. Mudou alguma regra aqui? Mude no script tambem (ou o
 *   contrario) e rode os testes.
 *
 *   Quem chama (ProvedorNfe) ajusta antes os campos que a API controla:
 *   ide.nNF, ide.serie, ide.tpAmb e ide.dhEmi.
 */
final class NfeXmlBuilder
{
    /**
     * @param array $cfg precisa de: tpAmb, autxml_ba_cnpj
     * @return array{xml: string, chave: string}
     */
    public static function montar(array $data, array $cfg): array
    {
        $ide    = $data['ide']   ?? null;
        $emit   = $data['emit']  ?? null;
        $dest   = $data['dest']  ?? null;
        $itens  = $data['itens'] ?? [];
        $tot    = $data['totais']['ICMSTot'] ?? null;
        $transp = $data['transp'] ?? null;
        $pag    = $data['pag'] ?? null;
        $adic   = $data['infAdic'] ?? null;
        $autXml = $data['autXML'] ?? [];

        $rt = $data['reformaTributaria'] ?? null;
        $rtByItem = [];
        $rtTotais = null;

        if (is_array($rt)) {
            $rtItens = self::arr($rt['itens'] ?? []);
            foreach ($rtItens as $rti) {
                $rti = is_array($rti) ? $rti : [];
                $ni = (int)($rti['nItem'] ?? 0);
                if ($ni > 0) $rtByItem[$ni] = $rti;
            }
            $rtTotais = is_array($rt['totais'] ?? null) ? $rt['totais'] : null;
        }

        if (!$ide || !$emit || !$dest || !is_array($itens) || count($itens) === 0 || !$tot) {
            throw new NfeXmlException("JSON faltando blocos obrigatórios: ide/emit/dest/itens/totais.ICMSTot");
        }

        $schema = (string)($data['schema'] ?? 'PL_010_V1.30');
        $isPl010v130 = ($schema === 'PL_010_V1.30');

        $make = new Make($schema);

        /**
         * 1) infNFe
         */
        $inf = new stdClass();
        $inf->versao = "4.00";
        $inf->Id = "";
        $inf->pk_nItem = null;
        $make->taginfNFe($inf);

        /**
         * 2) ide
         */
        $o = new stdClass();
        $o->cUF = (int)$ide['cUF'];

        if (isset($ide['cNF']) && self::isNonEmpty($ide['cNF'])) {
            $o->cNF = (string)$ide['cNF'];
        } else {
            $o->cNF = str_pad((string)random_int(1, 99999999), 8, '0', STR_PAD_LEFT);
        }

        $o->natOp    = (string)$ide['natOp'];
        $o->mod      = (int)$ide['mod'];
        $o->serie    = (int)$ide['serie'];

        $o->nNF = (int)($ide['nNF'] ?? 0);
        if ($o->nNF <= 0) throw new NfeXmlException("JSON sem ide.nNF válido (precisa ser > 0)");

        $o->dhEmi    = (string)$ide['dhEmi'];
        $o->tpNF     = (int)$ide['tpNF'];
        $o->idDest   = (int)$ide['idDest'];
        $o->cMunFG   = (int)$ide['cMunFG'];
        $o->tpImp    = (int)$ide['tpImp'];
        $o->tpEmis   = (int)$ide['tpEmis'];
        $o->cDV      = null;
        $o->tpAmb    = (int)($ide['tpAmb'] ?? (int)($cfg['tpAmb'] ?? 2));
        $o->finNFe   = (int)$ide['finNFe'];
        $o->indFinal = (int)$ide['indFinal'];
        $o->indPres  = (int)$ide['indPres'];
        $o->procEmi  = (int)$ide['procEmi'];
        $o->verProc  = (string)$ide['verProc'];

        if ($isPl010v130) {
            if (self::isNonEmpty($ide['cMunFGIBS'] ?? null)) $o->cMunFGIBS = (int)$ide['cMunFGIBS'];
            if (self::isNonEmpty($ide['tpNFDebito'] ?? null)) $o->tpNFDebito = (string)$ide['tpNFDebito'];
            if (self::isNonEmpty($ide['tpNFCredito'] ?? null)) $o->tpNFCredito = (string)$ide['tpNFCredito'];
            if (self::isNonEmpty($ide['dPrevEntrega'] ?? null)) $o->dPrevEntrega = (string)$ide['dPrevEntrega'];
        }

        $make->tagide($o);

        /**
         * 3) emit + enderEmit
         */
        $e = new stdClass();
        $e->xNome = (string)$emit['xNome'];
        $e->xFant = (string)($emit['xFant'] ?? "");
        $e->IE    = (string)$emit['IE'];
        $e->IM    = (string)($emit['IM'] ?? "");
        $e->CNAE  = (string)($emit['CNAE'] ?? "");
        $e->CRT   = (int)$emit['CRT'];

        if (!empty($emit['CNPJ'])) $e->CNPJ = self::onlyDigits((string)$emit['CNPJ']);
        if (!empty($emit['CPF']))  $e->CPF  = self::onlyDigits((string)$emit['CPF']);

        $make->tagemit($e);

        $ee = (object)($emit['enderEmit'] ?? []);
        $eeo = new stdClass();
        $eeo->xLgr    = (string)($ee->xLgr ?? "");
        $eeo->nro     = (string)($ee->nro ?? "");
        $eeo->xCpl    = (string)($ee->xCpl ?? "");
        $eeo->xBairro = (string)($ee->xBairro ?? "");
        $eeo->cMun    = (int)($ee->cMun ?? 0);
        $eeo->xMun    = (string)($ee->xMun ?? "");
        $eeo->UF      = (string)($ee->UF ?? "");
        $eeo->CEP     = (string)($ee->CEP ?? "");
        $eeo->cPais   = (int)($ee->cPais ?? 1058);
        $eeo->xPais   = (string)($ee->xPais ?? "BRASIL");
        $eeo->fone    = (string)($ee->fone ?? "");
        $make->tagenderEmit($eeo);

        /**
         * autXML (BA exige)
         */
        $autXmlRows = is_array($autXml) ? $autXml : [];
        if (count($autXmlRows) === 0) {
            $autXmlRows[] = ['CNPJ' => (string)($cfg['autxml_ba_cnpj'] ?? '13937073000156')];
        }
        foreach ($autXmlRows as $row) {
            $row = (array)$row;
            $ax = new stdClass();
            if (!empty($row['CNPJ'])) $ax->CNPJ = self::onlyDigits((string)$row['CNPJ']);
            if (!empty($row['CPF']))  $ax->CPF  = self::onlyDigits((string)$row['CPF']);
            if (!empty($ax->CNPJ) || !empty($ax->CPF)) $make->tagautXML($ax);
        }

        /**
         * 4) dest + enderDest
         */
        $d = new stdClass();
        $d->xNome     = (string)$dest['xNome'];
        $d->indIEDest = (int)$dest['indIEDest'];
        $d->IE        = (string)($dest['IE'] ?? "");
        $d->email     = (string)($dest['email'] ?? "");

        if (!empty($dest['CNPJ'])) $d->CNPJ = self::onlyDigits((string)$dest['CNPJ']);
        if (!empty($dest['CPF']))  $d->CPF  = self::onlyDigits((string)$dest['CPF']);

        $make->tagdest($d);

        $ed = (object)($dest['enderDest'] ?? []);
        $edo = new stdClass();
        $edo->xLgr    = (string)($ed->xLgr ?? "");
        $edo->nro     = (string)($ed->nro ?? "");
        $edo->xCpl    = (string)($ed->xCpl ?? "");
        $edo->xBairro = (string)($ed->xBairro ?? "");
        $edo->cMun    = (int)($ed->cMun ?? 0);
        $edo->xMun    = (string)($ed->xMun ?? "");
        $edo->UF      = (string)($ed->UF ?? "");
        $edo->CEP     = (string)($ed->CEP ?? "");
        $edo->cPais   = (int)($ed->cPais ?? 1058);
        $edo->xPais   = (string)($ed->xPais ?? "BRASIL");
        $edo->fone    = (string)($ed->fone ?? "");
        $make->tagenderDest($edo);

        /**
         * 5) itens
         *
         * IMPORTANTES PARA 1076:
         * - Somar vBC do RTC com decimal seguro (string) e depois gravar no totalizador.
         * - NÃO usar float como fonte da verdade.
         */
        $temISNosItens = false;

        $somaVBCIBSCBS = '0.00';
        $somaVIBS      = '0.00';
        $somaVCBS      = '0.00';
        $somaVIS       = '0.00';

        foreach ($itens as $item) {
            $nItem = (int)($item['nItem'] ?? 0);
            if ($nItem <= 0) throw new NfeXmlException("Item sem nItem válido");

            $prod = (object)($item['prod'] ?? []);
            $p = new stdClass();
            $p->item     = $nItem;
            $p->cProd    = (string)($prod->cProd ?? "");
            $p->cEAN     = (string)($prod->cEAN ?? "SEM GTIN");
            $p->xProd    = (string)($prod->xProd ?? "");
            $p->NCM      = (string)($prod->NCM ?? "");
            $p->CFOP     = (string)($prod->CFOP ?? "");
            $p->uCom     = (string)($prod->uCom ?? "");
            $p->qCom     = (float)($prod->qCom ?? 0);
            $p->vUnCom   = (float)($prod->vUnCom ?? 0);
            $p->vProd    = (float)($prod->vProd ?? 0);
            $p->cEANTrib = (string)($prod->cEANTrib ?? "SEM GTIN");
            $p->uTrib    = (string)($prod->uTrib ?? $p->uCom);
            $p->qTrib    = (float)($prod->qTrib ?? $p->qCom);
            $p->vUnTrib  = (float)($prod->vUnTrib ?? $p->vUnCom);

            $vFrete = (float)($prod->vFrete ?? 0); if ($vFrete > 0) $p->vFrete = $vFrete;
            $vSeg   = (float)($prod->vSeg   ?? 0); if ($vSeg   > 0) $p->vSeg   = $vSeg;
            $vDesc  = (float)($prod->vDesc  ?? 0); if ($vDesc  > 0) $p->vDesc  = $vDesc;
            $vOutro = (float)($prod->vOutro ?? 0); if ($vOutro > 0) $p->vOutro = $vOutro;

            $p->indTot = (int)($prod->indTot ?? 1);

            if ($isPl010v130) {
                if (isset($prod->vItem) && $prod->vItem !== '' && $prod->vItem !== null) $p->vItem = (float)$prod->vItem;
                if (property_exists($prod, 'tpCredPresIBSZFM')) {
                    $v = $prod->tpCredPresIBSZFM;
                    $p->tpCredPresIBSZFM = ($v === null || (is_string($v) && trim($v) === '')) ? null : (int)$v;
                }
            }

            $make->tagprod($p);

            $imp = (object)($item['imposto'] ?? []);
            $imposto = new stdClass();
            $imposto->item = $nItem;
            $imposto->vTotTrib = (float)($imp->vTotTrib ?? 0);
            $make->tagimposto($imposto);

            if (!empty($item['imposto']['ICMS'])) {
                $ic = (object)$item['imposto']['ICMS'];
                $icms = new stdClass();
                $icms->item  = $nItem;
                $icms->orig  = (int)($ic->orig ?? 0);
                $icms->CST   = (string)($ic->CST ?? "00");
                $icms->modBC = (int)($ic->modBC ?? 3);
                $icms->vBC   = (float)($ic->vBC ?? 0);
                $icms->pICMS = (float)($ic->pICMS ?? 0);
                $icms->vICMS = (float)($ic->vICMS ?? 0);
                $make->tagICMS($icms);
            }

            if (!empty($item['imposto']['PIS'])) {
                $pi = (object)$item['imposto']['PIS'];
                $pis = new stdClass();
                $pis->item = $nItem;
                $pis->CST  = (string)($pi->CST ?? "01");
                $pis->vBC  = (float)($pi->vBC ?? 0);
                $pis->pPIS = (float)($pi->pPIS ?? 0);
                $pis->vPIS = (float)($pi->vPIS ?? 0);
                $make->tagPIS($pis);
            }

            if (!empty($item['imposto']['COFINS'])) {
                $co = (object)$item['imposto']['COFINS'];
                $cof = new stdClass();
                $cof->item    = $nItem;
                $cof->CST     = (string)($co->CST ?? "01");
                $cof->vBC     = (float)($co->vBC ?? 0);
                $cof->pCOFINS = (float)($co->pCOFINS ?? 0);
                $cof->vCOFINS = (float)($co->vCOFINS ?? 0);
                $make->tagCOFINS($cof);
            }

            /**
             * RTC por item: IBSCBS + IS (PL_010_V1.30)
             * -> Aqui é onde a 1076 nasce: o Make precisa receber os campos que vão para o XML.
             * -> Não passe objeto aninhado; "achate" os campos.
             */
            $rti = $rtByItem[$nItem] ?? null;
            if ($isPl010v130 && is_array($rti)) {

                // ===== IBSCBS =====
                if (!empty($rti['IBSCBS']) && method_exists($make, 'tagIBSCBS')) {
                    $ibArr = (array)$rti['IBSCBS'];

                    $CST        = (string)($ibArr['CST'] ?? '000');
                    $cClassTrib = (string)($ibArr['cClassTrib'] ?? '000000');

                    $g = (array)($ibArr['gIBSCBS'] ?? []);
                    $vBC = self::dec2($g['vBC'] ?? 0);

                    $gUF  = (array)($g['gIBSUF']  ?? []);
                    $gMun = (array)($g['gIBSMun'] ?? []);
                    $gCBS = (array)($g['gCBS']    ?? []);

                    $pIBSUF = self::dec2($gUF['pIBSUF'] ?? 0);
                    $vIBSUF = self::dec2($gUF['vIBSUF'] ?? 0);
                    $pIBSMun= self::dec2($gMun['pIBSMun'] ?? 0);
                    $vIBSMun= self::dec2($gMun['vIBSMun'] ?? 0);
                    $pCBS   = self::dec2($gCBS['pCBS'] ?? 0);
                    $vCBS   = self::dec2($gCBS['vCBS'] ?? 0);

                    // soma segura p/ totalizador
                    $somaVBCIBSCBS = self::add2($somaVBCIBSCBS, $vBC);

                    // (Se seu layout usa IBS por UF+Mun, some também)
                    $somaVIBS = self::add2($somaVIBS, self::add2($vIBSUF, $vIBSMun));
                    $somaVCBS = self::add2($somaVCBS, $vCBS);

                    $ib = new stdClass();
                    $ib->item = $nItem;
                    $ib->CST = $CST;
                    $ib->cClassTrib = $cClassTrib;

                    // base do gIBSCBS
                    $ib->vBC = $vBC;

                    // >>> AQUI É O PULO DO GATO: prefixos dos grupos <<<

                    // gIBSUF
                    $ib->gIBSUF_pIBSUF = $pIBSUF;
                    $ib->gIBSUF_vIBSUF = $vIBSUF;

                    // gIBSMun
                    $ib->gIBSMun_pIBSMun = $pIBSMun;
                    $ib->gIBSMun_vIBSMun = $vIBSMun;

                    // gCBS
                    $ib->gCBS_pCBS = $pCBS;
                    $ib->gCBS_vCBS = $vCBS;

                    $make->tagIBSCBS($ib);

                }

                // ===== IS =====
                if (!empty($rti['IS']) && method_exists($make, 'tagIS')) {
                    $isArr = (array)$rti['IS'];

                    $vIS = self::dec2($isArr['vIS'] ?? 0);
                    $somaVIS = self::add2($somaVIS, $vIS);

                    $is = new stdClass();
                    $is->item = $nItem;
                    $is->CSTIS = (string)($isArr['CSTIS'] ?? '000');
                    $is->cClassTribIS = (string)($isArr['cClassTribIS'] ?? '000000');
                    $is->vBCIS = self::dec2($isArr['vBCIS'] ?? 0);
                    $is->pIS   = self::dec2($isArr['pIS'] ?? 0);
                    $is->vIS   = $vIS;

                    $make->tagIS($is);

                    $temISNosItens = true;
                }
            }
        }

        /**
         * 6) totais ICMSTot
         */
        $t = (object)$tot;
        $tt = new stdClass();
        $tt->vBC       = (float)($t->vBC ?? 0);
        $tt->vICMS     = (float)($t->vICMS ?? 0);
        $tt->vICMSDeson= (float)($t->vICMSDeson ?? 0);
        $tt->vFCP      = (float)($t->vFCP ?? 0);
        $tt->vBCST     = (float)($t->vBCST ?? 0);
        $tt->vST       = (float)($t->vST ?? 0);
        $tt->vFCPST    = (float)($t->vFCPST ?? 0);
        $tt->vFCPSTRet = (float)($t->vFCPSTRet ?? 0);
        $tt->vProd     = (float)($t->vProd ?? 0);
        $tt->vFrete    = (float)($t->vFrete ?? 0);
        $tt->vSeg      = (float)($t->vSeg ?? 0);
        $tt->vDesc     = (float)($t->vDesc ?? 0);
        $tt->vII       = (float)($t->vII ?? 0);
        $tt->vIPI      = (float)($t->vIPI ?? 0);
        $tt->vIPIDevol = (float)($t->vIPIDevol ?? 0);
        $tt->vPIS      = (float)($t->vPIS ?? 0);
        $tt->vCOFINS   = (float)($t->vCOFINS ?? 0);
        $tt->vOutro    = (float)($t->vOutro ?? 0);
        $tt->vNF       = (float)($t->vNF ?? 0);
        $tt->vTotTrib  = (float)($t->vTotTrib ?? 0);
        $make->tagICMSTot($tt);

        /**
         * RTC Totais: IBSCBSTot
         * CORREÇÃO 1076:
         * -> vBCIBSCBS deve ser a soma EXATA dos itens (fonte da verdade).
         * -> Não confie no que veio no JSON para total (pode divergir por arredondamento).
         */
        if ($isPl010v130 && method_exists($make, 'tagIBSCBSTot')) {
            $rw = new stdClass();

            // Força total bater com soma dos itens
            $rw->vBCIBSCBS = $somaVBCIBSCBS;

            // Se você está informando IBS/CBS por item, total também precisa bater.
            $rw->vIBS = $somaVIBS;
            $rw->vCBS = $somaVCBS;

            if ($temISNosItens) {
                $rw->vIS = $somaVIS; // sempre presente se IS existiu em item
            }

            // vTotNF no RTC (alguns ambientes exigem)
            // Se vier do JSON e você quiser manter, ok. Se não, usa vNF do ICMSTot.
            $rtTot = is_array($rtTotais) ? ($rtTotais['IBSCBSTot'] ?? null) : null;
            if (is_array($rtTot) && array_key_exists('vTotNF', $rtTot)) {
                $rw->vTotNF = self::dec2($rtTot['vTotNF']);
            } else {
                $rw->vTotNF = self::dec2($t->vNF ?? 0);
            }

            $make->tagIBSCBSTot($rw);
        }

        /**
         * 7) transp
         */
        $tr = new stdClass();
        $tr->modFrete = (int)(($transp['modFrete'] ?? 9));
        $make->tagtransp($tr);

        /**
         * 8) pag
         */
        if (!empty($pag['detPag']) && is_array($pag['detPag'])) {
            $pago = new stdClass();
            $pago->vTroco = (float)($pag['vTroco'] ?? 0);
            $make->tagpag($pago);

            foreach ($pag['detPag'] as $dp) {
                $dp = (object)$dp;
                $det = new stdClass();
                $det->indPag = (int)($dp->indPag ?? 0);
                $det->tPag   = (string)($dp->tPag ?? "01");
                $det->vPag   = (float)($dp->vPag ?? 0);
                $det->tpIntegra = null;
                $make->tagdetPag($det);
            }
        }

        /**
         * 9) infAdic
         */
        if (!empty($adic)) {
            $ia = (object)$adic;
            $i = new stdClass();
            $i->infAdFisco = (string)($ia->infAdFisco ?? "");
            $i->infCpl     = (string)($ia->infCpl ?? "");
            $make->taginfAdic($i);
        }

        /**
         * Checa erros do Make
         */
        $errors = $make->getErrors();
        if (!empty($errors)) {
            throw new NfeXmlException("Make->getErrors(): " . json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $errors);
        }

        $xml = $make->getXML();
        if (!$xml) {
            throw new NfeXmlException("Não gerou XML. Verifique campos obrigatórios.");
        }

        $chaveGerada = $make->getChave();
        if (!$chaveGerada) {
            throw new NfeXmlException("Não consegui obter a chave da NF-e (getChave retornou vazio).");
        }

        return ['xml' => $xml, 'chave' => $chaveGerada];
    }

    // ---------------------------------------------------------------
    // Funcoes auxiliares (copiadas do script homologado)
    // ---------------------------------------------------------------

    private static function onlyDigits(string $s): string
    {
        return preg_replace('/\D+/', '', $s) ?? '';
    }

    private static function isNonEmpty($v): bool
    {
        if ($v === null) return false;
        if (is_string($v)) return trim($v) !== '';
        if (is_array($v)) return count($v) > 0;
        return true;
    }

    private static function num($v): float
    {
        return is_numeric($v) ? (float)$v : 0.0;
    }

    private static function arr($v): array
    {
        return is_array($v) ? $v : [];
    }

    /** Formata valor monetario/BC com 2 casas como string (para bc math e XML estavel). */
    private static function dec2($v): string
    {
        if ($v === null || $v === '') return '0.00';
        if (!is_numeric($v)) return '0.00';
        return number_format((float)$v, 2, '.', '');
    }

    /** Soma decimal segura (string) com 2 casas. */
    private static function add2(string $a, string $b): string
    {
        if (function_exists('bcadd')) return bcadd($a, $b, 2);
        $fa = (float)$a;
        $fb = (float)$b;
        return number_format($fa + $fb, 2, '.', '');
    }
}
