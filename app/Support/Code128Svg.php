<?php

namespace App\Support;

use InvalidArgumentException;

class Code128Svg
{
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    public static function render(string $value, int $moduleWidth = 3, int $height = 88): string
    {
        if ($value === '' || preg_match('/[^\x20-\x7E]/', $value)) {
            throw new InvalidArgumentException('Code 128-B supports printable ASCII characters only.');
        }

        $codes = [104];
        $checksum = 104;
        foreach (str_split($value) as $position => $character) {
            $code = ord($character) - 32;
            $codes[] = $code;
            $checksum += $code * ($position + 1);
        }
        $codes[] = $checksum % 103;
        $codes[] = 106;

        $quietZone = 12;
        $moduleCount = ($quietZone * 2) + array_sum(array_map(
            fn (int $code): int => array_sum(array_map('intval', str_split(self::PATTERNS[$code]))),
            $codes,
        ));
        $width = $moduleCount * $moduleWidth;
        $x = $quietZone * $moduleWidth;
        $bars = '';

        foreach ($codes as $code) {
            foreach (str_split(self::PATTERNS[$code]) as $index => $modules) {
                $barWidth = ((int) $modules) * $moduleWidth;
                if ($index % 2 === 0) {
                    $bars .= '<rect x="'.$x.'" y="0" width="'.$barWidth.'" height="'.$height.'"/>';
                }
                $x += $barWidth;
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Code 128 barcode" viewBox="0 0 '.$width.' '.$height.'" width="'.$width.'" height="'.$height.'" style="display:block;width:100%;max-width:100%;height:auto;background:#fff" preserveAspectRatio="xMidYMid meet" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><g fill="#000">'.$bars.'</g></svg>';
    }
}
