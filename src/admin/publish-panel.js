import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { urls } from './config';

/**
 * Paso «Publicar»: dónde está puesto el formulario y las tres formas de ponerlo en una página.
 *
 * @param {Object} props
 * @param {string} props.title      Nombre del formulario.
 * @param {string} props.shortcode  Código para pegar; vacío si el formulario todavía no se ha guardado.
 * @param {Array}  props.placements Páginas publicadas en las que se ha encontrado.
 */
export default function PublishPanel( { title, shortcode, placements } ) {
	const [ copied, setCopied ] = useState( false );

	if ( ! shortcode ) {
		return (
			<p className="fm-publish__lead">
				{ __(
					'Create the form first: then you will be able to put it on a page.',
					'formulario-mensaje'
				) }
			</p>
		);
	}

	const copy = () =>
		window.navigator.clipboard?.writeText( shortcode ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} );

	return (
		<div className="fm-publish">
			{ placements.length > 0 ? (
				<p className="fm-publish__lead">
					{ __( 'Right now it is on:', 'formulario-mensaje' ) }{ ' ' }
					{ placements.map( ( page, index ) => (
						<span key={ page.url }>
							{ index > 0 && ', ' }
							<a href={ page.url }>
								{ page.title ||
									__( '(no title)', 'formulario-mensaje' ) }
							</a>
						</span>
					) ) }
				</p>
			) : (
				<p className="fm-publish__lead">
					{ __(
						'It has not been found on any published page yet. Pick one of these three ways.',
						'formulario-mensaje'
					) }
				</p>
			) }

			<section className="fm-publish__way">
				<h3>
					{ __(
						'On a page, with the WordPress editor',
						'formulario-mensaje'
					) }
				</h3>
				<p>
					{ sprintf(
						/* translators: %s: name of the form. */
						__(
							'Open the page, press the + button and look for “Form”. Then choose “%s”.',
							'formulario-mensaje'
						),
						title || __( '(untitled form)', 'formulario-mensaje' )
					) }
				</p>
				{ urls.pages && (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						href={ urls.pages }
					>
						{ __( 'Go to the pages', 'formulario-mensaje' ) }
					</Button>
				) }
			</section>

			<section className="fm-publish__way">
				<h3>
					{ __(
						'On a page built with Elementor',
						'formulario-mensaje'
					) }
				</h3>
				<p>
					{ __(
						'Look for the “Form” element in the Elementor panel, drag it to the page and choose this form.',
						'formulario-mensaje'
					) }
				</p>
			</section>

			<section className="fm-publish__way">
				<h3>{ __( 'With a code to paste', 'formulario-mensaje' ) }</h3>
				<p>
					{ __(
						'For anywhere else on the site. Paste it where you want the form to appear.',
						'formulario-mensaje'
					) }
				</p>
				<div className="fm-publish__code">
					<code className="fm-admin__shortcode">{ shortcode }</code>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={ copy }
					>
						{ copied
							? __( 'Copied', 'formulario-mensaje' )
							: __( 'Copy', 'formulario-mensaje' ) }
					</Button>
				</div>
			</section>
		</div>
	);
}
