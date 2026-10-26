<?php

declare(strict_types=1);

namespace Quire\Content\Action;

use Quire\Action\ActionDefinition;
use Quire\Action\ActionInterface;
use Quire\Action\Arguments;
use Quire\Action\CommandLine;
use Quire\Action\Parameter;
use Quire\Action\ParameterType;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;

/**
 * "content.restore": take an item out of the trash.
 *
 * @internal
 */
final readonly class RestoreContent implements ActionInterface
{
    public function __construct(
        private ContentRepository $repository,
        private ContentWriter $writer,
        private ContentAccess $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Restore an item from the trash', [
            new Parameter('content', ParameterType::Content, 'Item'),
        ], command: 'quire:content:restore');
    }

    public function denied(Arguments $arguments): ?string
    {
        $item = $this->repository->get($arguments->string('content'));

        return $this->access->may($item->type, 'delete') ? null : 'You may not restore items of this type.';
    }

    public function run(Arguments $arguments): Content
    {
        $item = $this->repository->get($arguments->string('content'));

        return $this->writer->restore($item->id);
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:content:restore', [$arguments->string('content')]);
    }
}
