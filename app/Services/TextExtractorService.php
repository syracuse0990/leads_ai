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
        // Single call: describe image AND extract any text present
        return $this->kimiService->analyzeImage($filePath);
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
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Could not open PowerPoint file.');
        }

        $text = '';
        $slideIndex = 1;
        $hasImageSlides = false;

        while (($xmlContent = $zip->getFromName("ppt/slides/slide{$slideIndex}.xml")) !== false) {
            $text .= "## Slide {$slideIndex}\n";

            // Extract ALL text from the slide XML (covers text boxes, tables, SmartArt, grouped shapes)
            $slideText = $this->extractAllTextFromSlideXml($xmlContent);

            // Also check notes for this slide
            $notesXml = $zip->getFromName("ppt/notesSlides/notesSlide{$slideIndex}.xml");
            $notesText = '';
            if ($notesXml !== false) {
                $notesText = $this->extractAllTextFromSlideXml($notesXml);
                // Remove default placeholder text
                $notesText = preg_replace('/^\d+$/m', '', $notesText);
                $notesText = trim($notesText);
            }

            if (trim($slideText) !== '') {
                $text .= $slideText . "\n";
            }

            if ($notesText !== '') {
                $text .= "[Speaker Notes] " . $notesText . "\n";
            }

            // Check if this slide has images (for KIMI vision fallback)
            if (mb_strlen(trim($slideText)) < 30) {
                $hasImageSlides = true;
            }

            $text .= "\n";
            $slideIndex++;
        }

        $zip->close();

        $extractedText = trim($text);

        // If very little text was extracted, the presentation is likely image-heavy
        // Use KIMI vision to analyze the whole file as images would be ideal,
        // but at minimum flag that content may be missing
        if (mb_strlen($extractedText) < 50 && $slideIndex > 1) {
            $extractedText .= "\n\n[Note: This presentation appears to be image-heavy. Text extraction may be incomplete.]";
        }

        return $extractedText;
    }

    /**
     * Extract all text content from a PowerPoint XML string.
     * Handles text boxes, tables, grouped shapes, SmartArt, and other elements.
     */
    protected function extractAllTextFromSlideXml(string $xmlContent): string
    {
        $xml = @simplexml_load_string($xmlContent);
        if ($xml === false) {
            return '';
        }

        // Register all relevant namespaces
        $xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        // Get ALL paragraphs anywhere in the document (covers all shape types)
        $paragraphs = $xml->xpath('//a:p');
        if (!$paragraphs) {
            return '';
        }

        $lines = [];
        foreach ($paragraphs as $paragraph) {
            // Get text from regular runs
            $runs = $paragraph->xpath('.//a:r/a:t');
            $line = '';
            foreach ($runs as $run) {
                $line .= (string) $run;
            }

            // Also get text from field codes (dates, slide numbers, etc.)
            $fields = $paragraph->xpath('.//a:fld/a:t');
            foreach ($fields as $field) {
                $line .= (string) $field;
            }

            if (trim($line) !== '') {
                $lines[] = trim($line);
            }
        }

        return implode("\n", $lines);
    }

    protected function extractFromText(string $filePath): string
    {
        return file_get_contents($filePath);
    }
}
