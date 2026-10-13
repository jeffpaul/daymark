<?php

declare(strict_types=1);

namespace Daymark\Vendor\SpomkyLabs\Pki\ASN1\Type\Primitive;

use function chr;
use function ord;
use Daymark\Vendor\SpomkyLabs\Pki\ASN1\Component\Identifier;
use Daymark\Vendor\SpomkyLabs\Pki\ASN1\Component\Length;
use Daymark\Vendor\SpomkyLabs\Pki\ASN1\Element;
use Daymark\Vendor\SpomkyLabs\Pki\ASN1\Exception\DecodeException;
use Daymark\Vendor\SpomkyLabs\Pki\ASN1\Feature\ElementBase;
use Daymark\Vendor\SpomkyLabs\Pki\ASN1\Type\PrimitiveType;
use Daymark\Vendor\SpomkyLabs\Pki\ASN1\Type\UniversalClass;

/**
 * Implements *BOOLEAN* type.
 */
final class Boolean extends Element
{
    use UniversalClass;
    use PrimitiveType;

    private function __construct(
        private readonly bool $_bool
    ) {
        parent::__construct(self::TYPE_BOOLEAN);
    }

    public static function create(bool $_bool): self
    {
        return new self($_bool);
    }

    /**
     * Get the value.
     */
    public function value(): bool
    {
        return $this->_bool;
    }

    protected function encodedAsDER(): string
    {
        return $this->_bool ? chr(0xFF) : chr(0);
    }

    protected static function decodeFromDER(Identifier $identifier, string $data, int &$offset): ElementBase
    {
        $idx = $offset;
        Length::expectFromDER($data, $idx, 1);
        $byte = ord($data[$idx++]);
        if ($byte !== 0) {
            if ($byte !== 0xFF) {
                throw new DecodeException('DER encoded boolean true must have all bits set to 1.');
            }
        }
        $offset = $idx;
        return self::create($byte !== 0);
    }
}
