<?php

namespace App\Services\Memory;

class MemoryJsonCodec
{
    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    public function readStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $trimmed = trim($item);
            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  array<int, string>  $value
     * @return array<int, string>
     */
    public function writeStringList(array $value): array
    {
        return $this->readStringList($value);
    }

    /**
     * @param  mixed  $value
     * @return array<int, array{brand: string, orders: int}>
     */
    public function readTopBrands(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $output = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $brand = trim((string) ($item['brand'] ?? ''));
            if ($brand === '') {
                continue;
            }

            $output[] = [
                'brand' => $brand,
                'orders' => max(0, (int) ($item['orders'] ?? 0)),
            ];
        }

        return $output;
    }

    /**
     * @param  array<int, array{brand: string, orders: int}>  $value
     * @return array<int, array{brand: string, orders: int}>
     */
    public function writeTopBrands(array $value): array
    {
        return $this->readTopBrands($value);
    }
}
