<?php

namespace App\Services;

use App\Models\ProductRelease;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductReleaseService
{
    private const FIELDS = ['product_id', 'version_number', 'changelog', 'download_url', 'release_date', 'audience', 'is_published'];

    private function disk(): ?string
    {
        return config('crm.uploads.disk');
    }

    public function create(array $data, ?UploadedFile $installer, ?string $by): ProductRelease
    {
        return DB::transaction(function () use ($data, $installer, $by) {
            $release = ProductRelease::query()->create(Arr::only($data, self::FIELDS) + ['created_by' => $by]);
            $this->syncAudience($release, $data);

            if ($installer) {
                $this->attachInstaller($release, $installer);
            }

            return $release->refresh();
        });
    }

    public function update(ProductRelease $release, array $data): ProductRelease
    {
        return DB::transaction(function () use ($release, $data) {
            $release->fill(Arr::only($data, self::FIELDS))->save();
            $this->syncAudience($release, $data);

            return $release->refresh();
        });
    }

    /** Replaces the installer (the old file is removed). */
    public function attachInstaller(ProductRelease $release, UploadedFile $file): ProductRelease
    {
        $old = $release->installer_path;
        $path = $file->store('product-releases/'.$release->product_id, ['disk' => $this->disk()]);

        $release->update([
            'installer_path' => $path, 'installer_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'installer_mime' => (string) $file->getMimeType(), 'installer_size' => (int) $file->getSize(),
        ]);

        if ($old) {
            Storage::disk($this->disk())->delete($old);
        }

        return $release;
    }

    public function removeInstaller(ProductRelease $release): ProductRelease
    {
        if ($release->installer_path) {
            Storage::disk($this->disk())->delete($release->installer_path);
        }

        $release->update(['installer_path' => null, 'installer_name' => null, 'installer_mime' => null, 'installer_size' => null]);

        return $release;
    }

    public function delete(ProductRelease $release): void
    {
        DB::transaction(function () use ($release) {
            $this->removeInstaller($release);
            $release->delete();
        });
    }

    /** Named customers only matter (and are kept) for the "specific" audience. */
    private function syncAudience(ProductRelease $release, array $data): void
    {
        if ($release->audience !== 'specific') {
            $release->customers()->detach();

            return;
        }

        if (array_key_exists('customer_ids', $data)) {
            $release->customers()->sync($data['customer_ids']);
        }
    }

    public function storageDisk(): ?string
    {
        return $this->disk();
    }
}
