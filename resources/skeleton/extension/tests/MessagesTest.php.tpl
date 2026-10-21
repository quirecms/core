<?php

declare(strict_types=1);

namespace @@NAMESPACE@@\Tests;

use Quire\Testing\ExtensionTestCase;

final class MessagesTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['@@ID@@'];

    public function testShowsTheGreeting(): void
    {
        $this->client->request('GET', '@@PATH@@');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Hello from @@TITLE@@!');
        self::assertSelectorTextSame('p', 'No messages yet.');
    }

    public function testTheGreetingIsASetting(): void
    {
        $this->setSetting('@@ID@@.greeting', 'Welcome!');

        $this->client->request('GET', '@@PATH@@');

        self::assertSelectorTextSame('h1', 'Welcome!');
    }

    public function testListsStoredMessages(): void
    {
        $this->connection()->insert('@@TABLE@@', ['message' => 'First message']);

        $this->client->request('GET', '@@PATH@@');

        self::assertSelectorTextSame('li', 'First message');
    }
}
