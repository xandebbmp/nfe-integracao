<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Pdf;

use NFePHP\DA\Legacy\Pdf;

/**
 * O mesmo Pdf do nfephp, com um unico recurso a mais: o modo "multiplicar".
 *
 * Para que serve: a marca d'agua "CANCELADA" e desenhada no FINAL, por cima
 * de tudo. Com o modo multiplicar, o cinza da marca se mistura com o que
 * esta embaixo, como uma caneta marca-texto:
 *  - texto preto continua preto (fica legivel por cima da marca);
 *  - fundo branco fica cinza (a marca aparece);
 *  - as faixas cinza dos titulos NAO cortam mais as letras da marca.
 *
 * Uso: modoMultiplicar(true) antes de desenhar, modoMultiplicar(false) depois.
 */
class PdfComMarcaDagua extends Pdf
{
    /** Numero do objeto PDF que guarda o modo multiplicar (0 = ainda nao criado). */
    private int $objetoMultiplicar = 0;
    private bool $usouMultiplicar = false;

    public function modoMultiplicar(bool $ligado): void
    {
        $this->usouMultiplicar = true;
        $this->pdfVersion = '1.4'; // modo multiplicar existe a partir do PDF 1.4
        // /GSm = multiplicar, /GSn = normal (os dois sao criados em putResources).
        $this->out($ligado ? '/GSm gs' : '/GSn gs');
    }

    protected function putResources()
    {
        if ($this->usouMultiplicar) {
            $this->newObj();
            $this->objetoMultiplicar = $this->n;
            $this->out('<</Type /ExtGState /BM /Multiply>>');
            $this->out('endobj');
            $this->newObj();
            $this->out('<</Type /ExtGState /BM /Normal>>');
            $this->out('endobj');
        }
        parent::putResources();
    }

    protected function putResourceDict()
    {
        parent::putResourceDict();
        if ($this->objetoMultiplicar > 0) {
            $normal = $this->objetoMultiplicar + 1;
            $this->out('/ExtGState << /GSm ' . $this->objetoMultiplicar . ' 0 R /GSn ' . $normal . ' 0 R >>');
        }
    }
}
