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
	 * Longitud máxima del testigo del captcha: los de algunos servicios ocupan varios miles de caracteres.
	 */
	private const MAX_CAPTCHA_LENGTH = 8192;

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
	 * @param mixed              $captcha_token Testigo del captcha, sin validar.
	 * @param mixed              $files         Elemento de `$_FILES` con los ficheros del formulario, sin validar.
	 */
	public function create( int $form_id, mixed $values, mixed $honeypot, mixed $token, Submission_Channel $channel, mixed $captcha_token = '', mixed $files = array() ): Submission_Request {
		return new Submission_Request(
			$form_id,
			is_array( $values ) ? $values : array(),
			is_scalar( $honeypot ) ? mb_substr( (string) $honeypot, 0, self::MAX_LENGTH ) : '',
			is_scalar( $token ) ? mb_substr( (string) $token, 0, self::MAX_LENGTH ) : '',
			$channel,
			$this->ip_resolver->resolve(),
			$this->page_url(),
			mb_substr( $this->server->get( 'HTTP_USER_AGENT' ), 0, 255 ),
			is_scalar( $captcha_token ) ? mb_substr( (string) $captcha_token, 0, self::MAX_CAPTCHA_LENGTH ) : '',
			is_array( $files ) ? $files : array(),
			$this->is_truncated()
		);
	}

	/**
	 * Indica si la petición superaba el tamaño que acepta el servidor.
	 *
	 * En ese caso PHP no entrega ni los valores ni los ficheros: sin esta comprobación, el visitante
	 * vería un error del token antispam en vez de saber que sus ficheros pesan demasiado.
	 */
	private function is_truncated(): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );

		return $limit > 0 && (int) $this->server->get( 'CONTENT_LENGTH' ) > $limit;
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
