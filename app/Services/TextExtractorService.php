<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
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

        while (($xmlContent = $zip->getFromName("ppt/slides/slide{$slideIndex}.xml")) !== false) {
            $text .= "## Slide {$slideIndex}\n";

            // Extract text from XML (text boxes, tables, SmartArt, grouped shapes)
            $slideText = $this->extractAllTextFromSlideXml($xmlContent);

            // Also check notes
            $notesXml = $zip->getFromName("ppt/notesSlides/notesSlide{$slideIndex}.xml");
            $notesText = '';
            if ($notesXml !== false) {
                $notesText = $this->extractAllTextFromSlideXml($notesXml);
                $notesText = preg_replace('/^\d+$/m', '', $notesText);
                $notesText = trim($notesText);
            }

            // If slide has very little text, try OCR on its embedded image
            if (mb_strlen(trim($slideText)) < 30) {
                $ocrText = $this->ocrSlideImage($zip, $slideIndex);
                if ($ocrText !== '') {
                    $slideText = $ocrText;
                }
            }

            if (trim($slideText) !== '') {
                $text .= $slideText . "\n";
            }

            if ($notesText !== '') {
                $text .= "[Speaker Notes] " . $notesText . "\n";
            }

            $text .= "\n";
            $slideIndex++;
        }

        $zip->close();

        return trim($text);
    }

    /**
     * Extract the main image from a slide and OCR it via KIMI vision.
     */
    protected function ocrSlideImage(\ZipArchive $zip, int $slideIndex): string
    {
        $relsXml = $zip->getFromName("ppt/slides/_rels/slide{$slideIndex}.xml.rels");
        if (!$relsXml) {
            return '';
        }

        // Find image relationship: <Relationship ... Type="...image" Target="../media/imageN.ext"/>
        $imagePath = null;
        if (preg_match_all('/Relationship\s[^>]+>/s', $relsXml, $relMatches)) {
            foreach ($relMatches[0] as $rel) {
                if (str_contains($rel, 'relationships/image') && preg_match('/Target="\.\.\/media\/([^"]+)"/', $rel, $m)) {
                    $imagePath = 'ppt/media/' . $m[1];
                    break;
                }
            }
        }

        if (!$imagePath) {
            return '';
        }

        $imageData = $zip->getFromName($imagePath);
        if ($imageData === false) {
            return '';
        }

        $ext = pathinfo($imagePath, PATHINFO_EXTENSION) ?: 'jpg';
        $tmpFile = tempnam(sys_get_temp_dir(), 'pptx_slide_') . '.' . strtolower($ext);

        try {
            file_put_contents($tmpFile, $imageData);
            $ocrText = $this->kimiService->extractTextFromImage($tmpFile);
            return trim($ocrText);
        } catch (\Exception $e) {
            Log::warning("KIMI OCR failed for slide {$slideIndex}", ['error' => $e->getMessage()]);
            return '';
        } finally {
            @unlink($tmpFile);
            // tempnam creates a file too, clean up the one without extension
            $base = preg_replace('/\.[^.]+$/', '', $tmpFile);
            if ($base !== $tmpFile) {
                @unlink($base);
            }
        }
    }

    /**
     * Extract all text content from a PowerPoint XML string.
     * Uses regex to extract <a:t> text runs — works regardless of XML namespace issues.
     */
    protected function extractAllTextFromSlideXml(string $xmlContent): string
    {
        // Primary method: regex-based extraction of <a:t> elements (most reliable)
        // This catches all text runs regardless of namespace prefix or XML structure
        $lines = [];
        $currentLine = '';

        // Split by paragraph boundaries to maintain text grouping
        // PowerPoint paragraphs are wrapped in <a:p>...</a:p>
        $paragraphs = preg_split('/<[^>]*:p[\s>]/', $xmlContent);

        foreach ($paragraphs as $para) {
            // Find the end of this paragraph
            $endPos = strpos($para, ':p>');
            if ($endPos !== false) {
                $para = substr($para, 0, $endPos);
            }

            // Extract all text run content: <a:t>text</a:t> or <X:t>text</X:t>
            if (preg_match_all('/<[^>]*:t[^>]*>([^<]*)<\/[^>]*:t>/s', $para, $matches)) {
                $line = implode('', $matches[1]);
                $line = html_entity_decode(trim($line), ENT_QUOTES | ENT_XML1, 'UTF-8');
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        // Fallback: if paragraph splitting didn't work, extract ALL <*:t> content
        if (empty($lines)) {
            if (preg_match_all('/<[^>]*:t[^>]*>([^<]+)<\/[^>]*:t>/s', $xmlContent, $matches)) {
                foreach ($matches[1] as $text) {
                    $text = html_entity_decode(trim($text), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    if ($text !== '') {
                        $lines[] = $text;
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    protected function extractFromText(string $filePath): string
    {
        return file_get_contents($filePath);
    }
}
