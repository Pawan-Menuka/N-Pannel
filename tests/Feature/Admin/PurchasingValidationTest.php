<?php

use App\Filament\Support\PurchasingValidation;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

it('shows a single validation message as the title without a body', function () {
    PurchasingValidation::notify(ValidationException::withMessages(['quote_revision' => 'Send the quote before marking it accepted.']));

    Notification::assertNotified(Notification::make()->danger()->title('Send the quote before marking it accepted.'));
});

it('shows only remaining escaped validation messages in the body across fields', function () {
    PurchasingValidation::notify(ValidationException::withMessages([
        'lines' => ['Check the order lines.', '<b>Item & quantity</b>'],
        'payment' => 'A payment reference is required.',
    ]));

    Notification::assertNotified(Notification::make()->danger()->title('Check the order lines.')
        ->body("&lt;b&gt;Item &amp; quantity&lt;/b&gt;\nA payment reference is required."));
});

it('limits the notification body to ten messages after the title', function () {
    $messages = ['Check the order lines.'];
    for ($number = 1; $number <= 11; $number++) {
        $messages[] = 'Issue '.$number;
    }

    PurchasingValidation::notify(ValidationException::withMessages(['lines' => $messages]));

    Notification::assertNotified(Notification::make()->danger()->title('Check the order lines.')
        ->body("Issue 1\nIssue 2\nIssue 3\nIssue 4\nIssue 5\nIssue 6\nIssue 7\nIssue 8\nIssue 9\nIssue 10"));
});
