<?php

declare(strict_types=1);

namespace Daymark\Vendor\Jose\Component\Signature\Algorithm;

use Daymark\Vendor\Jose\Component\Core\Algorithm;
use Daymark\Vendor\Jose\Component\Core\JWK;

interface SignatureAlgorithm extends Algorithm
{
    /**
     * Sign the input.
     *
     * @param JWK $key The private key used to sign the data
     * @param string $input The input
     */
    public function sign(JWK $key, string $input): string;

    /**
     * Verify the signature of data.
     *
     * @param JWK $key The private key used to sign the data
     * @param string $input The input
     * @param string $signature The signature to verify
     */
    public function verify(JWK $key, string $input, string $signature): bool;
}
