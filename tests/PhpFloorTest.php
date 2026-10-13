<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.phpfloortest

/**
 * PHPUnit tests: bin/php-floor.php, the evidence behind "Requires PHP: 7.4" (task 5144). Every PHP 8.0+
 * feature the script claims to find is planted once, so a clean answer on the real release ZIP means
 * something; plain 7.4 code, including a `throw` statement, must not be flagged.
 */

namespace STM\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/bin/php-floor.php';

class PhpFloorTest extends TestCase {

    private function run_floor( string $php ): array {
        return stm_php_floor( [ 'sample.php' => $php ] );
    }

    public function test_plain_php_74_code_is_ok() {
        $result = $this->run_floor( <<<'PHP'
<?php
declare( strict_types=1 );
class Sample {
    private ?int $count = null;
    public function add( int ...$numbers ): int {
        $total = array_sum( $numbers );
        $fn    = fn( $x ) => $x * 2;
        try {
            if ( $total < 0 ) {
                throw new \InvalidArgumentException( 'negative' );
            }
        } catch ( \Exception $e ) {
            return 0;
        }
        return $fn( $total ) ?? 0;
    }
}
PHP );
        $this->assertTrue( $result['ok'] );
        $this->assertSame( [], $result['language'] );
        $this->assertSame( [], $result['functions'] );
        $this->assertSame( [], $result['parse_errors'] );
        $this->assertSame( 1, $result['files'] );
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function newer_features(): array {
        return [
            'nullsafe'              => [ '<?php $a = $b?->c;', 'nullsafe operator' ],
            'match'                 => [ '<?php $a = match ( 1 ) { 1 => 2, default => 3 };', 'match expression' ],
            'union type'            => [ '<?php function f( int|string $x ) {}', 'union or intersection type' ],
            'constructor promotion' => [ '<?php class A { public function __construct( private int $x ) {} }', 'constructor promotion' ],
            'mixed parameter'       => [ '<?php function f( mixed $x ) {}', 'mixed type' ],
            'named argument'        => [ '<?php strlen( string: "a" );', 'named argument' ],
            'attribute'             => [ '<?php #[Deprecated] function f() {}', 'attribute' ],
            'enum'                  => [ '<?php enum Suit { case Hearts; }', 'enum' ],
            'throw expression'      => [ '<?php $a = $b ?? throw new Exception( "x" );', 'throw expression' ],
            'catch without variable' => [ '<?php try { f(); } catch ( Exception ) {}', 'catch without variable' ],
            'readonly property'     => [ '<?php class A { public readonly int $x; }', 'readonly property' ],
            'class on object'       => [ '<?php $a = $obj::class;', '::class on an object' ],
            'static return type'    => [ '<?php class A { public function f(): static { return $this; } }', 'return type static' ],
            'never return type'     => [ '<?php function f(): never { exit; }', 'return type never' ],
        ];
    }

    #[DataProvider( 'newer_features' )]
    public function test_every_php_80_feature_is_found( string $php, string $label ) {
        $result = $this->run_floor( $php );

        $this->assertFalse( $result['ok'], $label );
        $this->assertArrayHasKey( $label, $result['language'] );
        $this->assertStringStartsWith( 'sample.php:', $result['language'][ $label ][0] );
    }

    public function test_a_throw_statement_is_not_a_throw_expression() {
        $result = $this->run_floor( '<?php function f() { throw new Exception( "x" ); }' );
        $this->assertTrue( $result['ok'] );
    }

    public function test_calls_to_functions_added_after_74_are_listed_with_their_location() {
        $result = $this->run_floor( "<?php\n\$a = str_contains( 'abc', 'b' );\n\$b = array_is_list( [] );\n\$c = strlen( 'x' );\n" );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( [ 'array_is_list' => [ 'sample.php:3' ], 'str_contains' => [ 'sample.php:2' ] ], $result['functions'] );
    }

    public function test_a_file_that_does_not_parse_is_reported_not_skipped() {
        $result = $this->run_floor( '<?php function ( {' );

        $this->assertFalse( $result['ok'] );
        $this->assertArrayHasKey( 'sample.php', $result['parse_errors'] );
    }

    public function test_the_script_reads_a_folder_and_only_php_files() {
        $dir = sys_get_temp_dir() . '/stm-floor-test-' . bin2hex( random_bytes( 4 ) );
        mkdir( $dir . '/sub', 0777, true );
        file_put_contents( $dir . '/a.php', '<?php echo 1;' );
        file_put_contents( $dir . '/sub/b.php', '<?php $x = $y?->z;' );
        file_put_contents( $dir . '/readme.txt', 'match' );

        try {
            $files = stm_pf_read( $dir );
            $this->assertSame( [ 'a.php', 'sub/b.php' ], array_keys( $files ) );
            $this->assertArrayHasKey( 'nullsafe operator', stm_php_floor( $files )['language'] );
        } finally {
            unlink( $dir . '/a.php' );
            unlink( $dir . '/sub/b.php' );
            unlink( $dir . '/readme.txt' );
            rmdir( $dir . '/sub' );
            rmdir( $dir );
        }
    }
}
