<?php

declare(strict_types=1);

final class ItineraryExporter
{
    private const DEFAULT_EVENT_MINUTES = 90;
    private const GAP_MINUTES = 15;

    public static function createIcs(array $trip): string
    {
        $trip = self::normalizeTrip($trip);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Red Clay Road Trip//Itinerary//EN',
            'CALSCALE:GREGORIAN',
        ];

        $summary = $trip['summary'] ?: sprintf('Road trip through %s', $trip['city_of_interest']);
        $descriptionParts = array_filter([
            $trip['route_overview'],
            $trip['total_travel_time'],
            $trip['additional_tips'],
        ]);

        $start = self::parseDateTime($trip['departure_datetime']);
        $cursor = clone $start;
        $stopEvents = [];

        foreach ($trip['stops'] as $index => $stop) {
            $eventStart = $cursor;
            $durationMinutes = self::parseDurationMinutes($stop['duration']);
            $eventEnd = $eventStart->add(new \DateInterval('PT' . $durationMinutes . 'M'));

            $eventLines = ['BEGIN:VEVENT'];
            $eventLines[] = 'UID:' . self::generateUid($trip, $index + 1) . '@redclay';
            $eventLines[] = self::foldLine('DTSTART:' . self::formatAsUtc($eventStart));
            $eventLines[] = self::foldLine('DTEND:' . self::formatAsUtc($eventEnd));
            $eventLines[] = self::foldLine('SUMMARY:' . self::escapeText(sprintf('Stop %d: %s', $index + 1, $stop['title'])));

            $details = array_filter([
                $stop['address'],
                $stop['description'],
                $stop['historical_note'] ? 'History: ' . $stop['historical_note'] : '',
                $stop['challenge'] ? 'Challenge: ' . $stop['challenge'] : '',
            ]);
            if ($details) {
                $eventLines[] = self::foldLine('DESCRIPTION:' . self::escapeText(implode(' \n', $details)));
            }
            $eventLines[] = self::foldLine('LOCATION:' . self::escapeText($stop['address'] ?: $stop['title']));
            $eventLines[] = 'END:VEVENT';

            $stopEvents[] = $eventLines;
            $cursor = $eventEnd->add(new \DateInterval('PT' . self::GAP_MINUTES . 'M'));
        }

        if ($cursor <= $start) {
            $cursor = $start->add(new \DateInterval('PT' . self::DEFAULT_EVENT_MINUTES . 'M'));
        }

        $tripEvent = ['BEGIN:VEVENT'];
        $tripEvent[] = 'UID:' . self::generateUid($trip) . '@redclay';
        $tripEvent[] = self::foldLine('DTSTART:' . self::formatAsUtc($start));
        $tripEvent[] = self::foldLine('DTEND:' . self::formatAsUtc($cursor));
        $tripEvent[] = self::foldLine('SUMMARY:' . self::escapeText($summary));
        if ($descriptionParts) {
            $tripEvent[] = self::foldLine('DESCRIPTION:' . self::escapeText(implode(' \n', $descriptionParts)));
        }
        $tripEvent[] = self::foldLine('LOCATION:' . self::escapeText($trip['start_location'] ?: $trip['city_of_interest']));
        $tripEvent[] = 'END:VEVENT';

