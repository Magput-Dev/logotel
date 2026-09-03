<?php

declare(strict_types=1);

namespace Magput\Logotel\Logging;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class RequestPayload
{
    public const QUERY_KEY = 'http.request.query';
    public const BODY_KEY = 'http.request.body';

    public const DEFAULT_BODY_MAX_BYTES = 4096;
    public const TRUNCATED_SUFFIX = '...[truncated]';
    public const OMITTED_BINARY = '[omitted: binary]';

    /**
     * @param ServerRequestInterface $request
     * @return array{http.request.query: string, http.request.body: string}
     */
    public static function fields(ServerRequestInterface $request): array
    {
        return [
            self::QUERY_KEY => self::query($request),
            self::BODY_KEY => self::body($request),
        ];
    }

    /**
     * @param ServerRequestInterface $request
     * @return string
     */
    public static function query(ServerRequestInterface $request): string
    {
        $query = $request->getUri()->getQuery();
        if ($query === '') {
            $params = $request->getQueryParams();
            if ($params !== []) {
                $query = http_build_query($params);
            }
        }

        return SecretRedactor::redact($query);
    }

    /**
     * @param ServerRequestInterface $request
     * @return string
     */
    public static function body(ServerRequestInterface $request): string
    {
        $max = self::maxBodyBytes();
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (self::isBinaryContentType($contentType)) {
            return self::OMITTED_BINARY;
        }

        $parsed = $request->getParsedBody();
        if (is_array($parsed) || is_object($parsed)) {
            $normalized = self::normalizeParsed($parsed);
            $files = $request->getUploadedFiles();
            if ($files !== []) {
                $normalized = array_merge(
                    is_array($normalized) ? $normalized : ['body' => $normalized],
                    ['_files' => self::normalizeParsed($files)],
                );
            }
            $json = json_encode(
                SecretRedactor::redactValue($normalized),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );

            return self::truncate(is_string($json) ? $json : '', $max);
        }

        if (is_string($parsed) && $parsed !== '') {
            return self::truncate(SecretRedactor::redact($parsed), $max);
        }

        return self::truncate(SecretRedactor::redact(self::readStream($request)), $max);
    }

    /**
     * @return int
     */
    public static function maxBodyBytes(): int
    {
        $value = $_ENV['HTTP_LOG_BODY_MAX'] ?? $_SERVER['HTTP_LOG_BODY_MAX'] ?? getenv('HTTP_LOG_BODY_MAX');
        if ($value === false || $value === null || $value === '') {
            return self::DEFAULT_BODY_MAX_BYTES;
        }

        $bytes = (int)$value;

        return $bytes > 0 ? $bytes : self::DEFAULT_BODY_MAX_BYTES;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalizeParsed(mixed $value): mixed
    {
        if ($value instanceof UploadedFileInterface) {
            return [
                'clientFilename' => $value->getClientFilename(),
                'size' => $value->getSize(),
                'mediaType' => $value->getClientMediaType(),
            ];
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::normalizeParsed($item);
            }

            return $out;
        }

        if (is_object($value)) {
            $encoded = json_encode($value);
            if (is_string($encoded)) {
                $decoded = json_decode($encoded, true);
                if (is_array($decoded)) {
                    return self::normalizeParsed($decoded);
                }
            }
        }

        return $value;
    }

    /**
     * @param ServerRequestInterface $request
     * @return string
     */
    private static function readStream(ServerRequestInterface $request): string
    {
        $stream = $request->getBody();
        if (!$stream->isSeekable()) {
            return '';
        }

        $position = $stream->tell();
        $stream->rewind();
        $raw = $stream->getContents();
        $stream->seek($position);

        return $raw;
    }

    /**
     * @param string $contentType
     * @return bool
     */
    private static function isBinaryContentType(string $contentType): bool
    {
        $type = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($type === 'application/octet-stream') {
            return true;
        }

        foreach (['image/', 'audio/', 'video/'] as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $value
     * @param int $max
     * @return string
     */
    private static function truncate(string $value, int $max): string
    {
        if ($max <= 0 || strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max) . self::TRUNCATED_SUFFIX;
    }
}
