<?php
/**
 * Contrato de acceso a los datos de la petición que aporta el servidor.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Variables del servidor de la petición actual (dirección remota, cabeceras).
 */
interface Server_Vars {

	/**
	 * Valor de una variable del servidor, saneado como texto de una línea.
	 *
	 * @param string $name Nombre de la variable, como `REMOTE_ADDR` o `HTTP_REFERER`.
	 * @return string Cadena vacía si no existe.
	 */
	public function get( string $name ): string;
}
