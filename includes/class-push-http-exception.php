<?php
/**
 * The exception Daymark_Push_Http_Client throws for a failed push request.
 *
 * Loaded only by Daymark_Push_Sender::load_library(), after the prefixed
 * library's autoloader, since it implements that library's interface.
 *
 * @package Daymark
 */

defined( 'ABSPATH' ) || exit;

/**
 * A failed push request (network error or refused address).
 */
class Daymark_Push_Http_Exception extends \RuntimeException implements \Daymark\Vendor\Psr\Http\Client\ClientExceptionInterface {
}
