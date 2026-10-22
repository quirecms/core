<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Extend\ExtenderContext;
use Quire\Extend\Settings;
use Quire\Settings\SettingsSchema;
use Quire\Settings\SettingType;

final class SettingsTest extends ExtenderTestCase
{
    public function testDefinesSettings(): void
    {
        $this->apply(new Settings()
            ->define('acme-reviews.per_page', 'int', 20, label: 'Reviews per page', help: 'How many…', group: 'display')
            ->define('acme-reviews.sort', SettingType::Enum, 'newest', choices: ['newest', 'oldest'])
            ->define('acme-reviews.api_key', 'string', null, nullable: true, sensitive: true));

        $schema = $this->schema();

        self::assertSame(['acme-reviews.api_key', 'acme-reviews.per_page', 'acme-reviews.sort'], array_keys($schema->all()));
        $perPage = $schema->get('acme-reviews.per_page');
        self::assertSame(SettingType::Int, $perPage->type);
        self::assertSame(20, $perPage->default);
        self::assertSame('acme-reviews', $perPage->owner);
        self::assertSame('Reviews per page', $perPage->label);
        self::assertSame('display', $perPage->group);
        self::assertSame(['newest', 'oldest'], $schema->get('acme-reviews.sort')->choices);
        self::assertTrue($schema->get('acme-reviews.api_key')->sensitive);
        self::assertSame('QUIRE_SETTING__ACME_REVIEWS__PER_PAGE', $perPage->environmentVariable());
    }

    public function testCoreAndSitePrefixes(): void
    {
        new Settings()->define('site.tagline', 'string', '')
            ->extend(new ExtenderContext(ExtenderContext::CORE, __DIR__, $this->container, []));
        new Settings()->define('custom.footer', 'string', '')
            ->extend(new ExtenderContext(ExtenderContext::SITE, __DIR__, $this->container, []));

        self::assertSame(['custom.footer', 'site.tagline'], array_keys($this->schema()->all()));
    }

    public function testInvalidDeclarations(): void
    {
        $this->assertExtenderFails(new Settings()->define('acme-reviews.x', 'number', 1), 'Unknown type "number". Use one of: string, bool, int, float, enum, list, json.');
        $this->assertExtenderFails(new Settings()->define('acme-reviews', 'int', 1), 'Keys are lowercase words separated by dots');
        $this->assertExtenderFails(new Settings()->define('acme-reviews.Per_Page', 'int', 1), 'Keys are lowercase words separated by dots');
        $this->assertExtenderFails(new Settings()->define('site.title', 'string', ''), 'Keys declared here must start with "acme-reviews."');
        $this->assertExtenderFails(new Settings()->define('acme-reviews.sort', 'enum', 'a'), 'Settings of type "enum" need a list of choices.');
        $this->assertExtenderFails(new Settings()->define('acme-reviews.sort', 'string', 'a', choices: ['a']), 'Choices only apply to settings of type "enum".');
        $this->assertExtenderFails(new Settings()->define('acme-reviews.per_page', 'int', '20'), 'The default value doesn\'t fit the setting: expected an integer.');
        $this->assertExtenderFails(new Settings()->define('acme-reviews.api_key', 'string', null), 'can\'t be empty (null)');
        $this->assertExtenderFails(new Settings()->define('acme-reviews.api_key', 'string', '', sensitive: true, exposed: true), 'Sensitive settings can\'t be exposed to templates.');
    }

    public function testExposedSettingsAndThemeSettings(): void
    {
        $this->apply(new Settings()->define('acme-reviews.footer', 'string', '', exposed: true)->define('acme-reviews.limit', 'int', 5));
        new Settings()->define('theme.acme-dark.accent', 'string', 'navy')
            ->extend(new ExtenderContext('acme-dark', __DIR__, $this->container, [], theme: true));

        self::assertTrue($this->schema()->get('acme-reviews.footer')->exposed);
        self::assertFalse($this->schema()->get('acme-reviews.limit')->exposed);
        self::assertTrue($this->schema()->get('theme.acme-dark.accent')->exposed, 'A theme\'s settings are for its templates.');

        $this->expectExceptionMessageIsOrContains('Keys declared here must start with "theme.acme-dark."');
        new Settings()->define('acme-dark.accent', 'string', '')
            ->extend(new ExtenderContext('acme-dark', __DIR__, $this->container, [], theme: true));
    }

    public function testDuplicateKeysAreRejected(): void
    {
        $this->apply(new Settings()->define('acme-reviews.per_page', 'int', 20));

        $this->assertExtenderFails(new Settings()->define('acme-reviews.per_page', 'int', 10), 'The setting is already declared by "acme-reviews".');
    }

    private function schema(): SettingsSchema
    {
        /** @var array<string, array<string, mixed>> $definitions */
        $definitions = $this->container->getParameterBag()->all()[SettingsSchema::PARAMETER];

        return new SettingsSchema($definitions);
    }
}
