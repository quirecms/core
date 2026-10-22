<?php

declare(strict_types=1);

namespace Quire\Install\Console;

use Quire\Runtime\PhpBinary;
use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\Settings;
use Quire\User\Console\CreateUserCommand;
use Quire\User\Console\Passwords;
use Quire\User\CreateUser;
use Quire\User\Exception\InvalidUserException;
use Quire\User\Role;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'quire:install',
    description: 'Set up a new site: the database, the site\'s title and language, and the first admin',
    help: <<<'HELP'
        Prepares the database, asks for the site's title and language, and creates the first admin:

          <info>%command.full_name%</info>

        Without a terminal, pass the admin's email; a strong password is generated and shown once:

          <info>%command.full_name% --no-interaction --admin-email=ada@example.com --title="Ada's notes"</info>

        Once the site has an admin, it changes nothing: create more users with "quire:user:create".
        HELP,
)]
final readonly class InstallCommand
{
    /** @var \Closure(list<string>, \Closure(string): void): int */
    private \Closure $run;

    /**
     * @param (\Closure(list<string>, \Closure(string): void): int)|null $run Runs a command and returns its exit code; replaceable in tests
     */
    public function __construct(
        private UserRepository $users,
        private UserWriter $writer,
        private Settings $settings,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
        ?\Closure $run = null,
    ) {
        $this->run = $run ?? $this->runProcess(...);
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Option(description: 'The site\'s title')]
        ?string $title = null,
        #[Option(description: 'The site\'s language, e.g. "en" or "de"')]
        ?string $locale = null,
        #[Option(description: 'The first admin\'s email')]
        ?string $adminEmail = null,
        #[Option(description: 'The first admin\'s name; derived from the email by default')]
        ?string $adminName = null,
    ): int {
        $io->title('Installing Quire');

        // In a new process: the database may not have Quire's tables yet, which this one was built without.
        if (0 !== ($this->run)([PhpBinary::path(), $this->projectDir.'/bin/console', 'quire:migrations:migrate', '--no-interaction', '--allow-no-migration', '--env='.$this->environment], $io->write(...))) {
            $io->error('Preparing the database failed; see the output above.');

            return Command::FAILURE;
        }

        $admins = $this->users->all(Role::ADMIN, 1);

        if ([] !== $admins) {
            $io->success(\sprintf('Quire is already installed: %s is an admin. Create more users with "bin/console quire:user:create".', $admins[0]->email ?? $admins[0]->username));

            return Command::SUCCESS;
        }

        $interactive = $input->isInteractive();
        $title ??= $interactive ? self::text($io->ask('Site title', $this->settings->getString('site.title'))) : null;
        $locale ??= $interactive ? self::text($io->ask('Site language, as a language tag such as "en" or "de"', $this->settings->getString('site.locale'))) : null;
        $adminEmail ??= $interactive ? self::text($io->ask('Your email (you\'ll be the admin)', null, static fn (mixed $value): string => '' !== trim(self::text($value)) ? self::text($value) : throw new \RuntimeException('Your email is needed: you\'ll sign in with it.'))) : null;

        if (null === $adminEmail || '' === trim($adminEmail)) {
            $io->error('The first admin needs an email: pass --admin-email.');

            return Command::FAILURE;
        }

        $adminName ??= $interactive ? self::text($io->ask('Your name', CreateUserCommand::nameFromEmail($adminEmail))) : CreateUserCommand::nameFromEmail($adminEmail);
        $asked = Passwords::askOrReport($io, $interactive);

        if (null === $asked) {
            return Command::FAILURE;
        }

        [$password, $generated] = $asked;

        try {
            foreach (['site.title' => $title, 'site.locale' => $locale] as $key => $value) {
                if (null !== $value && $value !== $this->settings->get($key)) {
                    $this->settings->set($key, $value);
                }
            }

            $admin = $this->writer->create(new CreateUser(displayName: $adminName, email: $adminEmail, password: $password, roles: [Role::ADMIN]));
        } catch (InvalidUserException $e) {
            $io->error(array_values($e->violations));

            return Command::FAILURE;
        } catch (InvalidSettingValueException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Quire is installed. %s (%s) is the admin.', $admin->displayName, $admin->email));

        if ($generated) {
            $io->warning(\sprintf('The admin\'s password is: %s', $password));
            $io->writeln('It isn\'t shown again. Change it with "bin/console quire:user:password".');
        }

        return Command::SUCCESS;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /**
     * @param list<string>           $command
     * @param \Closure(string): void $output
     */
    private function runProcess(array $command, \Closure $output): int
    {
        return new Process($command, timeout: 600)->run(static function (string $type, string $buffer) use ($output): void {
            $output($buffer);
        });
    }
}
