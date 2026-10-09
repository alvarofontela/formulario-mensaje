<?php
/**
 * Envío guardado.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

use Formulario_Mensaje\Submissions\Submission;
use Formulario_Mensaje\Submissions\Submission_Entry;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con un envío tal como está en la base de datos.
 *
 * Guarda las etiquetas de los campos como eran al recibirlo, para seguir siendo legible aunque el
 * formulario cambie después.
 */
final class Stored_Submission {

	/**
	 * Constructor.
	 *
	 * @param int                $id             Identificador; 0 si todavía no se ha guardado.
	 * @param int                $form_id        Identificador del formulario.
	 * @param string             $reference      Número de referencia.
	 * @param int                $created_at     Marca de tiempo Unix en que se recibió.
	 * @param string             $page_url       Página desde la que se envió, o cadena vacía.
	 * @param string             $ip             IP guardada (completa o anonimizada), o cadena vacía.
	 * @param string             $user_agent     Navegador guardado, o cadena vacía.
	 * @param Submission_Entry[] $entries        Datos recibidos, en el orden del formulario.
	 * @param int                $failed_actions Entregas fallidas pendientes de este envío.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $form_id,
		public readonly string $reference,
		public readonly int $created_at,
		public readonly string $page_url,
		public readonly string $ip,
		public readonly string $user_agent,
		public readonly array $entries,
		public readonly int $failed_actions = 0
	) {}

	/**
	 * Reconstruye el envío para volver a ejecutar una acción sobre él.
	 */
	public function to_submission(): Submission {
		$entries = array();

		foreach ( $this->entries as $entry ) {
			$entries[ $entry->field_id ] = $entry;
		}

		return new Submission( $this->form_id, $this->reference, $this->created_at, $this->page_url, $entries, $this->ip, $this->user_agent );
	}

	/**
	 * Resumen de una línea con los primeros datos del envío, para los listados. Sin escapar.
	 */
	public function summary(): string {
		$parts = array();

		foreach ( $this->entries as $entry ) {
			if ( '' === $entry->formatted ) {
				continue;
			}

			$parts[] = (string) preg_replace( '/\s+/', ' ', $entry->formatted );

			if ( count( $parts ) >= 3 ) {
				break;
			}
		}

		$summary = implode( ' · ', $parts );

		return mb_strlen( $summary ) > 140 ? mb_substr( $summary, 0, 139 ) . '…' : $summary;
	}

	/**
	 * Texto sobre el que se busca: la referencia y todos los valores legibles.
	 */
	public function search_text(): string {
		$parts = array( $this->reference );

		foreach ( $this->entries as $entry ) {
			if ( '' !== $entry->formatted ) {
				$parts[] = $entry->formatted;
			}
		}

		return implode( "\n", $parts );
	}
}
