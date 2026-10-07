<?php

namespace App\Support;

/**
 * Helpers for comparing the client-generated perceptual (blockhash) hashes,
 * stored base64-encoded over 32 bytes (256 bits). Two images are "near
 * duplicates" when their hashes differ in only a few bits.
 */
class PerceptualHash
{
    /**
     * Hamming distance (number of differing bits) between two base64-encoded
     * hashes of equal byte length. Null when either input is missing or the two
     * cannot be compared (decode failure or length mismatch).
     */
    public static function hammingDistance(?string $a, ?string $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }

        $bytesA = base64_decode($a, true);
        $bytesB = base64_decode($b, true);

        if ($bytesA === false || $bytesB === false || $bytesA === '' || strlen($bytesA) !== strlen($bytesB)) {
            return null;
        }

        $distance = 0;
        $length = strlen($bytesA);
        for ($i = 0; $i < $length; $i++) {
            $distance += self::popcount(ord($bytesA[$i]) ^ ord($bytesB[$i]));
        }

        return $distance;
    }

    /**
     * Distance between two photos' orientation hash sets (eight base64
     * blockhashes each, index 0 = as displayed): the closest of each one's
     * orientations to the other's displayed orientation, so a rotated or
     * mirrored copy compares at the orientation that undoes the transform.
     * Null when either set is missing or nothing is comparable.
     *
     * @param  list<string>|null  $a
     * @param  list<string>|null  $b
     */
    public static function orientedDistance(?array $a, ?array $b): ?int
    {
        if ($a === null || $b === null || $a === [] || $b === []) {
            return null;
        }

        $best = null;
        foreach ([[$a, $b[0]], [$b, $a[0]]] as [$orientations, $displayed]) {
            foreach ($orientations as $hash) {
                $distance = self::hammingDistance($hash, $displayed);
                if ($distance !== null && ($best === null || $distance < $best)) {
                    $best = $distance;
                }
            }
        }

        return $best;
    }

    private static function popcount(int $byte): int
    {
        $count = 0;
        while ($byte !== 0) {
            $count += $byte & 1;
            $byte >>= 1;
        }

        return $count;
    }
}
