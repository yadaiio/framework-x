<?php

namespace FrameworkX;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\ServerRequest;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use React\Stream\ReadableStreamInterface;

class App
{
    private $loop;

    /** @var MiddlewareHandler */
    private $handler;

    /** @var RouteHandler */
    private $router;

    /**
     * Instantiate new X application
     *
     * ```php
     * // instantiate
     * $app = new App();
     *
     * // instantiate with global middleware
     * $app = new App($middleware);
     * $app = new App($middleware1, $middleware2);
     *
     * // instantiate with optional $loop
     * $app = new App($loop);
     * $app = new App($loop, $middleware);
     * $app = new App($loop, $middleware1, $middleware2);
     *
     * // invalid $loop argument
     * $app = new App(null);
     * $app = new App(null, $middleware);
     * ```
     *
     * @param callable|LoopInterface|null $loop
     * @param callable ...$middleware
     * @throws \TypeError if given $loop argument is invalid
     */
    public function __construct($loop = null, callable ...$middleware)
    {
        $errorHandler = new ErrorHandler();
        if (\is_callable($loop)) {
            \array_unshift($middleware, $loop);
            $loop = null;
        } elseif (\func_num_args() !== 0 && !$loop instanceof LoopInterface) {
            throw new \TypeError('Argument 1 ($loop) must be callable|' . LoopInterface::class . ', ' . $errorHandler->describeType($loop) . ' given');
        }

        $this->loop = $loop ?? Loop::get();
        $this->router = new RouteHandler();

        // new MiddlewareHandler([$errorHandler, ...$middleware, $routeHandler])
        \array_unshift($middleware, $errorHandler);
        $middleware[] = $this->router;
        $this->handler = new MiddlewareHandler($middleware);
    }

    public function get(string $route, callable $handler, callable ...$handlers): void
    {
        $this->map(['GET'], $route, $handler, ...$handlers);
    }

    public function head(string $route, callable $handler, callable ...$handlers): void
    {
        $this->map(['HEAD'], $route, $handler, ...$handlers);
    }

    public function post(string $route, callable $handler, callable ...$handlers): void
    {
        $this->map(['POST'], $route, $handler, ...$handlers);
    }

    public function put(string $route, callable $handler, callable ...$handlers): void
    {
        $this->map(['PUT'], $route, $handler, ...$handlers);
    }

    public function patch(string $route, callable $handler, callable ...$handlers): void
    {
        $this->map(['PATCH'], $route, $handler, ...$handlers);
    }

    public function delete(string $route, callable $handler, callable ...$handlers): void
    {
        $this->map(['DELETE'], $route, $handler, ...$handlers);
    }

    public function options(string $route, callable $handler, callable ...$handlers): void
    {
        $this->map(['OPTIONS'], $route, $handler, ...$handlers);
    }

    public function any(string $route, callable $handler, callable ...$handlers): void
    {
        $this->map(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $route, $handler, ...$handlers);
    }

    public function map(array $methods, string $route, callable $handler, callable ...$handlers): void
    {
        $this->router->map($methods, $route, $handler, ...$handlers);
    }

    public function redirect(string $route, string $target, int $code = 302): void
    {
        $this->get($route, new RedirectHandler($target, $code));
    }

    public function run()
    {
        if (\php_sapi_name() === 'cli') {
            $this->runLoop();
        } else {
            $this->runOnce();
        }

        $this->loop->run();
    }

    private function runLoop()
    {
        $http = new HttpServer($this->loop, function (ServerRequestInterface $request) {
            $response = $this->handleRequest($request);

            if ($response instanceof ResponseInterface) {
                $this->logRequestResponse($request, $response);
            } elseif ($response instanceof PromiseInterface) {
                $response->then(function (ResponseInterface $response) use ($request) {
                    $this->logRequestResponse($request, $response);
                });
            }

            return $response;
        });

        $listen = \getenv('X_LISTEN');
        if ($listen === false) {
            $listen = '127.0.0.1:8080';
        }

        $socket = new SocketServer($listen, [], $this->loop);
        $http->listen($socket);

        $this->log('Listening on ' . \str_replace('tcp:', 'http:', $socket->getAddress()));

        $http->on('error', function (\Exception $e) {
            $orig = $e;
            $message = 'Error: ' . $e->getMessage();
            while (($e = $e->getPrevious()) !== null) {
                $message .= '. Previous: ' . $e->getMessage();
            }

            $this->log($message);

            \fwrite(STDERR, (string)$orig);
        });
    }

