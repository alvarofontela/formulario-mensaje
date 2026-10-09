<?php
/**
 * Contrato de quien aporta los adjuntos de una notificación.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * Dice qué ficheros de un envío se adjuntan a sus notificaciones.
 */
interface Attachment_Source {

	/**
	 * Ficheros que se adjuntan a las notificaciones de un envío.
	 *
	 * @param Submission $submission Envío.
	 * @param Form       $form       Formulario.
	 * @return array<int, array{path: string, name: string}> Ruta completa en el servidor y nombre con el que se adjunta.
	 */
	public function attachments( Submission $submission, Form $form ): array;
}
