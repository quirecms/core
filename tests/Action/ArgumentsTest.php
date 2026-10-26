<?php

declare(strict_types=1);

namespace Quire\Tests\Action;

use PHPUnit\Framework\TestCase;
use Quire\Action\ActionDefinition;
use Quire\Action\Arguments;
use Quire\Action\Exception\InvalidArgumentsException;
use Quire\Action\Parameter;
use Quire\Action\ParameterType;

final class ArgumentsTest extends TestCase
{
    private ActionDefinition $definition;

    protected function setUp(): void
    {
        $this->definition = new ActionDefinition('Approve a review', [
            new Parameter('review', ParameterType::Text, 'Review'),
            new Parameter('stars', ParameterType::Text, 'Stars', required: false, choices: ['1', '2', '3']),
            new Parameter('notify', ParameterType::Bool, 'Notify', required: false),
            new Parameter('tags', ParameterType::Text, 'Tags', required: false, multiple: true),
            new Parameter('at', ParameterType::DateTime, 'When', required: false),
            new Parameter('data', ParameterType::Value, 'Data', required: false),
        ]);
    }

    public function testValuesOfTheirParametersKind(): void
    {
        $arguments = Arguments::check('acme.approve', $this->definition, [
            'review' => '42', 'stars' => '3', 'notify' => true, 'tags' => ['a', 'b'], 'at' => '2026-12-24T09:00:00+01:00', 'data' => ['any' => [1, null]],
        ]);

        self::assertSame(['42', '3', true, ['a', 'b'], ['any' => [1, null]]], [$arguments->string('review'), $arguments->optionalString('stars'), $arguments->bool('notify'), $arguments->strings('tags'), $arguments->value('data')]);
        self::assertSame('2026-12-24T08:00:00+00:00', $arguments->dateTime('at')?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }

    public function testLeftOutAndNullAreTheSame(): void
    {
        $arguments = Arguments::check('acme.approve', $this->definition, ['review' => '42', 'stars' => null]);

        self::assertSame([false, null, false, [], null], [$arguments->has('stars'), $arguments->optionalString('stars'), $arguments->bool('notify'), $arguments->strings('tags'), $arguments->dateTime('at')]);
        self::assertSame(['review' => '42'], $arguments->all());
    }

    public function testEveryProblemIsReportedByParameter(): void
    {
        try {
            Arguments::check('acme.approve', $this->definition, ['stars' => '5', 'notify' => 'yes', 'tags' => 'a', 'at' => '2026-12-24 09:00', 'colour' => 'red']);
            self::fail('Invalid arguments were accepted.');
        } catch (InvalidArgumentsException $e) {
            self::assertSame('acme.approve', $e->action);
            self::assertSame([
                'colour' => 'The action has no such parameter.',
                'review' => 'It\'s required.',
                'stars' => 'Give one of 1, 2, 3.',
                'notify' => 'Give true or false.',
                'tags' => 'Give a list.',
                'at' => 'Give a time with its offset, e.g. "2026-12-24T09:00:00+01:00".',
            ], $e->errors);
        }
    }

    public function testTextsAreNotEmpty(): void
    {
        $this->expectExceptionMessageIsOrContains('review: Give a text.');

        Arguments::check('acme.approve', $this->definition, ['review' => '']);
    }
}
