<?php

declare(strict_types=1);

namespace Quire\Install\Console;

use Quire\Extension\ExtensionManager;
use Quire\Extension\InstalledExtensions;
use Quire\Routing\ExtensionRouteLoader;
use Quire\Runtime\PhpBinary;
use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\Settings;
use Quire\User\Console\CreateUserCommand;
use Quire\User\Console\Passwords;
use Quire\User\CreateUser;
use Quire\User\Exception\InvalidUserException;
use Quire\User\Invitations;
use Quire\User\Role;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'quire:install',
    description: 'Set up a new site: the database, the site\'s title and language, and the first admin',
    help: <<<'HELP'
        Prepares the database, asks for the site's title and language, creates the first admin, and
        enables the admin interface (the extension "quire-admin") when it's installed:

          <info>%command.full_name%</info>

        Without a terminal, pass the admin's email; a strong password is generated and shown once:

          <info>%command.full_name% --no-interaction --admin-email=ada@example.com --title="Ada's notes"</info>

        Or email the admin a link to choose their own password (it needs a mailer, MAILER_DSN), so no
        password is ever shown or passed around:

          <info>%command.full_name% --no-interaction --admin-email=ada@example.com --invite</info>

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
    /** The first-party admin interface, enabled on installation when it's installed */
    public const string ADMIN_EXTENSION = 'quire-admin';

    public function __construct(
        private UserRepository $users,
        private InstalledExtensions $extensions,
        private ExtensionManager $manager,
        private UserWriter $writer,
        private Settings $settings,
        private Invitations $invitations,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
        #[Autowire(param: ExtensionRouteLoader::ADMIN_PREFIX_PARAMETER)]
        private string $adminPrefix,
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
        #[Option(description: 'Email the admin a link to choose their password, instead of setting one')]
        bool $invite = false,
    ): int {
        $io->title('Installing Quire');

        // Before anything changes: an invitation needs a mailer.
        if ($invite && null !== $reason = $this->invitations->unavailableReason()) {
            $io->error($reason);

            return Command::FAILURE;
        }

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
        $asked = $invite ? [null, false] : Passwords::askOrReport($io, $interactive);

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

        $adminInterface = $this->enableAdminInterface($io);
        $io->success(\sprintf('Quire is installed. %s (%s) is the admin.', $admin->displayName, $admin->email).($adminInterface ? \sprintf(' Manage the site at %s.', $this->adminPrefix) : ''));

        if ($invite) {
            try {
                $this->invitations->invite($admin);
                $io->writeln(\sprintf('Invited %s by email to choose a password; the link works once, for seven days.', $admin->email));
            } catch (InvalidUserException|TransportExceptionInterface $e) {
                $io->error(\sprintf('The invitation couldn\'t be sent: %s Send it again with "bin/console quire:user:invite %s".', $e->getMessage(), $admin->email));

                return Command::FAILURE;
            }
        }

        if ($generated) {
            $io->warning(\sprintf('The admin\'s password is: %s', $password));
            $io->writeln('It isn\'t shown again. Change it with "bin/console quire:user:password".');
        }

        return Command::SUCCESS;
    }

    /**
     * @return bool Whether the site has the admin interface
     */
    private function enableAdminInterface(SymfonyStyle $io): bool
    {
        if (!$this->extensions->has(self::ADMIN_EXTENSION)) {
            return false;
        }

        if ($this->manager->isEnabled(self::ADMIN_EXTENSION)) {
            return true;
        }

        if (null !== $this->manager->readOnlyReason()) {
            $io->note(\sprintf('The admin interface isn\'t enabled: %s', $this->manager->readOnlyReason()));

            return false;
        }

        // In its own process: enabling rebuilds the application, which this process can't use any more.
        if (0 !== ($this->run)([PhpBinary::path(), $this->projectDir.'/bin/console', 'quire:extension:enable', self::ADMIN_EXTENSION, '--no-interaction', '--env='.$this->environment], $io->write(...))) {
            $io->warning(\sprintf('Enabling the admin interface failed; see the output above. Enable it with "bin/console quire:extension:enable %s".', self::ADMIN_EXTENSION));

            return false;
        }

        return true;
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
