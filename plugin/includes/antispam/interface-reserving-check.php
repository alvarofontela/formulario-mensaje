<?php
/**
 * Contrato de una defensa que reserva al comprobar.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Defensa que, al dar por bueno un envío, consume algo (un hueco del límite por IP) y debe devolverlo
 * si el envío no llega a aceptarse.
 *
 * Reservar al comprobar, en vez de contar al final, es lo que impide que varios envíos simultáneos
 * pasen todos la comprobación antes de que ninguno se haya contado.
 */
interface Reserving_Check extends Spam_Check {

	/**
	 * Devuelve lo que se reservó para un envío que finalmente no se aceptó.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @param Form               $form    Formulario al que se envió.
	 */
	public function release( Submission_Request $request, Form $form ): void;
}
