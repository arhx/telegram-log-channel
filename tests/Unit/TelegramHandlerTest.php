<?php

namespace Arhx\TelegramLogChannel\Tests\Unit;

use Arhx\TelegramLogChannel\CreateTelegramLogger;
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

    public function test_it_throttles_identical_messages()
    {
        // Two successful responses available; throttling should consume only one.
        $mock = new MockHandler([
            new Response(200, [], '{"ok":true}'),
            new Response(200, [], '{"ok":true}'),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $telegramHandler = new TelegramHandler('token', 'chat_id', Level::Error, true, 600);

        $reflection = new \ReflectionClass($telegramHandler);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($telegramHandler, $client);

        $makeRecord = fn () => new LogRecord(
            new \DateTimeImmutable(),
            'test',
            Level::Error,
            'Identical message'
        );

        $telegramHandler->handle($makeRecord()); // sent -> consumes 1 mock response
        $telegramHandler->handle($makeRecord()); // identical -> throttled, no request

        // One response left in the mock queue means the second send was skipped.
        $this->assertSame(1, $mock->count());
    }

    public function test_it_does_not_throttle_when_disabled()
    {
        $mock = new MockHandler([
            new Response(200, [], '{"ok":true}'),
            new Response(200, [], '{"ok":true}'),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $telegramHandler = new TelegramHandler('token', 'chat_id', Level::Error, true, 0);

        $reflection = new \ReflectionClass($telegramHandler);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($telegramHandler, $client);

        $makeRecord = fn () => new LogRecord(
            new \DateTimeImmutable(),
            'test',
            Level::Error,
            'Identical message'
        );

        $telegramHandler->handle($makeRecord());
        $telegramHandler->handle($makeRecord());

        // Both messages sent -> mock queue fully drained.
        $this->assertSame(0, $mock->count());
    }

    public function test_it_sends_message_thread_id_when_a_topic_is_configured()
    {
        $sent = $this->captureSentPayload(new TelegramHandler('token', 'chat_id', Level::Error, true, 0, 7));

        $this->assertSame(7, $sent['message_thread_id']);
        $this->assertSame('chat_id', $sent['chat_id']);
    }

    public function test_it_omits_message_thread_id_without_a_topic()
    {
        $sent = $this->captureSentPayload(new TelegramHandler('token', 'chat_id', Level::Error, true, 0));

        $this->assertArrayNotHasKey('message_thread_id', $sent);
    }

    public function test_empty_topic_id_is_treated_as_no_topic()
    {
        // env('TELEGRAM_LOG_TOPIC_ID') on an unset key yields '' — that must not
        // become message_thread_id=0, which Telegram rejects.
        $sent = $this->captureSentPayload(new TelegramHandler('token', 'chat_id', Level::Error, true, 0, ''));

        $this->assertArrayNotHasKey('message_thread_id', $sent);
    }

    /**
     * Handle one record through the given handler with a mocked Guzzle client and
     * return the decoded JSON body that was posted to the Bot API.
     */
    protected function captureSentPayload(TelegramHandler $telegramHandler): array
    {
        $mock = new MockHandler([new Response(200, [], '{"ok":true}')]);
        $stack = HandlerStack::create($mock);

        $requests = [];
        $stack->push(\GuzzleHttp\Middleware::history($requests));

        $reflection = new \ReflectionClass($telegramHandler);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($telegramHandler, new Client(['handler' => $stack]));

        $telegramHandler->handle(new LogRecord(
            new \DateTimeImmutable(),
            'test',
            Level::Error,
            'Topic routing message'
        ));

        $this->assertCount(1, $requests, 'Expected exactly one Bot API request');

        return json_decode((string) $requests[0]['request']->getBody(), true);
    }

    protected function tearDown(): void
    {
        TelegramHandler::redactUsing(null);

        parent::tearDown();
    }

    public function test_tls_verification_is_enabled_by_default()
    {
        $handler = new TelegramHandler('token', 'chat_id');

        $this->assertTrue($handler->getClientOptions()['verify']);
    }

    public function test_logger_factory_enables_tls_verification_by_default()
    {
        $logger = (new CreateTelegramLogger())(['token' => 'token', 'chat_id' => 'chat_id']);

        $this->assertTrue($logger->getHandlers()[0]->getClientOptions()['verify']);
    }

    public function test_logger_factory_passes_verify_from_channel_config()
    {
        $off = (new CreateTelegramLogger())(['token' => 't', 'chat_id' => 'c', 'verify' => 'false']);
        $bundle = (new CreateTelegramLogger())(['token' => 't', 'chat_id' => 'c', 'verify' => '/etc/ssl/ca.pem']);

        $this->assertFalse($off->getHandlers()[0]->getClientOptions()['verify']);
        $this->assertSame('/etc/ssl/ca.pem', $bundle->getHandlers()[0]->getClientOptions()['verify']);
    }

    public function test_logger_factory_falls_back_to_package_config_for_verify()
    {
        config(['telegram-log-channel.verify_tls' => false]);

        $logger = (new CreateTelegramLogger())(['token' => 't', 'chat_id' => 'c']);

        $this->assertFalse($logger->getHandlers()[0]->getClientOptions()['verify']);
    }

    public function test_full_message_includes_request_body_context_and_text()
    {
        $text = $this->sendHttpRecord($this->httpHandler());

        $this->assertStringContainsString('Бронежилет', $text);
        $this->assertStringContainsString('SQLSTATE', $text);
        $this->assertStringContainsString('"price": 1500', $text);
        $this->assertStringContainsString('Request: POST /items', $text);
    }

    public function test_redact_hook_strips_request_context_and_message()
    {
        TelegramHandler::redactUsing(fn (LogRecord $record) => true);

        $text = $this->sendHttpRecord($this->httpHandler());

        $this->assertStringNotContainsString('Бронежилет', $text);
        $this->assertStringNotContainsString('Секретне майно', $text);
        $this->assertStringNotContainsString('SQLSTATE', $text);
        $this->assertStringNotContainsString('1500', $text);
        $this->assertStringNotContainsString('Іваненко', $text);
        $this->assertStringNotContainsString('Request:', $text);
        $this->assertStringContainsString('ERROR: RuntimeException ' . TelegramHandler::REDACTED_MARKER, $text);
        $this->assertStringContainsString('User: 42', $text);
    }

    public function test_redact_hook_receives_the_record_and_can_decline()
    {
        $seen = null;
        TelegramHandler::redactUsing(function (LogRecord $record) use (&$seen) {
            $seen = $record->message;

            return false;
        });

        $text = $this->sendHttpRecord($this->httpHandler());

        $this->assertStringStartsWith('SQLSTATE', $seen);
        $this->assertStringContainsString('Бронежилет', $text);
    }

    public function test_redact_fails_closed_when_hook_throws()
    {
        TelegramHandler::redactUsing(fn () => throw new \LogicException('tenant lookup failed'));

        $text = $this->sendHttpRecord($this->httpHandler());

        $this->assertStringNotContainsString('Бронежилет', $text);
        $this->assertStringNotContainsString('Секретне майно', $text);
        $this->assertStringContainsString(TelegramHandler::REDACTED_MARKER, $text);
    }

    public function test_include_request_false_drops_request_body_only()
    {
        $text = $this->sendHttpRecord($this->httpHandler(includeRequest: false));

        $this->assertStringContainsString("Request: POST /items\n", $text);
        $this->assertStringNotContainsString('Бронежилет', $text);
        $this->assertStringContainsString('"price": 1500', $text);
    }

    public function test_include_context_false_drops_context_and_extra()
    {
        $text = $this->sendHttpRecord($this->httpHandler(includeContext: false));

        $this->assertStringNotContainsString('1500', $text);
        $this->assertStringNotContainsString('Іваненко', $text);
        $this->assertStringContainsString('Бронежилет', $text);
    }

    public function test_redacted_messages_are_still_throttled()
    {
        TelegramHandler::redactUsing(fn () => true);

        $mock = new MockHandler([
            new Response(200, [], '{"ok":true}'),
            new Response(200, [], '{"ok":true}'),
        ]);
        $handler = new TelegramHandler('token', 'chat_id', Level::Error, true, 600);
        $this->injectClient($handler, new Client(['handler' => HandlerStack::create($mock)]));

        $handler->handle($this->sensitiveRecord());
        $handler->handle($this->sensitiveRecord());

        $this->assertSame(1, $mock->count());
    }

    /** Handler that behaves as if serving an HTTP request, with throttling off. */
    protected function httpHandler(bool $includeRequest = true, bool $includeContext = true): TelegramHandler
    {
        return new class('token', 'chat_id', Level::Error, true, 0, null, true, $includeRequest, $includeContext) extends TelegramHandler {
            protected function runningInConsole(): bool
            {
                return false;
            }
        };
    }

    protected function sensitiveRecord(): LogRecord
    {
        return new LogRecord(
            new \DateTimeImmutable(),
            'test',
            Level::Error,
            "SQLSTATE[22001]: insert into items (name) values ('Секретне майно')",
            [
                'exception' => new \RuntimeException("SQLSTATE[22001]: insert into items (name) values ('Секретне майно')"),
                'price' => 1500,
            ],
            ['extra_owner' => 'Іваненко'],
        );
    }

    /**
     * Send a sensitive record as user 42 during a POST /items request carrying
     * sensitive form data; return the text posted to the Bot API.
     */
    protected function sendHttpRecord(TelegramHandler $handler): string
    {
        $this->app->instance('request', \Illuminate\Http\Request::create('/items', 'POST', [
            'name' => 'Бронежилет',
            '_token' => 'csrf',
        ]));
        \Illuminate\Support\Facades\Auth::guard()->setUser(new \Illuminate\Auth\GenericUser(['id' => 42]));

        $mock = new MockHandler([new Response(200, [], '{"ok":true}')]);
        $stack = HandlerStack::create($mock);
        $requests = [];
        $stack->push(\GuzzleHttp\Middleware::history($requests));
        $this->injectClient($handler, new Client(['handler' => $stack]));

        $handler->handle($this->sensitiveRecord());

        $this->assertCount(1, $requests, 'Expected exactly one Bot API request');

        return json_decode((string) $requests[0]['request']->getBody(), true)['text'];
    }

    protected function injectClient(TelegramHandler $handler, Client $client): void
    {
        $property = new \ReflectionProperty(TelegramHandler::class, 'client');
        $property->setAccessible(true);
        $property->setValue($handler, $client);
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
