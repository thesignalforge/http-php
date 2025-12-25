<?php

declare(strict_types=1);

namespace Signalforge\Http;

use Exception;

/**
 * HTTP Request wrapper providing access to request data.
 *
 * This is a pure PHP implementation matching the signalforge_http C extension.
 * The Request object is immutable and captures the request state at creation time.
 *
 * @internal Created via Request::capture()
 */
final class Request
{
    private const FLAG_BODY_READ = 1;
    private const FLAG_PATH_PARSED = 2;
    private const FLAG_METHOD_RESOLVED = 4;
    private const FLAG_CTYPE_PARSED = 8;
    private const FLAG_INPUT_MERGED = 16;
    private const FLAG_JSON_PARSED = 32;

    /** @var array<string, mixed> */
    private array $server;

    /** @var array<string, mixed> */
    private array $get;

    /** @var array<string, mixed> */
    private array $post;

    /** @var array<string, string> */
    private array $cookie;

    /** @var array<string, array<string, mixed>> */
    private array $files;

    /** @var array<string, string> Normalized headers */
    private array $headers;

    private ?string $requestUri;
    private ?string $queryStringRaw;
    private ?string $requestMethodRaw;

    private int $flags = 0;

    private string $cachedBody = '';
    private string $cachedPath = '';
    private string $cachedMethod = '';
    private ?string $cachedContentType = null;

    /** @var array<string, mixed> */
    private array $cachedInput = [];

    private mixed $cachedJson = null;

    /**
     * Private constructor - use Request::capture() instead.
     *
     * @throws Exception Always throws to prevent direct instantiation
     */
    private function __construct()
    {
        throw new Exception(
            'Cannot instantiate Signalforge\\Http\\Request directly. Use Request::capture() instead.'
        );
    }

    /**
     * Create Request from current SAPI request context.
     *
     * @return self
     * @throws Exception If $_SERVER is not available
     */
    public static function capture(): self
    {
        if (!isset($_SERVER) || !is_array($_SERVER)) {
            throw new Exception('Request::capture() requires $_SERVER to be available.');
        }

        // Bypass private constructor
        $request = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();

        // Store references to superglobals
        $request->server = $_SERVER;
        $request->get = $_GET ?? [];
        $request->post = $_POST ?? [];
        $request->cookie = $_COOKIE ?? [];
        $request->files = $_FILES ?? [];

        // Extract request info from $_SERVER
        $request->requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI']
            : null;

        $request->queryStringRaw = isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING'])
            ? $_SERVER['QUERY_STRING']
            : null;

        $request->requestMethodRaw = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? $_SERVER['REQUEST_METHOD']
            : null;

        // Build normalized headers
        $request->headers = self::extractHeaders($_SERVER);

        return $request;
    }

    /**
     * Extract HTTP headers from $_SERVER and normalize keys.
     *
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            // Process HTTP_* keys
            if (str_starts_with($key, 'HTTP_')) {
                $normalized = self::normalizeHeaderName(substr($key, 5));
                $headers[$normalized] = $value;
            }
            // CONTENT_TYPE (no HTTP_ prefix)
            elseif ($key === 'CONTENT_TYPE') {
                $headers['content-type'] = $value;
            }
            // CONTENT_LENGTH (no HTTP_ prefix)
            elseif ($key === 'CONTENT_LENGTH') {
                $headers['content-length'] = $value;
            }
        }

        return $headers;
    }

    /**
     * Normalize HTTP header name from SERVER format.
     * Example: ACCEPT_LANGUAGE -> accept-language
     */
    private static function normalizeHeaderName(string $name): string
    {
        return strtolower(str_replace('_', '-', $name));
    }

    /**
     * Read request body from php://input.
     */
    private function readBody(): string
    {
        if ($this->flags & self::FLAG_BODY_READ) {
            return $this->cachedBody;
        }

        $this->flags |= self::FLAG_BODY_READ;

        $body = file_get_contents('php://input');
        $this->cachedBody = $body !== false ? $body : '';

        return $this->cachedBody;
    }

    /**
     * Parse path component from REQUEST_URI.
     */
    private function parsePath(): string
    {
        if ($this->flags & self::FLAG_PATH_PARSED) {
            return $this->cachedPath;
        }

        $this->flags |= self::FLAG_PATH_PARSED;

        if ($this->requestUri === null || $this->requestUri === '') {
            $this->cachedPath = '';
            return '';
        }

        // Find query string or fragment
        $pos = strpbrk($this->requestUri, '?#');
        if ($pos !== false) {
            $this->cachedPath = substr($this->requestUri, 0, strpos($this->requestUri, $pos[0]));
        } else {
            $this->cachedPath = $this->requestUri;
        }

        return $this->cachedPath;
    }

