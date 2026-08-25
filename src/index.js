import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { ServerSideRender } from '@wordpress/server-side-render';
import { PanelBody, RangeControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const VIEWS = ['images', 'videos', 'audio', 'notes'];
const MIN_COUNT = 1;
const MAX_COUNT = 50;

const edit = ({ attributes, setAttributes, name }) => (
	<>
		<InspectorControls>
			<PanelBody title={__('Daymark settings', 'daymark')} initialOpen>
				<RangeControl
					label={__('Number of Marks', 'daymark')}
					help={__('How many recent Marks to show (1 to 50).', 'daymark')}
					value={attributes.count}
					onChange={(count) => setAttributes({ count })}
					min={MIN_COUNT}
					max={MAX_COUNT}
				/>
			</PanelBody>
		</InspectorControls>
		<div {...useBlockProps()}>
			<ServerSideRender block={name} attributes={attributes} />
		</div>
	</>
);

VIEWS.forEach((view) => {
	registerBlockType(`daymark/${view}`, { edit });
});
