<?php

namespace App\Support\Cart;

use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Checkout\CheckoutPricingService;
use App\Support\Loaders\ProductLoader;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CartService
{
    public const TOKEN_HEADER = 'X-Cart-Token';

    public function resolve(Request $request, bool $create = true): ?Cart
    {
        /** @var User|null $user */
        $user = $request->user('sanctum');
        $token = trim((string) $request->header(self::TOKEN_HEADER, ''));

        if ($user) {
            return Cart::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['status' => 'active', 'last_activity_at' => now()]
            );
        }

        if ($token !== '') {
            $cart = Cart::query()->where('token', $token)->first();
            if ($cart || ! $create) {
                return $cart;
            }
        }

        if (! $create) {
            return null;
        }

        return Cart::query()->create([
            'token' => (string) Str::uuid(),
            'status' => 'active',
            'last_activity_at' => now(),
        ]);
    }

    public function add(Cart $cart, Product $product, int $quantity, ?ProductVariant $variant = null): Cart
    {
        $this->assertVariantSelection($product, $variant);
        $item = $cart->items()->firstOrNew([
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
        ]);
        $requested = (int) ($item->exists ? $item->quantity : 0) + $quantity;
        $this->assertQuantityAvailable($product, $variant, $requested);
        $item->quantity = $requested;
        $item->save();
        $this->touch($cart);

        return $cart;
    }

    public function setQuantity(Cart $cart, Product $product, int $quantity, ?ProductVariant $variant = null): Cart
    {
        $this->assertVariantSelection($product, $variant);
        $this->assertQuantityAvailable($product, $variant, $quantity);
        $cart->items()->updateOrCreate(
            ['product_id' => $product->id, 'product_variant_id' => $variant?->id],
            ['quantity' => $quantity]
        );
        $this->touch($cart);

        return $cart;
    }

    public function replaceItems(Cart $cart, array $items): Cart
    {
        return DB::transaction(function () use ($cart, $items): Cart {
            $productIds = collect($items)->pluck('product_id')->unique()->values();
            $products = Product::query()
                ->with('variants')
                ->whereIn('id', $productIds)
                ->whereIn('status', ['active', 'special'])
                ->get()
                ->keyBy('id');

            abort_if($products->count() !== $productIds->count(), 422, 'One or more products are unavailable.');

            foreach ($items as $item) {
                $product = $products->get((int) $item['product_id']);
                $variant = ! empty($item['product_variant_id'])
                    ? $product->variants->firstWhere('id', (int) $item['product_variant_id'])
                    : null;
                $this->assertVariantSelection($product, $variant);
                $this->assertQuantityAvailable($product, $variant, (int) $item['quantity']);
            }

            $cart->items()->delete();
            foreach ($items as $item) {
                $cart->items()->create([
                    'product_id' => (int) $item['product_id'],
                    'product_variant_id' => ! empty($item['product_variant_id'])
                        ? (int) $item['product_variant_id']
                        : null,
                    'quantity' => (int) $item['quantity'],
                ]);
            }
            $this->touch($cart);

            return $cart;
        });
    }

    public function merge(Cart $guestCart, Cart $userCart): Cart
    {
        DB::transaction(function () use ($guestCart, $userCart): void {
            $guestCart->load(['items.product.variants', 'items.variant']);
            foreach ($guestCart->items as $guestItem) {
                if (! $guestItem->product || ! in_array($guestItem->product->status, ['active', 'special'], true)) {
                    continue;
                }

                $current = $userCart->items()
                    ->where('product_id', $guestItem->product_id)
                    ->where('product_variant_id', $guestItem->product_variant_id)
                    ->first();
                $quantity = (int) ($current?->quantity ?? 0) + (int) $guestItem->quantity;
                $target = $guestItem->variant ?? $guestItem->product;
                $quantity = min($quantity, max(0, (int) $target->stock - (int) $target->stock_reserved));
                if ($quantity > 0) {
                    $userCart->items()->updateOrCreate(
                        [
                            'product_id' => $guestItem->product_id,
                            'product_variant_id' => $guestItem->product_variant_id,
                        ],
                        ['quantity' => $quantity]
                    );
                }
            }
            $guestCart->delete();
            $this->touch($userCart);
        });

        return $userCart;
    }

    public function payload(Cart $cart): array
    {
        $cart->load([
            'items.product.brands',
            'items.product.categories.parent',
            'items.product.tags',
            'items.product.attributes',
            'items.product.galleries',
            'items.product.comments.user',
            'items.product.variants',
            'items.variant',
        ]);

        $items = $cart->items->map(function ($item): array {
            $product = $item->product;
            $variant = $item->variant;
            $target = $variant ?? $product;
            $availableQuantity = $target
                ? max(0, (int) $target->stock - (int) $target->stock_reserved)
                : 0;
            $available = $product
                && in_array($product->status, ['active', 'special'], true)
                && (! $variant || $variant->status === 'active')
                && $availableQuantity >= (int) $item->quantity;
            $unitPrice = $variant ? (float) $variant->price : (float) $product?->price;

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'quantity' => (int) $item->quantity,
                'available' => $available,
                'available_quantity' => $availableQuantity,
                'product' => $product ? ProductLoader::make($product) : null,
                'variant' => $variant ? ProductLoader::variant($variant) : null,
                'unit_price' => $unitPrice,
                'line_total' => $product ? round($unitPrice * (int) $item->quantity, 2) : 0,
            ];
        })->values();

        $subtotal = round((float) $items->sum('line_total'), 2);
        $currency = $cart->items->pluck('product.currency')->filter()->unique()->count() === 1
            ? $cart->items->first()?->product?->currency
            : null;
        $pricing = CheckoutPricingService::calculate($subtotal);

        return [
            'id' => $cart->id,
            'token' => $cart->token,
            'items' => $items,
            'summary' => [
                'subtotal' => $subtotal,
                'shipping' => $pricing['shipping'],
                'tax' => $pricing['tax'],
                'total' => $pricing['total'],
                'currency' => $currency,
                'can_checkout' => $items->isNotEmpty() && $items->every('available'),
            ],
        ];
    }

    public function checkoutItems(Cart $cart): Collection
    {
        return $cart->items()->get(['product_id', 'product_variant_id', 'quantity'])
            ->map(fn ($item): array => [
                'product_id' => (int) $item->product_id,
                'product_variant_id' => $item->product_variant_id ? (int) $item->product_variant_id : null,
                'quantity' => (int) $item->quantity,
            ]);
    }

    private function assertVariantSelection(Product $product, ?ProductVariant $variant): void
    {
        abort_if(
            $variant && (int) $variant->product_id !== (int) $product->id,
            422,
            'Selected variant does not belong to the product.'
        );
        abort_if(
            ! $variant && $product->variants()->where('status', 'active')->exists(),
            422,
            'Select a product variant before adding it to the cart.'
        );
    }

    private function assertQuantityAvailable(Product $product, ?ProductVariant $variant, int $quantity): void
    {
        abort_if(! in_array($product->status, ['active', 'special'], true), 422, 'Product is unavailable.');
        abort_if($variant && $variant->status !== 'active', 422, 'Product variant is unavailable.');
        $target = $variant ?? $product;
        $minimum = max(1, (int) ($target->min_order_quantity ?? 1));
        $maximum = $target->max_order_quantity !== null ? (int) $target->max_order_quantity : null;
        $available = max(0, (int) $target->stock - (int) $target->stock_reserved);
        abort_if($quantity < $minimum, 422, "Minimum order quantity is {$minimum}.");
        abort_if($maximum !== null && $quantity > $maximum, 422, "Maximum order quantity is {$maximum}.");
        abort_if($available < $quantity, 422, 'Insufficient product stock.');
    }

    private function touch(Cart $cart): void
    {
        $cart->forceFill(['last_activity_at' => now(), 'status' => 'active'])->save();
    }
}
