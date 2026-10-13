<?php

declare(strict_types=1);

namespace Daymark\Vendor\Jose\Component\KeyManagement\Analyzer;

use Daymark\Vendor\Jose\Component\Core\JWK;
use Override;

final readonly class AlgorithmAnalyzer implements KeyAnalyzer
{
    #[Override]
    public function analyze(JWK $jwk, MessageBag $bag): void
    {
        if (! $jwk->has('alg')) {
            $bag->add(Message::medium('The parameter "alg" should be added.'));
        }
    }
}
