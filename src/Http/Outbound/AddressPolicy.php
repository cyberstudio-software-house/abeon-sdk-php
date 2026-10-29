<?php

declare(strict_types=1);

namespace Abeon\SDK\Http\Outbound;

/**
 * Which addresses the platform may connect to on a client's behalf (ADR-0041 §1). Every address
 * a host resolves to must be public: one private answer refuses the whole host, because the
 * connection could be pinned to it.
 */
final class AddressPolicy
{
    /** Ranges refused besides what PHP already calls private or reserved. */
    private const ALWAYS_DENIED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** @var list<string> */
    private readonly array $denied;

    /**
     * @param  list<string>  $clusterCidrs  the cluster's pod and service ranges, from configuration
     */
    public function __construct(array $clusterCidrs = [])
    {
        $this->denied = [...self::ALWAYS_DENIED, ...array_values(array_filter(array_map('trim', $clusterCidrs)))];
    }

    public function allows(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach ($this->denied as $cidr) {
            if (self::contains($cidr, $ip)) {
                return false;
            }
        }

        return true;
    }

    public static function contains(string $cidr, string $ip): bool
    {
        [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $address = @inet_pton($ip);
        $base = @inet_pton((string) $network);

        if ($address === false || $base === false || strlen($address) !== strlen($base)) {
            return false;
        }

        $bits = $bits === null ? strlen($base) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);

        if (substr($address, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }
}
