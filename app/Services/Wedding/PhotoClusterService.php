<?php

namespace App\Services\Wedding;

use App\Models\WeddingUpload;
use App\Support\PerceptualHash;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Groups near-identical photos (the same shot resized, recompressed, rotated
 * or mirrored: within `wedding.perceptual_duplicate_distance` of each other)
 * into clusters, and makes each cluster's best copy — most pixels, then most
 * bytes, then earliest — its representative. The gallery shows only
 * representatives; the rest stay ready and are listed as "similar".
 *
 * A cluster is stored flat: every other member's duplicate_of_id points at the
 * representative, whose own is null. Clusters are single-linkage (a photo near
 * any member joins, merging clusters it bridges) and are only rebuilt from
 * scratch by rebuild(), so removing a bridging photo leaves the rest together.
 */
class PhotoClusterService
{
    private const LOCK = 'wedding.photo-clusters';

    /**
     * Put a newly ready photo into the cluster(s) it is near, electing the
     * best copy of the merged cluster.
     */
    public function place(WeddingUpload $photo): void
    {
        if ($photo->isVideo() || ! $photo->isReady() || $photo->perceptual_hashes === null) {
            return;
        }

        Cache::lock(self::LOCK, 30)->block(10, function () use ($photo): void {
            $representatives = $this->nearbyRepresentativeIds($photo);
            if ($representatives === []) {
                return;
            }

            $members = $this->readyPhotos()
                ->where(fn ($query) => $query->whereIn('id', $representatives)->orWhereIn('duplicate_of_id', $representatives))
                ->get();
            $members->push($photo);

            $this->elect($members->unique('id'));
        });
    }

    /**
     * Re-elect a cluster after one of its members stopped being shown (e.g.
     * its uploader removed it). Call after the photo's status has changed.
     */
    public function release(WeddingUpload $photo): void
    {
        if ($photo->isVideo()) {
            return;
        }

        Cache::lock(self::LOCK, 30)->block(10, function () use ($photo): void {
            $representative = $photo->duplicate_of_id ?? $photo->id;

            $photo->duplicate_of_id = null;
            $photo->saveQuietly();

            $members = $this->readyPhotos()
                ->where(fn ($query) => $query->whereKey($representative)->orWhere('duplicate_of_id', $representative))
                ->whereKeyNot($photo->id)
                ->get();

            if ($members->isNotEmpty()) {
                $this->elect($members);
            }
        });
    }

    /**
     * Recompute every cluster from the stored hashes, oldest photo first.
     * Returns the number of photos shown under another copy.
     */
    public function rebuild(): int
    {
        WeddingUpload::query()
            ->where('kind', WeddingUpload::KIND_PHOTO)
            ->whereNotNull('duplicate_of_id')
            ->update(['duplicate_of_id' => null]);

        $this->readyPhotos()
            ->whereNotNull('perceptual_hashes')
            ->orderBy('id')
            ->each(fn (WeddingUpload $photo) => $this->place($photo->refresh()));

        return $this->readyPhotos()->whereNotNull('duplicate_of_id')->count();
    }

    /**
     * Representatives of every cluster with a member near this photo.
     *
     * @return list<int>
     */
    private function nearbyRepresentativeIds(WeddingUpload $photo): array
    {
        $threshold = (int) config('wedding.perceptual_duplicate_distance');
        $representatives = [];

        $this->readyPhotos()
            ->whereNotNull('perceptual_hashes')
            ->whereKeyNot($photo->id)
            ->select(['id', 'perceptual_hashes', 'duplicate_of_id'])
            ->lazyById(500)
            ->each(function (WeddingUpload $candidate) use ($photo, $threshold, &$representatives): void {
                $distance = PerceptualHash::orientedDistance($photo->perceptual_hashes, $candidate->perceptual_hashes);
                if ($distance !== null && $distance <= $threshold) {
                    $representatives[$candidate->duplicate_of_id ?? $candidate->id] = true;
                }
            });

        return array_keys($representatives);
    }

    /**
     * @param  Collection<int, WeddingUpload>  $members
     */
    private function elect(Collection $members): void
    {
        $best = $members
            ->sort(fn (WeddingUpload $a, WeddingUpload $b): int => [$b->pixels(), $b->size_bytes ?? 0, $a->id]
                <=> [$a->pixels(), $a->size_bytes ?? 0, $b->id])
            ->first();

        foreach ($members as $member) {
            $target = $member->id === $best->id ? null : $best->id;
            if ($member->duplicate_of_id !== $target) {
                $member->duplicate_of_id = $target;
                $member->saveQuietly();
            }
        }
    }

    /**
     * @return Builder<WeddingUpload>
     */
    private function readyPhotos(): Builder
    {
        return WeddingUpload::query()->ready()->where('kind', WeddingUpload::KIND_PHOTO);
    }
}
