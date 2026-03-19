<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class TextExtractorService
{
    protected KimiService $kimiService;

    public function __construct(KimiService $kimiService)
    {
        $this->kimiService = $kimiService;
    }

    /**
     * Extract text from an uploaded file based on its MIME type.
     */
    public function extract(string $filePath, string $mimeType): string
    {
        return match (true) {
            str_contains($mimeType, 'pdf') => $this->extractFromPdf($filePath),
            str_starts_with($mimeType, 'image/') => $this->extractFromImage($filePath),
            str_contains($mimeType, 'wordprocessingml') || str_contains($mimeType, 'msword') => $this->extractFromWord($filePath),
            str_contains($mimeType, 'spreadsheetml') || str_contains($mimeType, 'ms-excel') => $this->extractFromExcel($filePath),
            str_contains($mimeType, 'presentationml') || str_contains($mimeType, 'ms-powerpoint') => $this->extractFromPowerPoint($filePath),
            str_contains($mimeType, 'text/') || str_contains($mimeType, 'markdown') || str_contains($mimeType, 'csv') => $this->extractFromText($filePath),
            default => throw new \RuntimeException("Unsupported file type: {$mimeType}"),
        };
    }

    protected function extractFromPdf(string $filePath): string
    {
        // Try smalot/pdfparser first
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();

            // Strip control characters (form feeds, etc.) before checking
            $cleanText = preg_replace('/[\x00-\x1F\x7F]+/', '', trim($text));
            if (!empty($cleanText) && mb_strlen($cleanText) > 20) {
                return $text;
            }
        } catch (\Exception $e) {
            // Fall through to pdftotext
        }

        // Fallback: use pdftotext (poppler-utils) for scanned/complex PDFs
        $escapedPath = escapeshellarg($filePath);
        $output = shell_exec("pdftotext {$escapedPath} - 2>/dev/null");

        $cleanOutput = preg_replace('/[\x00-\x1F\x7F]+/', '', trim($output ?? ''));
        if (!empty($cleanOutput) && mb_strlen($cleanOutput) > 20) {
            return $output;
        }

        // Last resort: OCR scanned PDF pages via KIMI vision
        return $this->ocrPdfViaKimi($filePath);
    }

    /**
     * Convert PDF pages to images using pdftoppm, then OCR each via KIMI vision.
     */
    protected function ocrPdfViaKimi(string $filePath): string
    {
        $tempDir = sys_get_temp_dir() . '/pdf_ocr_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            $escapedPath = escapeshellarg($filePath);
            $escapedDir = escapeshellarg($tempDir . '/page');

            // Convert PDF pages to JPEG images (150 DPI for speed/quality balance)
            shell_exec("pdftoppm -jpeg -r 150 {$escapedPath} {$escapedDir} 2>/dev/null");

            // Collect page images sorted by name
            $images = glob($tempDir . '/page-*.jpg');
            sort($images);

            if (empty($images)) {
                return '';
            }

            // Limit to first 50 pages to avoid excessive API calls
            $images = array_slice($images, 0, 50);

            $fullText = '';
            foreach ($images as $i => $imagePath) {
                try {
                    $pageText = $this->kimiService->extractTextFromImage($imagePath);
                    if (!empty(trim($pageText))) {
                        $fullText .= $pageText . "\n\n";
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("OCR failed for page " . ($i + 1), [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return trim($fullText);
        } finally {
            // Clean up temp images
            array_map('unlink', glob($tempDir . '/*'));
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    protected function extractFromImage(string $filePath): string
    {
        // First try text extraction (for documents, screenshots with text, etc.)
        $text = $this->kimiService->extractTextFromImage($filePath);
        $cleanText = preg_replace('/[\x00-\x1F\x7F]+/', '', trim($text));

        // Check if KIMI actually found text vs returning a "no text" explanation
        $noTextPatterns = ['no text', 'no visible text', 'no readable text', 'does not contain', 'not present', 'not visible'];
        $lowerText = mb_strtolower($cleanText);
        $isNoTextResponse = false;
        foreach ($noTextPatterns as $pattern) {
            if (str_contains($lowerText, $pattern)) {
                $isNoTextResponse = true;
                break;
            }
        }

        if (!$isNoTextResponse && !empty($cleanText) && mb_strlen($cleanText) > 20) {
            return $text;
        }

        // No meaningful text found — describe the image content instead
        return $this->kimiService->describeImage($filePath);
    }

    protected function extractFromWord(string $filePath): string
    {
        $phpWord = WordIOFactory::load($filePath);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= $this->extractWordElement($element) . "\n";
            }
        }

        return trim($text);
    }

    protected function extractWordElement($element): string
    {
        $text = '';

        if (method_exists($element, 'getText')) {
            $text .= $element->getText() . ' ';
        }

        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractWordElement($child);
            }
        }

        // Handle tables
        if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            foreach ($element->getRows() as $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $cellText = '';
                    foreach ($cell->getElements() as $cellElement) {
                        $cellText .= $this->extractWordElement($cellElement);
                    }
                    $cells[] = trim($cellText);
                }
                $text .= implode(' | ', $cells) . "\n";
            }
        }

        return $text;
    }

    protected function extractFromExcel(string $filePath): string
    {
        $spreadsheet = SpreadsheetIOFactory::load($filePath);
        $text = '';

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetTitle = $sheet->getTitle();
            $text .= "## {$sheetTitle}\n";

            foreach ($sheet->getRowIterator() as $row) {
                $cells = [];
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(true);

                foreach ($cellIterator as $cell) {
                    $value = $cell->getFormattedValue();
                    if ($value !== '' && $value !== null) {
                        $cells[] = $value;
                    }
                }

                if (!empty($cells)) {
                    $text .= implode(' | ', $cells) . "\n";
                }
            }

            $text .= "\n";
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return trim($text);
    }

    protected function extractFromPowerPoint(string $filePath): string
    {
        // PhpSpreadsheet can't read PPTX, so we use the ZIP-based XML approach
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Could not open PowerPoint file.');
        }

        $text = '';
        $slideIndex = 1;

        while (($xmlContent = $zip->getFromName("ppt/slides/slide{$slideIndex}.xml")) !== false) {
            $text .= "## Slide {$slideIndex}\n";

            // Strip XML tags and extract text content
            $xml = simplexml_load_string($xmlContent);
            $xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $paragraphs = $xml->xpath('//a:p');

            foreach ($paragraphs as $paragraph) {
                $runs = $paragraph->xpath('.//a:r/a:t');
                $line = '';
                foreach ($runs as $run) {
                    $line .= (string) $run;
                }
                if (trim($line) !== '') {
                    $text .= trim($line) . "\n";
                }
            }

            $text .= "\n";
            $slideIndex++;
        }

        $zip->close();

        return trim($text);
    }

    protected function extractFromText(string $filePath): string
    {
        return file_get_contents($filePath);
    }
}
