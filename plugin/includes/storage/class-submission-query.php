<?php
/**
 * Filtros de una consulta de envíos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con los filtros y la paginación de un listado de envíos.
 */
final class Submission_Query {

	/**
	 * Máximo de envíos por página.
	 */
	public const MAX_PER_PAGE = 500;

	/**
	 * Envíos por página.
	 *
	 * @var int
	 */
	public readonly int $per_page;

	/**
	 * Página, empezando en 1.
	 *
	 * @var int
	 */
	public readonly int $page;

	/**
	 * Constructor.
	 *
	 * @param int    $form_id     Formulario; 0 para todos.
	 * @param string $search      Texto a buscar en la referencia y en los valores; vacío para no buscar.
	 * @param bool   $only_failed Solo los envíos con alguna entrega fallida.
	 * @param string $from_gmt    Fecha mínima en GMT (formato MySQL); vacío para no filtrar.
	 * @param string $to_gmt      Fecha máxima en GMT (formato MySQL); vacío para no filtrar.
	 * @param int    $page        Página, empezando en 1.
	 * @param int    $per_page    Envíos por página.
	 * @param bool   $only_unread Solo los envíos que nadie ha leído todavía.
	 */
	public function __construct(
		public readonly int $form_id = 0,
		public readonly string $search = '',
		public readonly bool $only_failed = false,
		public readonly string $from_gmt = '',
		public readonly string $to_gmt = '',
		int $page = 1,
		int $per_page = 20,
		public readonly bool $only_unread = false
	) {
		$this->per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$this->page     = max( 1, $page );
	}

	/**
	 * Posición del primer envío de la página.
	 */
	public function offset(): int {
		return ( $this->page - 1 ) * $this->per_page;
	}
}
