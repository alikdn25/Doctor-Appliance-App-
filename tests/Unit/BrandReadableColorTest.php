<?php

use App\Models\Brand;

it('keeps a dark enough brand colour as it is', function () {
    expect(Brand::readableColor('#b45309'))->toBe('#b45309')
        ->and(Brand::readableColor('#0f172a'))->toBe('#0f172a');
});

it('darkens a light brand colour until white text on it is readable', function () {
    $color = Brand::readableColor('#6ae1f0');

    expect($color)->not->toBe('#6ae1f0')->toMatch('/^#[0-9a-f]{6}$/');

    [$r, $g, $b] = array_map(function (int $i) use ($color) {
        $c = hexdec(substr($color, $i, 2)) / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }, [1, 3, 5]);

    expect(1.05 / (0.2126 * $r + 0.7152 * $g + 0.0722 * $b + 0.05))->toBeGreaterThanOrEqual(4.5);
});

it('falls back to the default colour when none or an invalid one is set', function () {
    expect(Brand::readableColor(null))->toBe('#0f172a')
        ->and(Brand::readableColor('red'))->toBe('#0f172a');
});
