<?php

declare(strict_types=1);

namespace Daymark\Vendor\Jose\Component\Encryption\Algorithm\KeyEncryption;

use Daymark\Vendor\Jose\Component\Core\JWK;
use Daymark\Vendor\Jose\Component\Encryption\Algorithm\KeyEncryptionAlgorithm;

interface DirectEncryption extends KeyEncryptionAlgorithm
{
    /**
     * Returns the CEK.
     *
     * @param JWK $key The key used to get the CEK
     */
    public function getCEK(JWK $key): string;
}
