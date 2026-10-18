<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Inventory;

use PerfectConsign\Feature\Inventory\ItemPhotoIntake;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

final class ItemPhotoIntakeTest extends TestCase
{
    public function testAMissingPhotoIsOptionalUntilTheFieldRequiresOne(): void
    {
        $intake = $this->intake();

        self::assertNull($intake->hex(null, false));
        self::assertSame('JPEG, PNG, or WebP. ' . $intake->label() . ' or smaller.', $intake->rule());
        try {
            $intake->hex(['tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'name' => ''], true);
            self::fail('a required photo was skipped');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose a photo.', $error->getMessage());
        }
    }

    public function testAnUnfinishedOrOversizedUploadIsRefused(): void
    {
        $intake = $this->intake();
        $over = [
            'tmp_name' => 'none',
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 1,
            'name' => 'big.jpg',
        ];
        try {
            $intake->hex($over, false);
            self::fail('an oversized photo was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('or smaller', $error->getMessage());
        }
        $partial = $over;
        $partial['error'] = UPLOAD_ERR_PARTIAL;
        try {
            $intake->hex($partial, false);
            self::fail('a partial photo was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That photo did not finish uploading. Try again.', $error->getMessage());
        }
        $unreadable = $over;
        $unreadable['error'] = UPLOAD_ERR_NO_TMP_DIR;
        try {
            $intake->hex($unreadable, false);
            self::fail('a missing temp file was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That photo could not be read.', $error->getMessage());
        }
        $form = $over;
        $form['error'] = UPLOAD_ERR_FORM_SIZE;
        try {
            $intake->hex($form, false);
            self::fail('a form-capped photo was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('or smaller', $error->getMessage());
        }
        $bytes = [
            'tmp_name' => 'none',
            'error' => UPLOAD_ERR_OK,
            'size' => ItemPhotoIntake::MAX_BYTES + 1,
            'name' => 'big.jpg',
        ];
        try {
            $intake->hex($bytes, false);
            self::fail('a photo past the byte cap was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('or smaller', $error->getMessage());
        }
    }

    public function testTheSizeLabelFollowsTheByteCap(): void
    {
        $intake = $this->intake();
        $label = $intake->label();
        self::assertNotSame('', $label);
        self::assertMatchesRegularExpression('/(MB|KB)$/', $label);
        self::assertSame('JPEG, PNG, or WebP. ' . $label . ' or smaller.', $intake->rule());
        self::assertGreaterThan(0, $intake->bytes());
        self::assertNull($intake->media("\xFF\xD8"));
        self::assertNull($intake->media('RIFFWEBP'));
    }

    public function testTheFileKindIsReadFromTheHeader(): void
    {
        $intake = $this->intake();

        self::assertSame('image/jpeg', $intake->media("\xFF\xD8\xFF"));
        self::assertSame('image/png', $intake->media("\x89PNG\r\n\x1a\n"));
        self::assertSame('image/webp', $intake->media('RIFF' . "\x00\x00\x00\x00" . 'WEBP'));
        self::assertNull($intake->media('not-a-photo'));
    }

    public function testALocalTempFileIsNotTreatedAsAnUpload(): void
    {
        $intake = $this->intake();
        try {
            $intake->hex([
                'tmp_name' => __FILE__,
                'error' => UPLOAD_ERR_OK,
                'size' => 10,
                'name' => 'x.jpg',
            ], false);
            self::fail('a local file was stored as a photo');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That photo could not be read.', $error->getMessage());
        }
        $ini = new ReflectionMethod(ItemPhotoIntake::class, 'iniBytes');
        self::assertSame(0, $ini->invoke($intake, 'this_ini_key_is_missing'));
        $label = $intake->label();
        self::assertNotSame('', $label);
    }

    private function intake(): ItemPhotoIntake
    {
        return new ItemPhotoIntake(new NullLogger());
    }
}
