<?php

namespace App\Csp;

use Spatie\Csp\Directive;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;

/**
 * Lets the wedding hub talk to its private R2 buckets: presigned PUTs from the
 * browser (connect-src), presigned GETs behind the app's 302s for images and
 * HLS segments (img/media/connect-src), and hls.js's blob: worker and MSE
 * source. Only the configured buckets' origins are allowed, never a wildcard
 * over every R2 bucket.
 */
class WeddingMediaPreset implements Preset
{
    public function configure(Policy $policy): void
    {
        $origins = array_values(array_unique(array_filter([
            self::bucketOrigin((string) config('wedding.disk')),
            self::bucketOrigin((string) config('wedding.hls_disk')),
        ])));

        if ($origins !== []) {
            $policy
                ->add(Directive::CONNECT, $origins)
                ->add(Directive::IMG, $origins)
                ->add(Directive::MEDIA, $origins);
        }

        $policy
            ->add(Directive::MEDIA, 'blob:')
            ->add(Directive::WORKER, ["'self'", 'blob:']);
    }

    /**
     * Origin presigned URLs for this disk point at: the endpoint itself for
     * path-style addressing, else the virtual-hosted `<bucket>.<host>`.
     */
    public static function bucketOrigin(string $disk): ?string
    {
        $config = config("filesystems.disks.{$disk}");
        if (! is_array($config) || empty($config['endpoint']) || empty($config['bucket'])) {
            return null;
        }

        $parts = parse_url((string) $config['endpoint']);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $host = filter_var($config['use_path_style_endpoint'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ? $parts['host']
            : $config['bucket'].'.'.$parts['host'];

        return $scheme.'://'.$host.$port;
    }
}
