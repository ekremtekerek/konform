<?php
/**
 * Lisans zincirini ucdan uca olcer.
 *
 * Kullanim:
 *   docker compose run --rm wpcli wp eval-file \
 *     wp-content/plugins/konform/tests/plan-probe.php
 *
 * @package Konform
 */

$fs = function_exists( 'konform_fs' ) ? konform_fs() : null;

printf( "konform_fs()            : %s\n", is_object( $fs ) ? get_class( $fs ) : 'yok' );

if ( is_object( $fs ) ) {
	printf( "is_premium (kod)        : %s\n", $fs->is_premium() ? 'true' : 'false' );
	printf( "can_use_premium_code()  : %s\n", $fs->can_use_premium_code() ? 'true' : 'false' );
	printf( "is_registered()         : %s\n", $fs->is_registered() ? 'evet' : 'hayir' );
	printf( "is_paying()             : %s\n", $fs->is_paying() ? 'evet' : 'hayir' );
	printf( "is_trial()              : %s\n", $fs->is_trial() ? 'evet' : 'hayir' );

	$license = $fs->_get_license();
	printf( "lisans                  : %s\n", is_object( $license ) ? 'var (id ' . $license->id . ')' : 'yok' );
}

printf( "Licensing::plan()       : %s\n", Konform\License\Licensing::plan()->value );
printf( "has_hosted_validation() : %s\n", Konform\License\Licensing::has_hosted_validation() ? 'ACIK' : 'kapali' );

$validator = new Konform\Validation\HostedValidator();

printf( "validator yapilandirildi: %s\n", $validator->is_configured() ? 'evet' : 'hayir' );
