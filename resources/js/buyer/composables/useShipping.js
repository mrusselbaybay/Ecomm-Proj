/*
|--------------------------------------------------------------------------
| Shipping Options
|--------------------------------------------------------------------------
|
| Flat per-parcel fees, charged once per seller order (each seller ships
| their own parcel), not per item or per destination. Shared by the
| checkout picker and the product page's delivery summary so the two can
| never quote different prices.
|
| The server is the source of truth for what is actually charged:
| App\Services\CheckoutService::SHIPPING_FEES must stay in sync with this.
|
*/

export const shippingOptions = [
    {
        id: 'standard',
        name: 'Standard Delivery',
        shortName: 'Standard',
        description: 'Estimated 3-5 days',
        eta: '3-5 days',
        fee: 60
    },
    {
        id: 'express',
        name: 'Express Delivery',
        shortName: 'Express',
        description: 'Estimated 1-2 days',
        eta: '1-2 days',
        fee: 120
    }
];
