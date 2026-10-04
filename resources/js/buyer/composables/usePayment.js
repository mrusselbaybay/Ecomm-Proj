/*
|--------------------------------------------------------------------------
| Payment Methods
|--------------------------------------------------------------------------
|
| The payment methods checkout can actually complete. The server is the
| source of truth: App\Services\CheckoutService::PAYMENT_METHODS must stay
| in sync with the ids here, and CheckoutRequest rejects anything else.
|
| Only cash on delivery is supported today. There is no payment gateway,
| so every order is created with payment_status "Unpaid" and paid to the
| courier on arrival.
|
| Card, GCash and Maya are listed with available: false so the selector
| can show them as "Coming soon" while the design is being finished. They
| can't be selected or submitted. Flip one to true only once a provider is
| integrated and its id is added to CheckoutService::PAYMENT_METHODS;
| BuyTheWay must never collect raw card details itself.
|
*/

export const paymentMethods = [
    {
        id: 'cod',
        name: 'Cash on Delivery',
        description: 'Pay the courier in cash when your parcel arrives. Nothing is charged online.',
        fee: 0,
        available: true
    },
    {
        id: 'gcash',
        name: 'GCash',
        description: 'Pay from your GCash wallet.',
        fee: 0,
        available: false
    },
    {
        id: 'maya',
        name: 'Maya',
        description: 'Pay from your Maya wallet.',
        fee: 0,
        available: false
    },
    {
        id: 'card',
        name: 'Credit / Debit Card',
        description: 'Visa, Mastercard and other cards.',
        fee: 0,
        available: false
    }
];

/**
 * Methods the buyer may actually choose for the current checkout. Cash on
 * delivery has no order or address restrictions yet; restrictions go here
 * when the backend adds them.
 *
 * @returns {Array<{id: string, name: string, description: string, fee: number, available: boolean}>}
 */
export function availablePaymentMethods() {
    return paymentMethods.filter(method => method.available);
}
