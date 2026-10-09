<?php
/**
 * Ajustes del captcha.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con los ajustes del captcha, comunes a todos los formularios del sitio.
 *
 * La clave secreta no está aquí: se guarda cifrada en el almacén de secretos.
 */
final class Captcha_Config {

	/**
	 * Nombre de la clave secreta en el almacén de secretos.
	 */
	public const SECRET = 'captcha_secret';

	/**
	 * Puntuación mínima por defecto para los proveedores que puntúan en vez de aprobar o suspender.
	 */
	public const DEFAULT_SCORE_THRESHOLD = 0.5;

	/**
	 * Constructor.
	 *
	 * @param string $provider        Identificador del proveedor elegido; cadena vacía si no se usa captcha.
	 * @param string $site_key        Clave pública del sitio en el servicio.
	 * @param float  $score_threshold Puntuación mínima, entre 0.1 y 0.9, para aceptar un envío.
	 */
	public function __construct(
		public readonly string $provider = '',
		public readonly string $site_key = '',
		public readonly float $score_threshold = self::DEFAULT_SCORE_THRESHOLD
	) {}

	/**
	 * Representación para guardar en la base de datos o enviar por REST.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'provider'        => $this->provider,
			'site_key'        => $this->site_key,
			'score_threshold' => $this->score_threshold,
		);
	}
}
