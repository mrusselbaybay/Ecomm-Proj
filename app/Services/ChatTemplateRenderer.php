<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Order;

class ChatTemplateRenderer
{
    /** @var list<string> */
    public const PLACEHOLDERS = [
        'order_id',
        'status',
        'tracking_number',
        'tracking_link',
        'buyer_name',
        'seller_name',
    ];

    public function render(string $template, Conversation $conversation, ?Order $order): string
    {
        $trackingNumber = $order?->tracking_number ?: 'Not available yet';
        $values = [
            'order_id' => $order?->order_number ?: 'Not available',
            'status' => $order?->statusLabel() ?: 'Not available',
            'tracking_number' => $trackingNumber,
            'tracking_link' => $order?->tracking_number ? "Tracking number {$trackingNumber}" : 'Not available yet',
            'buyer_name' => $conversation->buyer?->full_name ?: 'Buyer',
            'seller_name' => $conversation->seller?->full_name ?: 'Seller',
        ];

        return preg_replace_callback(
            '/{{\s*([a-z_]+)\s*}}/i',
            fn (array $match): string => $values[strtolower($match[1])] ?? $match[0],
            $template,
        ) ?? $template;
    }

    /** @return list<string> */
    public function unsupportedPlaceholders(string $template): array
    {
        preg_match_all('/{{\s*([^{}]+)\s*}}/', $template, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $placeholder): string => strtolower(trim($placeholder)))
            ->reject(fn (string $placeholder): bool => in_array($placeholder, self::PLACEHOLDERS, true))
            ->unique()
            ->values()
            ->all();
    }
}
