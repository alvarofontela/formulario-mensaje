<?php
/**
 * Estados de una entrega.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Resultado de ejecutar una acción sobre un envío.
 */
enum Delivery_Status: string {

	/**
	 * La acción se completó.
	 */
	case Success = 'success';

	/**
	 * La acción falló y está pendiente de reintentar o descartar.
	 */
	case Failed = 'failed';

	/**
	 * La acción falló y alguien decidió no reintentarla.
	 */
	case Dismissed = 'dismissed';
}
