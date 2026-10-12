<?php
/**
 * Registro de entregas: cada una dice de qué acción del formulario es.
 */

class EntregasTest extends Caso_Formulario {

	public function test_cada_entrega_lleva_el_identificador_y_el_nombre_de_su_accion() {
		$definicion = $this->definicion();
		$equipo     = array_merge( $definicion['actions'][0], array( 'id' => 'equipo', 'name' => 'Al equipo <de> ventas' ) );
		$sin_nombre = array_merge( $definicion['actions'][0], array( 'id' => 'copia' ) );
		$rota       = array_merge( $definicion['actions'][0], array( 'id' => 'rota', 'name' => 'Sin destino' ) );

		$rota['settings']['to'] = array();

		$formulario = $this->crear_formulario( array( 'actions' => array( $equipo, $sin_nombre, $rota ) ) );

		$this->assertSame( 200, $this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) )->get_status() );

		$this->como_administrador();
		$envio    = $this->rest( 'GET', '/submissions', array( 'form' => $formulario ) )->get_data()[0];
		$entregas = array_column( $this->rest( 'GET', '/submissions/' . $envio['id'] )->get_data()['deliveries'], null, 'action_id' );

		$this->assertSame( array( 'equipo', 'copia', 'rota' ), array_keys( $entregas ) );
		// El nombre es texto sin formato: las etiquetas se quitaron al guardarlo.
		$this->assertSame( 'Al equipo ventas', $entregas['equipo']['action_name'] );
		$this->assertSame( '', $entregas['copia']['action_name'] );
		$this->assertSame( 'Sin destino', $entregas['rota']['action_name'] );
		$this->assertSame( 'failed', $entregas['rota']['status'] );
		$this->assertSame( 'email', $entregas['rota']['action_type'] );

		// En el listado de entregas fallidas, lo mismo.
		$fallidas = $this->rest( 'GET', '/deliveries' )->get_data();

		$this->assertSame( array( 'Sin destino' ), array_column( $fallidas, 'action_name' ) );

		// Si la acción se quita del formulario, la entrega se queda sin nombre, no con el de otra.
		$this->rest( 'PUT', "/forms/$formulario", array( 'title' => 'Contacto', 'definition' => $this->definicion( array( 'actions' => array( $equipo ) ) ) ) );

		$despues = array_column( $this->rest( 'GET', '/submissions/' . $envio['id'] )->get_data()['deliveries'], null, 'action_id' );

		$this->assertSame( '', $despues['rota']['action_name'] );
		$this->assertSame( 'Al equipo ventas', $despues['equipo']['action_name'] );
	}
}
