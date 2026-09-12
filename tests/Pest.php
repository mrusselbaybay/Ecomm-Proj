<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Profile;
use App\Models\SellerDetail;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Buyer API test helpers
|--------------------------------------------------------------------------
|
| The buyer API sits behind 'supabase.auth' (verifies a Supabase bearer
| token against GoTrue) + 'buyer' (approved, active buyer). These helpers
| stand up a buyer/seller profile and fake the GoTrue lookup so a test
| request authenticates as that buyer.
|
*/

function makeBuyer(array $overrides = []): Profile
{
    return Profile::create(array_merge([
        'id' => (string) Str::uuid(),
        'role' => 'buyer',
        'status' => 'approved',
        'account_status' => 'active',
        'first_name' => 'Test',
        'last_name' => 'Buyer',
        'email' => 'buyer_'.Str::random(8).'@example.test',
    ], $overrides));
}

function makeSeller(array $overrides = []): Profile
{
    $seller = Profile::create(array_merge([
        'id' => (string) Str::uuid(),
        'role' => 'seller',
        'status' => 'approved',
        'account_status' => 'active',
        'first_name' => 'Test',
        'last_name' => 'Seller',
        'email' => 'seller_'.Str::random(8).'@example.test',
    ], $overrides));

    SellerDetail::create([
        'profile_id' => $seller->id,
        'business_name' => 'Test Storefront',
        'line_of_business' => 'Electronics and Gadgets',
    ]);

    return $seller;
}

function makeAdmin(array $overrides = []): Profile
{
    return Profile::create(array_merge([
        'id' => (string) Str::uuid(),
        'role' => 'admin',
        'status' => 'approved',
        'account_status' => 'active',
        'first_name' => 'Test',
        'last_name' => 'Admin',
        'email' => 'admin_'.Str::random(8).'@example.test',
    ], $overrides));
}

function makeLogistics(array $overrides = []): Profile
{
    return Profile::create(array_merge([
        'id' => (string) Str::uuid(),
        'role' => 'logistics',
        'status' => 'approved',
        'account_status' => 'active',
        'first_name' => 'Test',
        'last_name' => 'Logistics',
        'email' => 'logistics_'.Str::random(8).'@example.test',
    ], $overrides));
}

function makeCourier(array $overrides = []): Profile
{
    return Profile::create(array_merge([
        'id' => (string) Str::uuid(),
        'role' => 'courier',
        'status' => 'approved',
        'account_status' => 'active',
        'first_name' => 'Test',
        'last_name' => 'Courier',
        'email' => 'courier_'.Str::random(8).'@example.test',
    ], $overrides));
}

function makeProduct(Profile $seller, array $overrides = []): Product
{
    return Product::create(array_merge([
        'seller_id' => $seller->id,
        'name' => 'Test Product',
        'category' => 'Electronics and Gadgets',
        'price' => 100,
        'stock' => 10,
        'status' => 'active',
        'images' => [],
    ], $overrides));
}

/**
 * @return array{0: Order, 1: OrderItem}
 */
function makeOrder(Profile $buyer, Profile $seller, array $overrides = []): array
{
    $order = Order::create(array_merge([
        'order_number' => 'SN-'.random_int(10000, 99999),
        'seller_id' => $seller->id,
        'buyer_profile_id' => $buyer->id,
        'recipient_name' => 'Test Buyer',
        'status' => 'New',
        'payment_status' => 'Unpaid',
        'subtotal' => 100,
        'shipping_fee' => 60,
        'total' => 160,
        'placed_at' => now(),
    ], $overrides));

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => null,
        'product_name' => 'Test Product',
        'category' => 'Electronics and Gadgets',
        'unit_price' => 100,
        'quantity' => 1,
        'subtotal' => 100,
    ]);

    OrderStatusHistory::create([
        'order_id' => $order->id,
        'status' => $order->status,
        'note' => 'Order placed by buyer.',
        'changed_by' => $buyer->id,
    ]);

    return [$order, $item];
}

function makeBuyerSellerConversation(
    Profile $buyer,
    Profile $seller,
    ?Product $product = null,
    ?Order $order = null,
    array $overrides = [],
): Conversation {
    $product ??= $order ? null : makeProduct($seller);
    $type = $order ? 'order' : 'product';
    $contextId = $order?->id ?? $product->id;

    $conversation = Conversation::create(array_merge([
        'type' => $type,
        'created_by' => $buyer->id,
        'context_key' => Conversation::makeContextKey($type, $contextId, [$buyer->id, $seller->id]),
        'buyer_id' => $buyer->id,
        'seller_id' => $seller->id,
        'order_id' => $order?->id,
        'product_id' => $product?->id,
        'status' => 'open',
    ], $overrides));

    foreach ([$buyer->id, $seller->id] as $participantId) {
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $participantId,
            'joined_at' => now(),
        ]);
    }

    return $conversation;
}

/**
 * @return array{0: SupportTicket, 1: Conversation}
 */
function makeSupportTicket(Profile $creator, array $overrides = []): array
{
    $ticket = SupportTicket::create(array_merge([
        'ticket_number' => 'CS-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
        'created_by' => $creator->id,
        'category' => 'account',
        'subject' => 'Help with my account',
        'description' => 'I need assistance with my account.',
        'priority' => 'normal',
        'status' => 'submitted',
    ], $overrides));

    $conversation = Conversation::create([
        'type' => 'support',
        'created_by' => $creator->id,
        'context_key' => Conversation::makeContextKey('support', $ticket->id, [$creator->id]),
        'buyer_id' => $creator->role === 'buyer' ? $creator->id : null,
        'seller_id' => $creator->role === 'seller' ? $creator->id : null,
        'support_ticket_id' => $ticket->id,
        'subject' => $ticket->subject,
        'status' => 'active',
    ]);

    ConversationParticipant::create([
        'conversation_id' => $conversation->id,
        'user_id' => $creator->id,
        'joined_at' => now(),
    ]);

    if ($ticket->assigned_admin_id) {
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $ticket->assigned_admin_id,
            'joined_at' => now(),
        ]);
    }

    Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $creator->id,
        'sender_role' => $creator->role,
        'message_type' => 'text',
        'body' => $ticket->description,
    ]);

    return [$ticket, $conversation];
}

function actingAsBuyer(Profile $buyer): void
{
    actingAsProfile($buyer);
}

function actingAsSeller(Profile $seller): void
{
    actingAsProfile($seller);
}

function actingAsProfile(Profile $profile): void
{
    $token = 'test-token-'.$profile->id;
    $supabaseUrl = 'https://unit-test-'.$profile->id.'.supabase.co';

    config([
        'services.supabase.url' => $supabaseUrl,
        'services.supabase.anon_key' => 'test-anon-key',
    ]);

    Http::fake([
        $supabaseUrl.'/auth/v1/user' => Http::response(['id' => $profile->id], 200),
    ]);

    test()->withHeader('Authorization', 'Bearer '.$token);
}

function actingAsDriver(Profile $driver): void
{
    $token = 'test-token-'.$driver->id;

    config([
        'services.supabase.url' => 'https://unit-test.supabase.co',
        'services.supabase.anon_key' => 'test-anon-key',
    ]);

    Http::fake([
        'https://unit-test.supabase.co/auth/v1/user' => Http::response(['id' => $driver->id], 200),
    ]);

    test()->withHeader('Authorization', 'Bearer '.$token);
}
