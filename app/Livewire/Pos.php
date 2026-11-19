<?php

namespace App\Livewire;

use App\Exceptions\CheckoutException;
use App\Exceptions\InsufficientStockException;
use App\Models\Category;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Pos\Cart;
use App\Services\CheckoutService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The cashier screen. Holds UI state only; cart maths lives in App\Pos\Cart and the sale itself in
 * CheckoutService. $lines and $checkoutToken are Locked (the browser cannot write them) and kept
 * in the session (a page reload does not lose the cart).
 */
#[Layout('components.layouts.pos')]
#[Title('POS')]
class Pos extends Component
{
    /** Upper bound for a unit price typed at the till (price override, manual item), in pesos. */
    private const MAX_PRICE = '999999.99';

    /** Upper bound for cash received and the discount, in pesos. */
    private const MAX_PAYMENT = '9999999.99';

    /** Largest manual item name; the length of transaction_items.product_name. */
    private const MAX_NAME_LENGTH = 150;

    public string $barcode = '';

    public string $search = '';

    public ?int $categoryId = null;

    /** @var array<string, array<string, mixed>> */
    #[Locked]
    #[Session(key: 'pos.lines')]
    public array $lines = [];

    #[Locked]
    #[Session(key: 'pos.token')]
    public string $checkoutToken = '';

    public string $discount = '';

    public string $cashReceived = '';

    public ?string $notFoundCode = null;

    public bool $showManualModal = false;

    public string $manualName = '';

    public string $manualQuantity = '1';

    public string $manualPrice = '';

    #[Locked]
    public ?string $editingPriceKey = null;

    public string $newPrice = '';

    #[Locked]
    public ?int $completedTransactionId = null;

    public function mount(): void
    {
        if ($this->checkoutToken === '') {
            $this->checkoutToken = (string) Str::uuid();
        }
    }

    /**
     * #[Session] only reads the session on mount, so each open tab would otherwise act on its own snapshot of the
     * cart and token: a second tab could complete with the first tab's (already used) token and get that sale back.
     * Reloading both on every request keeps one server-side cart and one token per session, whichever tab sends it.
     */
    public function hydrate(): void
    {
        $this->lines = session('pos.lines', []);
        $this->checkoutToken = session('pos.token') ?: (string) Str::uuid();
    }

    /**
     * The scanner types the code and presses Enter. Livewire requests skip the TrimStrings middleware,
     * so the code is trimmed here before the exact lookup. Focus goes back to the barcode field either way.
     */
    public function scan(): void
    {
        $code = trim($this->barcode);
        $this->barcode = '';
        $this->notFoundCode = null;
        $this->dispatch('focus-barcode');

        if ($code === '') {
            return;
        }

        $product = $this->findByCode($code);

        if ($product === null) {
            $this->notFoundCode = $code;

            return;
        }

        $this->addToCart($product);
    }

    /** Enter in the search box: exact barcode/SKU match, or the only result, goes straight to the cart. */
    public function submitSearch(): void
    {
        $term = trim($this->search);

        if ($term === '') {
            return;
        }

        $product = $this->findByCode($term)
            ?? ($this->products->count() === 1 ? $this->products->first() : null);

        if ($product !== null) {
            $this->addToCart($product);
            $this->search = '';
            unset($this->products);
        }
    }

    public function selectCategory(?int $categoryId): void
    {
        $this->categoryId = $categoryId;
    }

    public function addProduct(int $productId): void
    {
        $product = Product::query()->active()->find($productId);

        if ($product === null) {
            $this->toast('That product is no longer available.');

            return;
        }

        $this->addToCart($product);
    }

    public function increment(string $key): void
    {
        $this->applyQuantity($key, ($this->cart()->line($key)['quantity'] ?? 0) + 1);
    }

    public function decrement(string $key): void
    {
        $this->applyQuantity($key, ($this->cart()->line($key)['quantity'] ?? 0) - 1);
    }

    public function setQuantity(string $key, mixed $quantity): void
    {
        $this->applyQuantity($key, (int) $quantity);
    }

    public function remove(string $key): void
    {
        $cart = $this->cart();
        $cart->remove($key);
        $this->saveCart($cart);
    }

    public function clearCart(): void
    {
        $this->resetSale();
    }

    public function openPriceEditor(string $key): void
    {
        Gate::authorize('override-price');

        $line = $this->cart()->line($key);

        if ($line === null || $line['is_manual']) {
            return;
        }

        $this->editingPriceKey = $key;
        $this->newPrice = Money::fromCents($line['unit_price_cents']);
    }

    public function savePrice(): void
    {
        Gate::authorize('override-price');

        $this->validate(['newPrice' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_PRICE]], attributes: ['newPrice' => 'price']);

        if ($this->editingPriceKey !== null) {
            $cart = $this->cart();
            $cart->overridePrice($this->editingPriceKey, Money::toCents($this->newPrice));
            $this->saveCart($cart);
        }