        $lines = array_merge($lines, $tripEvent);
        foreach ($stopEvents as $eventLines) {
            $lines = array_merge($lines, $eventLines);
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    public static function createPdf(array $trip): string
    {
        $trip = self::normalizeTrip($trip);
        $lines = [];

        $lines[] = 'Red Clay Road Trip';
        $lines[] = '';
        $lines[] = 'Route: ' . ($trip['route_overview'] ?: '—');
        $lines[] = 'Travel Time: ' . ($trip['total_travel_time'] ?: '—');
        $lines[] = 'Departure: ' . ($trip['departure_datetime'] ?: '—');
        $lines[] = 'Starting From: ' . ($trip['start_location'] ?: '—');
        $lines[] = 'City of Interest: ' . ($trip['city_of_interest'] ?: '—');
        if ($trip['traveler_preferences']) {
            $lines[] = 'Traveler Notes: ' . $trip['traveler_preferences'];
        }
        if ($trip['summary']) {
            $lines[] = '';
            $lines[] = 'Summary: ' . $trip['summary'];
        }

        foreach ($trip['stops'] as $index => $stop) {
            $lines[] = '';
            $lines[] = sprintf('Stop %d: %s', $index + 1, $stop['title'] ?: 'Untitled stop');
            $lines[] = 'Address: ' . ($stop['address'] ?: '—');
            $lines[] = 'Suggested time: ' . ($stop['duration'] ?: '—');
            if ($stop['description']) {
                $lines[] = 'Details: ' . $stop['description'];
            }
            if ($stop['historical_note']) {
                $lines[] = 'Historical note: ' . $stop['historical_note'];
            }
            if ($stop['challenge']) {
                $lines[] = 'Challenge: ' . $stop['challenge'];
            }
        }

        if ($trip['additional_tips']) {
            $lines[] = '';
            $lines[] = 'Travel Tips: ' . $trip['additional_tips'];
        }

        $wrapped = [];
        foreach ($lines as $line) {
            if ($line === '') {
                $wrapped[] = '';
                continue;
            }
            $chunks = preg_split('/\r\n|\r|\n/', wordwrap($line, 90));
            foreach ($chunks as $chunk) {
                $wrapped[] = $chunk;
            }
        }

        return self::renderSimplePdf($wrapped);
    }

    private static function renderSimplePdf(array $lines): string
    {
        $pdf = "%PDF-1.4\n";
        $objects = [];
        $offsets = [];

        $addObject = static function (string $content) use (&$pdf, &$objects, &$offsets): int {
            $objectNumber = count($objects) + 1;
            $offsets[$objectNumber] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $objectNumber, $content);
            $objects[$objectNumber] = $content;
            return $objectNumber;
        };

        $catalogId = $addObject('<< /Type /Catalog /Pages 2 0 R >>');
        $pagesId = $addObject('<< /Type /Pages /Kids [3 0 R] /Count 1 >>');

        $contentsStream = self::buildPdfStream($lines);
        $contentsId = $addObject('<< /Length ' . strlen($contentsStream) . " >>\nstream\n" . $contentsStream . "\nendstream");
        $fontId = $addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $pageObject = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
            . '/Resources << /Font << /F1 ' . $fontId . ' 0 R >> >> '
            . '/Contents ' . $contentsId . ' 0 R >>';
        $pageId = $addObject($pageObject);

        // Update pages object now that we know the page id
        $pagesContent = '<< /Type /Pages /Kids [' . $pageId . ' 0 R] /Count 1 >>';
        $pdf = str_replace('<< /Type /Pages /Kids [3 0 R] /Count 1 >>', $pagesContent, $pdf);

        $xrefPosition = strlen($pdf);
        $pdf .= 'xref' . PHP_EOL;
        $pdf .= '0 ' . (count($objects) + 1) . PHP_EOL;
        $pdf .= '0000000000 65535 f ' . PHP_EOL;
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i] ?? 0) . PHP_EOL;
        }
        $pdf .= 'trailer' . PHP_EOL;
        $pdf .= '<< /Size ' . (count($objects) + 1) . ' /Root ' . $catalogId . ' 0 R >>' . PHP_EOL;
        $pdf .= 'startxref' . PHP_EOL . $xrefPosition . PHP_EOL;
        $pdf .= '%%EOF';

        return $pdf;
    }

    private static function buildPdfStream(array $lines): string
    {
        $streamLines = ['BT', '/F1 12 Tf', '72 770 Td'];
        $firstLine = true;
        foreach ($lines as $line) {
            if ($firstLine) {
                $firstLine = false;
            } else {
                $streamLines[] = '0 -16 Td';
            }
            if ($line === '') {
                continue;
            }
            $streamLines[] = '(' . self::escapePdfText($line) . ') Tj';
        }
        $streamLines[] = 'ET';

        return implode("\n", $streamLines);
    }

    private static function normalizeTrip(array $trip): array
    {
        $defaults = [
            'route_overview' => '',
            'total_travel_time' => '',
            'summary' => '',
            'additional_tips' => '',
            'start_location' => '',
            'departure_datetime' => '',
            'city_of_interest' => '',
            'traveler_preferences' => '',
            'stops' => [],
        ];
        $trip = array_merge($defaults, $trip);
        $trip['stops'] = array_map(static function ($stop): array {
            $stopDefaults = [
                'title' => '',
                'address' => '',
                'duration' => '',
                'description' => '',
                'historical_note' => '',
                'challenge' => '',
            ];
            return array_merge($stopDefaults, is_array($stop) ? $stop : []);
        }, $trip['stops']);

        return $trip;
    }

    private static function parseDateTime(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value ?: 'now', new \DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
    }

    private static function formatAsUtc(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private static function parseDurationMinutes(string $duration): int
    {
        if (!$duration) {
            return self::DEFAULT_EVENT_MINUTES;
        }
        $matches = [];
        if (preg_match('/(\d+)(?:\s*)(hour|hr)/i', $duration, $matches)) {
            $hours = (int) $matches[1];
            return max(30, $hours * 60);
        }
        if (preg_match('/(\d+)(?:\s*)(minute|min)/i', $duration, $matches)) {
            return max(30, (int) $matches[1]);
        }
        return self::DEFAULT_EVENT_MINUTES;
    }

    private static function escapeText(string $text): string
    {
        $text = str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $text);
        return $text;
    }

    private static function foldLine(string $line): string
    {
        $result = [];
        $length = strlen($line);
        $chunk = '';
        for ($i = 0; $i < $length; $i++) {
            $chunk .= $line[$i];
            if (strlen($chunk) >= 73) {
                $result[] = $chunk;
                $chunk = ' ';
            }
        }
        if ($chunk !== '') {
            $result[] = $chunk;
        }
        return implode("\r\n", $result);
    }

    private static function generateUid(array $trip, int $suffix = 0): string
    {
        $seed = $trip['start_location'] . '|' . $trip['city_of_interest'] . '|' . $trip['departure_datetime'] . '|' . $suffix;
        return bin2hex(substr(hash('sha256', $seed, true), 0, 8));
    }

    private static function escapePdfText(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
