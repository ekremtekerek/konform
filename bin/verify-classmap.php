<?php
/**
 * Dagitilan pakette her onekli sinifin otomatik yukleyiciden cozulup
 * cozulmedigini dogrular.
 *
 * Konform\Vendor\* siniflari YALNIZCA classmap uzerinden cozulur; psr-4
 * yedegi yoktur. Budanmis bir classmap, calisma aninda olumcul hata demektir.
 */

$root = $argv[1] ?? '';

if ( '' === $root || ! is_dir( $root ) ) {
	fwrite( STDERR, "kullanim: verify-classmap.php <paket-koku>\n" );
	exit( 2 );
}

$classmap = require $root . '/vendor/composer/autoload_classmap.php';

$declared = array();
$missing  = array();

$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root . '/vendor-prefixed', FilesystemIterator::SKIP_DOTS )
);

foreach ( $files as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	$source = (string) file_get_contents( $file->getPathname() );

	if ( ! preg_match( '/^namespace\s+([^;]+);/m', $source, $ns ) ) {
		continue;
	}

	if ( ! preg_match( '/^(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $source, $cls ) ) {
		continue;
	}

	$fqcn = trim( $ns[1] ) . '\\' . $cls[1];

	$declared[] = $fqcn;

	if ( ! isset( $classmap[ $fqcn ] ) ) {
		$missing[] = $fqcn;
	}
}

printf( "Pakette tanimli sinif : %d\n", count( $declared ) );
printf( "Classmap'te bulunan   : %d\n", count( $declared ) - count( $missing ) );
printf( "EKSIK                 : %d\n", count( $missing ) );

foreach ( array_slice( $missing, 0, 10 ) as $fqcn ) {
	printf( "  - %s\n", $fqcn );
}

exit( array() === $missing ? 0 : 1 );
