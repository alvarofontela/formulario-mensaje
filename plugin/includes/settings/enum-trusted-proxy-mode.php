<?php
/**
 * Modos de proxy de confianza.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * De dónde se toma la IP del visitante.
 */
enum Trusted_Proxy_Mode: string {

	/**
	 * Sin proxy: la IP es la de la conexión.
	 */
	case None = 'none';

	/**
	 * El sitio está detrás de Cloudflare.
	 */
	case Cloudflare = 'cloudflare';

	/**
	 * El sitio está detrás de un proxy propio, identificado por sus IP.
	 */
	case Custom = 'custom';
}
