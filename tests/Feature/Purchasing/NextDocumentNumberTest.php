<?php

use App\Actions\Documents\NextDocumentNumber;
use App\Enums\DocumentKind;
use App\Models\DocumentSequence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('allocates separate consecutive document numbers per kind and local year', function () {
    config(['app.timezone' => 'Asia/Colombo']);
    $at = CarbonImmutable::parse('2026-06-01', 'Asia/Colombo');
    $numbers = app(NextDocumentNumber::class);

    DB::transaction(function () use ($numbers, $at): void {
        expect($numbers->handle(DocumentKind::GoodsReceipt, $at))->toBe('GRN-2026-00001');
        expect($numbers->handle(DocumentKind::GoodsReceipt, $at))->toBe('GRN-2026-00002');
        expect($numbers->handle(DocumentKind::Invoice, $at))->toBe('INV-2026-00001');
        expect($numbers->handle(DocumentKind::Receipt, $at))->toBe('RCP-2026-00001');
        expect($numbers->handle(DocumentKind::CreditNote, $at))->toBe('CRN-2026-00001');
        expect($numbers->handle(DocumentKind::GoodsReceipt, $at->addYear()))->toBe('GRN-2027-00001');
    });
});

it('uses the application timezone without mutating the supplied time', function () {
    config(['app.timezone' => 'Asia/Colombo']);
    $at = CarbonImmutable::parse('2026-12-31 20:00:00', 'UTC');
    expect(DB::transaction(fn () => app(NextDocumentNumber::class)->handle(DocumentKind::GoodsReceipt, $at)))->toBe('GRN-2027-00001');
    expect($at->year)->toBe(2026);
});

it('returns numbers to both new and existing sequences after rollback', function () {
    $at = CarbonImmutable::parse('2026-06-01');
    $numbers = app(NextDocumentNumber::class);
    expect(fn () => DB::transaction(function () use ($numbers, $at): void {
        $numbers->handle(DocumentKind::GoodsReceipt, $at);
        throw new RuntimeException('Roll back');
    }))->toThrow(RuntimeException::class, 'Roll back');
    expect(DocumentSequence::count())->toBe(0);
    expect(DB::transaction(fn () => $numbers->handle(DocumentKind::GoodsReceipt, $at)))->toBe('GRN-2026-00001');
    expect(fn () => DB::transaction(function () use ($numbers, $at): void {
        $numbers->handle(DocumentKind::GoodsReceipt, $at);
        throw new RuntimeException('Roll back again');
    }))->toThrow(RuntimeException::class, 'Roll back again');
    expect(DB::transaction(fn () => $numbers->handle(DocumentKind::GoodsReceipt, $at)))->toBe('GRN-2026-00002');
});

it('requires an open transaction', function () {
    $manager = DB::getFacadeRoot();
    DB::shouldReceive('transactionLevel')->once()->andReturn(0);
    try {
        expect(fn () => app(NextDocumentNumber::class)->handle(DocumentKind::GoodsReceipt))->toThrow(LogicException::class);
    } finally {
        DB::swap($manager);
    }
    $this->assertDatabaseCount('document_sequences', 0);
});
