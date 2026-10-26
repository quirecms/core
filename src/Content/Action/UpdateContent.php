<?php

declare(strict_types=1);

namespace Quire\Content\Action;

use Quire\Action\ActionDefinition;
use Quire\Action\ActionInterface;
use Quire\Action\Arguments;
use Quire\Action\CommandLine;
use Quire\Action\Parameter;
use Quire\Action\ParameterType;
use Quire\Content\Command\UpdateContent as UpdateContentCommand;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;

/**
 * "content.update": change an item's title, slug or excerpt. The text and fields are written in an
 * editor, and go through pending revisions or {@see ContentWriter::update()}.
 *
 * @internal
 */
final readonly class UpdateContent implements ActionInterface
{
    public function __construct(
        private ContentRepository $repository,
        private ContentWriter $writer,
        private ContentAccess $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Rename an item', [
            new Parameter('content', ParameterType::Content, 'Item'),
            new Parameter('title', ParameterType::Text, 'Title', required: false),
            new Parameter('slug', ParameterType::Text, 'Slug', required: false),
            new Parameter('excerpt', ParameterType::Text, 'Excerpt', required: false),
        ], command: 'quire:content:update');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->mayEdit($this->repository->get($arguments->string('content'))) ? null : 'You may not change this item.';
    }

    public function run(Arguments $arguments): Content
    {
        $item = $this->repository->get($arguments->string('content'));
        $update = new UpdateContentCommand($item->id, $item->version, actorId: $this->access->actorId());

        $title = $arguments->optionalString('title');
        $slug = $arguments->optionalString('slug');
        $excerpt = $arguments->optionalString('excerpt');

        if (null !== $title) {
            $update->title($title);
        }

        if (null !== $slug) {
            $update->slug($slug);
        }

        if (null !== $excerpt) {
            $update->excerpt($excerpt);
        }

        return $this->writer->update($update);
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:content:update', [$arguments->string('content')], [
            'title' => $arguments->optionalString('title'),
            'slug' => $arguments->optionalString('slug'),
            'excerpt' => $arguments->optionalString('excerpt'),
        ]);
    }
}
