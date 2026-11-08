<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.jengowork

declare(strict_types=1);

namespace WpPluginCheck;

use RuntimeException;

/**
 * The few Jengo Work calls the importer needs, over its ClickUp v2 compatible REST API.
 *
 * Every body is checked for an `err` key: Jengo Work answers some refusals with HTTP 200.
 * A rate limit answer is retried a few times; anything else is an exception, so the importer
 * stops instead of carrying on half-way.
 */
final class JengoWork
{
    private string $base;
    private string $key;
    /** @var callable(string,string,array<string,string>,?string):array{status:int,body:string} */
    private $transport;
    /** @var callable(int):void */
    private $sleep;

    /**
     * @param callable|null $transport fn(method, url, headers, body): array{status:int, body:string}; a test double or null for real HTTP
     * @param callable|null $sleep     fn(seconds): void; null to really sleep
     */
    public function __construct(string $apiBase, string $apiKey, ?callable $transport = null, ?callable $sleep = null)
    {
        $this->base      = rtrim($apiBase, '/');
        $this->key       = $apiKey;
        $this->transport = $transport ?? [self::class, 'httpTransport'];
        $this->sleep     = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Every task of a board, closed ones included.
     *
     * @return list<array<string,mixed>>
     */
    public function listTasks(string $board): array
    {
        $tasks = [];
        $seen  = [];
        for ($page = 0; $page < 60; $page++) {
            $data  = $this->call('GET', "/list/{$board}/task?include_closed=true&page={$page}");
            $batch = $data['tasks'] ?? null;
            if (!is_array($batch)) {
                throw new RuntimeException('Jengo Work returned no task list for board ' . $board);
            }
            $added = 0;
            foreach ($batch as $task) {
                $id = (string) ($task['id'] ?? '');
                if ($id !== '' && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $tasks[]   = $task;
                    $added++;
                }
            }
            if ($added === 0) {
                break;
            }
        }
        return $tasks;
    }

    /** @return array<string,mixed> the created task */
    public function createTask(string $board, string $name, string $description, string $status): array
    {
        $data = $this->call('POST', "/list/{$board}/task", ['name' => $name, 'description' => $description, 'status' => $status]);
        if (empty($data['id'])) {
            throw new RuntimeException('Jengo Work did not return an id for the new task');
        }
        return $data;
    }

    /** @return list<array<string,mixed>> */
    public function comments(string $taskId): array
    {
        $data = $this->call('GET', "/task/{$taskId}/comment");
        if (!isset($data['comments']) || !is_array($data['comments'])) {
            // A rate-limited or stale answer must not read as "no comments": that would post a duplicate.
            throw new RuntimeException("Jengo Work returned no comments key for task {$taskId}");
        }
        return array_values($data['comments']);
    }

    public function comment(string $taskId, string $text): void
    {
        $this->call('POST', "/task/{$taskId}/comment", ['comment_text' => $text]);
    }

    /**
     * @param array<string,mixed>|null $json
     * @return array<string,mixed>
     */
    private function call(string $method, string $path, ?array $json = null): array
    {
        $body = $json === null ? null : json_encode($json, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $res = ($this->transport)($method, $this->base . $path, [
                'Authorization' => $this->key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ], $body);

            $data = json_decode($res['body'], true);
            if (is_array($data) && isset($data['err']) && stripos((string) $data['err'], 'rate limit') !== false && $attempt < 4) {
                ($this->sleep)(10 * $attempt);
                continue;
            }
            if ($res['status'] < 200 || $res['status'] >= 300) {
                throw new RuntimeException("Jengo Work {$method} {$path} answered HTTP {$res['status']}: " . substr($res['body'], 0, 200));
            }
            if (!is_array($data)) {
                throw new RuntimeException("Jengo Work {$method} {$path} did not answer JSON: " . substr($res['body'], 0, 200));
            }
            if (isset($data['err'])) {
                throw new RuntimeException("Jengo Work {$method} {$path} refused: " . (is_string($data['err']) ? $data['err'] : json_encode($data['err'])));
            }
            return $data;
        }
        throw new RuntimeException("Jengo Work {$method} {$path}: rate limit did not clear");
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    public static function httpTransport(string $method, string $url, array $headers, ?string $body): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $lines),
            'content'       => $body ?? '',
            'ignore_errors' => true,
            'timeout'       => 60,
        ]]);
        $text   = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($text === false) {
            throw new RuntimeException("Cannot reach Jengo Work at {$url}");
        }
        return ['status' => $status, 'body' => $text];
    }
}
