<?php

declare(strict_types=1);

namespace Daymark\Vendor\Jose\Component\Signature\Algorithm;

use Daymark\Vendor\Jose\Component\Core\Algorithm;
use Daymark\Vendor\Jose\Component\Core\JWK;

interface MacAlgorithm extends Algorithm
{
    /**
     * Sign the input.
     *
     * @param JWK $key The private key used to hash the data
     * @param string $input The input
     */
    public function hash(JWK $key, string $input): string;

    /**
     * Verify the signature of data.
     *
     * @param JWK $key The private key used to hash the data
     * @param string $input The input
     * @param string $signature The signature to verify
     */
    public function verify(JWK $key, string $input, string $signature): bool;
}
