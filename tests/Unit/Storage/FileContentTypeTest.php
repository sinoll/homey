<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\FileContentType;
use Codeception\Test\Unit;

final class FileContentTypeTest extends Unit
{
    public function testRecognizesPreviewableTypesWithoutCaseSensitivity(): void
    {
        $this->assertSame('audio/mpeg', FileContentType::forPreview('music.MP3'));
        $this->assertSame('application/pdf', FileContentType::forPreview('report.pdf'));
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            FileContentType::forPreview('report.DOCX'),
        );
    }

    public function testRejectsUnsafeOrUnsupportedInlineTypes(): void
    {
        $this->assertNull(FileContentType::forPreview('page.html'));
        $this->assertNull(FileContentType::forPreview('image.svg'));
        $this->assertNull(FileContentType::forPreview('archive.zip'));
    }

    public function testUsesFallbackForUnknownDownloadTypes(): void
    {
        $this->assertSame('application/octet-stream', FileContentType::forFilename('archive.zip'));
        $this->assertSame('application/custom', FileContentType::forFilename('archive.zip', 'application/custom'));
    }
}
