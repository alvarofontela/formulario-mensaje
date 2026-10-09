<?php
/**
 * Contrato de quien mueve un fichero subido a su sitio definitivo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Uploads;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Mueve un fichero recién subido al almacén, tras comprobar que su contenido es de un tipo permitido.
 */
interface Upload_Mover {

	/**
	 * Comprueba y mueve un fichero.
	 *
	 * @param Uploaded_File         $file      Fichero recibido.
	 * @param string                $directory Directorio de destino, que ya existe.
	 * @param string                $filename  Nombre con el que se guarda. No es el que traía el fichero.
	 * @param array<string, string> $mimes     Tipos permitidos, por extensión.
	 * @return array{path: string, type: string}|WP_Error Ruta completa y tipo real del fichero guardado.
	 */
	public function move( Uploaded_File $file, string $directory, string $filename, array $mimes ): array|WP_Error;
}
