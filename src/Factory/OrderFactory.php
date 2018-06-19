<?php

declare(strict_types=1);

namespace Sylius\AdminOrderCreationPlugin\Factory;

use Sylius\AdminOrderCreationPlugin\Entity\OrderItemInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Payment\Factory\PaymentFactoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

final class OrderFactory implements OrderFactoryInterface
{
    /** @var FactoryInterface */
    private $decoratedFactory;

    /** @var FactoryInterface */
    private $customerFactory;

    /** @var FactoryInterface */
    private $orderItemFactory;

    /** @var FactoryInterface */
    private $shipmentFactory;

    /** @var PaymentFactoryInterface */
    private $paymentFactory;

    /** @var CustomerRepositoryInterface */
    private $customerRepository;

    /** @var ChannelRepositoryInterface */
    private $channelRepository;

    /** @var RepositoryInterface */
    private $currencyRepository;

    /** @var RepositoryInterface */
    private $localeRepository;

    /** @var OrderModifierInterface */
    private $orderModifier;

    /** @var OrderItemQuantityModifierInterface */
    private $orderItemQuantityModifier;

    public function __construct(
        FactoryInterface $decoratedFactory,
        FactoryInterface $customerFactory,
        FactoryInterface $orderItemFactory,
        FactoryInterface $shipmentFactory,
        PaymentFactoryInterface $paymentFactory,
        CustomerRepositoryInterface $customerRepository,
        ChannelRepositoryInterface $channelRepository,
        RepositoryInterface $currencyRepository,
        RepositoryInterface $localeRepository,
        OrderModifierInterface $orderModifier,
        OrderItemQuantityModifierInterface $orderItemQuantityModifier
    ) {
        $this->decoratedFactory = $decoratedFactory;
        $this->customerFactory = $customerFactory;
        $this->orderItemFactory = $orderItemFactory;
        $this->shipmentFactory = $shipmentFactory;
        $this->paymentFactory = $paymentFactory;
        $this->customerRepository = $customerRepository;
        $this->channelRepository = $channelRepository;
        $this->currencyRepository = $currencyRepository;
        $this->localeRepository = $localeRepository;
        $this->orderModifier = $orderModifier;
        $this->orderItemQuantityModifier = $orderItemQuantityModifier;
    }

    public function createNew(): OrderInterface
    {
        $order = $this->decoratedFactory->createNew();
        assert($order instanceof OrderInterface);

        return $order;
    }

    public function createForCustomer(string $customerEmail): OrderInterface
    {
        $customer = $this->getCustomerForOrder($customerEmail);

        $order = $this->decoratedFactory->createNew();
        assert($order instanceof OrderInterface);

        $order->setCustomer($customer);
        $order->setChannel($this->channelRepository->findOneBy(['enabled' => true]));

        $currency = $this->currencyRepository->findOneBy([]);
        assert($currency instanceof CurrencyInterface);
        $order->setCurrencyCode($currency->getCode());

        $locale = $this->localeRepository->findOneBy([]);
        assert($locale instanceof LocaleInterface);
        $order->setLocaleCode($locale->getCode());

        return $order;
    }

    public function createFromExistingOrder(OrderInterface $order): OrderInterface
    {
        $reorder = $this->decoratedFactory->createNew();
        assert($reorder instanceof OrderInterface);

        $reorder->setChannel($order->getChannel());
        $reorder->setCustomer($order->getCustomer());
        $reorder->setCurrencyCode($order->getCurrencyCode());
        $reorder->setNotes($order->getNotes());
        $reorder->setLocaleCode($order->getLocaleCode());

        /** @var AddressInterface $billingAddress */
        $billingAddress = $order->getBillingAddress();

        /** @var AddressInterface $shippingAddress */
        $shippingAddress = $order->getShippingAddress();
        $reorder->setBillingAddress(clone $billingAddress);
        $reorder->setShippingAddress(clone $shippingAddress);

        $this->copyShipmentToReorder($order, $reorder);
        $this->copyPaymentToReorder($order, $reorder);
        $this->copyOrderItemsToReorder($order, $reorder);

        return $reorder;
    }

    private function getCustomerForOrder(string $email): CustomerInterface
    {
        $customer = $this->customerRepository->findOneBy(['email' => $email]);

        if (null === $customer) {
            $customer = $this->customerFactory->createNew();
            assert($customer instanceof CustomerInterface);

            $customer->setEmail($email);
        }

        assert($customer instanceof CustomerInterface);

        return $customer;
    }

    private function copyOrderItemsToReorder(OrderInterface $order, OrderInterface $reorder): void
    {
        $orderItems = $order->getItems();

        /** @var OrderItemInterface $orderItem */
        foreach ($orderItems as $orderItem) {
            /** @var ProductVariantInterface $productVariant */
            $productVariant = $orderItem->getVariant();
            if ($productVariant->isTracked() && !$productVariant->isInStock()) {
                continue;
            }

            /** @var OrderItemInterface $newItem */
            $newItem = $this->orderItemFactory->createNew();

            $newItem->setVariant($orderItem->getVariant());
            $newItem->setUnitPrice($orderItem->getUnitPrice());
            $newItem->setProductName($orderItem->getProductName());
            $newItem->setVariantName($orderItem->getVariantName());

            $this->orderItemQuantityModifier->modify($newItem, $orderItem->getQuantity());
            $this->orderModifier->addToOrder($reorder, $newItem);
        }
    }

    private function copyShipmentToReorder(OrderInterface $order, OrderInterface $reorder): void
    {
        if (!$order->hasShipments()) {
            return;
        }

        /** @var ShipmentInterface $shipment */
        foreach ($order->getShipments() as $shipment) {
            if (ShipmentInterface::STATE_CANCELLED === $shipment->getState()) {
                continue;
            }

            /** @var ShipmentInterface $newShipment */
            $newShipment = $this->shipmentFactory->createNew();
            $newShipment->setOrder($reorder);
            $newShipment->setMethod($shipment->getMethod());

            $reorder->addShipment($newShipment);
        }
    }

    private function copyPaymentToReorder(OrderInterface $order, OrderInterface $reorder): void
    {
        if (!$order->hasPayments()) {
            return;
        }

        /** @var PaymentInterface $payment */
        foreach ($order->getPayments() as $payment) {
            if (
                PaymentInterface::STATE_CANCELLED === $payment->getState() ||
                PaymentInterface::STATE_FAILED === $payment->getState()
            ) {
                continue;
            }

            /** @var PaymentInterface $newPayment */
            $newPayment = $this->paymentFactory->createNew();
            $newPayment->setOrder($reorder);
            $newPayment->setMethod($payment->getMethod());

            $reorder->addPayment($newPayment);
        }
    }
}
