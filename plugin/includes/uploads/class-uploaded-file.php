<?php
/**
 * Fichero recibido con un envío.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con un fichero tal como lo ha recibido PHP, antes de comprobarlo y de moverlo.
 *
 * Nada de lo que trae es fiable salvo su ruta temporal: el nombre lo elige el visitante y el tipo
 * que declara el navegador ni se guarda.
 */
final class Uploaded_File {

	/**
	 * Constructor.
	 *
	 * @param string $name     Nombre que tenía el fichero en el equipo del visitante, ya sin ruta ni caracteres raros.
	 * @param string $tmp_name Ruta temporal en la que PHP ha dejado el fichero.
	 * @param int    $size     Tamaño en bytes.
	 * @param int    $error    Código de error de la subida: una de las constantes UPLOAD_ERR_* de PHP.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $tmp_name,
		public readonly int $size,
		public readonly int $error
	) {}

	/**
	 * Datos del fichero con la forma de un elemento de `$_FILES`, que es la que espera WordPress.
	 *
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
	 */
	public function to_files_array(): array {
		return array(
			'name'     => $this->name,
			'type'     => '',
			'tmp_name' => $this->tmp_name,
			'error'    => $this->error,
			'size'     => $this->size,
		);
	}
}
