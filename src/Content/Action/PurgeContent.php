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
 * "content.purge": delete an item permanently, with its revisions.
 *
 * @internal
 */
final readonly class PurgeContent implements ActionInterface
{
    public function __construct(
        private ContentRepository $repository,
        private ContentWriter $writer,
        private ContentAccess $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Delete an item permanently', [
            new Parameter('content', ParameterType::Content, 'Item'),
        ], command: 'quire:content:delete', destructive: true);
    }

    public function denied(Arguments $arguments): ?string
    {
        $item = $this->repository->get($arguments->string('content'));

        return $this->access->may($item->type, 'delete') ? null : 'You may not delete items of this type.';
    }

    public function run(Arguments $arguments): Content
    {
        $item = $this->repository->get($arguments->string('content'));

        return $this->writer->purge($item->id);
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:content:delete', [$arguments->string('content')], ['purge' => true, 'force' => true]);
    }
}
