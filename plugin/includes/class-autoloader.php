<?php
/**
 * Carga de clases del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje;

defined( 'ABSPATH' ) || exit;

/**
 * Traduce un nombre del namespace del plugin a su fichero, siguiendo las convenciones de WordPress.
 *
 * `Formulario_Mensaje\Mail\Smtp_Transport` se busca en `mail/class-smtp-transport.php` y, si no es
 * una clase, con los prefijos `interface-`, `trait-` y `enum-`.
 */
final class Autoloader {

	/**
	 * Namespace raíz que atiende este cargador.
	 */
	private const ROOT_NAMESPACE = 'Formulario_Mensaje\\';

	/**
	 * Prefijos de fichero admitidos, por orden de frecuencia.
	 */
	private const FILE_PREFIXES = array( 'class', 'interface', 'enum', 'trait' );

	/**
	 * Constructor.
	 *
	 * @param string $base_dir Carpeta que corresponde al namespace raíz, sin barra final.
	 */
	public function __construct( private readonly string $base_dir ) {}

	/**
	 * Registra el cargador en PHP.
	 */
	public function register(): void {
		spl_autoload_register( array( $this, 'load' ) );
	}

	/**
	 * Carga el fichero de un tipo del plugin, si existe.
	 *
	 * @param string $name Nombre completo de la clase, interfaz, trait o enum.
	 */
	public function load( string $name ): void {
		if ( ! str_starts_with( $name, self::ROOT_NAMESPACE ) ) {
			return;
		}

		$relative = substr( $name, strlen( self::ROOT_NAMESPACE ) );

		// Solo letras, cifras, guiones bajos y separadores de namespace: nada que pueda salir de la carpeta.
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}

		$segments = array_map(
			static fn ( string $segment ): string => strtolower( str_replace( '_', '-', $segment ) ),
			explode( '\\', $relative )
		);
		$basename = array_pop( $segments );
		$dir      = $this->base_dir . '/' . ( $segments ? implode( '/', $segments ) . '/' : '' );

		foreach ( self::FILE_PREFIXES as $prefix ) {
			$file = $dir . $prefix . '-' . $basename . '.php';

			if ( is_file( $file ) ) {
				// La ruta sale de la carpeta del plugin y de un nombre ya validado contra la expresión de arriba.
				require_once $file;
				return;
			}
		}
	}
}
