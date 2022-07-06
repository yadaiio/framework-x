<?php

namespace FrameworkX;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;

class App
{
    /** @var MiddlewareHandler */
    private $handler;

    /** @var RouteHandler */
    private $router;

    /** @var SapiHandler */
    private $sapi;

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
     * ```
     *
     * @param callable|class-string ...$middleware
     */
    public function __construct(...$middleware)
    {
        // new MiddlewareHandler([$fiberHandler, $accessLogHandler, $errorHandler, ...$middleware, $routeHandler])
        $handlers = [];

        $container = new Container();
        if ($middleware) {
            foreach ($middleware as $handler) {
                if ($handler instanceof Container) {
                    $container = $handler;
                } elseif ($handler === ErrorHandler::class) {
                    throw new \TypeError('ErrorHandler may currently only be passed as instance');
                } elseif (!\is_callable($handler)) {
                    $handlers[] = $container->callable($handler);
                } else {
                    $handlers[] = $handler;
                }
            }
        }

        // add default ErrorHandler as first handler unless it is already added explicitly
        if (!($handlers[0] ?? null) instanceof ErrorHandler) {
            \array_unshift($handlers, new ErrorHandler());
        }

        // only log for built-in webserver and PHP development webserver by default, others have their own access log
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'cli-server') {
            \array_unshift($handlers, new AccessLogHandler());
        }

        // automatically start new fiber for each request on PHP 8.1+
        if (\PHP_VERSION_ID >= 80100) {
            \array_unshift($handlers, new FiberHandler()); // @codeCoverageIgnore
        }

        $this->router = new RouteHandler($container);
        $handlers[] = $this->router;
        $this->handler = new MiddlewareHandler($handlers);
        $this->sapi = new SapiHandler();
    }

    /**
     * @param string $route
     * @param callable|class-string $handler
     * @param callable|class-string ...$handlers
     */
    public function get(string $route, $handler, ...$handlers): void
    {
        $this->map(['GET'], $route, $handler, ...$handlers);
    }

    /**
     * @param string $route
     * @param callable|class-string $handler
     * @param callable|class-string ...$handlers
     */
    public function head(string $route, $handler, ...$handlers): void
    {
        $this->map(['HEAD'], $route, $handler, ...$handlers);
    }

    /**
     * @param string $route
     * @param callable|class-string $handler
     * @param callable|class-string ...$handlers
     */
    public function post(string $route, $handler, ...$handlers): void
    {
        $this->map(['POST'], $route, $handler, ...$handlers);
    }

    /**
     * @param string $route
     * @param callable|class-string $handler
     * @param callable|class-string ...$handlers
     */
    public function put(string $route, $handler, ...$handlers): void
    {
        $this->map(['PUT'], $route, $handler, ...$handlers);
    }

    /**
     * @param string $route
     * @param callable|class-string $handler
     * @param callable|class-string ...$handlers
     */
    public function patch(string $route, $handler, ...$handlers): void
    {
        $this->map(['PATCH'], $route, $handler, ...$handlers);
    }

    /**
     * @param string $route
     * @param callable|class-string $handler
     * @param callable|class-string ...$handlers
     */
    public function delete(string $route, $handler, ...$handlers): void
    {
        $this->map(['DELETE'], $route, $handler, ...$handlers);
    }

    /**
     * @param string $route
     * @param callable|class-string $handler
     * @param callable|class-string ...$handlers
     */
    public function options(string $route, $handler, ...$handlers): void
    {
        $this->map(['OPTIONS'], $route, $handler, ...$handlers);
    }

    /**
     * @param string $route
     * @param callable|class-string $handler
     * @param callable|class-string ...$handlers
     */
    public function any(string $route, $handler, ...$handlers): void
    {
        $this->map(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $route, $handler, ...$handlers);
    }

    /**
     *
     * @param string[] $methods
     * @param string $route
     * @param callable|class-string $handler
     * @param callable|class-string ...$handlers
     */
    public function map(array $methods, string $route, $handler, ...$handlers): void
    {
        $this->router->map($methods, $route, $handler, ...$handlers);
    }

    /**
     * @param string $route
     * @param string $target
     * @param int $code
     */
    public function redirect(string $route, string $target, int $code = Response::STATUS_FOUND): void
    {
        $this->any($route, new RedirectHandler($target, $code));
    }

    public function run()
    {
        if (\PHP_SAPI === 'cli') {
            $this->runLoop();
        } else {
            $this->runOnce(); // @codeCoverageIgnore
        }
    }

    private function runLoop()
    {
        $http = new HttpServer(function (ServerRequestInterface $request) {
            return $this->handleRequest($request);
        });

        $listen = $_SERVER['X_LISTEN'] ?? '127.0.0.1:8080';

        $socket = new SocketServer($listen);
        $http->listen($socket);

        $this->sapi->log('Listening on ' . \str_replace('tcp:', 'http:', $socket->getAddress()));

        $http->on('error', function (\Exception $e) {
            $orig = $e;
            $message = 'Error: ' . $e->getMessage();
            while (($e = $e->getPrevious()) !== null) {
                $message .= '. Previous: ' . $e->getMessage();
            }

            $this->sapi->log($message);

            \fwrite(STDERR, (string)$orig);
        });

        try {
            Loop::addSignal(\defined('SIGINT') ? \SIGINT : 2, $f1 = function () use ($socket) {
                if (\PHP_VERSION_ID >= 70200 && \stream_isatty(\STDIN)) {
                    echo "\r";
                }
                $this->sapi->log('Received SIGINT, stopping loop');

                $socket->close();
                Loop::stop();
            });
            Loop::addSignal(\defined('SIGTERM') ? \SIGTERM : 15, $f2 = function () use ($socket) {
                $this->sapi->log('Received SIGTERM, stopping loop');

                $socket->close();
                Loop::stop();
            });
        } catch (\BadMethodCallException $e) { // @codeCoverageIgnoreStart
            $this->sapi->log('Notice: No signal handler support, installing ext-ev or ext-pcntl recommended for production use.');
        } // @codeCoverageIgnoreEnd

        do {
            Loop::run();

            if ($socket->getAddress() !== null) {
                // Fiber compatibility mode for PHP < 8.1: Restart loop as long as socket is available
                $this->sapi->log('Warning: Loop restarted. Upgrade to react/async v4 recommended for production use.');
            } else {
                break;
            }
        } while (true);

        // remove signal handlers when loop stops (if registered)
        Loop::removeSignal(\defined('SIGINT') ? \SIGINT : 2, $f1);
        Loop::removeSignal(\defined('SIGTERM') ? \SIGTERM : 15, $f2 ?? 'printf');
    }

    private function runOnce()
    {
        $request = $this->sapi->requestFromGlobals();

        $response = $this->handleRequest($request);

        if ($response instanceof ResponseInterface) {
            $this->sapi->sendResponse($response);
        } elseif ($response instanceof PromiseInterface) {
            $response->then(function (ResponseInterface $response) {
                $this->sapi->sendResponse($response);
            });
        }

        Loop::run();
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
}
