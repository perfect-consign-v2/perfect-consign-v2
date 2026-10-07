<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class BarcodePng
{
    private const QUIET = 10;

    public function draw(string $bits, int $ppm, int $height): string
    {
        if ($bits === '' || $ppm < 1 || $height < 1) {
            throw new \RuntimeException('barcode image could not be drawn');
        }
        $width = (strlen($bits) + (self::QUIET * 2)) * $ppm;
        $painted = $this->paint(str_repeat("\xFF", intdiv($width + 7, 8)), $bits, $ppm);
        $raw = str_repeat("\x00" . $painted, $height);
        $deflated = gzcompress($raw, 9);
        if ($deflated === false) {
            throw new \RuntimeException('barcode image could not be drawn');
        }

        return "\x89PNG\r\n\x1a\n"
            . $this->chunk('IHDR', pack('NNCCCCC', $width, $height, 1, 0, 0, 0, 0))
            . $this->chunk('IDAT', $deflated)
            . $this->chunk('IEND', '');
    }

    private function paint(string $row, string $bits, int $ppm): string
    {
        $length = strlen($bits);
        for ($index = 0; $index < $length; $index++) {
            if ($bits[$index] !== '1') {
                continue;
            }
            $row = $this->bar($row, (self::QUIET + $index) * $ppm, $ppm);
        }

        return $row;
    }

    private function bar(string $row, int $start, int $ppm): string
    {
        for ($offset = 0; $offset < $ppm; $offset++) {
            $x = $start + $offset;
            $byte = intdiv($x, 8);
            $mask = ~(1 << (7 - ($x % 8))) & 0xFF;
            $row[$byte] = chr(ord($row[$byte]) & $mask);
        }

        return $row;
    }

    private function chunk(string $type, string $data): string
    {
        $crc = hexdec(hash('crc32b', $type . $data));
        if (!is_int($crc)) {
            throw new \RuntimeException('barcode image could not be drawn');
        }

        return pack('N', strlen($data)) . $type . $data . pack('N', $crc);
    }
}
