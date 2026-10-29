/**
 * "Follow me with Daymark" block (daymark/follow-me): lets a visitor follow
 * this site from their own site with Daymark, or with any feed reader.
 *
 * A dynamic block rendered in PHP (Daymark_Follow_Link::render_block()).
 * The editor previews it through the server-side renderer with the form
 * showing (the front-end script that reveals it on the site doesn't run in
 * the editor), inside Disabled so the preview can't be submitted. Plain
 * ES2020 against WordPress's own bundled scripts, with no build step, like
 * the rest of Daymark's browser code.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.blocks || !wp.element) {
		return;
	}

	const { registerBlockType } = wp.blocks;
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;
	const { useBlockProps } = wp.blockEditor;
	const { Disabled } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	registerBlockType('daymark/follow-me', {
		apiVersion: 3,
		title: __('Follow me with Daymark', 'daymark'),
		description: __('Lets visitors follow this site from their own site with Daymark, or with any feed reader.', 'daymark'),
		category: 'widgets',
		icon: 'groups',
		keywords: [__('follow', 'daymark'), __('subscribe', 'daymark'), __('feed', 'daymark')],
		attributes: {
			preview: { type: 'boolean', default: false },
		},
		supports: {
			html: false,
			align: ['wide', 'full'],
			spacing: { margin: true, padding: true },
		},
		edit(props) {
			const blockProps = useBlockProps();
			return el(
				'div',
				blockProps,
				el(
					Disabled,
					null,
					el(ServerSideRender, {
						block: 'daymark/follow-me',
						attributes: Object.assign({}, props.attributes, { preview: true }),
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
