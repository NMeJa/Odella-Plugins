<?php
/**
 * Pure checks for odella_round_up_9. Run before deploy:
 *   wp eval-file tools/test_round9.php
 */

if ( ! function_exists( 'odella_round_up_9' ) ) {
	function odella_round_up_9( $v ) {
		if ( '' === $v || null === $v || ! is_numeric( $v ) ) {
			return $v;
		}
		$v = (float) $v;
		if ( $v <= 0 ) {
			return $v;
		}
		$whole = ceil( $v );
		return (string) ( ceil( ( $whole + 1 ) / 10 ) * 10 - 1 );
	}
}

$cases = array(
	array( 148.3, '149' ),
	array( 149, '149' ),
	array( 149.01, '159' ),
	array( 150.2, '159' ),
	array( 9, '9' ),
	array( 9.5, '19' ),
	array( 0, 0 ),
	array( '', '' ),
);

$failed = 0;
foreach ( $cases as $case ) {
	$in  = $case[0];
	$out = odella_round_up_9( $in );
	$ok  = ( '' === $case[1] ) ? ( '' === $out ) : ( (string) $case[1] === (string) $out );
	if ( ! $ok ) {
		$failed++;
		echo 'FAIL ' . json_encode( $in ) . ' => ' . json_encode( $out ) . ' expected ' . json_encode( $case[1] ) . "\n";
	}
}

if ( $failed ) {
	echo "failed=$failed\n";
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::halt( 1 );
	}
	exit( 1 );
}

echo "passed=" . count( $cases ) . "\n";
