<?php
declare(strict_types=1);

namespace Terence\ShippingWebhook\Service;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order\Shipment;
use Psr\Log\LoggerInterface;

class WebhookService
{
    private const XML_PATH_WEBHOOK_ENABLED = 'shipping_webhook/general/enabled';
    private const XML_PATH_WEBHOOK_URL = 'shipping_webhook/general/webhook_url';
    private const XML_PATH_WEBHOOK_SECRET = 'shipping_webhook/general/secret_key';

    private Curl $curl;
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;

    public function __construct(
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->curl = $curl;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    public function sendShipmentStatusUpdate(Shipment $shipment): void
    {
        if (!$this->isWebhookEnabled()) {
            return;
        }

        $webhookUrl = $this->getWebhookUrl();
        if (!$webhookUrl) {
            $this->logger->warning('Webhook URL is not configured');
            return;
        }

        $payload = $this->preparePayload($shipment);
        $this->sendWebhook($webhookUrl, $payload);
    }

    private function isWebhookEnabled(): bool
    {
        return (bool)$this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_ENABLED);
    }

    private function getWebhookUrl(): ?string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_URL);
    }

    private function getWebhookSecret(): ?string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_SECRET);
    }

    private function preparePayload(Shipment $shipment): array
    {
        $order = $shipment->getOrder();
        return [
            'event_type' => 'shipment_status_update',
            'shipment_id' => $shipment->getId(),
            'increment_id' => $shipment->getIncrementId(),
            'order_id' => $order->getId(),
            'order_increment_id' => $order->getIncrementId(),
            'status' => $shipment->getStatus(),
            'tracking_numbers' => $this->getTrackingNumbers($shipment),
            'created_at' => $shipment->getCreatedAt(),
            'updated_at' => $shipment->getUpdatedAt()
        ];
    }

    private function getTrackingNumbers(Shipment $shipment): array
    {
        $trackingNumbers = [];
        foreach ($shipment->getAllTracks() as $track) {
            $trackingNumbers[] = [
                'number' => $track->getTrackNumber(),
                'title' => $track->getTitle(),
                'carrier_code' => $track->getCarrierCode()
            ];
        }
        return $trackingNumbers;
    }

    private function sendWebhook(string $url, array $payload): void
    {
        try {
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('X-Webhook-Signature', $this->generateSignature($payload));
            $this->curl->post($url, json_encode($payload));

            $statusCode = $this->curl->getStatus();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \Exception("Webhook request failed with status code: {$statusCode}");
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to send webhook: ' . $e->getMessage(), [
                'url' => $url,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    private function generateSignature(array $payload): string
    {
        $secret = $this->getWebhookSecret();
        if (!$secret) {
            return '';
        }

        return hash_hmac('sha256', json_encode($payload), $secret);
    }
} 