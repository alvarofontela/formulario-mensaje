<?php
/**
 * Contrato de una comprobación antispam.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Una defensa contra envíos automatizados. Se ejecutan en cadena antes de validar los datos.
 */
interface Spam_Check {

	/**
	 * Decide si un envío puede continuar.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @param Form               $form    Formulario al que se envía.
	 * @return WP_Error|null Null si el envío puede continuar; un error con `status` en sus datos si se rechaza.
	 */
	public function check( Submission_Request $request, Form $form ): ?WP_Error;
}
