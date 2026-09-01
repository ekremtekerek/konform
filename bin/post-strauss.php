<?php
/**
 * Strauss sonrası düzeltmeler.
 *
 * Strauss tek başına yeterli değildir; üç boşluğu bu betik kapatır.
 *
 * 1. METADATA ÖNEKLEME
 *    Strauss yalnızca .php dosyalarını işler. horstoeko/zugferd ise sınıf
 *    eşlemelerini 399 adet .yml dosyasında taşır ve jms/serializer bu
 *    dosyaların anahtarlarının gerçek sınıf adlarıyla birebir eşleşmesini
 *    bekler. Önekleme yapılmazsa:
 *      InvalidMetadataException: Expected metadata for class
 *      Konform\Vendor\horstoeko\zugferd\...\CrossIndustryInvoiceType
 *
 * 2. ÖNEKSİZ KOPYALARIN SİLİNMESİ
 *    Strauss'un delete_vendor_packages seçeneği kapalıdır; çünkü açıkken
 *    Strauss çalışma anında dosya yazmaya çalışan bir autoload_aliases
 *    katmanı üretiyor ve bu, üretim ortamında izin hatası veriyor.
 *    Silmeyi bu yüzden kendimiz yapıyoruz.
 *
 * 3. ALIAS KATMANININ KALDIRILMASI
 *    Önceki çalıştırmalardan kalmış olabilir.
 *
 * Yapının zorunlu adımıdır; composer.json'daki "strauss" betiğinden çağrılır.
 * Tekrar çalıştırılabilir (idempotent).
 *
 * Çalıştır: php bin/post-strauss.php
 *
 * @package Konform
 */

declare( strict_types = 1 );

$plugin_dir    = dirname( __DIR__ ) . '/plugin/konform';
$composer_file = $plugin_dir . '/composer.json';

if ( ! is_readable( $composer_file ) ) {
	fwrite( STDERR, "composer.json okunamadi: {$composer_file}\n" );
	exit( 1 );
}

$composer = json_decode( (string) file_get_contents( $composer_file ), true );
$strauss  = $composer['extra']['strauss'] ?? array();

$prefix = rtrim( (string) ( $strauss['namespace_prefix'] ?? '' ), '\\' ) . '\\';
$target = $plugin_dir . '/' . trim( (string) ( $strauss['target_directory'] ?? 'vendor-prefixed' ), '/' );

if ( '\\' === $prefix ) {
	fwrite( STDERR, "extra.strauss.namespace_prefix tanimli degil.\n" );
	exit( 1 );
}

if ( ! is_dir( $target ) ) {
	fwrite( STDERR, "Hedef dizin yok: {$target}\nOnce 'composer strauss' calistirin.\n" );
	exit( 1 );
}

/**
 * Dizini yinelemeli olarak gezer.
 *
 * @param string $dir Dizin.
 * @return RecursiveIteratorIterator<RecursiveDirectoryIterator>
 */
function konform_walk( string $dir ): RecursiveIteratorIterator {
	return new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
}

/**
 * Öneklenmiş PHP dosyalarından kök ad alanlarını toplar.
 *
 * Listeyi elle tutmak yerine gerçek çıktıdan türetmek, yeni bir bağımlılık
 * eklendiğinde betiğin sessizce eksik kalmasını engeller.
 *
 * @param string $target Öneklenmiş dizin.
 * @param string $prefix Ad alanı öneki.
 * @return string[]
 */
function konform_collect_roots( string $target, string $prefix ): array {
	$roots   = array();
	$pattern = '/^\s*namespace\s+' . preg_quote( $prefix, '/' ) . '([A-Za-z_][A-Za-z0-9_]*)/m';

	foreach ( konform_walk( $target ) as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}

		if ( preg_match_all( $pattern, (string) file_get_contents( $file->getPathname() ), $matches ) ) {
			foreach ( $matches[1] as $root ) {
				$roots[ $root ] = true;
			}
		}
	}

	return array_keys( $roots );
}

/**
 * Dizini yinelemeli siler.
 *
 * @param string $dir Dizin.
 * @return bool
 */