        $this->closePriceEditor();
    }

    public function resetPrice(string $key): void
    {
        $cart = $this->cart();
        $cart->resetPrice($key);
        $this->saveCart($cart);
        $this->closePriceEditor();
    }

    public function closePriceEditor(): void
    {
        $this->editingPriceKey = null;
        $this->newPrice = '';
        $this->resetValidation('newPrice');
        $this->dispatch('focus-barcode');
    }

    public function openManualEntry(): void
    {
        $this->manualName = '';
        $this->manualQuantity = '1';
        $this->manualPrice = '';
        $this->notFoundCode = null;
        $this->showManualModal = true;
    }

    /** The name is trimmed before validation (Livewire skips TrimStrings), so the length limit applies to what is stored. */
    public function addManualItem(): void
    {
        $this->manualName = trim($this->manualName);

        $this->validate([
            'manualName' => ['required', 'string', 'max:'.self::MAX_NAME_LENGTH],
            'manualQuantity' => ['required', 'integer', 'min:1', 'max:'.Cart::MAX_QUANTITY],
            'manualPrice' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_PRICE],
        ], attributes: ['manualName' => 'name', 'manualQuantity' => 'quantity', 'manualPrice' => 'price']);

        $cart = $this->cart();
        $cart->addManual($this->manualName, (int) $this->manualQuantity, Money::toCents($this->manualPrice));
        $this->saveCart($cart);

        $this->closeManualEntry();
    }

    public function closeManualEntry(): void
    {
        $this->showManualModal = false;
        $this->resetValidation(['manualName', 'manualQuantity', 'manualPrice']);
        $this->dispatch('focus-barcode');
    }

    public function setCash(int $pesos): void
    {
        $this->cashReceived = (string) $pesos;
    }

    public function exactCash(): void
    {
        $this->cashReceived = Money::fromCents($this->totalCents);
    }

    public function completeSale(CheckoutService $checkout): void
    {
        $this->validate([
            'cashReceived' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_PAYMENT],
            'discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_PAYMENT],
        ], attributes: ['cashReceived' => 'cash received']);

        $cart = $this->cart();

        try {
            $transaction = $checkout->checkout(
                auth()->user(),
                $cart,
                Money::toCents($this->discount),
                Money::toCents($this->cashReceived),
                $this->checkoutToken,
            );
        } catch (CheckoutException $exception) {
            if ($exception->reason === 'price_changed') {
                foreach ($exception->context['prices'] as $key => $cents) {
                    $cart->syncPrice($key, $cents);
                }

                $this->saveCart($cart);
            }

            $this->toast($exception->getMessage());

            return;
        } catch (InsufficientStockException $exception) {
            $this->toast($exception->getMessage());

            return;
        }

        if (! $transaction->wasRecentlyCreated && ! $this->isSaleOf($transaction, $cart)) {
            $this->checkoutToken = (string) Str::uuid();
            $this->toast('This sale was already recorded. Your cart has changed — press Complete Sale again.');

            return;
        }

        $this->resetSale();
        $this->completedTransactionId = $transaction->id;
    }

    public function newSale(): void
    {
        $this->completedTransactionId = null;
        $this->dispatch('focus-barcode');
    }

    /** @return Collection<int, Product> */
    #[Computed]
    public function products(): Collection
    {
        return Product::query()
            ->active()
            ->when($this->categoryId, fn (Builder $query, int $id): Builder => $query->whereHas('categories', fn (Builder $categories): Builder => $categories->whereKey($id)))
            ->search($this->search)
            ->orderBy('name')
            ->limit(60)
            ->get();
    }

    /** @return Collection<int, Category> */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->orderBy('name')->get(['id', 'name']);
    }

    /** The settings row, read once per request. */
    #[Computed]
    public function storeSetting(): StoreSetting
    {
        return StoreSetting::current();
    }

    #[Computed]
    public function allowNegativeStock(): bool
    {
        return $this->storeSetting->allow_negative_stock;
    }

    #[Computed]
    public function canOverridePrice(): bool
    {
        return auth()->user()->can('override-price');
    }

    #[Computed]
    public function canDiscount(): bool
    {
        return auth()->user()->can('apply-discount');
    }

    #[Computed]
    public function subtotalCents(): int
    {
        return $this->cart()->subtotalCents();
    }

    #[Computed]
    public function discountCents(): int
    {
        return $this->canDiscount ? min($this->livePaymentCents($this->discount) ?? 0, $this->subtotalCents) : 0;
    }

    #[Computed]
    public function totalCents(): int
    {
        return $this->subtotalCents - $this->discountCents;
    }

    #[Computed]
    public function changeCents(): int
    {
        return max(0, ($this->livePaymentCents($this->cashReceived) ?? 0) - $this->totalCents);
    }

    /** A typed discount larger than the subtotal: the server would refuse the sale, so the screen says so first. */
    #[Computed]
    public function discountTooLarge(): bool
    {
        return $this->canDiscount && ($this->livePaymentCents($this->discount) ?? 0) > $this->subtotalCents;
    }

    #[Computed]
    public function canComplete(): bool
    {
        $cashCents = $this->livePaymentCents($this->cashReceived);

        return $this->lines !== []
            && ! $this->discountTooLarge
            && $cashCents !== null
            && $cashCents >= $this->totalCents;
    }

    #[Computed]
    public function completedTransaction(): ?Transaction
    {
        if ($this->completedTransactionId === null) {
            return null;
        }

        return Transaction::query()
            ->where('user_id', auth()->id())
            ->find($this->completedTransactionId);
    }

    public function render(): View
    {
        return view('livewire.pos', [
            'storeName' => $this->storeSetting->store_name,
            'cartLines' => $this->cart()->lines(),
        ]);
    }

    private function cart(): Cart
    {
        return Cart::fromArray($this->lines);
    }

    private function saveCart(Cart $cart): void
    {
        $this->lines = $cart->toArray();
        unset($this->subtotalCents, $this->discountCents, $this->discountTooLarge, $this->totalCents, $this->changeCents, $this->canComplete);
    }

    private function findByCode(string $code): ?Product
    {
        return Product::query()->active()->where('barcode', $code)->first()
            ?? Product::query()->active()->where('sku', $code)->first();
    }

    /**
     * A payment amount typed into the cash or discount field, in centavos, for the live totals.
     * Null unless the text would pass the numeric|decimal:0,2|min:0|max rules that completeSale() applies, so the
     * screen never shows change or enables Complete Sale for input the server will reject.
     * Commas are rejected here exactly as the numeric rule rejects them, although Money::toCents() would strip them.
     */
    private function livePaymentCents(string $amount): ?int
    {
        if (! is_numeric($amount) || preg_match('/^-?\d*(\.\d{0,2})?$/', $amount) !== 1 || (float) $amount < 0 || (float) $amount > (float) self::MAX_PAYMENT) {
            return null;
        }

        return Money::toCents($amount);
    }

    /**
     * Whether a sale that already existed for the checkout token is the sale of this cart: the same lines
     * (product or manual name, quantity, unit price) and the same subtotal. A genuine double submit matches;
     * a cart that changed under a used token (two tabs racing on the session) does not.
     */
    private function isSaleOf(Transaction $transaction, Cart $cart): bool
    {
        $cartLines = array_map(
            fn (array $line): string => ($line['is_manual'] ? 'm:'.trim($line['name']) : 'p:'.$line['product_id'])
                .'|'.$line['quantity'].'|'.$line['unit_price_cents'],
            $cart->lines(),
        );

        $saleLines = $transaction->items
            ->map(fn (TransactionItem $item): string => ($item->is_manual ? 'm:'.$item->product_name : 'p:'.$item->product_id)
                .'|'.$item->quantity.'|'.Money::toCents($item->unit_price))
            ->all();

        sort($cartLines);
        sort($saleLines);

        return $cartLines === $saleLines
            && Money::toCents($transaction->subtotal) === $cart->subtotalCents();
    }

    private function addToCart(Product $product): void
    {
        $cart = $this->cart();
        $wanted = $cart->quantityOf($product->id) + 1;

        if ($wanted > Cart::MAX_QUANTITY) {
            $this->toast('You cannot sell more than '.number_format(Cart::MAX_QUANTITY)." of {$product->name} in one sale.");

            return;
        }

        if (! $this->hasStockFor($product->id, $product->name, $wanted)) {
            return;
        }

        $cart->addProduct($product);
        $this->saveCart($cart);
    }

    private function applyQuantity(string $key, int $quantity): void
    {
        $cart = $this->cart();
        $line = $cart->line($key);

        if ($line === null) {
            return;
        }

        $quantity = min($quantity, Cart::MAX_QUANTITY);

        if ($quantity > $line['quantity']
            && ! $line['is_manual']
            && ! $this->hasStockFor($line['product_id'], $line['name'], $quantity)) {
            return;
        }

        $cart->setQuantity($key, $quantity);
        $this->saveCart($cart);
    }

    /** A friendly early check. The authoritative check happens under lock in CheckoutService. */
    private function hasStockFor(int $productId, string $name, int $wanted): bool
    {
        if ($this->allowNegativeStock) {
            return true;
        }

        $available = max(0, (int) Product::query()->whereKey($productId)->value('stock_quantity'));

        if ($wanted > $available) {
            $this->toast("Only {$available} left of {$name}.");

            return false;
        }

        return true;
    }

    private function resetSale(): void
    {
        $this->lines = [];
        $this->checkoutToken = (string) Str::uuid();
        $this->discount = '';
        $this->cashReceived = '';
        $this->notFoundCode = null;
        $this->showManualModal = false;
        $this->editingPriceKey = null;
        unset($this->subtotalCents, $this->discountCents, $this->discountTooLarge, $this->totalCents, $this->changeCents, $this->canComplete);
    }

    private function toast(string $message): void
    {
        $this->dispatch('pos-toast', message: $message);
    }
}
