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
	 * Signo de fórmula en cualquier sitio donde una hoja de cálculo puede empezar una celda: al
	 * principio, o detrás de un punto y coma, una barra vertical, un tabulador o un salto de línea,
	 * aunque entre medias haya comillas o espacios (que al partir la celda se descartan).
	 */
	private const TRIGGER_AT_CELL_START = '/(^|[;|\t\r\n])(["\' \t]*)(?=[=+\-@])/';

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
	 * No basta con mirar el principio: el fichero se separa por comas, pero una hoja de cálculo
	 * configurada con otro separador (el punto y coma, en los idiomas que usan la coma decimal) parte
	 * la celda por él, y entonces tampoco reconoce las comillas que la envuelven: un salto de línea
	 * dentro de la celda le parece una fila nueva. Lo que viene detrás pasa a ser el principio de otra
	 * celda. Por eso un texto de varias líneas escrito como lista («- uno») sale con su apóstrofo.
	 *
	 * @param string $cell Contenido de la celda.
	 * @return string El mismo contenido, con un apóstrofo delante de cada signo de fórmula que esté al
	 *                principio de la celda o de lo que otra hoja de cálculo tomaría por una celda.
	 */
	public function neutralize( string $cell ): string {
		if ( '' === $cell ) {
			return $cell;
		}

		$cell = (string) preg_replace( self::TRIGGER_AT_CELL_START, "$1$2'", $cell );

		// Un tabulador o un retorno de carro al principio también hacen que la celda se lea como fórmula.
		return "\t" === $cell[0] || "\r" === $cell[0] ? "'" . $cell : $cell;
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
