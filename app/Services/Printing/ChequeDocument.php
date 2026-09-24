<?php

namespace App\Services\Printing;

use TCPDF;

/**
 * A cheque is a negotiable instrument: nothing prints on it that we didn't put
 * there. TCPDF stamps a "Powered by TCPDF" line and link into the foot of every
 * document unless `$tcpdflink` is off, and that property is protected with no
 * public setter — and re-set to true by TCPDF's own constructor, so a subclass
 * property default won't hold either.
 */
class ChequeDocument extends TCPDF
{
    public function suppressProducerLink(): void
    {
        $this->tcpdflink = false;
    }
}
