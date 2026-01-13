<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao;

use NFePHP\NFe\Make;

final class EmissorNfe
{
    private function o(array $a): \stdClass
    {
        return json_decode(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function gerarNfeMinimaHomologacao(array $cfg): string
    {
        $nfe = new Make();

        $nfe->taginfNFe($this->o([
            "versao" => "4.00"
        ]));


        $cUF = 29; // BA
        $mod = '55';
        $serie = 1;
        $nNF = 1;

        $nfe->tagide($this->o([
            "cUF" => $cUF,
            "cNF" => str_pad((string)random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            "natOp" => "VENDA",
            "mod" => $mod,
            "serie" => $serie,
            "nNF" => $nNF,
            "dhEmi" => date('c'),
            "tpNF" => 1,
            "idDest" => 1,
            "cMunFG" => 2927408,
            "tpImp" => 1,
            "tpEmis" => 1,
            "tpAmb" => (int)$cfg['tpAmb'],
            "finNFe" => 1,
            "indFinal" => 1,
            "indPres" => 1,
            "procEmi" => 0,
            "verProc" => "XandeNFe 1.0"
        ]));

        $nfe->tagemit($this->o([
            "CNPJ" => preg_replace('/\D+/', '', $cfg['cnpj']),
            "xNome" => $cfg['razao'],
            "xFant" => $cfg['razao'],
            "IE" => preg_replace('/\D+/', '', $cfg['ie']),
            "CRT" => 3
        ]));

        $nfe->tagenderEmit($this->o([
            "xLgr" => "RUA TESTE",
            "nro" => "123",
            "xBairro" => "CENTRO",
            "cMun" => 2927408,
            "xMun" => "SALVADOR",
            "UF" => "BA",
            "CEP" => "40000000",
            "cPais" => 1058,
            "xPais" => "BRASIL",
            "fone" => "7130000000"
        ]));

        $nfe->tagdest($this->o([
            "xNome" => "NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL",
            "indIEDest" => 9,
            "CPF" => "00000000000"
        ]));

        $nfe->tagenderDest($this->o([
            "xLgr" => "RUA HOMOLOG",
            "nro" => "0",
            "xBairro" => "CENTRO",
            "cMun" => 2927408,
            "xMun" => "SALVADOR",
            "UF" => "BA",
            "CEP" => "40000000",
            "cPais" => 1058,
            "xPais" => "BRASIL"
        ]));

        $nItem = 1;

        $nfe->tagprod($this->o([
            "nItem" => $nItem,
            "cProd" => "1",
            "cEAN" => "SEM GTIN",
            "xProd" => "PRODUTO TESTE HOMOLOGACAO",
            "NCM" => "61091000",
            "CFOP" => "5102",
            "uCom" => "UN",
            "qCom" => "1.0000",
            "vUnCom" => "10.00",
            "vProd" => "10.00",
            "cEANTrib" => "SEM GTIN",
            "uTrib" => "UN",
            "qTrib" => "1.0000",
            "vUnTrib" => "10.00",
            "indTot" => 1
        ]));

        $nfe->tagimposto($this->o([
            "nItem" => $nItem,
            "vTotTrib" => "0.00"
        ]));

        $nfe->tagICMS($this->o([
            "nItem" => $nItem,
            "orig" => 0,
            "CST" => "00",
            "modBC" => 3,
            "vBC" => "10.00",
            "pICMS" => "18.00",
            "vICMS" => "1.80"
        ]));

        $nfe->tagPIS($this->o([
            "nItem" => $nItem,
            "CST" => "07"
        ]));

        $nfe->tagCOFINS($this->o([
            "nItem" => $nItem,
            "CST" => "07"
        ]));

        $nfe->tagICMSTot($this->o([
            "vBC" => "10.00",
            "vICMS" => "1.80",
            "vICMSDeson" => "0.00",
            "vFCP" => "0.00",
            "vBCST" => "0.00",
            "vST" => "0.00",
            "vFCPST" => "0.00",
            "vFCPSTRet" => "0.00",
            "vProd" => "10.00",
            "vFrete" => "0.00",
            "vSeg" => "0.00",
            "vDesc" => "0.00",
            "vII" => "0.00",
            "vIPI" => "0.00",
            "vIPIDevol" => "0.00",
            "vPIS" => "0.00",
            "vCOFINS" => "0.00",
            "vOutro" => "0.00",
            "vNF" => "10.00",
            "vTotTrib" => "0.00"
        ]));

        $nfe->tagtransp($this->o(["modFrete" => 9]));
        $nfe->tagpag($this->o(["vTroco" => "0.00"]));
        $nfe->tagdetPag($this->o(["tPag" => "01", "vPag" => "10.00"]));
        $nfe->taginfAdic($this->o(["infCpl" => "EMITIDA EM HOMOLOGACAO - SEM VALOR FISCAL"]));

        $xml = $nfe->getXML();
        if ($xml === '' || $nfe->getErrors()) {
            throw new \RuntimeException("Erros ao montar XML: " . json_encode($nfe->getErrors(), JSON_UNESCAPED_UNICODE));
        }

        return $xml;
    }
}
