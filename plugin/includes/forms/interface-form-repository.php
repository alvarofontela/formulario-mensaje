<?php
/**
 * Contrato del almacén de formularios.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Forms;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lee y guarda formularios.
 */
interface Form_Repository {

	/**
	 * Busca un formulario por su identificador, en cualquier estado.
	 *
	 * @param int $id Identificador del formulario.
	 */
	public function find( int $id ): ?Form;

	/**
	 * Lista los formularios de un estado, del más reciente al más antiguo.
	 *
	 * @param string $status Form::STATUS_PUBLISHED o Form::STATUS_TRASHED.
	 * @return Form[]
	 */
	public function all( string $status ): array;

	/**
	 * Crea o actualiza un formulario.
	 *
	 * @param int             $id         Identificador, o 0 para crear uno nuevo.
	 * @param string          $title      Nombre del formulario.
	 * @param Form_Definition $definition Definición ya saneada.
	 * @return int|WP_Error Identificador del formulario guardado.
	 */
	public function save( int $id, string $title, Form_Definition $definition ): int|WP_Error;

	/**
	 * Envía un formulario a la papelera.
	 *
	 * @param int $id Identificador del formulario.
	 */
	public function trash( int $id ): bool;

	/**
	 * Recupera un formulario de la papelera.
	 *
	 * @param int $id Identificador del formulario.
	 */
	public function restore( int $id ): bool;

	/**
	 * Borra un formulario definitivamente.
	 *
	 * @param int $id Identificador del formulario.
	 */
	public function delete( int $id ): bool;
}
