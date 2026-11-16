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
        Notification::make()->danger()->title($exception->validator->errors()->first())
            ->body(implode("\n", array_map(fn (string $message): string => e($message), array_slice($messages, 0, 10))))->send();
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
