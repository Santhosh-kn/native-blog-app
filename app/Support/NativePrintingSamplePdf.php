<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class NativePrintingSamplePdf
{
    public const FILE_NAME = 'Native Printing – Sample.pdf';

    public function ensure(): string
    {
        $directory = storage_path('app/native-printing');

        if (
            ! is_dir($directory) &&
            ! mkdir($directory, 0700, true) &&
            ! is_dir($directory)
        ) {
            throw new RuntimeException(
                'The native printing sample directory could not be created.',
            );
        }

        $path = $directory.DIRECTORY_SEPARATOR.self::FILE_NAME;
        $contents = $this->build();
        $expectedHash = hash('sha256', $contents);

        if (is_file($path)) {
            $existingHash = hash_file('sha256', $path);

            if (
                is_string($existingHash) &&
                hash_equals($expectedHash, $existingHash)
            ) {
                $canonicalPath = realpath($path);

                if (is_string($canonicalPath)) {
                    return $canonicalPath;
                }
            }
        }

        $written = file_put_contents(
            $path,
            $contents,
            LOCK_EX,
        );

        if ($written !== strlen($contents)) {
            throw new RuntimeException(
                'The native printing sample PDF could not be created.',
            );
        }

        @chmod($path, 0600);

        $canonicalPath = realpath($path);

        if (! is_string($canonicalPath)) {
            throw new RuntimeException(
                'The native printing sample PDF could not be resolved.',
            );
        }

        return $canonicalPath;
    }

    private function build(): string
    {
        $pageOne = implode("\n", [
            'BT',
            '/F1 22 Tf',
            '72 760 Td',
            '(NativePHP Mobile) Tj',
            '0 -36 Td',
            '/F1 15 Tf',
            '(Native Printing and PDF Preview) Tj',
            '0 -30 Td',
            '/F1 12 Tf',
            '(Application-local two-page test document.) Tj',
            '0 -24 Td',
            '(Use Preview to verify scrolling and zoom.) Tj',
            '0 -24 Td',
            '(Use Print to open the Android print framework.) Tj',
            'ET',
            '',
        ]);

        $pageTwo = implode("\n", [
            'BT',
            '/F1 22 Tf',
            '72 760 Td',
            '(Page 2) Tj',
            '0 -36 Td',
            '/F1 12 Tf',
            '(This page verifies lazy multi-page rendering.) Tj',
            '0 -24 Td',
            '(The file name contains spaces and Unicode.) Tj',
            '0 -24 Td',
            '(Closing preview must return safely to the app.) Tj',
            'ET',
            '',
        ]);

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => (
                '<< /Type /Pages '.
                '/Kids [3 0 R 5 0 R] /Count 2 >>'
            ),
            3 => (
                '<< /Type /Page /Parent 2 0 R '.
                '/MediaBox [0 0 595 842] '.
                '/Resources << /Font << /F1 7 0 R >> >> '.
                '/Contents 4 0 R >>'
            ),
            4 => $this->streamObject($pageOne),
            5 => (
                '<< /Type /Page /Parent 2 0 R '.
                '/MediaBox [0 0 595 842] '.
                '/Resources << /Font << /F1 7 0 R >> >> '.
                '/Contents 6 0 R >>'
            ),
            6 => $this->streamObject($pageTwo),
            7 => (
                '<< /Type /Font /Subtype /Type1 '.
                '/BaseFont /Helvetica >>'
            ),
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];

        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n";
            $pdf .= $object."\n";
            $pdf .= "endobj\n";
        }

        $xrefOffset = strlen($pdf);
        $size = count($objects) + 1;

        $pdf .= "xref\n";
        $pdf .= "0 {$size}\n";
        $pdf .= "0000000000 65535 f \n";

        for ($number = 1; $number < $size; $number++) {
            $pdf .= sprintf(
                "%010d 00000 n \n",
                $offsets[$number],
            );
        }

        $pdf .= "trailer\n";
        $pdf .= "<< /Size {$size} /Root 1 0 R >>\n";
        $pdf .= "startxref\n";
        $pdf .= "{$xrefOffset}\n";
        $pdf .= "%%EOF\n";

        return $pdf;
    }

    private function streamObject(string $contents): string
    {
        return sprintf(
            "<< /Length %d >>\nstream\n%sendstream",
            strlen($contents),
            $contents,
        );
    }
}
