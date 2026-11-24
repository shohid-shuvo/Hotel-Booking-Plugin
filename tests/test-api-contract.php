<?php
/** Verify runtime API route literals against the checked-in OpenAPI contract. */
$root = dirname( __DIR__ );
$schema = json_decode( file_get_contents( $root . '/agent-instructions/hotel-all-Api-End-point.json' ),true,512,JSON_THROW_ON_ERROR );
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes',FilesystemIterator::SKIP_DOTS ) );
$count = 0;
foreach ( $files as $file ) {
	if ( $file->getExtension() !== 'php' || $file->getFilename() === 'Api_Mock.php' ) { continue; }
	$text = file_get_contents( $file->getPathname() );
	preg_match_all( '/->(get|post)\(\s*\x27(api\/v1\/public-booking\/[^\x27]+)\x27/', $text,$matches,PREG_SET_ORDER );
	foreach ( $matches as $match ) {
		$path = '/' . $match[2];
		if ( str_ends_with( $path,'/track/' ) ) { $path .= '{referenceOrPhone}'; }
		if ( ! isset( $schema['paths'][$path][$match[1]] ) ) { throw new RuntimeException( 'Undocumented route: ' . $path ); }
		$count++;
	}
}
echo "$count runtime API calls match the checked-in contract.\n";
