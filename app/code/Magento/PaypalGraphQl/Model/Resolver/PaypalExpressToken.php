<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\PaypalGraphQl\Model\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Url\Validator as UrlValidator;
use Magento\Checkout\Helper\Data as CheckoutHelper;
use Magento\PaypalGraphQl\Model\Provider\Checkout as CheckoutProvider;
use Magento\PaypalGraphQl\Model\Provider\Config as ConfigProvider;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magento\StoreGraphQl\Model\Service\Url;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Framework\Validation\ValidationException;

/**
 * Resolver for generating Paypal token
 */
class PaypalExpressToken implements ResolverInterface
{
    /**
     * @var GetCartForUser
     */
    private $getCartForUser;

    /**
     * @var ConfigProvider
     */
    private $configProvider;

    /**
     * @var CheckoutProvider
     */
    private $checkoutProvider;

    /**
     * @var UrlValidator
     */
    private $urlValidator;

    /**
     * @var CheckoutHelper
     */
    private $checkoutHelper;

    /**
     * @var Url
     */
    private $urlService;

    /**
     * @param GetCartForUser $getCartForUser
     * @param CheckoutProvider $checkoutProvider
     * @param ConfigProvider $configProvider
     * @param UrlValidator $urlValidator
     * @param CheckoutHelper $checkoutHelper
     * @param Url $urlService
     */
    public function __construct(
        GetCartForUser $getCartForUser,
        CheckoutProvider $checkoutProvider,
        ConfigProvider $configProvider,
        UrlValidator $urlValidator,
        CheckoutHelper $checkoutHelper,
        Url $urlService
    ) {
        $this->getCartForUser = $getCartForUser;
        $this->checkoutProvider = $checkoutProvider;
        $this->configProvider = $configProvider;
        $this->urlValidator = $urlValidator;
        $this->checkoutHelper = $checkoutHelper;
        $this->urlService = $urlService;
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        $cartId = $args['input']['cart_id'] ?? '';
        $paymentCode = $args['input']['code'] ?? '';
        $usePaypalCredit = isset($args['input']['paypal_credit']) ? $args['input']['paypal_credit'] : false;
        $usedExpressButton = isset($args['input']['express_button']) ? $args['input']['express_button'] : false;
        $customerId = $context->getUserId();

        /** @var StoreInterface $store */
        $store = $context->getExtensionAttributes()->getStore();

        $storeId = (int)$store->getId();
        $cart = $this->getCartForUser->execute($cartId, $customerId, $storeId);
        $config = $this->configProvider->getConfig($paymentCode);
        $checkout = $this->checkoutProvider->getCheckout($config, $cart);

        if ($cart->getIsMultiShipping()) {
            $cart->setIsMultiShipping(0);
            $cart->removeAllAddresses();
        }
        $checkout->setIsBml($usePaypalCredit);

        if ($customerId) {
            $checkout->setCustomerWithAddressChange(
                $cart->getCustomer(),
                $cart->getBillingAddress(),
                $cart->getShippingAddress()
            );
        } else {
            if (!$this->checkoutHelper->isAllowedGuestCheckout($cart)) {
                throw new GraphQlInputException(__("Guest checkout is disabled."));
            }
        }

        if (!empty($args['input']['urls'])) {
            $this->validateUrls($args['input']['urls'], $store);
        }
        $checkout->prepareGiropayUrls(
            $args['input']['urls']['success_url'] ?? '',
            $args['input']['urls']['cancel_url'] ?? '',
            $args['input']['urls']['pending_url'] ?? ''
        );

        try {
            $token = $checkout->start(
                $args['input']['urls']['return_url'] ?? '',
                $args['input']['urls']['cancel_url'] ?? '',
                $usedExpressButton
            );
        } catch (LocalizedException $e) {
            throw new GraphQlInputException(__($e->getMessage()));
        }

        return [
            'token' => $token,
            'paypal_urls' => [
                'start' => $checkout->getRedirectUrl(),
                'edit' => $config->getExpressCheckoutEditUrl($token)
            ]
        ];
    }

    /**
     * Validate redirect Urls
     *
     * @param string $urls
     * @param StoreInterface $store
     * @return boolean
     * @throws GraphQlInputException
     */
    private function validateUrls(array $urls, StoreInterface $store): bool
    {
        foreach ($urls as $url) {
            try {
                $this->urlService->getUrlFromPath($url, $store);
            } catch (ValidationException $e) {
                throw new GraphQlInputException(__($e));
            }
        }
        return true;
    }
}
