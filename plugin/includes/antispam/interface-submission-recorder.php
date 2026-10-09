<?php
/**
 * Contrato de quien toma nota de los envíos aceptados.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Defensa que necesita saber cuándo un envío se ha aceptado, por ejemplo para contarlo.
 */
interface Submission_Recorder {

	/**
	 * Toma nota de un envío aceptado.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @param Form               $form    Formulario al que se envió.
	 */
	public function record( Submission_Request $request, Form $form ): void;
}
