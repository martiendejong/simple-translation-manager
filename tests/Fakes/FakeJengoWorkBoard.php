<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.fakes-fakejengoworkboard


/**
 * An in-memory Jengo Work board that answers the few ClickUp-v2 style calls the importer makes.
 * It is the "transport" of WpPluginCheck\JengoWork, so the importer is tested end to end
 * (find tasks by marker, create, comment) without any network.
 */

namespace STM\Tests\Fakes;

class FakeJengoWorkBoard {

    /** @var array<string,array<string,mixed>> id => task */
    public array $tasks = [];

    /** @var array<string,list<array<string,mixed>>> task id => comments */
    public array $comments = [];

    /** @var list<array{method:string,path:string,body:?array}> every request, in order */
    public array $requests = [];

    private int $nextId = 9000;
    private int $nextCommentId = 1;
    private int $clock = 1_800_000_000_000;
    public int $pageSize = 3;

    /** When set, the next calls are answered with this body (HTTP 200) before returning to normal. @var list<string> */
    public array $queuedBodies = [];

    public function __invoke( string $method, string $url, array $headers, ?string $body ): array {
        $path = (string) parse_url( $url, PHP_URL_PATH );
        $path = preg_replace( '#^/api/v2#', '', $path );
        parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
        $json = $body !== null && $body !== '' ? json_decode( $body, true ) : null;
        $this->requests[] = [ 'method' => $method, 'path' => $path, 'body' => is_array( $json ) ? $json : null ];

        if ( $this->queuedBodies ) {
            return [ 'status' => 200, 'body' => array_shift( $this->queuedBodies ) ];
        }

        if ( $method === 'GET' && preg_match( '#^/list/([^/]+)/task$#', $path ) ) {
            $page  = (int) ( $query['page'] ?? 0 );
            $slice = array_slice( array_values( $this->tasks ), $page * $this->pageSize, $this->pageSize );
            return $this->ok( [ 'tasks' => $slice ] );
        }
        if ( $method === 'POST' && preg_match( '#^/list/([^/]+)/task$#', $path ) ) {
            $id = (string) ( $this->nextId++ );
            $this->tasks[ $id ] = [
                'id'          => $id,
                'name'        => $json['name'] ?? '',
                'description' => $json['description'] ?? '',
                'status'      => [ 'status' => $json['status'] ?? 'backlog' ],
                'url'         => 'https://tasks.example.test/task/' . $id,
            ];
            return $this->ok( $this->tasks[ $id ] );
        }
        if ( $method === 'GET' && preg_match( '#^/task/([^/]+)/comment$#', $path, $m ) ) {
            return $this->ok( [ 'comments' => $this->comments[ $m[1] ] ?? [] ] );
        }
        if ( $method === 'POST' && preg_match( '#^/task/([^/]+)/comment$#', $path, $m ) ) {
            $this->comments[ $m[1] ][] = [ 'id' => $this->nextCommentId++, 'date' => (string) ( $this->clock += 1000 ), 'comment_text' => $json['comment_text'] ?? '' ];
            return $this->ok( [ 'id' => $this->nextCommentId - 1 ] );
        }
        return [ 'status' => 404, 'body' => '{"err":"no such route in the fake"}' ];
    }

    /** Add a task a person (or an earlier import) created. */
    public function addTask( string $description, string $status = 'backlog', string $name = 'Existing task' ): string {
        $id = (string) ( $this->nextId++ );
        $this->tasks[ $id ] = [ 'id' => $id, 'name' => $name, 'description' => $description, 'status' => [ 'status' => $status ], 'url' => 'https://tasks.example.test/task/' . $id ];
        return $id;
    }

    public function setStatus( string $id, string $status ): void {
        $this->tasks[ $id ]['status'] = [ 'status' => $status ];
    }

    /** @return list<array{method:string,path:string,body:?array}> */
    public function writes(): array {
        return array_values( array_filter( $this->requests, static fn( array $r ): bool => $r['method'] !== 'GET' ) );
    }

    public function clearRequests(): void {
        $this->requests = [];
    }

    /** @param array<string,mixed> $data */
    private function ok( array $data ): array {
        return [ 'status' => 200, 'body' => json_encode( $data ) ];
    }
}