    /**
     * Resolve HTTP method considering overrides.
     * Priority: X-HTTP-Method-Override header > _method POST field > REQUEST_METHOD
     */
    private function resolveMethod(): string
    {
        if ($this->flags & self::FLAG_METHOD_RESOLVED) {
            return $this->cachedMethod;
        }

        $this->flags |= self::FLAG_METHOD_RESOLVED;

        // Check X-HTTP-Method-Override header
        if (isset($this->headers['x-http-method-override'])) {
            $this->cachedMethod = strtoupper($this->headers['x-http-method-override']);
            return $this->cachedMethod;
        }

        // Check _method POST field
        if (isset($this->post['_method']) && is_string($this->post['_method'])) {
            $this->cachedMethod = strtoupper($this->post['_method']);
            return $this->cachedMethod;
        }

        // Fall back to REQUEST_METHOD
        $this->cachedMethod = $this->requestMethodRaw ?? 'GET';
        return $this->cachedMethod;
    }

    /**
     * Parse Content-Type header to extract just the MIME type.
     */
    private function parseContentType(): ?string
    {
        if ($this->flags & self::FLAG_CTYPE_PARSED) {
            return $this->cachedContentType;
        }

        $this->flags |= self::FLAG_CTYPE_PARSED;

        if (!isset($this->headers['content-type'])) {
            $this->cachedContentType = null;
            return null;
        }

        $ct = $this->headers['content-type'];

        // Find semicolon that starts parameters
        $semicolon = strpos($ct, ';');
        if ($semicolon !== false) {
            $this->cachedContentType = rtrim(substr($ct, 0, $semicolon));
        } else {
            $this->cachedContentType = $ct;
        }

        return $this->cachedContentType;
    }

    /**
     * Build merged input from JSON body, POST, and GET.
     *
     * @return array<string, mixed>
     */
    private function mergeInput(): array
    {
        if ($this->flags & self::FLAG_INPUT_MERGED) {
            return $this->cachedInput;
        }

        $this->flags |= self::FLAG_INPUT_MERGED;

        // Start with GET params (lowest priority)
        $merged = $this->get;

        // Override with POST params
        foreach ($this->post as $key => $value) {
            $merged[$key] = $value;
        }

        // Override with JSON body (highest priority) if content type is JSON
        $ct = $this->parseContentType();
        if ($ct === 'application/json') {
            $body = $this->readBody();
            if ($body !== '') {
                $json = json_decode($body, true);
                if (is_array($json)) {
                    foreach ($json as $key => $value) {
                        $merged[$key] = $value;
                    }
                }
            }
        }

        $this->cachedInput = $merged;
        return $merged;
    }

    /**
     * Get resolved HTTP method.
     */
    public function method(): string
    {
        return $this->resolveMethod();
    }

    /**
     * Get original HTTP method from REQUEST_METHOD.
     */
    public function realMethod(): string
    {
        return $this->requestMethodRaw ?? 'GET';
    }

    /**
     * Check if method matches (case-insensitive).
     */
    public function isMethod(string $method): bool
    {
        return strcasecmp($this->resolveMethod(), $method) === 0;
    }

    /**
     * Get full REQUEST_URI.
     */
    public function uri(): string
    {
        return $this->requestUri ?? '';
    }

    /**
     * Get path component of URI.
     */
    public function path(): string
    {
        return $this->parsePath();
    }

    /**
     * Get raw query string.
     */
    public function queryString(): ?string
    {
        if ($this->queryStringRaw !== null && $this->queryStringRaw !== '') {
            return $this->queryStringRaw;
        }
        return null;
    }

    /**
     * Get single query parameter.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    /**
     * Check if query parameter exists.
     */
    public function hasQuery(string $key): bool
    {
        return array_key_exists($key, $this->get);
    }

    /**
     * Get all query parameters.
     *
     * @return array<string, mixed>
     */
    public function allQuery(): array
    {
        return $this->get;
    }

    /**
     * Get single POST parameter.
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Check if POST parameter exists.
     */
    public function hasPost(string $key): bool
    {
        return array_key_exists($key, $this->post);
    }

    /**
     * Get all POST parameters.
     *
     * @return array<string, mixed>
     */
    public function allPost(): array
    {
        return $this->post;
    }

    /**
     * Get input from merged sources (JSON > POST > GET).
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $merged = $this->mergeInput();
        return $merged[$key] ?? $default;
    }

    /**
     * Check if input exists in any source.
     */
    public function hasInput(string $key): bool
    {
        $merged = $this->mergeInput();
        return array_key_exists($key, $merged);
    }

    /**
     * Get all input as merged array.
     *
     * @return array<string, mixed>
     */
    public function allInput(): array
    {
        return $this->mergeInput();
    }

    /**
     * Get header value (case-insensitive).
     */
    public function header(string $name, ?string $default = null): ?string
    {
        $normalized = strtolower($name);
        return $this->headers[$normalized] ?? $default;
    }

    /**
     * Check if header exists.
     */
    public function hasHeader(string $name): bool
    {
        $normalized = strtolower($name);
        return isset($this->headers[$normalized]);
    }

