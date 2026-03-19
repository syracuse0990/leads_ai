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
        $parser = new PdfParser();
        $pdf = $parser->parseFile($filePath);
        return $pdf->getText();
    }

    protected function extractFromImage(string $filePath): string
    {
        return $this->kimiService->extractTextFromImage($filePath);
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
