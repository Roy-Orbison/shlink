<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Entity;

use Cake\Chronos\Chronos;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Laminas\Diactoros\Uri;
use Shlinkio\Shlink\Common\Entity\AbstractEntity;
use Shlinkio\Shlink\Core\Domain\Resolver\DomainResolverInterface;
use Shlinkio\Shlink\Core\Domain\Resolver\SimpleDomainResolver;
use Shlinkio\Shlink\Core\Exception\ShortCodeCannotBeRegeneratedException;
use Shlinkio\Shlink\Core\Model\ShortUrlEdit;
use Shlinkio\Shlink\Core\Model\ShortUrlMeta;
use Shlinkio\Shlink\Core\Validation\ShortUrlMetaInputFilter;
use Shlinkio\Shlink\Importer\Model\ImportedShlinkUrl;
use Shlinkio\Shlink\Rest\Entity\ApiKey;

use function count;
use function Shlinkio\Shlink\Core\generateRandomShortCode;

class ShortUrl extends AbstractEntity
{
    private string $longUrl;
    private string $shortCode;
    private Chronos $dateCreated;
    /** @var Collection|Visit[] */
    private Collection $visits;
    /** @var Collection|Tag[] */
    private Collection $tags;
    private ?Chronos $validSince = null;
    private ?Chronos $validUntil = null;
    private ?int $maxVisits = null;
    private ?Domain $domain = null;
    private bool $customSlugWasProvided;
    private int $shortCodeLength;
    private ?string $importSource = null;
    private ?string $importOriginalShortCode = null;
    private ?ApiKey $authorApiKey = null;

    public function __construct(
        string $longUrl,
        ?ShortUrlMeta $meta = null,
        ?DomainResolverInterface $domainResolver = null
    ) {
        $meta = $meta ?? ShortUrlMeta::createEmpty();

        $this->longUrl = $longUrl;
        $this->dateCreated = Chronos::now();
        $this->visits = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->validSince = $meta->getValidSince();
        $this->validUntil = $meta->getValidUntil();
        $this->maxVisits = $meta->getMaxVisits();
        $this->customSlugWasProvided = $meta->hasCustomSlug();
        $this->shortCodeLength = $meta->getShortCodeLength();
        $this->shortCode = $meta->getCustomSlug() ?? generateRandomShortCode($this->shortCodeLength);
        $this->domain = ($domainResolver ?? new SimpleDomainResolver())->resolveDomain($meta->getDomain());
    }

    public static function fromImport(
        ImportedShlinkUrl $url,
        bool $importShortCode,
        ?DomainResolverInterface $domainResolver = null
    ): self {
        $meta = [
            ShortUrlMetaInputFilter::DOMAIN => $url->domain(),
            ShortUrlMetaInputFilter::VALIDATE_URL => false,
        ];
        if ($importShortCode) {
            $meta[ShortUrlMetaInputFilter::CUSTOM_SLUG] = $url->shortCode();
        }

        $instance = new self($url->longUrl(), ShortUrlMeta::fromRawData($meta), $domainResolver);
        $instance->importSource = $url->source();
        $instance->importOriginalShortCode = $url->shortCode();
        $instance->dateCreated = Chronos::instance($url->createdAt());

        return $instance;
    }

    public function getLongUrl(): string
    {
        return $this->longUrl;
    }

    public function getShortCode(): string
    {
        return $this->shortCode;
    }

    public function getDateCreated(): Chronos
    {
        return $this->dateCreated;
    }

    public function getDomain(): ?Domain
    {
        return $this->domain;
    }

    /**
     * @return Collection|Tag[]
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    /**
     * @param Collection|Tag[] $tags
     */
    public function setTags(Collection $tags): self
    {
        $this->tags = $tags;
        return $this;
    }

    public function update(ShortUrlEdit $shortUrlEdit): void
    {
        if ($shortUrlEdit->hasValidSince()) {
            $this->validSince = $shortUrlEdit->validSince();
        }
        if ($shortUrlEdit->hasValidUntil()) {
            $this->validUntil = $shortUrlEdit->validUntil();
        }
        if ($shortUrlEdit->hasMaxVisits()) {
            $this->maxVisits = $shortUrlEdit->maxVisits();
        }
        if ($shortUrlEdit->hasLongUrl()) {
            $this->longUrl = $shortUrlEdit->longUrl();
        }
    }

    /**
     * @throws ShortCodeCannotBeRegeneratedException
     */
    public function regenerateShortCode(): void
    {
        // In ShortUrls where a custom slug was provided, throw error, unless it is an imported one
        if ($this->customSlugWasProvided && $this->importSource === null) {
            throw ShortCodeCannotBeRegeneratedException::forShortUrlWithCustomSlug();
        }

        // The short code can be regenerated only on ShortUrl which have not been persisted yet
        if ($this->id !== null) {
            throw ShortCodeCannotBeRegeneratedException::forShortUrlAlreadyPersisted();
        }

        $this->shortCode = generateRandomShortCode($this->shortCodeLength);
    }

    public function getValidSince(): ?Chronos
    {
        return $this->validSince;
    }

    public function getValidUntil(): ?Chronos
    {
        return $this->validUntil;
    }

    public function getVisitsCount(): int
    {
        return count($this->visits);
    }

    /**
     * @param Collection|Visit[] $visits
     * @internal
     */
    public function setVisits(Collection $visits): self
    {
        $this->visits = $visits;
        return $this;
    }

    public function getMaxVisits(): ?int
    {
        return $this->maxVisits;
    }

    public function isEnabled(): bool
    {
        $maxVisitsReached = $this->maxVisits !== null && $this->getVisitsCount() >= $this->maxVisits;
        if ($maxVisitsReached) {
            return false;
        }

        $now = Chronos::now();
        $beforeValidSince = $this->validSince !== null && $this->validSince->gt($now);
        if ($beforeValidSince) {
            return false;
        }

        $afterValidUntil = $this->validUntil !== null && $this->validUntil->lt($now);
        if ($afterValidUntil) {
            return false;
        }

        return true;
    }

    public function toString(array $domainConfig): string
    {
        return (string) (new Uri())->withPath($this->shortCode)
                                   ->withScheme($domainConfig['schema'] ?? 'http')
                                   ->withHost($this->resolveDomain($domainConfig['hostname'] ?? ''));
    }

    private function resolveDomain(string $fallback = ''): string
    {
        if ($this->domain === null) {
            return $fallback;
        }

        return $this->domain->getAuthority();
    }
}
