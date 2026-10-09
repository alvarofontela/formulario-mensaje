<?php
/**
 * Ficheros dentro del flujo de un envío.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Uploads;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission_Entry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Guarda los ficheros de un envío ya validado y deja en sus datos lo que hay que saber de ellos.
 *
 * El dato de un campo de ficheros es la lista de sus nombres, y su texto legible añade el tamaño.
 * Los enlaces de descarga firmados no se guardan con el envío: se añaden solo a la copia que
 * reciben las acciones, que es la que acaba en las notificaciones.
 */
final class Submission_Uploads {

	/**
	 * Constructor.
	 *
	 * @param Upload_Collector $collector Recogida de los ficheros de una petición.
	 * @param Upload_Store     $store     Ficheros de los envíos.
	 * @param Download_Links   $links     Enlaces de descarga.
	 */
	public function __construct(
		private readonly Upload_Collector $collector,
		private readonly Upload_Store $store,
		private readonly Download_Links $links
	) {}

	/**
	 * Ficheros recibidos con una petición, por campo de subida del formulario.
	 *
	 * @param Form  $form Formulario.
	 * @param mixed $raw  Ficheros tal como los entrega PHP, sin validar.
	 * @return array<string, Uploaded_File[]>
	 */
	public function collect( Form $form, mixed $raw ): array {
		return $this->collector->collect( $form, $raw );
	}

	/**
	 * Guarda los ficheros de los campos que forman parte del envío y actualiza sus datos.
	 *
	 * @param Form                            $form      Formulario.
	 * @param string                          $reference Número de referencia del envío.
	 * @param array<string, Submission_Entry> $entries   Datos del envío ya validados.
	 * @param array<string, Uploaded_File[]>  $uploads   Ficheros recibidos, por identificador de campo.
	 * @return array<string, Submission_Entry>|WP_Error Datos del envío con el nombre y el tamaño de sus ficheros.
	 */
	public function attach( Form $form, string $reference, array $entries, array $uploads ): array|WP_Error {
		// Solo los de los campos que le corresponden al envío: los de un campo oculto por su condición se descartan.
		$uploads = array_intersect_key( $uploads, $entries );

		if ( array() === $uploads ) {
			return $entries;
		}

		$stored = $this->store->persist( $form, $reference, $uploads );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return $this->describe( $entries, $stored, array() );
	}

	/**
	 * Datos del envío para sus acciones: como los guardados, más el enlace de descarga firmado de
	 * los ficheros cuyo campo lo pide.
	 *
	 * @param Form                            $form          Formulario.
	 * @param string                          $reference     Número de referencia del envío.
	 * @param int                             $submission_id Identificador del envío guardado, o 0 si no se guarda.
	 * @param array<string, Submission_Entry> $entries       Datos del envío.
	 * @return array<string, Submission_Entry>
	 */
	public function with_links( Form $form, string $reference, int $submission_id, array $entries ): array {
		$days = array();

		foreach ( $form->definition->fields as $field ) {
			// El enlace solo tiene sentido si el fichero se conserva: el del campo que lo guarda, en un envío que se guarda.
			if ( $submission_id > 0 && $field->flag( 'keep' ) && $field->flag( 'signed_link' ) && isset( $entries[ $field->id ] ) ) {
				$days[ $field->id ] = max( 1, (int) $field->text( 'link_days', '7' ) );
			}
		}

		if ( array() === $days ) {
			return $entries;
		}

		$stored = array();

		foreach ( $this->store->for_reference( $form, $reference ) as $file ) {
			$stored[ $file->field_id ][] = $file;
		}

		return $this->describe( $entries, array_intersect_key( $stored, $days ), $days );
	}

	/**
	 * Asocia los ficheros con el envío ya guardado.
	 *
	 * @param Form   $form          Formulario.
	 * @param string $reference     Número de referencia del envío.
	 * @param int    $submission_id Identificador del envío guardado, o 0 si no se guarda.
	 */
	public function link( Form $form, string $reference, int $submission_id ): void {
		$this->store->link( $form, $reference, $submission_id );
	}

	/**
	 * Borra, terminado el envío, los ficheros que solo hacían falta para sus acciones.
	 *
	 * @param Form   $form          Formulario.
	 * @param string $reference     Número de referencia del envío.
	 * @param int    $submission_id Identificador del envío guardado, o 0 si no se guarda.
	 */
	public function finish( Form $form, string $reference, int $submission_id ): void {
		$this->store->cleanup( $form, $reference, $submission_id );
	}

	/**
	 * Borra los ficheros de un envío que al final no se ha aceptado.
	 *
	 * @param Form   $form      Formulario.
	 * @param string $reference Número de referencia del envío.
	 */
	public function discard( Form $form, string $reference ): void {
		$this->store->discard( $form, $reference );
	}

	/**
	 * Sustituye el texto legible de los campos de ficheros por la lista de sus ficheros.
	 *
	 * @param array<string, Submission_Entry> $entries Datos del envío.
	 * @param array<string, Stored_File[]>    $stored  Ficheros guardados, por campo.
	 * @param array<string, int>              $days    Días de validez del enlace firmado, para los campos que lo llevan.
	 * @return array<string, Submission_Entry>
	 */
	private function describe( array $entries, array $stored, array $days ): array {
		foreach ( $stored as $field_id => $files ) {
			if ( ! isset( $entries[ $field_id ] ) ) {
				continue;
			}

			$lines = array();

			foreach ( $files as $file ) {
				$line    = sprintf( '%s (%s)', $file->name, (string) size_format( $file->size, 1 ) );
				$lines[] = isset( $days[ $field_id ] ) ? $line . ' ' . $this->links->signed( $file, $days[ $field_id ] ) : $line;
			}

			$entry                = $entries[ $field_id ];
			$entries[ $field_id ] = new Submission_Entry( $entry->field_id, $entry->type, $entry->label, $entry->value, implode( "\n", $lines ) );
		}

		return $entries;
	}
}