function konform_rmdir( string $dir ): bool {
	if ( ! is_dir( $dir ) ) {
		return true;
	}

	foreach ( konform_walk( $dir ) as $item ) {
		$ok = $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );

		if ( ! $ok ) {
			return false;
		}
	}

	return @rmdir( $dir );
}

// --- 1. Metadata onekleme -----------------------------------------------

$roots = konform_collect_roots( $target, $prefix );

if ( array() === $roots ) {
	fwrite( STDERR, "Onekli ad alani bulunamadi. Strauss calisti mi?\n" );
	exit( 1 );
}

$metadata_extensions = array( 'yml', 'yaml', 'xml' );
$changed_files       = 0;

foreach ( konform_walk( $target ) as $file ) {
	if ( ! $file->isFile() || ! in_array( strtolower( $file->getExtension() ), $metadata_extensions, true ) ) {
		continue;
	}

	$path     = $file->getPathname();
	$original = (string) file_get_contents( $path );
	$updated  = $original;

	foreach ( $roots as $root ) {
		/*
		 * Yalnizca onekli OLMAYAN referanslar degistirilir; negatif lookbehind
		 * betigin tekrar calistirilabilir olmasini saglar.
		 */
		$search = '/(?<![A-Za-z0-9_\\\\])(?<!' . preg_quote( rtrim( $prefix, '\\' ), '/' ) . '\\\\)'
			. preg_quote( $root, '/' ) . '\\\\/';

		$updated = (string) preg_replace( $search, str_replace( '\\', '\\\\', $prefix . $root ) . '\\\\', $updated );
	}

	if ( $updated !== $original ) {
		file_put_contents( $path, $updated );
		++$changed_files;
	}
}

// --- 2. Oneksiz kopyalari sil -------------------------------------------

$vendor  = $plugin_dir . '/vendor';
$removed = array();
$failed  = array();

foreach ( (array) glob( $target . '/*', GLOB_ONLYDIR ) as $vendor_dir ) {
	foreach ( (array) glob( $vendor_dir . '/*', GLOB_ONLYDIR ) as $package_dir ) {
		$relative = basename( dirname( $package_dir ) ) . '/' . basename( $package_dir );
		$original = $vendor . '/' . $relative;

		if ( ! is_dir( $original ) ) {
			continue;
		}

		if ( konform_rmdir( $original ) ) {
			$removed[] = $relative;
		} else {
			$failed[] = $relative;
		}
	}
}

// Bos kalan saglayici dizinlerini temizle.
foreach ( (array) glob( $vendor . '/*', GLOB_ONLYDIR ) as $vendor_dir ) {
	if ( array() === array_diff( (array) scandir( $vendor_dir ), array( '.', '..' ) ) ) {
		@rmdir( $vendor_dir );
	}
}

// --- 3. Alias katmanini kaldir ------------------------------------------

$aliases_removed = false;

foreach ( array( 'autoload_aliases.php', 'autoload_alias.php' ) as $name ) {
	$path = $vendor . '/composer/' . $name;

	if ( file_exists( $path ) && @unlink( $path ) ) {
		$aliases_removed = true;
	}
}

// --- Rapor ---------------------------------------------------------------

printf( 'Onek         : %s%s', $prefix, PHP_EOL );
printf( 'Kok ad alani : %s%s', implode( ', ', $roots ), PHP_EOL );
printf( 'Metadata     : %d dosya guncellendi%s', $changed_files, PHP_EOL );
printf( 'Silinen paket: %d%s', count( $removed ), PHP_EOL );
printf( 'Alias katmani: %s%s', $aliases_removed ? 'kaldirildi' : 'yok', PHP_EOL );

if ( array() !== $failed ) {
	printf( '%sUYARI: %d paket silinemedi:%s', PHP_EOL, count( $failed ), PHP_EOL );

	foreach ( $failed as $package ) {
		printf( '  %s%s', $package, PHP_EOL );
	}

	fwrite(
		STDERR,
		PHP_EOL . 'Windows bind mount uzerinde silme basarisiz olabilir; ' .
		'kabuktan "rm -rf plugin/konform/vendor/<paket>" ile temizleyin.' . PHP_EOL
	);
}
