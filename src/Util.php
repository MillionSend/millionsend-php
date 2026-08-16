<?php

declare(strict_types=1);

namespace MillionSend;

/** Shared mapping helpers used across resources. */
final class Util
{
    /**
     * Copy the keys named in $map (camelCase => snake_case), preserving explicit
     * nulls and omitting keys absent from the input. This is what lets a contact
     * update send `first_name: null` to clear a field while leaving untouched
     * fields off the wire entirely.
     *
     * @param array<string,mixed>  $input
     * @param array<string,string> $map
     * @return array<string,mixed>
     */
    public static function pick(array $input, array $map): array
    {
        $out = [];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $input)) {
                $out[$to] = $input[$from];
            }
        }

        return $out;
    }

    /**
     * Keyset list params (limit/after/before), dropping any that are unset.
     *
     * @param array<string,mixed> $options
     * @return array<string,scalar>
     */
    public static function listQuery(array $options): array
    {
        $out = [];
        foreach (['limit', 'after', 'before'] as $key) {
            if (isset($options[$key])) {
                $out[$key] = $options[$key];
            }
        }

        return $out;
    }
}
