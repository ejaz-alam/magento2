<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\StoreGraphQl\Model\Resolver\Store;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\Url\Validator as UrlValidator;
use Magento\Framework\Validation\ValidationException;

/**
 * Service class for scoped urls
 */
class Url
{
    /** @var UrlValidator */
    private $urlValidator;

    /** @var UrlInterface */
    private $urlInterface;

    /**
     * @param UrlValidator $urlValidator
     * @param UrlInterface $urlInterface
     */
    public function __construct(UrlValidator $urlValidator, UrlInterface $urlInterface)
    {
        $this->urlValidator = $urlValidator;
        $this->urlInterface = $urlInterface;
    }

    /**
     * Get full url with base path from a path
     *
     * @param string $path
     * @param StoreInterface $store
     * @param bool $isSecure
     * @return string
     * @throws ValidationException
     */
    public function getUrlFromPath(string $path, StoreInterface $store): string
    {
        if (preg_match("|^(https?:)?\/\/|i", $path)
        ) {
            throw new ValidationException(__('Invalid Url.'));
        }

        $params = ["_secure" => $store->isCurrentlySecure()];
        $this->urlInterface->setScope($store);

        $baseUrl = $this->urlInterface->getBaseUrl($params);
        $resultUrl = $this->urlInterface->getUrl($path, $params);

        // validate the resulting url
        if (substr($resultUrl, 0, strlen($baseUrl)) != $baseUrl
            || $path == $resultUrl
            || $resultUrl == $baseUrl
            || !$this->validateUrl($resultUrl)
        ) {
            throw new ValidationException(__('Invalid Url.'));
        }

        return $resultUrl;
    }

    /**
     * Validate redirect Urls
     *
     * @param array $urls
     * @return boolean
     */
    private function validateUrl(string $url): bool
    {
        if (!$this->urlValidator->isValid($url)) {
            return false;
        }
        return true;
    }
}
