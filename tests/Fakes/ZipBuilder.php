<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.fakes-zipbuilder


/**
 * Writes small ZIP files (stored or deflated) without ext-zip, for tests of the
 * scanner tooling. Entry names ending in "/" become directory entries.
 */

namespace STM\Tests\Fakes;

class ZipBuilder {

    /**
     * @param array<string,string> $files name => content
     */
    public static function build( array $files, bool $deflate = true ): string {
        $out     = '';
        $central = '';
        $count   = 0;

        foreach ( $files as $name => $content ) {
            $isDir  = substr( $name, -1 ) === '/';
            $data   = $isDir ? '' : $content;
            $method = ( $deflate && !$isDir && $data !== '' ) ? 8 : 0;
            $packed = $method === 8 ? gzdeflate( $data ) : $data;
            $crc    = $isDir ? 0 : ( crc32( $data ) & 0xFFFFFFFF );
            $offset = strlen( $out );

            $out .= "PK\x03\x04" . pack( 'v', 20 ) . pack( 'v', 0 ) . pack( 'v', $method )
                . pack( 'v', 0 ) . pack( 'v', 0x21 )
                . pack( 'V', $crc ) . pack( 'V', strlen( $packed ) ) . pack( 'V', strlen( $data ) )
                . pack( 'v', strlen( $name ) ) . pack( 'v', 0 ) . $name . $packed;

            $central .= "PK\x01\x02" . pack( 'v', 20 ) . pack( 'v', 20 ) . pack( 'v', 0 ) . pack( 'v', $method )
                . pack( 'v', 0 ) . pack( 'v', 0x21 )
                . pack( 'V', $crc ) . pack( 'V', strlen( $packed ) ) . pack( 'V', strlen( $data ) )
                . pack( 'v', strlen( $name ) ) . pack( 'v', 0 ) . pack( 'v', 0 ) . pack( 'v', 0 ) . pack( 'v', 0 )
                . pack( 'V', $isDir ? 0x10 : 0 ) . pack( 'V', $offset ) . $name;
            $count++;
        }

        return $out . $central . "PK\x05\x06" . pack( 'v', 0 ) . pack( 'v', 0 ) . pack( 'v', $count ) . pack( 'v', $count )
            . pack( 'V', strlen( $central ) ) . pack( 'V', strlen( $out ) ) . pack( 'v', 0 );
    }

    /** Write the ZIP to a new temp file and return its path. */
    public static function file( array $files, bool $deflate = true ): string {
        $path = sys_get_temp_dir() . '/stm-zip-' . bin2hex( random_bytes( 6 ) ) . '.zip';
        file_put_contents( $path, self::build( $files, $deflate ) );
        return $path;
    }
}
