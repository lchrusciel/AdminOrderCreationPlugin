<?php

declare(strict_types=1);

namespace Tests\Sylius\AdminOrderCreationPlugin\Behat\Element\Admin;

use Sylius\Component\Core\Model\AddressInterface;

interface OrderCreateFormElementInterface
{
    public function addProduct(string $productName): void;

    public function addMultipleProducts(string $productName, int $quantity): void;

    public function removeProduct(string $productName): void;

    public function specifyShippingAddress(AddressInterface $address): void;

    public function specifyBillingAddress(AddressInterface $address): void;

    public function selectShippingMethod(string $shippingMethodName): void;

    public function selectPaymentMethod(string $paymentMethodName): void;

    public function specifyOrderPrice(string $orderPrice): void;

    public function specifyUnitPrice(string $itemProductName, string $unitPrice): void;

    public function placeOrder(): void;

    public function hasOrderPriceValidationMessage(string $message): bool;

    public function hasUnitPriceValidationMessage(string $productName, string $message): bool;

    public function getPreFilledBillingAddress(): AddressInterface;

    public function getPreFilledShippingAddress(): AddressInterface;

    public function getShippingMethodName(): string;

    public function getPaymentMethodName(): string;
}
