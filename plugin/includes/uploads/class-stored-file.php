<?php
/**
 * Fichero guardado.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con un fichero ya comprobado y guardado en el almacén privado del plugin.
 */
final class Stored_File {

	/**
	 * Constructor.
	 *
	 * @param int    $id            Identificador; 0 si todavía no se ha guardado el registro.
	 * @param int    $submission_id Identificador del envío guardado, o 0 si el envío no se guarda.
	 * @param int    $form_id       Identificador del formulario.
	 * @param string $field_id      Identificador del campo con el que se subió.
	 * @param string $reference     Número de referencia del envío.
	 * @param string $token         Identificador aleatorio e impredecible del fichero.
	 * @param string $name          Nombre original, saneado, para mostrarlo y para la descarga.
	 * @param string $mime          Tipo real del contenido.
	 * @param int    $size          Tamaño en bytes.
	 * @param string $path          Ruta relativa al almacén privado.
	 * @param int    $created_at    Marca de tiempo Unix de la subida.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $submission_id,
		public readonly int $form_id,
		public readonly string $field_id,
		public readonly string $reference,
		public readonly string $token,
		public readonly string $name,
		public readonly string $mime,
		public readonly int $size,
		public readonly string $path,
		public readonly int $created_at
	) {}
}
