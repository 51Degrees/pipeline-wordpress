<?php
/*
    This Original Work is copyright of 51 Degrees Mobile Experts Limited.
    Copyright 2019 51 Degrees Mobile Experts Limited, 5 Charlotte Close,
    Caversham, Reading, Berkshire, United Kingdom RG4 7BY.

    This Original Work is licensed under the European Union Public Licence (EUPL)
    v.1.2 and is subject to its terms as set out below.

    If a copy of the EUPL was not distributed with this file, You can obtain
    one at https://opensource.org/licenses/EUPL-1.2.

    The 'Compatible Licences' set out in the Appendix to the EUPL (as may be
    amended by the European Commission) shall be deemed incompatible for
    the purposes of the Work and the provisions of the compatibility
    clause in Article 5 of the EUPL shall not apply.
*/

class ClientIpResolver
{
    private const HEADERS = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
    ];

    public static function resolve(?array $server = null): string
    {
        $server = $server ?? $_SERVER;

        foreach (self::HEADERS as $key) {
            if (empty($server[$key])) {
                continue;
            }
            $raw = $server[$key];
            $candidate = $key === 'HTTP_X_FORWARDED_FOR'
                ? trim(explode(',', $raw, 2)[0])
                : trim($raw);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return $server['REMOTE_ADDR'] ?? '';
    }
}
