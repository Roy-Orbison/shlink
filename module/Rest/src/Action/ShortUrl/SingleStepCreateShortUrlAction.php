<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Rest\Action\ShortUrl;

use Laminas\Diactoros\Uri;
use Psr\Http\Message\ServerRequestInterface as Request;
use Shlinkio\Shlink\Core\Exception\ValidationException;
use Shlinkio\Shlink\Core\Model\CreateShortUrlData;
use Shlinkio\Shlink\Core\Service\UrlShortenerInterface;
use Shlinkio\Shlink\Rest\Service\ApiKeyServiceInterface;

class SingleStepCreateShortUrlAction extends AbstractCreateShortUrlAction
{
    protected const ROUTE_PATH = '/short-urls/shorten';
    protected const ROUTE_ALLOWED_METHODS = [self::METHOD_GET];

    private ApiKeyServiceInterface $apiKeyService;

    public function __construct(
        UrlShortenerInterface $urlShortener,
        ApiKeyServiceInterface $apiKeyService,
        array $domainConfig
    ) {
        parent::__construct($urlShortener, $domainConfig);
        $this->apiKeyService = $apiKeyService;
    }

    /**
     * @throws ValidationException
     */
    protected function buildShortUrlData(Request $request): CreateShortUrlData
    {
        $query = $request->getQueryParams();

        if (! $this->apiKeyService->check($query['apiKey'] ?? '')) {
            throw ValidationException::fromArray([
                'apiKey' => 'No API key was provided or it is not valid',
            ]);
        }

        if (! isset($query['longUrl'])) {
            throw ValidationException::fromArray([
                'longUrl' => 'A URL was not provided',
            ]);
        }

        return new CreateShortUrlData($query['longUrl']);
    }
}
