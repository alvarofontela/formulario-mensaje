<?php
/**
 * Exportación de envíos a CSV.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Convierte los envíos de un formulario en filas de una hoja de cálculo.
 *
 * Los datos los escriben visitantes anónimos: una celda que empiece por un signo de fórmula se
 * ejecutaría al abrir el fichero, así que se neutraliza.
 */
final class Csv_Exporter {

	/**
	 * Envíos que se leen en cada consulta.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Caracteres con los que una hoja de cálculo interpreta una celda como fórmula.
	 */
	private const FORMULA_TRIGGERS = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Constructor.
	 *
	 * @param Submission_Repository $submissions Almacén de envíos.
	 */
	public function __construct( private readonly Submission_Repository $submissions ) {}

	/**
	 * Filas de la exportación: la primera es la cabecera.
	 *
	 * Las columnas son la unión de los campos de todos los envíos exportados, porque el formulario
	 * puede haber cambiado; cada una lleva la etiqueta más reciente del campo.
	 *
	 * @param int    $form_id  Identificador del formulario.
	 * @param string $from_gmt Fecha mínima en GMT (formato MySQL); vacío para no filtrar.
	 * @param string $to_gmt   Fecha máxima en GMT (formato MySQL); vacío para no filtrar.
	 * @return iterable<int, string[]>
	 */
	public function rows( int $form_id, string $from_gmt = '', string $to_gmt = '' ): iterable {
		$columns   = array();
		$has_ip    = false;
		$has_agent = false;

		// Primera pasada: qué columnas hay. Los envíos llegan del más reciente al más antiguo.
		foreach ( $this->pages( $form_id, $from_gmt, $to_gmt ) as $submission ) {
			$has_ip    = $has_ip || '' !== $submission->ip;
			$has_agent = $has_agent || '' !== $submission->user_agent;

			foreach ( $submission->entries as $entry ) {
				$columns[ $entry->field_id ] ??= '' !== $entry->label ? $entry->label : $entry->field_id;
			}
		}

		$header = array( __( 'Reference', 'formulario-mensaje' ), __( 'Date', 'formulario-mensaje' ), __( 'Page', 'formulario-mensaje' ) );

		if ( $has_ip ) {
			$header[] = __( 'IP address', 'formulario-mensaje' );
		}

		if ( $has_agent ) {
			$header[] = __( 'Browser', 'formulario-mensaje' );
		}

		yield array_map( array( $this, 'neutralize' ), array( ...$header, ...array_values( $columns ) ) );

		foreach ( $this->pages( $form_id, $from_gmt, $to_gmt ) as $submission ) {
			$row = array(
				$submission->reference,
				(string) wp_date( 'Y-m-d H:i:s', $submission->created_at ),
				$submission->page_url,
			);

			if ( $has_ip ) {
				$row[] = $submission->ip;
			}

			if ( $has_agent ) {
				$row[] = $submission->user_agent;
			}

			$values = array();

			foreach ( $submission->entries as $entry ) {
				$values[ $entry->field_id ] = $entry->formatted;
			}

			foreach ( array_keys( $columns ) as $field_id ) {
				$row[] = $values[ $field_id ] ?? '';
			}

			yield array_map( array( $this, 'neutralize' ), $row );
		}
	}

	/**
	 * Neutraliza una celda que una hoja de cálculo interpretaría como fórmula.
	 *
	 * @param string $cell Contenido de la celda.
	 * @return string El mismo contenido, precedido de un apóstrofo si empezaba por un signo de fórmula.
	 */
	public function neutralize( string $cell ): string {
		return '' !== $cell && in_array( $cell[0], self::FORMULA_TRIGGERS, true ) ? "'" . $cell : $cell;
	}

	/**
	 * Recorre todos los envíos que cumplen los filtros, leyéndolos por páginas.
	 *
	 * @param int    $form_id  Identificador del formulario.
	 * @param string $from_gmt Fecha mínima en GMT.
	 * @param string $to_gmt   Fecha máxima en GMT.
	 * @return iterable<Stored_Submission>
	 */
	private function pages( int $form_id, string $from_gmt, string $to_gmt ): iterable {
		$page = 1;

		do {
			$submissions = $this->submissions->search( new Submission_Query( $form_id, '', false, $from_gmt, $to_gmt, $page, self::PAGE_SIZE ) );

			yield from $submissions;

			++$page;
			$fetched = count( $submissions );
		} while ( self::PAGE_SIZE === $fetched );
	}
}
