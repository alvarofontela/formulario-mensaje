<?php
/**
 * Contrato de los proveedores de servicios.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje;

defined( 'ABSPATH' ) || exit;

/**
 * Un proveedor por módulo: declara sus servicios en el contenedor y registra sus hooks.
 *
 * Los callbacks de los hooks piden el servicio al contenedor cuando el hook se dispara, no al registrarlo,
 * de modo que una petición que no usa el módulo no construye nada.
 */
interface Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void;
}
