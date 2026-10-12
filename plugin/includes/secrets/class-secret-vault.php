<?php
/**
 * Almacén de secretos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Guarda y entrega los secretos del plugin: contraseñas y claves de servicios externos.
 *
 * Un secreto puede definirse como constante en `wp-config.php` (`FORMULARIO_MENSAJE_` y su nombre en
 * mayúsculas), que tiene prioridad, o guardarse cifrado en la base de datos. El valor solo sale de
 * aquí hacia el código que lo usa para conectar: nunca hacia el navegador, ni por REST ni en el HTML.
 */
final class Secret_Vault {

	/**
	 * Opción que guarda los secretos cifrados, por nombre.
	 */
	public const OPTION = 'formulario_mensaje_secrets';

	/**
	 * Longitud máxima de un secreto, en bytes. Una contraseña o una clave de API no se le acercan; sin
	 * tope, un valor enorme podría hacer fracasar la escritura de todos los secretos a la vez.
	 */
	public const MAX_LENGTH = 1000;

	public const SOURCE_CONSTANT = 'constant';
	public const SOURCE_STORED   = 'stored';
	public const SOURCE_NONE     = 'none';

	/**
	 * Constructor.
	 *
	 * @param Secret_Cipher $cipher Cifrado de secretos.
	 */
	public function __construct( private readonly Secret_Cipher $cipher ) {}

	/**
	 * Devuelve un secreto en claro.
	 *
	 * @param string $name Nombre del secreto: minúsculas, cifras y guiones bajos.
	 * @return string Cadena vacía si no está definido o no se puede descifrar.
	 */
	public function get( string $name ): string {
		$constant = $this->constant_name( $name );

		if ( defined( $constant ) && is_string( constant( $constant ) ) ) {
			return constant( $constant );
		}

		$stored = $this->stored()[ $name ] ?? '';

		return '' === $stored ? '' : ( $this->cipher->decrypt( $stored ) ?? '' );
	}

	/**
	 * Guarda un secreto cifrado. Una cadena vacía lo borra.
	 *
	 * @param string $name  Nombre del secreto.
	 * @param string $value Valor en claro.
	 * @return bool Si el secreto ha quedado como se pedía. False si es demasiado largo, si no se pudo
	 *              cifrar o si la base de datos no aceptó el cambio: quien llama decide qué hacer.
	 */
	public function set( string $name, string $value ): bool {
		if ( strlen( $value ) > self::MAX_LENGTH ) {
			return false;
		}

		$stored = $this->stored();

		if ( '' === $value ) {
			unset( $stored[ $name ] );
		} else {
			$encrypted = $this->cipher->encrypt( $value );

			if ( '' === $encrypted ) {
				return false;
			}

			$stored[ $name ] = $encrypted;
		}

		// Sin carga automática: solo hacen falta al conectar con el servicio.
		update_option( self::OPTION, $stored, false );

		// update_option() devuelve false tanto si falla como si no había nada que cambiar: lo que cuenta
		// es lo que ha quedado guardado.
		return $this->stored() === $stored;
	}

	/**
	 * De dónde sale un secreto, sin revelarlo. Es lo único que se le cuenta al navegador.
	 *
	 * @param string $name Nombre del secreto.
	 * @return string SOURCE_CONSTANT, SOURCE_STORED o SOURCE_NONE.
	 */
	public function source( string $name ): string {
		if ( defined( $this->constant_name( $name ) ) ) {
			return self::SOURCE_CONSTANT;
		}

		return '' !== $this->get( $name ) ? self::SOURCE_STORED : self::SOURCE_NONE;
	}

	/**
	 * Nombre de la constante de `wp-config.php` que define un secreto.
	 *
	 * @param string $name Nombre del secreto.
	 */
	public function constant_name( string $name ): string {
		return 'FORMULARIO_MENSAJE_' . strtoupper( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $name ) ) );
	}

	/**
	 * Secretos cifrados guardados, por nombre.
	 *
	 * @return array<string, string>
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? array_filter( $stored, 'is_string' ) : array();
	}
}
