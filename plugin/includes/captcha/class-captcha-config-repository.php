<?php
/**
 * Almacén de los ajustes del captcha.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Captcha;

use Formulario_Mensaje\Secrets\Secret_Vault;

defined( 'ABSPATH' ) || exit;

/**
 * Guarda y lee los ajustes del captcha en la Options API, junto con su clave secreta.
 */
final class Captcha_Config_Repository {

	/**
	 * Nombre de la opción.
	 */
	public const OPTION = 'formulario_mensaje_captcha';

	/**
	 * Constructor.
	 *
	 * @param Captcha_Config_Sanitizer $sanitizer Saneado de los ajustes.
	 * @param Secret_Vault             $secrets   Almacén de secretos.
	 */
	public function __construct(
		private readonly Captcha_Config_Sanitizer $sanitizer,
		private readonly Secret_Vault $secrets
	) {}

	/**
	 * Ajustes actuales; sin captcha si no se ha guardado ninguno.
	 */
	public function get(): Captcha_Config {
		// Lo guardado se vuelve a sanear: la opción puede haberse editado por otra vía.
		return $this->sanitizer->sanitize( get_option( self::OPTION, array() ) );
	}

	/**
	 * Guarda los ajustes y aplica el cambio pedido sobre la clave secreta.
	 *
	 * La clave secreta pertenece al servicio para el que se escribió: si el proveedor cambia y no
	 * llega una nueva, la guardada se descarta en vez de enviarse a otro servicio. Se descarta antes
	 * de guardar el proveedor nuevo, y si no se puede descartar no se guarda nada: así el proveedor
	 * nuevo nunca convive con la clave del anterior.
	 *
	 * @param Captcha_Config $config Ajustes ya saneados.
	 * @param string         $secret Clave secreta nueva; vacía para conservar la guardada.
	 * @param bool           $clear  Si se borra la clave secreta guardada.
	 * @return bool Si la clave secreta guardada se ha descartado sin que nadie lo pidiera.
	 */
	public function save( Captcha_Config $config, string $secret = '', bool $clear = false ): bool {
		$previous = $this->get();
		$dropped  = false;

		// Una clave definida como constante no se puede descartar: la controla quien edita `wp-config.php`.
		if ( $previous->provider !== $config->provider && Secret_Vault::SOURCE_STORED === $this->secrets->source( Captcha_Config::SECRET ) ) {
			if ( ! $this->secrets->set( Captcha_Config::SECRET, '' ) ) {
				return false;
			}

			$dropped = true;
		}

		// Sin carga automática: solo hace falta donde hay un formulario o en la administración.
		update_option( self::OPTION, $config->to_array(), false );

		if ( '' !== $secret ) {
			$this->secrets->set( Captcha_Config::SECRET, $secret );
		} elseif ( $clear ) {
			$this->secrets->set( Captcha_Config::SECRET, '' );
		}

		// Se avisa cuando nadie pidió borrarla y al final no ha quedado ninguna: cuenta lo guardado.
		return $dropped && ! $clear && Secret_Vault::SOURCE_NONE === $this->secrets->source( Captcha_Config::SECRET );
	}

	/**
	 * Clave secreta del sitio en el servicio, o cadena vacía si no hay ninguna.
	 */
	public function secret(): string {
		return $this->secrets->get( Captcha_Config::SECRET );
	}

	/**
	 * De dónde sale la clave secreta: una de las constantes SOURCE_* del almacén de secretos.
	 */
	public function secret_source(): string {
		return $this->secrets->source( Captcha_Config::SECRET );
	}

	/**
	 * Nombre de la constante de `wp-config.php` que puede definir la clave secreta.
	 */
	public function secret_constant(): string {
		return $this->secrets->constant_name( Captcha_Config::SECRET );
	}
}
