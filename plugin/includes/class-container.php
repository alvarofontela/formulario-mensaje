<?php
/**
 * Contenedor de servicios del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje;

use LogicException;

defined( 'ABSPATH' ) || exit;

/**
 * Contenedor mínimo y perezoso: guarda cómo se construye cada servicio y lo crea la primera vez que se pide.
 *
 * Solo lo conocen el arranque y los proveedores. Ningún servicio lo recibe: no es un localizador de servicios.
 */
final class Container {

	/**
	 * Fábricas registradas, por identificador.
	 *
	 * @var array<string, callable(Container): object>
	 */
	private array $factories = array();

	/**
	 * Servicios ya construidos, por identificador.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Declara cómo se construye un servicio.
	 *
	 * @param string                     $id      Identificador; por convención, el nombre de la clase o interfaz.
	 * @param callable(Container):object $factory Función que construye el servicio.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Indica si un servicio está declarado.
	 *
	 * @param string $id Identificador del servicio.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Devuelve un servicio, construyéndolo si es la primera vez que se pide.
	 *
	 * @template T of object
	 *
	 * @param class-string<T>|string $id Identificador del servicio.
	 * @return T|object
	 *
	 * @throws LogicException Si el servicio no está declarado: es un error de programación.
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new LogicException( esc_html( sprintf( 'Service "%s" is not registered.', $id ) ) );
		}

		$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->instances[ $id ];
	}
}
