<?php
/**
 * Arranque del plugin: carga de clases, contenedor, ciclo de vida y tipo de contenido.
 */

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Forms\Form_Post_Type;
use Formulario_Mensaje\Lifecycle\Activator;
use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Lifecycle\Installer;

class NucleoTest extends Caso_Formulario {

	public function test_el_autoloader_resuelve_clases_interfaces_y_enums() {
		$this->assertTrue( class_exists( Formulario_Mensaje\Mail\Wp_Mail_Transport::class ) );
		$this->assertTrue( interface_exists( Formulario_Mensaje\Mail\Mail_Transport::class ) );
		$this->assertTrue( enum_exists( Formulario_Mensaje\Settings\Trusted_Proxy_Mode::class ) );
	}

	public function test_el_autoloader_ignora_otros_namespaces_y_nombres_raros() {
		$this->assertFalse( class_exists( 'Otro_Plugin\\Clase' ) );
		$this->assertFalse( class_exists( 'Formulario_Mensaje\\..\\..\\wp-config' ) );
	}

	public function test_el_contenedor_construye_cada_servicio_una_sola_vez_y_solo_al_pedirlo() {
		$contenedor     = new Container();
		$construcciones = 0;

		$contenedor->set(
			'servicio',
			static function () use ( &$construcciones ) {
				++$construcciones;
				return new stdClass();
			}
		);

		$this->assertSame( 0, $construcciones, 'Declarar un servicio no lo construye.' );
		$this->assertSame( $contenedor->get( 'servicio' ), $contenedor->get( 'servicio' ) );
		$this->assertSame( 1, $construcciones );
	}

	public function test_el_contenedor_avisa_si_se_pide_un_servicio_no_declarado() {
		$this->expectException( LogicException::class );

		( new Container() )->get( 'no-existe' );
	}

	public function test_la_instalacion_concede_las_capabilities_al_administrador_y_guarda_la_version() {
		$capabilities = new Capabilities();
		$capabilities->remove_from_all_roles();

		( new Installer( $capabilities, '9.9.9' ) )->install();

		foreach ( $capabilities->all() as $capability ) {
			$this->assertTrue( get_role( 'administrator' )->has_cap( $capability ), $capability );
			$this->assertFalse( get_role( 'editor' )->has_cap( $capability ), $capability );
		}

		$this->assertSame( '9.9.9', get_option( Installer::VERSION_OPTION ) );
	}

	public function test_la_actualizacion_solo_se_ejecuta_cuando_cambia_la_version() {
		$capabilities = new Capabilities();
		$instalador   = new Installer( $capabilities, '1.0.0' );
		$instalador->install();
		$capabilities->remove_from_all_roles();

		$instalador->maybe_upgrade();
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_FORMS ), 'Misma versión: no hace nada.' );

		( new Installer( $capabilities, '1.1.0' ) )->maybe_upgrade();
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_FORMS ) );
	}

	public function test_la_activacion_para_toda_la_red_se_rechaza() {
		$capabilities = new Capabilities();
		$capabilities->remove_from_all_roles();

		try {
			( new Activator( new Installer( $capabilities, '1.0.0' ) ) )->activate( true );
			$this->fail( 'La activación en red debería detener la ejecución.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'network', $e->getMessage() );
		}

		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_FORMS ), 'No debe instalar nada.' );
	}

	public function test_el_tipo_de_contenido_es_privado_y_exige_la_capability_del_plugin() {
		$tipo = get_post_type_object( Form_Post_Type::NAME );

		$this->assertNotNull( $tipo );
		$this->assertFalse( $tipo->public );
		$this->assertFalse( $tipo->show_ui );
		$this->assertFalse( $tipo->show_in_rest );
		$this->assertFalse( $tipo->publicly_queryable );
		$this->assertSame( Capabilities::MANAGE_FORMS, $tipo->cap->edit_posts );
		$this->assertSame( Capabilities::MANAGE_FORMS, $tipo->cap->delete_posts );
	}

	public function test_las_traducciones_al_espanol_se_cargan_desde_el_plugin() {
		$this->assertTrue( switch_to_locale( 'es_ES' ), 'El idioma es_ES debe estar disponible en la suite de tests.' );
		$traducido = __( 'This field is required.', 'formulario-mensaje' );
		restore_previous_locale();

		$this->assertSame( 'Este campo es obligatorio.', $traducido );
	}
}
