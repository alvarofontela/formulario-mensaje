import { Button, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Ventana para confirmar una acción que no se puede deshacer.
 *
 * @param {Object}   props
 * @param {string}   props.title     Pregunta que se hace.
 * @param {string}   props.confirm   Texto del botón que confirma.
 * @param {Function} props.onConfirm Se llama al confirmar.
 * @param {Function} props.onCancel  Se llama al cancelar o cerrar.
 * @param {Object}   props.children  Explicación de lo que va a pasar.
 */
export default function ConfirmModal( {
	title,
	confirm,
	onConfirm,
	onCancel,
	children,
} ) {
	return (
		<Modal title={ title } onRequestClose={ onCancel } size="small">
			{ children }
			<div className="fm-admin__modal-actions">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onCancel }
				>
					{ __( 'Cancel', 'formulario-mensaje' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isDestructive
					onClick={ onConfirm }
				>
					{ confirm }
				</Button>
			</div>
		</Modal>
	);
}
