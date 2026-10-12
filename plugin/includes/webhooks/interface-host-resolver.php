<?php
/**
 * Contrato de la resolución de nombres de servidor.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Averigua a qué dirección apunta un nombre de servidor.
 *
 * Está detrás de una interfaz para que la decisión de si un destino es seguro se pueda probar sin
 * depender de la red ni de lo que responda un DNS de verdad.
 */
interface Host_Resolver {

	/**
	 * Dirección IPv4 de un nombre de servidor.
	 *
	 * @param string $host Nombre de servidor.
	 * @return string Dirección IPv4, o cadena vacía si el nombre no se puede resolver.
	 */
	public function resolve( string $host ): string;
}
