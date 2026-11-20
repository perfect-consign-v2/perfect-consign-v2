<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class MissLog
{
    /**
     * @return array{
     *     method: string,
     *     path: string,
     *     reason: string,
     *     target: string,
     *     query_keys: list<string>,
     *     address: string,
     *     host: string,
     *     user_agent: string,
     *     accept: string,
     *     referer: string
     * }
     */
    public static function context(Request $request, string $reason): array
    {
        $keys = [];
        foreach (array_keys($request->query) as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return [
            'method' => $request->method,
            'path' => $request->path,
            'reason' => $reason,
            'target' => $request->trace->target,
            'query_keys' => $keys,
            'address' => $request->address,
            'host' => $request->trace->host,
            'user_agent' => $request->trace->userAgent,
            'accept' => $request->trace->accept,
            'referer' => $request->trace->rawReferer,
        ];
    }
}
