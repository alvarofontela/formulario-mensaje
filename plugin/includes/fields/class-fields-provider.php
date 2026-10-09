<?php
/**
 * Proveedor de los campos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Support\Inline_Html;
use Formulario_Mensaje\Uploads\File_Types;
use Formulario_Mensaje\Support\Php_Request_Query;
use Formulario_Mensaje\Support\Request_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Declara el registro de tipos de campo y registra los tipos que trae el plugin.
 */
final class Fields_Provider implements Service_Provider {

	/**
	 * Acción en la que el plugin y terceros registran sus tipos de campo.
	 */
	public const REGISTER_ACTION = 'formulario_mensaje_register_field_types';

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set( Inline_Html::class, static fn (): Inline_Html => new Inline_Html() );
		$container->set( Request_Query::class, static fn (): Request_Query => new Php_Request_Query() );
		$container->set( Field_Markup::class, static fn (): Field_Markup => new Field_Markup() );
		$container->set( Common_Settings::class, static fn (): Common_Settings => new Common_Settings() );
		$container->set( Step_Layout::class, static fn ( Container $c ): Step_Layout => new Step_Layout( $c->get( Field_Type_Registry::class ) ) );

		$container->set(
			Prefill_Resolver::class,
			static fn ( Container $c ): Prefill_Resolver => new Prefill_Resolver( $c->get( Request_Query::class ) )
		);

		$container->set(
			Context_Resolver::class,
			static fn ( Container $c ): Context_Resolver => new Context_Resolver( $c->get( Request_Query::class ) )
		);

		$container->set(
			Settings_Schema_Sanitizer::class,
			static fn ( Container $c ): Settings_Schema_Sanitizer => new Settings_Schema_Sanitizer( $c->get( Inline_Html::class ) )
		);

		$container->set(
			Field_Type_Registry::class,
			static function (): Field_Type_Registry {
				$registry = new Field_Type_Registry();

				/**
				 * Registra los tipos de campo disponibles.
				 *
				 * Los tipos del plugin se registran en esta misma acción con prioridad 5: un tipo
				 * registrado después con el mismo identificador sustituye al del plugin.
				 *
				 * @since 0.1.0
				 *
				 * @param Field_Type_Registry $registry Registro al que añadir los tipos con `register()`.
				 */
				do_action( 'formulario_mensaje_register_field_types', $registry );

				return $registry;
			}
		);

		add_action(
			self::REGISTER_ACTION,
			static function ( Field_Type_Registry $registry ) use ( $container ): void {
				foreach ( self::built_in_types( $container ) as $type ) {
					$registry->register( $type );
				}
			},
			5
		);
	}

	/**
	 * Tipos de campo que trae el plugin, en el orden en que aparecen en el constructor.
	 *
	 * @param Container $container Contenedor del plugin.
	 * @return Field_Type[]
	 */
	private static function built_in_types( Container $container ): array {
		$markup  = $container->get( Field_Markup::class );
		$common  = $container->get( Common_Settings::class );
		$prefill = $container->get( Prefill_Resolver::class );
		$inline  = $container->get( Inline_Html::class );

		return array(
			new Types\Text_Field_Type( $markup, $common, $prefill ),
			new Types\Email_Field_Type( $markup, $common, $prefill ),
			new Types\Tel_Field_Type( $markup, $common, $prefill ),
			new Types\Textarea_Field_Type( $markup, $common, $prefill ),
			new Types\Select_Field_Type( $markup, $common ),
			new Types\Radio_Field_Type( $markup, $common ),
			new Types\Checkboxes_Field_Type( $markup, $common ),
			new Types\Consent_Field_Type( $markup, $common, $inline ),
			new Types\Number_Field_Type( $markup, $common, $prefill ),
			new Types\Date_Field_Type( $markup, $common, $prefill ),
			new Types\Url_Field_Type( $markup, $common, $prefill ),
			new Types\File_Field_Type( $markup, $common, $container->get( File_Types::class ) ),
			new Types\Hidden_Field_Type( $markup, $common, $container->get( Context_Resolver::class ) ),
			new Types\Step_Field_Type( $common ),
			new Types\Heading_Field_Type( $markup, $common ),
			new Types\Paragraph_Field_Type( $markup, $common, $inline ),
			new Types\Divider_Field_Type( $markup, $common ),
		);
	}
}
