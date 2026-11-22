<?php

declare(strict_types=1);

namespace Quire\Translation\Internal;

use Psr\Log\LoggerInterface;
use Quire\Translation\PlainTexts;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * Symfony's YAML loader for translations (both "yaml" and "yml"), except that a language artifact's files,
 * picked by their exact paths, are read as plain texts: Symfony's own loader would resolve "!php/const" and
 * autoload the class it names. They were checked when the site was built; read again here, a file that
 * stopped being plain texts since is left out, said in the log, and its texts fall back to the package's.
 *
 * @internal
 */
final readonly class ArtifactLoader implements LoaderInterface
{
    /** @var array<string, true> */
    private array $artifacts;

    /**
     * @param list<string> $files The loaded artifacts' files
     */
    public function __construct(
        private LoaderInterface $inner,
        array $files,
        private ?LoggerInterface $logger = null,
    ) {
        $this->artifacts = array_fill_keys($files, true);
    }

    public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
    {
        if (!\is_string($resource) || !isset($this->artifacts[$resource])) {
            return $this->inner->load($resource, $locale, $domain);
        }

        try {
            $catalogue = new ArrayLoader()->load(PlainTexts::read($resource), $locale, $domain);
        } catch (\InvalidArgumentException $e) {
            $this->logger?->warning('A language artifact\'s file is left out: {reason}', ['file' => $resource, 'reason' => $e->getMessage()]);
            $catalogue = new MessageCatalogue($locale);
        }

        $catalogue->addResource(new FileResource($resource));

        return $catalogue;
    }
}
