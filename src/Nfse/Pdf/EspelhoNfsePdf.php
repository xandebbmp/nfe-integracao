<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Pdf;

use NFePHP\DA\Legacy\Pdf;
use RuntimeException;

/**
 * Gera um "espelho" simples da NFS-e em PDF.
 *
 * Usado quando o padrao NAO oferece PDF oficial por webservice (Salvador).
 * O documento deixa claro que e auxiliar: a NFS-e valida e a do portal da
 * prefeitura. Os dados vem do registro do documento (payload + retorno).
 *
 * Usa a classe de PDF que ja vem no sped-da (sem dependencia nova).
 */
final class EspelhoNfsePdf
{
    public function gerar(array $documento, array $cfg, ?string $urlVisualizacao = null): string
    {
        if (empty($documento['numero_nfse'])) {
            throw new RuntimeException('PDF disponivel apenas para NFS-e autorizada.');
        }

        $p = $documento['payload'];
        $emitente = $cfg['emitente'];
        $tomador = $p['tomador'] ?? [];
        $valores = $p['valores'] ?? [];

        $pdf = new Pdf('P', 'mm', 'A4');
        $pdf->setMargins(12, 12, 12);
        $pdf->addPage();

        $pdf->setFont('Arial', 'B', 13);
        $pdf->cell(0, 7, $this->t('NOTA FISCAL DE SERVIÇOS ELETRÔNICA - NFS-e'), 0, 1, 'C');
        $pdf->setFont('Arial', '', 8);
        $pdf->cell(0, 5, $this->t('Espelho gerado pela API - documento auxiliar. A NFS-e oficial está no portal da prefeitura.'), 0, 1, 'C');
        if ((int)$cfg['tpAmb'] === 2) {
            $pdf->setFont('Arial', 'B', 10);
            $pdf->cell(0, 6, $this->t('AMBIENTE DE HOMOLOGAÇÃO - SEM VALOR FISCAL'), 0, 1, 'C');
        }
        $pdf->ln(2);

        $this->secao($pdf, 'Identificação');
        $this->linha($pdf, 'Número da NFS-e', (string)$documento['numero_nfse']);
        $this->linha($pdf, 'Código de verificação', (string)($documento['codigo_verificacao'] ?? ''));
        $this->linha($pdf, 'Data de emissão', (string)($documento['data_autorizacao'] ?? ''));
        $this->linha($pdf, 'RPS', 'Série ' . $documento['serie'] . ' / Nº ' . $documento['numero']);
        $this->linha($pdf, 'Padrão', (string)$documento['padrao']);

        $this->secao($pdf, 'Prestador');
        $this->linha($pdf, 'Razão social', (string)($emitente['razao_social'] ?? ''));
        $this->linha($pdf, 'CNPJ', (string)$emitente['cnpj']);
        $this->linha($pdf, 'Inscrição municipal', (string)($emitente['inscricao_municipal'] ?? ''));

        $this->secao($pdf, 'Tomador');
        $this->linha($pdf, 'Nome / Razão social', (string)($tomador['nome'] ?? ''));
        $this->linha($pdf, 'CPF / CNPJ', (string)($tomador['cpf_cnpj'] ?? ''));
        $end = $tomador['endereco'] ?? [];
        if (!empty($end['logradouro'])) {
            $this->linha($pdf, 'Endereço', trim(sprintf(
                '%s, %s %s - %s - %s/%s - CEP %s',
                $end['logradouro'] ?? '',
                $end['numero'] ?? 'S/N',
                $end['complemento'] ?? '',
                $end['bairro'] ?? '',
                $end['codigo_municipio'] ?? '',
                $end['uf'] ?? '',
                $end['cep'] ?? ''
            )));
        }

        $this->secao($pdf, 'Discriminação do serviço');
        $pdf->setFont('Arial', '', 9);
        $pdf->multicell(0, 5, $this->t((string)($p['servico']['descricao'] ?? '')), 0, 'L');
        $this->linha($pdf, 'Item da lista de serviço', (string)($p['servico']['item_lista_servico'] ?? $p['servico']['codigo_tributacao_nacional'] ?? ''));

        $this->secao($pdf, 'Valores');
        $this->linha($pdf, 'Valor dos serviços', $this->moeda($valores['valor_servicos'] ?? 0));
        $this->linha($pdf, 'Alíquota ISS', number_format((float)($valores['aliquota_iss'] ?? 0), 2, ',', '.') . '%');
        $this->linha($pdf, 'ISS retido', !empty($valores['iss_retido']) ? 'Sim' : 'Não');

        if ($urlVisualizacao !== null) {
            $this->secao($pdf, 'Consulta da autenticidade');
            $pdf->setFont('Arial', '', 8);
            $pdf->multicell(0, 4, $this->t($urlVisualizacao), 0, 'L');
        }

        return $pdf->output('', 'S');
    }

    private function secao(Pdf $pdf, string $titulo): void
    {
        $pdf->ln(2);
        $pdf->setFont('Arial', 'B', 10);
        $pdf->cell(0, 6, $this->t(mb_strtoupper($titulo)), 'B', 1, 'L');
        $pdf->ln(1);
    }

    private function linha(Pdf $pdf, string $rotulo, string $valor): void
    {
        $pdf->setFont('Arial', 'B', 9);
        $pdf->cell(50, 5, $this->t($rotulo . ':'), 0, 0, 'L');
        $pdf->setFont('Arial', '', 9);
        $pdf->multicell(0, 5, $this->t($valor), 0, 'L');
    }

    private function moeda(mixed $valor): string
    {
        return 'R$ ' . number_format((float)$valor, 2, ',', '.');
    }

    /** As fontes padrao do FPDF usam ISO-8859-1. */
    private function t(string $texto): string
    {
        return (string)mb_convert_encoding($texto, 'ISO-8859-1', 'UTF-8');
    }
}
