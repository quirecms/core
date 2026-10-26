<?php

declare(strict_types=1);

namespace Quire\Action;

/**
 * Something people do with the site, e.g. publish an item or give a role an ability: what it needs, who
 * may do it, doing it, and the console command that does the same. Register actions with
 * {@see \Quire\Extend\Actions}; run them through {@see Actions}, which checks the arguments against
 * the definition and asks {@see denied()} first.
 *
 * Actions do their work through Quire's services (or the extension's own), so whatever records or
 * reacts to the change, e.g. the audit log, sees it the same way however it was made.
 *
 * @api
 */
interface ActionInterface
{
    /** What the action is called and what it needs. */
    public function definition(): ActionDefinition;

    /**
     * Why the signed-in user may not do it with these arguments, in a sentence for them; null when they
     * may. Checks only access: whether the action can succeed is up to {@see run()}.
     */
    public function denied(Arguments $arguments): ?string;

    /**
     * Does it. Throws the services' exceptions when it can't, e.g. when a transition isn't possible.
     *
     * @return object|null What it created or changed, e.g. the content item
     */
    public function run(Arguments $arguments): ?object;

    /**
     * The console command that does the same, as typed in a shell (see {@see CommandLine}); null when
     * these arguments have none, e.g. because a value is secret.
     */
    public function commandLine(Arguments $arguments): ?string;
}
