<?php

declare(strict_types=1);

namespace Quire\Content\Action;

use Quire\Action\ActionDefinition;
use Quire\Action\ActionInterface;
use Quire\Action\Arguments;
use Quire\Action\CommandLine;
use Quire\Action\Parameter;
use Quire\Action\ParameterType;
use Quire\Content\Command\CreateContent as CreateContentCommand;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\User\UserRepository;

/**
 * "content.create": start a draft of a content type, written by the signed-in user unless an author
 * is given.
 *
 * @internal
 */
final readonly class CreateContent implements ActionInterface
{
    public function __construct(
        private ContentWriter $writer,
        private UserRepository $users,
        private ContentAccess $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Create an item', [
            new Parameter('type', ParameterType::ContentType, 'Type'),
            new Parameter('title', ParameterType::Text, 'Title'),
            new Parameter('slug', ParameterType::Text, 'Slug', required: false),
            new Parameter('locale', ParameterType::Text, 'Language', required: false),
            new Parameter('parent', ParameterType::Content, 'Parent', required: false),
            new Parameter('author', ParameterType::User, 'Author', required: false),
        ], command: 'quire:content:create');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->may($arguments->string('type'), 'create') ? null : 'You may not create items of this type.';
    }

    public function run(Arguments $arguments): Content
    {
        $actor = $this->access->actorId();
        $author = $arguments->optionalString('author');

        return $this->writer->create(new CreateContentCommand(
            type: $arguments->string('type'),
            title: $arguments->string('title'),
            slug: $arguments->optionalString('slug'),
            locale: $arguments->optionalString('locale'),
            parentId: $arguments->optionalString('parent'),
            authorId: null === $author ? $actor : ($this->users->findByReference($author) ?? throw new \InvalidArgumentException(\sprintf('There is no user "%s".', $author)))->id,
            actorId: $actor,
        ));
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:content:create', [$arguments->string('type'), $arguments->string('title')], [
            'slug' => $arguments->optionalString('slug'),
            'locale' => $arguments->optionalString('locale'),
            'parent' => $arguments->optionalString('parent'),
            'author' => $arguments->optionalString('author'),
        ]);
    }
}
