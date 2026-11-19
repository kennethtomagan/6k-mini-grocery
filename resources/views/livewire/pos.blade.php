@php use App\Support\Money; @endphp

<div class="flex h-dvh flex-col text-lg"
     x-data="{
        toast: null,
        toastTimer: null,
        modalOpen() {
            return $wire.showManualModal || $wire.showPaymentModal || $wire.editingPriceKey !== null || $wire.completedTransactionId !== null;
        },
        focusCash() {
            this.$nextTick(() => { const cash = document.getElementById('pos-cash'); if (cash) cash.focus(); });
        },
        focusBarcode() {
            this.$nextTick(() => { if (! this.modalOpen() && this.$refs.barcode) this.$refs.barcode.focus(); });
        },
        onKey(event) {
            if (this.modalOpen()) return;
            const tag = document.activeElement ? document.activeElement.tagName : '';
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) return;
            if (event.key.length === 1 && ! event.ctrlKey && ! event.metaKey && ! event.altKey) this.$refs.barcode.focus();
        },
        onClick(event) {
            if (event.target.closest('input, textarea, select, [data-modal]')) return;
            this.focusBarcode();
        },
        showToast(detail) {
            this.toast = detail.message;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => this.toast = null, 4500);
        },
     }"
     x-init="focusBarcode()"
     x-on:focus-barcode.window="focusBarcode()"
     x-on:pos-toast.window="showToast($event.detail)"
     x-on:keydown.window="onKey($event)"
     x-on:click="onClick($event)">

    {{-- Header --}}
    <header class="flex h-14 shrink-0 items-center justify-between bg-slate-900 px-4 text-white">
        <div class="text-xl font-semibold">{{ $storeName }}</div>
        <nav class="flex items-center gap-2 text-base">
            <span class="mr-2 text-slate-300">{{ auth()->user()->name }}</span>
            <a href="{{ url('/admin/transactions') }}" class="inline-flex min-h-12 items-center rounded-lg bg-slate-700 px-4 hover:bg-slate-600">Sales history</a>
            @if (auth()->user()->isAdmin())
                <a href="{{ url('/admin') }}" class="inline-flex min-h-12 items-center rounded-lg bg-slate-700 px-4 hover:bg-slate-600">Admin</a>
            @endif
            <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
                @csrf
                <button type="submit" class="inline-flex min-h-12 items-center rounded-lg bg-slate-700 px-4 hover:bg-slate-600">Log out</button>
            </form>
        </nav>
    </header>

    <main class="grid min-h-0 flex-1 grid-cols-1 overflow-y-auto md:grid-cols-[minmax(0,1fr)_22rem] md:overflow-hidden lg:grid-cols-[minmax(0,1fr)_26rem] xl:grid-cols-[minmax(0,1fr)_30rem]">

        {{-- LEFT: find products --}}
        <section class="flex min-h-0 flex-col gap-3 p-4">
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                <form wire:submit="scan">
                    <label for="pos-barcode" class="sr-only">Scan barcode</label>
                    <input id="pos-barcode" autofocus x-ref="barcode" wire:model="barcode" type="text" autocomplete="off"
                           placeholder="Scan barcode"
                           class="h-14 w-full rounded-xl border-2 border-emerald-600 bg-white px-4 text-xl focus:outline-none focus:ring-4 focus:ring-emerald-200">
                </form>
                <div>
                    <label for="pos-search" class="sr-only">Search products</label>
                    <input id="pos-search" wire:model.live.debounce.300ms="search" wire:keydown.enter="submitSearch"
                           type="search" autocomplete="off" placeholder="Search name, SKU or barcode"
                           class="h-14 w-full rounded-xl border-2 border-slate-300 bg-white px-4 text-xl focus:border-emerald-600 focus:outline-none">
                </div>
            </div>

            @if ($notFoundCode !== null)
                <div role="alert" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border-2 border-amber-400 bg-amber-50 px-4 py-3">
                    <span>No product found for <strong class="font-mono">{{ $notFoundCode }}</strong>.</span>
                    <span class="flex gap-2">
                        <button type="button" wire:click="openManualEntry"
                                class="min-h-12 rounded-lg bg-amber-500 px-4 font-semibold text-white hover:bg-amber-600">Add as manual item</button>
                        <button type="button" wire:click="$set('notFoundCode', null)"
                                class="min-h-12 rounded-lg bg-white px-4 font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Dismiss</button>
                    </span>
                </div>
            @endif

            <div class="flex shrink-0 gap-2 overflow-x-auto pb-1">
                <button type="button" wire:click="selectCategory(null)"
                        @class(['min-h-12 shrink-0 rounded-full px-5 font-semibold',
                                'bg-emerald-600 text-white' => $categoryId === null,
                                'bg-white text-slate-700 ring-1 ring-slate-300' => $categoryId !== null])>All</button>
                @foreach ($this->categories as $category)
                    <button type="button" wire:key="category-{{ $category->id }}" wire:click="selectCategory({{ $category->id }})"
                            @class(['min-h-12 shrink-0 rounded-full px-5 font-semibold',
                                    'bg-emerald-600 text-white' => $categoryId === $category->id,
                                    'bg-white text-slate-700 ring-1 ring-slate-300' => $categoryId !== $category->id])>{{ $category->name }}</button>
                @endforeach
                <button type="button" wire:click="openManualEntry"
                        class="ml-auto min-h-12 shrink-0 rounded-full bg-slate-800 px-5 font-semibold text-white hover:bg-slate-700">+ Manual item</button>
            </div>

            <div class="grid min-h-0 flex-1 auto-rows-max grid-cols-2 gap-3 overflow-y-auto sm:grid-cols-3 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                @forelse ($this->products as $product)
                    @php $soldOut = $product->stock_quantity <= 0 && ! $this->allowNegativeStock; @endphp
                    <button type="button" wire:key="product-{{ $product->id }}" wire:click="addProduct({{ $product->id }})"
                            @disabled($soldOut)
                            class="flex flex-col overflow-hidden rounded-xl bg-white text-left shadow-sm ring-1 ring-slate-200 hover:ring-2 hover:ring-emerald-500 disabled:opacity-50">
                        @if ($product->imageUrl())
                            <img src="{{ $product->imageUrl() }}" alt="" loading="lazy" class="h-28 w-full object-cover">
                        @else
                            <div class="flex h-28 w-full items-center justify-center bg-slate-200 text-4xl font-bold text-slate-400">
                                {{ mb_strtoupper(mb_substr($product->name, 0, 1)) }}
                            </div>
                        @endif
                        <div class="flex flex-1 flex-col gap-1 p-3">
                            <div class="line-clamp-2 font-semibold leading-tight">{{ $product->name }}</div>
                            <div class="mt-auto flex items-end justify-between">
                                <span class="text-xl font-bold text-emerald-700">{{ Money::format($product->sellingPriceCents()) }}</span>
                                <span @class(['rounded-full px-2 py-0.5 text-sm font-semibold',
                                              'bg-red-100 text-red-700' => $product->isLowStock(),
                                              'bg-slate-100 text-slate-600' => ! $product->isLowStock()])>
                                    {{ $product->stock_quantity }} left
                                </span>
                            </div>
                        </div>
                    </button>
                @empty
                    <div class="col-span-full rounded-xl bg-white p-8 text-center text-slate-500">
                        No products match. Try another search, or add a manual item.
                    </div>
                @endforelse
            </div>
        </section>

        {{-- RIGHT: cart --}}
        <aside class="flex min-h-0 flex-col border-l border-slate-300 bg-white">
            <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="text-xl font-bold">Cart <span class="font-normal text-slate-500">({{ array_sum(array_column($cartLines, 'quantity')) }})</span></h2>
                <button type="button" wire:click="clearCart" wire:confirm="Clear the whole cart?" @disabled($cartLines === [])
                        class="min-h-12 rounded-lg px-4 font-semibold text-red-700 ring-1 ring-red-200 hover:bg-red-50 disabled:opacity-40">Clear cart</button>
            </div>

            <ul class="min-h-0 flex-1 divide-y divide-slate-200 overflow-y-auto">
                @forelse ($cartLines as $line)
                    <li wire:key="line-{{ $line['key'] }}" class="flex flex-col gap-2 px-4 py-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="font-semibold leading-tight">
                                {{ $line['name'] }}
                                @if ($line['is_manual'])
                                    <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-sm font-semibold text-amber-800">Manual</span>
                                @endif
                            </div>
                            <button type="button" wire:click="remove(@js($line['key']))" aria-label="Remove {{ $line['name'] }}"
                                    class="flex size-12 shrink-0 items-center justify-center rounded-lg text-2xl text-red-600 hover:bg-red-50">&times;</button>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-1">
                                <button type="button" wire:click="decrement(@js($line['key']))" aria-label="Decrease quantity"
                                        class="size-12 rounded-lg bg-slate-200 text-2xl font-bold hover:bg-slate-300">&minus;</button>
                                <input type="number" min="0" inputmode="numeric" value="{{ $line['quantity'] }}" aria-label="Quantity"
                                       wire:key="qty-{{ $line['key'] }}-{{ $line['quantity'] }}"
                                       wire:change="setQuantity(@js($line['key']), $event.target.value)"
                                       class="h-12 w-16 rounded-lg border border-slate-300 text-center text-xl font-bold">
                                <button type="button" wire:click="increment(@js($line['key']))" aria-label="Increase quantity"
                                        class="size-12 rounded-lg bg-slate-200 text-2xl font-bold hover:bg-slate-300">+</button>
                            </div>
                            <div class="text-right">
                                @if ($this->canOverridePrice && ! $line['is_manual'])
                                    <button type="button" wire:click="openPriceEditor(@js($line['key']))"
                                            class="min-h-12 rounded-lg px-2 text-base underline decoration-dotted hover:bg-slate-100">
                                        @if ($line['price_overridden'])
                                            <s class="text-slate-400">{{ Money::format($line['original_price_cents']) }}</s>
                                        @endif
                                        {{ Money::format($line['unit_price_cents']) }} each
                                    </button>
                                @else
                                    <div class="text-base text-slate-500">
                                        @if ($line['price_overridden'])
                                            <s class="text-slate-400">{{ Money::format($line['original_price_cents']) }}</s>
                                        @endif
                                        {{ Money::format($line['unit_price_cents']) }} each
                                    </div>
                                    @if ($line['price_overridden'])
                                        {{-- The override permission was revoked mid-sale: undoing an override is always allowed. --}}
                                        <button type="button" wire:click="resetPrice(@js($line['key']))"
                                                class="min-h-12 rounded-lg px-2 text-base font-semibold text-slate-700 underline hover:bg-slate-100">Use regular price</button>
                                    @endif
                                @endif
                                <div class="text-xl font-bold">{{ Money::format($line['unit_price_cents'] * $line['quantity']) }}</div>
                            </div>
                        </div>
                    </li>
                @empty
                    <li class="p-8 text-center text-slate-500">Scan or tap a product to start a sale.</li>
                @endforelse
            </ul>

            {{-- Cart summary --}}
            <div class="shrink-0 space-y-3 bg-slate-900 p-4 text-white">
                <dl class="space-y-1">
                    <div class="flex justify-between text-slate-300">
                        <dt>Subtotal</dt><dd class="tabular-nums">{{ Money::format($this->subtotalCents) }}</dd>
                    </div>
                    <div class="flex justify-between text-slate-300">
                        <dt>Discount</dt>
                        <dd class="tabular-nums">{{ $this->discountCents > 0 ? '−'.Money::format($this->discountCents) : Money::format(0) }}</dd>
                    </div>
                    @if ($this->discountTooLarge)
                        <p class="text-right text-base text-red-300">Discount cannot be more than the subtotal.</p>
                    @endif
                    <div class="flex items-baseline justify-between border-t border-slate-700 pt-2">
                        <dt class="text-xl font-semibold">Total</dt>
                        <dd class="text-4xl font-extrabold tabular-nums">{{ Money::format($this->totalCents) }}</dd>
                    </div>
                </dl>

                <button type="button" wire:click="openPayment" @disabled($cartLines === [])
                        class="h-16 w-full rounded-xl bg-emerald-500 text-2xl font-extrabold text-white hover:bg-emerald-400 disabled:bg-slate-700 disabled:text-slate-400">
                    Payment
                </button>
            </div>
        </aside>
    </main>

    {{-- Manual item modal --}}
    @if ($showManualModal)
        <div data-modal class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 p-4" role="dialog" aria-modal="true" aria-labelledby="manual-title">
            <form wire:submit="addManualItem" class="w-full max-w-lg space-y-4 rounded-2xl bg-white p-6 shadow-xl">
                <h2 id="manual-title" class="text-2xl font-bold">Manual item</h2>
                <p class="text-base text-slate-500">For items that are not in the product list. Stock is not affected.</p>
                <div>
                    <label for="manual-name" class="mb-1 block font-semibold">Item name</label>
                    <input id="manual-name" wire:model="manualName" type="text" x-init="$nextTick(() => $el.focus())"
                           class="h-14 w-full rounded-xl border-2 border-slate-300 px-4 text-xl">
                    @error('manualName') <p class="mt-1 text-base text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="manual-qty" class="mb-1 block font-semibold">Quantity</label>
                        <input id="manual-qty" wire:model="manualQuantity" type="number" min="1" step="1" inputmode="numeric"
                               class="h-14 w-full rounded-xl border-2 border-slate-300 px-4 text-xl">
                        @error('manualQuantity') <p class="mt-1 text-base text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="manual-price" class="mb-1 block font-semibold">Unit price (₱)</label>
                        <input id="manual-price" wire:model="manualPrice" type="number" min="0" step="0.01" inputmode="decimal"
                               class="h-14 w-full rounded-xl border-2 border-slate-300 px-4 text-xl">
                        @error('manualPrice') <p class="mt-1 text-base text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" wire:click="closeManualEntry" class="min-h-14 rounded-xl px-6 font-semibold ring-1 ring-slate-300 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="min-h-14 rounded-xl bg-emerald-600 px-6 font-bold text-white hover:bg-emerald-500">Add to cart</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Price override modal --}}
    @if ($editingPriceKey !== null)
        <div data-modal class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 p-4" role="dialog" aria-modal="true" aria-labelledby="price-title">
            <form wire:submit="savePrice" class="w-full max-w-md space-y-4 rounded-2xl bg-white p-6 shadow-xl">
                <h2 id="price-title" class="text-2xl font-bold">Change price for this sale</h2>
                <p class="text-base text-slate-500">The product's regular price is not changed.</p>
                <div>
                    <label for="new-price" class="mb-1 block font-semibold">Price each (₱)</label>
                    <input id="new-price" wire:model="newPrice" type="number" min="0" step="0.01" inputmode="decimal"
                           x-init="$nextTick(() => { $el.focus(); $el.select(); })"
                           class="h-14 w-full rounded-xl border-2 border-slate-300 px-4 text-right text-2xl font-bold">
                    @error('newPrice') <p class="mt-1 text-base text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-between gap-3">
                    <button type="button" wire:click="resetPrice(@js($editingPriceKey))" class="min-h-14 rounded-xl px-4 font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Use regular price</button>
                    <span class="flex gap-3">
                        <button type="button" wire:click="closePriceEditor" class="min-h-14 rounded-xl px-6 font-semibold ring-1 ring-slate-300 hover:bg-slate-50">Cancel</button>
                        <button type="submit" class="min-h-14 rounded-xl bg-emerald-600 px-6 font-bold text-white hover:bg-emerald-500">Save</button>
                    </span>
                </div>
            </form>
        </div>
    @endif

    {{-- Payment modal --}}
    @if ($showPaymentModal)
        <div data-modal class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 p-4" role="dialog" aria-modal="true" aria-labelledby="payment-title"
             x-on:keydown.escape.window="$wire.closePayment()">
            <div class="flex max-h-full w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-3">
                    <h2 id="payment-title" class="text-2xl font-bold">Payment</h2>
                    <button type="button" wire:click="closePayment" aria-label="Back to cart"
                            class="flex size-12 items-center justify-center rounded-lg text-3xl text-slate-500 hover:bg-slate-100">&times;</button>
                </div>

                <div class="grid min-h-0 flex-1 grid-cols-1 overflow-y-auto md:grid-cols-2">
                    {{-- Amount due --}}
                    <div class="flex flex-col gap-5 bg-slate-900 p-6 text-white">
                        <dl class="space-y-2">
                            <div class="flex justify-between text-slate-300">
                                @php $itemCount = array_sum(array_column($cartLines, 'quantity')); @endphp
                                <dt>Subtotal <span class="text-slate-500">({{ $itemCount }} {{ Str::plural('item', $itemCount) }})</span></dt>
                                <dd class="tabular-nums">{{ Money::format($this->subtotalCents) }}</dd>
                            </div>
                            @if ($this->canDiscount)
                                <div class="flex items-center justify-between gap-3 text-slate-300">
                                    <dt><label for="pos-discount">Discount (₱)</label></dt>
                                    <dd>
                                        <input id="pos-discount" wire:model.live.debounce.300ms="discount" type="number" min="0" step="0.01" inputmode="decimal"
                                               class="h-12 w-36 rounded-lg border-0 bg-slate-800 px-3 text-right text-xl text-white focus:outline-none focus:ring-4 focus:ring-emerald-500/40">
                                    </dd>
                                </div>
                                @error('discount')
                                    <p class="text-right text-base text-red-300">{{ $message }}</p>
                                @else
                                    @if ($this->discountTooLarge)
                                        <p class="text-right text-base text-red-300">Discount cannot be more than the subtotal.</p>
                                    @endif
                                @enderror
                            @else
                                <div class="flex justify-between text-slate-300">
                                    <dt>Discount</dt><dd class="tabular-nums">{{ Money::format(0) }}</dd>
                                </div>
                            @endif
                            <div class="flex items-baseline justify-between border-t border-slate-700 pt-3">
                                <dt class="text-xl font-semibold">Total</dt>
                                <dd class="text-4xl font-extrabold tabular-nums">{{ Money::format($this->totalCents) }}</dd>
                            </div>
                        </dl>

                        <div class="mt-auto rounded-xl bg-slate-800 p-4">
                            <div class="text-lg text-slate-300">Change</div>
                            <div class="text-5xl font-extrabold tabular-nums text-emerald-400">{{ Money::format($this->changeCents) }}</div>
                        </div>
                    </div>

                    {{-- Cash --}}
                    <div class="flex flex-col gap-4 p-6">
                        <div>
                            <label for="pos-cash" class="mb-1 block font-semibold">Cash received (₱)</label>
                            <input id="pos-cash" wire:model.live.debounce.200ms="cashReceived" wire:keydown.enter="completeSale"
                                   type="number" min="0" step="0.01" inputmode="decimal"
                                   x-init="$nextTick(() => { $el.focus(); $el.select(); })"
                                   class="h-16 w-full rounded-xl border-2 border-slate-300 px-4 text-right text-3xl font-bold focus:border-emerald-600 focus:outline-none focus:ring-4 focus:ring-emerald-200">
                            @error('cashReceived') <p class="mt-1 text-base text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid grid-cols-3 gap-2">
                            <button type="button" wire:click="exactCash" x-on:click="focusCash()"
                                    class="min-h-14 rounded-lg bg-slate-100 font-semibold text-slate-800 ring-1 ring-slate-300 hover:bg-slate-200">Exact</button>
                            @foreach ([50, 100, 200, 500, 1000] as $bill)
                                <button type="button" wire:click="setCash({{ $bill }})" wire:key="bill-{{ $bill }}" x-on:click="focusCash()"
                                        class="min-h-14 rounded-lg bg-slate-100 font-semibold text-slate-800 ring-1 ring-slate-300 hover:bg-slate-200">₱{{ number_format($bill) }}</button>
                            @endforeach
                        </div>

                        <div class="mt-auto flex flex-col gap-3 pt-2">
                            <button type="button" wire:click="completeSale" wire:loading.attr="disabled" wire:target="completeSale"
                                    @disabled(! $this->canComplete)
                                    class="h-16 w-full rounded-xl bg-emerald-600 text-2xl font-extrabold text-white hover:bg-emerald-500 disabled:bg-slate-200 disabled:text-slate-400">
                                <span wire:loading.remove wire:target="completeSale">Complete Sale</span>
                                <span wire:loading wire:target="completeSale">Saving…</span>
                            </button>
                            <button type="button" wire:click="closePayment"
                                    class="min-h-12 rounded-xl font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Back to cart</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Sale complete overlay --}}
    @if ($this->completedTransaction)
        <div data-modal class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/70 p-4" role="dialog" aria-modal="true" aria-labelledby="done-title">
            <div class="w-full max-w-md space-y-5 rounded-2xl bg-white p-8 text-center shadow-xl">
                <h2 id="done-title" class="text-2xl font-bold text-emerald-700">Sale complete</h2>
                <p class="font-mono text-base text-slate-500">{{ $this->completedTransaction->reference_number }}</p>
                <dl class="space-y-1 text-xl">
                    <div class="flex justify-between"><dt>Total</dt><dd class="font-bold">{{ Money::format(Money::toCents($this->completedTransaction->total)) }}</dd></div>
                    <div class="flex justify-between"><dt>Cash</dt><dd class="font-bold">{{ Money::format(Money::toCents($this->completedTransaction->cash_received)) }}</dd></div>
                </dl>
                <div>
                    <div class="text-xl text-slate-500">Change</div>
                    <div class="text-6xl font-extrabold tabular-nums text-emerald-600">{{ Money::format(Money::toCents($this->completedTransaction->change_amount)) }}</div>
                </div>
                <div class="flex flex-col gap-3" data-sale-complete-actions>
                    <button type="button"
                            x-on:click="window.open('{{ route('receipt', ['transaction' => $this->completedTransaction->id, 'print' => 1]) }}', 'receipt', 'width=420,height=720')"
                            class="h-14 rounded-xl text-xl font-bold text-slate-800 ring-2 ring-slate-300 hover:bg-slate-50">Print receipt</button>
                    <button type="button" wire:click="newSale" x-init="$nextTick(() => $el.focus())"
                            class="h-16 rounded-xl bg-emerald-600 text-2xl font-extrabold text-white hover:bg-emerald-500">New sale</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Toast --}}
    <div x-show="toast" x-cloak role="alert"
         class="fixed left-1/2 top-20 z-50 w-[min(40rem,90vw)] -translate-x-1/2 rounded-xl bg-red-600 px-6 py-4 text-center text-xl font-semibold text-white shadow-xl"
         x-text="toast" x-on:click="toast = null"></div>
</div>
