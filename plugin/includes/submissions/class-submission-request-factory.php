<?php
/**
 * Construcción de peticiones de envío.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

use Formulario_Mensaje\Antispam\Client_Ip_Resolver;
use Formulario_Mensaje\Support\Server_Vars;

defined( 'ABSPATH' ) || exit;

/**
 * Convierte lo que recibe un adaptador (REST o POST normal) en una petición de envío, añadiendo los
 * datos de la conexión. Así los dos adaptadores entregan exactamente lo mismo al servicio.
 */
final class Submission_Request_Factory {

	/**
	 * Longitud máxima que se conserva del campo trampa y del token.
	 */
	private const MAX_LENGTH = 200;

	/**
	 * Constructor.
	 *
	 * @param Client_Ip_Resolver $ip_resolver IP del visitante.
	 * @param Server_Vars        $server      Variables del servidor.
	 */
	public function __construct(
		private readonly Client_Ip_Resolver $ip_resolver,
		private readonly Server_Vars $server
	) {}

	/**
	 * Crea la petición de envío.
	 *
	 * @param int                $form_id  Identificador del formulario.
	 * @param mixed              $values   Valores de los campos, sin barras de escape.
	 * @param mixed              $honeypot Contenido del campo trampa.
	 * @param mixed              $token    Token antispam.
	 * @param Submission_Channel $channel  Vía por la que ha llegado.
	 */
	public function create( int $form_id, mixed $values, mixed $honeypot, mixed $token, Submission_Channel $channel ): Submission_Request {
		return new Submission_Request(
			$form_id,
			is_array( $values ) ? $values : array(),
			is_scalar( $honeypot ) ? mb_substr( (string) $honeypot, 0, self::MAX_LENGTH ) : '',
			is_scalar( $token ) ? mb_substr( (string) $token, 0, self::MAX_LENGTH ) : '',
			$channel,
			$this->ip_resolver->resolve(),
			$this->page_url(),
			mb_substr( $this->server->get( 'HTTP_USER_AGENT' ), 0, 255 )
		);
	}

	/**
	 * Dirección de la página desde la que se envió el formulario.
	 *
	 * Sale de la cabecera `Referer`, que escribe el navegador: solo se acepta si apunta a este sitio.
	 */
	private function page_url(): string {
		$referer = esc_url_raw( $this->server->get( 'HTTP_REFERER' ), array( 'http', 'https' ) );

		return '' === $referer ? '' : wp_validate_redirect( $referer, '' );
	}
}
