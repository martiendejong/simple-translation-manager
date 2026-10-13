<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.bin.phpfloor

/**
 * Evidence for the "Requires PHP" header (task 5144): does the shipped code use anything newer than PHP 7.4?
 *
 * Development-only: excluded from the plugin ZIP. It reads the PHP files of a release ZIP (or a folder) with
 * the PHP-Parser from the dev dependencies and lists PHP 8.0+ language features (nullsafe, match, union types,
 * constructor promotion, named arguments, attributes, enums, throw expressions, catch without variable,
 * readonly, `mixed`/`never`/`static` types) and calls to functions added after 7.4.
 *
 * What this is not: it does not run the plugin on PHP 7.4 (this host has PHP 8.5 only), so it cannot find a
 * changed behaviour of an old function. It finds the two things that break a 7.4 site at once: a parse error
 * and a call to a function that does not exist there.
 *
 * Usage: php bin/php-floor.php <release.zip|folder>
 * Exit code: 0 nothing newer than 7.4 found, 1 something found, 2 unreadable input.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/tools/wp-plugin-check/src/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/** Functions added in PHP 8.0 to 8.4. WordPress 5.9+ polyfills the first three, so they would not break a site, but they are still listed. */
const STM_PF_NEW_FUNCTIONS = [
    'str_contains', 'str_starts_with', 'str_ends_with', 'get_debug_type', 'get_resource_id', 'fdiv', 'preg_last_error_msg',
    'array_is_list', 'enum_exists', 'fsync', 'fdatasync',
    'json_validate', 'mb_str_pad', 'str_increment', 'str_decrement', 'stream_context_set_options',
    'array_find', 'array_find_key', 'array_any', 'array_all', 'mb_trim', 'mb_ltrim', 'mb_rtrim', 'mb_ucfirst', 'mb_lcfirst',
];

/**
 * @param array<string,string> $files path => PHP source
 * @return array{files:int,parse_errors:array<string,string>,language:array<string,list<string>>,functions:array<string,list<string>>,ok:bool}
 */
function stm_php_floor( array $files ): array {
    $parser   = ( new ParserFactory() )->createForNewestSupportedVersion();
    $language = [];
    $calls    = [];
    $errors   = [];
    $visitor  = new class() extends NodeVisitorAbstract {
        /** @var array<string,list<string>> */
        public $language = [];
        /** @var array<string,list<string>> */
        public $calls = [];
        /** @var string */
        public $file = '';

        private function add( string $what, Node $node ): void {
            $this->language[ $what ][] = $this->file . ':' . $node->getStartLine();
        }

        public function enterNode( Node $n ) {
            if ( $n instanceof Node\Expr\NullsafePropertyFetch || $n instanceof Node\Expr\NullsafeMethodCall ) {
                $this->add( 'nullsafe operator', $n );
            }
            if ( $n instanceof Node\Expr\Match_ ) {
                $this->add( 'match expression', $n );
            }
            if ( $n instanceof Node\UnionType || $n instanceof Node\IntersectionType ) {
                $this->add( 'union or intersection type', $n );
            }
            if ( $n instanceof Node\Param && $n->flags ) {
                $this->add( 'constructor promotion', $n );
            }
            if ( $n instanceof Node\Param && ( $n->type instanceof Node\Identifier || $n->type instanceof Node\Name ) && 'mixed' === strtolower( $n->type->toString() ) ) {
                $this->add( 'mixed type', $n );
            }
            if ( $n instanceof Node\Arg && $n->name ) {
                $this->add( 'named argument', $n );
            }
            if ( $n instanceof Node\AttributeGroup ) {
                $this->add( 'attribute', $n );
            }
            if ( $n instanceof Node\Stmt\Enum_ ) {
                $this->add( 'enum', $n );
            }
            // `throw` as a statement is fine in 7.4; only `throw` used as an expression is new.
            if ( $n instanceof Node\Stmt\Expression && $n->expr instanceof Node\Expr\Throw_ ) {
                $n->expr->setAttribute( 'stm_statement', true );
            }
            if ( $n instanceof Node\Expr\Throw_ && ! $n->getAttribute( 'stm_statement' ) ) {
                $this->add( 'throw expression', $n );
            }
            if ( $n instanceof Node\Stmt\Catch_ && null === $n->var ) {
                $this->add( 'catch without variable', $n );
            }
            if ( $n instanceof Node\Stmt\Property && $n->isReadonly() ) {
                $this->add( 'readonly property', $n );
            }
            if ( $n instanceof Node\Expr\ClassConstFetch && $n->name instanceof Node\Identifier && 'class' === $n->name->toString() && $n->class instanceof Node\Expr ) {
                $this->add( '::class on an object', $n );
            }
            if ( $n instanceof Node\Stmt\ClassMethod || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction ) {
                $rt = $n->returnType;
                if ( ( $rt instanceof Node\Identifier || $rt instanceof Node\Name ) && in_array( strtolower( $rt->toString() ), [ 'mixed', 'never', 'static' ], true ) ) {
                    $this->add( 'return type ' . strtolower( $rt->toString() ), $n );
                }
            }
            if ( $n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name ) {
                $name = strtolower( $n->name->toString() );
                if ( in_array( $name, STM_PF_NEW_FUNCTIONS, true ) ) {
                    $this->calls[ $name ][] = $this->file . ':' . $n->getStartLine();
                }
            }
            return null;
        }
    };

    $traverser = new NodeTraverser();
    $traverser->addVisitor( $visitor );
    ksort( $files );
    foreach ( $files as $path => $source ) {
        $visitor->file = $path;
        try {
            $traverser->traverse( $parser->parse( $source ) ?? [] );
        } catch ( \PhpParser\Error $e ) {
            $errors[ $path ] = $e->getMessage();
        }
    }
    $language = $visitor->language;
    $calls    = $visitor->calls;
    ksort( $language );
    ksort( $calls );

    return [
        'files'        => count( $files ),
        'parse_errors' => $errors,
        'language'     => $language,
        'functions'    => $calls,
        'ok'           => array() === $errors && array() === $language && array() === $calls,
    ];
}

/** @return array<string,string> path => source of every .php file in a ZIP or folder */
function stm_pf_read( string $input ): array {
    $out = [];
    if ( is_dir( $input ) ) {
        $base = rtrim( str_replace( '\\', '/', $input ), '/' );
        $it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $input, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( '.php' === substr( $file->getFilename(), -4 ) ) {
                $out[ substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $base ) + 1 ) ] = (string) file_get_contents( $file->getPathname() );
            }
        }
        return $out;
    }
    $zip = new WpPluginCheck\ZipFile( $input );
    foreach ( $zip->fileNames() as $name ) {
        if ( '.php' === substr( $name, -4 ) ) {
            $out[ $name ] = $zip->read( $name );
        }
    }
    return $out;
}

if ( PHP_SAPI === 'cli' && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
    $input = $argv[1] ?? '';
    if ( '' === $input || ! file_exists( $input ) ) {
        fwrite( STDERR, "Usage: php bin/php-floor.php <release.zip|folder>\n" );
        exit( 2 );
    }
    try {
        $result = stm_php_floor( stm_pf_read( $input ) );
    } catch ( Throwable $e ) {
        fwrite( STDERR, 'Error: ' . $e->getMessage() . "\n" );
        exit( 2 );
    }
    echo json_encode( [ 'input' => $input, 'php_floor_checked' => '7.4' ] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    exit( $result['ok'] ? 0 : 1 );
}
