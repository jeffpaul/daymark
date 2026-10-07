/**
 * Blogroll block (daymark/blogroll): the sites you follow with Daymark.
 *
 * A dynamic block rendered in PHP (Daymark_Blogroll::render_block()). The
 * editor previews it through the server-side renderer and offers one
 * setting, whether to show each site's icon. Plain ES2020 against
 * WordPress's own bundled scripts, with no build step, like the rest of
 * Daymark's browser code.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.blocks || !wp.element) {
		return;
	}

	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, ToggleControl, Placeholder } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	registerBlockType('daymark/blogroll', {
		apiVersion: 3,
		title: __('Blogroll', 'daymark'),
		description: __('The sites you follow with Daymark.', 'daymark'),
		category: 'widgets',
		icon: 'rss',
		attributes: {
			showIcons: { type: 'boolean', default: true },
		},
		supports: { html: false, align: ['wide', 'full'] },
		edit(props) {
			const { attributes, setAttributes } = props;
			const blockProps = useBlockProps();
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Settings', 'daymark') },
						el(ToggleControl, {
							label: __('Show site icons', 'daymark'),
							checked: !!attributes.showIcons,
							onChange: (value) => setAttributes({ showIcons: !!value }),
							__nextHasNoMarginBottom: true,
						})
					)
				),
				el(
					'div',
					blockProps,
					el(ServerSideRender, {
						block: 'daymark/blogroll',
						attributes,
						EmptyResponsePlaceholder: () =>
							el(
								Placeholder,
								{ icon: 'rss', label: __('Blogroll', 'daymark') },
								__('You aren’t following any sites yet. Sites you follow with Daymark appear here.', 'daymark')
							),
					})
				)
			);
		},
		// Rendered in PHP on every page view, so nothing is saved in the post.
		save() {
			return null;
		},
	});
})(window.wp);
