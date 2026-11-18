<?php

namespace Database\Seeders;

use App\Actions\Customers\ResolveCustomer;
use App\Actions\Customers\UpdateCustomer;
use App\Actions\Invoices\IssueInvoice;
use App\Actions\Orders\AcceptQuoteByStaff;
use App\Actions\Orders\CounterSalePayment;
use App\Actions\Orders\CreateCounterSale;
use App\Actions\Orders\CreateQuote;
use App\Actions\Orders\OrderLineInput;
use App\Actions\Orders\OrderLinePricing;
use App\Actions\Orders\SaveQuoteLines;
use App\Actions\Orders\SendQuote;
use App\Actions\Orders\SweepExpired;
use App\Actions\Orders\TransitionOrder;
use App\Actions\Payments\RecordPayment;
use App\Actions\Purchasing\CreateGoodsReceipt;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Purchasing\SaveGoodsReceiptLine;
use App\Actions\Purchasing\SaveSupplier;
use App\Actions\Returns\CreateReturn;
use App\Actions\Returns\ReturnLineInput;
use App\Actions\Returns\ReturnRefundInput;
use App\Actions\Stock\AdjustStock;
use App\Enums\CustomerType;
use App\Enums\LateCancelReason;
use App\Enums\OrderLineKind;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PriceTier;
use App\Enums\SaleUnit;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Variant;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemoDataSeeder extends Seeder
{
    private User $actor;

    /** @var array<string, Customer> */
    private array $customers = [];

    /** @var array<string, Supplier> */
    private array $suppliers = [];

    /** @var array<string, Variant> */
    private array $variants = [];

    /** @var array<string, Order> */
    private array $orders = [];

    public function run(): void
    {
        $startedAt = CarbonImmutable::now('Asia/Colombo');

        try {
            if (! app()->environment('local', 'testing', 'staging')) {
                throw new RuntimeException('Demo data can only be seeded in local, testing, or staging.');
            }

            DB::transaction(function () use ($startedAt): void {
                if (Order::query()->exists()) {
                    throw new RuntimeException('Demo data can only be added to a database with no sales yet.');
                }

                $this->actor = User::query()->where('role', UserRole::Admin)->where('is_active', true)->orderBy('id')->first()
                    ?? throw new RuntimeException('Run the base seed first: php artisan db:seed --force');

                $events = $this->schedule($startedAt);
                usort($events, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

                foreach ($events as [$at, $event]) {
                    Carbon::setTestNow($at);
                    CarbonImmutable::setTestNow($at);
                    $event();
                }
            });
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        $this->summarize();
    }

    /** @return list<array{CarbonImmutable, Closure}> */
    private function schedule(CarbonImmutable $startedAt): array
    {
        $day = static fn (int $daysAgo, int $hour): CarbonImmutable => $startedAt->subDays($daysAgo)->setTime($hour, 0);
        $opening = $day(0, 9);
        $closing = $startedAt->min($day(0, 18));

        if ($closing->getTimestamp() - $opening->getTimestamp() < 4) {
            $opening = $day(1, 16);
            $closing = $day(1, 18);
        }

        $elapsed = $closing->getTimestamp() - $opening->getTimestamp();
        $recent = static fn (int $slot): CarbonImmutable => $opening->addSeconds(intdiv($elapsed * $slot, 4));

        return [
            [$day(29, 9), fn () => $this->seedCatalogAndContacts()],
            [$day(29, 10), fn () => $this->receive('pvc', 'CPI-1018', [
                'PVC-WHITE-3000' => [150, 90000], 'PVC-WHITE-4000' => [110, 119000],
                'PVC-OAK-3000' => [68, 111000], 'PVC-OAK-4000' => [46, 145000],
            ])],
            [$day(28, 10), fn () => $this->receive('gypsum', 'LGD-2074', [
                'GYP-9' => [95, 245000], 'GYP-12' => [65, 295000],
                'SCREW-25' => [80, 82000], 'SCREW-38' => [45, 105000], 'SCREW-50' => [36, 131000],
            ])],
            [$day(27, 10), fn () => $this->receive('grid', 'MAP-3152', [
                'TEE-MAIN-3600' => [100, 145000], 'TEE-CROSS-600' => [200, 27000], 'TEE-CROSS-1200' => [100, 52000],
                'ANGLE-3000' => [35, 48000], 'NAIL-25' => [28, 42000], 'NAIL-38' => [38, 57000],
            ])],
            [$day(26, 10), fn () => $this->sendQuote('expired', 'nirmala', ['GYP-9' => 10], $day(24, 9))],
            [$day(26, 14), fn () => $this->orders['returnable'] = $this->sell('walk-in', ['PVC-WHITE-3000' => 6], invoice: true)],
            [$day(25, 10), fn () => $this->sell('walk-in', ['PVC-WHITE-3000' => 8, 'NAIL-25' => 4])],
            [$day(24, 11), fn () => $this->sell('kamal', ['PVC-OAK-3000' => 10, 'NAIL-25' => 4], PaymentMethod::Card, 800000)],
            [$day(23, 10), fn () => app(SweepExpired::class)->handle()],
            [$day(22, 11), fn () => $this->sendQuote('delivery', 'dilan', ['PVC-WHITE-3000' => 45, 'PVC-WHITE-4000' => 20, 'TEE-MAIN-3600' => 10], $day(15, 9), 3000000)],
            [$day(21, 10), fn () => $this->acceptQuote('delivery', 'CFT-141')],
            [$day(21, 14), fn () => $this->sell('walk-in', ['PVC-WHITE-4000' => 6, 'ANGLE-3000' => 4], PaymentMethod::Card)],
            [$day(20, 10), fn () => $this->moveOrder('delivery', OrderStatus::Processing)],
            [$day(20, 13), fn () => $this->sell('farzana', ['GYP-9' => 6, 'SCREW-25' => 2], PaymentMethod::Card)],
            [$day(19, 13), fn () => $this->sell('walk-in', ['TEE-MAIN-3600' => 6, 'TEE-CROSS-600' => 16, 'TEE-CROSS-1200' => 8, 'ANGLE-3000' => 12])],
            [$day(18, 10), fn () => $this->moveOrder('delivery', OrderStatus::Dispatched)],
            [$day(18, 15), fn () => $this->sell('rizwan', ['PVC-WHITE-3000' => 12, 'NAIL-38' => 2])],
            [$day(17, 10), fn () => $this->finishDelivery()],
            [$day(17, 14), fn () => $this->sell('tharushi', ['GYP-12' => 5, 'SCREW-38' => 3], PaymentMethod::Card, invoice: true)],
            [$day(16, 11), fn () => $this->sell('nirmala', ['PVC-OAK-3000' => 4])],
            [$day(15, 10), fn () => $this->returnPanels()],
            [$day(15, 15), fn () => $this->sell('walk-in', ['NAIL-25' => 6])],
            [$day(14, 10), fn () => $this->sendQuote('in-progress', 'arun', ['GYP-12' => 20, 'TEE-MAIN-3600' => 12, 'TEE-CROSS-600' => 30], $day(7, 9), 2000000)],
            [$day(13, 10), fn () => $this->receive('pvc', 'CPI-1056', ['PVC-WHITE-3000' => [85, 91500], 'PVC-OAK-3000' => [30, 112500]])],
            [$day(12, 10), fn () => $this->acceptQuote('in-progress', 'JIC-063')],
            [$day(12, 15), fn () => $this->sell('walk-in', ['TEE-MAIN-3600' => 8, 'TEE-CROSS-1200' => 12, 'ANGLE-3000' => 6], PaymentMethod::Card)],
            [$day(11, 9), fn () => $this->moveOrder('in-progress', OrderStatus::Processing)],
            [$day(11, 14), fn () => $this->sell('sunil', ['PVC-WHITE-4000' => 9, 'NAIL-25' => 3], paidCents: 500000)],
            [$day(10, 13), fn () => $this->startRefusedDelivery()],
            [$day(9, 10), fn () => $this->moveOrder('refused', OrderStatus::Dispatched)],
            [$day(9, 12), fn () => $this->sell('walk-in', ['SCREW-25' => 4, 'SCREW-50' => 2])],
            [$day(9, 16), fn () => $this->moveOrder('refused', OrderStatus::Cancelled, LateCancelReason::DeliveryRefused)],
            [$day(8, 13), fn () => $this->sell('malini', ['PVC-OAK-4000' => 8, 'NAIL-38' => 3], PaymentMethod::Card)],
            [$day(7, 10), fn () => $this->sendQuote('collection', 'farzana', ['GYP-9' => 12, 'ANGLE-3000' => 8], $day(1, 9))],
            [$day(6, 12), fn () => $this->acceptForCollection()],
            [$day(5, 11), fn () => $this->invoiceCollection()],
            [$day(5, 15), fn () => $this->sell('rizwan', ['GYP-9' => 14, 'SCREW-38' => 5])],
            [$day(4, 14), fn () => $this->sell('walk-in', ['GYP-12' => 7, 'SCREW-50' => 3], PaymentMethod::Card, invoice: true)],
            [$day(3, 10), fn () => $this->sendQuote('open', 'malini', ['PVC-OAK-4000' => 14, 'NAIL-38' => 2], $startedAt->addDays(10))],
            [$day(3, 15), fn () => $this->sell('tharushi', ['PVC-WHITE-4000' => 6, 'NAIL-38' => 1])],
            [$day(2, 11), fn () => $this->sendQuote('expiring-soon', 'shafiya', ['PVC-WHITE-3000' => 18, 'NAIL-25' => 3], $startedAt->addDay())],
            [$day(2, 16), fn () => app(AdjustStock::class)->handle($this->variants['NAIL-25'], -2, 'Two boxes damaged by a water leak on the shelf.', $this->actor)],
            [$day(1, 14), fn () => $this->sell('walk-in', ['GYP-9' => 3, 'SCREW-25' => 2])],
            [$recent(1), fn () => $this->sell('walk-in', ['PVC-WHITE-3000' => 5, 'NAIL-25' => 3])],
            [$recent(2), fn () => $this->sell('malini', ['GYP-12' => 4, 'SCREW-38' => 2], PaymentMethod::Card)],
            [$recent(3), fn () => $this->sell('walk-in', ['TEE-CROSS-600' => 10, 'ANGLE-3000' => 1], PaymentMethod::Card)],
        ];
    }

    private function seedCatalogAndContacts(): void
    {
        $this->createProduct('pvc-ceiling-panels', 'PVC ceiling panel 7.5 mm', 'ceilings', SaleUnit::Panel, [
            'PVC-WHITE-3000' => ['label' => '3000 x 200 mm, white', 'colour' => 'White', 'length_mm' => 3000, 'price_cents' => 127500, 'trade_price_cents' => 116000],
            'PVC-WHITE-4000' => ['label' => '4000 x 200 mm, white', 'colour' => 'White', 'length_mm' => 4000, 'price_cents' => 168000, 'trade_price_cents' => 153000],
            'PVC-OAK-3000' => ['label' => '3000 x 200 mm, oak grain', 'colour' => 'Oak grain', 'length_mm' => 3000, 'price_cents' => 155000, 'trade_price_cents' => 141000],
            'PVC-OAK-4000' => ['label' => '4000 x 200 mm, oak grain', 'colour' => 'Oak grain', 'length_mm' => 4000, 'price_cents' => 205000, 'trade_price_cents' => 187000],
        ], width: 200, thickness: 7500);
        $this->createProduct('gypsum-board', 'Gypsum ceiling board', 'ceilings', SaleUnit::Sheet, [
            'GYP-9' => ['label' => '2400 x 1200 mm, 9 mm', 'length_mm' => 2400, 'thickness_micron' => 9000, 'price_cents' => 325000, 'trade_price_cents' => 295000],
            'GYP-12' => ['label' => '2400 x 1200 mm, 12 mm', 'length_mm' => 2400, 'thickness_micron' => 12000, 'price_cents' => 395000, 'trade_price_cents' => 360000],
        ], width: 1200);
        $this->createProduct('aluminium-t-grid', 'Aluminium T-grid', 'accessories', SaleUnit::Piece, [
            'TEE-MAIN-3600' => ['label' => 'Main tee, 3600 mm', 'length_mm' => 3600, 'price_cents' => 205000],
            'TEE-CROSS-600' => ['label' => 'Cross tee, 600 mm', 'length_mm' => 600, 'price_cents' => 42000],
            'TEE-CROSS-1200' => ['label' => 'Cross tee, 1200 mm', 'length_mm' => 1200, 'price_cents' => 78000],
        ], width: 24);
        $this->createProduct('wall-angle', 'Aluminium wall angle', 'accessories', SaleUnit::Piece, [
            'ANGLE-3000' => ['label' => '3000 mm', 'length_mm' => 3000, 'price_cents' => 72500, 'reorder_level' => 8],
        ], width: 24);
        $this->createProduct('ceiling-nails', 'Ceiling nails, box of 100', 'accessories', SaleUnit::Box, [
            'NAIL-25' => ['label' => '25 mm', 'length_mm' => 25, 'price_cents' => 65000, 'reorder_level' => 8],
            'NAIL-38' => ['label' => '38 mm', 'length_mm' => 38, 'price_cents' => 82500],
        ], piecesPerUnit: 100);
        $this->createProduct('drywall-screws', 'Drywall screws, box of 200', 'accessories', SaleUnit::Box, [
            'SCREW-25' => ['label' => '25 mm', 'length_mm' => 25, 'price_cents' => 125000],
            'SCREW-38' => ['label' => '38 mm', 'length_mm' => 38, 'price_cents' => 155000],
            'SCREW-50' => ['label' => '50 mm', 'length_mm' => 50, 'price_cents' => 190000],
        ], piecesPerUnit: 200);

        foreach ([
            'gypsum' => ['Lanka Gypsum Distributors', '011 255 0101'],
            'pvc' => ['Colombo PVC Imports', '011 255 0102'],
            'grid' => ['Metro Aluminium Profiles', '011 255 0103'],
        ] as $key => [$name, $phone]) {
            $this->suppliers[$key] = app(SaveSupplier::class)->handle(null, ['name' => $name, 'phone_e164' => $phone], $this->actor);
        }

        $resolver = app(ResolveCustomer::class);
        $this->customers['walk-in'] = $resolver->walkIn();
        $phoneSuffix = 1;
        foreach ([
            'kamal' => ['Kamal Perera', 'Nugegoda', null],
            'farzana' => ['Farzana Ameen', 'Dehiwala', null],
            'tharushi' => ['Tharushi Silva', 'Maharagama', null],
            'nirmala' => ['Nirmala Jayasinghe', 'Piliyandala', null],
            'sunil' => ['Sunil Fernando', 'Moratuwa', null],
            'suresh' => ['Suresh Rajan', 'Wattala', null],
            'malini' => ['Malini Wickramasinghe', 'Kottawa', null],
            'shafiya' => ['Shafiya Nazeer', 'Mount Lavinia', null],
            'dilan' => ['Dilan Samarasinghe', 'Sri Jayawardenepura Kotte', 'Ceylon Fit-Outs'],
            'arun' => ['Arun Selvaratnam', 'Kadawatha', 'Jaya Interior Contracts'],
            'rizwan' => ['Rizwan Haniffa', 'Panadura', 'Coastal Ceiling Works'],
        ] as $key => [$name, $city, $company]) {
            $phone = sprintf('077 555 01%02d', $phoneSuffix++);
            $customer = $resolver->handle($phone, $name, $this->actor, ['city' => $city])->customer;
            $this->customers[$key] = $company === null ? $customer : app(UpdateCustomer::class)->handle($customer, [
                'type' => CustomerType::Contractor->value,
                'price_tier' => PriceTier::Trade->value,
                'company_name' => $company,
            ], $this->actor);
        }
    }

    /** @param array<string, array{label: string, length_mm: int, price_cents: int, colour?: string, trade_price_cents?: int, thickness_micron?: int, reorder_level?: int}> $variants */
    private function createProduct(string $slug, string $title, string $categorySlug, SaleUnit $unit, array $variants, ?int $width = null, ?int $thickness = null, int $piecesPerUnit = 1): void
    {
        $category = Category::query()->where('slug', $categorySlug)->firstOrFail();
        $category->update(['is_active' => true]);
        $product = Product::query()->updateOrCreate(['slug' => 'demo-'.$slug], [
            'category_id' => $category->id,
            'title' => ['en' => $title, 'si' => $title, 'ta' => $title],
            'is_active' => true,
        ]);

        foreach ($variants as $sku => $data) {
            $this->variants[$sku] = Variant::query()->updateOrCreate(['sku' => 'DEMO-'.$sku], $data + [
                'product_id' => $product->id,
                'sale_unit' => $unit,
                'width_mm' => $width,
                'thickness_micron' => $thickness,
                'pieces_per_unit' => $piecesPerUnit,
                'reorder_level' => 10,
                'track_stock' => true,
                'is_active' => true,
            ]);
        }
    }

    /** @param array<string, array{int, int}> $stock */
    private function receive(string $supplier, string $reference, array $stock): void
    {
        $receipt = app(CreateGoodsReceipt::class)->handle(
            $this->actor, $this->suppliers[$supplier]->id, now('Asia/Colombo')->toDateString(), supplierReference: $reference,
        );
        $saveLine = app(SaveGoodsReceiptLine::class);
        foreach ($stock as $sku => [$qty, $costCents]) {
            $saveLine->handle($receipt, null, $this->variants[$sku]->id, $qty, $costCents, $this->actor);
        }
        app(PostGoodsReceipt::class)->handle($receipt, $this->actor);
    }

    /** @param array<string, int> $basket */
    private function sell(string $customerKey, array $basket, ?PaymentMethod $method = PaymentMethod::Cash, ?int $paidCents = null, bool $invoice = false): Order
    {
        $customer = $this->customers[$customerKey];
        $payment = null;
        if ($method !== null) {
            if ($paidCents === null) {
                $pricing = app(OrderLinePricing::class);
                $paidCents = 0;
                foreach ($basket as $sku => $qty) {
                    $paidCents += $pricing->handle($this->variants[$sku], $customer->price_tier) * $qty;
                }
            }
            $payment = new CounterSalePayment($method, $paidCents, null, null);
        }

        $order = app(CreateCounterSale::class)->handle($customer, $this->lines($basket), $this->actor, $payment);
        if ($invoice) {
            $transition = app(TransitionOrder::class);
            $order = $transition->handle($order, OrderStatus::Processing, $this->actor);
            $order = $transition->handle($order, OrderStatus::ReadyForCollection, $this->actor);
            $order = $transition->handle($order, OrderStatus::Completed, $this->actor);
            app(IssueInvoice::class)->handle($order, null, $this->actor);
        }

        return $order;
    }

    /** @param array<string, int> $basket */
    private function sendQuote(string $key, string $customer, array $basket, CarbonImmutable $validUntil, ?int $advanceCents = null): void
    {
        $quote = app(CreateQuote::class)->handle($this->customers[$customer], $this->actor);
        $quote = app(SaveQuoteLines::class)->handle($quote, $this->lines($basket), $advanceCents, 'Materials only; installation not included.', $validUntil, $this->actor);
        app(SendQuote::class)->handle($quote, $this->actor);
        $this->orders[$key] = $quote;
    }

    private function acceptQuote(string $key, ?string $reference = null): void
    {
        $order = app(AcceptQuoteByStaff::class)->handle($this->orders[$key], $this->actor);
        if ($order->required_advance_cents !== null) {
            app(RecordPayment::class)->handle($order, PaymentMethod::BankTransfer, $order->required_advance_cents, $reference, 'Advance payment', $this->actor);
        }
        $this->orders[$key] = $order;
    }

    private function moveOrder(string $key, OrderStatus $status, ?LateCancelReason $reason = null): void
    {
        $this->orders[$key] = app(TransitionOrder::class)->handle($this->orders[$key], $status, $this->actor, cancelReason: $reason?->value);
    }

    private function finishDelivery(): void
    {
        $order = $this->orders['delivery'];
        app(RecordPayment::class)->handle($order, PaymentMethod::BankTransfer, $order->balanceCents(), 'CFT-154', 'Delivery balance', $this->actor);
        $this->moveOrder('delivery', OrderStatus::Completed);
        app(IssueInvoice::class)->handle($this->orders['delivery'], null, $this->actor);
    }

    private function startRefusedDelivery(): void
    {
        $this->orders['refused'] = $this->sell('suresh', ['GYP-9' => 8, 'SCREW-25' => 4], method: null);
        $this->moveOrder('refused', OrderStatus::Processing);
    }

    private function acceptForCollection(): void
    {
        $this->acceptQuote('collection');
        $this->moveOrder('collection', OrderStatus::Processing);
    }

    private function invoiceCollection(): void
    {
        $this->moveOrder('collection', OrderStatus::ReadyForCollection);
        $order = $this->orders['collection'];
        app(RecordPayment::class)->handle($order, PaymentMethod::Card, $order->balanceCents(), null, 'Paid before collection', $this->actor);
        app(IssueInvoice::class)->handle($order, null, $this->actor);
    }

    private function returnPanels(): void
    {
        $order = $this->orders['returnable'];
        $line = $order->lines()->sole();
        app(CreateReturn::class)->handle($order, [
            new ReturnLineInput($line->id, 1, $line->unit_price_cents, true),
            new ReturnLineInput($line->id, 1, $line->unit_price_cents, false),
        ], 'Two surplus panels returned', 'One unopened; one cracked during fitting and not resaleable.', new ReturnRefundInput(PaymentMethod::Cash, 2 * $line->unit_price_cents), $this->actor);
    }

    /**
     * @param  array<string, int>  $basket
     * @return list<OrderLineInput>
     */
    private function lines(array $basket): array
    {
        $lines = [];
        foreach ($basket as $sku => $qty) {
            $lines[] = new OrderLineInput(OrderLineKind::Product, $this->variants[$sku]->id, null, $qty, null);
        }

        return $lines;
    }

    private function summarize(): void
    {
        $this->command?->info(sprintf(
            'Demo data: %d products, %d named customers, %d orders (%d quotes), %d invoices, %d return(s).',
            Product::query()->where('slug', 'like', 'demo-%')->count(),
            count($this->customers) - 1,
            Order::query()->count(),
            Order::query()->where('source', OrderSource::Quote)->count(),
            Invoice::query()->count(),
            SalesReturn::query()->count(),
        ));

        foreach (['ANGLE-3000', 'NAIL-25'] as $sku) {
            $variant = $this->variants[$sku];
            $this->command?->line(sprintf('%s: %d in stock (reorder level %d).', $variant->sku, $variant->stockLevels()->sum('qty'), $variant->reorder_level));
        }
    }
}
