<?php
declare(strict_types=1);

namespace Terence\ShippingWebhook\Service;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order\Shipment;
use Psr\Log\LoggerInterface;
use Magento\Framework\Exception\LocalizedException;

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
        try {
            if (!$this->isWebhookEnabled()) {
                $this->logger->info('Webhook is disabled, skipping shipment status update');
                return;
            }

            $webhookUrl = $this->getWebhookUrl();
            if (!$webhookUrl) {
                throw new LocalizedException(__('Webhook URL is not configured'));
            }

            $payload = $this->preparePayload($shipment);
            $this->validatePayload($payload);
            
            $this->logger->info('Sending webhook for shipment', [
                'shipment_id' => $shipment->getId(),
                'order_id' => $shipment->getOrder()->getId(),
                'tracking_number' => $payload['tracking_number'] ?? 'N/A'
            ]);

            $this->sendWebhook($webhookUrl, $payload);
            
            $this->logger->info('Webhook sent successfully', [
                'shipment_id' => $shipment->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to process webhook: ' . $e->getMessage(), [
                'shipment_id' => $shipment->getId(),
                'exception' => $e
            ]);
            throw $e;
        }
    }

    private function validatePayload(array $payload): void
    {
        $requiredFields = [
            'reference',
            'created_at',
            'auth_user_id',
            'recipient_address',
            'recipient_zipcode',
            'recipient_city',
            'recipient_country'
        ];

        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (empty($payload[$field])) {
                $missingFields[] = $field;
            }
        }

        if (!empty($missingFields)) {
            throw new LocalizedException(
                __('Missing required fields: %1', implode(', ', $missingFields))
            );
        }
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
        $shippingAddress = $order->getShippingAddress();
        $billingAddress = $order->getBillingAddress();
        $track = $shipment->getAllTracks()[0] ?? null;
        
        // Calculate total value of shipped items
        $parcelValue = 0;
        foreach ($shipment->getAllItems() as $item) {
            $parcelValue += $item->getPrice() * $item->getQty();
        }

        // Get total weight of shipment
        $totalWeight = 0;
        foreach ($shipment->getAllItems() as $item) {
            $totalWeight += ($item->getWeight() ?? 0) * $item->getQty();
        }

        // Format items for content description
        $contentItems = [];
        foreach ($shipment->getAllItems() as $item) {
            $contentItems[] = $item->getName() . ' x' . (int)$item->getQty();
        }

        // Get store locale for recipient language
        $recipientLang = $order->getStore()->getLocaleCode() ?? 'fr_FR';
        $recipientLang = substr($recipientLang, 0, 2); // Extract just the language code (e.g., 'fr' from 'fr_FR')

        return [
            'reference' => $shipment->getIncrementId(),
            'tracking_number' => $track ? $track->getTrackNumber() : null,
            'carrier' => $track ? $track->getCarrierCode() : null,
            'parcel_value' => $parcelValue,
            'created_at' => $shipment->getCreatedAt(),
            'auth_user_id' => $order->getCustomerId(),
            'parcel_currency' => $order->getOrderCurrencyCode(),
            'parcel_category' => 'standard', // Default category
            'sender_company' => $billingAddress ? $billingAddress->getCompany() : null,
            'sender_first_name' => $billingAddress ? $billingAddress->getFirstname() : null,
            'sender_last_name' => $billingAddress ? $billingAddress->getLastname() : null,
            'sender_address' => $billingAddress ? implode(', ', $billingAddress->getStreet()) : null,
            'sender_zipcode' => $billingAddress ? $billingAddress->getPostcode() : null,
            'sender_city' => $billingAddress ? $billingAddress->getCity() : null,
            'sender_country' => $billingAddress ? $billingAddress->getCountryId() : null,
            'recipient_company' => $shippingAddress ? $shippingAddress->getCompany() : null,
            'recipient_first_name' => $shippingAddress ? $shippingAddress->getFirstname() : null,
            'recipient_last_name' => $shippingAddress ? $shippingAddress->getLastname() : null,
            'recipient_address' => $shippingAddress ? implode(', ', $shippingAddress->getStreet()) : null,
            'recipient_zipcode' => $shippingAddress ? $shippingAddress->getPostcode() : null,
            'recipient_city' => $shippingAddress ? $shippingAddress->getCity() : null,
            'recipient_country' => $shippingAddress ? $shippingAddress->getCountryId() : null,
            'content' => implode(', ', $contentItems),
            'protection_id' => null,
            'covered_value' => $parcelValue, // Using parcel value as covered value
            'user_email' => $order->getCustomerEmail(),
            'parcel_weight' => $totalWeight,
            'estimate_delivery_date' => $track ? $track->getEstimatedDelivery() : null,
            'tracking_link' => $this->generateTrackingLink($track),
            'sending_mode' => $order->getShippingMethod(),
            'recipient_email' => $shippingAddress ? $shippingAddress->getEmail() : null,
            'recipient_lang' => $recipientLang,
            'recipient_notif' => true,
            'claimer_email' => $order->getCustomerEmail(),
            'cart_data' => json_encode($this->getCartData($shipment)),
            'payment_mode' => $order->getPayment() ? $order->getPayment()->getMethod() : null
        ];
    }

    private function generateTrackingLink(?Shipment\Track $track): ?string
    {
        if (!$track) {
            return null;
        }

        // Add carrier-specific tracking URL generation
        $carrierCode = strtolower($track->getCarrierCode());
        $trackingNumber = $track->getTrackNumber();

        switch ($carrierCode) {
            case 'ups':
                return "https://www.ups.com/track?tracknum={$trackingNumber}";
            case 'fedex':
                return "https://www.fedex.com/fedextrack/?trknbr={$trackingNumber}";
            case 'usps':
                return "https://tools.usps.com/go/TrackConfirmAction?tLabels={$trackingNumber}";
            case 'dhl':
                return "https://www.dhl.com/en/express/tracking.html?AWB={$trackingNumber}";
            default:
                return null;
        }
    }

    private function getCartData(Shipment $shipment): array
    {
        $items = [];
        foreach ($shipment->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();
            $items[] = [
                'sku' => $orderItem->getSku(),
                'name' => $orderItem->getName(),
                'qty' => $item->getQty(),
                'price' => $orderItem->getPrice(),
                'row_total' => $orderItem->getRowTotal()
            ];
        }
        
        $order = $shipment->getOrder();
        return [
            'items' => $items,
            'totals' => [
                'subtotal' => $order->getSubtotal(),
                'shipping_amount' => $order->getShippingAmount(),
                'tax_amount' => $order->getTaxAmount(),
                'grand_total' => $order->getGrandTotal()
            ]
        ];
    }

    private function sendWebhook(string $url, array $payload): void
    {
        try {
            $this->curl->addHeader('Content-Type', 'application/json');
            
            $signature = $this->generateSignature($payload);
            if ($signature) {
                $this->curl->addHeader('X-Webhook-Signature', $signature);
            }

            $this->logger->debug('Sending webhook request', [
                'url' => $url,
                'payload' => $payload
            ]);

            $this->curl->post($url, json_encode($payload));

            $statusCode = $this->curl->getStatus();
            $response = $this->curl->getBody();

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new LocalizedException(
                    __('Webhook request failed with status code: %1, response: %2', $statusCode, $response)
                );
            }

            $this->logger->debug('Webhook response received', [
                'status_code' => $statusCode,
                'response' => $response
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send webhook', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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