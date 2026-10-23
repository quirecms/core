<?php

declare(strict_types=1);

namespace Quire\Storage\Internal;

/**
 * One disk's configuration, as QuireBundle resolved it into the container parameter.
 *
 * @internal
 */
final readonly class DiskConfig
{
    /**
     * @param 'local'|'s3'|'service' $adapter
     */
    private function __construct(
        public string $name,
        public string $adapter,
        public bool $public,
        public string $owner,
        public ?string $label,
        public ?string $url,
        public string $path,
        public ?string $bucket,
        public string $region,
        public ?string $endpoint,
        public ?string $accessKey,
        public ?string $secretKey,
        public string $prefix,
        public bool $pathStyle,
        public ?string $service,
    ) {
    }

    /**
     * @param array<mixed> $config
     */
    public static function fromArray(string $name, array $config): self
    {
        $adapter = $config['adapter'] ?? 'local';

        if (!\in_array($adapter, ['local', 's3', 'service'], true)) {
            throw new \LogicException(\sprintf('The disk "%s" has the unknown adapter "%s".', $name, get_debug_type($adapter)));
        }

        $string = static fn (string $key, ?string $default = null): ?string => \is_string($config[$key] ?? null) && '' !== $config[$key] ? $config[$key] : $default;

        return new self(
            name: $name,
            adapter: $adapter,
            public: true === ($config['public'] ?? false),
            owner: $string('owner', 'site') ?? 'site',
            label: $string('label'),
            url: $string('url'),
            path: $string('path', '') ?? '',
            bucket: $string('bucket'),
            region: $string('region', 'us-east-1') ?? 'us-east-1',
            endpoint: $string('endpoint'),
            accessKey: $string('access_key'),
            secretKey: $string('secret_key'),
            prefix: $string('prefix', '') ?? '',
            pathStyle: true === ($config['path_style'] ?? false),
            service: $string('service'),
        );
    }

    /**
     * Where the files are, for people: "local: /app/storage/public/acme", "s3: photos/acme at https://…".
     */
    public function location(): string
    {
        return match ($this->adapter) {
            'local' => 'local: '.$this->path,
            's3' => 's3: '.$this->bucket.('' === $this->prefix ? '' : '/'.trim($this->prefix, '/')).(null === $this->endpoint ? '' : ' at '.$this->endpoint),
            'service' => 'service: '.$this->service,
        };
    }
}
