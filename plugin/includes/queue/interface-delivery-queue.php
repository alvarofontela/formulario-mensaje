<?php
/**
 * Contrato de la cola de entregas.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Queue;

use Formulario_Mensaje\Storage\Delivery;
use Formulario_Mensaje\Storage\Delivery_Status;

defined( 'ABSPATH' ) || exit;

/**
 * Cola de las acciones que se ejecutan en segundo plano.
 *
 * Una entrega en cola es una fila del registro de entregas en estado pendiente, con la fecha de su
 * siguiente intento: no hay una segunda tabla que mantener sincronizada con el registro.
 */
interface Delivery_Queue {

	/**
	 * Pone en cola una entrega.
	 *
	 * @param Delivery $delivery Entrega pendiente, con la fecha de su primer intento.
	 * @param string   $payload  Copia del envío en JSON si el formulario no lo guarda; cadena vacía si se puede leer de los envíos guardados.
	 * @return int Identificador asignado, o 0 si no se pudo guardar.
	 */
	public function push( Delivery $delivery, string $payload ): int;

	/**
	 * Reserva la siguiente entrega a la que ya le toca, para que nadie más la procese a la vez.
	 *
	 * Reservar cuenta el intento y aplaza la entrega: si el proceso muere a mitad, vuelve a estar
	 * disponible cuando pase ese plazo.
	 *
	 * @param int $now          Marca de tiempo Unix actual.
	 * @param int $lock_seconds Segundos durante los que la entrega queda reservada.
	 * @return Queue_Job|null Null si no hay ninguna a la que le toque.
	 */
	public function claim_next( int $now, int $lock_seconds ): ?Queue_Job;

	/**
	 * Deja una entrega pendiente de un nuevo intento.
	 *
	 * @param int    $id              Identificador de la entrega.
	 * @param string $error           Motivo del fallo del intento que acaba de hacerse.
	 * @param int    $next_attempt_at Marca de tiempo Unix del siguiente intento.
	 * @param int    $now             Marca de tiempo Unix actual.
	 */
	public function reschedule( int $id, string $error, int $next_attempt_at, int $now ): void;

	/**
	 * Saca una entrega de la cola con su resultado definitivo y borra la copia del envío.
	 *
	 * @param int             $id     Identificador de la entrega.
	 * @param Delivery_Status $status Resultado.
	 * @param string          $error  Motivo del fallo, o cadena vacía.
	 * @param int             $now    Marca de tiempo Unix actual.
	 */
	public function finish( int $id, Delivery_Status $status, string $error, int $now ): void;

	/**
	 * Marca de tiempo Unix del intento pendiente más próximo, o 0 si la cola está vacía.
	 */
	public function next_due(): int;
}
