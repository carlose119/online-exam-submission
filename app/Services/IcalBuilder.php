<?php

namespace App\Services;

use App\Models\Meeting;

class IcalBuilder
{
    /**
     * Build an RFC 5545 VCALENDAR/VEVENT string for a single meeting.
     *
     * The caller MUST eager-load $meeting->classroom->teacher before calling
     * this method, otherwise an extra query per call will be issued.
     */
    public function build(Meeting $meeting): string
    {
        $uid = "meeting-{$meeting->id}@online-exam-submission.test";

        $dtStart = $meeting->scheduled_at->copy()->utc()->format('Ymd\THis\Z');

        $duration = $meeting->duration_minutes ?? 60;
        $dtEnd = $meeting->scheduled_at->copy()->utc()->addMinutes($duration)->format('Ymd\THis\Z');

        $summary = $this->escapeIcalText($meeting->title);

        $teacher = $meeting->classroom->teacher;
        $organizer = 'ORGANIZER;CN=' . $teacher->name . ':mailto:' . $teacher->email;

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//online-exam-submission//ical-export//EN',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            "DTSTART:{$dtStart}",
            "DTEND:{$dtEnd}",
            "SUMMARY:{$summary}",
        ];

        if ($meeting->agenda !== null) {
            $lines[] = 'DESCRIPTION:' . $this->escapeIcalText($meeting->agenda);
        }

        $location = $meeting->meeting_url !== null
            ? $this->escapeIcalText($meeting->meeting_url)
            : '';

        $lines[] = "LOCATION:{$location}";
        $lines[] = $organizer;
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    /**
     * Escape text values for iCalendar per RFC 5545 §3.3.11.
     *
     * Order matters: escape backslashes first so we don't double-escape
     * the literal backslash we just inserted for commas and semicolons.
     */
    private function escapeIcalText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(',', '\\,', $value);
        $value = str_replace(';', '\\;', $value);
        $value = str_replace("\n", '\\n', $value);

        return $value;
    }
}
