<?php

namespace Arhx\TelegramLogChannel\Tests\Unit;

use Arhx\TelegramLogChannel\TelegramHandler;
use Arhx\TelegramLogChannel\Tests\TestCase;
use Monolog\LogRecord;
use Monolog\Level;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;

class TelegramHandlerTest extends TestCase
{
    public function test_it_handles_guzzle_exceptions_without_bubbling_up()
    {
        $mock = new MockHandler([
            new RequestException('Error Communicating with Server', new Request('POST', 'test'))
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $telegramHandler = new TelegramHandler('token', 'chat_id');
        
        $reflection = new \ReflectionClass($telegramHandler);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($telegramHandler, $client);

        $record = new LogRecord(
            new \DateTimeImmutable(),
            'test',
            Level::Error,
            'Test message'
        );

        // Mock Log::channel('single')->error() to verify it's called
        Log::shouldReceive('channel')
            ->with('single')
            ->once()
            ->andReturnSelf();
        
        Log::shouldReceive('error')
            ->withArgs(function($message, $context) {
                return str_contains($message, 'Telegram Logger Error') && isset($context['exception']);
            })
            ->once();

        // This should not throw an exception anymore
        $telegramHandler->handle($record);
    }

    public function test_it_prevents_infinite_recursion()
    {
        $mock = new MockHandler([
            new RequestException('Recursion Test', new Request('POST', 'test')),
            new RequestException('Recursion Test', new Request('POST', 'test')),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $telegramHandler = new TelegramHandler('token', 'chat_id');
        
        $reflection = new \ReflectionClass($telegramHandler);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($telegramHandler, $client);

        $record = new LogRecord(
            new \DateTimeImmutable(),
            'test',
            Level::Error,
            'Test message'
        );

        // If logSelfError triggers another Telegram log, $isHandling should prevent it.
        // We can test this by making Log::channel('single')->error() trigger the same handler.
        
        Log::shouldReceive('channel')->with('single')->andReturnSelf();
        Log::shouldReceive('error')->andReturnUsing(function() use ($telegramHandler, $record) {
            $telegramHandler->handle($record); // This would cause recursion if not protected
        });

        $telegramHandler->handle($record);
        
        $this->assertTrue(true); // If we didn't get a stack overflow, it's working
    }
}
