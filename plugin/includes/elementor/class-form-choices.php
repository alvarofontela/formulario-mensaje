<?php
/**
 * Formularios que se pueden elegir en el widget de Elementor.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Elementor;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Forms\Form_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Opciones del desplegable con el que el widget elige su formulario.
 *
 * Elementor inserta el texto de cada opción en su panel como HTML, sin escaparlo: el nombre de un
 * formulario es texto que escribe quien lo crea (o que viene en un fichero importado), así que se
 * entrega ya escapado.
 */
final class Form_Choices {

	/**
	 * Constructor.
	 *
	 * @param Form_Repository $forms Almacén de formularios.
	 */
	public function __construct( private readonly Form_Repository $forms ) {}

	/**
	 * Opciones del desplegable: la vacía y un formulario publicado por opción.
	 *
	 * @return array<string, string> Texto de cada opción, ya escapado para HTML, por identificador de formulario.
	 */
	public function options(): array {
		$options = array( '0' => esc_html__( 'Select a form', 'formulario-mensaje' ) );

		foreach ( $this->forms->all( Form::STATUS_PUBLISHED ) as $form ) {
			$options[ (string) $form->id ] = esc_html( $form->title );
		}

		return $options;
	}
}
