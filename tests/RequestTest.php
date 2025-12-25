<?php

declare(strict_types=1);

namespace Signalforge\Http\Tests;

use PHPUnit\Framework\TestCase;
use Signalforge\Http\Request;
use Error;
use ReflectionClass;

final class RequestTest extends TestCase
{
    private function createRequest(
        array $server = [],
        array $get = [],
        array $post = [],
        array $cookie = [],
        array $files = []
    ): Request {
        // Set up superglobals
        $_SERVER = array_merge([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'QUERY_STRING' => '',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $server);
        $_GET = $get;
        $_POST = $post;
        $_COOKIE = $cookie;
        $_FILES = $files;

        return Request::capture();
    }

    public function testCaptureCreatesRequest(): void
    {
        $request = $this->createRequest();

        $this->assertInstanceOf(Request::class, $request);
    }

    public function testDirectInstantiationThrowsError(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('private');

        new Request();
    }

    public function testMethod(): void
    {
        $request = $this->createRequest(['REQUEST_METHOD' => 'POST']);

        $this->assertSame('POST', $request->method());
        $this->assertSame('POST', $request->realMethod());
    }

    public function testMethodOverrideViaHeader(): void
    {
        $request = $this->createRequest([
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_HTTP_METHOD_OVERRIDE' => 'PUT',
        ]);

        $this->assertSame('PUT', $request->method());
        $this->assertSame('POST', $request->realMethod());
    }

    public function testMethodOverrideViaPostField(): void
    {
        $request = $this->createRequest(
            ['REQUEST_METHOD' => 'POST'],
            [],
            ['_method' => 'DELETE']
        );

        $this->assertSame('DELETE', $request->method());
        $this->assertSame('POST', $request->realMethod());
    }

    public function testMethodOverrideHeaderTakesPrecedence(): void
    {
        $request = $this->createRequest(
            [
                'REQUEST_METHOD' => 'POST',
                'HTTP_X_HTTP_METHOD_OVERRIDE' => 'PUT',
            ],
            [],
            ['_method' => 'DELETE']
        );

        $this->assertSame('PUT', $request->method());
    }

    public function testIsMethod(): void
    {
        $request = $this->createRequest(['REQUEST_METHOD' => 'POST']);

        $this->assertTrue($request->isMethod('POST'));
        $this->assertTrue($request->isMethod('post'));
        $this->assertTrue($request->isMethod('Post'));
        $this->assertFalse($request->isMethod('GET'));
    }

    public function testUri(): void
    {
        $request = $this->createRequest([
            'REQUEST_URI' => '/users?page=1',
        ]);

        $this->assertSame('/users?page=1', $request->uri());
    }

    public function testPath(): void
    {
        $request = $this->createRequest([
            'REQUEST_URI' => '/users?page=1',
        ]);

        $this->assertSame('/users', $request->path());
    }

    public function testPathWithFragment(): void
    {
        $request = $this->createRequest([
            'REQUEST_URI' => '/docs#section-1',
        ]);

        $this->assertSame('/docs', $request->path());
    }

    public function testQueryString(): void
    {
        $request = $this->createRequest([
            'QUERY_STRING' => 'page=1&limit=10',
        ]);

        $this->assertSame('page=1&limit=10', $request->queryString());
    }

    public function testQueryStringReturnsNullWhenEmpty(): void
    {
        $request = $this->createRequest([
            'QUERY_STRING' => '',
        ]);

        $this->assertNull($request->queryString());
    }

    public function testQuery(): void
    {
        $request = $this->createRequest(
            [],
            ['page' => '1', 'limit' => '10']
        );

        $this->assertSame('1', $request->query('page'));
        $this->assertSame('10', $request->query('limit'));
        $this->assertNull($request->query('nonexistent'));
        $this->assertSame('default', $request->query('nonexistent', 'default'));
    }

    public function testHasQuery(): void
    {
        $request = $this->createRequest(
            [],
            ['page' => '1']
        );

        $this->assertTrue($request->hasQuery('page'));
        $this->assertFalse($request->hasQuery('nonexistent'));
    }

    public function testAllQuery(): void
    {
        $get = ['page' => '1', 'limit' => '10'];
        $request = $this->createRequest([], $get);

        $this->assertSame($get, $request->allQuery());
    }

    public function testPost(): void
    {
        $request = $this->createRequest(
            ['REQUEST_METHOD' => 'POST'],
            [],
            ['name' => 'John', 'email' => 'john@example.com']
        );

        $this->assertSame('John', $request->post('name'));
        $this->assertSame('john@example.com', $request->post('email'));
        $this->assertNull($request->post('nonexistent'));
        $this->assertSame('default', $request->post('nonexistent', 'default'));
    }

    public function testHasPost(): void
    {
        $request = $this->createRequest(
            ['REQUEST_METHOD' => 'POST'],
            [],
            ['name' => 'John']
        );

        $this->assertTrue($request->hasPost('name'));
        $this->assertFalse($request->hasPost('nonexistent'));
    }

    public function testAllPost(): void
    {
        $post = ['name' => 'John', 'email' => 'john@example.com'];
        $request = $this->createRequest(
            ['REQUEST_METHOD' => 'POST'],
            [],
            $post
        );

        $this->assertSame($post, $request->allPost());
    }

    public function testInputMergesGetAndPost(): void
    {
        $request = $this->createRequest(
            ['REQUEST_METHOD' => 'POST'],
            ['page' => '1', 'source' => 'get'],
            ['name' => 'John', 'source' => 'post']
        );

        $this->assertSame('1', $request->input('page'));
        $this->assertSame('John', $request->input('name'));
        // POST overrides GET
        $this->assertSame('post', $request->input('source'));
    }

    public function testHasInput(): void
    {
        $request = $this->createRequest(
            [],
            ['page' => '1'],
            ['name' => 'John']
        );

        $this->assertTrue($request->hasInput('page'));
        $this->assertTrue($request->hasInput('name'));
        $this->assertFalse($request->hasInput('nonexistent'));
    }

    public function testAllInput(): void
    {
        $request = $this->createRequest(
            [],
            ['page' => '1'],
            ['name' => 'John']
        );

        $input = $request->allInput();

        $this->assertSame('1', $input['page']);
        $this->assertSame('John', $input['name']);
    }

    public function testHeader(): void
    {
        $request = $this->createRequest([
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CUSTOM' => 'custom-value',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertSame('application/json', $request->header('accept'));
        $this->assertSame('application/json', $request->header('Accept'));
        $this->assertSame('application/json', $request->header('ACCEPT'));
        $this->assertSame('custom-value', $request->header('x-custom'));
        $this->assertSame('application/json', $request->header('content-type'));
        $this->assertNull($request->header('nonexistent'));
        $this->assertSame('default', $request->header('nonexistent', 'default'));
    }

    public function testHasHeader(): void
    {
        $request = $this->createRequest([
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertTrue($request->hasHeader('accept'));
        $this->assertTrue($request->hasHeader('Accept'));
        $this->assertFalse($request->hasHeader('nonexistent'));
    }

    public function testHeaders(): void
    {
        $request = $this->createRequest([
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US',
            'CONTENT_TYPE' => 'text/html',
        ]);

        $headers = $request->headers();

        $this->assertSame('application/json', $headers['accept']);
        $this->assertSame('en-US', $headers['accept-language']);
        $this->assertSame('text/html', $headers['content-type']);
    }

    public function testCookie(): void
    {
        $request = $this->createRequest(
            [],
            [],
            [],
            ['session' => 'abc123', 'token' => 'xyz789']
        );

        $this->assertSame('abc123', $request->cookie('session'));
        $this->assertSame('xyz789', $request->cookie('token'));
        $this->assertNull($request->cookie('nonexistent'));
        $this->assertSame('default', $request->cookie('nonexistent', 'default'));
    }

    public function testHasCookie(): void
    {
        $request = $this->createRequest(
            [],
            [],
            [],
            ['session' => 'abc123']
        );

        $this->assertTrue($request->hasCookie('session'));
        $this->assertFalse($request->hasCookie('nonexistent'));
    }

    public function testCookies(): void
    {
        $cookies = ['session' => 'abc123', 'token' => 'xyz789'];
        $request = $this->createRequest([], [], [], $cookies);

        $this->assertSame($cookies, $request->cookies());
    }

    public function testFile(): void
    {
        $files = [
            'upload' => [
                'name' => 'test.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/phpXXXXXX',
                'error' => UPLOAD_ERR_OK,
                'size' => 1234,
            ],
        ];
        $request = $this->createRequest([], [], [], [], $files);

        $file = $request->file('upload');
        $this->assertIsArray($file);
        $this->assertSame('test.txt', $file['name']);
        $this->assertNull($request->file('nonexistent'));
    }

    public function testHasFile(): void
    {
        $files = [
            'upload' => [
                'name' => 'test.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/phpXXXXXX',
                'error' => UPLOAD_ERR_OK,
                'size' => 1234,
            ],
        ];
        $request = $this->createRequest([], [], [], [], $files);

        $this->assertTrue($request->hasFile('upload'));
        $this->assertFalse($request->hasFile('nonexistent'));
    }

    public function testFiles(): void
    {
        $files = [
            'upload' => [
                'name' => 'test.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/phpXXXXXX',
                'error' => UPLOAD_ERR_OK,
                'size' => 1234,
            ],
        ];
        $request = $this->createRequest([], [], [], [], $files);

        $this->assertSame($files, $request->files());
    }

    public function testServer(): void
    {
        $request = $this->createRequest([
            'DOCUMENT_ROOT' => '/var/www',
        ]);

        $this->assertSame('/var/www', $request->server('DOCUMENT_ROOT'));
        $this->assertNull($request->server('NONEXISTENT'));
        $this->assertSame('default', $request->server('NONEXISTENT', 'default'));
    }

    public function testHasServer(): void
    {
        $request = $this->createRequest([
            'DOCUMENT_ROOT' => '/var/www',
        ]);

        $this->assertTrue($request->hasServer('DOCUMENT_ROOT'));
        $this->assertFalse($request->hasServer('NONEXISTENT'));
    }

    public function testContentType(): void
    {
        $request = $this->createRequest([
            'CONTENT_TYPE' => 'application/json; charset=utf-8',
        ]);

        $this->assertSame('application/json', $request->contentType());
    }

    public function testContentTypeReturnsNullWhenNotSet(): void
    {
        $request = $this->createRequest();

        $this->assertNull($request->contentType());
    }

    public function testIsJson(): void
    {
        $jsonRequest = $this->createRequest([
            'CONTENT_TYPE' => 'application/json',
        ]);
        $htmlRequest = $this->createRequest([
            'CONTENT_TYPE' => 'text/html',
        ]);

        $this->assertTrue($jsonRequest->isJson());
        $this->assertFalse($htmlRequest->isJson());
    }

    public function testIsForm(): void
    {
        $formRequest = $this->createRequest([
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ]);
        $jsonRequest = $this->createRequest([
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertTrue($formRequest->isForm());
        $this->assertFalse($jsonRequest->isForm());
    }

    public function testIsMultipart(): void
    {
        $multipartRequest = $this->createRequest([
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----WebKitFormBoundary',
        ]);
        $jsonRequest = $this->createRequest([
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertTrue($multipartRequest->isMultipart());
        $this->assertFalse($jsonRequest->isMultipart());
    }

    public function testAccepts(): void
    {
        $request = $this->createRequest([
            'HTTP_ACCEPT' => 'text/html, application/json, */*',
        ]);

        $this->assertTrue($request->accepts('application/json'));
        $this->assertTrue($request->accepts('text/html'));
        $this->assertTrue($request->accepts('image/png')); // */* matches
    }

    public function testAcceptsReturnsFalseWithoutHeader(): void
    {
        $request = $this->createRequest();

        $this->assertFalse($request->accepts('application/json'));
    }

    public function testExpectsJson(): void
    {
        $jsonAcceptRequest = $this->createRequest([
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $ajaxRequest = $this->createRequest([
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $normalRequest = $this->createRequest([
            'HTTP_ACCEPT' => 'text/html',
        ]);

        $this->assertTrue($jsonAcceptRequest->expectsJson());
        $this->assertTrue($ajaxRequest->expectsJson());
        $this->assertFalse($normalRequest->expectsJson());
    }

    public function testIsAjax(): void
    {
        $ajaxRequest = $this->createRequest([
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $normalRequest = $this->createRequest();

        $this->assertTrue($ajaxRequest->isAjax());
        $this->assertFalse($normalRequest->isAjax());
    }

    public function testIsSecure(): void
    {
        $httpsRequest = $this->createRequest([
            'HTTPS' => 'on',
        ]);
        $schemeRequest = $this->createRequest([
            'REQUEST_SCHEME' => 'https',
        ]);
        $httpRequest = $this->createRequest([
            'HTTPS' => 'off',
        ]);

        $this->assertTrue($httpsRequest->isSecure());
        $this->assertTrue($schemeRequest->isSecure());
        $this->assertFalse($httpRequest->isSecure());
    }

    public function testClientIp(): void
    {
        $request = $this->createRequest([
            'REMOTE_ADDR' => '192.168.1.100',
        ]);

        $this->assertSame('192.168.1.100', $request->clientIp());
    }

    public function testClientIpDefaultsToLocalhost(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];

        $request = Request::capture();

        $this->assertSame('127.0.0.1', $request->clientIp());
    }

    public function testHost(): void
    {
        $request = $this->createRequest([
            'HTTP_HOST' => 'example.com:8080',
        ]);

        $this->assertSame('example.com', $request->host());
    }

    public function testHostFallsBackToServerName(): void
    {
        $request = $this->createRequest([
            'SERVER_NAME' => 'example.com',
        ]);

        $this->assertSame('example.com', $request->host());
    }

    public function testPort(): void
    {
        $request = $this->createRequest([
            'SERVER_PORT' => '8080',
        ]);

        $this->assertSame(8080, $request->port());
    }

    public function testPortDefaultsBasedOnScheme(): void
    {
        $httpRequest = $this->createRequest();
        $httpsRequest = $this->createRequest([
            'REQUEST_SCHEME' => 'https',
            'SERVER_PORT' => null,
        ]);

        $this->assertSame(80, $httpRequest->port());
    }

    public function testScheme(): void
    {
        $httpsRequest = $this->createRequest([
            'REQUEST_SCHEME' => 'https',
        ]);
        $httpRequest = $this->createRequest([
            'REQUEST_SCHEME' => 'http',
        ]);

        $this->assertSame('https', $httpsRequest->scheme());
        $this->assertSame('http', $httpRequest->scheme());
    }

    public function testSchemeDefaultsToHttp(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];

        $request = Request::capture();

        $this->assertSame('http', $request->scheme());
    }

    public function testRawBody(): void
    {
        // Note: php://input cannot be mocked in a standard test
        // This test verifies the method exists and returns a string
        $request = $this->createRequest();

        $body = $request->rawBody();
        $this->assertIsString($body);
    }
}