    /**
     * Get all headers.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Get cookie value.
     */
    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookie[$name] ?? null;
        if (is_string($value)) {
            return $value;
        }
        return $default;
    }

    /**
     * Check if cookie exists.
     */
    public function hasCookie(string $name): bool
    {
        return isset($this->cookie[$name]);
    }

    /**
     * Get all cookies.
     *
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookie;
    }

    /**
     * Get file upload info.
     *
     * @return array<string, mixed>|null
     */
    public function file(string $name): ?array
    {
        if (isset($this->files[$name]) && is_array($this->files[$name])) {
            return $this->files[$name];
        }
        return null;
    }

    /**
     * Check if file was uploaded.
     */
    public function hasFile(string $name): bool
    {
        return isset($this->files[$name]);
    }

    /**
     * Get all uploaded files.
     *
     * @return array<string, array<string, mixed>>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Get server parameter.
     */
    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * Check if server parameter exists.
     */
    public function hasServer(string $key): bool
    {
        return array_key_exists($key, $this->server);
    }

    /**
     * Get raw request body.
     */
    public function rawBody(): string
    {
        return $this->readBody();
    }

    /**
     * Parse body as JSON.
     *
     * @throws Exception If JSON parsing fails
     */
    public function json(bool $assoc = true, int $depth = 512): mixed
    {
        if ($this->flags & self::FLAG_JSON_PARSED) {
            return $this->cachedJson;
        }

        $body = $this->readBody();

        if ($body === '') {
            return null;
        }

        $this->flags |= self::FLAG_JSON_PARSED;

        $result = json_decode($body, $assoc, $depth);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse JSON body');
        }

        $this->cachedJson = $result;
        return $result;
    }

    /**
     * Get Content-Type MIME type.
     */
    public function contentType(): ?string
    {
        return $this->parseContentType();
    }

    /**
     * Check if Content-Type is JSON.
     */
    public function isJson(): bool
    {
        return $this->parseContentType() === 'application/json';
    }

    /**
     * Check if Content-Type is form submission.
     */
    public function isForm(): bool
    {
        return $this->parseContentType() === 'application/x-www-form-urlencoded';
    }

    /**
     * Check if Content-Type is multipart.
     */
    public function isMultipart(): bool
    {
        $ct = $this->parseContentType();
        return $ct !== null && str_starts_with($ct, 'multipart/');
    }

    /**
     * Check if client accepts MIME type.
     */
    public function accepts(string $type): bool
    {
        $accept = $this->headers['accept'] ?? null;
        if ($accept === null) {
            return false;
        }

        // Simple substring check - not full content negotiation
        return str_contains($accept, $type) || str_contains($accept, '*/*');
    }

    /**
     * Check if request expects JSON response.
     */
    public function expectsJson(): bool
    {
        // Check Accept header
        $accept = $this->headers['accept'] ?? null;
        if ($accept !== null && str_contains($accept, 'application/json')) {
            return true;
        }

        // Check if AJAX request
        $xhr = $this->headers['x-requested-with'] ?? null;
        if ($xhr !== null && strcasecmp($xhr, 'XMLHttpRequest') === 0) {
            return true;
        }

        return false;
    }

    /**
     * Check if request is AJAX.
     */
    public function isAjax(): bool
    {
        $xhr = $this->headers['x-requested-with'] ?? null;
        return $xhr !== null && strcasecmp($xhr, 'XMLHttpRequest') === 0;
    }

    /**
     * Check if connection is HTTPS.
     */
    public function isSecure(): bool
    {
        // Check HTTPS server variable
        $https = $this->server['HTTPS'] ?? null;
        if (is_string($https) && $https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }

        // Check REQUEST_SCHEME
        $scheme = $this->server['REQUEST_SCHEME'] ?? null;
        return $scheme === 'https';
    }

    /**
     * Get client IP address.
     */
    public function clientIp(): string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? null;
        if (is_string($ip)) {
            return $ip;
        }
        return '127.0.0.1';
    }

    /**
     * Get request host.
     */
    public function host(): string
    {
        // Try Host header first
        $host = $this->headers['host'] ?? null;
        if ($host !== null) {
            // Strip port if present
            $colonPos = strpos($host, ':');
            if ($colonPos !== false) {
                return substr($host, 0, $colonPos);
            }
            return $host;
        }

        // Fall back to SERVER_NAME
        $serverName = $this->server['SERVER_NAME'] ?? null;
        if (is_string($serverName)) {
            return $serverName;
        }

        return 'localhost';
    }

    /**
     * Get request port.
     */
    public function port(): int
    {
        $port = $this->server['SERVER_PORT'] ?? null;

        if (is_int($port)) {
            return $port;
        }
        if (is_string($port)) {
            return (int) $port;
        }

        // Default based on scheme
        $scheme = $this->server['REQUEST_SCHEME'] ?? null;
        if ($scheme === 'https') {
            return 443;
        }

        return 80;
    }

    /**
     * Get request scheme (http/https).
     */
    public function scheme(): string
    {
        $scheme = $this->server['REQUEST_SCHEME'] ?? null;
        if (is_string($scheme)) {
            return $scheme;
        }

        // Check HTTPS
        $https = $this->server['HTTPS'] ?? null;
        if (is_string($https) && $https !== '' && $https !== 'off' && $https !== '0') {
            return 'https';
        }

        return 'http';
    }
}
