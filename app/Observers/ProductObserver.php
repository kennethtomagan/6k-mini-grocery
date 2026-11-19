<?php

namespace App\Observers;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductObserver
{
    /**
     * Deletes the previous image file from the public disk once a product's image_path really changed.
     *
     * Soft deletes never reach this hook with a changed path, so a trashed product keeps its file. A file that
     * any other product, including a trashed one, still references is left in place. Only paths inside the
     * `products/` directory without a `..` segment are deleted, and a storage failure is reported instead of
     * failing a save whose row is already written.
     */
    public function updated(Product $product): void
    {
        if (! $product->wasChanged('image_path')) {
            return;
        }

        $originalPath = $product->getOriginal('image_path');

        if (! self::isDeletablePath($originalPath) || $originalPath === $product->image_path) {
            return;
        }

        $isStillReferenced = Product::withTrashed()
            ->where('image_path', $originalPath)
            ->whereKeyNot($product->getKey())
            ->exists();

        if ($isStillReferenced) {
            return;
        }

        try {
            Storage::disk('public')->delete($originalPath);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function isDeletablePath(mixed $path): bool
    {
        return is_string($path)
            && str_starts_with($path, 'products/')
            && ! in_array('..', explode('/', str_replace('\\', '/', $path)), true);
    }
}
