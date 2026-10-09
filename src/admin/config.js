/**
 * Datos que el servidor entrega al cargar la pantalla (ver Admin_Data en PHP): tipos de campo y de
 * acción registrados con sus esquemas, permisos del usuario y direcciones.
 */
const config = window.formularioMensajeAdmin || {};

export const fieldTypes = config.fieldTypes || [];
export const fieldGroups = config.fieldGroups || {};
export const actionTypes = config.actionTypes || [];
export const defaultDefinition = config.defaultDefinition || {
	fields: [],
	settings: {},
	actions: [],
};
export const can = config.can || {};
export const urls = config.urls || {};
export const siteHost = config.siteHost || '';
export const operators = config.operators || [];
export const userEmail = config.userEmail || '';
export const restBase = `/${ config.restNamespace || 'formulario-mensaje/v1' }`;

/**
 * @param {string} type Identificador de un tipo de campo.
 * @return {?Object} Descripción del tipo, o undefined si no está registrado.
 */
export const getFieldType = ( type ) =>
	fieldTypes.find( ( candidate ) => candidate.type === type );

/**
 * @param {string} type Identificador de un tipo de acción.
 * @return {?Object} Descripción del tipo, o undefined si no está registrado.
 */
export const getActionType = ( type ) =>
	actionTypes.find( ( candidate ) => candidate.type === type );
