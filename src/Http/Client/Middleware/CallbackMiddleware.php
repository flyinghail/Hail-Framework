<?php

declare(strict_types=1);

namespace Hail\Http\Client\Middleware;

use Hail\Http\Client\Exception\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class CallbackMiddleware implements MiddlewareInterface
{
    private $callable;

    /**
     * The callback should expect either one or two arguments, depending on
     * whether it is receiving a pre or post send notification.
     *
     *     $middleware = new CallbackMiddleware(function($request, $response = null) {
     *         if ($response) {
     *             // postSend
     *         } else {
     *             // preSend
     *         }
     *     });
     *
     * @param callable $callable A PHP callable
     *
     * @throws InvalidArgumentException If the argument is not callable
     */
    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    public function handleRequest(RequestInterface $request, callable $next)
    {
        $request = ($this->callable)($request);

        return $next($request);
    }

    public function handleResponse(RequestInterface $request, ResponseInterface $response, callable $next)
    {
        $response = ($this->callable)($request, $response);

        return $next($request, $response);
    }
}
