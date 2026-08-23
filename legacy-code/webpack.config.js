const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'blocks/collection-table/index': path.resolve(
			__dirname,
			'src/blocks/collection-table/index.js'
		),
		'blocks/object-view/index': path.resolve(
			__dirname,
			'src/blocks/object-view/index.js'
		),
	},
};
