<?php

namespace App\Services\Booking;

/**
 * Canonical forms used to store contacts and to count the per-contact limit
 * (docs/planejamento-barbearia-mvp.md, seção 1): e-mail trimmed and
 * lowercased (dots and +aliases kept — no identity is inferred), phone in
 * E.164.
 *
 * There is no phone-number library in the project, so E.164 is reached by a
 * deliberately small rule set instead of full numbering-plan validation:
 * formatting characters are dropped; a leading "+" or "00" means an
 * international number; 10 or 11 bare digits are a Brazilian number with
 * area code (DDD) and get "+55". Anything else is rejected rather than
 * guessed.
 */
class ContactNormalizer
{
    public static function email(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return string|null The E.164 form, or null when the input cannot be normalized.
     */
    public static function phone(string $phone): ?string
    {
        $compact = preg_replace('/[\s().\-]/', '', trim($phone));

        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }

        if (preg_match('/^\d{10,11}$/', $compact) === 1) {
            $compact = '+55'.$compact;
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $compact) === 1 ? $compact : null;
    }
}
