<?php
/**
 * Números de referencia de los envíos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

defined( 'ABSPATH' ) || exit;

/**
 * Genera el número de referencia que identifica un envío ante el visitante y en las notificaciones.
 */
final class Reference_Generator {

	/**
	 * Formato de una referencia válida.
	 */
	public const PATTERN = '/^[A-Z2-9]{4}-[A-Z2-9]{4}$/';

	/**
	 * Caracteres usados: sin 0, O, 1 ni I, que se confunden al dictarlos o copiarlos.
	 */
	private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	/**
	 * Genera una referencia aleatoria con el formato `XXXX-XXXX`.
	 */
	public function generate(): string {
		$reference = '';
		$max       = strlen( self::ALPHABET ) - 1;

		for ( $i = 0; $i < 8; $i++ ) {
			$reference .= self::ALPHABET[ random_int( 0, $max ) ];
		}

		return substr( $reference, 0, 4 ) . '-' . substr( $reference, 4 );
	}
}
