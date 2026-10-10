<?php

namespace App\Support;

final class MarketplaceReportReasons
{
    /** @var array<string, string> */
    public const STORE = [
        'fraud_deception' => 'Fraud and Deception',
        'legal_regulatory' => 'Legal and Regulatory',
        'discriminatory_offensive' => 'Discriminatory or Offensive Conduct',
        'policy_violations' => 'Policy Violations',
        'product_issues' => 'Product Issues',
        'pricing_fees' => 'Pricing and Fees',
        'shipping_problems' => 'Shipping Problems',
        'customer_service' => 'Customer Service and Communication',
    ];

    /** @var array<string, string> */
    public const PRODUCT = [
        'prohibited_items' => 'Prohibited (Banned) Items',
        'counterfeit_copyright' => 'Counterfeits and Copyright',
        'offensive_items' => 'Offensive or Potentially Offensive Items',
        'fraudulent_listing' => 'Fraudulent Listings (illegal seller demands, etc.)',
        'off_platform_transactions' => 'Directing Transactions Outside Shopee',
        'others' => 'Others',
    ];

    /** @var array<string, string> */
    public const PRIORITY = [
        'fraud_deception' => 'urgent',
        'legal_regulatory' => 'urgent',
        'discriminatory_offensive' => 'urgent',
        'policy_violations' => 'high',
        'product_issues' => 'high',
        'pricing_fees' => 'normal',
        'shipping_problems' => 'normal',
        'customer_service' => 'low',
        'prohibited_items' => 'urgent',
        'counterfeit_copyright' => 'urgent',
        'offensive_items' => 'urgent',
        'fraudulent_listing' => 'high',
        'off_platform_transactions' => 'high',
        'others' => 'low',
    ];

    public static function priority(string $reason): string
    {
        return self::PRIORITY[$reason] ?? 'normal';
    }
}
