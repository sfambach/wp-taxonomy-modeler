/**
 * Copy each src/blocks/<name>/block.json next to its built bundle.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const srcRoot = path.resolve( __dirname, '../src/blocks' );
const buildRoot = path.resolve( __dirname, '../build/blocks' );

if ( ! fs.existsSync( srcRoot ) ) {
	console.error( 'Missing', srcRoot );
	process.exit( 1 );
}

const entries = fs
	.readdirSync( srcRoot, { withFileTypes: true } )
	.filter( ( d ) => d.isDirectory() )
	.map( ( d ) => d.name );

let copied = 0;
for ( const name of entries ) {
	const src = path.join( srcRoot, name, 'block.json' );
	if ( ! fs.existsSync( src ) ) {
		continue;
	}
	const destDir = path.join( buildRoot, name );
	fs.mkdirSync( destDir, { recursive: true } );
	const dest = path.join( destDir, 'block.json' );
	fs.copyFileSync( src, dest );
	console.log( 'Copied block.json →', dest );
	copied += 1;
}

if ( 0 === copied ) {
	console.error( 'No block.json files found under', srcRoot );
	process.exit( 1 );
}
