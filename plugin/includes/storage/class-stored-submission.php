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
	 * @param int                $read_at        Marca de tiempo Unix en que se leyó; 0 si nadie lo ha abierto.
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
		public readonly int $failed_actions = 0,
		public readonly int $read_at = 0
	) {}

	/**
	 * Indica si alguien ha abierto ya el envío en la bandeja de mensajes.
	 */
	public function is_read(): bool {
		return $this->read_at > 0;
	}

	/**
	 * Quién escribe, para encabezar el mensaje: el primer dato de un campo de texto, que casi
	 * siempre es el nombre. Sin escapar; cadena vacía si el formulario no tiene ninguno.
	 */
	public function sender(): string {
		return $this->shorten( $this->first_of( 'text' ), 80 );
	}

	/**
	 * Dirección de correo de quien escribe, para poder responderle; cadena vacía si no dejó una válida.
	 */
	public function email(): string {
		$email = $this->first_of( 'email' );

		return false === is_email( $email ) ? '' : $email;
	}

	/**
	 * Teléfono de quien escribe, tal como lo dejó; cadena vacía si no hay.
	 */
	public function phone(): string {
		return $this->shorten( $this->first_of( 'tel' ), 40 );
	}

	/**
	 * Campos cuyos datos encabezan el mensaje (quién escribe, su correo y su teléfono), para que
	 * quien lo presenta no los repita entre el resto.
	 *
	 * @return string[] Identificadores de campo.
	 */
	public function header_field_ids(): array {
		$ids = array();

		foreach ( array( 'text', 'email', 'tel' ) as $type ) {
			$entry = $this->first_entry_of( $type );

			// Un correo que no vale no sube a la cabecera: se queda con los demás datos, donde se puede leer.
			if ( null !== $entry && ( 'email' !== $type || '' !== $this->email() ) ) {
				$ids[] = $entry->field_id;
			}
		}

		return $ids;
	}

	/**
	 * Comienzo del mensaje, para los listados: el primer texto largo y, si no hay, el resumen. Sin escapar.
	 */
	public function excerpt(): string {
		$text = $this->first_of( 'textarea' );

		return '' === $text ? $this->summary() : $this->shorten( $text, 200 );
	}

	/**
	 * Indica si el envío trae algún fichero adjunto.
	 */
	public function has_files(): bool {
		return '' !== $this->first_of( 'file' );
	}

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
	 * Primer dato no vacío de un tipo de campo, en una sola línea.
	 *
	 * Solo sirve para presentar el envío (quién escribe, cómo empieza): mira los tipos de campo
	 * del propio plugin. Un tipo registrado por otro plugin no se pierde, sale con el resto de datos.
	 *
	 * @param string $type Identificador del tipo de campo.
	 */
	private function first_of( string $type ): string {
		$entry = $this->first_entry_of( $type );

		return null === $entry ? '' : trim( (string) preg_replace( '/\s+/', ' ', $entry->formatted ) );
	}

	/**
	 * Primer dato no vacío de un tipo de campo.
	 *
	 * @param string $type Identificador del tipo de campo.
	 */
	private function first_entry_of( string $type ): ?Submission_Entry {
		foreach ( $this->entries as $entry ) {
			if ( $type === $entry->type && '' !== $entry->formatted ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Recorta un texto a un máximo de caracteres, con puntos suspensivos si sobra.
	 *
	 * @param string $text  Texto.
	 * @param int    $limit Máximo de caracteres.
	 */
	private function shorten( string $text, int $limit ): string {
		return mb_strlen( $text ) > $limit ? mb_substr( $text, 0, $limit - 1 ) . '…' : $text;
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
