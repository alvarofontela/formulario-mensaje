<?php
/**
 * Ficheros de los comandos de WP-CLI.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Lee y escribe los ficheros que indica quien ejecuta un comando de WP-CLI.
 *
 * La ruta la escribe en su terminal alguien que ya tiene acceso al servidor con ese mismo usuario
 * del sistema: no hay un directorio al que limitarla, como sí lo hay en una petición web. Por eso
 * esta clase solo se usa desde los comandos, nunca desde una ruta REST ni desde la administración.
 */
final class File_Access {

	/**
	 * Tamaño máximo, en bytes, de un fichero que se lee entero.
	 */
	private const MAX_READ_BYTES = 2 * MB_IN_BYTES;

	/**
	 * Lee un fichero local entero.
	 *
	 * @param string $path Ruta del fichero.
	 * @return string|null Null si no existe, no se puede leer, no es un fichero local o es demasiado grande.
	 */
	public function read( string $path ): ?string {
		// Solo rutas locales: una dirección (http://, php://, phar://) no es un fichero.
		if ( '' === $path || str_contains( $path, '://' ) || ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) > self::MAX_READ_BYTES ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichero local que indica quien ejecuta WP-CLI; ver la descripción de la clase.
		$contents = file_get_contents( $path );

		return false === $contents ? null : $contents;
	}

	/**
	 * Escribe un fichero local, sustituyendo el que hubiera.
	 *
	 * @param string $path     Ruta del fichero.
	 * @param string $contents Contenido.
	 */
	public function write( string $path, string $contents ): bool {
		if ( '' === $path || str_contains( $path, '://' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fichero local que indica quien ejecuta WP-CLI; ver la descripción de la clase.
		return false !== file_put_contents( $path, $contents );
	}

	/**
	 * Abre un fichero local para escribir en él fila a fila; sin ruta, la salida del comando.
	 *
	 * @param string $path Ruta del fichero, o cadena vacía para la salida estándar.
	 * @return resource|null Null si no se puede abrir.
	 */
	public function open_for_writing( string $path ) {
		if ( str_contains( $path, '://' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Flujo de salida o fichero local que indica quien ejecuta WP-CLI; WP_Filesystem no escribe fila a fila.
		$handle = fopen( '' === $path ? 'php://stdout' : $path, 'w' );

		return false === $handle ? null : $handle;
	}
}
