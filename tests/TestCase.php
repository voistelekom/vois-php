<?php

namespace Vois\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    protected array $history = [];

    /** @param list<Response|\Throwable> $responses */
    protected function handler(array $responses): HandlerStack
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return $stack;
    }

    protected function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body));
    }

    protected function lastRequest(): RequestInterface
    {
        return end($this->history)['request'];
    }

    protected function lastBody(): array
    {
        return json_decode((string) $this->lastRequest()->getBody(), true);
    }
}