    private function requestFromGlobals(): ServerRequestInterface
    {
        $host = null;
        $headers = array();
        if (\function_exists('getallheaders')) {
            $headers = \getallheaders();
            $host = \array_change_key_case($headers, \CASE_LOWER)['host'] ?? null;
        } else {
            foreach ($_SERVER as $key => $value) {
                if (\strpos($key, 'HTTP_') === 0) {
                    $key = str_replace(' ', '-', \ucwords(\strtolower(\str_replace('_', ' ', \substr($key, 5)))));
                    $headers[$key] = $value;

                    if ($host === null && $key === 'Host') {
                        $host = $value;
                    }
                }
            }
        }

        $body = file_get_contents('php://input');

        $request = new ServerRequest(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            ($_SERVER['HTTPS'] ?? null === 'on' ? 'https://' : 'http://') . ($host ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/'),
            $headers,
            $body,
            substr($_SERVER['SERVER_PROTOCOL'] ?? 'http/1.1', 5),
            $_SERVER
        );
        if ($host === null) {
            $request = $request->withoutHeader('Host');
        }
        $request = $request->withParsedBody($_POST);

        // Content-Length / Content-Type are special <3
        if ($request->getHeaderLine('Content-Length') === '') {
            $request = $request->withoutHeader('Content-Length');
        }
        if ($request->getHeaderLine('Content-Type') === '' && !isset($_SERVER['HTTP_CONTENT_TYPE'])) {
            $request = $request->withoutHeader('Content-Type');
        }

        return $request;
    }

    private function runOnce()
    {
        $request = $this->requestFromGlobals();

        $response = $this->handleRequest($request);

        if ($response instanceof ResponseInterface) {
            $this->sendResponse($request, $response);
        } elseif ($response instanceof PromiseInterface) {
            $response->then(function (ResponseInterface $response) use ($request) {
                $this->sendResponse($request, $response);
            });
        }
    }

    private function sendResponse(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $this->logRequestResponse($request, $response);

        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase());

        // automatically assign "Content-Length" response header if known and not already present
        if (!$response->hasHeader('Content-Length') && $response->getBody()->getSize() !== null) {
            $response = $response->withHeader('Content-Length', (string)$response->getBody()->getSize());
        }

        // remove default "Content-Type" header set by PHP (default_mimetype)
        if (!$response->hasHeader('Content-Type')) {
            header('Content-Type: foo');
            header_remove('Content-Type');
        }

        // send all headers without applying default "; charset=utf-8" set by PHP (default_charset)
        $old = ini_get('default_charset');
        ini_set('default_charset', '');
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value);
            }
        }
        ini_set('default_charset', $old);

        $body = $response->getBody();

        if ($body instanceof ReadableStreamInterface) {
            // clear all output buffers (default in cli-server)
            while (ob_get_level()) {
                ob_end_clean();
            }

            // try to disable nginx buffering (http://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_buffering)
            if (isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'nginx') === 0) {
                header('X-Accel-Buffering: no');
            }

            // flush data whenever stream reports one data chunk
            $body->on('data', function ($chunk) {
                echo $chunk;
                flush();
            });
        } else {
            echo $response->getBody();
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @return ResponseInterface|PromiseInterface<ResponseInterface,void>
     *     Returns a response or a Promise which eventually fulfills with a
     *     response. This method never throws or resolves a rejected promise.
     *     If the request can not be routed or the handler fails, it will be
     *     turned into a valid error response before returning.
     */
    private function handleRequest(ServerRequestInterface $request)
    {
        $response = ($this->handler)($request);
        if ($response instanceof \Generator) {
            if ($response->valid()) {
                $response = $this->coroutine($response);
            } else {
                $response = $response->getReturn();
            }
        }

        return $response;
    }

    private function coroutine(\Generator $generator): PromiseInterface
    {
        $next = null;
        $deferred = new Deferred();
        $next = function () use ($generator, &$next, $deferred) {
            if (!$generator->valid()) {
                $deferred->resolve($generator->getReturn());
                return;
            }

            $promise = $generator->current();
            $promise->then(function ($value) use ($generator, $next) {
                $generator->send($value);
                $next();
            }, function ($reason) use ($generator, $next) {
                $generator->throw($reason);
                $next();
            });
        };

        $next();

        return $deferred->promise();
    }

    private function logRequestResponse(ServerRequestInterface $request, ResponseInterface $response): void
    {
        // only log for built-in webserver and PHP development webserver, others have their own access log
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
            return; // @codeCoverageIgnore
        }

        $this->log(
            ($request->getServerParams()['REMOTE_ADDR'] ?? '-') . ' ' .
            '"' . $request->getMethod() . ' ' . $request->getUri()->getPath() . ' HTTP/' . $request->getProtocolVersion() . '" ' .
            $response->getStatusCode() . ' ' . $response->getBody()->getSize()
        );
    }

    private function log(string $message): void
    {
        $time = microtime(true);

        $log = date('Y-m-d H:i:s', (int)$time) . sprintf('.%03d ', (int)(($time - (int)$time) * 1e3)) . $message . PHP_EOL;

        if (\PHP_SAPI === 'cli') {
            echo $log;
        } else {
            fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'a'), $log);
        }
    }
}
