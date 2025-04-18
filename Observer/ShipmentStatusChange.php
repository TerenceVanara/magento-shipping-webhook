<?php
declare(strict_types=1);

namespace Terence\ShippingWebhook\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Terence\ShippingWebhook\Service\WebhookService;
use Psr\Log\LoggerInterface;

class ShipmentStatusChange implements ObserverInterface
{
    private WebhookService $webhookService;
    private LoggerInterface $logger;

    public function __construct(
        WebhookService $webhookService,
        LoggerInterface $logger
    ) {
        $this->webhookService = $webhookService;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            $shipment = $observer->getEvent()->getShipment();
            if (!$shipment) {
                return;
            }

            $this->webhookService->sendShipmentStatusUpdate($shipment);
        } catch (\Exception $e) {
            $this->logger->error('Error processing shipment status webhook: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }
} 