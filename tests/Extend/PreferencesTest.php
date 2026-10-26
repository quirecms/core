<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Extend\ExtenderContext;
use Quire\Extend\Preferences;
use Quire\User\Preference\UserPreferences;

final class PreferencesTest extends ExtenderTestCase
{
    public function testDefinesPreferences(): void
    {
        $this->apply(new Preferences()
            ->define('acme-reviews.sort', 'enum', 'newest', choices: ['newest', 'oldest'])
            ->define('acme-reviews.show_spam', 'bool', false));
        new Preferences()->define('quire.theme', 'string', null, nullable: true)
            ->extend(new ExtenderContext(ExtenderContext::CORE, __DIR__, $this->container, []));

        self::assertSame([
            'acme-reviews.sort' => ['name' => 'acme-reviews.sort', 'type' => 'enum', 'default' => 'newest', 'owner' => 'acme-reviews', 'choices' => ['newest', 'oldest'], 'nullable' => false],
            'acme-reviews.show_spam' => ['name' => 'acme-reviews.show_spam', 'type' => 'bool', 'default' => false, 'owner' => 'acme-reviews', 'choices' => [], 'nullable' => false],
            'quire.theme' => ['name' => 'quire.theme', 'type' => 'string', 'default' => null, 'owner' => 'core', 'choices' => [], 'nullable' => true],
        ], $this->container->getParameterBag()->all()[UserPreferences::PARAMETER] ?? null);
    }

    public function testInvalidDeclarations(): void
    {
        $this->assertExtenderFails(new Preferences()->define('acme-reviews.x', 'number', 1), 'Unknown type "number"');
        $this->assertExtenderFails(new Preferences()->define('acme-reviews', 'int', 1), 'Names are lowercase words separated by dots');
        $this->assertExtenderFails(new Preferences()->define('quire.language', 'string', ''), 'Names declared here must start with "acme-reviews."');
        $this->assertExtenderFails(new Preferences()->define('acme-reviews.sort', 'enum', 'a'), 'Preferences of type "enum" need a list of choices.');
        $this->assertExtenderFails(new Preferences()->define('acme-reviews.sort', 'string', 'a', choices: ['a']), 'Choices only apply to preferences of type "enum".');
        $this->assertExtenderFails(new Preferences()->define('acme-reviews.limit', 'int', '20'), 'The default doesn\'t fit: expected an integer.');
        $this->assertExtenderFails(new Preferences()->define('acme-reviews.x', 'int', 1)->define('acme-reviews.x', 'int', 2), 'The preference is already declared by "acme-reviews".');
    }
}
