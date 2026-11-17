<?php

namespace App\Filament\Support;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

final class PurchasingValidation
{
    public static function notify(ValidationException $exception): void
    {
        $messages = [];
        foreach ($exception->errors() as $errors) {
            foreach ($errors as $message) {
                $messages[] = $message;
            }
        }
        $notification = Notification::make()->danger()->title($exception->validator->errors()->first());
        $remaining = array_slice($messages, 1, 10);
        if ($remaining !== []) {
            $notification->body(implode("\n", array_map(fn (string $message): string => e($message), $remaining)));
        }
        $notification->send();
    }

    public static function forForm(ValidationException $exception, string $path = 'data'): ValidationException
    {
        self::notify($exception);
        $errors = [];
        foreach ($exception->errors() as $key => $messages) {
            $errors[$path.'.'.$key] = $messages;
        }

        return ValidationException::withMessages($errors);
    }
}
