<?php

namespace App\Domain\Contact;

final class PhoneNumber
{
    public static function toE164(string $input): string
    {
        $number = preg_replace('/[\s.()\-]+/', '', $input) ?? '';
        if (str_starts_with($number, '00')) {
            $number = '+'.substr($number, 2);
        } elseif (preg_match('/^0[0-9]{9}$/D', $number) === 1) {
            $number = '+94'.substr($number, 1);
        } elseif (preg_match('/^[1-9][0-9]{8}$/D', $number) === 1) {
            $number = '+94'.$number;
        }
        if (preg_match('/^\+[1-9][0-9]{7,14}$/D', $number) !== 1
            || (str_starts_with($number, '+94') && preg_match('/^\+94[0-9]{9}$/D', $number) !== 1)) {
            throw new InvalidPhoneNumber('Enter a valid phone number, such as 0771234567 or +94771234567.');
        }

        return $number;
    }
}
