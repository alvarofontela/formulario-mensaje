<?php
/**
 * Contrato de un proveedor de captcha.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Captcha;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Servicio externo que distingue a una persona de un programa.
 *
 * En el navegador, cada proveedor tiene su adaptador en `assets/js/captcha.js`, con el mismo
 * identificador, que consigue el testigo; aquí se comprueba ese testigo contra el servicio.
 */
interface Captcha_Provider {

	/**
	 * Identificador del proveedor. Coincide con el de su adaptador en el navegador.
	 */
	public function get_id(): string;

	/**
	 * Nombre del proveedor, ya traducido.
	 */
	public function get_label(): string;

	/**
	 * Comprueba el testigo que ha enviado el navegador.
	 *
	 * @param string         $token     Testigo recibido con el envío.
	 * @param string         $secret    Clave secreta del sitio en el servicio.
	 * @param string         $remote_ip IP del visitante, o cadena vacía si no se conoce.
	 * @param Captcha_Config $config    Ajustes del captcha.
	 * @return true|WP_Error True si el servicio da el testigo por bueno. El error distingue un testigo
	 *                       rechazado de un servicio que no ha respondido.
	 */
	public function verify( string $token, string $secret, string $remote_ip, Captcha_Config $config ): bool|WP_Error;
}
