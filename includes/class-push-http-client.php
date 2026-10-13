<?php
/**
 * A PSR-18 HTTP client for the bundled web-push-php library that sends
 * through WordPress's HTTP API and Daymark's outbound guard.
 *
 * Loaded only by Daymark_Push_Sender::load_library(), after the prefixed
 * library's autoloader, since it implements that library's interfaces.
 *
 * @package Daymark
 */

defined( 'ABSPATH' ) || exit;

use Daymark\Vendor\Psr\Http\Client\ClientInterface;
use Daymark\Vendor\Psr\Http\Message\RequestInterface;
use Daymark\Vendor\Psr\Http\Message\ResponseFactoryInterface;
use Daymark\Vendor\Psr\Http\Message\ResponseInterface;
use Daymark\Vendor\Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends push requests with wp_safe_remote_post() inside the outbound guard.
 */
class Daymark_Push_Http_Client implements ClientInterface {

	/**
	 * Builds PSR-7 responses.
	 *
	 * @var ResponseFactoryInterface&StreamFactoryInterface
	 */
	private $factory;

	/**
	 * Constructor.
	 *
	 * @param ResponseFactoryInterface&StreamFactoryInterface $factory PSR-17 factory.
	 */
	public function __construct( $factory ) {
		$this->factory = $factory;
	}

	/**
	 * Send one push request.
	 *
	 * @param RequestInterface $request Request built by web-push-php.
	 * @return ResponseInterface
	 * @throws Daymark_Push_Http_Exception When the address isn't a push service or the request fails.
	 */
	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$url = (string) $request->getUri();

		if ( ! Daymark_Push::is_allowed_endpoint( $url ) ) {
			throw new Daymark_Push_Http_Exception( 'Not a push service address.' );
		}

		$safe = Daymark_Subscription_Url_Guard::check( $url );

		if ( is_wp_error( $safe ) ) {
			throw new Daymark_Push_Http_Exception( esc_html( $safe->get_error_message() ) );
		}

		$headers = array();

		foreach ( $request->getHeaders() as $name => $values ) {
			// WordPress sets Host and Content-Length itself.
			if ( in_array( strtolower( (string) $name ), array( 'host', 'content-length' ), true ) ) {
				continue;
			}
			$headers[ (string) $name ] = implode( ', ', $values );
		}

		$response = Daymark_Outbound_Guard::post(
			$url,
			array(
				'headers'             => $headers,
				'body'                => (string) $request->getBody(),
				/**
				 * Filter the timeout for one push request, in seconds.
				 *
				 * @param int $timeout Default 10.
				 */
				'timeout'             => max( 1, (int) apply_filters( 'daymark_push_request_timeout', 10 ) ),
				'redirection'         => 0,
				'limit_response_size' => 65536,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Daymark_Push_Http_Exception( esc_html( $response->get_error_message() ) );
		}

		$psr = $this->factory->createResponse( (int) wp_remote_retrieve_response_code( $response ) );

		$response_headers = wp_remote_retrieve_headers( $response );

		if ( is_object( $response_headers ) && method_exists( $response_headers, 'getAll' ) ) {
			$response_headers = $response_headers->getAll();
		}

		foreach ( (array) $response_headers as $name => $value ) {
			if ( is_string( $name ) && '' !== $name ) {
				$psr = $psr->withHeader( $name, is_array( $value ) ? array_map( 'strval', $value ) : (string) $value );
			}
		}

		return $psr->withBody( $this->factory->createStream( (string) wp_remote_retrieve_body( $response ) ) );
	}
}
