<?php
/**
 * Cifrado de secretos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Secrets;

use SodiumException;

defined( 'ABSPATH' ) || exit;

/**
 * Cifra y descifra los secretos que el plugin guarda en la base de datos.
 *
 * La clave se deriva de las salts de `wp-config.php`, así que no viaja con una copia de la base de
 * datos: quien solo tenga un volcado no puede leer los secretos. Si las salts cambian, lo guardado
 * deja de poder descifrarse y hay que volver a escribirlo.
 */
final class Secret_Cipher {

	/**
	 * Prefijo de un valor cifrado, con la versión del formato.
	 */
	private const PREFIX = 'fm1:';

	/**
	 * Cifra un texto.
	 *
	 * @param string $plaintext Texto en claro.
	 * @return string Valor cifrado y autenticado, en hexadecimal; cadena vacía si no se pudo cifrar.
	 */
	public function encrypt( string $plaintext ): string {
		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			return self::PREFIX . sodium_bin2hex( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $this->key() ) );
		} catch ( SodiumException ) {
			return '';
		}
	}

	/**
	 * Indica si un valor tiene la forma de los que devuelve encrypt(). No comprueba que se pueda descifrar.
	 *
	 * @param string $value Valor a comprobar.
	 */
	public function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	/**
	 * Descifra un valor cifrado con encrypt().
	 *
	 * @param string $encrypted Valor cifrado.
	 * @return string|null El secreto, o null si el valor no es de esta instalación o se ha manipulado.
	 */
	public function decrypt( string $encrypted ): ?string {
		if ( ! str_starts_with( $encrypted, self::PREFIX ) ) {
			return null;
		}

		try {
			$binary = sodium_hex2bin( substr( $encrypted, strlen( self::PREFIX ) ) );

			if ( strlen( $binary ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return null;
			}

			$plaintext = sodium_crypto_secretbox_open(
				substr( $binary, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $binary, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				$this->key()
			);
		} catch ( SodiumException ) {
			return null;
		}

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * Clave de cifrado: 32 bytes derivados de las salts del sitio.
	 */
	private function key(): string {
		return hash_hmac( 'sha256', 'formulario-mensaje-secrets', wp_salt( 'secure_auth' ), true );
	}
}
