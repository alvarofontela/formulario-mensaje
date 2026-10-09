<?php
/**
 * Almacén privado de ficheros.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Directorio en el que se guardan los ficheros de los envíos.
 *
 * Está dentro de la carpeta de subidas de WordPress, que es donde el servidor deja escribir, pero no
 * se sirve por URL: lleva un `.htaccess` que lo prohíbe, y los ficheros se guardan con un nombre
 * aleatorio y una extensión que ningún servidor ejecuta ni ningún navegador interpreta. Donde el
 * `.htaccess` no se aplica (nginx), la protección es que el nombre no se puede adivinar.
 */
final class Upload_Directory {

	/**
	 * Nombre de la carpeta, dentro de la de subidas.
	 */
	private const NAME = 'formulario-mensaje-private';

	/**
	 * Extensión con la que se guardan todos los ficheros, sea cual sea su tipo.
	 */
	private const EXTENSION = '.dat';

	/**
	 * Ruta completa del almacén, sin barra final.
	 */
	public function base(): string {
		return untrailingslashit( wp_upload_dir( null, false )['basedir'] ) . '/' . self::NAME;
	}

	/**
	 * Ruta nueva, relativa al almacén, para guardar un fichero.
	 *
	 * El nombre es aleatorio y no sale de ningún otro dato del fichero, tampoco de su identificador
	 * público: quien tiene un enlace de descarga, vigente o caducado, no puede deducir de él dónde
	 * está el fichero. Donde el servidor no aplica el `.htaccess`, eso es lo que impide ir a buscarlo
	 * sin pasar por los permisos ni por la caducidad del enlace.
	 */
	public function new_relative_path(): string {
		$name = bin2hex( random_bytes( 16 ) );

		// Una subcarpeta por los dos primeros caracteres, para no juntar miles de ficheros en una sola.
		return substr( $name, 0, 2 ) . '/' . $name . self::EXTENSION;
	}

	/**
	 * Crea, si falta, la carpeta en la que va un fichero, con el almacén ya protegido.
	 *
	 * @param string $relative_path Ruta relativa del fichero.
	 * @return string|null Ruta completa de la carpeta, o null si no se ha podido crear.
	 */
	public function prepare( string $relative_path ): ?string {
		$base      = $this->base();
		$directory = $base . '/' . dirname( $relative_path );

		if ( ! wp_mkdir_p( $directory ) ) {
			return null;
		}

		$this->protect( $base );

		return $directory;
	}

	/**
	 * Ruta completa de un fichero guardado, comprobando que no se sale del almacén.
	 *
	 * @param string $relative_path Ruta relativa guardada con el fichero.
	 * @return string|null Null si el fichero no existe o la ruta apunta fuera del almacén.
	 */
	public function resolve( string $relative_path ): ?string {
		$base = realpath( $this->base() );
		$path = realpath( $this->base() . '/' . $relative_path );

		if ( false === $base || false === $path || ! is_file( $path ) ) {
			return null;
		}

		return str_starts_with( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $base ) ) ) ? $path : null;
	}

	/**
	 * Borra el almacén entero. Solo al desinstalar, si el usuario ha pedido borrar los datos.
	 */
	public function remove(): void {
		$base = realpath( $this->base() );

		if ( false === $base || ! is_dir( $base ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Carpeta propia del plugin, dentro de la de subidas.
				rmdir( $item->getPathname() );
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Carpeta propia del plugin, dentro de la de subidas.
		rmdir( $base );
	}

	/**
	 * Deja en el almacén los ficheros que impiden servirlo y listarlo por URL.
	 *
	 * @param string $base Ruta completa del almacén.
	 */
	private function protect( string $base ): void {
		$files = array(
			// Apache 2.4 y, por si acaso, la sintaxis de 2.2.
			'.htaccess'  => "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'index.html' => '',
		);

		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $base . '/' . $name ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fichero fijo, escrito en la carpeta propia del plugin.
				file_put_contents( $base . '/' . $name, $content );
			}
		}
	}
}
